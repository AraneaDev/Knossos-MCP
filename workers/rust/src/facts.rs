//! Collecting one file's facts in a deterministic order.

use std::collections::{BTreeMap, HashSet};

use proc_macro2::Span;
use serde_json::Value;

use crate::protocol::{Contribution, Diagnostic, Edge, Evidence, Node};

/// Build a language-namespaced reference for one node: `rust:<kind>:<canonical>`.
///
/// Mirrors the Python worker's `ref(kind, canonical)` (`workers/python/bin/worker.py`)
/// and the shape the TypeScript worker already emits. `GraphReconciler::collectNodes`
/// (`src/Reconciliation/GraphReconciler.php`) calls `languageFromReference` on every
/// node's `local_id`, and that function throws if the reference has no `<lang>:`
/// prefix — so every node's `local_id` and every edge endpoint must be built
/// through this function, never a bare canonical name.
#[must_use]
pub fn reference(kind: &str, canonical: &str) -> String {
    format!("rust:{kind}:{canonical}")
}

/// One file's nodes, edges, and diagnostics.
pub struct Facts {
    /// Project-relative path every piece of evidence in this file points at.
    relative: String,
    /// Nodes in declaration order.
    nodes: Vec<Node>,
    /// Edges, sorted before emission.
    edges: Vec<Edge>,
    /// Problems encountered while scanning this file.
    diagnostics: Vec<Diagnostic>,
    /// `local_id` of every node emitted so far, used to drop edges whose
    /// source this contribution never declared. `GraphReconciler::resolveEdges`
    /// throws when an edge's source is not in the node map (unlike a missing
    /// target, which it tolerates), so `finish()` enforces that invariant here
    /// rather than letting a bad source reach the core.
    declared: HashSet<String>,
    /// `calls` edges recorded through [`Facts::conditional_edge`] whose
    /// target is a guess that only counts as real when this same file turns
    /// out to declare it. Held separately from `edges` because that can't be
    /// known until the whole file has been walked — a call may appear before
    /// the function it names, since items are walked in source order — so
    /// `finish()` validates each of these against the completed `declared`
    /// set instead of trusting it at the point the call was seen.
    pending_edges: Vec<Edge>,
    /// Node references this contribution uses as an edge source but does not
    /// itself declare, which the scan-wide declaration index confirmed to
    /// exist in another contribution of the same request. `finish()` keeps
    /// edges whose source is in `declared` *or* here, so an `impl` block for a
    /// type declared in another file keeps its `implements` and `contains`
    /// edges instead of scattering orphan method nodes (those nodes still
    /// live in the declaring file's contribution — nothing is duplicated).
    external: HashSet<String>,
    /// Attribute values to apply to already-emitted nodes in `finish()`,
    /// keyed by canonical name. Needed when a role is only discovered after
    /// the node was pushed (an axum handler named before its `fn` appears) or
    /// when the value is derived at the end of the walk (a file's
    /// `executable` flag lands on its module node).
    pending_attributes: Vec<(String, String, Value)>,
    /// Whether this file's crate-root module is an executable target — it
    /// declares a top-level `fn main`, or (for an extensionless script) a
    /// shebang. Applied to the module node in `finish()`.
    executable: bool,
}

impl Facts {
    /// An empty accumulator owning one file.
    #[must_use]
    pub fn new(relative: &str) -> Self {
        Self {
            relative: relative.to_owned(),
            nodes: Vec::new(),
            edges: Vec::new(),
            diagnostics: Vec::new(),
            declared: HashSet::new(),
            pending_edges: Vec::new(),
            external: HashSet::new(),
            pending_attributes: Vec::new(),
            executable: false,
        }
    }

    /// Evidence spanning from `start` to `end`, clamped to one-based lines.
    ///
    /// The pinned `proc-macro2` gates `Span::start()`/`end()` behind
    /// `#[cfg(span_locations)]`, so building without the `span-locations`
    /// feature is a compile error here, not a line-0 span at runtime. The
    /// clamp is defensive: it keeps `start_line`/`end_line` within the schema's
    /// one-based contract regardless of what a span reports.
    #[must_use]
    pub fn evidence(&self, start: Span, end: Span) -> Evidence {
        let first = start.start().line.max(1);
        Evidence {
            path: self.relative.clone(),
            start_line: first,
            end_line: end.end().line.max(first),
        }
    }

