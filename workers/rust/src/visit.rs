//! Walking one parsed file into nodes and containment edges.
//!
//! Items are walked with an explicit container stack rather than
//! `syn::visit::Visit`, because every node needs the canonical path of its
//! parent and a visitor's callbacks do not carry one.

use std::collections::BTreeMap;

use syn::spanned::Spanned;
use syn::visit::Visit;
use syn::{ImplItem, Item, TraitItem, Type};

use crate::facts::{reference, Facts};
use crate::resolve::{flatten_use, parent_module, rebase, Aliases};

/// Canonical name of every top-level and inline-module declaration in the
/// request, mapped to how many files declared it. The scan-wide view that lets
/// an `impl` block attach to a type declared in another file and a
/// child-module call resolve to its real target. A count above one means the
/// name is ambiguous across the batch and must not be trusted.
pub type Declarations = BTreeMap<String, usize>;

/// One route fact discovered while walking, emitted after the walk.
///
/// Unlike ordinary nodes, a route's handler may be declared later in the file
/// than the routing call that names it (Rust is order-agnostic), so routes are
/// deferred until the whole file's declarations are known.
struct RouteCandidate {
    /// Semantic framework name (`axum`, `actix`, `rocket`) for the node's
    /// `framework` attribute.
    framework: &'static str,
    /// HTTP method, uppercase.
    method: String,
    /// The path as written, when it is a static literal.
    path: String,
    /// Handler's resolved canonical path, or the container-relative guess
    /// when nothing resolved it.
    handler: String,
    /// Whether `handler` is that guess — needs the same-file declarations to
    /// confirm it, like a call target.
    handler_unconfirmed: bool,
    /// Where the routing statement is, for evidence.
    span: proc_macro2::Span,
}

/// A walk in progress: the facts being built plus the names in scope.
struct Walk<'a> {
    /// Where facts accumulate.
    facts: &'a mut Facts,
    /// The file's module path, which owns its imports.
    module: String,
    /// Names this file brought into scope.
    aliases: Aliases,
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
}

