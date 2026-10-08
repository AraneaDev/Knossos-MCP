"""Module ids: which directories are source roots, and which file owns an id.

The PHPUnit suite checks these end to end through the real worker; these cases
pin the pieces: the pyproject's declared roots, the src layout, and how a file
whose location another file owns is named.
"""

from __future__ import annotations

from pathlib import Path
from types import ModuleType
from typing import Any

import pytest


def _scan(worker: ModuleType, root: Path, files: list[str]) -> dict[str, dict[str, Any]]:
    """Scan ``files`` as the whole project and return each contribution by its path."""
    emitted: list[dict[str, Any]] = []
    worker.scan({"root": str(root), "files": files, "source_files": files}, emitted.append)
    return {item["owner_key"].rsplit(":", 1)[-1]: item for item in emitted}


def _ids(contribution: dict[str, Any]) -> set[str]:
    return {node["local_id"] for node in contribution["nodes"]}


def _edges(contribution: dict[str, Any]) -> set[tuple[str, str, str]]:
    return {(edge["kind"], edge["source"], edge["target"]) for edge in contribution["edges"]}


@pytest.mark.parametrize(
    ("pyproject", "roots"),
    [
        ('[tool.setuptools.packages.find]\nwhere = ["lib", "./code"]\n', [(), ("code",), ("lib",)]),
        ('[tool.setuptools.package-dir]\n"" = "code"\n', [(), ("code",)]),
        ('[tool.poetry]\npackages = [{ include = "a", from = "code" }, { include = "b" }, "c"]\n', [(), ("code",)]),
        ('[tool.hatch.build.targets.wheel]\npackages = ["code/a", "b", 3]\n', [(), ("code",)]),
        ('[tool.pdm.build]\npackage-dir = "code/py"\n', [(), ("code", "py")]),
        ('[tool.setuptools.packages.find]\nwhere = ["../up", "/abs", "a\\\\b", "x\\u0001", "", 4]\n', [()]),
        ("[tool.setuptools\n", [()]),
        ("tool = 1\n", [()]),
    ],
)
def test_the_pyproject_declares_source_roots(worker: ModuleType, project: Any, pyproject: str, roots: list) -> None:
    """Each build backend's package directory is a source root; nothing unsafe or unparsable is."""
    root = project({"pyproject.toml": pyproject})
    assert worker.ProjectModuleIndex(root, 2_000_000).prefixes == roots


def test_src_is_a_source_root_only_without_a_marker(worker: ModuleType, project: Any) -> None:
    """``src/`` is the src layout's root, or a package with ``__init__.py``; no other directory is a root."""
    root = project({"src/a.py": "", "app/b.py": "", "tests/c.py": ""})
    index = worker.ProjectModuleIndex(root, 2_000_000)
    assert index.prefixes == [(), ("src",)]
    assert index.read_hashes == {"src/__init__.py": None}
    assert [index.module_for(path) for path in ("src/a.py", "app/b.py", "tests/c.py")] == ["a", "app.b", "tests.c"]

    (root / "src" / "__init__.py").write_text("")
    packaged = worker.ProjectModuleIndex(root, 2_000_000)
    assert packaged.prefixes == [()]
    assert packaged.module_for("src/a.py") == "src.a"


def test_an_excluded_or_oversized_pyproject_is_not_read(worker: ModuleType, project: Any) -> None:
    """A pyproject discovery leaves out, or one over the byte cap, declares nothing."""
    root = project({"pyproject.toml": '[tool.setuptools.packages.find]\nwhere = ["code"]\n'})
    rules = {"segments": [], "prefixes": [], "sequences": [], "suffixes": [], "path_prefixes": ["pyproject.toml"]}
    excluded = worker.Exclusions(rules | {"patterns": []})
    assert worker.ProjectModuleIndex(root, 2_000_000, excluded).prefixes == [()]
    assert worker.ProjectModuleIndex(root, 10).prefixes == [()]
    assert worker.ProjectModuleIndex(root, 2_000_000).prefixes == [(), ("code",)]


