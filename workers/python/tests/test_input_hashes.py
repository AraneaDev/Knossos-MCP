"""The ``input_hashes`` map a scan result reports for every file the request read.

The PHPUnit suite drives the real worker process and covers the ordinary reads.
These cases reach what a real process cannot be made to do on demand: a read
that fails after the index found the file (running as root, a chmod does not
stop the read), a file whose bytes change between two reads in one request, and
a link retargeted between the index's checks.
"""

from __future__ import annotations

import hashlib
from collections.abc import Callable
from pathlib import Path
from types import ModuleType
from typing import Any

import pytest


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

    assert index.module_file("pkg.b") is None
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
    index = worker.ProjectModuleIndex(_dot_dot_layout(project), 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    assert index.read_hashes == {"deep/c.py": _sha(DEEP)}


def test_a_removed_target_behind_a_link_with_dot_dot_is_keyed_by_the_file_the_kernel_would_open(
    worker: ModuleType, project
) -> None:
    root = _dot_dot_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    index._is_project_file = lambda path: path.name == "lnk.py"
    (root / "deep" / "c.py").unlink()

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {"deep/c.py": None}


def test_a_stable_over_cap_target_behind_a_link_with_dot_dot_is_not_keyed_by_a_textual_collapse(
    worker: ModuleType, project
) -> None:
    index = worker.ProjectModuleIndex(_dot_dot_layout(project, DEEP + b"#" * 100), 60)

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {"deep/c.py": None}


# The cases below follow each path the way the kernel's lookup does. A module
# name only reaches ``module_declarations`` through ``_is_project_file``, whose
# ``is_file()`` is itself a kernel lookup, so a path the kernel cannot resolve is
# a probe in a stable tree; the ``_is_project_file`` override stands in for the
# tree changing between that check and the read.
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
    index = worker.ProjectModuleIndex(_out_and_back_layout(project, tmp_path_factory), 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    assert index.read_hashes == {"deep/c.py": _sha(DEEP)}


def test_a_removed_target_at_the_end_of_a_chain_that_leaves_the_root_is_keyed_by_that_target(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    root = _out_and_back_layout(project, tmp_path_factory)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")
    (root / "deep" / "c.py").unlink()

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {"deep/c.py": None}


def test_a_stable_over_cap_target_at_the_end_of_a_chain_that_leaves_the_root_is_keyed_by_that_target(
    worker: ModuleType, project, tmp_path_factory
) -> None:
    index = worker.ProjectModuleIndex(_out_and_back_layout(project, tmp_path_factory, DEEP + b"#" * 100), 60)

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {"deep/c.py": None}


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
def test_a_stable_link_with_dot_dot_the_kernel_cannot_apply_is_only_a_probe(
    worker: ModuleType, project, layout: Callable[[Path], None], link: str
) -> None:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY})
    layout(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {}


@pytest.mark.parametrize(("layout", "link"), UNAPPLIABLE_DOT_DOT)
def test_a_read_through_a_link_with_dot_dot_the_kernel_cannot_apply_is_keyed_by_the_last_link(
    worker: ModuleType, project, layout: Callable[[Path], None], link: str
) -> None:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY})
    layout(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {link: None}
    assert worker.input_key(root, root / "pkg" / "lnk.py") == link


def test_a_read_through_a_directory_link_with_dot_dot_that_resolves_is_keyed_by_the_file_opened(
    worker: ModuleType, project
) -> None:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY, "gone/dir/keep.txt": ""})
    (root / "gone" / "c.py").write_bytes(DEEP)
    _dot_dot_through_dangling_directory_link(root)
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    assert index.read_hashes == {"gone/c.py": _sha(DEEP)}


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
    assert index.read_hashes == {"sub/c.py": None}


def _absolute_link_layout(project, contents: bytes = DEEP) -> Path:
    root = project({"app.py": "x = 1\n", "pkg/c.py": DECOY, "lib/keep.txt": ""})
    (root / "lib" / "b.py").write_bytes(contents)
    (root / "pkg" / "lnk.py").symlink_to(root / "lib" / "b.py")
    return root


def test_a_read_through_an_absolute_link_inside_the_root_is_keyed_by_the_target(worker: ModuleType, project) -> None:
    index = worker.ProjectModuleIndex(_absolute_link_layout(project), 2_000_000)

    assert "Deep" in index.module_declarations("pkg.lnk")
    assert index.read_hashes == {"lib/b.py": _sha(DEEP)}


def test_a_removed_target_of_an_absolute_link_inside_the_root_is_keyed_by_the_target(
    worker: ModuleType, project
) -> None:
    root = _absolute_link_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")
    (root / "lib" / "b.py").unlink()

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {"lib/b.py": None}


def test_a_stable_over_cap_target_of_an_absolute_link_inside_the_root_is_keyed_by_the_target(
    worker: ModuleType, project
) -> None:
    index = worker.ProjectModuleIndex(_absolute_link_layout(project, DEEP + b"#" * 100), 60)

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {"lib/b.py": None}


def _chain(project, links: int) -> Path:
    # pkg/l0.py -> l1.py ... -> real.py. Linux follows at most 40 links in one
    # lookup; the 41st fails it with ELOOP.
    root = project({"app.py": "x = 1\n", "pkg/real.py": DEEP.decode()})
    for index in range(links):
        target = "real.py" if index == links - 1 else f"l{index + 1}.py"
        (root / "pkg" / f"l{index}.py").symlink_to(target)
    return root


def test_a_chain_of_40_links_is_followed_to_its_file(worker: ModuleType, project) -> None:
    index = worker.ProjectModuleIndex(_chain(project, 40), 2_000_000)

    assert "Deep" in index.module_declarations("pkg.l0")
    assert index.read_hashes == {"pkg/real.py": _sha(DEEP)}


def test_a_chain_of_41_links_is_a_probe_in_a_stable_tree(worker: ModuleType, project) -> None:
    index = worker.ProjectModuleIndex(_chain(project, 41), 2_000_000)

    assert index.module_declarations("pkg.l0") == {}
    assert index.read_hashes == {}


def test_a_read_through_a_chain_of_41_links_is_keyed_by_the_last_link_followed(worker: ModuleType, project) -> None:
    index = worker.ProjectModuleIndex(_chain(project, 41), 2_000_000)
    _accepting(index, "l0.py")

    assert index.module_declarations("pkg.l0") == {}
    assert index.read_hashes == {"pkg/l39.py": None}


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
    assert index.read_hashes == {"pkg/c.py": None}


def test_a_link_target_replaced_by_a_directory_before_the_read_is_keyed_by_that_target(
    worker: ModuleType, project
) -> None:
    root = _absolute_link_layout(project)
    index = worker.ProjectModuleIndex(root, 2_000_000)
    _accepting(index, "lnk.py")
    _swap_to_directory(root / "lib" / "b.py")

    assert index.module_declarations("pkg.lnk") == {}
    assert index.read_hashes == {"lib/b.py": None}


def test_an_absolute_link_target_with_a_doubled_leading_slash_is_followed_inside_the_root(
    worker: ModuleType, project
) -> None:
    root = project({"app.py": "x = 1\n", "q/c.py": DEEP.decode()})
    (root / "q" / "l6.py").symlink_to("/" + str(root / "q" / "c.py"))
    index = worker.ProjectModuleIndex(root, 2_000_000)

    assert index.module_file("q.l6") is not None
    assert "Deep" in index.module_declarations("q.l6")
    assert index.read_hashes == {"q/c.py": _sha(DEEP)}
