#!/usr/bin/env python3
"""Knossos Python scanner worker. Parses target files; never imports them."""

from __future__ import annotations

import ast
import hashlib
import json
import os
import re
import sys
from collections.abc import Callable
from pathlib import Path, PurePosixPath
from typing import Any, NamedTuple

VERSION = "0.5.0"
EXCLUDED = {
    ".git",
    ".knossos",
    ".venv",
    "venv",
    "__pycache__",
    ".tox",
    ".mypy_cache",
    ".pytest_cache",
    "node_modules",
    "vendor",
    # Kept in sync with the authoritative PHP IgnoreMatcher: generated build
    # output and mutation-testing sandboxes are not source.
    ".stryker-tmp",
    "build",
    "dist",
}
# Name prefixes for the namespace this tool owns, kept in sync with the PHP
# IgnoreMatcher. ".knossos" alone is in the set above; a CI job parks a checkout
# of the analyzer or its snapshot database beside the project under the same
# convention, and neither is a source root of the project being scanned.
EXCLUDED_PREFIXES = (".knossos-",)


def is_excluded(name: str) -> bool:
    """Whether a directory name is excluded from discovery."""
    return name in EXCLUDED or name.startswith(EXCLUDED_PREFIXES)


# Bytes read when probing an extensionless file's shebang; one short line is enough.
SHEBANG_PROBE_BYTES = 256
# The UTF-8 byte-order mark, spelled out rather than reached for through
# `codecs`: a single constant does not earn a module dependency, and this file's
# import count is a budgeted maintainability metric.
UTF8_BOM = b"\xef\xbb\xbf"


def write(message: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(message, separators=(",", ":"), ensure_ascii=False) + "\n")
    sys.stdout.flush()


def safe_root(value: Any) -> Path:
    if not isinstance(value, str) or not value:
        raise ValueError("A project root is required.")
    root = Path(value).resolve(strict=True)
    if not root.is_dir():
        raise ValueError("Project root is not a directory.")
    return root


def names_python_in_shebang(absolute: Path) -> bool:
    """Whether an extensionless script's first line names Python as its interpreter.

    Discovery classifies an extensionless script by its shebang and routes it
    here, so gating on the suffix alone rejected exactly the files discovery had
    just resolved. Mirrors the discoverer's rule: match both `#!/usr/bin/python`
    and `#!/usr/bin/env python`, tolerate a version suffix such as `python3.12`,
    and anchor to a word boundary so a path merely containing an interpreter name
    is not matched. Only the first line is read, and only for a suffixless file.
    """
    if absolute.suffix:
        return False
    try:
        with absolute.open("rb") as handle:
            first = handle.readline(SHEBANG_PROBE_BYTES).decode("utf-8", "replace")
    except OSError:
        return False
    return first.startswith("#!") and re.search(r"\b(python)[0-9.]*\b", first, re.IGNORECASE) is not None


def starts_with_shebang(source: bytes) -> bool:
    """Whether the file opens with a shebang, whatever interpreter it names.

    A shebang means the file is meant to be executed rather than imported, which
    is what dead-code analysis needs to know: nothing in the codebase references
    a script, so its module having no inbound edge says nothing about whether it
    is wanted. Unlike `names_python_in_shebang`, which decides whether an
    extensionless file is Python at all, this asks only how the file is entered,
    so the interpreter is irrelevant. A byte-order mark may precede it.
    """
    return source.removeprefix(UTF8_BOM).startswith(b"#!")


def names_main_guard(tree: ast.Module) -> bool:
    """Whether the module body guards a block with `if __name__ == "__main__":`.

    The other half of the same question: a module run as `python -m package.mod`
    carries no shebang, and the guard is the declaration that it is meant to be
    run. Only module-level statements are considered — the guard means nothing
    nested inside a function — and either operand order is accepted.
    """
    for statement in tree.body:
        if not isinstance(statement, ast.If):
            continue
        test = statement.test
        if not isinstance(test, ast.Compare) or len(test.ops) != 1 or not isinstance(test.ops[0], ast.Eq):
            continue
        operands = (test.left, test.comparators[0])
        names = {operand.id for operand in operands if isinstance(operand, ast.Name)}
        values = {operand.value for operand in operands if isinstance(operand, ast.Constant)}
        if "__name__" in names and "__main__" in values:
            return True
    return False


def assert_scannable_path(value: Any) -> PurePosixPath:
    """Reject a path of the wrong shape, which can name no file at all.

    Kept separate from reading the file: a malformed path cannot be attributed to
    any file, so it fails the request, while a well-formed path that simply
    cannot be scanned costs only that file.
    """
    if not isinstance(value, str) or not value or "\0" in value or "\\" in value:
        raise ValueError("Python input must be a normalized project-relative path.")
    relative = PurePosixPath(value)
    if relative.is_absolute() or any(part in {"", ".", ".."} for part in relative.parts):
        raise ValueError("Python input path is unsafe.")
    return relative


def safe_file(root: Path, value: Any, max_bytes: int) -> tuple[Path, str]:
    relative = assert_scannable_path(value)
    absolute = (root / Path(*relative.parts)).resolve(strict=True)
    try:
        absolute.relative_to(root)
    except ValueError as error:
        raise ValueError("Python input path escapes the project root.") from error
    if not absolute.is_file() or not (absolute.suffix.lower() in {".py", ".pyi"} or names_python_in_shebang(absolute)):
        raise ValueError("Unsupported Python input.")
    if absolute.stat().st_size > max_bytes:
        raise ValueError("Python input exceeds the configured byte limit.")
    return absolute, relative.as_posix()


def module_name(relative: str, strip: int = 0) -> str:
    path = PurePosixPath(relative)
    parts = list(path.with_suffix("").parts)[strip:]
    if parts and parts[-1] == "__init__":
        parts.pop()
    return ".".join(parts) or "__root__"


