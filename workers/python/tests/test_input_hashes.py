"""The ``input_hashes`` map a scan result reports for every file the request read.

The PHPUnit suite drives the real worker process and covers the ordinary reads.
These cases reach what a real process cannot be made to do on demand: a read
that fails after the index found the file (running as root, a chmod does not
stop the read), a file whose bytes change between two reads in one request, and
a link retargeted between the index's checks.
"""

from __future__ import annotations

import hashlib
from pathlib import Path
from types import ModuleType
from typing import Any


def _sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


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
    real_read_bytes = Path.read_bytes

    def failing(self: Path) -> bytes:
        if self.name == "b.py":
            raise OSError("vanished mid-read")
        return real_read_bytes(self)

    monkeypatch.setattr(Path, "read_bytes", failing)
    result, _ = _scan(worker, root, ["pkg/a.py"])

    assert result["input_hashes"] == {
        "pkg/a.py": _sha((root / "pkg/a.py").read_bytes()),
        "pkg/b.py": None,
    }


def _serve(monkeypatch, name: str, outcomes: list[bytes | None]) -> list[str]:
    """Make successive reads of files called ``name`` return ``outcomes`` in order.

    ``None`` makes that read raise ``OSError``. Returns the list the reads are
    appended to, so a test can prove the reads it describes really happened.
    """
    real_read_bytes = Path.read_bytes
    reads: list[str] = []

    def served(self: Path) -> bytes:
        if self.name != name:
            return real_read_bytes(self)
        outcome = outcomes[len(reads)]
        reads.append(self.as_posix())
        if outcome is None:
            raise OSError("unreadable on this read")
        return outcome

    monkeypatch.setattr(Path, "read_bytes", served)
    return reads


IMPORTER = "from pkg.b import Thing\n\n\nclass Local(Thing):\n    pass\n"
DECLARES = b"class Thing:\n    pass\n"
CHANGED = b"class Thing:\n    changed = True\n"


def test_an_index_read_then_a_differing_own_read_records_null(monkeypatch, worker: ModuleType, project) -> None:
    root = project({"pkg/a.py": IMPORTER, "pkg/b.py": DECLARES.decode()})
    reads = _serve(monkeypatch, "b.py", [DECLARES, CHANGED])
    result, contributions = _scan(worker, root, ["pkg/a.py", "pkg/b.py"])

    assert len(reads) == 2  # a's import resolution, then b's own scan
    assert contributions["pkg/b.py"]["content_hash"] == _sha(CHANGED)
    assert result["input_hashes"]["pkg/b.py"] is None


def test_a_failed_index_read_then_a_successful_own_read_stays_null(monkeypatch, worker: ModuleType, project) -> None:
    # a's import resolved against nothing because the index read of b failed;
    # b's own read succeeding afterwards does not make that resolution verified.
    root = project({"pkg/a.py": IMPORTER, "pkg/b.py": DECLARES.decode()})
    reads = _serve(monkeypatch, "b.py", [None, DECLARES])
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
    reads = _serve(monkeypatch, "b.py", [broken, DECLARES])
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
    reads = _serve(monkeypatch, "b.py", [DECLARES, CHANGED])
    result, _ = _scan(worker, root, ["app.py"])

    assert len(reads) == 2
    assert result["input_hashes"]["src/pkg/b.py"] is None


def test_a_hashed_read_then_a_failed_read_of_one_path_records_null(monkeypatch, worker: ModuleType, project) -> None:
    root = _two_module_ids(project)
    reads = _serve(monkeypatch, "b.py", [DECLARES, None])
    result, _ = _scan(worker, root, ["app.py"])

    assert len(reads) == 2
    assert result["input_hashes"]["src/pkg/b.py"] is None


