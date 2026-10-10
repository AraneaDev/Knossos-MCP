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

mod cfg;
mod declarations;
mod routes;

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
            Item::Fn(node) => {
                let name = ident_name(&node.sig.ident);
                // A harness-invoked test has no caller in the graph, the same
                // way a cfg(test) module has none.
                let is_test = is_test_attribute(&node.attrs);
                if is_test {
                    self.facts.enter_test_scope();
                }
                let canonical =
                    self.declare(container, container_kind, &name, "function", item.span());
                // A crate-root `fn main` is what the runtime invokes — nothing
                // calls or imports it, so without the executable mark the
                // whole entry point reads as dead code.
                if container == self.module && name == "main" {
                    self.facts.mark_executable();
                }
                // Exported to a foreign caller: the host embedding the library
                // calls it by its symbol, and nothing in Rust ever does.
                if is_foreign_export(node) {
                    self.facts.node_attribute(
                        &canonical,
                        "runtime_invoked",
                        serde_json::Value::Bool(true),
                    );
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
            Item::Trait(node) => {
                let canonical = self.declare(
                    container,
                    container_kind,
                    &ident_name(&node.ident),
                    "interface",
                    item.span(),
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
                                item.span(),
                            );
                        }
                    }
                }
                for member in &node.items {
                    if let TraitItem::Fn(method) = member {
                        // A member compiled only for tests is test code, as
                        // a free function is (see `Walk::walk_items`).
                        let is_test = is_cfg_test(&method.attrs);
                        self.facts.enter_test_scope_if(is_test);
                        let method_canonical = self.declare(
                            &canonical,
                            "interface",
                            &ident_name(&method.sig.ident),
                            "method",
                            member.span(),
                        );
                        // A trait method with no default body has no block to
                        // walk: `default` is `None` for a signature-only member.
                        if let Some(block) = &method.default {
                            self.walk_body(
                                &reference("method", &method_canonical),
                                container,
                                &method.sig,
                                block,
                            );
                        }
                        self.facts.exit_test_scope_if(is_test);
                    }
                }
            }
            Item::Impl(node) => {
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
                            item.span(),
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
                            item.span(),
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
                        let is_test = is_cfg_test(&method.attrs);
                        self.facts.enter_test_scope_if(is_test);
                        let name = ident_name(&method.sig.ident);
                        let method_canonical =
                            self.declare(&target, "class", &name, "method", member.span());
                        // The declared return type, which is what a call on the
                        // result resolves through in the core (`method_of_return`).
                        // Speculative: `String` or `Vec<T>` names nothing here.
                        if let syn::ReturnType::Type(_, returned) = &method.sig.output {
                            if let Some(returned) = self.receiver_type(container, returned) {
                                self.facts.speculative_edge(
                                    "returns",
                                    &reference("method", &method_canonical),
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
                            self.facts.node_attribute(
                                &method_canonical,
                                "overrides",
                                serde_json::Value::Bool(true),
                            );
                        }
                        if (drop_impl && name == "drop")
                            || (exported_impl && matches!(method.vis, syn::Visibility::Public(_)))
                        {
                            self.facts.node_attribute(
                                &method_canonical,
                                "runtime_invoked",
                                serde_json::Value::Bool(true),
                            );
                        }
                        self.walk_body(
                            &reference("method", &method_canonical),
                            container,
                            &method.sig,
                            &method.block,
                        );
                        self.facts.exit_test_scope_if(is_test);
                    }
                }
                self.current_impl_target = old_target;
            }
            Item::Mod(node) => self.walk_mod(container, container_kind, node, item.span()),
            _ => {}
        }
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

    /// The canonical target a syntactic path names, resolved through `use`.
    ///
    /// A path rooted at `::` or `crate` is absolute and taken as written. A path
    /// rooted at `self` or `super` is relative to `container` and is rebased
    /// through [`rebase`] — `self::foo` in `crate::net` names `crate::net::foo`,
    /// and `super::foo` in `crate::net::http` names `crate::net::foo`. A bare or
    /// aliased head is expanded through the import map. Anything the map cannot
    /// place is attributed to `container`, which is where an unqualified name
    /// declared in the same file lives — unless the head names an alias that
    /// was imported ambiguously (two different `use` lines bound the same
    /// local name to two different full paths). That case is not "unknown";
    /// it is known and poisoned, and guessing a target for it would silently
    /// prefer one of the two conflicting imports over the other with no
    /// basis for the choice, so it returns `None` instead of falling through
    /// to the container guess — see `Aliases::is_ambiguous`.
    ///
    /// Returns `None` for an empty path, or for a `super` chain longer than
    /// `container` has segments to give up — see [`rebase`].
    fn path_target(&self, container: &str, path: &syn::Path) -> Option<String> {
        self.resolve_path(container, path).map(|(target, _)| target)
    }

    /// [`Walk::path_target`], plus whether the answer still has to be confirmed
    /// against this file's own declarations before an edge is built from it.
    ///
    /// The second element is `true` in two cases. One is the container-relative
    /// fallback: the path was neither rooted (`crate`, `::`, `self`, `super`)
    /// nor expandable through the import map, so the target was assembled by
    /// assuming the name is declared alongside the code that writes it.
    ///
    /// The other is a path of exactly *one* segment that reaches a rooted
    /// branch, which never means what it renders as. `syn` puts a
    /// `leading_colon` on the bare `default` of `<Widget>::default()`, because
    /// the qualified self takes position 0 and the trait is absent, so the path
    /// reads as the absolute `::default`. A lone `self`, the callee of a call
    /// through an `Fn` receiver (`self()`), rebases onto the *enclosing module*
    /// rather than naming anything inside it. Trusting either produced a target
    /// no declaration can match: the literal one-word name `default`, or a
    /// `rust:function:` reference to a path that names a module, which the
    /// reconciler materialises as an external twin of a real node. Multi-segment
    /// rooted paths (`crate::helper`, `::a::b`, `self::go`, `super::go`) mean
    /// exactly what they say and stay trusted.
    ///
    /// A single segment the *import map* expands stays trusted too: `use
    /// a::b::go;` followed by `go()` names `a::b::go` because a `use` line in
    /// this same file said so.
    ///
    /// Callers that can afford an unconfirmed answer (an `impl` self type, which
    /// only ever attaches methods to a path this same file also declares) take
    /// [`Walk::path_target`] and ignore the flag. A `calls` edge cannot: its
    /// target reaches the reconciler as-is, and an unconfirmed target that names
    /// nothing becomes a fabricated external node. See [`Calls::visit_call`].
    fn resolve_path(&self, container: &str, path: &syn::Path) -> Option<(String, bool)> {
        let rendered = path
            .segments
            .iter()
            .map(|segment| ident_name(&segment.ident))
            .collect::<Vec<_>>()
            .join("::");
        if rendered.is_empty() {
            return None;
        }
        let single_segment = path.segments.len() == 1;
        if path.leading_colon.is_some() {
            return match self.library_path(&rendered) {
                Some(library) => self.renamed(library),
                None => Some(rendered),
            }
            .map(|target| (target, single_segment));
        }
        if rendered == "crate" || rendered.starts_with("crate::") {
            return self
                .renamed(self.anchor_crate(&rendered))
                .map(|target| (target, single_segment));
        }
        if rendered == "Self" || rendered.starts_with("Self::") {
            if let Some(target) = &self.current_impl_target {
                return Some((rendered.replacen("Self", target, 1), single_segment));
            }
        }
        if rendered == "self"
            || rendered.starts_with("self::")
            || rendered == "super"
            || rendered.starts_with("super::")
        {
            return rebase(container, &rendered)
                .and_then(|target| self.renamed(target))
                .map(|target| (target, single_segment));
        }
        if let Some(answer) = self.through_renamed_child(container, &rendered) {
            return answer;
        }
        if let Some(expanded) = self.aliases.expand(&rendered) {
            return Some((expanded, false));
        }
        if let Some(library) = self.library_path(&rendered) {
            return self.renamed(library).map(|target| (target, single_segment));
        }
        let head = rendered.split("::").next().unwrap_or(&rendered);
        if self.aliases.is_ambiguous(head) {
            return None;
        }

        // The crate-wide declaration index: an unimported name can still be
        // placed when exactly one file of the project declares it at an
        // address the path could mean. Candidates are tried in Rust scoping
        // order — the enclosing module first, then the crate root, then the
        // path as written — so `sign::any_supported_type` from the crate root
        // resolves to the child-module function, and a same-file name to its
        // own container. Unlike the container-relative fallback below, an
        // index hit is trusted outright: the node it names exists in the
        // graph, even when the declaring file is not this one.
        for candidate in self.index_candidates(container, &rendered) {
            match self.declarations.get(&candidate) {
                Some(1) => return Some((candidate, false)),
                // Ambiguous across files: guessing would silently prefer one
                // declaration over another, so the scoping winner must not
                // fall through to a weaker candidate.
                Some(_) => return None,
                None => {}
            }
        }

        Some((format!("{container}::{rendered}"), true))
    }

    /// A path whose head is a `mod name;` of `container` that names a module
    /// other than `container::name` (see [`Walk::renamed_children`]), placed
    /// in that module and answered by the declaration index as any other
    /// child-module path is: one declaration is trusted, several resolve to
    /// nothing, none leaves the target to be confirmed by this file. `None`
    /// for any other path.
    #[allow(clippy::option_option)]
    fn through_renamed_child(
        &self,
        container: &str,
        rendered: &str,
    ) -> Option<Option<(String, bool)>> {
        let (head, rest) = rendered.split_once("::")?;
        let Some(child) = self
            .renamed_children
            .get(&(container.to_owned(), head.to_owned()))?
        else {
            return Some(None);
        };
        let target = format!("{child}::{rest}");

        Some(match self.declarations.get(&target) {
            Some(1) => Some((target, false)),
            Some(_) => None,
            None => Some((target, true)),
        })
    }

    /// Resolve the glob imports [`Walk::collect_uses`] set aside, now that
    /// every alias in the file is known: `use parts::*;` beside
    /// `use crate::left as parts;` imports from `crate::left`, whichever line
    /// comes first.
    fn resolve_globs(&mut self) {
        let source = reference("module", &self.module);
        for (container, written, absolute, span) in std::mem::take(&mut self.pending_globs) {
            // `::dep::*` names the external crate whatever the file calls
            // `dep`; otherwise a leading alias is one this module declares.
            let expanded = if absolute {
                written
            } else {
                let (head, rest) = written.split_once("::").unwrap_or((written.as_str(), ""));
                match self
                    .module_aliases
                    .get(&(container.clone(), head.to_owned()))
                {
                    Some(Some(full)) if rest.is_empty() => full.clone(),
                    Some(Some(full)) => format!("{full}::{rest}"),
                    _ => written.clone(),
                }
            };
            let Some(full) = rebase(&container, &self.anchor_crate(&expanded))
                .and_then(|full| self.renamed(full))
            else {
                continue;
            };
            self.import(&source, &full, span);
            let glob = (container, full);
            if !self.globs.contains(&glob) {
                self.globs.push(glob);
            }
        }
    }

    /// The name this file's crate root has in the graph: `crate` for the
    /// package at the project root, the crate's own name for a workspace
    /// member (see `module_path_in_crate`).
    fn crate_root(&self) -> &str {
        self.module.split("::").next().unwrap_or("crate")
    }

    /// A path as written, with its root as the graph names it: `crate` is
    /// this file's crate root (a target root's own module, see
    /// [`Walk::crate_module`]), and a project library's crate
    /// name its root (see [`Walk::library_path`]).
    fn anchor_crate(&self, path: &str) -> String {
        if path == "crate" {
            return self.crate_module.clone();
        }
        match path.strip_prefix("crate::") {
            Some(rest) => format!("{}::{rest}", self.crate_module),
            None => self.library_path(path).unwrap_or_else(|| path.to_owned()),
        }
    }

    /// A path inside the project, rewritten through every `mod` declaration
    /// of the project that loads a module other than its declared path (see
    /// [`Declarations::renamed`]); `None` when one of those is ambiguous.
    /// A path outside the project is returned unchanged and asks nothing.
    fn renamed(&self, path: String) -> Option<String> {
        let head = path.split("::").next().unwrap_or(&path);
        if head != self.crate_root()
            && head != self.crate_module.split("::").next().unwrap_or("")
            && !self.layout.is_project_root(head)
        {
            return Some(path);
        }
        match self.declarations.renamed(&path) {
            Some(placed) => placed,
            None => Some(path),
        }
    }

    /// A path headed by the crate name of one of the project's libraries,
    /// rewritten onto that library's root in the graph: `my_demo::run` in
    /// `src/main.rs`, `tests/` or `examples/` is `crate::run`, the node the
    /// library's own file declares. A head that is this file's own crate root
    /// is left alone, since it already is a graph root.
    fn library_path(&self, path: &str) -> Option<String> {
        let head = path.split("::").next().unwrap_or(path);
        if head == self.crate_root() {
            return None;
        }

        self.layout.library_path(path)
    }

    /// The paths an unqualified `rendered` path could name, in scoping order.
    ///
    /// A name declared in the enclosing module shadows one a glob import
    /// brings in, so the globs come right after it; only a glob that module
    /// itself declares brings anything in.
    fn index_candidates(&self, container: &str, rendered: &str) -> Vec<String> {
        let mut candidates = Vec::with_capacity(3 + self.globs.len());
        let globbed = self
            .globs
            .iter()
            .filter(|(declared_in, _)| declared_in == container)
            .map(|(_, glob)| format!("{glob}::{rendered}"));
        for candidate in std::iter::once(format!("{container}::{rendered}"))
            .chain(globbed)
            .chain([
                if container == self.crate_root() {
                    String::new()
                } else {
                    format!("{}::{rendered}", self.crate_root())
                },
                rendered.to_owned(),
            ])
        {
            if !candidate.is_empty() && !candidates.contains(&candidate) {
                candidates.push(candidate);
            }
        }

        candidates
    }

    /// The canonical path of an `impl` block's self type, when it names one.
    ///
    /// Only a plain path is handled. `impl Trait for &T`, tuples, and slices name
    /// no declared type in this file, so attributing methods to them would invent
    /// a node. A path that does name a type is resolved through [`Walk::path_target`]
    /// like any other path — through `use`, `crate`/`self`/`super`, and the
    /// local-container fallback — rather than by keeping only its last segment.
    /// Keeping only the last segment previously collapsed a qualified self type
    /// such as `other::Engine` down to `crate::Engine`: a node that can genuinely
    /// exist under a different name in the same file, so the resulting edge would
    /// silently point at the wrong type instead of being dropped or resolved
    /// correctly.
    fn type_path(&self, container: &str, ty: &Type) -> Option<String> {
        let Type::Path(path) = ty else {
            return None;
        };

        self.path_target(container, &path.path)
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

    /// The canonical path of a type a receiver holds, seen through `&`,
    /// `&mut` and parentheses, or None for anything that is not a plain path.
    fn receiver_type(&self, container: &str, ty: &Type) -> Option<String> {
        match ty {
            Type::Reference(reference) => self.receiver_type(container, &reference.elem),
            Type::Paren(inner) => self.receiver_type(container, &inner.elem),
            Type::Path(path) if path.qself.is_none() => self
                .resolve_path(container, &path.path)
                .map(|(target, _)| target),
            _ => None,
        }
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

impl Calls<'_, '_> {
    /// Resolve and emit a call, deferring the ones that need confirming.
    ///
    /// A callee path resolves through [`Walk::resolve_path`] like every other
    /// path this worker sees. What the flag it returns decides is whether the
    /// resulting edge can be trusted on the spot.
    ///
    /// A multi-segment path the source states outright — rooted at `crate`, at
    /// `::`, at `self`/`super`, or headed by a name the import map expands — is
    /// emitted immediately. Its target is where the author said it is, and an
    /// external target is a genuine external symbol.
    ///
    /// Everything else must be confirmed against this file's declarations
    /// first. That is a path whose head is neither rooted nor imported, and a
    /// single-segment callee, which `syn` can render as a rooted path without
    /// it naming one (see [`Walk::resolve_path`]). Rust makes an unrooted head
    /// legal in exactly two ways: the name was imported, or it is declared in
    /// this same file. Nothing else is callable that way. So such a target is
    /// real only when it lands on a declaration this contribution makes, which
    /// cannot be known yet — the declaration may come later in the file, since
    /// `walk_items` proceeds in source order — and is checked in
    /// `Facts::finish` through [`Facts::conditional_edge`].
    ///
    /// That covers a bare `helper()`, which is indistinguishable at the syntax
    /// level from a call through a local closure or function-pointer binding;
    /// a qualified head such as `String::from()` or `serde_json::to_value()`;
    /// and the two one-segment shapes, `<Widget>::default()` and `self()`.
    /// Trusting a qualified head used to attribute it to the enclosing module,
    /// so `String::from` in `crate::net::http` became
    /// `crate::net::http::String::from`: a `crate::`-rooted name no crate
    /// member ever declares, which the reconciler then materialised as a
    /// fabricated external node.
    ///
    /// The check confirms; it does not prove the negative. It drops every
    /// target this contribution cannot vouch for, and one legitimate shape
    /// falls with them: a call qualified by a *child* module, such as
    /// `sign::any_supported_type(&key_der)` under a `pub mod sign;` declared in
    /// the same file. A bare `mod foo;` emits only a containment edge and no
    /// node (see [`Walk::walk_mod`]), so the child module's node belongs to the
    /// contribution of the file that defines it, and the per-contribution
    /// `declared` set here cannot match it. That is a known false negative: a
    /// missing edge, never a wrong one.
    ///
    /// A callee named in UpperCamelCase builds a value rather than calling
    /// anything: `Wrapper(1)` and `Self(x)` construct a tuple struct, which
    /// they reference, and `Error::Io(e)` an enum variant, whose enum the
    /// owner reference in [`Calls::visit_expr_call`] already names. Neither
    /// is a function or a method, so neither becomes a `calls` edge.
    ///
    /// A trusted target inside this project (rooted at `crate`, this file's
    /// crate root or a workspace member) carries a kind guessed from its
    /// spelling ([`call_kind`]) that no declaration here confirmed, so its
    /// edge is speculative: kept when the graph declares it, dropped rather
    /// than invented as an external symbol when it does not. A target in
    /// another crate stays an ordinary edge, its external node the
    /// dependency's symbol.
    fn visit_call(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if builds_value(path) {
            if !names_variant(path) {
                self.type_reference(path, span);
            }
            return;
        }
        let Some((target, unconfirmed)) = self.walk.resolve_path(&self.container, path) else {
            return;
        };
        let endpoint = reference(call_kind(&target), &target);
        if unconfirmed {
            self.walk
                .facts
                .conditional_edge(&self.enclosing, &endpoint, span);
            return;
        }
        let head = target.split("::").next().unwrap_or(&target);
        if head == self.walk.crate_root() || self.walk.layout.is_project_root(head) {
            self.walk
                .facts
                .speculative_edge("calls", &self.enclosing, &endpoint, span);
        } else {
            self.walk
                .facts
                .edge("calls", &self.enclosing, &endpoint, "probable", span);
        }
    }
}

impl Calls<'_, '_> {
    /// Speculative `references` edges to the type a path names, as a struct
    /// or enum and as a trait: which one it is, and whether it is declared
    /// here at all rather than being `Vec` or `String`, only the graph knows.
    fn type_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        let Some((target, _)) = self.walk.resolve_path(&self.container, path) else {
            return;
        };
        for kind in ["class", "interface"] {
            let endpoint = reference(kind, &target);
            if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
        }
    }

    /// The type a multi-segment value or pattern path sits under:
    /// `HookState::Absent` names `HookState`, `Self::helper` the impl's type.
    fn owner_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if path.segments.len() < 2 {
            return;
        }
        let mut owner = path.clone();
        owner.segments.pop();
        owner.segments.pop_punct();
        self.type_reference(&owner, span);
    }

    /// A function named as a value (`map(Self::helper)`, `.or_else(fallback)`),
    /// which no call expression marks. A bare name is usually a local binding,
    /// so an unconfirmed one counts only when this file declares a function
    /// by that name, the same deferral an unqualified call gets.
    fn value_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        let Some((target, unconfirmed)) = self.walk.resolve_path(&self.container, path) else {
            return;
        };
        if path.segments.len() < 2 {
            let endpoint = reference("function", &target);
            if unconfirmed {
                self.walk.facts.conditional_any_edge(
                    &self.enclosing,
                    &endpoint,
                    span,
                    "references",
                    "probable",
                );
            } else if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
            return;
        }
        for kind in ["function", "method"] {
            let endpoint = reference(kind, &target);
            if endpoint != self.enclosing {
                self.walk
                    .facts
                    .speculative_edge("references", &self.enclosing, &endpoint, span);
            }
        }
    }

    /// The functions a `#[serde(...)]` attribute names by string:
    /// `default = "f"`, `serialize_with`, `deserialize_with`,
    /// `skip_serializing_if`, `getter`, and a `with = "module"`'s
    /// `serialize`/`deserialize`. Serde calls each; nothing else names them.
    fn serde_references(&mut self, attrs: &[syn::Attribute]) {
        for attr in attrs {
            if !attr.path().is_ident("serde") {
                continue;
            }
            let mut named: Vec<(syn::Path, bool)> = Vec::new();
            let _ = attr.parse_nested_meta(|meta| {
                let key = meta.path.get_ident().map(ident_name);
                if meta.input.peek(syn::Token![=]) {
                    let value: syn::Expr = meta.value()?.parse()?;
                    if let (
                        Some(key),
                        syn::Expr::Lit(syn::ExprLit {
                            lit: syn::Lit::Str(literal),
                            ..
                        }),
                    ) = (key, value)
                    {
                        let with = key == "with";
                        if with
                            || matches!(
                                key.as_str(),
                                "default"
                                    | "serialize_with"
                                    | "deserialize_with"
                                    | "skip_serializing_if"
                                    | "getter"
                            )
                        {
                            if let Ok(path) = literal.parse::<syn::Path>() {
                                named.push((path, with));
                            }
                        }
                    }
                } else if meta.input.peek(syn::token::Paren) {
                    meta.parse_nested_meta(|nested| {
                        if nested.input.peek(syn::Token![=]) {
                            let _: syn::Expr = nested.value()?.parse()?;
                        }
                        Ok(())
                    })?;
                }
                Ok(())
            });
            for (path, with) in named {
                let span = attr.span();
                if with {
                    for function in ["serialize", "deserialize"] {
                        let mut member = path.clone();
                        member.segments.push(syn::PathSegment::from(syn::Ident::new(
                            function,
                            proc_macro2::Span::call_site(),
                        )));
                        self.function_reference(&member, span);
                    }
                } else {
                    self.function_reference(&path, span);
                }
            }
        }
    }

    /// The type a method call's receiver holds, when the source states it: a
    /// local of known type, or an enum variant or associated constant
    /// (`Theme::Regular`), whose type is the path it sits under.
    fn receiver_owner(&self, expression: &syn::Expr) -> Option<String> {
        if let Some(name) = receiver_name(expression) {
            return self.receivers.get(&name).cloned();
        }
        let mut current = expression;
        while let syn::Expr::Paren(inner) = current {
            current = &inner.expr;
        }
        // `self.walk.facts`: the base's type, then the field's declared type.
        if let syn::Expr::Field(field) = current {
            let syn::Member::Named(name) = &field.member else {
                return None;
            };
            let owner = self.receiver_owner(&field.base)?;
            return self
                .walk
                .struct_fields
                .get(&owner)
                .and_then(|fields| fields.get(&name.to_string()))
                .cloned();
        }
        let syn::Expr::Path(path) = current else {
            return None;
        };
        if path.qself.is_some() || path.path.segments.len() < 2 {
            return None;
        }
        let mut owner = path.path.clone();
        owner.segments.pop();
        owner.segments.pop_punct();
        self.walk
            .resolve_path(&self.container, &owner)
            .map(|(target, _)| target)
    }

    /// The method whose result an expression is, when its owner is known: a
    /// method call on a receiver of stated type, or an associated call
    /// `Type::f(..)`. `Type::method` as a canonical path, or None.
    fn returning_call(&self, expression: &syn::Expr) -> Option<String> {
        match expression {
            syn::Expr::Paren(inner) => self.returning_call(&inner.expr),
            syn::Expr::Reference(reference) => self.returning_call(&reference.expr),
            syn::Expr::MethodCall(call) => {
                let owner = self.receiver_owner(&call.receiver)?;
                Some(format!("{owner}::{}", ident_name(&call.method)))
            }
            syn::Expr::Call(call) => match call.func.as_ref() {
                syn::Expr::Path(path) if path.qself.is_none() && path.path.segments.len() >= 2 => {
                    self.walk
                        .resolve_path(&self.container, &path.path)
                        .map(|(target, _)| target)
                }
                _ => None,
            },
            _ => None,
        }
    }

    /// A speculative `references` edge to the function a path names.
    fn function_reference(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if let Some((target, _)) = self.walk.resolve_path(&self.container, path) {
            let endpoint = reference("function", &target);
            self.walk
                .facts
                .speculative_edge("references", &self.enclosing, &endpoint, span);
        }
    }
}

