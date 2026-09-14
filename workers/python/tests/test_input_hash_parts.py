"""``scan/input_hashes`` notifications: a large ``input_hashes`` map split across frames."""

from __future__ import annotations

import json
from pathlib import Path
from types import ModuleType
from typing import Any

HASH = "a" * 64


def _size(part: dict[str, str | None]) -> int:
    return len(json.dumps(part, separators=(",", ":"), ensure_ascii=False).encode())


def test_nothing_read_is_one_empty_part(worker: ModuleType) -> None:
    assert worker.input_hash_parts({}) == [{}]


def test_a_map_that_fits_stays_whole(worker: ModuleType) -> None:
    hashes = {"pkg/a.py": HASH, "pkg/b.py": None}

    assert worker.input_hash_parts(hashes) == [hashes]


def test_parts_fit_the_budget_as_serialized_lose_nothing_and_are_full(worker: ModuleType) -> None:
    hashes: dict[str, str | None] = {f"pkg/dir_{i}/é_{i}.py": (None if i % 7 == 0 else HASH) for i in range(500)}

    parts = worker.input_hash_parts(hashes, 4_000)

    assert len(parts) > 10
    for index, part in enumerate(parts):
        assert _size(part) <= 4_000
        if index + 1 < len(parts):
            key, value = next(iter(parts[index + 1].items()))
            assert _size({**part, key: value}) > 4_000
    merged: dict[str, str | None] = {}
    for part in parts:
        merged.update(part)
    assert merged == hashes
    assert [key for part in parts for key in part] == list(hashes)


def test_a_part_fills_to_exactly_the_budget(worker: ModuleType) -> None:
    hashes = {"pkg/a.py": HASH, "pkg/b.py": None}
    exact = _size(hashes)

    assert worker.input_hash_parts(hashes, exact) == [hashes]
    assert worker.input_hash_parts(hashes, exact - 1) == [{"pkg/a.py": HASH}, {"pkg/b.py": None}]


def test_an_entry_larger_than_the_budget_travels_alone(worker: ModuleType) -> None:
    long = "pkg/" + "x" * 300 + ".py"
    hashes = {long: HASH, "pkg/a.py": HASH, long + "x": None}

    assert worker.input_hash_parts(hashes, 200) == [{long: HASH}, {"pkg/a.py": HASH}, {long + "x": None}]


def test_the_default_part_is_well_under_the_core_line_cap(worker: ModuleType) -> None:
    assert worker.INPUT_HASHES_PART_BYTES <= 500_000


def test_a_scan_sends_all_but_the_last_part_ahead_of_its_result(monkeypatch, worker: ModuleType, project) -> None:
    root: Path = project({f"pkg/m{i}.py": f"X{i} = {i}\n" for i in range(40)} | {"pkg/__init__.py": ""})
    written: list[dict[str, Any]] = []
    monkeypatch.setattr(worker, "write", written.append)
    monkeypatch.setattr(worker, "INPUT_HASHES_PART_BYTES", 300)

    files = sorted(f"pkg/m{i}.py" for i in range(40))
    worker.handle({"jsonrpc": "2.0", "id": 7, "method": "scan", "params": {"root": str(root), "files": files}})

    parts = [message for message in written if message.get("method") == "scan/input_hashes"]
    result = written[-1]
    assert result["id"] == 7
    assert len(parts) > 1
    assert all(message["jsonrpc"] == "2.0" and set(message["params"]) == {"input_hashes"} for message in parts)
    merged: dict[str, str | None] = {}
    for message in parts:
        merged.update(message["params"]["input_hashes"])
    merged.update(result["result"]["input_hashes"])
    # Every requested file's read, beside the probes the index recorded.
    assert set(files) <= set(merged)
    # Each entry travels once: the result keeps only the last part.
    total = sum(len(message["params"]["input_hashes"]) for message in parts) + len(result["result"]["input_hashes"])
    assert total == len(merged)
    assert result["result"]["input_hashes"] != {}
    # Every notification precedes the result.
    assert written.index(parts[-1]) < len(written) - 1
