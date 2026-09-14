"""The ``input_hashes`` map a scan result reports for every file the request read.

The PHPUnit suite drives the real worker process and covers the ordinary reads.
These cases reach what a real process cannot be made to do on demand: a read
that fails after the index found the file (running as root, a chmod does not
stop the read), a file whose bytes change between two reads in one request, and
a link retargeted between the index's checks.
"""

from __future__ import annotations

import hashlib
import os
from collections.abc import Callable
from pathlib import Path
from types import ModuleType
from typing import Any

import pytest


def _sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _discovered(root: Path, max_bytes: int = 2_000_000) -> dict[str, str]:
    """What discovery would hash: regular files within the cap, reached without following a link."""
    found: dict[str, str] = {}
    for directory, directories, files in os.walk(root):
        directories[:] = [name for name in directories if not (Path(directory) / name).is_symlink()]
        for name in files:
            path = Path(directory) / name
            if path.is_symlink() or not path.is_file() or path.stat().st_size > max_bytes:
                continue
            found[path.relative_to(root).as_posix()] = _sha(path.read_bytes())
    return found


def _disagreements(read_hashes: dict[str, str | None], discovery: dict[str, str]) -> list[str]:
    """The paths the core would fail the scan on: discovered, with a value other than discovery's hash."""
    return sorted(key for key, value in read_hashes.items() if key in discovery and value != discovery[key])


def _includes(read_hashes: dict[str, str | None], expected: dict[str, str | None]) -> None:
    """Every expected key is recorded with its expected value.

    Probes add ``None`` entries for candidates that do not exist and for links
    they passed; those never name a discovered path, which ``_disagreements``
    checks where a test's tree is stable.
    """
    assert {key: read_hashes.get(key, "absent") for key in expected} == expected


def _scan(worker: ModuleType, root: Path, files: list[str]) -> tuple[dict[str, Any], dict[str, dict[str, Any]]]:
    emitted: list[dict[str, Any]] = []
    result = worker.scan({"root": str(root), "files": files}, emitted.append)
    return result, {item["owner_key"].rsplit(":", 1)[-1]: item for item in emitted}


def test_a_failed_index_read_is_reported_as_null(monkeypatch, worker: ModuleType, project) -> None:
    root = project(
        {
            "pkg/a.py": "from pkg.b import Thing\n\n\nclass Local(Thing):\n    pass\n",
            "pkg/b.py": "class Thing:\n    pass\n",
        }
    )
    real_read = worker.read_bounded

    def failing(path: Path, max_bytes: int) -> bytes:
        if path.name == "b.py":
            raise OSError("vanished mid-read")
        return real_read(path, max_bytes)

    monkeypatch.setattr(worker, "read_bounded", failing)
    discovery = _discovered(root)
    result, _ = _scan(worker, root, ["pkg/a.py"])

    _includes(result["input_hashes"], {"pkg/a.py": _sha((root / "pkg/a.py").read_bytes()), "pkg/b.py": None})
    assert _disagreements(result["input_hashes"], discovery) == ["pkg/b.py"]


def _serve(monkeypatch, worker: ModuleType, name: str, outcomes: list[bytes | None]) -> list[str]:
    """Make successive reads of files called ``name`` return ``outcomes`` in order.

    ``None`` makes that read raise ``OSError``. Returns the list the reads are
    appended to, so a test can prove the reads it describes really happened.
    """
    real_read = worker.read_bounded
    reads: list[str] = []

    def served(path: Path, max_bytes: int) -> bytes:
        if path.name != name:
            return real_read(path, max_bytes)
        outcome = outcomes[len(reads)]
        reads.append(path.as_posix())
        if outcome is None:
            raise OSError("unreadable on this read")
        return outcome

    monkeypatch.setattr(worker, "read_bounded", served)
    return reads


IMPORTER = "from pkg.b import Thing\n\n\nclass Local(Thing):\n    pass\n"
DECLARES = b"class Thing:\n    pass\n"
CHANGED = b"class Thing:\n    changed = True\n"


def test_an_index_read_then_a_differing_own_read_records_null(monkeypatch, worker: ModuleType, project) -> None:
    root = project({"pkg/a.py": IMPORTER, "pkg/b.py": DECLARES.decode()})
    reads = _serve(monkeypatch, worker, "b.py", [DECLARES, CHANGED])
    result, contributions = _scan(worker, root, ["pkg/a.py", "pkg/b.py"])

    assert len(reads) == 2  # a's import resolution, then b's own scan
    assert contributions["pkg/b.py"]["content_hash"] == _sha(CHANGED)
    assert result["input_hashes"]["pkg/b.py"] is None


def test_a_failed_index_read_then_a_successful_own_read_stays_null(monkeypatch, worker: ModuleType, project) -> None:
    # a's import resolved against nothing because the index read of b failed;
    # b's own read succeeding afterwards does not make that resolution verified.
    root = project({"pkg/a.py": IMPORTER, "pkg/b.py": DECLARES.decode()})
    reads = _serve(monkeypatch, worker, "b.py", [None, DECLARES])
    result, contributions = _scan(worker, root, ["pkg/a.py", "pkg/b.py"])

    assert len(reads) == 2
    assert contributions["pkg/b.py"]["content_hash"] == _sha(DECLARES)
    assert result["input_hashes"]["pkg/b.py"] is None


