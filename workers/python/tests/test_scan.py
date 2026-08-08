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
    (tmp_path / "latin.py").write_bytes(b"# -*- coding: latin-1 -*-\n# \xe9 accented comment\nclass Caf:\n    pass\n")
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


def test_oversized_file_costs_only_itself(worker: ModuleType, project, scan_collect) -> None:
    # The path is well-formed, so the request succeeds and the file arrives as a
    # diagnostic-only contribution; aborting would discard every other file's facts.
    root = project({"big.py": "x = 1\n" * 100, "small.py": "y = 2\n"})
    contributions = scan_collect(root, ["big.py", "small.py"], limits={"max_file_bytes": 40})
    by_owner = {c["owner_key"]: c for c in contributions}
    assert _diag_codes(by_owner["knossos.python:file:big.py"]) == ["PY_UNSCANNABLE_FILE"]
    assert by_owner["knossos.python:file:big.py"]["nodes"] == []
    assert by_owner["knossos.python:file:small.py"]["nodes"] != []


def test_unsafe_path_aborts_request(worker: ModuleType, project, scan_collect) -> None:
    root = project({"ok.py": "x = 1\n"})
    with pytest.raises(ValueError):
        scan_collect(root, ["../escape.py"])


def test_attribute_receivers_resolve_instead_of_inventing_members(
    worker: ModuleType, tmp_path: Path, scan_collect
) -> None:
    # `self.helper.use()` names a method of whatever `self.helper` holds. Reading
    # it as a member of the enclosing class invents `Owner::helper.use`, a symbol
    # no file declares, and leaves the real method looking unreferenced.
    #
    # Each receiver calls a different member, because identical relationships are
    # deduplicated and a shared member would hide two of the three failing.
    (tmp_path / "mod.py").write_text(
        "class Helper:\n"
        "    def injected_call(self) -> None:\n"
        "        pass\n"
        "\n"
        "    def built_call(self) -> None:\n"
        "        pass\n"
        "\n"
        "    def local_call(self) -> None:\n"
        "        pass\n"
        "\n"
        "    def param_call(self) -> None:\n"
        "        pass\n"
        "\n"
        "\n"
        "class Owner:\n"
        "    def __init__(self, injected: Helper) -> None:\n"
        "        self.injected = injected\n"
        "        self.built = Helper()\n"
        "\n"
        "    def run(self, passed: Helper) -> None:\n"
        "        self.injected.injected_call()\n"
        "        self.built.built_call()\n"
        "        local = Helper()\n"
        "        local.local_call()\n"
        "        passed.param_call()\n",
        encoding="utf-8",
    )

    [contribution] = scan_collect(tmp_path, ["mod.py"])

    calls = sorted(
        target
        for kind, source, target in _edges(contribution)
        if kind == "calls" and source == "py:method:mod.Owner::run"
    )
    assert calls == [
        "py:class:mod.Helper",
        "py:method:mod.Helper::built_call",
        "py:method:mod.Helper::injected_call",
        "py:method:mod.Helper::local_call",
        "py:method:mod.Helper::param_call",
    ]
    assert not any("." in target.split("::", 1)[1] for _, _, target in _edges(contribution) if "::" in target), (
        "no edge may name a member containing a dot"
    )


def test_a_receiver_reassigned_to_something_untracked_stops_resolving(
    worker: ModuleType, tmp_path: Path, scan_collect
) -> None:
    # The type is inferred from local flow, so it has to be given up when the
    # flow stops supporting it, rather than attributing a later call to a class
    # the receiver no longer holds.
    (tmp_path / "mod.py").write_text(
        "class Helper:\n"
        "    def use(self) -> None:\n"
        "        pass\n"
        "\n"
        "\n"
        "def run(source) -> None:\n"
        "    held = Helper()\n"
        "    held = source.anything()\n"
        "    held.use()\n",
        encoding="utf-8",
    )

    [contribution] = scan_collect(tmp_path, ["mod.py"])

    assert "py:method:mod.Helper::use" not in [
        target for kind, source, target in _edges(contribution) if kind == "calls"
    ]


