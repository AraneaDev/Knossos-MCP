"""The exclusion rules the core sends with a scan request, and the fallback without them."""

from __future__ import annotations

from pathlib import Path
from types import ModuleType
from typing import Any

import pytest

# The rules the core sends, as IgnoreMatcher::workerRules() exports them.
CORE_RULES: dict[str, Any] = {
    "segments": [
        ".git",
        ".idea",
        ".knossos",
        "vendor",
        "node_modules",
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
    ],
    "anchored_segments": ["build", "coverage", "dist", "site"],
    "anchor_roots": [""],
    "prefixes": [".knossos-", "_ide_helper"],
    "sequences": [[".vitepress", "cache"], [".vitepress", "dist"]],
    "suffixes": [".min.js", ".min.mjs", ".min.cjs"],
    "path_prefixes": ["public/build", "storage/framework", "storage/attachments", "storage/debugbar", "storage/logs"],
    "patterns": [],
}

# The shared case list; the source of truth is IgnoreMatcherTest::workerRuleCases
# in the core, with the answers its IgnoreMatcher gives. Each entry: patterns as
# workerRules() compiles them, the path, the anchor roots, and whether discovery
# leaves the path out.
SHARED_PATTERNS: list[dict[str, Any]] = [
    {"regex": "legacy", "anchored": True, "negated": False},
    {"regex": "[^/]*\\.gen\\.ts", "anchored": False, "negated": False},
    {"regex": "keep\\.gen\\.ts", "anchored": False, "negated": True},
    {"regex": "docs/[a-zA-Z][^/]*\\.md", "anchored": True, "negated": False},
    {"regex": "tmp[^/]", "anchored": False, "negated": False},
    {"regex": "a/(?:.*/)?b", "anchored": True, "negated": False},
    {"regex": "rooted", "anchored": True, "negated": False},
]
NEGATED_DIST: list[dict[str, Any]] = [{"regex": "packages/a/dist", "anchored": True, "negated": True}]
ROOTS = ["", "packages/a"]
SHARED_CASES: list[tuple[list[dict[str, Any]], str, list[str], bool]] = [
    (SHARED_PATTERNS, "src/a.ts", ROOTS, False),
    (SHARED_PATTERNS, "venv/lib.js", ROOTS, True),
    (SHARED_PATTERNS, "pkg/venv", ROOTS, True),
    (SHARED_PATTERNS, "coverage/x.py", ROOTS, True),
    (SHARED_PATTERNS, ".knossos-ci/x.ts", ROOTS, True),
    (SHARED_PATTERNS, "public/build/app.js", ROOTS, True),
    (SHARED_PATTERNS, "public/buildings/app.js", ROOTS, False),
    (SHARED_PATTERNS, "lib/x.min.js", ROOTS, True),
    (SHARED_PATTERNS, "site/.vitepress/cache/x.js", ROOTS, True),
    (SHARED_PATTERNS, ".vitepress/cache", ROOTS, True),
    (SHARED_PATTERNS, "legacy", ROOTS, True),
    (SHARED_PATTERNS, "legacy/old.ts", ROOTS, True),
    (SHARED_PATTERNS, "src/legacy/old.ts", ROOTS, False),
    (SHARED_PATTERNS, "src/x.gen.ts", ROOTS, True),
    (SHARED_PATTERNS, "src/keep.gen.ts", ROOTS, False),
    (SHARED_PATTERNS, "docs/readme.md", ROOTS, True),
    (SHARED_PATTERNS, "docs/1.md", ROOTS, False),
    (SHARED_PATTERNS, "tmp1", ROOTS, True),
    (SHARED_PATTERNS, "src/tmp2/x.ts", ROOTS, True),
    (SHARED_PATTERNS, "tmp12", ROOTS, False),
    (SHARED_PATTERNS, "a/b", ROOTS, True),
    (SHARED_PATTERNS, "a/x/y/b", ROOTS, True),
    (SHARED_PATTERNS, "rooted", ROOTS, True),
    (SHARED_PATTERNS, "src/rooted", ROOTS, False),
    (SHARED_PATTERNS, "_ide_helper.php", ROOTS, True),
    (SHARED_PATTERNS, "dist/a.js", ROOTS, True),
    (SHARED_PATTERNS, "src/dist/a.js", ROOTS, False),
    (SHARED_PATTERNS, "packages/a/dist/a.js", ROOTS, True),
    (SHARED_PATTERNS, "packages/b/dist/a.js", ROOTS, False),
    (SHARED_PATTERNS, "packages/a/src/build/x.ts", ROOTS, False),
    (SHARED_PATTERNS, "packages/a/coverage", ROOTS, True),
    (SHARED_PATTERNS, "apps/site/c.ts", ROOTS, False),
    (NEGATED_DIST, "dist/a.js", ROOTS, True),
    (NEGATED_DIST, "packages/a/dist/a.js", ROOTS, False),
    (NEGATED_DIST, "packages/a/build/a.js", ROOTS, True),
    ([], "packages/a/dist/a.js", [""], False),
]

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


@pytest.mark.parametrize(("patterns", "path", "roots", "excluded"), SHARED_CASES)
def test_answers_the_shared_case_list_as_the_core_does(
    worker: ModuleType, patterns: list[dict[str, Any]], path: str, roots: list[str], excluded: bool
) -> None:
    rules = worker.Exclusions({**CORE_RULES, "anchor_roots": roots, "patterns": patterns})

    assert rules.excludes(tuple(path.split("/"))) is excluded


def test_anchors_its_fallback_build_output_at_the_project_root_only(worker: ModuleType) -> None:
    rules = worker.Exclusions()

    assert rules.excludes(("src", "build", "x.py")) is False
    assert rules.excludes(("build", "x.py")) is True
    assert rules.excludes(("coverage", "x.py")) is True


def test_refuses_anchored_fields_that_are_not_lists_of_strings(worker: ModuleType) -> None:
    for field, value in (("anchor_roots", [1]), ("anchored_segments", "dist")):
        try:
            worker.Exclusions({**CORE_RULES, field: value})
        except ValueError as error:
            assert "exclusions" in str(error)
        else:
            raise AssertionError(f"malformed {field} was accepted")