class ProjectModuleIndex:
    """Filesystem-backed, batch-independent module resolution.

    Import and reference targets must be identical no matter how a scan request
    was chunked, so resolution is grounded in the project's on-disk layout — not
    in whichever files happen to share the current batch. Source roots (bare root
    plus non-package top-level directories such as ``src/``) are detected once,
    and each referenced module's top-level declarations are parsed lazily and
    memoized. Only files that live under the validated root and stay within the
    byte cap are read.

    ``read_hashes`` records every file a request read, keyed by its
    project-relative path, for the result's ``input_hashes``: the SHA-256 of
    the bytes read, or ``None`` when the read was attempted and failed. One
    path can be read more than once in a request: by an importer's resolution
    and by its own scan, or under two module ids. Two reads that disagree, in
    hash or in whether they succeeded, record ``None``, because at least one
    of them differs from what discovery hashed or failed, and either may have
    fed facts. Keeping either value alone would leave the other read
    unverified.
    """

    def __init__(self, root: Path, max_bytes: int) -> None:
        self.root = root
        self.max_bytes = max_bytes
        self.prefixes = self._source_root_prefixes()
        self._cache: dict[str, dict[str, str]] = {}
        self.read_hashes: dict[str, str | None] = {}

    def record_read(self, relative: str, content_hash: str | None) -> None:
        """Record one read for ``input_hashes``; a disagreeing repeat read records ``None``."""
        if relative in self.read_hashes and self.read_hashes[relative] != content_hash:
            content_hash = None
        self.read_hashes[relative] = content_hash

    def _in_root_file(self, walked: PathWalk) -> Path | None:
        """The file a walked path opens, when that file lies inside the root.

        Discovery never follows a symlink, so a module reached through a linked
        file or directory is read where its bytes actually live, and keyed
        there by :func:`walk_key`; that is the path whose recorded hash
        describes them. ``None`` for a walk that did not end at a file inside
        the root: nothing outside the root is read.
        """
        if walked.kind != "file" or walked.location is None or not _inside(self.root, walked.location):
            return None
        return Path(walked.location)

    def _record_walk(self, walked: PathWalk, content_hash: str | None) -> None:
        """Record a read under the key its walk gives, if it gives one."""
        relative = walk_key(self.root, walked)
        if relative is not None:
            self.record_read(relative, content_hash)

    def _source_root_prefixes(self) -> list[tuple[str, ...]]:
        prefixes: list[tuple[str, ...]] = [()]
        try:
            for child in sorted(self.root.iterdir()):
                if is_excluded(child.name) or not child.is_dir():
                    continue
                if not (child / "__init__.py").is_file():
                    prefixes.append((child.name,))
        except OSError:
            pass
        return prefixes

    def module_for(self, relative: str) -> str:
        parts = PurePosixPath(relative).parts
        best: tuple[str, ...] = ()
        for prefix in self.prefixes:
            if len(prefix) < len(parts) and parts[: len(prefix)] == prefix and len(prefix) > len(best):
                best = prefix
        return module_name(relative, len(best))

    def module_file(self, module: str) -> Path | None:
        parts = module.split(".")
        if not parts or "" in parts:
            return None
        for prefix in self.prefixes:
            base = self.root.joinpath(*prefix).joinpath(*parts)
            # Prefer the package (``mod/__init__.py``) over a same-named module
            # (``mod.py``) so a colliding pair resolves to a single stable id.
            for candidate in (base / "__init__.py", base.with_suffix(".py")):
                if self._is_project_file(candidate):
                    return candidate
        return None

    def _is_project_file(self, path: Path) -> bool:
        """Whether ``path`` is a module file this index may read.

        A candidate that does not exist was only probed for. One that exists
        but is refused (it links out of the root, it is over the byte cap, or
        it cannot be examined) is left out of resolution, so every importer's
        facts are computed as if it did not exist; it is recorded as a failed
        read under its own path. A stable layout never trips over that, since
        discovery reports neither a symlink nor an over-cap file and the core
        ignores a path it did not discover, while a discovered file that became
        one mid-scan fails verification.
        """
        try:
            if not path.is_file():
                return False
        except OSError:
            pass
        walked = walk_path(path)
        location = self._in_root_file(walked)
        try:
            if location is not None and location.stat().st_size <= self.max_bytes:
                return True
        except OSError:
            pass
        # Keyed by the same walk a successful read of it is keyed by.
        self._record_walk(walked, None)
        return False

    def module_declarations(self, module: str) -> dict[str, str]:
        cached = self._cache.get(module)
        if cached is not None:
            return cached
        declarations: dict[str, str] = {}
        path = self.module_file(module)
        # One walk decides whether the module may be read, the file its bytes
        # are read from, and the key the read goes under, so none can disagree.
        walked = None if path is None else walk_path(path)
        location = None if walked is None else self._in_root_file(walked)
        if walked is not None and location is None:
            # Accepted by module_file, then retargeted out of the root or gone
            # before this read resolved it: not read, and recorded as refused.
            self._record_walk(walked, None)
        if walked is not None and location is not None:
            try:
                source = location.read_bytes()
            except OSError:
                self._record_walk(walked, None)
            else:
                # Hashed before parsing, so a module that fails to parse still
                # reports the bytes this request saw.
                self._record_walk(walked, hashlib.sha256(source).hexdigest())
                try:
                    declarations = top_level_declarations(ast.parse(source), module)
                except (SyntaxError, ValueError, RecursionError):
                    declarations = {}
        self._cache[module] = declarations
        return declarations

    def adopt_parsed(self, absolute: Path, relative: str, tree: ast.Module) -> None:
        """Make a scanned file's own declarations come from the tree just parsed.

        ``module_declarations`` reads a module's file on its own, so an earlier
        file in the batch that imports this one may have cached declarations
        from bytes other than the ones this scan hashed. Overwriting the entry
        with the hashed tree ties the file's own local resolution to its
        ``content_hash``. The entry is only replaced when the module id resolves
        to this very file: for the loser of a ``mod.py``/``mod/__init__.py``
        collision the id names the package, and seeding it from the module file
        would make every later importer's targets depend on batch order.
        """
        module = self.module_for(relative)
        owner = self.module_file(module)
        if owner is None or owner.resolve() != absolute:
            return
        self._cache[module] = top_level_declarations(tree, module)

    def collides(self, absolute: Path, is_package: bool) -> bool:
        """A ``mod.py``/``mod/__init__.py`` pair maps to the same module id."""
        try:
            if is_package:
                competitor = absolute.parent.with_suffix(".py")
            else:
                competitor = absolute.with_suffix("") / "__init__.py"
            return competitor.is_file()
        except OSError:
            return False


# Linux's MAXSYMLINKS: one lookup follows at most this many links, and the next
# one fails it with ELOOP.
MAX_SYMLINK_HOPS = 40
# The file-type bits of ``st_mode``, spelled out rather than imported from
# ``stat``: three constants do not earn a module dependency, and this file's
# import count is a budgeted maintainability metric.
S_IFMT = 0o170000
S_IFDIR = 0o040000
S_IFLNK = 0o120000


class PathWalk(NamedTuple):
    """How the kernel's lookup of one path went; see :func:`walk_path`.

    ``links`` holds each symlink followed, in order, with the components that
    were still to walk after it at that moment.
    """

    kind: str
    location: str | None
    links: tuple[tuple[str, tuple[str, ...]], ...]