impl syn::visit::Visit<'_> for Calls<'_, '_> {
    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(path) = node.func.as_ref() {
            self.visit_call(&path.path, node.span());
            // `Action::Deny(x)` builds a variant: the enum is used. The callee
            // itself is not walked as a value path, since it is the call.
            self.owner_reference(&path.path, node.span());
            for argument in &node.args {
                self.visit_expr(argument);
            }
            return;
        }
        syn::visit::visit_expr_call(self, node);
    }

    fn visit_expr_path(&mut self, node: &syn::ExprPath) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.value_reference(&node.path, node.span());
        }
        syn::visit::visit_expr_path(self, node);
    }

    fn visit_type_path(&mut self, node: &syn::TypePath) {
        if node.qself.is_none() {
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_type_path(self, node);
    }

    fn visit_trait_bound(&mut self, node: &syn::TraitBound) {
        self.type_reference(&node.path, node.span());
        syn::visit::visit_trait_bound(self, node);
    }

    fn visit_pat_tuple_struct(&mut self, node: &syn::PatTupleStruct) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_pat_tuple_struct(self, node);
    }

    fn visit_pat_struct(&mut self, node: &syn::PatStruct) {
        if node.qself.is_none() {
            self.owner_reference(&node.path, node.span());
            self.type_reference(&node.path, node.span());
        }
        syn::visit::visit_pat_struct(self, node);
    }

    fn visit_macro(&mut self, node: &syn::Macro) {
        // A macro body is tokens to syn. Most invocations in ordinary code
        // (`format!`, `println!`, `assert_eq!`, `write!`) take comma-separated
        // expressions, so a body that parses as such is walked like any other
        // arguments; one that does not (`vec![x; n]`, `json!({..})`, a DSL)
        // contributes nothing rather than a guess.
        self.visit_macro_tokens(node.tokens.clone());
    }

    fn visit_expr_struct(&mut self, node: &syn::ExprStruct) {
        if let Some((target, unconfirmed)) = self.walk.resolve_path(&self.container, &node.path) {
            let endpoint = crate::visit::reference("class", &target);
            if unconfirmed {
                self.walk.facts.conditional_edge(
                    &self.enclosing,
                    &endpoint,
                    syn::spanned::Spanned::span(node),
                );
            } else {
                self.walk.facts.edge(
                    "calls",
                    &self.enclosing,
                    &endpoint,
                    "probable",
                    syn::spanned::Spanned::span(node),
                );
            }
        }
        syn::visit::visit_expr_struct(self, node);
    }

    fn visit_expr_method_call(&mut self, node: &syn::ExprMethodCall) {
        // A `route(...)` method call is how axum and actix declare routes.
        if node.method == "route" {
            self.call_routes(node);
        }
        // A call on what another call returns: `s.mode().label()` or
        // `State::new().mode()`. The inner call's owner is known here; its
        // return type may be declared in another file, so the member is named
        // through the call and the core resolves it, or drops the edge.
        let returning = self.returning_call(&node.receiver);
        if let Some(callee) = &returning {
            let endpoint = reference(
                "method_of_return",
                &format!("{callee}::{}", ident_name(&node.method)),
            );
            self.walk.facts.edge(
                "calls",
                &self.enclosing,
                &endpoint,
                "probable",
                node.method.span(),
            );
        }
        if let Some(target) = self.receiver_owner(&node.receiver) {
            let endpoint = reference("method", &format!("{target}::{}", ident_name(&node.method)));
            self.walk.facts.speculative_edge(
                "calls",
                &self.enclosing,
                &endpoint,
                node.method.span(),
            );
        } else if returning.is_none() {
            self.walk.facts.untyped_call(ident_name(&node.method));
        }
        syn::visit::visit_expr_method_call(self, node);
    }

    fn visit_local(&mut self, node: &syn::Local) {
        // The initializer is walked first: it runs before the binding exists,
        // so `let p = p.clone()` resolves its receiver through the old `p`.
        syn::visit::visit_local(self, node);
        let (ident, annotation) = match &node.pat {
            syn::Pat::Ident(ident) => (ident_name(&ident.ident), None),
            syn::Pat::Type(typed) => match typed.pat.as_ref() {
                syn::Pat::Ident(ident) => (ident_name(&ident.ident), Some(typed.ty.as_ref())),
                _ => return,
            },
            _ => return,
        };
        let stated = match annotation {
            Some(ty) => self.walk.receiver_type(&self.container, ty),
            None => node
                .init
                .as_ref()
                .and_then(|init| constructed_type(&init.expr))
                .and_then(|path| self.walk.resolve_path(&self.container, &path))
                .map(|(target, _)| target),
        };
        // A rebinding of unknown type shadows the old one, so its type is forgotten.
        match stated {
            Some(target) => {
                self.receivers.insert(ident, target);
            }
            None => {
                self.receivers.remove(&ident);
            }
        }
    }

    fn visit_expr_closure(&mut self, node: &syn::ExprClosure) {
        // A closure parameter shadows any outer binding of the same name, and
        // says nothing about its own type, so it is forgotten while inside.
        let saved = self.receivers.clone();
        for input in &node.inputs {
            if let syn::Pat::Ident(ident) = input {
                self.receivers.remove(&ident_name(&ident.ident));
            }
        }
        syn::visit::visit_expr_closure(self, node);
        self.receivers = saved;
    }
}

