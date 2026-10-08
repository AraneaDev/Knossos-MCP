"""Which files each contribution's facts were read from (``read_attribution``).

The PHPUnit suite drives the real worker through the core and compares an
incremental graph with a full one; these cases pin the shape of the reads
themselves: what each file names, what the request shares, and that every
read the request made is named somewhere with the value ``input_hashes`` gives.
"""

from __future__ import annotations

import json
from pathlib import Path
from types import ModuleType
from typing import Any

import pytest

PACKAGE = {
    "pkg/__init__.py": "from .impl import Engine\nfrom .star import *\nfrom .later import Later\n",
    "pkg/impl.py": "class Engine:\n    def run(self):\n        return 1\n",
    "pkg/star.py": "def helper():\n    return 1\n",
    "pkg/sub/__init__.py": "",
    "pkg/sub/user.py": "from ..impl import Engine\n\n\ndef use():\n    return Engine().run()\n",
    "app.py": "from pkg import Engine\n\n\ndef main():\n    return Engine().run()\n",
    "cli.py": "from pkg import Engine\n\n\ndef cli():\n    return Engine().run()\n",
    "stars.py": "from pkg import *\n\n\ndef go():\n    return helper()\n",
    "other.py": "def unrelated():\n    return 2\n",
    "src/shared.py": "def thing():\n    return 1\n",
}


def _scan(
    worker: ModuleType, root: Path, files: list[str], source_files: list[str] | None = None
) -> tuple[dict[str, Any], dict[str, dict[str, Any]]]:
    """Scan ``files`` and return the result and each contribution by its path."""
    emitted: list[dict[str, Any]] = []
    params: dict[str, Any] = {"root": str(root), "files": files}
    if source_files is not None:
        params["source_files"] = source_files
    result = worker.scan(params, emitted.append)
    return result, {item["owner_key"].rsplit(":", 1)[-1]: item for item in emitted}


def _assert_protocol(result: dict[str, Any], contributions: dict[str, dict[str, Any]], requested: list[str]) -> None:
    """Every read is confirmed by ``input_hashes``, and every input is named by some read."""
    hashes = result["input_hashes"]
    named: set[str] = set(requested)
    for reads in [result["reads"], result["unattributed_reads"], *(item["reads"] for item in contributions.values())]:
        for key, value in reads.items():
            assert key in hashes and hashes[key] == value, key
        named |= set(reads)
    assert sorted(set(hashes) - named) == []


def test_capabilities_declare_read_attribution(worker: ModuleType, capsys: pytest.CaptureFixture[str]) -> None:
    """The handshake announces attributed reads."""
    worker.handle({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}})
    manifest = json.loads(capsys.readouterr().out)["result"]
    assert "read_attribution" in manifest["capabilities"]


def test_each_file_names_the_modules_it_resolved(worker: ModuleType, project: Any) -> None:
    """An importer names the package it read; an unrelated file names none of it."""
    root = project(PACKAGE)
    files = sorted(PACKAGE)
    result, contributions = _scan(worker, root, files, files)

    assert "pkg/__init__.py" in contributions["app.py"]["reads"]
    assert "pkg/impl.py" in contributions["pkg/__init__.py"]["reads"]
    assert contributions["pkg/__init__.py"]["reads"]["pkg/later.py"] is None
    assert contributions["other.py"]["reads"] == {"other/__init__.py": None}
    assert "app.py" not in contributions["app.py"]["reads"]
    _assert_protocol(result, contributions, files)


def test_a_module_from_the_memo_is_named_by_every_importer(worker: ModuleType, project: Any) -> None:
    """The second importer takes the package from the memo and still names it."""
    root = project(PACKAGE)
    files = ["app.py", "cli.py", "pkg/sub/user.py", "stars.py"]
    result, contributions = _scan(worker, root, files, sorted(PACKAGE))

    for importer in ("app.py", "cli.py", "stars.py"):
        assert "pkg/__init__.py" in contributions[importer]["reads"], importer
    assert "pkg/impl.py" in contributions["pkg/sub/user.py"]["reads"]
    # The package's own contribution names what it re-exports, so no importer does.
    assert "pkg/star.py" not in contributions["stars.py"]["reads"]
    assert "pkg/star.py" in result["unattributed_reads"]
    _assert_protocol(result, contributions, files)