def test_an_own_read_then_a_differing_index_read_records_null(monkeypatch, worker: ModuleType, project) -> None:
    # b sorts first and its own bytes fail to parse, so it seeds nothing and
    # c's import makes the index read b again. The facts c resolves come from
    # that second read, which no content_hash describes.
    root = project({"pkg/b.py": "class :\n", "pkg/c.py": IMPORTER})
    broken = b"class :\n"
    reads = _serve(monkeypatch, worker, "b.py", [broken, DECLARES])
    result, contributions = _scan(worker, root, ["pkg/b.py", "pkg/c.py"])

    assert len(reads) == 2
    assert contributions["pkg/b.py"]["content_hash"] == _sha(broken)
    assert ("extends", "py:class:c.Local", "py:class:pkg.b.Thing") in [
        (edge["kind"], edge["source"], edge["target"]) for edge in contributions["pkg/c.py"]["edges"]
    ]
    assert result["input_hashes"]["pkg/b.py"] is None


def _two_module_ids(project) -> Path:
    # `src` is a non-package source root, so src/pkg/b.py is both `pkg.b` and
    # `src.pkg.b`: two cache entries, two index reads of one path.
    return project(
        {
            "src/pkg/b.py": DECLARES.decode(),
            "app.py": "from pkg.b import Thing\nfrom src.pkg.b import Thing as T2\n\n\n"
            "class One(Thing):\n    pass\n\n\nclass Two(T2):\n    pass\n",
        }
    )


def test_two_differing_index_reads_of_one_path_record_null(monkeypatch, worker: ModuleType, project) -> None:
    root = _two_module_ids(project)
    reads = _serve(monkeypatch, worker, "b.py", [DECLARES, CHANGED])
    result, _ = _scan(worker, root, ["app.py"])

    assert len(reads) == 2
    assert result["input_hashes"]["src/pkg/b.py"] is None


def test_a_hashed_read_then_a_failed_read_of_one_path_records_null(monkeypatch, worker: ModuleType, project) -> None:
    root = _two_module_ids(project)
    reads = _serve(monkeypatch, worker, "b.py", [DECLARES, None])
    result, _ = _scan(worker, root, ["app.py"])

    assert len(reads) == 2
    assert result["input_hashes"]["src/pkg/b.py"] is None


def _no_reads(monkeypatch, worker: ModuleType) -> list[str]:
    """Record every read of a file's bytes, so a test can assert none happened."""
    reads: list[str] = []
    real_read = worker.read_bounded

    def spying(path: Path, max_bytes: int) -> bytes:
        reads.append(path.name)
        return real_read(path, max_bytes)

    monkeypatch.setattr(worker, "read_bounded", spying)
    return reads


def test_a_module_that_no_longer_resolves_inside_the_root_is_null_and_not_read(
    monkeypatch, worker: ModuleType, project, tmp_path_factory
) -> None:
    # A link retargeted outside the root after _is_project_file accepted it.
    # Nothing is read from outside, but the importer's facts are now computed
    # without the module, so its own path is recorded as a failed read.
    outside = tmp_path_factory.mktemp("outside")
    (outside / "b.py").write_text("class Thing:\n    pass\n", encoding="utf-8")
    root = project({"app.py": IMPORTER})
    (root / "pkg").mkdir()
    (root / "pkg" / "b.py").symlink_to(outside / "b.py")
    index = worker.ProjectModuleIndex(root, 2_000_000)
    index._is_project_file = lambda path: path.exists()
    reads = _no_reads(monkeypatch, worker)

    assert index.module_declarations("pkg.b") == {}
    assert reads == []
    _includes(index.read_hashes, {"pkg/b.py": None})


def test_a_module_that_no_longer_resolves_at_all_is_null(worker: ModuleType, project) -> None:
    # Accepted, then removed before the read resolved it.
    root = project({"app.py": IMPORTER})
    index = worker.ProjectModuleIndex(root, 2_000_000)
    index._is_project_file = lambda path: path.name == "b.py"

    assert index.module_declarations("pkg.b") == {}
    assert index.read_hashes == {"pkg/b.py": None}


def test_a_module_refused_as_over_the_byte_cap_is_null_and_not_read(monkeypatch, worker: ModuleType, project) -> None:
    root = project({"app.py": IMPORTER, "pkg/b.py": "class Thing:\n    pass\n" + "#" * 100})
    index = worker.ProjectModuleIndex(root, 60)
    reads = _no_reads(monkeypatch, worker)

    assert index.module_declarations("pkg.b") == {}
    assert reads == []
    _includes(index.read_hashes, {"pkg/b.py": None})


def test_a_module_refused_for_linking_out_of_the_root_is_null(worker: ModuleType, project, tmp_path_factory) -> None:
    outside = tmp_path_factory.mktemp("outside")
    (outside / "b.py").write_text("class Thing:\n    pass\n", encoding="utf-8")
    root = project({"app.py": IMPORTER})
    (root / "pkg").mkdir()
    (root / "pkg" / "b.py").symlink_to(outside / "b.py")
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.module_file("pkg.b") is None
    assert index.module_declarations("pkg.b") == {}
    _includes(index.read_hashes, {"pkg/b.py": None})


