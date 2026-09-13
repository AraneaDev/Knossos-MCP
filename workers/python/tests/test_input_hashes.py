"""The ``input_hashes`` map a scan result reports for every file the request read.

The PHPUnit suite drives the real worker process and covers the ordinary reads.
These cases reach what a real process cannot be made to do on demand: a read
that fails after the index found the file (running as root, a chmod does not
stop the read), and a file whose bytes change between two reads in one request.
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


def test_the_first_read_of_a_path_wins_over_a_later_one(monkeypatch, worker: ModuleType, project) -> None:
    root = project(
        {
            "pkg/a.py": "from pkg.b import Thing\n\n\nclass Local(Thing):\n    pass\n",
            "pkg/b.py": "class Thing:\n    pass\n",
        }
    )
    first = (root / "pkg/b.py").read_bytes()
    second = b"class Thing:\n    changed = True\n"
    real_read_bytes = Path.read_bytes
    reads: list[str] = []

    def rewriting(self: Path) -> bytes:
        if self.name != "b.py":
            return real_read_bytes(self)
        reads.append(self.name)
        return first if len(reads) == 1 else second

    monkeypatch.setattr(Path, "read_bytes", rewriting)
    result, contributions = _scan(worker, root, ["pkg/a.py", "pkg/b.py"])

    # The index read b.py for a's import, then b's own scan read it again.
    assert len(reads) == 2
    assert result["input_hashes"]["pkg/b.py"] == _sha(first)
    assert contributions["pkg/b.py"]["content_hash"] == _sha(second)


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