def test_a_module_scanned_before_its_importer_is_still_named(worker: ModuleType, project: Any) -> None:
    """The package's own scan fills the memo first; the importer after it still names the package."""
    root = project(PACKAGE | {"zz.py": "from pkg import Engine\n"})
    files = ["pkg/__init__.py", "pkg/impl.py", "zz.py"]
    result, contributions = _scan(worker, root, files, [*sorted(PACKAGE), "zz.py"])

    assert "pkg/__init__.py" in contributions["zz.py"]["reads"]
    _assert_protocol(result, contributions, files)


def test_a_probe_that_found_a_module_names_it(worker: ModuleType, project: Any) -> None:
    """A submodule found without being read is named by its hash, so deleting it reaches the importer."""
    root = project(PACKAGE | {"mods.py": "from pkg.sub import user\n"})
    result, contributions = _scan(worker, root, ["mods.py"], [*sorted(PACKAGE), "mods.py"])

    reads = contributions["mods.py"]["reads"]
    assert reads["pkg/sub/user.py"] == result["input_hashes"]["pkg/sub/user.py"] is not None
    _assert_protocol(result, contributions, ["mods.py"])


def test_source_roots_are_shared_and_labelled(worker: ModuleType, project: Any) -> None:
    """Source-root probes are the request's; every file carries the source roots' digest."""
    root = project(PACKAGE)
    result, contributions = _scan(worker, root, ["other.py"], sorted(PACKAGE))

    assert result["reads"] == {"pyproject.toml": None, "src/__init__.py": None}
    item = contributions["other.py"]
    assert item["program"] == "python"
    assert result["environments"] == {"python": item["environment"]}

    # A new top-level directory is no source root; one the pyproject declares is.
    (root / "aaa").mkdir()
    (root / "aaa" / "x.py").write_text("X = 1\n")
    unmoved, _ = _scan(worker, root, ["other.py"], sorted(PACKAGE))
    assert unmoved["environments"]["python"] == item["environment"]
    (root / "pyproject.toml").write_text('[tool.setuptools.packages.find]\nwhere = ["aaa"]\n')
    moved, _ = _scan(worker, root, ["other.py"], sorted(PACKAGE))
    assert moved["environments"]["python"] != item["environment"]


def test_an_undiscovered_module_names_what_it_reexports(worker: ModuleType, project: Any) -> None:
    """No contribution of its own names what a module discovery left out re-exports, so its importer does."""
    root = project(
        {
            "vendor/lib/__init__.py": "from .core import Thing\n",
            "vendor/lib/core.py": "class Thing:\n    pass\n",
            "use.py": "from vendor.lib import Thing\n",
        }
    )
    result, contributions = _scan(worker, root, ["use.py"], ["use.py"])

    assert "vendor/lib/core.py" in contributions["use.py"]["reads"]
    _assert_protocol(result, contributions, ["use.py"])


def test_a_module_read_under_another_spelling_is_still_its_own(worker: ModuleType, project: Any) -> None:
    """``src/tools.py`` read as ``src.tools`` is named ``tools``, as its own scan names it.

    Its declarations are the ones its own scan derives, so its own
    contribution names what it re-exports and the importer names only the module.
    """
    root = project(
        {
            "src/tools.py": "from .impl2 import tool\n",
            "src/impl2.py": "def tool():\n    return 1\n",
            "use.py": "from src.tools import tool\n\n\ndef u():\n    return tool()\n",
        }
    )
    files = ["src/impl2.py", "src/tools.py", "use.py"]
    result, contributions = _scan(worker, root, ["use.py"], files)

    assert ("calls", "py:function:use.u", "py:function:impl2.tool") in [
        (edge["kind"], edge["source"], edge["target"]) for edge in contributions["use.py"]["edges"]
    ]
    assert "src/tools.py" in contributions["use.py"]["reads"]
    assert "src/impl2.py" not in contributions["use.py"]["reads"]
    assert "src/impl2.py" in result["unattributed_reads"]
    _assert_protocol(result, contributions, ["use.py"])


