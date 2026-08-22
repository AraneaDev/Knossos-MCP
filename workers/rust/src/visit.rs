//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use syn::spanned::Spanned;
use syn::{ImplItem, Item, TraitItem, Type};

use crate::facts::{reference, Facts};
use crate::resolve::{flatten_use, rebase, Aliases};

/// A walk in progress: the facts being built plus the names in scope.
struct Walk<'a> {
    /// Where facts accumulate.
    facts: &'a mut Facts,
    /// The file's module path, which owns its imports.
    module: String,
    /// Names this file brought into scope.
    aliases: Aliases,
}

/// Walk every item in a parsed file, attributing each to `module`.
pub fn walk(facts: &mut Facts, module: &str, file: &syn::File) {
    let mut walker = Walk {
        facts,
        module: module.to_owned(),
        aliases: Aliases::default(),
    };
    walker.collect_uses(module, &file.items);
    walker.walk_items(module, "module", &file.items);
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
    fn collect_uses(&mut self, container: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Use(node) => {
                    let mut leaves = Vec::new();
                    flatten_use(&node.tree, "", &mut leaves);
                    let source = reference("module", &self.module);
                    for (alias, full) in leaves {
                        let Some(full) = rebase(container, &full) else {
                            continue;
                        };
                        let target = reference("module", &full);
                        self.facts
                            .edge("imports", &source, &target, "certain", item.span());
                        self.aliases.insert(alias, full);
                    }
                }
                Item::Mod(node) => {
                    if let Some((_, inner)) = &node.content {
                        let nested = format!("{container}::{}", node.ident);
                        self.collect_uses(&nested, inner);
                    }
                }
                _ => {}
            }
        }
    }

    /// Walk one item list whose declarations belong to `container`.
    ///
    /// `container_kind` is the node kind of `container` itself (`module`, `class`,
    /// or `interface`), needed to build the `contains` edge's source reference —
    /// an edge endpoint carries a node kind that isn't always the edge's own kind.
    fn walk_items(&mut self, container: &str, container_kind: &str, items: &[Item]) {
        for item in items {
            self.walk_item(container, container_kind, item);
        }
    }

    /// Walk one item, emitting its node and recursing into anything it holds.
    fn walk_item(&mut self, container: &str, container_kind: &str, item: &Item) {
        match item {
            Item::Struct(node) => {
                self.declare(
                    container,
                    container_kind,
                    &node.ident.to_string(),
                    "class",
                    item.span(),
                );
            }
            Item::Enum(node) => {
                self.declare(
                    container,
                    container_kind,
                    &node.ident.to_string(),
                    "class",
                    item.span(),
                );
            }
            Item::Union(node) => {
                self.declare(
                    container,
                    container_kind,
                    &node.ident.to_string(),
                    "class",
                    item.span(),
                );
            }
            Item::Fn(node) => {
                self.declare(
                    container,
                    container_kind,
                    &node.sig.ident.to_string(),
                    "function",
                    item.span(),
                );
            }
            Item::Trait(node) => {
                let canonical = self.declare(
                    container,
                    container_kind,
                    &node.ident.to_string(),
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
                        self.declare(
                            &canonical,
                            "interface",
                            &method.sig.ident.to_string(),
                            "method",
                            member.span(),
                        );
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
                let Some(target) = type_path(container, &node.self_ty) else {
                    return;
                };
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
                for member in &node.items {
                    if let ImplItem::Fn(method) = member {
                        self.declare(
                            &target,
                            "class",
                            &method.sig.ident.to_string(),
                            "method",
                            member.span(),
                        );
                    }
                }
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
    /// Only the `contains` edge is emitted for that case, and nothing is walked.
    fn walk_mod(
        &mut self,
        container: &str,
        container_kind: &str,
        node: &syn::ItemMod,
        span: proc_macro2::Span,
    ) {
        let canonical = format!("{container}::{}", node.ident);
        if let Some((_, items)) = &node.content {
            self.facts
                .node("module", &canonical, &node.ident.to_string(), span, span);
            self.walk_items(&canonical, "module", items);
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
    /// declared in the same file lives.
    ///
    /// Returns `None` for an empty path, or for a `super` chain longer than
    /// `container` has segments to give up — see [`rebase`].
    fn path_target(&self, container: &str, path: &syn::Path) -> Option<String> {
        let rendered = path
            .segments
            .iter()
            .map(|segment| segment.ident.to_string())
            .collect::<Vec<_>>()
            .join("::");
        if rendered.is_empty() {
            return None;
        }
        if path.leading_colon.is_some() || rendered == "crate" || rendered.starts_with("crate::") {
            return Some(rendered.trim_start_matches("::").to_owned());
        }
        if rendered == "self"
            || rendered.starts_with("self::")
            || rendered == "super"
            || rendered.starts_with("super::")
        {
            return rebase(container, &rendered);
        }
        if let Some(expanded) = self.aliases.expand(&rendered) {
            return Some(expanded);
        }

        Some(format!("{container}::{rendered}"))
    }
}

/// The canonical path of an `impl` block's self type, when it names one.
///
/// Only a plain path is handled. `impl Trait for &T`, tuples, and slices name no
/// declared type in this file, so attributing methods to them would invent a node.
fn type_path(container: &str, ty: &Type) -> Option<String> {
    let Type::Path(path) = ty else {
        return None;
    };
    let last = path.path.segments.last()?;

    Some(format!("{container}::{}", last.ident))
}