def test_a_tracked_local_propagates_through_a_passthrough_assignment(
    worker: ModuleType, tmp_path: Path, scan_collect
) -> None:
    # A receiver is resolved against the local and parameter stacks both, so a
    # name assigned from one of them has to carry the same type across. Reading
    # only the parameter stack made `other = passed` propagate while
    # `other = local` did not, for no difference either name can observe.
    (tmp_path / "mod.py").write_text(
        "class Helper:\n"
        "    def copied_call(self) -> None:\n"
        "        pass\n"
        "\n"
        "    def stored_call(self) -> None:\n"
        "        pass\n"
        "\n"
        "\n"
        "class Owner:\n"
        "    def run(self) -> None:\n"
        "        local = Helper()\n"
        "        copied = local\n"
        "        copied.copied_call()\n"
        "        self.stored = local\n"
        "        self.stored.stored_call()\n",
        encoding="utf-8",
    )

    [contribution] = scan_collect(tmp_path, ["mod.py"])

    calls = sorted(
        target
        for kind, source, target in _edges(contribution)
        if kind == "calls" and source == "py:method:mod.Owner::run"
    )
    assert calls == [
        "py:class:mod.Helper",
        "py:method:mod.Helper::copied_call",
        "py:method:mod.Helper::stored_call",
    ]


def test_a_parameter_reassigned_to_something_untracked_stops_resolving(
    worker: ModuleType, tmp_path: Path, scan_collect
) -> None:
    # The local stack gives up a name reassigned to something untracked, but the
    # annotation outlives the assignment: unless the parameter is given up with
    # it, the stale annotation answers for a name that no longer holds it.
    (tmp_path / "mod.py").write_text(
        "class Helper:\n"
        "    def use(self) -> None:\n"
        "        pass\n"
        "\n"
        "\n"
        "def run(held: Helper, source) -> None:\n"
        "    held = source.anything()\n"
        "    held.use()\n",
        encoding="utf-8",
    )

    [contribution] = scan_collect(tmp_path, ["mod.py"])

    assert "py:method:mod.Helper::use" not in [
        target for kind, source, target in _edges(contribution) if kind == "calls"
    ]


def test_unreadable_file_costs_only_itself(monkeypatch, worker: ModuleType, project, scan_collect) -> None:
    """A file that vanishes between validation and the read.

    `safe_file` stats the path, then the loop reads it — another process can
    delete or chmod it in between, and the read raises OSError. That escaped the
    per-file handlers and aborted the whole batch, discarding facts for every
    sibling, which is exactly what the loop's isolation is meant to prevent.
    """
    root = project({"good.py": "x = 1\n", "gone.py": "y = 2\n"})
    real_read = worker.Path.read_bytes

    def flaky(self):  # type: ignore[no-untyped-def]
        if self.name == "gone.py":
            raise OSError("No such file or directory")
        return real_read(self)

    monkeypatch.setattr(worker.Path, "read_bytes", flaky)
    contributions = {c["owner_key"].rsplit(":", 1)[-1]: c for c in scan_collect(root, ["good.py", "gone.py"])}

    assert _diag_codes(contributions["gone.py"]) == ["PY_UNSCANNABLE_FILE"]
    assert contributions["good.py"]["nodes"]  # unaffected by the sibling failure


def test_top_level_relative_import_emits_no_empty_module_edge(worker: ModuleType, tmp_path: Path, scan_collect) -> None:
    # `from . import x` in a module with no parent package resolves to nothing.
    # Emitting `py:module:` for it produced a reference the graph cannot name.
    (tmp_path / "toplevel.py").write_text("from . import helper\n", encoding="utf-8")
    [contribution] = scan_collect(tmp_path, ["toplevel.py"])
    assert all(edge["target"] != "py:module:" for edge in contribution["edges"])
    assert "PY_UNRESOLVED_RELATIVE_IMPORT" in _diag_codes(contribution)


def test_package_relative_import_still_emits_its_module_edge(worker: ModuleType, project, scan_collect) -> None:
    # The guard must not swallow a relative import that does resolve.
    root = project({"pkg/__init__.py": "", "pkg/a.py": "from . import b\n", "pkg/b.py": "VALUE = 1\n"})
    [contribution] = scan_collect(root, ["pkg/a.py"])
    assert ("imports", "py:module:pkg.a", "py:module:pkg") in _edges(contribution)
    assert "PY_UNRESOLVED_RELATIVE_IMPORT" not in _diag_codes(contribution)