def test_a_file_whose_location_another_file_owns_is_named_by_its_path(worker: ModuleType, project: Any) -> None:
    """The file an import finds keeps the id; the other is ``<location>.<path>``, and both say which owns it."""
    root = project({"utils.py": "", "src/utils.py": "", "pkg/mod.py": "", "pkg/mod.pyi": "", "pkg/ext.pyi": ""})
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.file_identity("utils.py", root / "utils.py") == ("utils", "utils", root / "utils.py")
    assert index.file_identity("src/utils.py", root / "src/utils.py") == (
        "utils",
        "utils.<src/utils.py>",
        root / "utils.py",
    )
    assert index.file_identity("pkg/mod.pyi", root / "pkg/mod.pyi")[1] == "pkg.mod.<pkg/mod.pyi>"
    # No import finds a stub, so one alone keeps its location and owns nothing.
    assert index.file_identity("pkg/ext.pyi", root / "pkg/ext.pyi") == ("pkg.ext", "pkg.ext", None)
    # Every spelling of an import names the file it finds by that file's own id.
    assert index.canonical_module("src.utils") == "utils.<src/utils.py>"
    assert index.canonical_module("utils") == "utils"
    assert index.canonical_module("missing.mod") == "missing.mod"
    # A path-qualified id is never probed as a dotted name.
    assert index.module_file("utils.<src/utils.py>") is None


def test_a_loser_resolves_its_own_names_to_itself(worker: ModuleType, project: Any) -> None:
    """``src/utils.py``'s own calls stay inside it, with a collision warning naming the owner."""
    root = project(
        {
            "utils.py": "def inner():\n    return 0\n",
            "src/utils.py": "def inner():\n    return 1\n\n\ndef outer():\n    return inner()\n",
        }
    )
    contributions = _scan(worker, root, ["src/utils.py", "utils.py"])

    loser = contributions["src/utils.py"]
    assert ("calls", "py:function:utils.<src/utils.py>.outer", "py:function:utils.<src/utils.py>.inner") in _edges(
        loser
    )
    assert [item["code"] for item in loser["diagnostics"]] == ["PY_MODULE_ID_COLLISION"]
    assert "'utils.py'" in loser["diagnostics"][0]["message"]
    assert contributions["utils.py"]["diagnostics"] == []


def test_a_stub_is_a_declaration_named_apart_from_its_module(worker: ModuleType, project: Any) -> None:
    """Every node of a ``.pyi`` is a declaration; beside its module it takes a path-qualified id quietly."""
    root = project(
        {"pkg/__init__.py": "", "pkg/mod.py": "def f():\n    return 1\n", "pkg/mod.pyi": "def f() -> int: ...\n"}
    )
    stub = _scan(worker, root, ["pkg/__init__.py", "pkg/mod.py", "pkg/mod.pyi"])["pkg/mod.pyi"]

    assert _ids(stub) == {"py:module:pkg.mod.<pkg/mod.pyi>", "py:function:pkg.mod.<pkg/mod.pyi>.f"}
    assert all(node["attributes"]["declaration_file"] for node in stub["nodes"])
    assert stub["diagnostics"] == []


def test_an_import_of_a_dotted_module_binds_its_first_name(worker: ModuleType, project: Any) -> None:
    """``import a.b`` binds ``a``, and ``a.b.f()`` reaches ``f`` in ``a.b``; ``os.path.join`` stays ``os.path.join``."""
    root = project(
        {
            "a/__init__.py": "",
            "a/b.py": "def f():\n    return 1\n",
            "use.py": "import a.b\nimport os.path\n\n\ndef u():\n    return a.b.f(), os.path.join('x')\n",
        }
    )
    edges = _edges(_scan(worker, root, ["a/__init__.py", "a/b.py", "use.py"])["use.py"])

    assert ("calls", "py:function:use.u", "py:function:a.b.f") in edges
    assert ("calls", "py:function:use.u", "py:function:os.path.join") in edges
    assert ("imports", "py:module:use", "py:module:a.b") in edges