def test_a_candidate_that_does_not_exist_is_recorded_as_null(worker: ModuleType, project) -> None:
    # Resolution goes on as if an absent candidate were not there, so a
    # discovered candidate missing for that moment must fail verification.
    # Discovery never reports an absent path, so a stable tree is unaffected.
    root = project({"app.py": IMPORTER, "pkg/b.py": "class Thing:\n    pass\n"})
    discovery = _discovered(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    index.module_declarations("pkg.b")
    index.module_declarations("pkg.missing")

    _includes(
        index.read_hashes,
        {
            "pkg/b/__init__.py": None,
            "pkg/b.py": _sha(b"class Thing:\n    pass\n"),
            "pkg/missing/__init__.py": None,
            "pkg/missing.py": None,
        },
    )
    assert _disagreements(index.read_hashes, discovery) == []


def test_a_discovered_module_absent_while_the_index_probes_it_fails_verification(worker: ModuleType, project) -> None:
    # Reproduction: pkg/b.py swapped for a directory while module_file probed
    # it, then restored. The import's target became external, and the map
    # named only the importer.
    root = project({"app.py": IMPORTER, "pkg/b.py": DECLARES.decode()})
    discovery = _discovered(root)
    b = root / "pkg" / "b.py"
    b.unlink()
    b.mkdir()
    try:
        result, contributions = _scan(worker, root, ["app.py"])
    finally:
        b.rmdir()
        b.write_bytes(DECLARES)

    assert ("extends", "py:class:app.Local", "py:class:pkg.b.Thing") not in [
        (edge["kind"], edge["source"], edge["target"]) for edge in contributions["app.py"]["edges"]
    ]
    assert _disagreements(result["input_hashes"], discovery) == ["pkg/b.py"]


def test_an_unreadable_requested_file_is_null_and_an_empty_request_reports_an_empty_map(
    monkeypatch, worker: ModuleType, project
) -> None:
    # The contribution standing in for gone.py carries none of its facts, so a
    # discovered gone.py must not pass verification as if it had been read.
    root = project({"gone.py": "x = 1\n"})
    empty, _ = _scan(worker, root, [])
    assert empty["input_hashes"] == {}

    real_read = worker.read_bounded

    def failing(path: Path, max_bytes: int) -> bytes:
        if path.name == "gone.py":
            raise OSError("deleted after discovery")
        return real_read(path, max_bytes)

    monkeypatch.setattr(worker, "read_bounded", failing)
    result, contributions = _scan(worker, root, ["gone.py"])
    assert contributions["gone.py"]["diagnostics"][0]["code"] == "PY_UNSCANNABLE_FILE"
    assert result["input_hashes"] == {"gone.py": None}


def test_a_requested_file_the_filesystem_refuses_is_null_and_a_policy_refusal_is_not_reported(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    outside = tmp_path_factory.mktemp("outside")
    (outside / "out.py").write_text("x = 1\n", encoding="utf-8")
    root = project({"big.py": "x = 1\n" + "#" * 100, "notes.txt": "text\n", "script": "#!/bin/sh\n"})
    (root / "dir.py").mkdir()
    (root / "out.py").symlink_to(outside / "out.py")
    emitted: list[dict[str, Any]] = []

    result = worker.scan(
        {
            "root": str(root),
            "files": ["big.py", "dir.py", "gone.py", "notes.txt", "out.py", "script"],
            "limits": {"max_file_bytes": 50},
        },
        emitted.append,
    )

    # Nothing was resolved, so no source-root probe ran either (dir.py is a
    # top-level directory it would have probed). notes.txt was refused by its
    # name and is not reported; script was refused on what its shebang says,
    # so it is reported by the hash of what that verdict rested on.
    assert result["input_hashes"] == {
        "big.py": None,
        "dir.py": None,
        "gone.py": None,
        "out.py": None,
        "script": _sha(b"#!/bin/sh\n"),
    }
    messages = {item["owner_key"].rsplit(":", 1)[-1]: item["diagnostics"][0]["message"] for item in emitted}
    assert messages["big.py"] == "Python input exceeds the configured byte limit."
    assert messages["dir.py"] == "Python input is not a regular file."
    assert messages["out.py"] == "Python input path escapes the project root."
    assert messages["notes.txt"] == "Unsupported Python input."
    assert messages["script"] == "Unsupported Python input."
    assert all(item["nodes"] == [] and item["edges"] == [] for item in emitted)


def test_a_requested_file_that_grows_past_the_cap_before_its_read_is_null_and_not_read_unbounded(
    monkeypatch, worker: ModuleType, project
) -> None:
    root = project({"grows.py": "x = 1\n"})
    sizes: list[int] = []
    real_read = worker.read_bounded

    def grown(path: Path, max_bytes: int) -> bytes:
        path.write_bytes(b"x = 1\n" + b"#" * 10_000)
        data = real_read(path, max_bytes)
        sizes.append(len(data))
        return data

    monkeypatch.setattr(worker, "read_bounded", grown)
    result, contributions = _scan_limited(worker, root, ["grows.py"], 50)

    assert sizes == [51]
    assert result["input_hashes"] == {"grows.py": None}
    assert contributions["grows.py"]["diagnostics"][0]["message"] == "Python input exceeds the configured byte limit."
    assert "content_hash" not in contributions["grows.py"]


def test_a_module_that_grows_past_the_cap_before_the_index_reads_it_is_null(
    monkeypatch, worker: ModuleType, project
) -> None:
    root = project({"app.py": IMPORTER, "pkg/b.py": DECLARES.decode()})
    real_read = worker.read_bounded

    def grown(path: Path, max_bytes: int) -> bytes:
        if path.name == "b.py":
            path.write_bytes(DECLARES + b"#" * 10_000)
        return real_read(path, max_bytes)

    monkeypatch.setattr(worker, "read_bounded", grown)
    index = worker.ProjectModuleIndex(root, 100)

    assert index.module_declarations("pkg.b") == {}
    assert index.read_hashes["pkg/b.py"] is None


def test_a_shebang_probe_that_cannot_open_the_file_is_a_failed_read(worker: ModuleType, tmp_path: Path) -> None:
    with pytest.raises(worker.UnreadableInput):
        worker.names_python_in_shebang(tmp_path / "gone")


def test_a_bounded_read_stops_one_byte_past_the_cap(worker: ModuleType, tmp_path: Path) -> None:
    path = tmp_path / "ten.py"
    path.write_bytes(b"0123456789")

    assert worker.read_bounded(path, 4) == b"01234"
    assert worker.read_bounded(path, 10) == b"0123456789"


def _scan_limited(
    worker: ModuleType, root: Path, files: list[str], max_bytes: int
) -> tuple[dict[str, Any], dict[str, dict[str, Any]]]:
    emitted: list[dict[str, Any]] = []
    result = worker.scan({"root": str(root), "files": files, "limits": {"max_file_bytes": max_bytes}}, emitted.append)
    return result, {item["owner_key"].rsplit(":", 1)[-1]: item for item in emitted}


# A refused module reached through a linked file name (pkg/alias.py links to
# pkg/real.py) and through a linked directory (lnk links to real/). Discovery
# never follows a link, so the null must land on the real in-root path.
REFUSED_ON_REAL_KEYS = {
    "pkg/real.py": None,
    "real/c.py": None,
    # The linked names, as written and at the link.
    "pkg/alias.py": None,
    "lnk": None,
    "lnk/c.py": None,
}


def _linked_layout(project, real: str = "class R:\n    pass\n", c: str = "class C:\n    pass\n") -> Path:
    root = project({"app.py": "x = 1\n", "pkg/real.py": real, "real/c.py": c})
    (root / "pkg" / "alias.py").symlink_to(root / "pkg" / "real.py")
    (root / "lnk").symlink_to(root / "real")
    return root


def _declarations_of_linked_modules(index: Any) -> None:
    assert index.module_declarations("pkg.alias") == {}
    assert index.module_declarations("lnk.c") == {}


def _escape(targets: list[Path], outside: Path) -> None:
    for target in targets:
        target.unlink()
        target.symlink_to(outside)


def test_a_module_refused_over_the_byte_cap_is_keyed_by_its_real_path(worker: ModuleType, project) -> None:
    root = _linked_layout(project, "class R:\n    pass\n" + "#" * 100, "class C:\n    pass\n" + "#" * 100)
    index = worker.ProjectModuleIndex(root, 60)

    _declarations_of_linked_modules(index)

    _includes(index.read_hashes, REFUSED_ON_REAL_KEYS)


def test_a_module_refused_for_linking_out_of_the_root_is_keyed_by_the_in_root_link(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    outside = tmp_path_factory.mktemp("outside") / "x.py"
    outside.write_text("class X:\n    pass\n", encoding="utf-8")
    root = _linked_layout(project)
    _escape([root / "pkg" / "real.py", root / "real" / "c.py"], outside)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    _declarations_of_linked_modules(index)

    _includes(index.read_hashes, REFUSED_ON_REAL_KEYS)


def test_a_module_retargeted_out_of_the_root_before_the_read_is_keyed_by_the_in_root_link(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    outside = tmp_path_factory.mktemp("outside") / "x.py"
    outside.write_text("class X:\n    pass\n", encoding="utf-8")
    root = _linked_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _escape([root / "pkg" / "real.py", root / "real" / "c.py"], outside)
    index._is_project_file = lambda path: path.exists()

    _declarations_of_linked_modules(index)

    _includes(index.read_hashes, REFUSED_ON_REAL_KEYS)


def test_a_module_removed_before_the_read_is_keyed_by_where_it_was(worker: ModuleType, project) -> None:
    root = _linked_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    (root / "pkg" / "real.py").unlink()
    (root / "real" / "c.py").unlink()
    index._is_project_file = lambda path: path.name in {"alias.py", "c.py"}

    _declarations_of_linked_modules(index)

    _includes(index.read_hashes, REFUSED_ON_REAL_KEYS)


def test_a_module_under_a_directory_swapped_for_a_link_out_of_the_root_is_keyed_by_where_it_was(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    outside = tmp_path_factory.mktemp("outside")
    (outside / "c.py").write_text("class C:\n    pass\n", encoding="utf-8")
    root = project({"app.py": "x = 1\n", "sub/c.py": "class C:\n    pass\n"})
    index = worker.ProjectModuleIndex(root, 2_000_000)
    (root / "sub" / "c.py").unlink()
    (root / "sub").rmdir()
    (root / "sub").symlink_to(outside)

    assert index.module_declarations("sub.c") == {}

    _includes(index.read_hashes, {"sub/c.py": None, "sub": None})


# pkg/lnk.py links to d/../c.py and pkg/d links to ../deep/dir. The kernel
# applies the ``..`` after following pkg/d, so a read of pkg/lnk.py opens
# deep/c.py; a textual collapse would name pkg/c.py, present to catch that.
DEEP = b"class Deep:\n    pass\n"


def _dot_dot_layout(project, deep: bytes = DEEP) -> Path:
    root = project({"app.py": "x = 1\n", "pkg/c.py": "class Decoy:\n    pass\n", "deep/dir/keep.txt": ""})
    (root / "deep" / "c.py").write_bytes(deep)
    (root / "pkg" / "d").symlink_to("../deep/dir")
    (root / "pkg" / "lnk.py").symlink_to("d/../c.py")
    return root


def test_a_read_through_a_link_with_dot_dot_is_keyed_by_the_file_the_kernel_opened(worker: ModuleType, project) -> None:
    root = _dot_dot_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    _includes(index.read_hashes, {"deep/c.py": _sha(DEEP), "pkg/lnk.py": None, "pkg/d": None})
    assert "pkg/c.py" not in index.read_hashes
    assert _disagreements(index.read_hashes, _discovered(root)) == []


def test_a_removed_target_behind_a_link_with_dot_dot_is_keyed_by_the_file_the_kernel_would_open(
    worker: ModuleType, project
) -> None:
    root = _dot_dot_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    index._is_project_file = lambda path: path.name == "lnk.py"
    (root / "deep" / "c.py").unlink()

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {"deep/c.py": None})


def test_a_stable_over_cap_target_behind_a_link_with_dot_dot_is_not_keyed_by_a_textual_collapse(
    worker: ModuleType, project
) -> None:
    root = _dot_dot_layout(project, DEEP + b"#" * 100)
    index = worker.ProjectModuleIndex(root, 60)

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {"deep/c.py": None})
    assert "pkg/c.py" not in index.read_hashes
    assert _disagreements(index.read_hashes, _discovered(root, 60)) == []


# The cases below follow each path the way the kernel's lookup does. A module
# name only reaches ``module_declarations`` through ``_is_project_file``, whose
# walk is itself a kernel lookup, so a path the kernel cannot resolve is refused
# there and recorded as None under keys discovery never reports; the
# ``_is_project_file`` override stands in for the tree changing between that
# check and the read.
DECOY = "class Decoy:\n    pass\n"


def _accepting(index: Any, name: str) -> None:
    index._is_project_file = lambda path: path.name == name


def _out_and_back_layout(project, tmp_path_factory, deep: bytes = DEEP) -> Path:
    # pkg/lnk.py -> OUT/u/f.py -> ROOT/deep/c.py: the chain leaves the root and
    # comes back, so a read of pkg/lnk.py opens deep/c.py.
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY, "deep/keep.txt": ""})
    (root / "deep" / "c.py").write_bytes(deep)
    outside = tmp_path_factory.mktemp("outside")
    (outside / "u").mkdir()
    (outside / "u" / "f.py").symlink_to(root / "deep" / "c.py")
    (root / "pkg" / "lnk.py").symlink_to(outside / "u" / "f.py")
    return root


def test_a_read_through_a_chain_that_leaves_the_root_and_returns_is_keyed_by_the_file_it_ends_at(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    root = _out_and_back_layout(project, tmp_path_factory)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    _includes(index.read_hashes, {"deep/c.py": _sha(DEEP), "pkg/lnk.py": None})
    assert _disagreements(index.read_hashes, _discovered(root)) == []


def test_a_removed_target_at_the_end_of_a_chain_that_leaves_the_root_is_keyed_by_that_target(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    root = _out_and_back_layout(project, tmp_path_factory)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")
    (root / "deep" / "c.py").unlink()

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {"deep/c.py": None})


def test_a_stable_over_cap_target_at_the_end_of_a_chain_that_leaves_the_root_is_keyed_by_that_target(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    root = _out_and_back_layout(project, tmp_path_factory, DEEP + b"#" * 100)
    index = worker.ProjectModuleIndex(root, 60)

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {"deep/c.py": None})
    assert _disagreements(index.read_hashes, _discovered(root, 60)) == []


def _dot_dot_after_missing(root: Path) -> None:
    (root / "pkg" / "lnk.py").symlink_to("nope/../c.py")


def _dot_dot_after_self_loop(root: Path) -> None:
    (root / "pkg" / "ld").symlink_to("ld")
    (root / "pkg" / "lnk.py").symlink_to("ld/../c.py")


def _dot_dot_through_dangling_directory_link(root: Path) -> None:
    (root / "pkg" / "d").symlink_to("../gone/dir")
    (root / "pkg" / "lnk.py").symlink_to("d/../c.py")


# Each link target reaches ``..`` through a component that does not resolve, so
# the kernel fails the read, while a textual collapse names the discovered,
# unchanged pkg/c.py.
UNAPPLIABLE_DOT_DOT = [
    (_dot_dot_after_missing, "pkg/lnk.py"),
    (_dot_dot_after_self_loop, "pkg/ld"),
    (_dot_dot_through_dangling_directory_link, "pkg/d"),
]


@pytest.mark.parametrize(("layout", "link"), UNAPPLIABLE_DOT_DOT)
def test_a_stable_link_with_dot_dot_the_kernel_cannot_apply_never_disagrees_with_discovery(
    worker: ModuleType, project, layout: Callable[[Path], None], link: str
) -> None:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY})
    layout(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {link: None})
    assert "pkg/c.py" not in index.read_hashes
    assert _disagreements(index.read_hashes, _discovered(root)) == []


