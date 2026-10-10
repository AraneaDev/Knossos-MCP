//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use std::collections::{BTreeMap, BTreeSet};

use syn::spanned::Spanned;
use syn::visit::Visit;
use syn::{ImplItem, Item, TraitItem, Type};

use crate::facts::{reference, Facts};
use crate::layout::Layout;
use crate::resolve::{flatten_use, glob_prefixes, ident_name, parent_module, rebase, Aliases};

mod calls;
mod cfg;
mod declarations;
mod paths;
mod routes;

use calls::{is_foreign_export, is_wasm_bindgen};
pub use cfg::{collect_test_modules, TestModules};
use cfg::{is_cfg_test, is_test_attribute, is_test_module_path, item_attrs, path_attribute};
pub use declarations::{collect_declarations, declaration_paths, declared_renames, Declarations};
use routes::RouteCandidate;

/// A walk in progress: the facts being built plus the names in scope.
struct Walk<'a> {
    /// Where facts accumulate.
    facts: &'a mut Facts,
    /// The file's module path, which owns its imports.
    module: String,
    /// Names this file brought into scope.
    aliases: Aliases,
    /// The modules this file's glob imports (`use a::b::*;`) bring every
    /// public name of into scope, each with the module that declares it, in
    /// the order they were written.
    globs: Vec<(String, String)>,
    /// Glob imports as written, with their module, whether the path is
    /// absolute (`::dep::*`), and span, until every `use` in the file is
    /// known: a glob's path may start with an alias declared later in the
    /// same module.
    pending_globs: Vec<(String, String, bool, proc_macro2::Span)>,
    /// Each module's own imported names, for a glob's leading alias: a `use`
    /// is scoped to the module that declares it, so sibling modules may bind
    /// one alias to different paths. `None` marks a name bound twice.
    module_aliases: BTreeMap<(String, String), Option<String>>,
    /// Target type of the current impl block, for resolving `Self`.
    current_impl_target: Option<String>,
    /// Frameworks the scan request asked this worker to enrich, by short name
    /// (`axum`, `actix`, `rocket`). Empty means none.
    frameworks: &'a [String],
    /// Scan-wide declaration index, see [`Declarations`].
    declarations: &'a Declarations,
    /// Route facts found during the walk, flushed in [`Walk::finish_walk`].
    routes: Vec<RouteCandidate>,
    /// Framework roles discovered for handlers in this file, applied by
    /// canonical name once the walk is complete.
    role_marks: Vec<String>,
    /// The field types of each struct this file declares, by struct then
    /// field name, so `self.walk.facts.edge()` resolves through the fields.
    struct_fields: BTreeMap<String, BTreeMap<String, String>>,
    /// Where the project's crates are, which names each `mod` declaration's
    /// module (see [`mod_child`]).
    layout: &'a Layout,
    /// The file's project-relative path, which the `mod` declarations in it
    /// load their files beside.
    relative: String,
    /// Each `mod name;` whose module is not `container::name` (a binary
    /// root's or a test target's children, `#[path]`), by its container and
    /// name: a path through `name` there names that module. `None` marks a
    /// name two declarations (under different `cfg`s) send to two modules.
    renamed_children: BTreeMap<(String, String), Option<String>>,
    /// What `crate` names in this file: the package's crate root, or for a
    /// target root (a binary in `src/bin/`, a test, an example) the file's
    /// own module, so `crate::own` in `src/bin/tool.rs` is
    /// `crate::bin::tool::own`. Its `mod helper;` loads `src/bin/helper.rs`,
    /// and `crate::helper` reaches that through the renamed declaration (see
    /// [`Declarations::renamed`]).
    crate_module: String,
    /// The files this file's `mod` declarations load, whose own placement
    /// decided the module each declaration names.
    placed: BTreeSet<String>,
}

