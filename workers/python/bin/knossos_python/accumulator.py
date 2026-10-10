"""The per-file fact accumulator: what a scan of one file collected, rendered deterministically.

Its own module so the framework enrichers and the collector that drives them
can both take it without importing each other.
"""

import ast
import json
from typing import Any

from .ast_helpers import evidence


class PythonFactAccumulator:
    """Store and deterministically render facts collected for one Python file."""

    def __init__(self, relative: str) -> None:
        self.relative = relative
        self.nodes: dict[str, dict[str, Any]] = {}
        self.edges: dict[str, dict[str, Any]] = {}
        self.diagnostics: list[dict[str, Any]] = []

    def add_node(
        self,
        local_id: str,
        kind: str,
        canonical: str,
        display: str,
        node: ast.AST,
        attributes: dict[str, Any] | None = None,
    ) -> None:
        self.nodes.setdefault(
            local_id,
            {
                "local_id": local_id,
                "kind": kind,
                "canonical_name": canonical,
                "display_name": display,
                "origin": "ast",
                "confidence": "certain",
                "evidence": evidence(self.relative, node),
                "attributes": attributes or {},
            },
        )

    def add_edge(
        self, kind: str, source: str, target: str, node: ast.AST, attributes: dict[str, Any] | None = None
    ) -> None:
        added: dict[str, Any] = attributes or {}
        item: dict[str, Any] = {
            "kind": kind,
            "source": source,
            "target": target,
            "origin": "ast",
            "confidence": "certain",
            "evidence": evidence(self.relative, node),
            "attributes": added,
        }
        key = json.dumps([kind, source, target], sort_keys=True)
        existing = self.edges.get(key)
        if existing is None:
            self.edges[key] = item
            return
        # Two statements between the same pair are one edge, and the first
        # one's attributes stand. When either is type-only, which kinds were
        # seen is kept, so one runtime import keeps the dependency real
        # whichever came first. A missing marker is a runtime import.
        kept: dict[str, Any] = existing["attributes"]
        if kind == "imports" and ("type_only" in kept or "type_only" in added):
            kept["type_only_variants"] = sorted(
                {
                    kept.get("type_only", False),
                    *kept.get("type_only_variants", []),
                    added.get("type_only", False),
                }
            )

    def add_diagnostic(self, code: str, message: str, node: ast.AST) -> None:
        self.diagnostics.append(
            {"severity": "warning", "code": code, "message": message, "evidence": evidence(self.relative, node)}
        )

    def result(self) -> dict[str, Any]:
        return {
            "owner_key": f"knossos.python:file:{self.relative}",
            "nodes": sorted(self.nodes.values(), key=lambda item: item["local_id"]),
            "edges": sorted(
                self.edges.values(),
                key=lambda item: (item["kind"], item["source"], item["target"], item["evidence"]["start_line"]),
            ),
            "diagnostics": self.diagnostics,
        }