@pytest.mark.parametrize(("layout", "link"), UNAPPLIABLE_DOT_DOT)
def test_a_read_through_a_link_with_dot_dot_the_kernel_cannot_apply_is_keyed_by_the_last_link(
    worker: ModuleType, project, layout: Callable[[Path], None], link: str
) -> None:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY})
    layout(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {link: None})
    assert "pkg/c.py" not in index.read_hashes
    assert worker.input_key(root, root / "pkg" / "lnk.py") == link


def test_a_read_through_a_directory_link_with_dot_dot_that_resolves_is_keyed_by_the_file_opened(
    worker: ModuleType, project
) -> None:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY, "gone/dir/keep.txt": ""})
    (root / "gone" / "c.py").write_bytes(DEEP)
    _dot_dot_through_dangling_directory_link(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    _includes(index.read_hashes, {"gone/c.py": _sha(DEEP)})
    assert _disagreements(index.read_hashes, _discovered(root)) == []


@pytest.mark.parametrize("change", ["removed", "replaced by a file"])
def test_a_module_under_a_directory_changed_before_the_read_is_keyed_where_it_was(
    worker: ModuleType, project, change: str
) -> None:
    # The kernel fails the read at sub, yet the path it was to open is known.
    root = project({"app.py": "x = 1\n", "sub/c.py": "class C:\n    pass\n"})
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "c.py")
    (root / "sub" / "c.py").unlink()
    (root / "sub").rmdir()
    if change == "replaced by a file":
        (root / "sub").write_text("x = 1\n", encoding="utf-8")

    assert index.module_declarations("sub.c") == {}
    _includes(index.read_hashes, {"sub/c.py": None})