impl Calls<'_, '_> {
    /// Walk a macro body as expressions, as far as it is made of them.
    ///
    /// Comma-separated expressions first (`format!`, `assert_eq!`). A body
    /// that is not (`json!({ "k": f(x) })`) is split at its top-level commas,
    /// each piece loses a leading `"key":` or `key:`, and what remains is
    /// parsed on its own; a piece that still does not parse is searched
    /// through its bracketed groups the same way. Nothing is guessed: only
    /// token runs that parse as Rust expressions are walked. The groups still
    /// to search are kept in a worklist, so the walk is one loop rather than
    /// two functions calling each other.
    fn visit_macro_tokens(&mut self, tokens: proc_macro2::TokenStream) {
        use syn::parse::Parser;
        let parser = syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated;
        let mut pending = vec![tokens];
        while let Some(tokens) = pending.pop() {
            if let Ok(arguments) = parser.parse2(tokens.clone()) {
                for argument in &arguments {
                    self.visit_expr(argument);
                }
                continue;
            }
            for piece in top_level_pieces(tokens) {
                match macro_piece(piece) {
                    Ok(expression) => self.visit_expr(&expression),
                    Err(groups) => pending.extend(groups),
                }
            }
        }
    }
}

/// A macro body split at its top-level commas.
fn top_level_pieces(tokens: proc_macro2::TokenStream) -> Vec<Vec<proc_macro2::TokenTree>> {
    let mut pieces = vec![Vec::new()];
    for token in tokens {
        if matches!(&token, proc_macro2::TokenTree::Punct(punct) if punct.as_char() == ',') {
            pieces.push(Vec::new());
        } else if let Some(piece) = pieces.last_mut() {
            piece.push(token);
        }
    }
    pieces
}

