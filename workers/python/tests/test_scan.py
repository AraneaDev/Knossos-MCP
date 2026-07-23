"""End-to-end behaviour of ``worker.scan`` over crafted project trees."""

from __future__ import annotations

from pathlib import Path
from types import ModuleType

import pytest


def _diag_codes(contribution: dict) -> list[str]:
    return [item["code"] for item in contribution["diagnostics"]]


def _edges(contribution: dict) -> list[tuple[str, str, str]]:
    return [(edge["kind"], edge["source"], edge["target"]) for edge in contribution["edges"]]


def test_bom_prefixed_file_parses_without_syntax_error(worker: ModuleType, tmp_path: Path, scan_collect) -> None:
    # A UTF-8 BOM is legal Python; reading text as utf-8 would misreport it.
    (tmp_path / "boms.py").write_bytes(b"\xef\xbb\xbfclass Widget:\n    pass\n")
    [contribution] = scan_collect(tmp_path, ["boms.py"])
    assert _diag_codes(contribution) == []
    assert any(node["kind"] == "class" and node["display_name"] == "Widget" for node in contribution["nodes"])


def test_pep263_encoded_file_parses_without_syntax_error(worker: ModuleType, tmp_path: Path, scan_collect) -> None:
    # A PEP 263 coding cookie with a latin-1 byte would raise UnicodeDecodeError
    # under utf-8 text decoding; parsing bytes honours the declared encoding.
    (tmp_path / "latin.py").write_bytes(
        b"# -*- coding: latin-1 -*-\n# \xe9 accented comment\nclass Caf:\n    pass\n"
    )
    [contribution] = scan_collect(tmp_path, ["latin.py"])
    assert _diag_codes(contribution) == []
    assert any(node["kind"] == "class" for node in contribution["nodes"])


def test_src_layout_imports_resolve_to_declared_nodes(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "src/app/__init__.py": "",
            "src/app/models.py": "class User:\n    pass\n",
            "src/app/service.py": "from app.models import User\n\n\ndef make() -> None:\n    User()\n",
        }
    )
    [contribution] = scan_collect(root, ["src/app/service.py"])
    module = next(node for node in contribution["nodes"] if node["kind"] == "module")
    # Source root `src` is stripped so the module id matches the import path.
    assert module["canonical_name"] == "app.service"
    assert ("imports", "py:module:app.service", "py:module:app.models") in _edges(contribution)
    # The reference resolves to the real declared class, not an external symbol.
    assert ("calls", "py:function:app.service.make", "py:class:app.models.User") in _edges(contribution)


def test_import_targets_are_batch_independent(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "pkg/__init__.py": "",
            "pkg/models.py": "class Account:\n    pass\n",
            "pkg/api.py": "from pkg.models import Account\n",
        }
    )
    # Whether or not models.py shares the request, the import resolves identically.
    with_target = scan_collect(root, ["pkg/api.py", "pkg/models.py"])
    without_target = scan_collect(root, ["pkg/api.py"])
    api_with = next(c for c in with_target if c["owner_key"].endswith("pkg/api.py"))
    api_without = next(c for c in without_target if c["owner_key"].endswith("pkg/api.py"))
    assert api_with["edges"] == api_without["edges"]
    assert ("imports", "py:module:pkg.api", "py:module:pkg.models") in _edges(api_without)


def test_syntax_error_isolated_to_one_file(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "good.py": "class Ok:\n    pass\n",
            "bad.py": "def (:\n",
        }
    )
    contributions = {c["owner_key"].rsplit(":", 1)[-1]: c for c in scan_collect(root, ["good.py", "bad.py"])}
    assert _diag_codes(contributions["bad.py"]) == ["PY_SYNTAX_ERROR"]
    # The healthy file still produced its facts.
    assert any(node["kind"] == "class" for node in contributions["good.py"]["nodes"])


def test_internal_error_becomes_per_file_diagnostic(monkeypatch, worker: ModuleType, project, scan_collect) -> None:
    root = project({"good.py": "x = 1\n", "boom.py": "y = 2\n"})

    real_collect = worker.PythonAstFactCollector.collect

    def flaky(self):  # type: ignore[no-untyped-def]
        if self.relative == "boom.py":
            raise RuntimeError("collector exploded")
        return real_collect(self)

    monkeypatch.setattr(worker.PythonAstFactCollector, "collect", flaky)
    contributions = {c["owner_key"].rsplit(":", 1)[-1]: c for c in scan_collect(root, ["good.py", "boom.py"])}
    assert _diag_codes(contributions["boom.py"]) == ["PY_INTERNAL_ERROR"]
    assert contributions["good.py"]["nodes"]  # unaffected by the sibling failure


def test_recursion_error_during_parse_is_isolated(monkeypatch, worker: ModuleType, project, scan_collect) -> None:
    root = project({"deep.py": "x = 1\n"})
    original = worker.ast.parse

    def exploding(*args, **kwargs):  # type: ignore[no-untyped-def]
        raise RecursionError("too deep")

    monkeypatch.setattr(worker.ast, "parse", exploding)
    try:
        [contribution] = scan_collect(root, ["deep.py"])
    finally:
        monkeypatch.setattr(worker.ast, "parse", original)
    assert _diag_codes(contribution) == ["PY_INTERNAL_ERROR"]


def test_module_collision_emits_diagnostic(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "orders.py": "class Order:\n    pass\n",
            "orders/__init__.py": "z = 1\n",
        }
    )
    [contribution] = scan_collect(root, ["orders.py"])
    assert "PY_MODULE_ID_COLLISION" in _diag_codes(contribution)


def test_scan_is_deterministic_and_sorted(worker: ModuleType, project, scan_collect) -> None:
    root = project(
        {
            "pkg/__init__.py": "",
            "pkg/a.py": "class A:\n    pass\n",
            "pkg/b.py": "class B:\n    pass\n",
        }
    )
    first = scan_collect(root, ["pkg/b.py", "pkg/a.py"])
    second = scan_collect(root, ["pkg/a.py", "pkg/b.py"])
    assert [c["owner_key"] for c in first] == [c["owner_key"] for c in second]
    # Inputs are emitted in sorted relative-path order regardless of request order.
    assert [c["owner_key"] for c in first] == [
        "knossos.python:file:pkg/a.py",
        "knossos.python:file:pkg/b.py",
    ]


def test_oversized_file_aborts_request(worker: ModuleType, project, scan_collect) -> None:
    root = project({"big.py": "x = 1\n" * 100})
    with pytest.raises(ValueError):
        scan_collect(root, ["big.py"], limits={"max_file_bytes": 5})


def test_unsafe_path_aborts_request(worker: ModuleType, project, scan_collect) -> None:
    root = project({"ok.py": "x = 1\n"})
    with pytest.raises(ValueError):
        scan_collect(root, ["../escape.py"])