def _absolute_link_layout(project, contents: bytes = DEEP) -> Path:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY, "lib/keep.txt": ""})
    (root / "lib" / "b.py").write_bytes(contents)
    (root / "pkg" / "lnk.py").symlink_to(root / "lib" / "b.py")
    return root


def test_a_read_through_an_absolute_link_inside_the_root_is_keyed_by_the_target(worker: ModuleType, project) -> None:
    root = _absolute_link_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    _includes(index.read_hashes, {"lib/b.py": _sha(DEEP), "pkg/lnk.py": None})
    assert _disagreements(index.read_hashes, _discovered(root)) == []


def test_a_removed_target_of_an_absolute_link_inside_the_root_is_keyed_by_the_target(
    worker: ModuleType, project
) -> None:
    root = _absolute_link_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")
    (root / "lib" / "b.py").unlink()

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {"lib/b.py": None})


def test_a_stable_over_cap_target_of_an_absolute_link_inside_the_root_is_keyed_by_the_target(
    worker: ModuleType, project
) -> None:
    root = _absolute_link_layout(project, DEEP + b"#" * 100)
    index = worker.ProjectModuleIndex(root, 60)

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {"lib/b.py": None})
    assert _disagreements(index.read_hashes, _discovered(root, 60)) == []


def _chain(project, links: int) -> Path:
    # pkg/l0.py -> l1.py ... -> real.py. Linux follows at most 40 links in one
    # lookup; the 41st fails it with ELOOP.
    root = project({"app.py": "x = 1\n", "pkg/real.py": DEEP.decode()})
    for index in range(links):
        target = "real.py" if index == links - 1 else f"l{index + 1}.py"
        (root / "pkg" / f"l{index}.py").symlink_to(target)
    return root