/// One comma-separated piece of a macro body, without a leading `"key":` or
/// `key:`: the expression it parses as, or else the bracketed groups inside
/// it, which may hold expressions of their own. See [`Calls::visit_macro_tokens`].
fn macro_piece(
    mut piece: Vec<proc_macro2::TokenTree>,
) -> Result<syn::Expr, Vec<proc_macro2::TokenStream>> {
    let keyed = matches!(
        piece.as_slice(),
        [
            proc_macro2::TokenTree::Literal(_) | proc_macro2::TokenTree::Ident(_),
            proc_macro2::TokenTree::Punct(colon),
            next,
            ..
        ] if colon.as_char() == ':'
            && colon.spacing() == proc_macro2::Spacing::Alone
            && !matches!(next, proc_macro2::TokenTree::Punct(p) if p.as_char() == ':')
    );
    if keyed {
        piece.drain(..2);
    }
    if piece.is_empty() {
        return Err(Vec::new());
    }
    let stream: proc_macro2::TokenStream = piece.iter().cloned().collect();
    syn::parse2::<syn::Expr>(stream).map_err(|_| {
        piece
            .into_iter()
            .filter_map(|token| match token {
                proc_macro2::TokenTree::Group(group) => Some(group.stream()),
                _ => None,
            })
            .collect()
    })
}

