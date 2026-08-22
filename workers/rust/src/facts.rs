//! Collecting one file's facts in a deterministic order.

use std::collections::BTreeMap;

use proc_macro2::Span;

use crate::protocol::{Contribution, Diagnostic, Edge, Evidence, Node};

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
        }
    }

    /// Evidence spanning from `start` to `end`, clamped to one-based lines.
    ///
    /// `Span::start()` reports line 0 when `proc-macro2` lacks the
    /// `span-locations` feature, so the clamp keeps a misconfigured build from
    /// emitting facts the schema rejects. The regression test in
    /// `tests/scan.rs` is what actually catches that case.
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
    pub fn node(
        &mut self,
        local_id: &str,
        kind: &str,
        canonical: &str,
        display: &str,
        start: Span,
        end: Span,
    ) {
        let evidence = self.evidence(start, end);
        self.nodes.push(Node {
            local_id: local_id.to_owned(),
            kind: kind.to_owned(),
            canonical_name: canonical.to_owned(),
            display_name: display.to_owned(),
            origin: "ast",
            confidence: "certain",
            evidence,
            attributes: BTreeMap::new(),
        });
    }

    /// Record one relationship.
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
    #[must_use]
    pub fn finish(mut self) -> Contribution {
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
