//! Resolving the paths a file writes to the canonical paths the graph uses.
//!
//! A path is taken through this file's imports and glob imports, the
//! `crate`, `self`, `super` and `Self` roots, the `mod` declarations that
//! place a module elsewhere, and finally the crate-wide declaration index.
//! An answer that rests on a guess says so, so a caller can demand that
//! this file confirms it. The file's own `use` lines are read here first,
//! since they are what every later path resolves through.

use std::collections::BTreeMap;

use syn::spanned::Spanned;
use syn::{Item, Type};

use super::calls::POINTER_MARK;
use super::placement::mod_child;
use super::state::Walk;
use crate::facts::reference;
use crate::resolve::{flatten_use, glob_prefixes, ident_name, parent_module, rebase};

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
    /// `workers/python/bin/knossos_python/collector.py` splits the two. A `use`
    /// overwhelmingly names a struct, trait, or function, whose real node kind
    /// is `class`, `interface`, or `function`, so targeting
    /// `rust:module:a::b::C` resolved against nothing and made the reconciler
    /// synthesise an external module that shadowed the very symbol the file had
    /// already declared. The module is a node the graph genuinely holds. Two
    /// shapes need no truncation: a single-segment `use foo;` already names a
    /// module (see [`parent_module`]), and a `self` leaf already names its
    /// prefix module (see [`crate::resolve::UseLeaf::names_module`]).
    ///
    /// Several symbols imported from the same module produce one edge, not one
    /// per symbol, matching the Python worker, which emits a single `imports`
    /// edge per `from` statement. `Facts::finish` performs that collapse for the
    /// whole contribution rather than per `use` line, so a module importing from
    /// the same place on ten separate lines still stores one row: the scanner
    /// SDK's persistence identity is kind/source/target within one owner, and
    /// every one of those rows is identical.
    pub(super) fn collect_uses(&mut self, container: &str, items: &[Item]) {
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
                        // The import names the module the source wrote; the
                        // alias, what that name is declared as.
                        self.import(&source, &module, item.span());
                        if !matches!(node.vis, syn::Visibility::Inherited) && leaf.alias != "_" {
                            let name = format!("{container}::{}", leaf.alias);
                            match self.exports.get(&name) {
                                Some(Some(existing)) if *existing != full => {
                                    self.exports.insert(name, None);
                                }
                                Some(_) => {}
                                None => {
                                    self.exports.insert(name, Some(full.clone()));
                                }
                            }
                        }
                        let full = self.exported(full);
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
    pub(super) fn path_target(&self, container: &str, path: &syn::Path) -> Option<String> {
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
    /// nothing becomes a fabricated external node. See
    /// [`Calls::visit_call`](super::state::Calls::visit_call).
    ///
    /// A path a visible `use` re-exports (`crate::visit::go` under `pub use
    /// cfg::go;`) names the item it imports, which is where the graph
    /// declares it (see [`Walk::exported`]).
    pub(super) fn resolve_path(&self, container: &str, path: &syn::Path) -> Option<(String, bool)> {
        self.resolve_written(container, path)
            .map(|(target, unconfirmed)| (self.exported(target), unconfirmed))
    }

    /// [`Walk::resolve_path`] before re-exports are followed: the path as
    /// the file's imports, roots and `mod` declarations place it.
    fn resolve_written(&self, container: &str, path: &syn::Path) -> Option<(String, bool)> {
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
        if let Some(answer) = self.through_child_module(container, &rendered) {
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
        // A prelude trait is in scope everywhere, while a crate-root item
        // of that name is in scope only in the crate root: past the module's
        // own declarations and its globs, the prelude wins.
        let mut candidates = self.index_candidates(container, &rendered);
        if single_segment && standard_trait(&rendered).is_some() {
            let own = format!("{container}::{rendered}");
            let globbed: Vec<String> = self
                .globs
                .iter()
                .filter(|(declared_in, _)| declared_in == container)
                .map(|(_, glob)| format!("{glob}::{rendered}"))
                .collect();
            candidates.retain(|candidate| *candidate == own || globbed.contains(candidate));
        }
        for candidate in candidates {
            match self.declarations.get(&candidate) {
                Some(1) => return Some((candidate, false)),
                // Ambiguous across files: guessing would silently prefer one
                // declaration over another, so the scoping winner must not
                // fall through to a weaker candidate.
                Some(_) => return None,
                None => {}
            }
        }

        // A standard trait named bare that nothing in scope declares or
        // imports is the standard library's: `impl From<u8> for Str` in
        // `crate::util` implements `std::convert::From`, never a
        // `crate::util::From` the fallback below would guess.
        if single_segment {
            if let Some(standard) = standard_trait(&rendered) {
                return Some((standard.to_owned(), false));
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

    /// A multi-segment path whose head is both an import and a child module
    /// the import reaches into: `parse::helper()` beside `mod parse;` and
    /// `pub use parse::parse;` names the module's `helper`, since only a
    /// module has items below it. `None` for any other path.
    #[allow(clippy::option_option)]
    fn through_child_module(
        &self,
        container: &str,
        rendered: &str,
    ) -> Option<Option<(String, bool)>> {
        let (head, rest) = rendered.split_once("::")?;
        let module = format!("{container}::{head}");
        let imported = self.aliases.expand(head)?;
        if !imported.starts_with(&format!("{module}::")) {
            return None;
        }

        Some(
            self.renamed(format!("{module}::{rest}"))
                .map(|target| (target, false)),
        )
    }

    /// Resolve the glob imports [`Walk::collect_uses`] set aside, now that
    /// every alias in the file is known: `use parts::*;` beside
    /// `use crate::left as parts;` imports from `crate::left`, whichever line
    /// comes first.
    pub(super) fn resolve_globs(&mut self) {
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
    pub(super) fn crate_root(&self) -> &str {
        self.module.split("::").next().unwrap_or("crate")
    }

    /// A path as written, with its root as the graph names it: `crate` is
    /// this file's crate root (a target root's own module, see
    /// [`Walk::crate_module`]), and a project library's crate
    /// name its root (see [`Walk::library_path`]).
    pub(super) fn anchor_crate(&self, path: &str) -> String {
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
    /// [`Declarations::renamed`](super::declarations::Declarations::renamed)); `None` when
    /// one of those is ambiguous.
    /// A path outside the project is returned unchanged and asks nothing.
    pub(super) fn renamed(&self, path: String) -> Option<String> {
        if !self.in_project(&path) {
            return Some(path);
        }
        match self.declarations.renamed(&path) {
            Some(placed) => placed,
            None => Some(path),
        }
    }

    /// A path inside the project, followed through every visible `use` that
    /// re-exports it or a module above it (see
    /// [`Declarations::exported`](super::declarations::Declarations::exported)),
    /// to the path the graph declares the item at: `crate::visit::go` under
    /// `pub use cfg::go;` is `crate::visit::cfg::go`. A chain of re-exports
    /// is followed to its end, within a bound a cycle cannot outrun. A path
    /// outside the project, or one nothing re-exports, is returned unchanged.
    pub(super) fn exported(&self, path: String) -> String {
        /// Re-exports followed for one path at most.
        const MAX_HOPS: usize = 8;
        // `Declarations::exported` never rewrites a path through a prefix
        // whose re-export targets something below it, so a path cannot grow
        // by matching one re-export again; the visited set and the bound
        // end a cycle of re-exports.
        let mut current = path;
        let mut visited = std::collections::BTreeSet::new();
        for _ in 0..MAX_HOPS {
            if !self.in_project(&current) || !visited.insert(current.clone()) {
                break;
            }
            let Some(next) = self.declarations.exported(&current) else {
                break;
            };
            match self.renamed(next) {
                Some(next) if next != current => current = next,
                _ => break,
            }
        }

        current
    }

    /// Whether `path` is rooted in this project: at this file's crate root,
    /// its target root's crate, or a workspace member's crate.
    fn in_project(&self, path: &str) -> bool {
        let head = path.split("::").next().unwrap_or(path);
        head == self.crate_root()
            || head == self.crate_module.split("::").next().unwrap_or("")
            || self.layout.is_project_root(head)
    }

    /// The declared type of `owner`'s field `field`: from this file when it
    /// declares the struct, else from the declaration index, which holds
    /// what the declaring file's own imports resolved it to.
    pub(super) fn field_type(&self, owner: &str, field: &str) -> Option<String> {
        if let Some(fields) = self.struct_fields.get(owner) {
            return fields.get(field).cloned();
        }
        let declared = self.declarations.field_type(owner, field)?;
        let (mark, held) = match declared.strip_prefix(POINTER_MARK) {
            Some(held) => (POINTER_MARK, held.to_owned()),
            None => ("", declared),
        };

        self.renamed(held)
            .map(|target| format!("{mark}{}", self.exported(target)))
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
    pub(super) fn type_path(&self, container: &str, ty: &Type) -> Option<String> {
        let Type::Path(path) = ty else {
            return None;
        };

        self.path_target(container, &path.path)
    }

    /// The canonical path of a type a receiver holds, seen through `&`,
    /// `&mut`, parentheses and the smart pointers that dereference to what
    /// they hold (`Box<T>`, `Rc<T>`, `Arc<T>`), or None for anything that is
    /// not a plain path. Any other generic (`Option<T>`, `Vec<T>`) is the
    /// type itself, whose methods are not `T`'s.
    pub(super) fn receiver_type(&self, container: &str, ty: &Type) -> Option<String> {
        match ty {
            Type::Reference(reference) => self.receiver_type(container, &reference.elem),
            Type::Paren(inner) => self.receiver_type(container, &inner.elem),
            Type::Path(path) if path.qself.is_none() => match pointee(&path.path) {
                Some(inner) => self
                    .receiver_type(container, inner)
                    .map(|held| format!("{POINTER_MARK}{}", super::calls::pointee_of(&held))),
                None => self.stated_type(container, &path.path),
            },
            _ => None,
        }
    }

    /// The canonical path of the type a path names, when something vouches
    /// for it: rooted, imported, or declared where it could be named. A lone
    /// name only the enclosing-module guess places (`Vec`, `Option`, `char`,
    /// `String`) names no type of this crate, so it gives none.
    pub(super) fn stated_type(&self, container: &str, path: &syn::Path) -> Option<String> {
        // A primitive is never a type of this crate, whatever a module
        // declares by that name in the value namespace (`fn char()`).
        if path.leading_colon.is_none()
            && path.segments.len() == 1
            && is_primitive(&ident_name(&path.segments[0].ident))
        {
            return None;
        }
        // `Self` is the impl's type, however `resolve_path` flags it for calls.
        let is_self = path.is_ident("Self");
        match self.resolve_path(container, path)? {
            (target, true)
                if path.segments.len() == 1
                    && !is_self
                    && !self.own_declarations.contains(&target) =>
            {
                None
            }
            (target, _) => Some(target),
        }
    }
}

/// The standard library path of a trait the Rust 2021 prelude brings into
/// every module, by its bare name. A trait outside the prelude (`Display`,
/// `Hash`) is in scope only through an import, which resolves it.
fn standard_trait(name: &str) -> Option<&'static str> {
    Some(match name {
        "AsMut" => "std::convert::AsMut",
        "AsRef" => "std::convert::AsRef",
        "From" => "std::convert::From",
        "Into" => "std::convert::Into",
        "TryFrom" => "std::convert::TryFrom",
        "TryInto" => "std::convert::TryInto",
        "Clone" => "std::clone::Clone",
        "Copy" => "std::marker::Copy",
        "Send" => "std::marker::Send",
        "Sized" => "std::marker::Sized",
        "Sync" => "std::marker::Sync",
        "Unpin" => "std::marker::Unpin",
        "Default" => "std::default::Default",
        "Eq" => "std::cmp::Eq",
        "Ord" => "std::cmp::Ord",
        "PartialEq" => "std::cmp::PartialEq",
        "PartialOrd" => "std::cmp::PartialOrd",
        "DoubleEndedIterator" => "std::iter::DoubleEndedIterator",
        "ExactSizeIterator" => "std::iter::ExactSizeIterator",
        "Extend" => "std::iter::Extend",
        "FromIterator" => "std::iter::FromIterator",
        "IntoIterator" => "std::iter::IntoIterator",
        "Iterator" => "std::iter::Iterator",
        "Drop" => "std::ops::Drop",
        "Fn" => "std::ops::Fn",
        "FnMut" => "std::ops::FnMut",
        "FnOnce" => "std::ops::FnOnce",
        "ToOwned" => "std::borrow::ToOwned",
        "ToString" => "std::string::ToString",
        _ => return None,
    })
}

/// Whether a bare name is a primitive type or a type the prelude brings
/// into every module (`String`, `Vec`, `Option`, `Result`, `Box`).
pub(super) fn is_standard_type(name: &str) -> bool {
    is_primitive(name) || matches!(name, "Box" | "Option" | "Result" | "String" | "Vec")
}

/// Whether a bare name is one of Rust's primitive types.
fn is_primitive(name: &str) -> bool {
    matches!(
        name,
        "bool"
            | "char"
            | "str"
            | "u8"
            | "u16"
            | "u32"
            | "u64"
            | "u128"
            | "usize"
            | "i8"
            | "i16"
            | "i32"
            | "i64"
            | "i128"
            | "isize"
            | "f32"
            | "f64"
    )
}

/// The type a `Box<T>`, `Rc<T>` or `Arc<T>` holds, however its path is
/// qualified (`std::sync::Arc<T>`), or None for any other type.
fn pointee(path: &syn::Path) -> Option<&Type> {
    let last = path.segments.last()?;
    if !matches!(ident_name(&last.ident).as_str(), "Box" | "Rc" | "Arc") {
        return None;
    }
    let syn::PathArguments::AngleBracketed(arguments) = &last.arguments else {
        return None;
    };
    let mut types = arguments.args.iter().filter_map(|argument| match argument {
        syn::GenericArgument::Type(ty) => Some(ty),
        _ => None,
    });
    let inner = types.next()?;

    types.next().is_none().then_some(inner)
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