/// Whether an attribute is `#[wasm_bindgen]`, however its path is spelled,
/// or a `cfg_attr`, at any depth, that applies it.
fn is_wasm_bindgen(attr: &syn::Attribute) -> bool {
    applies_wasm_bindgen(&attr.meta)
}

/// Whether one attribute's meta is `wasm_bindgen`, or a `cfg_attr` that
/// applies it: `#[cfg_attr(target_arch = "wasm32", wasm_bindgen)]` is how an
/// item is exported only to the build the bindings exist for, and a
/// `cfg_attr` may apply another.
fn applies_wasm_bindgen(meta: &syn::Meta) -> bool {
    if meta
        .path()
        .segments
        .last()
        .is_some_and(|segment| segment.ident == "wasm_bindgen")
    {
        return true;
    }
    let syn::Meta::List(list) = meta else {
        return false;
    };
    list.path.is_ident("cfg_attr")
        && list
            .parse_args_with(
                syn::punctuated::Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated,
            )
            .is_ok_and(|metas| metas.iter().skip(1).any(applies_wasm_bindgen))
}

/// Whether a function is exported to a caller outside Rust: `#[no_mangle]`
/// (also as `#[unsafe(no_mangle)]`), `#[export_name]`, `#[wasm_bindgen]`, or
/// an `extern` ABI.
fn is_foreign_export(node: &syn::ItemFn) -> bool {
    node.sig.abi.is_some()
        || node.attrs.iter().any(|attr| {
            let path = attr.path();
            path.is_ident("no_mangle")
                || path.is_ident("export_name")
                || is_wasm_bindgen(attr)
                || (path.is_ident("unsafe")
                    && attr
                        .meta
                        .require_list()
                        .is_ok_and(|list| list.tokens.to_string().contains("no_mangle")))
        })
}