/// Walk every item in a parsed file, attributing each to `module`, and
/// return the files its `mod` declarations load (see [`mod_child`]).
pub fn walk(
    facts: &mut Facts,
    module: &str,
    file: &syn::File,
    frameworks: &[String],
    declarations: &Declarations,
    test_modules: &TestModules,
    layout: &Layout,
) -> BTreeSet<String> {
    // A file that IS an out-of-line test module is test code in its entirety,
    // and so is anything nested below it, so the scope opens around the whole
    // walk rather than around a single item.
    let file_is_test = is_test_module_path(module, test_modules);
    if file_is_test {
        facts.enter_test_scope();
    }
    let relative = facts.relative().to_owned();
    let root = module.split("::").next().unwrap_or("crate").to_owned();
    let crate_module = if layout.is_target_root(&relative) {
        module.to_owned()
    } else {
        root
    };
    let mut walker = Walk {
        facts,
        module: module.to_owned(),
        aliases: Aliases::default(),
        globs: Vec::new(),
        pending_globs: Vec::new(),
        module_aliases: BTreeMap::new(),
        current_impl_target: None,
        frameworks,
        declarations,
        routes: Vec::new(),
        role_marks: Vec::new(),
        struct_fields: BTreeMap::new(),
        layout,
        relative,
        renamed_children: BTreeMap::new(),
        crate_module,
        placed: BTreeSet::new(),
    };
    walker.collect_uses(module, &file.items);
    walker.resolve_globs();
    walker.collect_struct_fields(module, &file.items);
    walker.walk_items(module, "module", &file.items);
    walker.finish_walk();
    let placed = std::mem::take(&mut walker.placed);
    if file_is_test {
        facts.exit_test_scope();
    }

    placed
}

/// The module a `mod` item in `relative` (placed in `module`) declares, and
/// for a `mod name;` the file it loads.
///
/// `mod name { .. }` declares `container::name` in place. `mod name;` names
/// the module of the file Rust loads for it (see [`Layout::child_file`]), so
/// a binary root's `mod cli;` is `crate::cli` beside `src/cli.rs`, a test
/// target's `mod common;` is `tests::common`, and `#[path = "x.rs"]` is the
/// module of `x.rs`: the declaration and that file's contribution agree on
/// one node.
fn mod_child(
    relative: &str,
    module: &str,
    container: &str,
    node: &syn::ItemMod,
    layout: &Layout,
) -> (String, Option<String>) {
    let name = ident_name(&node.ident);
    let in_place = format!("{container}::{name}");
    if node.content.is_some() {
        return (in_place, None);
    }
    let inline: Vec<String> = container
        .strip_prefix(module)
        .and_then(|rest| rest.strip_prefix("::"))
        .map(|rest| rest.split("::").map(str::to_owned).collect())
        .unwrap_or_default();
    let path = path_attribute(&node.attrs);
    match layout.child_file(relative, &inline, &name, path.as_deref()) {
        Some(file) => (layout.module_of(&file), Some(file)),
        None => (in_place, None),
    }
}

/// A `use` path whose head names a `mod` of the module it is written in,
/// rewritten onto that module's path (see [`mod_child`]); `Some(None)` when
/// two declarations of that name load different modules, `None` for any
/// other path. A leading `::` (`unrooted` false) always names a crate.
#[allow(clippy::option_option)]
fn through_child(
    children: &BTreeMap<String, Option<String>>,
    unrooted: bool,
    path: &str,
) -> Option<Option<String>> {
    if !unrooted {
        return None;
    }
    let (head, rest) = match path.split_once("::") {
        Some((head, rest)) => (head, Some(rest)),
        None => (path, None),
    };
    let child = children.get(head)?;

    Some(child.as_ref().map(|child| match rest {
        Some(rest) => format!("{child}::{rest}"),
        None => child.clone(),
    }))
}