def walk_path(path: str | os.PathLike[str]) -> PathWalk:
    """Resolve an absolute path the way the kernel's lookup does, one component at a time.

    The result names the file a read of the path opens. ``current`` is always a
    real directory, so a ``..`` steps to where the kernel's ``..`` goes once
    the links before it have been followed. A symlink's target is put in front
    of the components still to walk, raw, so its own ``..`` is applied the same
    way; nothing is ever collapsed as text.

    - ``file``: the walk reached a non-directory as its last component.
    - ``directory``: the walk ended at a directory.
    - ``missing``: a component does not exist, or is a non-directory with more
      to walk (ENOENT, ENOTDIR); ``location`` is the path the read was to open
      (see :func:`_absent_below`).
    - ``unresolvable``: no such path can be named: a ``..`` left to apply below
      a missing component, another lookup error, or more than
      ``MAX_SYMLINK_HOPS`` links (ELOOP).

    ``path`` must be absolute; every module path is built on the real root.
    """
    text = os.fspath(path)
    current = Path(text).anchor
    remaining = text[len(current) :].split(os.sep)
    links: list[tuple[str, tuple[str, ...]]] = []
    hops = 0
    while remaining:
        name = remaining.pop(0)
        if name in ("", os.curdir):
            continue
        if name == os.pardir:
            current = os.path.dirname(current)
            continue
        candidate = os.path.join(current, name)
        try:
            mode = os.lstat(candidate).st_mode & S_IFMT
        except FileNotFoundError:
            return _absent_below(candidate, remaining, links)
        except OSError:
            return PathWalk("unresolvable", None, tuple(links))
        if mode == S_IFLNK:
            hops += 1
            if hops > MAX_SYMLINK_HOPS:
                return PathWalk("unresolvable", None, tuple(links))
            links.append((candidate, tuple(remaining)))
            try:
                target = os.readlink(candidate)
            except OSError:
                return PathWalk("unresolvable", None, tuple(links))
            if os.path.isabs(target):
                current = Path(target).anchor
                target = target[len(current) :]
            remaining[:0] = target.split(os.sep)
        elif mode == S_IFDIR:
            current = candidate
        elif remaining:
            return _absent_below(candidate, remaining, links)
        else:
            return PathWalk("file", candidate, tuple(links))
    return PathWalk("directory", current, tuple(links))


def _absent_below(candidate: str, remaining: list[str], links: list[tuple[str, tuple[str, ...]]]) -> PathWalk:
    """The walk's result when the lookup fails at ``candidate``, which is absent or not a directory.

    Nothing exists below it, so nothing below it can be a link, and without a
    ``..`` still to apply the remaining components name exactly the file the
    read was to open. A ``..`` still to apply could only be resolved against a
    directory that is not there, so the path is unresolvable.
    """
    rest = [name for name in remaining if name not in ("", os.curdir)]
    if os.pardir in rest:
        return PathWalk("unresolvable", None, tuple(links))
    return PathWalk("missing", os.path.join(candidate, *rest), tuple(links))


def _inside(root: Path, candidate: str) -> bool:
    base = os.fspath(root)
    return candidate == base or candidate.startswith(base.rstrip(os.sep) + os.sep)


def walk_key(root: Path, walked: PathWalk) -> str | None:
    """The root-relative key a walked read goes under in ``input_hashes``.

    - A walk that ended at a file, or at a missing location, inside the root:
      that location, the file the read opened or would have opened.
    - Otherwise the last link followed inside the root. A link followed as a
      directory component keys the path below it as it was about to be walked
      (a discovered ``sub/c.py`` whose ``sub`` became a link out of the root
      keys ``sub/c.py``), unless that path holds a ``..``, which only the walk
      could have applied; then the link itself.

    ``None`` when no location the walk passed lies inside the root. Every key a
    stable layout produces names either the file the kernel reaches, or a link
    or a path through one or through a missing component, none of which
    discovery reports, so the core ignores it; a discovered file changed into
    one of those mid-scan is keyed where discovery saw it and fails
    verification. ``root`` must be a real path, as :func:`safe_root` makes it.
    """
    location = walked.location
    if walked.kind not in ("file", "missing") or location is None or not _inside(root, location):
        inside = [link for link in walked.links if _inside(root, link[0])]
        if not inside:
            return None
        location, remaining = inside[-1]
        rest = [name for name in remaining if name not in ("", os.curdir)]
        if rest and os.pardir not in rest:
            location = os.path.join(location, *rest)
    if location == os.fspath(root):
        return None
    return PurePosixPath(os.path.relpath(location, root)).as_posix()


def input_key(root: Path, path: str | os.PathLike[str]) -> str | None:
    """The key a read of ``path`` goes under: :func:`walk_key` of its walk."""
    return walk_key(root, walk_path(path))


def top_level_declarations(tree: ast.Module, module: str) -> dict[str, str]:
    """Map each top-level class and function name in ``tree`` to its symbol reference."""

    declarations: dict[str, str] = {}
    for child in tree.body:
        if isinstance(child, ast.ClassDef):
            declarations[child.name] = ref("class", f"{module}.{child.name}")
        elif isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef)):
            declarations[child.name] = ref("function", f"{module}.{child.name}")
    return declarations


def ref(kind: str, canonical: str) -> str:
    return f"py:{kind}:{canonical}"


def evidence(relative: str, node: ast.AST) -> dict[str, Any]:
    start = max(1, int(getattr(node, "lineno", 1)))
    end = max(start, int(getattr(node, "end_lineno", start) or start))
    return {"path": relative, "start_line": start, "end_line": end}


def dotted(node: ast.AST) -> str | None:
    if isinstance(node, ast.Name):
        return node.id
    if isinstance(node, ast.Attribute):
        base = dotted(node.value)
        return f"{base}.{node.attr}" if base else None
    return None


def absolute_import(current_module: str, level: int, imported: str | None, is_package: bool = False) -> str:
    if level == 0:
        return imported or ""
    package = current_module.split(".") if is_package else current_module.split(".")[:-1]
    if level > 0:
        package = package[: max(0, len(package) - (level - 1))]
    if imported:
        package.extend(imported.split("."))
    return ".".join(package)


def decorator_short(name: str) -> str:
    return name.rsplit(".", 1)[-1]


def positional_string(call: ast.Call, position: int) -> str | None:
    if len(call.args) <= position:
        return None
    value = call.args[position]
    return value.value if isinstance(value, ast.Constant) and isinstance(value.value, str) else None


def keyword_string(call: ast.Call, name: str) -> str | None:
    value = next((item.value for item in call.keywords if item.arg == name), None)
    return value.value if isinstance(value, ast.Constant) and isinstance(value.value, str) else None


class PythonFactAccumulator:
    """Store and deterministically render facts collected for one Python file."""

    def __init__(self, relative: str) -> None:
        self.relative = relative
        self.nodes: dict[str, dict[str, Any]] = {}
        self.edges: dict[str, dict[str, Any]] = {}
        self.diagnostics: list[dict[str, Any]] = []

    def add_node(
        self,
        local_id: str,
        kind: str,
        canonical: str,
        display: str,
        node: ast.AST,
        attributes: dict[str, Any] | None = None,
    ) -> None:
        self.nodes.setdefault(
            local_id,
            {
                "local_id": local_id,
                "kind": kind,
                "canonical_name": canonical,
                "display_name": display,
                "origin": "ast",
                "confidence": "certain",
                "evidence": evidence(self.relative, node),
                "attributes": attributes or {},
            },
        )

    def add_edge(
        self, kind: str, source: str, target: str, node: ast.AST, attributes: dict[str, Any] | None = None
    ) -> None:
        item = {
            "kind": kind,
            "source": source,
            "target": target,
            "origin": "ast",
            "confidence": "certain",
            "evidence": evidence(self.relative, node),
            "attributes": attributes or {},
        }
        key = json.dumps([kind, source, target], sort_keys=True)
        self.edges.setdefault(key, item)

    def add_diagnostic(self, code: str, message: str, node: ast.AST) -> None:
        self.diagnostics.append(
            {"severity": "warning", "code": code, "message": message, "evidence": evidence(self.relative, node)}
        )

    def result(self) -> dict[str, Any]:
        return {
            "owner_key": f"knossos.python:file:{self.relative}",
            "nodes": sorted(self.nodes.values(), key=lambda item: item["local_id"]),
            "edges": sorted(
                self.edges.values(),
                key=lambda item: (item["kind"], item["source"], item["target"], item["evidence"]["start_line"]),
            ),
            "diagnostics": self.diagnostics,
        }