    /// Record one declared symbol.
    ///
    /// The `local_id` is derived from `kind` and `canonical` via [`reference`],
    /// so every call site gets the language prefix for free instead of having
    /// to remember to build it.
    pub fn node(&mut self, kind: &str, canonical: &str, display: &str, start: Span, end: Span) {
        let local_id = reference(kind, canonical);
        let evidence = self.evidence(start, end);
        self.nodes.push(Node {
            local_id: local_id.clone(),
            kind: kind.to_owned(),
            canonical_name: canonical.to_owned(),
            display_name: display.to_owned(),
            origin: "ast",
            confidence: "certain",
            evidence,
            attributes: BTreeMap::new(),
        });
        self.declared.insert(local_id);
    }

    /// Record one declared symbol with attributes.
    ///
    /// The [`Facts::node`] shape plus attributes, for framework facts (a
    /// route node carries its path and methods).
    pub fn node_with_attributes(
        &mut self,
        kind: &str,
        canonical: &str,
        display: &str,
        start: Span,
        end: Span,
        attributes: BTreeMap<String, Value>,
    ) {
        let local_id = reference(kind, canonical);
        let evidence = self.evidence(start, end);
        self.nodes.push(Node {
            local_id: local_id.clone(),
            kind: kind.to_owned(),
            canonical_name: canonical.to_owned(),
            display_name: display.to_owned(),
            origin: "ast",
            confidence: "certain",
            evidence,
            attributes,
        });
        self.declared.insert(local_id);
    }

    /// Add one attribute to an already-emitted node, or queue it for
    /// `finish()` when the node has not been pushed yet.
    ///
    /// The canonical name, not the `local_id`, keys the lookup, matching how
    /// the value is derived (a name resolved from a call site).
    pub fn node_attribute(&mut self, canonical: &str, key: &str, value: Value) {
        self.pending_attributes
            .push((canonical.to_owned(), key.to_owned(), value));
    }

    /// Whether a node reference was declared in this contribution.
    #[must_use]
    pub fn declares(&self, id: &str) -> bool {
        self.declared.contains(id)
    }

    /// Vouch for a node reference the scan-wide index confirmed, so edges
    /// may use it as a source without this contribution declaring it.
    ///
    /// No-op when the reference was declared here after all, which is the
    /// normal case for a same-file `impl` target.
    pub fn external_unless_declared(&mut self, id: &str) {
        if !self.declared.contains(id) {
            self.external.insert(id.to_owned());
        }
    }

    /// Mark this file as an executable script: its `fn main` or shebang makes
    /// it something the runtime invokes rather than imports, which keeps it
    /// off the dead-code report.
    pub fn mark_executable(&mut self) {
        self.executable = true;
    }

    /// Record one relationship.
    ///
    /// `source` and `target` must already be full references built via
    /// [`reference`] (or another worker's equivalent) — this method does not
    /// prefix them itself, since the endpoint's kind is not always the same as
    /// `kind`, the edge's own kind (e.g. `contains`).
    pub fn edge(
        &mut self,
        kind: &str,
        source: &str,
        target: &str,
        confidence: &'static str,
        span: Span,
    ) {
        let evidence = self.evidence(span, span);
        self.edges.push(Edge {
            kind: kind.to_owned(),
            source: source.to_owned(),
            target: target.to_owned(),
            origin: "ast",
            confidence,
            evidence,
            attributes: BTreeMap::new(),
        });
    }

    /// Record one `calls` edge whose target this file has yet to confirm:
    /// `Walk::resolve_path`'s container-relative fallback, or a single-segment
    /// callee, which `syn` can render as a rooted path without it naming one.
    ///
    /// Unlike [`Facts::edge`], this is not trusted outright: Rust cannot name
    /// anything from outside the current module without importing it or
    /// rooting the path, so such a target is only a real call when it lands on
    /// something this file declares. A local closure or function-pointer
    /// binding (`helper()`) satisfies neither, nor does a prelude or external
    /// type reached by a bare head (`String::from()`), nor the bare `default`
    /// of `<Widget>::default()` or the lone `self` of a call through an `Fn`
    /// receiver; all would otherwise produce a phantom edge to a target that
    /// names no declaration at all. `finish()` checks that once the whole
    /// file's declarations are known.
    pub fn conditional_edge(&mut self, source: &str, target: &str, span: Span) {
        self.conditional_any_edge(source, target, span, "calls", "probable");
    }