/// The local a method call's receiver names, seen through `&` and parentheses.
fn receiver_name(expression: &syn::Expr) -> Option<String> {
    match expression {
        syn::Expr::Paren(inner) => receiver_name(&inner.expr),
        syn::Expr::Reference(reference) => receiver_name(&reference.expr),
        syn::Expr::Path(path) if path.qself.is_none() => path.path.get_ident().map(ident_name),
        _ => None,
    }
}

/// The type a `let` initializer constructs, when the source names it: a
/// struct literal `T { .. }`, or an associated call `T::new(..)` (taken to
/// return `Self`, as constructors do), either one behind `?`.
fn constructed_type(expression: &syn::Expr) -> Option<syn::Path> {
    match expression {
        syn::Expr::Try(inner) => constructed_type(&inner.expr),
        syn::Expr::Paren(inner) => constructed_type(&inner.expr),
        syn::Expr::Struct(literal) if literal.qself.is_none() => Some(literal.path.clone()),
        syn::Expr::Call(call) => match call.func.as_ref() {
            syn::Expr::Path(path) if path.qself.is_none() && path.path.segments.len() >= 2 => {
                let mut owner = path.path.clone();
                owner.segments.pop();
                owner.segments.pop_punct();
                Some(owner)
            }
            _ => None,
        },
        _ => None,
    }
}