/// Walk every item in a parsed file, attributing each to `module`.
pub fn walk(
    facts: &mut Facts,
    module: &str,
    file: &syn::File,
    frameworks: &[String],
    declarations: &Declarations,
) {
    let mut walker = Walk {
        facts,
        module: module.to_owned(),
        aliases: Aliases::default(),
        current_impl_target: None,
        frameworks,
        declarations,
        routes: Vec::new(),
        role_marks: Vec::new(),
    };
    walker.collect_uses(module, &file.items);
    walker.walk_items(module, "module", &file.items);
    walker.finish_walk();
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
        for item in items {
            match item {
                Item::Use(node) => {
                    let mut leaves = Vec::new();
                    flatten_use(&node.tree, "", &mut leaves);
                    let source = reference("module", &self.module);
                    for leaf in leaves {
                        let Some(full) = rebase(container, &leaf.full) else {
                            continue;
                        };
                        let module = if leaf.names_module {
                            full.clone()
                        } else {
                            parent_module(&full).to_owned()
                        };
                        self.facts.edge(
                            "imports",
                            &source,
                            &reference("module", &module),
                            "certain",
                            item.span(),
                        );
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
                let name = node.sig.ident.to_string();
                let canonical =
                    self.declare(container, container_kind, &name, "function", item.span());
                // A crate-root `fn main` is what the runtime invokes — nothing
                // calls or imports it, so without the executable mark the
                // whole entry point reads as dead code.
                if container == self.module && name == "main" {
                    self.facts.mark_executable();
                }
                self.attribute_routes(&canonical, &node.attrs);
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
                // The impl target may live in another file. Its `implements`
                // and `contains` edges use it as their SOURCE, and a source
                // the contribution never declared is normally filtered in
                // `Facts::finish`; the scan-wide index vouching for it keeps
                // those edges instead of orphaning the methods. The index is
                // the only acceptable vouching: it is built from THIS
                // request's files, so a vouch also guarantees the declaring
                // file's contribution is in the batch and its node will
                // resolve. A target the index cannot place — declared in a
                // cached file, or nowhere — stays unvouched and the edges
                // ride out the old drop, because a `contains` edge whose
                // source names nothing would make reconciliation throw.
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
            return rebase(container, &rendered).map(|target| (target, single_segment));
        }
        if let Some(expanded) = self.aliases.expand(&rendered) {
            return Some((expanded, false));
        }
        let head = rendered.split("::").next().unwrap_or(&rendered);
        if self.aliases.is_ambiguous(head) {
            return None;
        }

        // The scan-wide declaration index: an unimported name can still be
        // placed when exactly one file in this request declares it at an
        // address the path could mean. Candidates are tried in Rust scoping
        // order — the enclosing module first, then the crate root, then the
        // path as written — so `sign::any_supported_type` from the crate root
        // resolves to the child-module function, and a same-file name to its
        // own container. Unlike the container-relative fallback below, an
        // index hit is trusted outright: the node it names exists in this
        // batch, even when the declaring file is not this one.
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

    /// The paths an unqualified `rendered` path could name, in scoping order.
    fn index_candidates(&self, container: &str, rendered: &str) -> Vec<String> {
        let mut candidates = Vec::with_capacity(3);
        for candidate in [
            format!("{container}::{rendered}"),
            if container == "crate" {
                String::new()
            } else {
                format!("crate::{rendered}")
            },
            rendered.to_owned(),
        ] {
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

    /// Record routes declared by actix-style handler attributes.
    ///
    /// `#[get("/path")]`, `#[post(...)]`, … and actix's `#[route("/path",
    /// method = "DELETE")]` are actix's and rocket's primary wiring — both
    /// share the shape, so which framework owns the route is decided by the
    /// scan request's framework list (actix when both are present). The route
    /// fact is deferred to [`Walk::finish_walk`] like every other route; the
    /// handler is this function itself, so it is always declared.
    fn attribute_routes(&mut self, canonical: &str, attrs: &[syn::Attribute]) {
        let actix = self.frameworks.iter().any(|f| f == "actix");
        let rocket = self.frameworks.iter().any(|f| f == "rocket");
        if !actix && !rocket {
            return;
        }
        const VERBS: [&str; 7] = ["get", "post", "put", "delete", "patch", "head", "options"];
        for attr in attrs {
            let Some(ident) = attr.path().get_ident() else {
                continue;
            };
            let name = ident.to_string();
            let (path, method) = if VERBS.contains(&name.as_str()) {
                (attr_path(attr), Some(name.to_uppercase()))
            } else if actix && name == "route" {
                (attr_path(attr), attr_method(attr))
            } else {
                continue;
            };
            let Some(path) = path.filter(|p| !is_dynamic_path(p)) else {
                // A non-literal path, or a literal with dynamic segments
                // (`<id>`, `{id}`, `:id`), would produce a guessed route;
                // diagnose it instead, mirroring the Python worker's
                // PY_DYNAMIC_ROUTE_PATH.
                self.facts.diagnostic(
                    "warning",
                    "RS_DYNAMIC_ROUTE_PATH",
                    "Dynamic Rust route path was skipped.",
                    attr.span().start().line,
                );
                continue;
            };
            let Some(method) = method else {
                continue; // an actix `#[route]` without a method names no verb
            };
            self.routes.push(RouteCandidate {
                framework: if actix { "actix" } else { "rocket" },
                method,
                path,
                handler: canonical.to_owned(),
                handler_unconfirmed: false,
                span: attr.span(),
            });
        }
    }

    /// Emit the routes and roles collected during the walk, once the whole
    /// file's declarations are known.
    ///
    /// A route's handler may be declared later in the file than the routing
    /// call that names it, so both kinds of route — attribute and call — are
    /// flushed here. An attribute route's handler is the function being
    /// walked, hence always declared; a call route's handler may be an
    /// unresolved guess, which only counts if it names a real declaration in
    /// this file.
    fn finish_walk(&mut self) {
        for route in &self.routes {
            if route.handler_unconfirmed
                && !self.facts.declares(&reference("function", &route.handler))
            {
                // Unknown handler: the route exists only as a guess. Dropping
                // it is a false negative, never a wrong fact.
                continue;
            }
            let canonical = format!("{} {} => {}", route.method, route.path, route.handler);
            self.facts.node_with_attributes(
                "route",
                &canonical,
                &format!("{} {}", route.method, route.path),
                route.span,
                route.span,
                BTreeMap::from([
                    ("framework".to_owned(), serde_json::json!(route.framework)),
                    (
                        "methods".to_owned(),
                        serde_json::json!(vec![route.method.clone()]),
                    ),
                    ("path".to_owned(), serde_json::json!(route.path)),
                ]),
            );
            let source = reference("route", &canonical);
            let target = reference("function", &route.handler);
            self.facts
                .edge("routes_to", &source, &target, "certain", route.span);
            // The handler role is only meaningful here when the handler is in
            // this file; a cross-file handler keeps its role with its own
            // contribution.
            if self.facts.declares(&target) {
                self.role_marks.push(route.handler.clone());
            }
        }
        for handler in &self.role_marks {
            self.facts.node_attribute(
                handler,
                "rust_framework_roles",
                serde_json::json!(["rust.route_handler"]),
            );
        }
    }
}

/// The first string-literal argument of an attribute, `"/path"` of
/// `#[get("/path", rank = 1)]`, or `None` when the list does not open with
/// one (a variable, a macro — anything the worker cannot prove static).
fn attr_path(attr: &syn::Attribute) -> Option<String> {
    for arg in attr_args(attr)? {
        if let syn::Expr::Lit(lit) = arg {
            if let syn::Lit::Str(text) = lit.lit {
                return Some(text.value());
            }
        } else {
            break;
        }
    }

    None
}

/// The `method` value of actix's `#[route("/path", method = "PUT")]`.
fn attr_method(attr: &syn::Attribute) -> Option<String> {
    for arg in attr_args(attr)? {
        if let syn::Expr::Assign(assign) = arg {
            if let syn::Expr::Path(left) = &*assign.left {
                if left.path.is_ident("method") {
                    if let syn::Expr::Lit(lit) = &*assign.right {
                        if let syn::Lit::Str(string) = &lit.lit {
                            return Some(string.value().to_uppercase());
                        }
                    }
                }
            }
        }
    }

    None
}

/// The parsed argument list of a list attribute, or `None` for an empty,
/// path-only, or unparsable attribute (`#[get]`, `#[inline]`).
fn attr_args(
    attr: &syn::Attribute,
) -> Option<syn::punctuated::Punctuated<syn::Expr, syn::Token![,]>> {
    let syn::Meta::List(list) = &attr.meta else {
        return None;
    };
    list.parse_args_with(syn::punctuated::Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated)
        .ok()
}

/// Every declaration a file can serve to the scan-wide index: the canonical
/// path of each struct, enum, union, trait, and top-level function, plus
/// inline modules recursed into. `mod foo;` file modules derive their own
/// entries when their defining file is indexed.
pub fn collect_declarations(module: &str, items: &[Item], out: &mut Declarations) {
    for item in items {
        match item {
            Item::Struct(node) => record(out, module, &node.ident.to_string()),
            Item::Enum(node) => record(out, module, &node.ident.to_string()),
            Item::Union(node) => record(out, module, &node.ident.to_string()),
            Item::Trait(node) => record(out, module, &node.ident.to_string()),
            Item::Fn(node) => record(out, module, &node.sig.ident.to_string()),
            Item::Mod(node) => {
                if let Some((_, inner)) = &node.content {
                    let nested = format!("{module}::{}", node.ident);
                    collect_declarations(&nested, inner, out);
                }
            }
            _ => {}
        }
    }
}

/// Bump one canonical path's count in the declaration index.
fn record(out: &mut Declarations, module: &str, name: &str) {
    *out.entry(format!("{module}::{name}")).or_insert(0) += 1;
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

impl Calls<'_, '_> {
    /// An axum `Router::route("/path", get(handler))` or actix
    /// `web::resource("/path").route(web::get().to(handler))` call, recorded
    /// as a route fact for [`Walk::finish_walk`].
    ///
    /// Only the two canonical shapes are handled: the routing shorthand
    /// (`get(handler)`, `routing::post(handler)`, `any(handler)`) for axum,
    /// and the `web::<verb>().to(handler)` chain actix builds inside `route`.
    /// Anything else — `nest`, `route_service`, a handler named by a
    /// non-path expression — contributes nothing rather than a guessed
    /// route. A dynamic path (a variable, or a `{}` segment) is diagnosed
    /// rather than guessed, mirroring `PY_DYNAMIC_ROUTE_PATH`.
    fn framework_route(&mut self, node: &syn::ExprMethodCall) {
        let has_axum = self.walk.frameworks.iter().any(|f| f == "axum");
        let has_actix = self.walk.frameworks.iter().any(|f| f == "actix");
        if !has_axum && !has_actix {
            return;
        }
        let args: Vec<&syn::Expr> = node.args.iter().collect();
        let (route_path, method, handler, framework) = if has_axum && args.len() >= 2 {
            let Some(path) = expr_string(args[0]) else {
                self.dynamic_route(node.span());
                return;
            };
            let Some((method, handler)) = routing_shorthand(args[1]) else {
                return;
            };
            (path, method, handler, "axum")
        } else if has_actix && args.len() == 1 {
            let Some((path, method, handler)) = actix_resource(&node.receiver, args[0]) else {
                return;
            };
            (path, method, handler, "actix")
        } else {
            return;
        };
        if is_dynamic_path(&route_path) {
            self.dynamic_route(node.span());
            return;
        }
        let Some(path) = path_of(handler).and_then(|path| {
            self.walk
                .resolve_path(&self.container, path)
                .map(|(target, unconfirmed)| RouteCandidate {
                    framework,
                    method,
                    path: route_path,
                    handler: target,
                    handler_unconfirmed: unconfirmed,
                    span: node.span(),
                })
        }) else {
            return;
        };
        self.walk.routes.push(path);
    }

    /// A dynamic route path diagnostic.
    fn dynamic_route(&mut self, span: proc_macro2::Span) {
        self.walk.facts.diagnostic(
            "warning",
            "RS_DYNAMIC_ROUTE_PATH",
            "Dynamic Rust route path was skipped.",
            span.start().line,
        );
    }
}

/// Whether a route path contains a dynamic segment (`<id>` for rocket,
/// `{id}` for actix, `:id` for axum). These arrive as plain string literals,
/// so the literal check alone cannot tell them apart from a static path —
/// and a route node named `GET /users/<id> => …` would be a false fact.
fn is_dynamic_path(path: &str) -> bool {
    path.bytes()
        .any(|b| matches!(b, b'{' | b'}' | b'<' | b'>' | b':'))
}

/// The string value of a string-literal expression.
fn expr_string(expr: &syn::Expr) -> Option<String> {
    let syn::Expr::Lit(lit) = expr else {
        return None;
    };
    let syn::Lit::Str(text) = &lit.lit else {
        return None;
    };

    Some(text.value())
}

/// A path expression, when the expression is a bare path.
fn path_of(expr: &syn::Expr) -> Option<&syn::Path> {
    let syn::Expr::Path(path) = expr else {
        return None;
    };

    Some(&path.path)
}

/// The `get(handler)`/`routing::post(handler)`/`any(handler)` shorthand axum
/// takes as its handler argument: the HTTP verb and the handler expression.
fn routing_shorthand(expr: &syn::Expr) -> Option<(String, &syn::Expr)> {
    let syn::Expr::Call(call) = expr else {
        return None;
    };
    let path = path_of(&call.func)?;
    let name = path.segments.last()?.ident.to_string().to_uppercase();
    if !matches!(
        name.as_str(),
        "GET" | "POST" | "PUT" | "DELETE" | "PATCH" | "OPTIONS" | "HEAD" | "ANY"
    ) {
        return None;
    }

    call.args.first().map(|handler| (name, handler))
}

/// actix's `web::resource("/path").route(web::get().to(handler))` shape:
/// the path from the `resource(...)` call and the verb from the `web::<verb>()`
/// chain.
fn actix_resource<'a>(
    receiver: &'a syn::Expr,
    arg: &'a syn::Expr,
) -> Option<(String, String, &'a syn::Expr)> {
    let path = match receiver {
        syn::Expr::Call(resource) => {
            let resource_path = path_of(&resource.func)?;
            if resource_path.segments.last()?.ident != "resource" {
                return None;
            }
            expr_string(resource.args.first()?)?
        }
        syn::Expr::MethodCall(resource) => {
            if resource.method != "resource" {
                return None;
            }
            expr_string(resource.args.first()?)?
        }
        _ => return None,
    };
    let syn::Expr::MethodCall(to) = arg else {
        return None;
    };
    if to.method != "to" {
        return None;
    }
    let method = match &*to.receiver {
        syn::Expr::Call(verb) => path_of(&verb.func)?
            .segments
            .last()?
            .ident
            .to_string()
            .to_uppercase(),
        syn::Expr::MethodCall(verb) => verb.method.to_string().to_uppercase(),
        _ => return None,
    };
    if !matches!(
        method.as_str(),
        "GET" | "POST" | "PUT" | "DELETE" | "PATCH" | "OPTIONS" | "HEAD"
    ) {
        return None;
    }

    Some((path, method, to.args.first()?))
}

impl syn::visit::Visit<'_> for Calls<'_, '_> {
    fn visit_expr_call(&mut self, node: &syn::ExprCall) {
        if let syn::Expr::Path(path) = node.func.as_ref() {
            self.visit_call(&path.path, node.span());
        }
        syn::visit::visit_expr_call(self, node);
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
        // Method calls are not call edges (the receiver's type is unknown),
        // but a `route(...)` method call is how axum and actix declare routes.
        if node.method == "route" {
            self.call_routes(node);
        }
        syn::visit::visit_expr_method_call(self, node);
    }
}

/// The `route(...)` method-call shapes of axum and actix. Dispatched from the
/// visitor so the routing logic stays one method: `framework_route` decides
/// which framework's shape applies and defers the fact.
impl Calls<'_, '_> {
    /// Look for a route declaration in one `route(...)` method call.
    fn call_routes(&mut self, node: &syn::ExprMethodCall) {
        self.framework_route(node);
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
