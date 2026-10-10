//! Resolving the paths a file writes to the canonical paths the graph uses.
//!
//! A path is taken through this file's imports and glob imports, the
//! `crate`, `self`, `super` and `Self` roots, the `mod` declarations that
//! place a module elsewhere, and finally the crate-wide declaration index.
//! An answer that rests on a guess says so, so a caller can demand that
//! this file confirms it.

use syn::Type;

use super::Walk;
use crate::facts::reference;
use crate::resolve::{ident_name, rebase};

impl Walk<'_> {
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
    /// [`Calls::visit_call`](super::Calls::visit_call).
    pub(super) fn resolve_path(&self, container: &str, path: &syn::Path) -> Option<(String, bool)> {
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
    /// [`Declarations::renamed`](super::Declarations::renamed)); `None` when
    /// one of those is ambiguous.
    /// A path outside the project is returned unchanged and asks nothing.
    pub(super) fn renamed(&self, path: String) -> Option<String> {
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
    pub(super) fn type_path(&self, container: &str, ty: &Type) -> Option<String> {
        let Type::Path(path) = ty else {
            return None;
        };

        self.path_target(container, &path.path)
    }

    /// The canonical path of a type a receiver holds, seen through `&`,
    /// `&mut` and parentheses, or None for anything that is not a plain path.
    pub(super) fn receiver_type(&self, container: &str, ty: &Type) -> Option<String> {
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