class PythonFrameworkRoleEnricher:
    """Derive framework classifications without owning AST traversal."""

    @staticmethod
    def decorators(node: ast.AST) -> list[str]:
        result = []
        for decorator in getattr(node, "decorator_list", []):
            target = decorator.func if isinstance(decorator, ast.Call) else decorator
            name = dotted(target)
            if name:
                result.append(name)
        return result

    def class_roles(self, node: ast.ClassDef, decorators: list[str]) -> list[str]:
        roles: list[str] = []
        for base in node.bases:
            base_name = dotted(base) or ""
            if base_name.endswith("models.Model") or base_name == "Model":
                roles.append("django.model")
            if base_name.endswith("View") or base_name.endswith("ViewSet"):
                roles.append("django.view")
        if node.name.endswith("Middleware") and any(
            isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef)) and item.name == "__call__" for item in node.body
        ):
            roles.append("django.middleware")
        if any(decorator_short(name) == "AsgiMiddleware" for name in decorators):
            roles.append("django.middleware")
        if any(base_name.endswith("MethodView") for base_name in (dotted(base) or "" for base in node.bases)):
            roles.append("flask.view")
        return sorted(set(roles))

    def function_roles(
        self, decorators: list[str], has_fastapi_route: bool = False, has_flask_route: bool = False
    ) -> list[str]:
        roles: list[str] = []
        framework_decorators = [decorator_short(name) for name in decorators]
        if any(name in {"api_view", "action"} for name in framework_decorators):
            roles.append("django.view")
        if any(name in {"shared_task", "task"} for name in framework_decorators):
            roles.append("python.task")
        if has_fastapi_route:
            roles.append("fastapi.route_handler")
        if has_flask_route:
            roles.append("flask.route_handler")
        return sorted(set(roles))


