"""What discovery leaves out of a scan, and the gitignore-style rules a request can add."""

import re
from typing import Any

# What discovery leaves out, for a request that does not carry the core's own
# rules (``exclusions``, see :class:`Exclusions`), which replace these.
EXCLUDED = {
    ".git",
    ".knossos",
    ".venv",
    "venv",
    "__pycache__",
    ".tox",
    ".mypy_cache",
    ".pytest_cache",
    ".worktrees",
    "node_modules",
    "vendor",
    # Mutation-testing sandboxes are not source.
    ".stryker-tmp",
    ".pnpm-store",
    ".yarn",
}
# Build output, excluded only directly under an anchor root: the project root,
# or (when the core sends them) the directories holding a package or build
# manifest. Below anything else these names are ordinary source directories.
ANCHORED_EXCLUDED = ("build", "coverage", "dist", "site")
# Name prefixes for the namespace this tool owns. ".knossos" alone is in the
# set above; a CI job parks a checkout of the analyzer or its snapshot database
# beside the project under the same convention, and neither is a source root of
# the project being scanned.
EXCLUDED_PREFIXES = (".knossos-",)
# Dependency trees may be read for import resolution even though discovery
# does not scan them as project-owned source. Generated and tool-owned trees
# remain blocked at this boundary.
RESOLUTION_ALLOWED_EXCLUDED = {"node_modules", "vendor"}


class Exclusions:
    """What discovery leaves out, as the rules the core sends with a request.

    The core's IgnoreMatcher exports them (``exclusions``): segments, segment
    prefixes and pairs of segments excluded anywhere, file-name suffixes, path
    prefixes, build-output segments anchored under the manifest roots
    (``anchored_segments``, ``anchor_roots``), and the project's own patterns,
    the last match deciding. A path is excluded when it or a directory above it
    matches, since discovery never descends into a directory that matches. An
    anchored segment marks a directory ignored before the patterns decide, so a
    negated pattern re-includes it. Without the rules, the built-in list above
    stands in, with build output anchored at the project root.
    """

    def __init__(self, rules: Any = None) -> None:
        if rules is None:
            rules = {"segments": sorted(EXCLUDED), "prefixes": list(EXCLUDED_PREFIXES)}
            rules |= {"sequences": [], "suffixes": [], "path_prefixes": [], "patterns": []}
            rules |= {"anchored_segments": list(ANCHORED_EXCLUDED), "anchor_roots": [""]}
        if not self._valid(rules):
            raise ValueError(
                "Python exclusions must be an object of segments, anchored_segments, anchor_roots, prefixes, "
                "sequences, suffixes, path_prefixes and patterns."
            )
        self.segments = frozenset(rules["segments"])
        self.anchored_segments = frozenset(rules.get("anchored_segments", []))
        self.anchor_roots = frozenset(rules.get("anchor_roots", []))
        self.prefixes = tuple(rules["prefixes"])
        self.sequences = [tuple(pair) for pair in rules["sequences"]]
        self.suffixes = tuple(rules["suffixes"])
        self.path_prefixes = list(rules["path_prefixes"])
        self.patterns = [
            (
                re.compile(f"^(?:{item['regex']})(?:/.*)?$" if item["anchored"] else f"^(?:{item['regex']})$"),
                item["anchored"],
                item["negated"],
            )
            for item in rules["patterns"]
        ]

    @staticmethod
    def _valid(rules: Any) -> bool:
        """Whether ``rules`` has the shape IgnoreMatcher exports; the anchored fields are optional."""

        def strings(value: Any) -> bool:
            return isinstance(value, list) and all(isinstance(item, str) for item in value)

        return (
            isinstance(rules, dict)
            and all(strings(rules.get(field)) for field in ("segments", "prefixes", "suffixes", "path_prefixes"))
            and all(field not in rules or strings(rules[field]) for field in ("anchored_segments", "anchor_roots"))
            and isinstance(rules.get("sequences"), list)
            and all(strings(pair) and len(pair) == 2 for pair in rules["sequences"])
            and isinstance(rules.get("patterns"), list)
            and all(
                isinstance(item, dict)
                and isinstance(item.get("regex"), str)
                and isinstance(item.get("anchored"), bool)
                and isinstance(item.get("negated"), bool)
                for item in rules["patterns"]
            )
        )

    def excludes(self, parts: tuple[str, ...]) -> bool:
        """Whether discovery leaves out the project-relative path made of ``parts``."""
        pairs = self.sequences
        for index, segment in enumerate(parts):
            if (
                segment in self.segments
                or segment.startswith(self.prefixes)
                or segment.endswith(self.suffixes)
                or any(segment == first and parts[index + 1 : index + 2] == (second,) for first, second in pairs)
            ):
                return True
        joined = "/".join(parts)
        if any(joined == prefix or joined.startswith(prefix + "/") for prefix in self.path_prefixes):
            return True
        anchored = False
        for end in range(1, len(parts) + 1):
            anchored = anchored or (
                parts[end - 1] in self.anchored_segments and "/".join(parts[: end - 1]) in self.anchor_roots
            )
            if self._ignored(parts[:end], anchored):
                return True
        return False

    def _ignored(self, parts: tuple[str, ...], ignored: bool) -> bool:
        """Whether the last pattern that matches ``parts`` ignores it; ``ignored`` stands when none matches."""
        joined = "/".join(parts)
        for expression, anchored, negated in self.patterns:
            matched = expression.match(joined) if anchored else any(expression.match(part) for part in parts)
            if matched:
                ignored = not negated
        return ignored