impl Walk<'_> {
    /// Record every `use` in this item list, emitting one `imports` edge each.
    ///
    /// Imports are collected before declarations are walked, because a call in
    /// the first function may name something imported at the bottom of the file.
    ///
    /// `container` only drives recursion into nested `mod` blocks — it builds
    /// the canonical path passed to a further-nested `collect_uses` call — and
    /// never the edge source, which is always `self.module`, the file's own
    /// module, regardless of how deep the `use` line is nested.
    ///
    /// A nested `mod` block's `use` lines are still collected into the same
    /// file-wide alias map. That is wider than Rust's own scoping, which would
    /// hide them from the outer module, and it is the deliberate trade: this
    /// worker resolves names to emit edges, and a name that resolves in the
    /// file it appears in produces a true edge whichever block declared it.
    /// That flatness has a cost the map itself owns up to: two different `use`
    /// lines in different modules that import different full paths under the
    /// same local name collide. See `Aliases::insert` for how that collision
    /// is handled without ever emitting a silently wrong edge.
    ///
    /// `flatten_use` renders a `self`- or `super`-rooted leaf literally
    /// (`self::foo`, `super::foo`); [`rebase`] resolves that against
    /// `container`, the module the `use` line actually appears in, before the
    /// leaf becomes an edge target or an alias. A leaf `rebase` cannot place —
    /// a `super` chain longer than `container` has segments to give up —
    /// contributes neither an edge nor an alias.
    ///
    /// The edge points at the MODULE the imported name lives in, not at the
    /// name itself: `use a::b::C;` emits `imports` to `rust:module:a::b`, and
    /// `C` goes into the alias map, exactly as `visit_ImportFrom` in
    /// `workers/python/bin/worker.py` splits the two. A `use` overwhelmingly
    /// names a struct, trait, or function, whose real node kind is `class`,
    /// `interface`, or `function`, so targeting `rust:module:a::b::C` resolved
    /// against nothing and made the reconciler synthesise an external module
    /// that shadowed the very symbol the file had already declared. The module
    /// is a node the graph genuinely holds. Two shapes need no truncation: a
    /// single-segment `use foo;` already names a module (see
    /// [`parent_module`]), and a `self` leaf already names its prefix module
    /// (see [`crate::resolve::UseLeaf::names_module`]).
    ///
    /// Several symbols imported from the same module produce one edge, not one
    /// per symbol, matching the Python worker, which emits a single `imports`
    /// edge per `from` statement. `Facts::finish` performs that collapse for the
    /// whole contribution rather than per `use` line, so a module importing from
    /// the same place on ten separate lines still stores one row: the scanner
    /// SDK's persistence identity is kind/source/target within one owner, and
    /// every one of those rows is identical.
    fn collect_uses(&mut self, container: &str, items: &[Item]) {
        // `use policy::Policy;` beside `mod policy;` names that child module:
        // since the 2018 edition a path's first segment may be any name in
        // scope, and a module declared here is one. Left as written, the path
        // read as an external crate's.
        let mut children: BTreeMap<String, Option<String>> = BTreeMap::new();
        for item in items {
            if let Item::Mod(node) = item {
                let (child, file) =
                    mod_child(&self.relative, &self.module, container, node, self.layout);
                self.placed.extend(file);
                let name = ident_name(&node.ident);
                match children.get(&name) {
                    Some(Some(existing)) if *existing != child => {
                        children.insert(name, None);
                    }
                    Some(_) => {}
                    None => {
                        children.insert(name, Some(child));
                    }
                }
            }
        }
        for (name, child) in &children {
            if child.as_deref() != Some(format!("{container}::{name}").as_str()) {
                self.renamed_children
                    .insert((container.to_owned(), name.clone()), child.clone());
            }
        }
        for item in items {
            match item {
                Item::Use(node) => {
                    let mut leaves = Vec::new();
                    flatten_use(&node.tree, "", &mut leaves);
                    let source = reference("module", &self.module);
                    for leaf in leaves {
                        let written = match through_child(
                            &children,
                            node.leading_colon.is_none(),
                            &leaf.full,
                        ) {
                            Some(Some(written)) => written,
                            Some(None) => continue,
                            None => leaf.full.clone(),
                        };
                        let Some(full) = rebase(container, &self.anchor_crate(&written))
                            .and_then(|full| self.renamed(full))
                        else {
                            continue;
                        };
                        let module = if leaf.names_module {
                            full.clone()
                        } else {
                            parent_module(&full).to_owned()
                        };
                        self.import(&source, &module, item.span());
                        let key = (container.to_owned(), leaf.alias.clone());
                        match self.module_aliases.get(&key) {
                            Some(Some(existing)) if existing != &full => {
                                self.module_aliases.insert(key, None);
                            }
                            Some(_) => {}
                            None => {
                                self.module_aliases.insert(key, Some(full.clone()));
                            }
                        }
                        self.aliases.insert(leaf.alias, full);
                    }
                    let mut globs = Vec::new();
                    glob_prefixes(&node.tree, "", &mut globs);
                    for glob in globs {
                        let written =
                            match through_child(&children, node.leading_colon.is_none(), &glob) {
                                Some(Some(written)) => written,
                                Some(None) => continue,
                                None => glob,
                            };
                        self.pending_globs.push((
                            container.to_owned(),
                            written,
                            node.leading_colon.is_some(),
                            item.span(),
                        ));
                    }
                }
                Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        let nested = format!("{container}::{}", ident_name(&node.ident));
                        self.collect_uses(&nested, inner);
                    }
                }
                _ => {}
            }
        }
    }

    /// The edge a `use` leaves from `source` to the scope it imports from.
    ///
    /// A scope whose last segment is UpperCamelCase is a type, not a module:
    /// `use crate::errors::Error::Io;` and `use Error::*;` take variants or
    /// associated items of `Error`, so they reference the type (speculative,
    /// struct or trait being unknown) instead of importing a module the graph
    /// would invent.
    fn import(&mut self, source: &str, scope: &str, span: proc_macro2::Span) {
        let last = scope.rsplit("::").next().unwrap_or(scope);
        if scope.contains("::") && last.starts_with(char::is_uppercase) {
            for kind in ["class", "interface"] {
                self.facts
                    .speculative_edge("references", source, &reference(kind, scope), span);
            }
            return;
        }
        self.facts.edge(
            "imports",
            source,
            &reference("module", scope),
            "certain",
            span,
        );
    }

    /// Walk one item list whose declarations belong to `container`.
    ///
    /// `container_kind` is the node kind of `container` itself (`module`, `class`,
    /// or `interface`), needed to build the `contains` edge's source reference —
    /// an edge endpoint carries a node kind that isn't always the edge's own kind.
    ///
    /// An item compiled only under `test` (`#[cfg(test)] fn helper()`) is
    /// test code with everything it declares; a module opens that scope in
    /// [`Walk::walk_mod`], so it is not opened twice here.
    fn walk_items(&mut self, container: &str, container_kind: &str, items: &[Item]) {
        for item in items {
            let is_test = !matches!(item, Item::Mod(_)) && is_cfg_test(item_attrs(item));
            if is_test {
                self.facts.enter_test_scope();
            }
            self.walk_item(container, container_kind, item);
            if is_test {
                self.facts.exit_test_scope();
            }
        }
    }

    /// Walk one item, emitting its node and recursing into anything it holds.
    fn walk_item(&mut self, container: &str, container_kind: &str, item: &Item) {
        match item {
            Item::Struct(node) => {
                let canonical = self.declare(
                    container,
                    container_kind,
                    &ident_name(&node.ident),
                    "class",
                    item.span(),
                );
                self.walk_type_declaration(
                    &reference("class", &canonical),
                    container,
                    &node.attrs,
                    &[&node.fields],
                    &[],
                );
            }
            Item::Enum(node) => {
                let canonical = self.declare(
                    container,
                    container_kind,
                    &ident_name(&node.ident),
                    "class",
                    item.span(),
                );
                let fields: Vec<&syn::Fields> = node.variants.iter().map(|v| &v.fields).collect();
                let variant_attrs: Vec<&[syn::Attribute]> =
                    node.variants.iter().map(|v| v.attrs.as_slice()).collect();
                self.walk_type_declaration(
                    &reference("class", &canonical),
                    container,
                    &node.attrs,
                    &fields,
                    &variant_attrs,
                );
            }
            Item::Const(node) if container_kind == "module" => {
                self.walk_module_value(container, &node.ty, &node.expr);
            }
            Item::Static(node) if container_kind == "module" => {
                self.walk_module_value(container, &node.ty, &node.expr);
            }
            Item::Union(node) => {
                self.declare(
                    container,
                    container_kind,
                    &ident_name(&node.ident),
                    "class",
                    item.span(),
                );
            }
            Item::Fn(node) => self.walk_fn(container, container_kind, node, item.span()),
            Item::Trait(node) => self.walk_trait(container, container_kind, node, item.span()),
            Item::Impl(node) => self.walk_impl(container, container_kind, node, item.span()),
            Item::Mod(node) => self.walk_mod(container, container_kind, node, item.span()),
            _ => {}
        }
    }

    /// Walk a free function: its node, its markers as a test, an entry point
    /// or a foreign export, its attribute routes, and its body.
    fn walk_fn(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemFn,
        span: proc_macro2::Span,
    ) {
        let name = ident_name(&node.sig.ident);
        // A harness-invoked test has no caller in the graph, the same
        // way a cfg(test) module has none.
        let is_test = is_test_attribute(&node.attrs);
        if is_test {
            self.facts.enter_test_scope();
        }
        let canonical = self.declare(container, container_kind, &name, "function", span);
        // A crate-root `fn main` is what the runtime invokes — nothing
        // calls or imports it, so without the executable mark the
        // whole entry point reads as dead code.
        if container == self.module && name == "main" {
            self.facts.mark_executable();
        }
        // Exported to a foreign caller: the host embedding the library
        // calls it by its symbol, and nothing in Rust ever does.
        if is_foreign_export(node) {
            self.facts
                .node_attribute(&canonical, "runtime_invoked", serde_json::Value::Bool(true));
        }
        self.attribute_routes(&canonical, &node.attrs);
        self.walk_body(
            &reference("function", &canonical),
            container,
            &node.sig,
            &node.block,
        );
        if is_test {
            self.facts.exit_test_scope();
        }
    }

    /// Walk a trait: its node, the supertraits it extends, and each method it
    /// declares, with the default body when it has one.
    fn walk_trait(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemTrait,
        span: proc_macro2::Span,
    ) {
        let canonical = self.declare(
            container,
            container_kind,
            &ident_name(&node.ident),
            "interface",
            span,
        );
        // A supertrait bound (`trait Named: Display`) names a trait the
        // declared trait extends. Only a plain trait bound is handled;
        // a lifetime bound (`trait Named: 'static`) names no node.
        for supertrait in &node.supertraits {
            if let syn::TypeParamBound::Trait(bound) = supertrait {
                if let Some(target) = self.path_target(container, &bound.path) {
                    self.facts.edge(
                        "extends",
                        &reference("interface", &canonical),
                        &reference("interface", &target),
                        "probable",
                        span,
                    );
                }
            }
        }
        for member in &node.items {
            if let TraitItem::Fn(method) = member {
                // A trait method with no default body has no block to
                // walk: `default` is `None` for a signature-only member.
                self.walk_member_fn(
                    container,
                    &canonical,
                    "interface",
                    &method.attrs,
                    &method.sig,
                    method.default.as_ref(),
                    member.span(),
                    |_, _| {},
                );
            }
        }
    }

    /// Walk an `impl` block's methods onto the type it implements, with the
    /// `implements` edge of a trait impl.
    fn walk_impl(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemImpl,
        span: proc_macro2::Span,
    ) {
        // An `impl` block is not a node: it declares members of a type that
        // is declared elsewhere, possibly in another file. Its methods are
        // attached to the type's canonical path so both halves of a split
        // definition land on the same node. When that type was not declared
        // in this file, `Facts::finish` drops the resulting `contains` edge
        // (its source was never emitted as a node here) while the method
        // nodes themselves still stand.
        let Some(target) = self.type_path(container, &node.self_ty) else {
            return;
        };
        // A type from outside this crate can only take a trait impl
        // (the orphan rule), and its path is a name the project does
        // not own: attaching methods there declared `std::sync::Arc::x`
        // with nothing tying them to the trait they implement. The
        // block itself is the project's, so it becomes the container.
        let root = self.crate_root().to_owned();
        let target = match &node.trait_ {
            Some((_, trait_path, _))
                if target != root && !target.starts_with(&format!("{root}::")) =>
            {
                let trait_name = trait_path
                    .segments
                    .last()
                    .map(|segment| ident_name(&segment.ident))
                    .unwrap_or_default();
                let type_name = target.rsplit("::").next().unwrap_or(&target).to_owned();
                self.declare(
                    container,
                    container_kind,
                    &format!("<impl {trait_name} for {type_name}>"),
                    "class",
                    span,
                )
            }
            _ => target,
        };
        // The impl target may live in another file. Its `implements`
        // and `contains` edges use it as their SOURCE, and a source
        // the contribution never declared is normally filtered in
        // `Facts::finish`; the crate-wide index vouching for it keeps
        // those edges instead of orphaning the methods. The index is
        // the only acceptable vouching: it holds only files the core
        // discovered and this worker parsed, so a vouch also
        // guarantees the declaring file has a contribution, in this
        // request or cached, whose node will resolve. A target the
        // index cannot place stays unvouched and the edges ride out
        // the old drop, because a `contains` edge whose source names
        // nothing would make reconciliation throw.
        if self.declarations.get(&target).copied() == Some(1) {
            self.facts
                .external_unless_declared(&reference("class", &target));
        }
        let old_target = self.current_impl_target.replace(target.clone());
        // `impl Trait for Type` names both endpoints explicitly, so the
        // `implements` edge is `certain`. The trait name is resolved the
        // same way any other path is, through `use` and the `self`/`super`/
        // `crate` prefixes.
        if let Some((_, trait_path, _)) = &node.trait_ {
            if let Some(interface) = self.path_target(container, trait_path) {
                self.facts.edge(
                    "implements",
                    &reference("class", &target),
                    &reference("interface", &interface),
                    "certain",
                    span,
                );
            }
        }
        // `impl Drop for T` is the one trait whose method the runtime
        // calls: nothing in the graph names `drop`, so it reads as
        // unreferenced however heavily the type is used. Marked here,
        // on the trait rather than on the method name, so an ordinary
        // inherent method that happens to be called `drop` keeps its
        // reference check.
        let drop_impl = node.trait_.as_ref().is_some_and(|(_, path, _)| {
            path.segments
                .last()
                .is_some_and(|segment| segment.ident == "Drop")
        });
        // `#[wasm_bindgen] impl T`: JavaScript calls what the bindings
        // export, the public methods and the constructor, and nothing
        // in Rust has to.
        let exported_impl = node.attrs.iter().any(is_wasm_bindgen);
        for member in &node.items {
            if let ImplItem::Fn(method) = member {
                let name = ident_name(&method.sig.ident);
                self.walk_member_fn(
                    container,
                    &target,
                    "class",
                    &method.attrs,
                    &method.sig,
                    Some(&method.block),
                    member.span(),
                    |this, method_canonical| {
                        // The declared return type, which is what a call on the
                        // result resolves through in the core (`method_of_return`).
                        // Speculative: `String` or `Vec<T>` names nothing here.
                        if let syn::ReturnType::Type(_, returned) = &method.sig.output {
                            if let Some(returned) = this.receiver_type(container, returned) {
                                this.facts.speculative_edge(
                                    "returns",
                                    &reference("method", method_canonical),
                                    &reference("class", &returned),
                                    method.sig.output.span(),
                                );
                            }
                        }
                        // Every method of a trait impl fulfils a member the
                        // trait declares, and is reached through the trait,
                        // which may be a dependency's (`Default`, a visitor)
                        // whose members the graph cannot see.
                        if node.trait_.is_some() {
                            this.facts.node_attribute(
                                method_canonical,
                                "overrides",
                                serde_json::Value::Bool(true),
                            );
                        }
                        if (drop_impl && name == "drop")
                            || (exported_impl && matches!(method.vis, syn::Visibility::Public(_)))
                        {
                            this.facts.node_attribute(
                                method_canonical,
                                "runtime_invoked",
                                serde_json::Value::Bool(true),
                            );
                        }
                    },
                );
            }
        }
        self.current_impl_target = old_target;
    }

    /// Declare one trait or impl method under `owner` and walk its body, when
    /// it has one. A member compiled only for tests is test code, as a free
    /// function is (see `Walk::walk_items`), so the whole member sits in a
    /// test scope. `annotate` adds the facts only one kind of member carries,
    /// given the method's canonical path, between declaring it and walking
    /// its body.
    #[allow(clippy::too_many_arguments)]
    fn walk_member_fn(
        &mut self,
        container: &str,
        owner: &str,
        owner_kind: &str,
        attrs: &[syn::Attribute],
        signature: &syn::Signature,
        block: Option<&syn::Block>,
        span: proc_macro2::Span,
        annotate: impl FnOnce(&mut Self, &str),
    ) {
        let is_test = is_cfg_test(attrs);
        self.facts.enter_test_scope_if(is_test);
        let method_canonical = self.declare(
            owner,
            owner_kind,
            &ident_name(&signature.ident),
            "method",
            span,
        );
        annotate(self, &method_canonical);
        if let Some(block) = block {
            self.walk_body(
                &reference("method", &method_canonical),
                container,
                signature,
                block,
            );
        }
        self.facts.exit_test_scope_if(is_test);
    }

    /// Walk one `mod` item.
    ///
    /// `mod foo { .. }` (inline content) declares the module here: it gets a node
    /// plus the `contains` edge from `container`, and its items are walked under
    /// it. `mod foo;` (no content) is only a *reference* to a module declared in
    /// another file — the file itself emits that module's node from its own
    /// `crate::visit::walk` call, so declaring a second node here would duplicate
    /// it under a different evidence path and trip
    /// `reconciler.duplicate_symbol_evidence` on virtually every multi-file crate.
    /// Only the `contains` edge is emitted for that case, and nothing is walked;
    /// its target is the module the loaded file is placed in (see [`mod_child`]).
    fn walk_mod(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemMod,
        span: proc_macro2::Span,
    ) {
        let (canonical, _) = mod_child(&self.relative, &self.module, container, node, self.layout);
        // The whole subtree compiles only under cfg(test), so the mark covers
        // the module node and every item it holds, helpers included.
        let is_test = is_cfg_test(&node.attrs);
        if is_test {
            self.facts.enter_test_scope();
        }
        if let Some((_, items)) = &node.content {
            self.facts
                .node("module", &canonical, &ident_name(&node.ident), span, span);
            self.walk_items(&canonical, "module", items);
        }
        if is_test {
            self.facts.exit_test_scope();
        }
        self.facts.edge(
            "contains",
            &reference(container_kind, container),
            &reference("module", &canonical),
            "certain",
            span,
        );
    }

    /// Emit one node plus the `contains` edge from its container, returning its
    /// bare (unprefixed) canonical path.
    ///
    /// A method separates from its container with `::` like every other Rust path,
    /// so the canonical name a reader sees is the one they would type. The
    /// returned path is what a caller passes back in as the next `container`, so
    /// it stays unprefixed — only `Facts::node` and the edge endpoints built here
    /// carry the `rust:<kind>:` reference prefix the core requires.
    fn declare(
        &mut self,
        container: &str,
        container_kind: &str,
        name: &str,
        kind: &str,
        span: proc_macro2::Span,
    ) -> String {
        let canonical = format!("{container}::{name}");
        self.facts.node(kind, &canonical, name, span, span);
        self.facts.edge(
            "contains",
            &reference(container_kind, container),
            &reference(kind, &canonical),
            "certain",
            span,
        );

        canonical
    }

    /// Emit a `calls` edge for every resolvable call in one function body.
    ///
    /// `enclosing` is the full `rust:<kind>:<canonical>` reference of the
    /// function or method whose block this is, exactly as `declare` returned
    /// it wrapped in [`reference`](fn@reference) — its kind is already known for certain at
    /// the call site, unlike a call target's, so it is never guessed.
    /// `container` is the module `enclosing` itself is declared in (the same
    /// value `walk_item` was called with, never an `impl` block's type path),
    /// and is what an unqualified call inside the body resolves against —
    /// see [`Walk::path_target`].
    ///
    /// Only `Expr::Call` with a path callee resolves. A method call
    /// (`value.run()`) names no type the worker can see, and is dropped. A
    /// multi-segment callee the source roots explicitly (`crate::helper()`,
    /// `self::go()`) or heads with an imported name (`http::get()`) is trusted
    /// as [`Walk::resolve_path`] resolves it, same as any other path in this
    /// worker. A callee that is neither — `helper()`, `String::from()`,
    /// `<Widget>::default()`, `self()` — cannot be vouched for on sight, and is
    /// emitted solely when it lands on a declaration this same file makes; see
    /// [`Calls::visit_call`] and `Facts::conditional_edge`. Both branches match
    /// the Python worker's principle: an unresolved target produces no edge
    /// instead of a wrong one.
    ///
    /// `signature` seeds the receiver types a method call can resolve through:
    /// `self` names the `impl` block's type, and each parameter its declared
    /// type. See [`Calls::visit_expr_method_call`].
    fn walk_body(
        &mut self,
        enclosing: &str,
        container: &str,
        signature: &syn::Signature,
        block: &syn::Block,
    ) {
        let receivers = self.signature_receivers(container, signature);
        let mut visitor = Calls {
            walk: self,
            enclosing: enclosing.to_owned(),
            container: container.to_owned(),
            receivers,
        };
        visitor.visit_signature(signature);
        for statement in &block.stmts {
            visitor.visit_stmt(statement);
        }
    }

    /// Walk what a type declaration names outside any body: its fields'
    /// types, and the functions serde attributes name by string. `enclosing`
    /// is the struct, enum or union itself.
    fn walk_type_declaration(
        &mut self,
        enclosing: &str,
        container: &str,
        attrs: &[syn::Attribute],
        fields: &[&syn::Fields],
        field_attrs: &[&[syn::Attribute]],
    ) {
        let mut visitor = Calls {
            walk: self,
            enclosing: enclosing.to_owned(),
            container: container.to_owned(),
            receivers: BTreeMap::new(),
        };
        visitor.serde_references(attrs);
        for fields in fields {
            visitor.visit_fields(fields);
            for field in fields.iter() {
                visitor.serde_references(&field.attrs);
            }
        }
        for attrs in field_attrs {
            visitor.serde_references(attrs);
        }
    }

    /// Walk a module-level `const` or `static`: its type and its initializer,
    /// whose calls run on behalf of the module that declares it.
    fn walk_module_value(&mut self, container: &str, ty: &Type, expr: &syn::Expr) {
        let mut visitor = Calls {
            walk: self,
            enclosing: reference("module", container),
            container: container.to_owned(),
            receivers: BTreeMap::new(),
        };
        visitor.visit_type(ty);
        visitor.visit_expr(expr);
    }

    /// Record the named field types of every struct in `items`, inline
    /// modules included, resolved through this file's imports. Collected
    /// before the walk, so an `impl` above its struct still sees them.
    fn collect_struct_fields(&mut self, container: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Struct(node) => {
                    let mut fields = BTreeMap::new();
                    for field in &node.fields {
                        if let Some(ident) = &field.ident {
                            if let Some(target) = self.receiver_type(container, &field.ty) {
                                fields.insert(ident_name(ident), target);
                            }
                        }
                    }
                    if !fields.is_empty() {
                        self.struct_fields
                            .insert(format!("{container}::{}", ident_name(&node.ident)), fields);
                    }
                }
                Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        self.collect_struct_fields(
                            &format!("{container}::{}", ident_name(&node.ident)),
                            inner,
                        );
                    }
                }
                _ => {}
            }
        }
    }

    /// The receiver types a signature states: `self` as the `impl` block's
    /// type, and every plainly named parameter as its type behind any `&`.
    fn signature_receivers(
        &self,
        container: &str,
        signature: &syn::Signature,
    ) -> BTreeMap<String, String> {
        let mut receivers = BTreeMap::new();
        for input in &signature.inputs {
            match input {
                syn::FnArg::Receiver(_) => {
                    if let Some(target) = &self.current_impl_target {
                        receivers.insert("self".to_owned(), target.clone());
                    }
                }
                syn::FnArg::Typed(typed) => {
                    if let syn::Pat::Ident(ident) = typed.pat.as_ref() {
                        if let Some(target) = self.receiver_type(container, &typed.ty) {
                            receivers.insert(ident_name(&ident.ident), target);
                        }
                    }
                }
            }
        }
        receivers
    }
}

/// A `syn` visitor that emits a `calls` edge for every resolvable call
/// expression it walks through.
///
/// Kept as a small, separate `Visit` implementation (rather than inline
/// closures in `Walk::walk_statement`) so the call-resolution logic stays a
/// single, shallow method — [`Calls::visit_call`] below — instead of growing
/// the cognitive complexity of a larger function.
struct Calls<'a, 'b> {
    /// The walk collecting facts.
    walk: &'a mut Walk<'b>,
    /// Full `rust:<kind>:<canonical>` reference of the function or method
    /// whose body this is; the source of every `calls` edge emitted while
    /// walking it.
    enclosing: String,
    /// The module `enclosing` is declared in — never an `impl` block's type
    /// path, since free functions and modules, the only things an
    /// unqualified call can name, live in module scope. Passed to
    /// [`Walk::path_target`] as the container an unqualified or relative
    /// path resolves against.
    container: String,
    /// Local names whose type the source states, mapped to that type's
    /// canonical path: `self`, typed parameters, and `let` bindings that are
    /// annotated or constructed through a path. What a method call on one of
    /// them resolves through.
    receivers: BTreeMap<String, String>,
}

#[cfg(test)]
mod tests;
