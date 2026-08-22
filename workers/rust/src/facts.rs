//! Collecting one file's facts in a deterministic order.

use std::collections::{BTreeMap, HashSet};

use proc_macro2::Span;

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
    pending_calls: Vec<Edge>,
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
            pending_calls: Vec::new(),
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

    /// Record one `calls` edge whose target is a bare, unqualified name
    /// guessed against the enclosing container rather than resolved through
    /// an import — `Walk::path_target`'s container-relative fallback.
    ///
    /// Unlike [`Facts::edge`], this is not trusted outright: Rust cannot call
    /// an unqualified name from outside its own module without importing it,
    /// so a guessed target for a name that was *not* imported is only a real
    /// call when the guess actually lands on something this file declares —
    /// a local closure or function-pointer binding satisfies neither, and
    /// would otherwise produce a phantom edge to a target that names no
    /// declaration at all. `finish()` checks that once the whole file's
    /// declarations are known.
    pub fn conditional_edge(&mut self, source: &str, target: &str, span: Span) {
        let evidence = self.evidence(span, span);
        self.pending_calls.push(Edge {
            kind: "calls".to_owned(),
            source: source.to_owned(),
            target: target.to_owned(),
            origin: "ast",
            confidence: "probable",
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
    /// A pending `calls` edge from [`Facts::conditional_edge`] additionally
    /// requires its *target* to be declared here — the one case where a
    /// missing target does get filtered, because unlike a genuinely external
    /// call (which is always qualified, and always trusted as written) an
    /// unqualified guess is only trustworthy when it lands on a real
    /// declaration in this same file.
    #[must_use]
    pub fn finish(mut self) -> Contribution {
        let declared = self.declared;
        self.edges.retain(|edge| declared.contains(&edge.source));
        self.edges.extend(
            self.pending_calls
                .into_iter()
                .filter(|edge| declared.contains(&edge.source) && declared.contains(&edge.target)),
        );
        self.edges.sort_by(|a, b| {
            (&a.kind, &a.source, &a.target, a.evidence.start_line).cmp(&(
                &b.kind,
                &b.source,
                &b.target,
                b.evidence.start_line,
            ))
        });

        Contribution {
            owner_key: format!("knossos.rust:file:{}", self.relative),
            nodes: self.nodes,
            edges: self.edges,
            diagnostics: self.diagnostics,
        }
    }
}
