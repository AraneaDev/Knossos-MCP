"""The exclusion rules the core sends with a scan request, and the fallback without them."""

from __future__ import annotations

from pathlib import Path
from types import ModuleType
from typing import Any

# The rules the core sends, as IgnoreMatcher::workerRules() exports them.
CORE_RULES: dict[str, Any] = {
    "segments": [
        ".git",
        ".idea",
        ".knossos",
        "vendor",
        "node_modules",
        "coverage",
        ".next",
        ".nuxt",
        ".venv",
        "venv",
        "__pycache__",
        ".tox",
        ".mypy_cache",
        ".pytest_cache",
        ".worktrees",
        ".stryker-tmp",
        ".pnpm-store",
        ".yarn",
        "build",
        "dist",
        "site",
    ],
    "prefixes": [".knossos-", "_ide_helper"],
    "sequences": [[".vitepress", "cache"], [".vitepress", "dist"]],
    "suffixes": [".min.js", ".min.mjs", ".min.cjs"],
    "path_prefixes": ["public/build", "storage/framework", "storage/attachments", "storage/debugbar", "storage/logs"],
    "patterns": [],
}

LAYOUT = {
    "app/__init__.py": "",
    "app/main.py": (
        "from coverage.helpers import measure\nfrom legacy.old import Old\n\n\n"
        "def run() -> None:\n    measure()\n    Old()\n"
    ),
    "coverage/__init__.py": "",
    "coverage/helpers.py": "def measure() -> None:\n    pass\n",
    "legacy/__init__.py": "",
    "legacy/old.py": "class Old:\n    pass\n",
}


def _scan(worker: ModuleType, root: Path, exclusions: dict[str, Any] | None) -> tuple[dict[str, Any], dict[str, Any]]:
    emitted: list[dict[str, Any]] = []
    params: dict[str, Any] = {"root": str(root), "files": ["app/main.py"]}
    if exclusions is not None:
        params["exclusions"] = exclusions
    result = worker.scan(params, emitted.append)
    return result, emitted[0]


def test_reads_nothing_discovery_leaves_out_and_draws_no_fact_from_it(worker: ModuleType, project) -> None:
    root = project(LAYOUT)
    rules = {**CORE_RULES, "patterns": [{"regex": "legacy", "anchored": True, "negated": False}]}

    result, contribution = _scan(worker, root, rules)

    read = list(result["input_hashes"])
    assert [key for key in read if key.startswith(("coverage/", "legacy/"))] == []
    # Nothing resolves to what those files declare: the names stay external.
    targets = {edge["target"] for edge in contribution["edges"]}
    assert "py:function:coverage.helpers.measure" not in targets
    assert "py:class:legacy.old.Old" not in targets
    assert "py:external_symbol:coverage.helpers.measure" in targets


def test_falls_back_to_its_own_list_when_the_request_carries_none(worker: ModuleType, project) -> None:
    root = project({**LAYOUT, "venv/__init__.py": "", "venv/lib.py": "x = 1\n"})

    result, _ = _scan(worker, root, None)

    assert [key for key in result["input_hashes"] if key.startswith("venv/")] == []


def test_refuses_exclusions_that_are_not_the_rules_object(worker: ModuleType, project) -> None:
    root = project(LAYOUT)

    try:
        _scan(worker, root, {"segments": "coverage"})
    except ValueError as error:
        assert "exclusions" in str(error)
    else:
        raise AssertionError("malformed exclusions were accepted")