def _no_reads(monkeypatch) -> list[str]:
    """Record every ``Path.read_bytes`` call, so a test can assert none happened."""
    reads: list[str] = []
    real_read_bytes = Path.read_bytes

    def spying(self: Path) -> bytes:
        reads.append(self.name)
        return real_read_bytes(self)

    monkeypatch.setattr(Path, "read_bytes", spying)
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
    reads = _no_reads(monkeypatch)

    assert index.module_declarations("pkg.b") == {}
    assert reads == []
    assert index.read_hashes == {"pkg/b.py": None}


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
    reads = _no_reads(monkeypatch)

    assert index.module_declarations("pkg.b") == {}
    assert reads == []
    assert index.read_hashes == {"pkg/b.py": None}


def test_a_module_refused_for_linking_out_of_the_root_is_null(worker: ModuleType, project, tmp_path_factory) -> None:
    outside = tmp_path_factory.mktemp("outside")
    (outside / "b.py").write_text("class Thing:\n    pass\n", encoding="utf-8")
    root = project({"app.py": IMPORTER})
    (root / "pkg").mkdir()
    (root / "pkg" / "b.py").symlink_to(outside / "b.py")
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.module_declarations("pkg.b") == {}
    assert index.read_hashes == {"pkg/b.py": None}


def test_a_candidate_that_does_not_exist_is_a_probe_not_a_read(worker: ModuleType, project) -> None:
    root = project({"app.py": IMPORTER, "pkg/b.py": "class Thing:\n    pass\n"})
    index = worker.ProjectModuleIndex(root, 2_000_000)

    index.module_declarations("pkg.b")
    index.module_declarations("pkg.missing")

    # pkg/b/__init__.py was probed first and is absent; only the read is recorded.
    assert index.read_hashes == {"pkg/b.py": _sha(b"class Thing:\n    pass\n")}


def test_an_unreadable_requested_file_is_absent_and_an_empty_request_reports_an_empty_map(
    monkeypatch, worker: ModuleType, project
) -> None:
    root = project({"gone.py": "x = 1\n"})
    empty, _ = _scan(worker, root, [])
    assert empty["input_hashes"] == {}

    real_read_bytes = Path.read_bytes

    def failing(self: Path) -> bytes:
        if self.name == "gone.py":
            raise OSError("deleted after discovery")
        return real_read_bytes(self)

    monkeypatch.setattr(Path, "read_bytes", failing)
    result, contributions = _scan(worker, root, ["gone.py"])
    assert contributions["gone.py"]["diagnostics"][0]["code"] == "PY_UNSCANNABLE_FILE"
    assert result["input_hashes"] == {}


# A refused module reached through a linked file name (pkg/alias.py links to
# pkg/real.py) and through a linked directory (lnk links to real/). Discovery
# never follows a link, so the null must land on the real in-root path.
REFUSED_ON_REAL_KEYS = {"pkg/real.py": None, "real/c.py": None}


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

    assert index.read_hashes == REFUSED_ON_REAL_KEYS


def test_a_module_refused_for_linking_out_of_the_root_is_keyed_by_the_in_root_link(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    outside = tmp_path_factory.mktemp("outside") / "x.py"
    outside.write_text("class X:\n    pass\n", encoding="utf-8")
    root = _linked_layout(project)
    _escape([root / "pkg" / "real.py", root / "real" / "c.py"], outside)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    _declarations_of_linked_modules(index)

    assert index.read_hashes == REFUSED_ON_REAL_KEYS


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

    assert index.read_hashes == REFUSED_ON_REAL_KEYS


def test_a_module_removed_before_the_read_is_keyed_by_where_it_was(worker: ModuleType, project) -> None:
    root = _linked_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    (root / "pkg" / "real.py").unlink()
    (root / "real" / "c.py").unlink()
    index._is_project_file = lambda path: path.name in {"alias.py", "c.py"}

    _declarations_of_linked_modules(index)

    assert index.read_hashes == REFUSED_ON_REAL_KEYS


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

    assert index.read_hashes == {"sub/c.py": None}