class FastApiFactEnricher:
    """Add FastAPI routes, dependencies, routers, and middleware facts."""

    def __init__(
        self,
        facts: PythonFactAccumulator,
        module: str,
        module_id: str,
        aliases: dict[str, str],
        resolve_name: Callable[[str, str], str | None],
    ) -> None:
        self.facts = facts
        self.module = module
        self.module_id = module_id
        self.aliases = aliases
        self.resolve_name = resolve_name
        self.framework_objects: dict[str, tuple[str, str]] = {}

    def register_assignment(self, variable: str, value: ast.AST | None) -> None:
        if not isinstance(value, ast.Call):
            return
        called = dotted(value.func)
        resolved = self.aliases.get(called or "", "")
        if resolved.endswith("fastapi.FastAPI") or resolved.endswith("fastapi.APIRouter"):
            prefix = keyword_string(value, "prefix") or ""
            self.framework_objects[variable] = ("fastapi", prefix)

    def route_decorators(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> list[tuple[str, str, ast.AST]]:
        result: list[tuple[str, str, ast.AST]] = []
        methods = {"get", "post", "put", "patch", "delete", "options", "head", "trace"}
        for decorator in node.decorator_list:
            if not isinstance(decorator, ast.Call) or not isinstance(decorator.func, ast.Attribute):
                continue
            owner = dotted(decorator.func.value)
            method = decorator.func.attr.lower()
            if owner not in self.framework_objects or method not in methods:
                continue
            raw_path = positional_string(decorator, 0)
            if raw_path is None:
                self.facts.add_diagnostic("PY_DYNAMIC_ROUTE_PATH", "Dynamic FastAPI route path was skipped.", decorator)
                continue
            prefix = self.framework_objects[owner][1]
            path = "/" + "/".join(part.strip("/") for part in (prefix, raw_path) if part.strip("/"))
            result.append((method.upper(), path or "/", decorator))
        return result

    def enrich_function(
        self,
        node: ast.FunctionDef | ast.AsyncFunctionDef,
        local_id: str,
        canonical: str,
        route_decorators: list[tuple[str, str, ast.AST]],
    ) -> None:
        for method, path, decorator in route_decorators:
            route_canonical = f"{method} {path} => {canonical}"
            route_id = ref("route", route_canonical)
            self.facts.add_node(
                route_id,
                "route",
                route_canonical,
                f"{method} {path}",
                decorator,
                {"framework": "fastapi", "methods": [method], "path": path},
            )
            self.facts.add_edge("routes_to", route_id, local_id, decorator)
        self.decorator_dependencies(node, local_id)
        self.parameter_dependencies(node, local_id)

    def enrich_call(self, node: ast.Call, name: str | None) -> None:
        if name and name.endswith(".add_middleware") and node.args:
            middleware = dotted(node.args[0])
            target = self.resolve_name(middleware, "class") if middleware else None
            if target:
                self.facts.add_edge("uses_middleware", self.module_id, target, node, {"framework": "fastapi"})
        if name and name.endswith(".include_router") and node.args:
            router = dotted(node.args[0])
            if router:
                self.facts.add_edge(
                    "mounts",
                    self.module_id,
                    ref("router", f"{self.module}.{router}"),
                    node,
                    {"prefix": keyword_string(node, "prefix") or ""},
                )

    def decorator_dependencies(self, node: ast.FunctionDef | ast.AsyncFunctionDef, source: str) -> None:
        for decorator in node.decorator_list:
            if not isinstance(decorator, ast.Call):
                continue
            dependencies = next((item.value for item in decorator.keywords if item.arg == "dependencies"), None)
            if isinstance(dependencies, (ast.List, ast.Tuple)):
                for dependency in dependencies.elts:
                    self.add_dependency(source, dependency)

    def parameter_dependencies(self, node: ast.FunctionDef | ast.AsyncFunctionDef, source: str) -> None:
        positional = [*node.args.posonlyargs, *node.args.args]
        defaults = [None] * (len(positional) - len(node.args.defaults)) + list(node.args.defaults)
        for default in [*defaults, *node.args.kw_defaults]:
            if default is not None:
                self.add_dependency(source, default)

    def add_dependency(self, source: str, expression: ast.AST) -> None:
        if not isinstance(expression, ast.Call) or decorator_short(dotted(expression.func) or "") != "Depends":
            return
        dependency_name = dotted(expression.args[0]) if expression.args else None
        target = self.resolve_name(dependency_name, "function") if dependency_name else None
        if target:
            self.facts.add_edge("depends_on", source, target, expression, {"framework": "fastapi"})


class DjangoFactEnricher:
    """Add Django settings and URL-pattern facts."""

    SETTINGS = frozenset({"INSTALLED_APPS", "MIDDLEWARE", "ROOT_URLCONF", "ASGI_APPLICATION", "WSGI_APPLICATION"})

    def __init__(
        self,
        facts: PythonFactAccumulator,
        module: str,
        module_id: str,
        aliases: dict[str, str],
        resolve_name: Callable[[str, str], str | None],
    ) -> None:
        self.facts = facts
        self.module = module
        self.module_id = module_id
        self.aliases = aliases
        self.resolve_name = resolve_name

    def enrich_assignment(self, variable: str, value_node: ast.AST, assignment: ast.Assign) -> None:
        if variable in self.SETTINGS:
            value = self.literal_value(value_node)
            setting_id = ref("setting", f"{self.module}.{variable}")
            self.facts.add_node(
                setting_id,
                "setting",
                f"{self.module}.{variable}",
                variable,
                assignment,
                {"framework": "django", "value": value, "dynamic": value is None},
            )
            self.facts.add_edge("configures", self.module_id, setting_id, assignment)
        if variable == "urlpatterns" and isinstance(value_node, (ast.List, ast.Tuple)):
            for item in value_node.elts:
                self.url_pattern(item)

    def url_pattern(self, expression: ast.AST) -> None:
        if not isinstance(expression, ast.Call):
            return
        called = dotted(expression.func)
        resolved = self.aliases.get(called or "", "")
        if not (resolved.endswith("django.urls.path") or resolved.endswith("django.urls.re_path")):
            return
        path = positional_string(expression, 0)
        if path is None:
            self.facts.add_diagnostic("PY_DYNAMIC_ROUTE_PATH", "Dynamic Django URL pattern was skipped.", expression)
            return
        target_expression = expression.args[1] if len(expression.args) > 1 else None
        target_name = dotted(target_expression) if target_expression else None
        if isinstance(target_expression, ast.Call) and isinstance(target_expression.func, ast.Attribute):
            target_name = dotted(target_expression.func.value)
        target = self.resolve_name(target_name, "function") if target_name else None
        canonical = f"ANY /{path.lstrip('/')} => {target_name or 'dynamic'}"
        route_id = ref("route", canonical)
        self.facts.add_node(
            route_id,
            "route",
            canonical,
            f"ANY /{path.lstrip('/')}",
            expression,
            {"framework": "django", "path": path, "name": keyword_string(expression, "name")},
        )
        if target:
            self.facts.add_edge("routes_to", route_id, target, expression)

    @staticmethod
    def literal_value(node: ast.AST) -> Any:
        try:
            value = ast.literal_eval(node)
        except (ValueError, TypeError):
            return None
        return value if isinstance(value, (str, int, float, bool, list, tuple, dict, type(None))) else None


class FlaskFactEnricher:
    """Add Flask route and blueprint facts.

    Flask's primary wiring is `@app.route("/path", methods=[...])` on a
    `Flask` or `Blueprint` instance, so unlike FastAPI it names one path per
    decorator but may carry several verbs. A dynamic path (`<id>`) is a plain
    string to the parser and would make a route node that no request ever
    matches, so it is diagnosed rather than guessed, mirroring
    `PY_DYNAMIC_ROUTE_PATH`.
    """

    def __init__(
        self,
        facts: PythonFactAccumulator,
        module: str,
        module_id: str,
        aliases: dict[str, str],
        resolve_name: Callable[[str, str], str | None],
    ) -> None:
        self.facts = facts
        self.module = module
        self.module_id = module_id
        self.aliases = aliases
        self.resolve_name = resolve_name
        self.framework_objects: dict[str, tuple[str, str]] = {}

    def register_assignment(self, variable: str, value: ast.AST | None) -> None:
        if not isinstance(value, ast.Call):
            return
        called = dotted(value.func)
        resolved = self.aliases.get(called or "", "")
        if resolved.endswith("flask.Flask") or resolved.endswith("flask.Blueprint"):
            prefix = keyword_string(value, "url_prefix") or ""
            self.framework_objects[variable] = ("flask", prefix)

    def route_decorators(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> list[tuple[str, str, ast.AST]]:
        result: list[tuple[str, str, ast.AST]] = []
        for decorator in node.decorator_list:
            if not isinstance(decorator, ast.Call) or not isinstance(decorator.func, ast.Attribute):
                continue
            if decorator.func.attr != "route":
                continue
            owner = dotted(decorator.func.value)
            if owner not in self.framework_objects:
                continue
            raw_path = positional_string(decorator, 0)
            if raw_path is None or any(marker in raw_path for marker in ("<", ">")):
                self.facts.add_diagnostic("PY_DYNAMIC_ROUTE_PATH", "Dynamic Flask route path was skipped.", decorator)
                continue
            prefix = self.framework_objects[owner][1]
            path = "/" + "/".join(part.strip("/") for part in (prefix, raw_path) if part.strip("/"))
            methods = self.methods_keyword(decorator)
            if methods is None:
                methods = ["GET"]  # Flask's default when `methods` is absent
            for method in methods:
                result.append((method.upper(), path or "/", decorator))
        return result

    @staticmethod
    def methods_keyword(call: ast.Call) -> list[str] | None:
        """The `methods` keyword as uppercase verb list, or None when absent."""

        value = next((item.value for item in call.keywords if item.arg == "methods"), None)
        if value is None:
            return None
        if not isinstance(value, (ast.List, ast.Tuple)):
            return None
        methods = []
        for item in value.elts:
            if isinstance(item, ast.Constant) and isinstance(item.value, str):
                methods.append(item.value.strip().upper())
        return methods

    def enrich_function(
        self,
        node: ast.FunctionDef | ast.AsyncFunctionDef,
        local_id: str,
        canonical: str,
        route_decorators: list[tuple[str, str, ast.AST]],
    ) -> None:
        for method, path, decorator in route_decorators:
            route_canonical = f"{method} {path} => {canonical}"
            route_id = ref("route", route_canonical)
            self.facts.add_node(
                route_id,
                "route",
                route_canonical,
                f"{method} {path}",
                decorator,
                {"framework": "flask", "methods": [method], "path": path},
            )
            self.facts.add_edge("routes_to", route_id, local_id, decorator)

    def enrich_call(self, node: ast.Call, name: str | None) -> None:
        if name and name.endswith(".register_blueprint") and node.args:
            blueprint = dotted(node.args[0])
            if blueprint:
                self.facts.add_edge(
                    "mounts",
                    self.module_id,
                    ref("router", f"{self.module}.{blueprint}"),
                    node,
                    {"prefix": keyword_string(node, "url_prefix") or ""},
                )
        if name and name.endswith(".add_url_rule"):
            raw_path = positional_string(node, 0)
            if raw_path is None or any(marker in raw_path for marker in ("<", ">")):
                self.facts.add_diagnostic("PY_DYNAMIC_ROUTE_PATH", "Dynamic Flask URL rule was skipped.", node)
                return
            view_value = next((item.value for item in node.keywords if item.arg == "view_func"), None)
            view = dotted(view_value) if view_value is not None else None
            # Flask's positional signature is (rule, endpoint=None,
            # view_func=None); the callable is the third argument when the
            # endpoint is supplied.
            if view is None and len(node.args) > 2:
                view = dotted(node.args[2])
            if view is None:
                return  # a lambda or expression handler names nothing to resolve
            target = self.resolve_name(view, "function")
            if target is None:
                return  # an unresolved handler would make a guessed route
            methods = self.methods_keyword(node)
            if methods is None:
                methods = ["GET"]
            if not methods:
                return  # an explicit empty `methods=[]` rules out every verb
            path = "/" + raw_path.lstrip("/") or "/"
            for method in methods:
                canonical = f"{method.upper()} {path} => {target.removeprefix('py:function:')}"
                route_id = ref("route", canonical)
                self.facts.add_node(
                    route_id,
                    "route",
                    canonical,
                    f"{method.upper()} {path}",
                    node,
                    {"framework": "flask", "methods": [method.upper()], "path": path},
                )
                self.facts.add_edge("routes_to", route_id, target, node)


class PythonAstFactCollector(ast.NodeVisitor):
    """Coordinate one AST traversal and delegate fact enrichment."""

    def __init__(
        self,
        relative: str,
        tree: ast.Module,
        index: ProjectModuleIndex,
        module_collision: bool = False,
        has_shebang: bool = False,
    ) -> None:
        self.relative = relative
        self.has_shebang = has_shebang
        self.index = index
        self.module = index.module_for(relative)
        self.is_package = PurePosixPath(relative).stem == "__init__"
        self.module_collision = module_collision
        self.tree = tree
        self.aliases: dict[str, str] = {}
        self.containers: list[tuple[str, str, str]] = []
        self.local_function_scopes: list[dict[str, str]] = []
        # What a receiver holds, so a call on it names the method that runs.
        # Attributes are keyed by the class that owns them; locals by the
        # function being walked. Both are inferences from local flow, so a
        # reassignment to anything untracked drops the entry rather than
        # letting a stale type attribute a call to the wrong class.
        self.attribute_types: dict[str, dict[str, str]] = {}
        self.local_variable_types: list[dict[str, str]] = []
        self.parameter_types: list[dict[str, str]] = []
        self.module_id = ref("module", self.module)
        self.facts = PythonFactAccumulator(relative)
        self.roles = PythonFrameworkRoleEnricher()
        self.fastapi = FastApiFactEnricher(self.facts, self.module, self.module_id, self.aliases, self.resolve_name)
        self.django = DjangoFactEnricher(self.facts, self.module, self.module_id, self.aliases, self.resolve_name)
        self.flask = FlaskFactEnricher(self.facts, self.module, self.module_id, self.aliases, self.resolve_name)

    def collect(self) -> dict[str, Any]:
        self.facts.add_node(
            self.module_id,
            "module",
            self.module,
            self.module,
            self.tree,
            {
                "stub": self.relative.endswith(".pyi"),
                "executable": self.has_shebang or names_main_guard(self.tree),
            },
        )
        if self.is_package:
            package = self.module
            self.facts.add_node(ref("package", package), "package", package, package.split(".")[-1], self.tree)
            self.facts.add_edge("contains", ref("package", package), self.module_id, self.tree)
        if self.module_collision:
            self.facts.add_diagnostic(
                "PY_MODULE_ID_COLLISION",
                f"Module id '{self.module}' is shared by a module file and a package; "
                "the package (__init__.py) owns it.",
                self.tree,
            )
        self.visit(self.tree)
        return self.facts.result()

    def current(self) -> str:
        return self.containers[-1][0] if self.containers else self.module_id

    def resolve_name(self, name: str, hint: str = "class") -> str | None:
        if "." not in name:
            for scope in reversed(self.local_function_scopes):
                if name in scope:
                    return scope[name]
        if name in self.aliases:
            return self.aliases[name]
        local = self.index.module_declarations(self.module).get(name)
        if local:
            return local
        if "." in name:
            first, rest = name.split(".", 1)
            base = self.aliases.get(first)
            if base and base.startswith("py:module:"):
                module = base.removeprefix("py:module:")
                return self.index.module_declarations(module).get(rest, ref(hint, f"{module}.{rest}"))
        return None

    def visit_Import(self, node: ast.Import) -> None:
        for alias in node.names:
            target = ref("module", alias.name)
            self.aliases[alias.asname or alias.name.split(".")[0]] = target
            self.facts.add_edge("imports", self.module_id, target, node, {"alias": alias.asname})

    def visit_ImportFrom(self, node: ast.ImportFrom) -> None:
        module = absolute_import(self.module, node.level, node.module, self.is_package)
        if not module:
            # A relative import that climbs past the top of the project: legal to
            # parse, unrunnable at import time, and nameable by nothing in the
            # graph. Report it rather than emitting `py:module:`, which is not a
            # symbol reference at all.
            self.facts.add_diagnostic(
                "PY_UNRESOLVED_RELATIVE_IMPORT",
                f"Relative import at level {node.level} has no parent package in module "
                f"'{self.module}'; the import edge was skipped.",
                node,
            )
            return
        self.facts.add_edge("imports", self.module_id, ref("module", module), node, {"relative_level": node.level})
        for alias in node.names:
            if alias.name == "*":
                continue
            target = self.index.module_declarations(module).get(
                alias.name, ref("external_symbol", f"{module}.{alias.name}")
            )
            self.aliases[alias.asname or alias.name] = target

    def visit_ClassDef(self, node: ast.ClassDef) -> None:
        canonical = f"{self.module}.{node.name}"
        local_id = ref("class", canonical)
        decorators = self.roles.decorators(node)
        roles = self.roles.class_roles(node, decorators)
        self.facts.add_node(
            local_id,
            "class",
            canonical,
            node.name,
            node,
            {"decorators": decorators, "python_framework_roles": roles},
        )
        self.facts.add_edge("contains", self.current(), local_id, node)
        for base in node.bases:
            name = dotted(base)
            target = self.resolve_name(name, "class") if name else None
            if target:
                self.facts.add_edge("extends", local_id, target, base)
        self.containers.append((local_id, canonical, "class"))
        self.generic_visit(node)
        self.containers.pop()

    def visit_FunctionDef(self, node: ast.FunctionDef) -> None:
        self.function(node, async_function=False)

    def visit_AsyncFunctionDef(self, node: ast.AsyncFunctionDef) -> None:
        self.function(node, async_function=True)

    def function(self, node: ast.FunctionDef | ast.AsyncFunctionDef, async_function: bool) -> None:
        if self.containers and self.containers[-1][2] == "class":
            parent_id, parent_canonical, _ = self.containers[-1]
            kind, canonical = "method", f"{parent_canonical}::{node.name}"
        elif self.containers:
            parent_id, parent_canonical, _ = self.containers[-1]
            kind, canonical = "function", f"{parent_canonical}.<locals>.{node.name}"
        else:
            parent_id, kind, canonical = self.current(), "function", f"{self.module}.{node.name}"
        local_id = ref(kind, canonical)
        decorators = self.roles.decorators(node)
        fastapi_routes = self.fastapi.route_decorators(node)
        flask_routes = self.flask.route_decorators(node)
        roles = self.roles.function_roles(decorators, bool(fastapi_routes), bool(flask_routes))
        self.facts.add_node(
            local_id,
            kind,
            canonical,
            node.name,
            node,
            {
                "async": async_function,
                "decorators": decorators,
                "python_framework_roles": roles,
            },
        )
        self.facts.add_edge("contains", parent_id, local_id, node)
        self.fastapi.enrich_function(node, local_id, canonical, fastapi_routes)
        self.flask.enrich_function(node, local_id, canonical, flask_routes)
        self.containers.append((local_id, canonical, kind))
        self.local_function_scopes.append(self.local_function_declarations(node, canonical))
        self.local_variable_types.append({})
        self.parameter_types.append(self.annotated_parameters(node))
        self.generic_visit(node)
        self.parameter_types.pop()
        self.local_variable_types.pop()
        self.local_function_scopes.pop()
        self.containers.pop()

    def annotated_parameters(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> dict[str, str]:
        """The class each annotated parameter declares it holds."""

        annotated: dict[str, str] = {}
        arguments = node.args
        for argument in [*arguments.posonlyargs, *arguments.args, *arguments.kwonlyargs]:
            if argument.annotation is None:
                continue
            held = self.held_class(None, argument.annotation)
            if held is not None:
                annotated[argument.arg] = held
        return annotated

    @staticmethod
    def local_function_declarations(
        node: ast.FunctionDef | ast.AsyncFunctionDef, parent_canonical: str
    ) -> dict[str, str]:
        declarations: dict[str, str] = {}
        pending: list[ast.AST] = list(reversed(node.body))
        while pending:
            child = pending.pop()
            if isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef)):
                canonical = f"{parent_canonical}.<locals>.{child.name}"
                declarations[child.name] = ref("function", canonical)
                continue
            if isinstance(child, (ast.ClassDef, ast.Lambda)):
                continue
            pending.extend(reversed(list(ast.iter_child_nodes(child))))
        return declarations

    def visit_Assign(self, node: ast.Assign) -> None:
        if len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            variable = node.targets[0].id
            self.fastapi.register_assignment(variable, node.value)
            self.django.enrich_assignment(variable, node.value, node)
            self.flask.register_assignment(variable, node.value)
            self.remember_local(variable, node.value)
        if len(node.targets) == 1:
            attribute = self.self_attribute(node.targets[0])
            if attribute is not None:
                self.remember_attribute(attribute, node.value)
        self.generic_visit(node)

    def visit_AnnAssign(self, node: ast.AnnAssign) -> None:
        attribute = self.self_attribute(node.target)
        if attribute is not None:
            # An annotation states the type outright, which beats inferring it.
            self.remember_attribute(attribute, node.value, node.annotation)
        elif isinstance(node.target, ast.Name):
            self.fastapi.register_assignment(node.target.id, node.value)
            self.flask.register_assignment(node.target.id, node.value)
            self.remember_local(node.target.id, node.value, node.annotation)
        self.generic_visit(node)

    @staticmethod
    def self_attribute(target: ast.AST) -> str | None:
        """The attribute name when a target is `self.<name>`, else None."""

        return (
            target.attr
            if isinstance(target, ast.Attribute) and isinstance(target.value, ast.Name) and target.value.id == "self"
            else None
        )

    def remember_attribute(self, attribute: str, value: ast.AST | None, annotation: ast.AST | None = None) -> None:
        """Record the class an attribute of the enclosing class holds."""

        class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
        if class_container is None:
            return
        held = self.held_class(value, annotation)
        owned = self.attribute_types.setdefault(class_container[1], {})
        if held is None:
            owned.pop(attribute, None)
        else:
            owned[attribute] = held

    def remember_local(self, variable: str, value: ast.AST | None, annotation: ast.AST | None = None) -> None:
        """Record the class a local variable holds, for the function being walked."""

        if not self.local_variable_types:
            return
        held = self.held_class(value, annotation)
        if held is None:
            self.local_variable_types[-1].pop(variable, None)
            # A parameter reassigned to something untracked no longer holds what
            # its annotation declared. Dropping only the local would leave the
            # annotation to answer for the name and attribute a later call to a
            # class it has lost.
            self.parameter_types[-1].pop(variable, None)
        else:
            self.local_variable_types[-1][variable] = held

    def held_class(self, value: ast.AST | None, annotation: ast.AST | None = None) -> str | None:
        """The class reference an assigned value or annotation names, if any."""

        for candidate in (annotation, value.func if isinstance(value, ast.Call) else None):
            name = dotted(candidate) if candidate is not None else None
            resolved = self.resolve_name(name, "class") if name else None
            if resolved and resolved.startswith("py:class:"):
                return resolved.removeprefix("py:class:")
        # A name passed straight through carries whatever it is tracked as, from
        # the same two stacks a receiver is resolved against and in the same
        # order, so `other = local` propagates as `other = parameter` does.
        if isinstance(value, ast.Name):
            if self.local_variable_types and value.id in self.local_variable_types[-1]:
                return self.local_variable_types[-1][value.id]
            if self.parameter_types:
                return self.parameter_types[-1].get(value.id)
        return None

    def receiver_member(self, receiver: str, member: str) -> str | None:
        """The method a call names when its receiver's class is known."""

        held = None
        if receiver.startswith("self.") and receiver.count(".") == 1:
            class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
            if class_container is not None:
                held = self.attribute_types.get(class_container[1], {}).get(receiver.split(".", 1)[1])
        elif "." not in receiver:
            held = self.local_variable_types[-1].get(receiver) if self.local_variable_types else None
            if held is None and self.parameter_types:
                held = self.parameter_types[-1].get(receiver)
        return ref("method", f"{held}::{member}") if held else None

    def visit_Call(self, node: ast.Call) -> None:
        name = dotted(node.func)
        target = None
        if name:
            member = name.rsplit(".", 1)
            if len(member) == 2:
                target = self.receiver_member(member[0], member[1])
            if target is None and name.startswith("self.") and name.count(".") == 1 and self.containers:
                # Only a direct `self.<name>()`. `self.a.b()` is a call on
                # whatever `a` holds, and reading it as a member of this class
                # invents a symbol named `a.b` that nothing declares.
                class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
                if class_container:
                    target = ref("method", f"{class_container[1]}::{name.split('.', 1)[1]}")
            target = target or self.resolve_name(name, "function")
        if target:
            self.facts.add_edge("calls", self.current(), target, node)
        self.fastapi.enrich_call(node, name)
        self.flask.enrich_call(node, name)
        self.generic_visit(node)


def scan(params: dict[str, Any], emit: Callable[[dict[str, Any]], None]) -> dict[str, Any]:
    """Parse a bounded file set and emit one owned contribution per input."""

    root = safe_root(params.get("root"))
    files = params.get("files")
    raw_limits = params.get("limits")
    limits: dict[str, Any] = raw_limits if isinstance(raw_limits, dict) else {}
    max_files = int(limits.get("max_files", 100_000))
    max_bytes = int(limits.get("max_file_bytes", 2_000_000))
    if not isinstance(files, list) or len(files) > max_files:
        raise ValueError("Python scan files must be a bounded list.")

    # A path this worker refuses, a file deleted between discovery and scan, or
    # one over the byte cap is reported per file rather than raised: aborting the
    # request would discard the facts every other file in the batch contributes,
    # so a single unscannable file produced no graph at all. A request that
    # cannot be interpreted — checked above — is still fatal, because that means
    # the caller is broken rather than the tree.
    resolved: list[tuple[Path, str]] = []
    rejected: list[tuple[str, str]] = []
    for value in files:
        # A malformed path stays fatal: it names no file, so there is nothing to
        # attribute a diagnostic to, and echoing it into a contribution would
        # emit an owner key the graph rejects anyway.
        requested = assert_scannable_path(value)
        try:
            resolved.append(safe_file(root, value, max_bytes))
        except (ValueError, OSError) as error:
            rejected.append((requested.as_posix(), str(error)))
    resolved.sort(key=lambda item: item[1])
    for skipped, message in sorted(rejected):
        emit(_unscannable_contribution(skipped, message))

    index = ProjectModuleIndex(root, max_bytes)
    for absolute, relative in resolved:
        _scan_one(absolute, relative, index, emit)
    return {
        "files_scanned": len(resolved) + len(rejected),
        "parser": "python.ast",
        "input_hashes": index.read_hashes,
    }


def _scan_one(absolute: Path, relative: str, index: ProjectModuleIndex, emit: Callable[[dict[str, Any]], None]) -> None:
    """Parse and collect a single file, emitting exactly one owned contribution.

    Isolated per file so peak memory stays bounded by the largest single file
    rather than the whole batch, and so a syntax error, an oversized recursion,
    or an unexpected fault degrades to a per-file diagnostic and never discards
    facts for the other inputs in the same request.
    """
    try:
        source = absolute.read_bytes()
    except OSError as error:
        # `safe_file` stats the path, and the file can still be deleted or made
        # unreadable before this read. Nothing about that is specific to the
        # batch, so it costs only its own file — the same treatment discovery
        # gives a file it could not resolve.
        emit(_unscannable_contribution(relative, str(error)))
        return
    # Of the exact bytes handed to ast.parse, which does its own decoding and
    # BOM handling, so the core can refuse facts parsed from a file that
    # changed after discovery hashed it.
    content_hash = hashlib.sha256(source).hexdigest()
    # The index may read this file too, before or after this read, for an
    # importer's sake; if the two reads disagree, the entry becomes None.
    index.record_read(relative, content_hash)
    try:
        tree = ast.parse(source, filename=relative, type_comments=True)
    except (SyntaxError, UnicodeDecodeError, ValueError) as error:
        emit(_diagnostic_contribution(relative, "PY_SYNTAX_ERROR", "error", error, line_of(error), content_hash))
        return
    except RecursionError as error:
        emit(_diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1, content_hash))
        return
    # The shebang lives in a comment the parser drops, so it has to be read off
    # the source. Reduce it to a flag and release the bytes here, so the loop's
    # memory bound stays the largest single tree.
    shebang = starts_with_shebang(source)
    del source
    try:
        index.adopt_parsed(absolute, relative, tree)
        collision = index.collides(absolute, PurePosixPath(relative).stem == "__init__")
        contribution = PythonAstFactCollector(relative, tree, index, collision, shebang).collect()
    except RecursionError as error:
        emit(_diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1, content_hash))
        return
    except Exception as error:
        emit(_diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1, content_hash))
        return
    finally:
        del tree  # drop the parsed tree before the next file to bound memory
    contribution["content_hash"] = content_hash
    emit(contribution)