def test_a_chain_of_40_links_is_followed_to_its_file(worker: ModuleType, project) -> None:
    root = _chain(project, 40)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert "Deep" in index.module_declarations("pkg.l0")
    # Every link is keyed too; the probe that accepted the chain recorded them as
    # None, and the read's hash disagrees into None.
    _includes(index.read_hashes, {"pkg/real.py": _sha(DEEP)} | {f"pkg/l{n}.py": None for n in range(40)})
    assert _disagreements(index.read_hashes, _discovered(root)) == []


def test_a_chain_of_41_links_never_disagrees_with_discovery_in_a_stable_tree(worker: ModuleType, project) -> None:
    root = _chain(project, 41)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.module_declarations("pkg.l0") == {}
    assert "pkg/real.py" not in index.read_hashes
    assert _disagreements(index.read_hashes, _discovered(root)) == []


def test_a_read_through_a_chain_of_41_links_is_keyed_by_the_last_link_followed(worker: ModuleType, project) -> None:
    index = worker.ProjectModuleIndex(_chain(project, 41), 2_000_000)
    _accepting(index, "l0.py")

    assert index.module_declarations("pkg.l0") == {}
    _includes(index.read_hashes, {"pkg/l39.py": None})
    assert "pkg/real.py" not in index.read_hashes


def _swap_to_directory(file: Path) -> None:
    file.unlink()
    file.mkdir()
    (file / "keep.txt").write_text("", encoding="utf-8")


def test_a_module_replaced_by_a_directory_before_the_read_is_keyed_where_it_was(worker: ModuleType, project) -> None:
    # Discovery never reports a directory, so keying where it stands is safe on
    # a stable tree, and a discovered file swapped for one fails verification.
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY})
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "c.py")
    _swap_to_directory(root / "pkg" / "c.py")

    assert index.module_declarations("pkg.c") == {}
    _includes(index.read_hashes, {"pkg/c.py": None})


def test_a_link_target_replaced_by_a_directory_before_the_read_is_keyed_by_that_target(
    worker: ModuleType, project
) -> None:
    root = _absolute_link_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")
    _swap_to_directory(root / "lib" / "b.py")

    assert index.module_declarations("pkg.lnk") == {}
    _includes(index.read_hashes, {"lib/b.py": None})