    /// Record one edge whose source is trusted but whose target is a guess
    /// that only counts as real when this file turns out to declare it — the
    /// same deferral [`Facts::conditional_edge`] gives call targets, reused
    /// by framework edges (e.g. an axum routing call naming a same-file
    /// handler that may be declared later in the file).
    pub fn conditional_any_edge(
        &mut self,
        source: &str,
        target: &str,
        span: Span,
        kind: &str,
        confidence: &'static str,
    ) {
        let evidence = self.evidence(span, span);
        self.pending_edges.push(Edge {
            kind: kind.to_owned(),
            source: source.to_owned(),
            target: target.to_owned(),
            origin: "ast",
            confidence,
            evidence,
            attributes: BTreeMap::new(),
        });
    }

    /// Record one problem.
    pub fn diagnostic(&mut self, severity: &'static str, code: &str, message: &str, line: usize) {
        self.diagnostics.push(Diagnostic {
            severity,
            code: code.to_owned(),
            message: message.to_owned(),
            evidence: Some(Evidence {
                path: self.relative.clone(),
                start_line: line.max(1),
                end_line: line.max(1),
            }),
        });
    }

    /// The finished contribution, with edges in a stable order.
    ///
    /// Sorting matches `workers/python/bin/worker.py`, so re-scanning an
    /// unchanged file produces byte-identical output and reconciliation sees no
    /// churn.
    ///
    /// Edges whose `source` this contribution never declared as a node are
    /// dropped first. A missing target is left alone: the reconciler tolerates
    /// that (it may resolve elsewhere, or become an external node), but it
    /// throws outright on an unresolved edge source, which would fail the
    /// whole scan rather than just this file's facts.
    ///
    /// A pending edge from [`Facts::conditional_edge`] additionally requires
    /// its *target* to be declared here — the one case where a missing target
    /// does get filtered, because unlike a genuinely external call (which is
    /// always qualified, and always trusted as written) an unqualified target
    /// is only guessable when it lands on a real declaration in this same
    /// file.
    ///
    /// Edges are then collapsed to the persistence identity the scanner SDK
    /// states (`docs/reference/scanner-sdk.md`): one row per
    /// kind/source/target within one owner, the same collapse `add_edge` in
    /// `workers/python/bin/worker.py` performs by keying its edge map. A module
    /// importing many symbols from one module, a function calling the same
    /// target from three branches, and a `use` group repeated across nested
    /// `mod` blocks all render the identical row otherwise. The collapse runs
    /// after the sort, so the surviving row is always the one with the earliest
    /// evidence line rather than whichever the walk happened to reach first.
    #[must_use]
    pub fn finish(mut self) -> Contribution {
        let declared = self.declared;
        self.edges
            .retain(|edge| declared.contains(&edge.source) || self.external.contains(&edge.source));
        self.edges.extend(
            self.pending_edges
                .into_iter()
                .filter(|edge| declared.contains(&edge.source) && declared.contains(&edge.target)),
        );
        // Deferred attributes land before serialisation: the module node's
        // `executable` flag and roles resolved after their node was pushed.
        if self.executable {
            let module = self.nodes[0].canonical_name.clone(); // the module node is always first
            self.pending_attributes
                .push((module, "executable".to_owned(), Value::Bool(true)));
        }
        for (canonical, key, value) in &self.pending_attributes {
            if let Some(node) = self
                .nodes
                .iter_mut()
                .find(|node| node.canonical_name == *canonical)
            {
                node.attributes.insert(key.clone(), value.clone());
            }
        }
        self.edges.sort_by(|a, b| {
            (&a.kind, &a.source, &a.target, a.evidence.start_line).cmp(&(
                &b.kind,
                &b.source,
                &b.target,
                b.evidence.start_line,
            ))
        });
        self.edges
            .dedup_by(|a, b| a.kind == b.kind && a.source == b.source && a.target == b.target);

        Contribution {
            owner_key: format!("knossos.rust:file:{}", self.relative),
            nodes: self.nodes,
            edges: self.edges,
            diagnostics: self.diagnostics,
        }
    }
}
