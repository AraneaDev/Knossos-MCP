//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use syn::spanned::Spanned;
use syn::visit::Visit;
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
        let head = rendered.split("::").next().unwrap_or(&rendered);
        if self.aliases.is_ambiguous(head) {
            return None;
        }

        Some(format!("{container}::{rendered}"))
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
    /// qualified path callee (`http::get()`, `Engine::stop()`) is trusted as
    /// [`Walk::path_target`] resolves it, same as any other path in this
    /// worker. A *bare*, single-segment callee (`helper()`) is trickier: it
    /// is indistinguishable at the syntax level from a call through a local
    /// closure or function-pointer binding, which names no declaration at
    /// all. Rust requires such a name to either be imported or declared in
    /// the same file to be callable unqualified, so a bare callee is only
    /// emitted when the alias map resolves it or the guessed target turns
    /// out to be declared in this file — see `Facts::conditional_edge`. Both
    /// branches match the Python worker's principle: an unresolved target
    /// produces no edge instead of a wrong one.
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
/// single, shallow method — `visit_expr_call` below — instead of growing the
/// cognitive complexity of a larger function.
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
    /// Resolve and emit a call whose callee path has two or more segments
    /// (`http::get`, `Engine::stop`, `crate::helper`, `<Engine as
    /// Named>::name`, …).
    ///
    /// A qualified path is trusted as [`Walk::path_target`] resolves it,
    /// exactly like any other path this worker resolves (an `implements`
    /// target, a supertrait bound): there is no ambiguity to guard against
    /// here the way there is for a bare name, since Rust requires a
    /// qualified callee to actually name something reachable from where it
    /// is written.
    fn visit_qualified_call(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        if let Some(target) = self.walk.path_target(&self.container, path) {
            let kind = call_kind(&target);
            self.walk.facts.edge(
                "calls",
                &self.enclosing,
                &reference(kind, &target),
                "probable",
                span,
            );
        }
    }

    /// Resolve a call whose callee path is exactly one segment: a bare,
    /// unqualified identifier such as `helper()` or `f()`.
    ///
    /// This shape is exactly what a call through a local closure or
    /// function-pointer binding also looks like — `syn` cannot tell them
    /// apart, since a callee like this is just a path, and a binding to a
    /// callable value is invisible to a syntax-only worker. Rust makes an
    /// *unqualified* free-function call legitimate in exactly two ways: the
    /// name was imported (the alias map expands it), or it names something
    /// declared in this same file. The former is checked immediately; the
    /// latter cannot be checked yet — the declaration this call names may
    /// come later in the file, since `walk_items` proceeds in source order —
    /// so it is recorded through [`Facts::conditional_edge`] and verified
    /// once the whole file's declarations are known, in `Facts::finish`. A
    /// local closure or function-pointer binding satisfies neither and is
    /// dropped there.
    fn visit_bare_call(&mut self, path: &syn::Path, span: proc_macro2::Span) {
        let Some(segment) = path.segments.first() else {
            return;
        };
        let name = segment.ident.to_string();
        if let Some(expanded) = self.walk.aliases.expand(&name) {
            let kind = call_kind(&expanded);
            self.walk.facts.edge(
                "calls",
                &self.enclosing,
                &reference(kind, &expanded),
                "probable",
                span,
            );
            return;
        }
        if let Some(target) = self.walk.path_target(&self.container, path) {
            let kind = call_kind(&target);
            self.walk
                .facts
                .conditional_edge(&self.enclosing, &reference(kind, &target), span);
        }
    }
}

impl syn::visit::Visit<'_> for Calls<'_, '_> {
    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(path) = node.func.as_ref() {
            if path.path.segments.len() == 1 {
                self.visit_bare_call(&path.path, node.span());
            } else {
                self.visit_qualified_call(&path.path, node.span());
            }
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
/// then matches no declared node, and the reconciler synthesises a phantom
/// external node for it rather than crashing — call targets are never
/// filtered the way edge sources are (see `Facts::finish`). So a bad guess
/// costs a spurious node in the graph, not a failed scan.
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