def test_without_source_files_every_module_is_followed(worker: ModuleType, project: Any) -> None:
    """A request that does not list the discovered files names what each module re-exports."""
    root = project(PACKAGE)
    result, contributions = _scan(worker, root, ["app.py"])

    assert "pkg/star.py" in contributions["app.py"]["reads"]
    assert result["unattributed_reads"] == {}
    _assert_protocol(result, contributions, ["app.py"])


def test_an_unscannable_file_reads_nothing(worker: ModuleType, project: Any) -> None:
    """A syntax error or a refused file carries empty reads and no program."""
    root = project({"bad.py": "def (\n", "notes.txt": "x\n"})
    result, contributions = _scan(worker, root, ["bad.py", "notes.txt"], ["bad.py"])

    assert contributions["bad.py"]["reads"] == {}
    assert contributions["notes.txt"]["reads"] == {}
    assert "program" not in contributions["bad.py"]
    _assert_protocol(result, contributions, ["bad.py", "notes.txt"])


def test_large_shared_reads_travel_in_parts(
    worker: ModuleType, project: Any, capsys: pytest.CaptureFixture[str], monkeypatch: pytest.MonkeyPatch
) -> None:
    """The request's shared and unattributed reads are split like ``input_hashes``."""
    root = project(PACKAGE)
    monkeypatch.setattr(worker, "INPUT_HASHES_PART_BYTES", 40)
    params = {"root": str(root), "files": ["app.py"], "source_files": sorted(PACKAGE)}
    worker.handle({"jsonrpc": "2.0", "id": 7, "method": "scan", "params": params})
    frames = [json.loads(line) for line in capsys.readouterr().out.splitlines()]

    parts = [frame["params"] for frame in frames if frame.get("method") == "scan/input_hashes"]
    final = frames[-1]["result"]
    shared: dict[str, Any] = dict(final["reads"])
    unattributed: dict[str, Any] = dict(final["unattributed_reads"])
    for part in parts:
        shared |= part.get("reads", {})
        unattributed |= part.get("unattributed_reads", {})
    assert set(shared) == {"pyproject.toml", "src/__init__.py"}
    assert {"pkg/impl.py", "pkg/star.py"} <= set(unattributed)
    assert any("unattributed_reads" in part for part in parts)


def test_a_script_ruled_out_by_its_shebang_is_named_by_its_hash(worker: ModuleType, project: Any) -> None:
    """A suffixless file that is not Python was judged on its bytes; editing them can make it the module."""
    root = project({"config": "#!/bin/sh\necho hi\n", "use.py": "from config import x\n"})
    result, contributions = _scan(worker, root, ["use.py"], ["use.py"])

    assert contributions["use.py"]["reads"]["config"] == result["input_hashes"]["config"] is not None
    _assert_protocol(result, contributions, ["use.py"])


def test_a_failure_while_following_a_reexport_names_what_was_read(
    worker: ModuleType, project: Any, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A module that fails mid-way still has its reads named, and the file it fails says its reads are partial."""
    real = worker.top_level_declarations

    def failing(tree: Any, module: str) -> dict[str, str]:
        if module == "pkg.star":
            raise RuntimeError("injected")
        return real(tree, module)

    monkeypatch.setattr(worker, "top_level_declarations", failing)
    root = project(PACKAGE)
    files = ["app.py", "cli.py", "other.py"]
    result, contributions = _scan(worker, root, files, sorted(PACKAGE))

    assert contributions["app.py"]["reads_partial"] is True
    assert contributions["app.py"]["diagnostics"][0]["code"] == "PY_INTERNAL_ERROR"
    assert "pkg/star.py" in contributions["app.py"]["reads"]
    assert "reads_partial" not in contributions["other.py"]
    _assert_protocol(result, contributions, files)