def line_of(error: BaseException) -> int:
    return max(1, int(getattr(error, "lineno", 1) or 1))


def _diagnostic_contribution(
    relative: str, code: str, severity: str, error: BaseException, line: int, content_hash: str | None = None
) -> dict[str, Any]:
    contribution: dict[str, Any] = {
        "owner_key": f"knossos.python:file:{relative}",
        "nodes": [],
        "edges": [],
        "diagnostics": [
            {
                "severity": severity,
                "code": code,
                "message": str(error),
                "evidence": {"path": relative, "start_line": line, "end_line": line},
            }
        ],
    }
    if content_hash is not None:
        contribution["content_hash"] = content_hash
    return contribution


def _unscannable_contribution(relative: str, message: str) -> dict[str, Any]:
    """Build a contribution that carries nothing but the reason one file was skipped."""

    return {
        "owner_key": f"knossos.python:file:{relative}",
        "nodes": [],
        "edges": [],
        "diagnostics": [
            {
                "severity": "error",
                "code": "PY_UNSCANNABLE_FILE",
                "message": message,
                "evidence": {"path": relative, "start_line": 1, "end_line": 1},
            }
        ],
    }


def handle(request: dict[str, Any]) -> None:
    """Validate and dispatch one NDJSON JSON-RPC worker request."""

    method, request_id = request.get("method"), request.get("id")
    params = request.get("params", {})
    if not isinstance(method, str) or not isinstance(params, dict):
        raise ValueError("Method and object params are required.")
    if method == "cancel":
        return
    if method == "initialize":
        result = {
            "id": "knossos.python",
            "version": VERSION,
            "protocol_version": "1.0",
            "output_schema_version": "1.0",
            "languages": ["python"],
            "file_extensions": ["py", "pyi"],
            "capabilities": ["partial_ast", "content_hash", "input_hashes"],
        }
    elif method == "scan":
        result = scan(
            params,
            lambda contribution: write({"jsonrpc": "2.0", "method": "scan/contribution", "params": contribution}),
        )
    elif method == "shutdown":
        result = {"status": "bye"}
    else:
        raise ValueError(f"Unknown method: {method}")
    write({"jsonrpc": "2.0", "id": request_id, "result": result})
    if method == "shutdown":
        raise SystemExit(0)


def main() -> None:
    """Drive the NDJSON JSON-RPC loop over standard input."""

    for input_line in sys.stdin:
        request: dict[str, Any] | None = None
        try:
            request = json.loads(input_line)
            if not isinstance(request, dict):
                raise ValueError("Request must be a JSON object.")
            handle(request)
        except SystemExit:
            raise
        except Exception as error:
            write(
                {
                    "jsonrpc": "2.0",
                    "id": request.get("id") if isinstance(request, dict) else None,
                    "error": {"code": -32602, "message": str(error)},
                }
            )


if __name__ == "__main__":
    main()