def test_an_absolute_link_target_with_a_doubled_leading_slash_is_followed_inside_the_root(
    worker: ModuleType, project
) -> None:
    root = project({"app.py": "x = 1\n", "q/c.py": DEEP.decode()})
    (root / "q" / "l6.py").symlink_to("/" + str(root / "q" / "c.py"))
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.module_file("q.l6") is not None
    assert "Deep" in index.module_declarations("q.l6")
    _includes(index.read_hashes, {"q/c.py": _sha(DEEP)})
    assert _disagreements(index.read_hashes, _discovered(root)) == []


def test_a_module_swapped_for_a_directory_while_module_file_probes_it_fails_verification(
    monkeypatch, worker: ModuleType, project
) -> None:
    # The reviewer's reproduction: the swap lasts exactly as long as the probe
    # stage, with no _is_project_file override, and is restored before any read.
    root = project({"app.py": IMPORTER, "pkg/b.py": DECLARES.decode()})
    discovery = _discovered(root)
    original = worker.ProjectModuleIndex.module_file

    def swapping(self: Any, module: str) -> Path | None:
        if module != "pkg.b":
            return original(self, module)
        b = root / "pkg" / "b.py"
        b.unlink()
        b.mkdir()
        try:
            return original(self, module)
        finally:
            b.rmdir()
            b.write_bytes(DECLARES)

    monkeypatch.setattr(worker.ProjectModuleIndex, "module_file", swapping)
    result, _ = _scan(worker, root, ["app.py"])

    assert _disagreements(result["input_hashes"], discovery) == ["pkg/b.py"]


def test_the_collision_probe_records_an_absent_competitor_and_not_a_present_one(worker: ModuleType, project) -> None:
    root = project({"pkg/mod.py": "", "pkg/lone.py": "", "pkg/both/__init__.py": "", "pkg/both.py": ""})
    index = worker.ProjectModuleIndex(root, 2_000_000)
    index.read_hashes.clear()

    assert index.collides(root / "pkg" / "lone.py", False) is False
    assert index.collides(root / "pkg" / "both.py", False) is True
    assert index.collides(root / "pkg" / "both" / "__init__.py", True) is True

    assert index.read_hashes == {"pkg/lone/__init__.py": None}