/// Whether a callee path builds a value: its last segment is UpperCamelCase,
/// as tuple structs and enum variants are and functions are not.
fn builds_value(path: &syn::Path) -> bool {
    path.segments
        .last()
        .is_some_and(|segment| ident_name(&segment.ident).starts_with(char::is_uppercase))
}

/// Whether a constructor path names an enum variant (`Error::Io`,
/// `Self::Empty`): the segment before the last is a type, too.
fn names_variant(path: &syn::Path) -> bool {
    let count = path.segments.len();
    count >= 2 && ident_name(&path.segments[count - 2].ident).starts_with(char::is_uppercase)
}

/// Guess whether a resolved call target names a free function or a
/// method/associated function on a type.
///
/// `path_target` only resolves *where* a path points, not *what kind of
/// item* it names — that would require type information this worker does
/// not have. This falls back to Rust's own naming convention instead: types
/// are UpperCamelCase and modules are snake_case by near-universal
/// convention, so the segment immediately before the final one carries the
/// same signal a human reader would use. `crate::Engine::stop` guesses
/// `method` because `Engine` starts uppercase; `crate::net::http::get`
/// guesses `function` because `http` does not.
///
/// A convention-breaking crate can fool this guess. That cost is accepted
/// deliberately: a wrong guess builds a reference of the wrong kind, which
/// then matches no declared node. For a target the source rooted or imported,
/// the reconciler synthesises an external node for it rather than crashing —
/// call targets are never filtered the way edge sources are (see
/// `Facts::finish`). For a guessed target it costs the edge instead, since
/// `Facts::finish` requires a pending call to match a declaration exactly.
/// Either way a bad guess costs one edge or one spurious node, not a failed
/// scan.
fn call_kind(canonical: &str) -> &'static str {
    let segments: Vec<&str> = canonical.split("::").collect();
    let owner = segments.len().checked_sub(2).and_then(|i| segments.get(i));
    match owner {
        Some(segment) if segment.starts_with(char::is_uppercase) => "method",
        _ => "function",
    }
}

#[cfg(test)]
mod tests;
