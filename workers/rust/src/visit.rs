//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use syn::spanned::Spanned;
use syn::visit::Visit;
use syn::{ImplItem, Item, TraitItem, Type};

use crate::facts::{reference, Facts};
use crate::resolve::{flatten_use, parent_module, rebase, Aliases};

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
    /// One `use` line naming several symbols from the same module emits one
    /// edge, not one per symbol — again matching the Python worker, which
    /// emits a single `imports` edge per `from` statement.
    fn collect_uses(&mut self, container: &str, items: &[Item]) {
        for item in items {
            match item {
                Item::Use(node) => {
                    let mut leaves = Vec::new();
                    flatten_use(&node.tree, "", &mut leaves);
                    let source = reference("module", &self.module);
                    let mut targeted: Vec<String> = Vec::new();
                    for leaf in leaves {
                        let Some(full) = rebase(container, &leaf.full) else {
                            continue;
                        };
                        let module = if leaf.names_module {
                            full.clone()
                        } else {
                            parent_module(&full).to_owned()
                        };
                        if !targeted.contains(&module) {
                            self.facts.edge(
                                "imports",
                                &source,
                                &reference("module", &module),
                                "certain",
                                item.span(),
                            );
                            targeted.push(module);
                        }
                        self.aliases.insert(leaf.alias, full);
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
                let canonical = self.declare(
                    container,
                    container_kind,
                    &node.sig.ident.to_string(),
                    "function",
                    item.span(),
                );
                self.walk_body(&reference("function", &canonical), container, &node.block);
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
                        let method_canonical = self.declare(
                            &canonical,
                            "interface",
                            &method.sig.ident.to_string(),
                            "method",
                            member.span(),
                        );
                        // A trait method with no default body has no block to
                        // walk: `default` is `None` for a signature-only member.
                        if let Some(block) = &method.default {
                            self.walk_body(
                                &reference("method", &method_canonical),
                                container,
                                block,
                            );
                        }
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
                        let method_canonical = self.declare(
                            &target,
                            "class",
                            &method.sig.ident.to_string(),
                            "method",
                            member.span(),
                        );
                        self.walk_body(
                            &reference("method", &method_canonical),
                            container,
                            &method.block,
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
            .map(|segment| segment.ident.to_string())
            .collect::<Vec<_>>()
            .join("::");
        if rendered.is_empty() {
            return None;
        }
        let single_segment = path.segments.len() == 1;
        if path.leading_colon.is_some() || rendered == "crate" || rendered.starts_with("crate::") {
            return Some((rendered.trim_start_matches("::").to_owned(), single_segment));
        }
        if rendered == "self"
            || rendered.starts_with("self::")
            || rendered == "super"
            || rendered.starts_with("super::")
        {
            return rebase(container, &rendered).map(|target| (target, single_segment));
        }
        if let Some(expanded) = self.aliases.expand(&rendered) {
            return Some((expanded, false));
        }
        let head = rendered.split("::").next().unwrap_or(&rendered);
        if self.aliases.is_ambiguous(head) {
            return None;
        }

        Some((format!("{container}::{rendered}"), true))
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
    /// it wrapped in [`reference`] — its kind is already known for certain at
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
    fn walk_body(&mut self, enclosing: &str, container: &str, block: &syn::Block) {
        for statement in &block.stmts {
            self.walk_statement(enclosing, container, statement);
        }
    }

    /// Walk one statement for calls, descending into nested expressions.
    fn walk_statement(&mut self, enclosing: &str, container: &str, statement: &syn::Stmt) {
        let mut visitor = Calls {
            walk: self,
            enclosing: enclosing.to_owned(),
            container: container.to_owned(),
        };
        visitor.visit_stmt(statement);
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
    fn visit_call(&mut self, path: &syn::Path, span: proc_macro2::Span) {
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
        self.walk
            .facts
            .edge("calls", &self.enclosing, &endpoint, "probable", span);
    }
}

impl syn::visit::Visit<'_> for Calls<'_, '_> {
    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(path) = node.func.as_ref() {
            self.visit_call(&path.path, node.span());
        }
        syn::visit::visit_expr_call(self, node);
    }
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
mod tests {
    use super::call_kind;

    #[test]
    fn a_snake_case_owner_segment_guesses_a_free_function() {
        assert_eq!("function", call_kind("crate::helper"));
        assert_eq!("function", call_kind("crate::net::http::get"));
    }

    #[test]
    fn an_upper_camel_case_owner_segment_guesses_a_method() {
        assert_eq!("method", call_kind("crate::Engine::stop"));
        assert_eq!("method", call_kind("crate::net::Engine::stop"));
    }
}