def test_a_competitor_absent_while_the_collision_probe_runs_fails_verification(worker: ModuleType, project) -> None:
    root = project({"pkg/both/__init__.py": "", "pkg/both.py": ""})
    discovery = _discovered(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    competitor = root / "pkg" / "both" / "__init__.py"
    competitor.unlink()
    try:
        assert index.collides(root / "pkg" / "both.py", False) is False
    finally:
        competitor.write_text("", encoding="utf-8")

    assert _disagreements(index.read_hashes, discovery) == ["pkg/both/__init__.py"]


def test_the_source_root_probe_records_an_absent_package_marker_and_not_a_present_one(
    worker: ModuleType, project
) -> None:
    # Whether a top-level directory holds __init__.py decides every module id
    # below it.
    root = project({"src/app.py": "", "pkg/__init__.py": "", "top.py": ""})

    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.prefixes == [(), ("src",)]
    assert index.read_hashes == {"src/__init__.py": None}


def test_a_package_marker_absent_while_the_source_roots_are_detected_fails_verification(
    monkeypatch, worker: ModuleType, project
) -> None:
    root = project({"pkg/__init__.py": "", "pkg/a.py": ""})
    discovery = _discovered(root)
    marker = root / "pkg" / "__init__.py"
    marker.unlink()
    try:
        index = worker.ProjectModuleIndex(root, 2_000_000)
        prefixes = index.prefixes
    finally:
        marker.write_text("", encoding="utf-8")

    assert prefixes == [(), ("pkg",)]
    assert _disagreements(index.read_hashes, discovery) == ["pkg/__init__.py"]


def _swapped_for(link: Path, target: str, restore: Callable[[], None], run: Callable[[], Any]) -> Any:
    """Run ``run`` with ``link`` replaced by a symlink to ``target``, then put it back with ``restore``."""
    if link.is_dir() and not link.is_symlink():
        for child in link.iterdir():
            child.unlink()
        link.rmdir()
    else:
        link.unlink()
    link.symlink_to(target)
    try:
        return run()
    finally:
        link.unlink()
        restore()


OTHER = b"class Thing:\n    other = True\n"


def test_an_imported_module_swapped_for_a_link_to_another_discovered_file_fails_verification(
    worker: ModuleType, project
) -> None:
    root = project({"app.py": IMPORTER, "pkg/b.py": DECLARES.decode(), "pkg/c.py": OTHER.decode()})
    discovery = _discovered(root)
    b = root / "pkg" / "b.py"

    result, _ = _swapped_for(b, "c.py", lambda: b.write_bytes(DECLARES), lambda: _scan(worker, root, ["app.py"]))

    assert result["input_hashes"]["pkg/c.py"] == _sha(OTHER)
    assert _disagreements(result["input_hashes"], discovery) == ["pkg/b.py"]


def test_a_module_under_a_directory_swapped_for_a_link_to_another_discovered_directory_fails_verification(
    worker: ModuleType, project
) -> None:
    root = project({"app.py": IMPORTER, "pkg/b.py": DECLARES.decode(), "other/b.py": OTHER.decode()})
    discovery = _discovered(root)
    pkg = root / "pkg"

    def restore() -> None:
        pkg.mkdir()
        (pkg / "b.py").write_bytes(DECLARES)

    result, _ = _swapped_for(pkg, "other", restore, lambda: _scan(worker, root, ["app.py"]))

    assert result["input_hashes"]["other/b.py"] == _sha(OTHER)
    assert _disagreements(result["input_hashes"], discovery) == ["pkg/b.py"]


def test_a_stable_tree_with_links_never_disagrees_with_discovery(worker: ModuleType, project, tmp_path_factory) -> None:
    outside = tmp_path_factory.mktemp("outside")
    (outside / "out.py").write_text("class Out:\n    pass\n", encoding="utf-8")
    root = project(
        {
            "app.py": "\n".join(
                [
                    "from pkg.alias import R",
                    "from lnk.c import C",
                    "from pkg.up import U",
                    "from pkg.lnk2 import D",
                    "from pkg.out import Out",
                    "from pkg.dangling import X",
                    "from pkg.missing import M",
                    "from vendored.node_modules.dep.mod import V",
                    "",
                ]
            ),
            "pkg/__init__.py": "",
            "pkg/real.py": "class R:\n    pass\n",
            "real/c.py": "class C:\n    pass\n",
            "deep/up.py": "class U:\n    pass\n",
            # pkg/lnk2.py -> d/../c2.py opens deep/c2.py; collapsing the ``..``
            # as text would name the discovered decoy pkg/c2.py.
            "deep/c2.py": "class D:\n    pass\n",
            "pkg/c2.py": "class D:\n    decoy = True\n",
            "deep/dir/keep.txt": "",
            "packages/dep/mod.py": "class V:\n    pass\n",
            "vendored/node_modules/keep.txt": "",
        }
    )
    (root / "vendored" / "node_modules" / "dep").symlink_to("../../packages/dep")
    (root / "pkg" / "alias.py").symlink_to("real.py")
    (root / "lnk").symlink_to("real")
    (root / "pkg" / "d").symlink_to("../deep/dir")
    (root / "pkg" / "up.py").symlink_to(root / "deep" / "up.py")
    (root / "pkg" / "lnk2.py").symlink_to("d/../c2.py")
    (root / "pkg" / "out.py").symlink_to(outside / "out.py")
    (root / "pkg" / "dangling.py").symlink_to("gone.py")
    discovery = _discovered(root)

    result, contributions = _scan(worker, root, sorted(key for key in discovery if key.endswith(".py")))

    assert _disagreements(result["input_hashes"], discovery) == []
    assert None in result["input_hashes"].values()
    # A link below node_modules is never keyed.
    assert not [key for key in result["input_hashes"] if "node_modules" in key.split("/")]
    assert all("content_hash" in contribution for contribution in contributions.values())


def test_a_shebang_refusal_of_a_stable_file_reports_its_content_hash(worker: ModuleType, project) -> None:
    # Discovery and this worker may judge a shebang apart; a tree that is not
    # changing matches the refusal's hash, so the file costs its diagnostic only.
    root = project({"bin/tool": "#!/usr/bin/env node\nconsole.log(1)\n", "notes.txt": "text\n"})

    result, contributions = _scan(worker, root, ["bin/tool", "notes.txt"])

    assert result["input_hashes"] == {"bin/tool": _sha(b"#!/usr/bin/env node\nconsole.log(1)\n")}
    assert contributions["bin/tool"]["diagnostics"][0]["message"] == "Unsupported Python input."
    assert _disagreements(result["input_hashes"], _discovered(root)) == []


@pytest.mark.parametrize("restored_before_evidence", [False, True])
def test_a_python_script_swapped_around_the_shebang_probe_fails_verification(
    monkeypatch, worker: ModuleType, project, restored_before_evidence: bool
) -> None:
    python = "#!/usr/bin/env python3\nprint('tool')\n"
    root = project({"bin/tool": python})
    discovery = _discovered(root)
    probe = worker.names_python_in_shebang

    def swapped(absolute: Path) -> bool:
        absolute.write_text("#!/usr/bin/env node\nconsole.log(1)\n", encoding="utf-8")
        verdict = probe(absolute)
        if restored_before_evidence:
            absolute.write_text(python, encoding="utf-8")
        return bool(verdict)

    monkeypatch.setattr(worker, "names_python_in_shebang", swapped)
    result, contributions = _scan(worker, root, ["bin/tool"])
    (root / "bin/tool").write_text(python, encoding="utf-8")

    assert contributions["bin/tool"]["nodes"] == []
    assert _disagreements(result["input_hashes"], discovery) == ["bin/tool"]
    if restored_before_evidence:
        assert result["input_hashes"] == {"bin/tool": None}


def test_shebang_refusal_evidence_is_null_unless_the_whole_file_still_refuses(
    worker: ModuleType, tmp_path: Path
) -> None:
    node = b"#!/usr/bin/env node\nconsole.log(1)\n"
    (tmp_path / "node").write_bytes(node)
    (tmp_path / "python").write_bytes(b"#!/usr/bin/env python3\n")
    # Past the probe's line the name no longer counts, as in the probe itself.
    late = b"#!" + b" " * 254 + b"python\n"
    (tmp_path / "late").write_bytes(late)

    assert worker.shebang_refusal_evidence(tmp_path / "node", len(node)) == _sha(node)
    assert worker.shebang_refusal_evidence(tmp_path / "node", len(node) - 1) is None
    assert worker.shebang_refusal_evidence(tmp_path / "python", 1000) is None
    assert worker.shebang_refusal_evidence(tmp_path / "late", 1000) == _sha(late)
    assert worker.shebang_refusal_evidence(tmp_path / "gone", 1000) is None
