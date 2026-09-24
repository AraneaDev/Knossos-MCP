#!/usr/bin/env python3
"""Knossos Python scanner worker. Parses target files; never imports them."""

from __future__ import annotations

import ast
import hashlib
import json
import os
import re
import sys
from collections.abc import Callable, Iterator
from pathlib import Path, PurePosixPath
from typing import Any, BinaryIO, NamedTuple

VERSION = "0.5.1"
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
    # Kept in sync with the authoritative PHP IgnoreMatcher: generated build
    # output and mutation-testing sandboxes are not source.
    ".stryker-tmp",
    ".pnpm-store",
    ".yarn",
    "build",
    "dist",
    "site",
}
# Name prefixes for the namespace this tool owns, kept in sync with the PHP
# IgnoreMatcher. ".knossos" alone is in the set above; a CI job parks a checkout
# of the analyzer or its snapshot database beside the project under the same
# convention, and neither is a source root of the project being scanned.
EXCLUDED_PREFIXES = (".knossos-",)
# Dependency trees may be read for import resolution even though discovery
# does not scan them as project-owned source. Generated and tool-owned trees
# remain blocked at this boundary.
RESOLUTION_ALLOWED_EXCLUDED = {"node_modules", "vendor"}


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


class UnreadableInput(ValueError):
    """A requested file the filesystem would not let the worker read as that file.

    Gone, not a regular file, over the byte cap, or resolving outside the root.
    Kept apart from a plain ``ValueError``, which also covers a path this worker
    refuses by policy, because the two answer ``input_hashes`` differently: a
    policy refusal says nothing about the tree, while a failed read says the file
    is not what discovery hashed at that moment. It is reported as ``None``, so
    the core fails the scan for a discovered path rather than keeping a graph
    that silently lacks the file's facts.
    """


class RefusedAfterRead(ValueError):
    """A requested file this worker refuses because of what it read in it.

    An extensionless script whose shebang does not name Python was routed here
    because discovery's reading of those bytes named Python when it hashed them.
    A script swapped for another one and restored around the probe would
    otherwise lose its facts from a graph reported fresh, so the refusal carries
    evidence for ``input_hashes``: the hash of the whole file from one bounded
    read that reached the same verdict, which a stable tree matches, or ``None``
    when that read failed, was over the cap, or found Python after all.
    """

    def __init__(self, message: str, content_hash: str | None) -> None:
        super().__init__(message)
        self.content_hash = content_hash


def read_bounded(path: Path, max_bytes: int) -> bytes:
    """Read at most ``max_bytes + 1`` bytes, so a file over the cap is told apart without reading the rest.

    A size checked before the read can belong to a file replaced before it, and
    the replacement must not be read unbounded.
    """
    with open_regular(path) as handle:
        return handle.read(max_bytes + 1)


def open_regular(path: Path) -> BinaryIO:
    """Open ``path`` for binary reading, raising ``OSError`` unless it is a regular file.

    Opened without blocking and checked on the handle: a FIFO's open blocks
    until a writer appears, which would stall the worker until its request
    timeout, and a type checked before the open can belong to a path swapped
    after it. A directory is refused the same way. Every caller already maps
    ``OSError`` to a failed read.
    """
    descriptor = os.open(path, os.O_RDONLY | os.O_NONBLOCK)
    try:
        if os.fstat(descriptor).st_mode & S_IFMT != S_IFREG:
            raise OSError(f"Not a regular file: {path}")
        # Blocking again for the read itself; a regular file never waits.
        os.set_blocking(descriptor, True)
        return os.fdopen(descriptor, "rb")
    except BaseException:
        os.close(descriptor)
        raise


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
        with open_regular(absolute) as handle:
            first = handle.readline(SHEBANG_PROBE_BYTES)
    except OSError as error:
        # Only ever asked of a path just found to be a regular file, so a failed
        # open is the filesystem's answer, not this script's interpreter.
        raise UnreadableInput(str(error)) from error
    return _first_line_names_python(first)


def _first_line_names_python(first: bytes) -> bool:
    """Whether a shebang line, as the probe reads it, names Python."""
    text = first.decode("utf-8", "replace")
    return text.startswith("#!") and re.search(r"\b(python)[0-9.]*\b", text, re.IGNORECASE) is not None


def shebang_refusal_evidence(absolute: Path, max_bytes: int) -> str | None:
    """What a shebang refusal reports in ``input_hashes`` for the refused file.

    The probe read only a line, which no discovery hash can be compared with,
    so the whole file is read once more, bounded, and judged again on those
    same bytes. A file that still does not name Python is reported by that
    read's hash: a tree that is not changing matches what discovery hashed, so
    a script this rule and discovery's happen to judge apart costs only its
    diagnostic, while a script swapped for another one does not match. A read
    that fails, is over the cap, or now names Python saw a file that changed
    between the two reads, and is reported as ``None``.
    """
    try:
        source = read_bounded(absolute, max_bytes)
    except OSError:
        return None
    if len(source) > max_bytes:
        return None
    newline = source.find(b"\n", 0, SHEBANG_PROBE_BYTES)
    first = source[:SHEBANG_PROBE_BYTES] if newline < 0 else source[: newline + 1]
    if _first_line_names_python(first):
        return None
    return hashlib.sha256(source).hexdigest()


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
    """Resolve a requested path to the in-root regular file it names.

    Raises :class:`UnreadableInput` when the filesystem refuses it,
    :class:`RefusedAfterRead` when its shebang does not name Python, and a plain
    ``ValueError`` when this worker does not scan such a file by its name.
    """
    relative = assert_scannable_path(value)
    try:
        absolute = (root / Path(*relative.parts)).resolve(strict=True)
        try:
            absolute.relative_to(root)
        except ValueError as error:
            raise UnreadableInput("Python input path escapes the project root.") from error
        if not absolute.is_file():
            raise UnreadableInput("Python input is not a regular file.")
        if absolute.suffix.lower() not in {".py", ".pyi"}:
            if absolute.suffix:
                raise ValueError("Unsupported Python input.")
            if not names_python_in_shebang(absolute):
                raise RefusedAfterRead("Unsupported Python input.", shebang_refusal_evidence(absolute, max_bytes))
        if absolute.stat().st_size > max_bytes:
            raise UnreadableInput("Python input exceeds the configured byte limit.")
    except OSError as error:
        raise UnreadableInput(str(error)) from error
    return absolute, relative.as_posix()


# ``sys.stdlib_module_names`` exists from Python 3.10; an older interpreter
# resolves no script-directory imports rather than risk shadowing the stdlib.
STDLIB_MODULE_NAMES: frozenset[str] = frozenset(getattr(sys, "stdlib_module_names", ()))


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
        self.read_hashes: dict[str, str | None] = {}
        self._prefixes: list[tuple[str, ...]] | None = None
        self._cache: dict[str, dict[str, str]] = {}

    @property
    def prefixes(self) -> list[tuple[str, ...]]:
        """The source roots, detected on first use.

        Detecting them probes the tree, and those probes are recorded, so a
        request that resolves nothing, such as one whose every file was
        refused, reports no reads it did not need.
        """
        if self._prefixes is None:
            self._prefixes = self._source_root_prefixes()
        return self._prefixes

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
        """Record a read, hashed or failed, under every key its walk gives."""
        final, linked = walk_keys(self.root, walked)
        for relative in ([final] if final is not None else []) + linked:
            self.record_read(relative, content_hash)

    def _record_probe(self, walked: PathWalk, present: bool) -> None:
        """Record an existence probe whose answer feeds facts.

        A probe answering absent, or not a file, records ``None`` under every key
        its walk gives: resolution goes on as if the file were not there, so a
        discovered file missing for that moment must fail verification. A probe
        answering present read no bytes, so it vouches for nothing at the
        location it reached, which a read records; it records ``None`` only
        under the linked keys, so a discovered path that had become a link is
        still caught. Discovery never reports an absent path or a link, so a
        stable tree is unaffected.
        """
        final, linked = walk_keys(self.root, walked)
        if not present and final is not None:
            self.record_read(final, None)
        for relative in linked:
            self.record_read(relative, None)

    def _source_root_prefixes(self) -> list[tuple[str, ...]]:
        """The source roots: the bare root, and each top-level directory that is not a package.

        Whether ``child/__init__.py`` is a file decides every module id below
        ``child``, so that probe is recorded (:meth:`_record_probe`).
        """
        prefixes: list[tuple[str, ...]] = [()]
        try:
            for child in sorted(self.root.iterdir()):
                if is_excluded(child.name) or not child.is_dir():
                    continue
                marker = child / "__init__.py"
                present = marker.is_file()
                self._record_probe(walk_path(marker), present)
                if not present:
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
            # Last, the suffixless file itself. Discovery admits an extensionless
            # script on its shebang, so such a file is scanned and its symbols
            # are emitted, but a name derived from it round-trips only to
            # ``<name>.py`` — which does not exist. Its own declarations were
            # therefore never found, every name inside it went unresolved, and
            # so a script's ``main()`` calling its ``check()`` produced no edge
            # and both read as unreferenced. Gated on the same shebang rule
            # discovery used, so ``import config`` cannot bind to a shell script
            # named ``config``.
            if self._is_python_script(base):
                return base
        return None

    def script_sibling_module(self, importer: str, module: str) -> str | None:
        """The module an absolute import names in the importer's own directory.

        Running ``python3 app/main.py`` puts ``app/`` first on ``sys.path``, so
        the script's bare ``import monitor`` loads ``app/monitor.py`` whether or
        not ``app/`` is a package. Only a script gets this: a module something
        else imports has no directory of its own on the path. A name the
        standard library owns is left alone, so a sibling ``json.py`` never
        captures ``import json``.
        """
        parts = module.split(".")
        if not parts or "" in parts or parts[0] in STDLIB_MODULE_NAMES:
            return None
        directory = PurePosixPath(importer).parent
        if not directory.parts:
            return None  # the bare root is already a source root
        base = self.root.joinpath(*directory.parts, *parts)
        for candidate in (base / "__init__.py", base.with_suffix(".py")):
            if self._is_project_file(candidate):
                return self.module_for(candidate.relative_to(self.root).as_posix())
        return None

    def _is_python_script(self, path: Path) -> bool:
        """Whether a suffixless path is a project file whose shebang names Python."""
        if path.suffix or not self._is_project_file(path):
            return False
        try:
            return names_python_in_shebang(path)
        except UnreadableInput:
            return False

    def _is_project_file(self, path: Path) -> bool:
        """Whether ``path`` is a module file this index may read.

        A candidate that is absent, not a regular file (a FIFO, say), or
        refused (it links out of the root, it is over the byte cap, or it
        cannot be examined) is left out of resolution, so every importer's facts are computed as if it did not
        exist; it is recorded as a failed read under the keys a read of it
        would go under. A stable layout never trips over that: discovery
        reports no absent path, symlink or over-cap file, and the core accepts
        a ``None`` at commit for an undiscovered path that is absent, not a
        regular file, reached through a link, or over the cap, while a
        discovered file that became one mid-scan fails verification. An
        accepted candidate is recorded as a probe that found it present.
        """
        # Discovery does not enter excluded directories, but import resolution
        # also tries candidates derived from dotted names. Reject those lexical
        # paths before statting them, so an import such as ``site.foo`` cannot
        # pull generated output back into the scan through the bare root prefix.
        try:
            if any(
                is_excluded(segment) and segment not in RESOLUTION_ALLOWED_EXCLUDED
                for segment in path.relative_to(self.root).parts
            ):
                return False
        except ValueError:
            pass
        walked = walk_path(path)
        location = self._in_root_file(walked)
        try:
            status = None if location is None else location.stat()
            if status is not None and status.st_mode & S_IFMT == S_IFREG and status.st_size <= self.max_bytes:
                self._record_probe(walked, True)
                return True
        except OSError:
            pass
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
        if path is not None and walked is not None and location is None:
            # Accepted by module_file, then retargeted out of the root or gone
            # before this read resolved it: not read, and recorded as refused.
            self._record_walk(walked, None)
        if path is not None and walked is not None and location is not None:
            try:
                source = read_bounded(location, self.max_bytes)
            except OSError:
                self._record_walk(walked, None)
            else:
                if len(source) > self.max_bytes:
                    # Grew past the cap after _is_project_file checked it.
                    self._record_walk(walked, None)
                    self._cache[module] = declarations
                    return declarations
                # Hashed before parsing, so a module that fails to parse still
                # reports the bytes this request saw.
                self._record_walk(walked, hashlib.sha256(source).hexdigest())
                try:
                    tree = ast.parse(source)
                except (SyntaxError, ValueError, RecursionError):
                    tree = None
                if tree is not None:
                    declarations = top_level_declarations(tree, module)
                    self._cache[module] = declarations
                    self._add_reexports(tree, module, path.name == "__init__.py", declarations)
                    self._add_instances(tree, module, path.name == "__init__.py", declarations)
        self._cache[module] = declarations
        return declarations

    def _add_reexports(self, tree: ast.Module, module: str, is_package: bool, declarations: dict[str, str]) -> None:
        """Add the project declarations ``tree`` imports to its declarations.

        A name a module imports is an attribute of that module, which is how a
        package re-exports what its submodules declare: ``from app import
        config; config.staging_dir()`` reaches ``app.config.paths.staging_dir``
        through ``app/config/__init__.py``. A star import brings the source's
        public names. A module's own declaration of a name wins. The
        declarations are cached before this runs, so modules importing each
        other resolve without recursing.
        """
        for child in module_statements(tree):
            if not isinstance(child, ast.ImportFrom):
                continue
            source = absolute_import(module, child.level, child.module, is_package)
            if not source:
                continue
            exported = self.module_declarations(source)
            for alias in child.names:
                if alias.name == "*":
                    for name, target in exported.items():
                        if not name.startswith("_"):
                            declarations.setdefault(name, target)
                    continue
                reexported = exported.get(alias.name)
                if reexported is not None:
                    declarations.setdefault(alias.asname or alias.name, reexported)

    def _add_instances(self, tree: ast.Module, module: str, is_package: bool, declarations: dict[str, str]) -> None:
        """Add the module-level instances ``tree`` creates to its declarations.

        ``user_repo = UserRepository()`` at module level is how a service hands
        out one shared object, and the rest of the codebase imports that name.
        Recorded as ``py:instance:<class>`` so an importer can type a call on
        it. The class is found among the module's own classes or its
        ``from ... import`` names; anything else is left out. The declarations
        are cached before this runs, so two modules importing each other
        resolve without recursing.
        """
        imported: dict[str, str] = {}
        for child in module_statements(tree):
            if not isinstance(child, ast.ImportFrom):
                continue
            source = absolute_import(module, child.level, child.module, is_package)
            if not source:
                continue
            for alias in child.names:
                if alias.name == "*":
                    continue
                target = self.module_declarations(source).get(alias.name)
                if target is not None and target.startswith("py:class:"):
                    imported[alias.asname or alias.name] = target
        for child in module_statements(tree):
            name, constructor = None, None
            if isinstance(child, ast.Assign) and len(child.targets) == 1 and isinstance(child.targets[0], ast.Name):
                name, constructor = child.targets[0].id, child.value
            elif isinstance(child, ast.AnnAssign) and isinstance(child.target, ast.Name):
                name, constructor = child.target.id, child.value
            if name is None or name in declarations:
                continue
            class_name = (
                constructor.func.id
                if isinstance(constructor, ast.Call) and isinstance(constructor.func, ast.Name)
                else None
            )
            target = None if class_name is None else declarations.get(class_name) or imported.get(class_name)
            if target is not None and target.startswith("py:class:"):
                declarations[name] = "py:instance:" + target.removeprefix("py:class:")

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
        declarations = top_level_declarations(tree, module)
        self._cache[module] = declarations
        is_package = PurePosixPath(relative).stem == "__init__"
        self._add_reexports(tree, module, is_package, declarations)
        self._add_instances(tree, module, is_package, declarations)

    def collides(self, absolute: Path, is_package: bool) -> bool:
        """A ``mod.py``/``mod/__init__.py`` pair maps to the same module id.

        The answer decides the file's module identity, so the probe is recorded
        (:meth:`_record_probe`).
        """
        if is_package:
            competitor = absolute.parent.with_suffix(".py")
        else:
            competitor = absolute.with_suffix("") / "__init__.py"
        try:
            present = competitor.is_file()
        except OSError:
            present = False
        self._record_probe(walk_path(competitor), present)
        return present


# Linux's MAXSYMLINKS: one lookup follows at most this many links, and the next
# one fails it with ELOOP.
MAX_SYMLINK_HOPS = 40
# The file-type bits of ``st_mode``, spelled out rather than imported from
# ``stat``: four constants do not earn a module dependency, and this file's
# import count is a budgeted maintainability metric.
S_IFMT = 0o170000
S_IFDIR = 0o040000
S_IFLNK = 0o120000
S_IFREG = 0o100000


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
    current = _anchor(text)
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
                current = _anchor(target)
                target = target[len(current) :]
            remaining[:0] = target.split(os.sep)
        elif mode == S_IFDIR:
            current = candidate
        elif remaining:
            return _absent_below(candidate, remaining, links)
        else:
            return PathWalk("file", candidate, tuple(links))
    return PathWalk("directory", current, tuple(links))


def _anchor(text: str) -> str:
    """The filesystem root a path starts from.

    POSIX lets an implementation give exactly two leading slashes a meaning of
    its own, so ``Path("//tmp").anchor`` is ``//``; Linux treats any run of
    leading slashes as ``/``, and so must the walk, or every location below it
    fails the textual containment check.
    """
    anchor = Path(text).anchor
    return os.sep if os.name == "posix" and anchor else anchor


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

    - A walk that ended at a file, a missing location or a directory inside
      the root: that location, the path the read opened or would have opened.
      A directory read fails (EISDIR) and is recorded as ``None``, which the
      core accepts at commit for a path that is not a regular file, while a
      discovered file replaced by one mid-scan fails verification.
    - Otherwise the last link followed inside the root. A link followed as a
      directory component keys the path below it as it was about to be walked
      (a discovered ``sub/c.py`` whose ``sub`` became a link out of the root
      keys ``sub/c.py``), unless that path holds a ``..``, which only the walk
      could have applied; then the link itself.

    ``None`` when no location the walk passed lies inside the root. Every key a
    stable layout produces names either the file the kernel reaches, or a link
    or a path through one or through a missing component, none of which
    discovery reports. The core verifies such a key when the scan commits: a
    hash must still match the in-root regular file within the cap the path
    names, and a ``None`` is valid only while the path is absent, not a regular
    file, reached through a link, or over the cap. A discovered file changed
    into one of those mid-scan is keyed where discovery saw it and fails
    verification against discovery's hash. ``root`` must be a real path, as
    :func:`safe_root` makes it.
    """
    location = walked.location
    if location is None or not _inside(root, location):
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


def walk_keys(root: Path, walked: PathWalk) -> tuple[str | None, list[str]]:
    """Every key a walk goes under in ``input_hashes``.

    The first is :func:`walk_key`, the location the walk reached. The rest are
    the other in-root keys the walk passed through a link: each link followed,
    and each link with the components still to walk below it. The first of
    those is the path as written, since every path the index walks is joined
    from the real root without a ``..``. A ``..`` among the components below a
    later link could only be applied by the walk, so such a path is left out,
    as is anything outside the root. A path below ``node_modules`` is keyed like
    any other.

    Discovery never reports a link and never descends into a linked directory,
    so on a stable tree every linked key names a path discovery did not hash,
    which the core verifies when the scan commits: a ``None`` there is valid
    while the path is a link or passes through one.
    Mid-scan, a discovered file swapped for a link (to another file, or to a
    directory) is keyed where discovery saw it, so the read or probe that went
    through it is checked against discovery's hash.
    """
    final = walk_key(root, walked)
    linked: list[str] = []

    def add(location: str) -> None:
        if not _inside(root, location) or location == os.fspath(root):
            return
        relative = PurePosixPath(os.path.relpath(location, root)).as_posix()
        if relative != final and relative not in linked:
            linked.append(relative)

    for location, remaining in walked.links:
        add(location)
        rest = [name for name in remaining if name not in ("", os.curdir)]
        if rest and os.pardir not in rest:
            add(os.path.join(location, *rest))
    return final, linked


def module_statements(tree: ast.Module) -> Iterator[ast.stmt]:
    """The statements run at module level, including those under a guard.

    A class declared under ``if TYPE_CHECKING:`` and an import inside
    ``try: ... except ImportError:`` still bind module-level names.
    """
    pending: list[ast.stmt] = list(reversed(tree.body))
    while pending:
        child = pending.pop()
        yield child
        nested: list[ast.stmt] = []
        if isinstance(child, ast.If):
            nested = [*child.body, *child.orelse]
        elif isinstance(child, (ast.Try, ast.TryStar)):
            handled = [item for handler in child.handlers for item in handler.body]
            nested = [*child.body, *handled, *child.orelse, *child.finalbody]
        pending.extend(reversed(nested))


def top_level_declarations(tree: ast.Module, module: str) -> dict[str, str]:
    """Map each top-level class and function name in ``tree`` to its symbol reference."""

    declarations: dict[str, str] = {}
    for child in module_statements(tree):
        if isinstance(child, ast.ClassDef):
            declarations[child.name] = ref("class", f"{module}.{child.name}")
        elif isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef)):
            declarations[child.name] = ref("function", f"{module}.{child.name}")
    return declarations


def bound_names(node: ast.FunctionDef | ast.AsyncFunctionDef) -> frozenset[str]:
    """The names a function binds: its parameters, and every name it assigns."""
    arguments = node.args
    names = {argument.arg for argument in [*arguments.posonlyargs, *arguments.args, *arguments.kwonlyargs]}
    for extra in (arguments.vararg, arguments.kwarg):
        if extra is not None:
            names.add(extra.arg)
    for child in ast.walk(node):
        if isinstance(child, ast.Name) and isinstance(child.ctx, ast.Store):
            names.add(child.id)
    return frozenset(names)


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

    def register_parameters(
        self, node: ast.FunctionDef | ast.AsyncFunctionDef
    ) -> list[tuple[str, tuple[str, str] | None]]:
        """Register parameters annotated as a FastAPI app or router, for this function's body.

        The register-function pattern hands the app in rather than creating it::

            def register_docs_and_health(app: FastAPI) -> None:
                @app.get("/api/health")
                def health_check(): ...

        `app` is never assigned a call, so gating on assignment alone left every
        route declared this way undiscovered: no route node, no handler role,
        and no inbound edge, so each handler read as unreferenced dead code.

        Returns what to restore, so a parameter cannot shadow a module-level
        object of the same name beyond the function that declared it.
        """
        arguments = node.args
        restore: list[tuple[str, tuple[str, str] | None]] = []
        for argument in [*arguments.posonlyargs, *arguments.args, *arguments.kwonlyargs]:
            named = None if argument.annotation is None else dotted(argument.annotation)
            # Through resolve_name rather than a bare alias lookup: `import
            # fastapi` binds the module, so an exact lookup of
            # "fastapi.FastAPI" finds nothing and every route in the function
            # is lost. resolve_name walks the module alias to the symbol.
            resolved = (named and self.resolve_name(named, "class")) or ""
            if not resolved.endswith(("fastapi.FastAPI", "fastapi.APIRouter")):
                continue
            restore.append((argument.arg, self.framework_objects.get(argument.arg)))
            # No prefix: a router built elsewhere carries its own, and this
            # function cannot see it.
            self.framework_objects[argument.arg] = ("fastapi", "")
        return restore

    def restore_parameters(self, restore: list[tuple[str, tuple[str, str] | None]]) -> None:
        for variable, previous in reversed(restore):
            if previous is None:
                self.framework_objects.pop(variable, None)
            else:
                self.framework_objects[variable] = previous

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
        self.executable = has_shebang or names_main_guard(tree)
        # `029_seed.py` names no module an import statement can reach, so a
        # loader reads it by path and calls the public names it exposes.
        self.loaded_by_path = not PurePosixPath(relative).stem.isidentifier()
        self.index = index
        self.module = index.module_for(relative)
        self.is_package = PurePosixPath(relative).stem == "__init__"
        self.module_collision = module_collision
        self.tree = tree
        self.aliases: dict[str, str] = {}
        self.containers: list[tuple[str, str, str]] = []
        self.local_function_scopes: list[dict[str, str]] = []
        # Methods each class declares in its own body, keyed by the class's
        # canonical name, so `self.<name>` read as a value can be told apart
        # from a data attribute before the method's own definition is visited.
        self.class_methods: dict[str, frozenset[str]] = {}
        # `self.<name>` nodes that are the callee of a call: those are `calls`.
        self.called_attributes: set[int] = set()
        # What a receiver holds, so a call on it names the method that runs.
        # Attributes are keyed by the class that owns them; locals by the
        # function being walked. Both are inferences from local flow, so a
        # reassignment to anything untracked drops the entry rather than
        # letting a stale type attribute a call to the wrong class.
        self.attribute_types: dict[str, dict[str, str]] = {}
        self.local_variable_types: list[dict[str, str]] = []
        self.parameter_types: list[dict[str, str]] = []
        # The names each function being walked binds itself (parameters and
        # assignments), which shadow a module-level instance of the same name.
        self.bound_names: list[frozenset[str]] = []
        # Member names called on a receiver no type was inferred for.
        self.untyped_calls: set[str] = set()
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
                "executable": self.executable,
                # Run by the import system whenever a module inside the package is imported.
                "package_init": self.is_package,
            }
            | ({"runtime_invoked": True} if self.loaded_by_path else {}),
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
        if self.untyped_calls:
            # A method by one of these names may be what such a call reaches,
            # so the core reports it as only possibly dead.
            self.facts.nodes[self.module_id]["attributes"]["unresolved_member_calls"] = sorted(self.untyped_calls)
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

    def script_import(self, module: str) -> str:
        """An absolute import, resolved against a script's own directory when no source root has it."""
        if not self.executable or self.index.module_file(module) is not None:
            return module
        return self.index.script_sibling_module(self.relative, module) or module

    def visit_Import(self, node: ast.Import) -> None:
        for alias in node.names:
            target = ref("module", self.script_import(alias.name))
            self.aliases[alias.asname or alias.name.split(".")[0]] = target
            self.facts.add_edge("imports", self.module_id, target, node, {"alias": alias.asname})

    def visit_ImportFrom(self, node: ast.ImportFrom) -> None:
        module = absolute_import(self.module, node.level, node.module, self.is_package)
        if node.level == 0 and module:
            module = self.script_import(module)
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
            target = self.index.module_declarations(module).get(alias.name)
            if target is None and self.index.module_file(f"{module}.{alias.name}") is not None:
                # `from .tools import cors` names the submodule `tools/cors.py`
                # when the package declares no `cors` of its own.
                submodule = f"{module}.{alias.name}"
                target = ref("module", submodule)
                self.facts.add_edge("imports", self.module_id, target, node, {"relative_level": node.level})
            self.aliases[alias.asname or alias.name] = target or ref("external_symbol", f"{module}.{alias.name}")

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
            # The `extends` edge below is what a base is; not also a reference.
            self.called_attributes.add(id(base))
            name = dotted(base)
            target = self.resolve_name(name, "class") if name else None
            if target:
                self.facts.add_edge("extends", local_id, target, base)
        self.class_methods[canonical] = frozenset(
            item.name for item in node.body if isinstance(item, (ast.FunctionDef, ast.AsyncFunctionDef))
        )
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
        attributes: dict[str, Any] = {
            "async": async_function,
            "decorators": decorators,
            "python_framework_roles": roles,
        }
        if self.registered_by_object(decorators) or (
            self.loaded_by_path and not self.containers and not node.name.startswith("_")
        ):
            attributes["runtime_invoked"] = True
        self.facts.add_node(local_id, kind, canonical, node.name, node, attributes)
        self.facts.add_edge("contains", parent_id, local_id, node)
        self.fastapi.enrich_function(node, local_id, canonical, fastapi_routes)
        self.flask.enrich_function(node, local_id, canonical, flask_routes)
        self.containers.append((local_id, canonical, kind))
        self.local_function_scopes.append(self.local_function_declarations(node, canonical))
        self.local_variable_types.append({})
        self.parameter_types.append(self.annotated_parameters(node))
        self.bound_names.append(bound_names(node))
        restore_fastapi = self.fastapi.register_parameters(node)
        self.generic_visit(node)
        self.fastapi.restore_parameters(restore_fastapi)
        self.parameter_types.pop()
        self.bound_names.pop()
        self.local_variable_types.pop()
        self.local_function_scopes.pop()
        self.containers.pop()

    def registered_by_object(self, decorators: list[str]) -> bool:
        """Whether a decorator hands the function to an object that calls it.

        ``@tq.register("scan")`` and ``@bus.on`` register the function with a
        queue or a bus, which calls it at runtime, so no call names it. A
        decorator reached through an imported module, ``@functools.cache``,
        wraps the function without registering it anywhere.
        """
        for name in decorators:
            head, _, member = name.partition(".")
            if member and not (self.aliases.get(head) or "").startswith("py:module:"):
                return True
        return False

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

    def emit_value_references(self, value: ast.AST | None) -> None:
        """Record a declaration named as a value rather than called.

        A dispatch table is the common shape::

            DERIVATIONS = {"adr_files": (derive_adr_files, "ADR files")}

        Nothing calls `derive_adr_files` anywhere, so with only `calls` edges
        it, and every function reached the same way, read as unreferenced dead
        code. This worker emitted no reference edges at all, which made the
        whole registry-reached class invisible.

        Deliberately narrow: an assignment's right-hand side, descending only
        through literal containers. Walking into calls, comprehensions or
        lambdas would turn every mention of a symbol into an edge and inflate
        the in-degree the hub ranking is built on, which is a different claim
        from "something holds a handle to this".
        """
        pending: list[ast.AST] = [] if value is None else [value]
        while pending:
            item = pending.pop()
            if isinstance(item, (ast.List, ast.Tuple, ast.Set)):
                pending.extend(item.elts)
                continue
            if isinstance(item, ast.Dict):
                pending.extend(key for key in item.keys if key is not None)
                pending.extend(item.values)
                continue
            if not isinstance(item, (ast.Name, ast.Attribute)):
                continue
            name = dotted(item)
            target = self.resolve_name(name, "function") if name else None
            if target is not None and target != self.current():
                self.facts.add_edge("references", self.current(), target, item)

    def visit_Assign(self, node: ast.Assign) -> None:
        self.emit_value_references(node.value)
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
        self.emit_value_references(node.value)
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

    def is_local_name(self, name: str) -> bool:
        """Whether the function being walked binds ``name`` itself."""
        return bool(self.bound_names) and name in self.bound_names[-1]

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
            if held is None and not self.is_local_name(receiver):
                # A module-level instance, this module's own or imported.
                instance = self.aliases.get(receiver) or self.index.module_declarations(self.module).get(receiver)
                if instance is not None and instance.startswith("py:instance:"):
                    held = instance.removeprefix("py:instance:")
        return ref("method", f"{held}::{member}") if held else None

    def visit_Name(self, node: ast.Name) -> None:
        """A function or class named as a value: returned, registered, passed.

        `def make_tool(): def handler(): ...; return handler` and
        `REGISTRY = [ping]` never call what they name, so without this every
        handler built or listed that way read as dead. Only a name that
        resolves to a function or class counts: a local closure first, then an
        import, then the module's own top-level declarations. A call's callee
        is the `calls` edge's, and a class base the `extends` edge's.
        """
        if isinstance(node.ctx, ast.Load) and id(node) not in self.called_attributes:
            target = next(
                (scope[node.id] for scope in reversed(self.local_function_scopes) if node.id in scope),
                None,
            )
            if target is None:
                target = self.aliases.get(node.id) or self.index.module_declarations(self.module).get(node.id)
            if target is not None and target.startswith(("py:function:", "py:class:")) and target != self.current():
                self.facts.add_edge("references", self.current(), target, node)
        self.generic_visit(node)

    def visit_Attribute(self, node: ast.Attribute) -> None:
        """A method of this class read as a value: a callback, a slot, a property.

        Only ``self.<name>`` where the enclosing class declares ``<name>``: a
        data attribute is no declaration, and an inherited name belongs to a
        class this file may not know.
        """
        if (
            isinstance(node.ctx, ast.Load)
            and id(node) not in self.called_attributes
            and isinstance(node.value, ast.Name)
            and node.value.id == "self"
        ):
            class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
            if class_container is not None and node.attr in self.class_methods.get(class_container[1], ()):
                target = ref("method", f"{class_container[1]}::{node.attr}")
                if target != self.current():
                    self.facts.add_edge("references", self.current(), target, node)
        elif isinstance(node.ctx, ast.Load) and id(node) not in self.called_attributes:
            # `client.prune_cache` read off a receiver whose class is known: a
            # bound method handed on, or a data attribute. Only the class can
            # tell, so the reconciler keeps the edge when the member resolves.
            receiver = dotted(node.value)
            member = self.receiver_member(receiver, node.attr) if receiver else None
            if member is not None:
                self.facts.add_edge("references", self.current(), member, node, {"speculative": True})
        self.generic_visit(node)

    def visit_Call(self, node: ast.Call) -> None:
        self.called_attributes.add(id(node.func))
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
            if target is None and len(member) == 2:
                self.untyped_calls.add(member[1])
        elif isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Call):
            # `Repo().count()`: the class just instantiated types the receiver.
            # Any other call's result has no type this worker follows.
            held = self.held_class(node.func.value)
            if held is None:
                self.untyped_calls.add(node.func.attr)
            else:
                target = ref("method", f"{held}::{node.func.attr}")
        # Calling an instance (`repo()`) names no declaration of its own.
        if target and not target.startswith("py:instance:"):
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
    index = ProjectModuleIndex(root, max_bytes)
    resolved: list[tuple[Path, str]] = []
    rejected: list[tuple[str, str]] = []
    for value in files:
        # A malformed path stays fatal: it names no file, so there is nothing to
        # attribute a diagnostic to, and echoing it into a contribution would
        # emit an owner key the graph rejects anyway.
        requested = assert_scannable_path(value)
        try:
            resolved.append(safe_file(root, value, max_bytes))
        except UnreadableInput as error:
            # Its contribution carries no facts, so a discovered file must not
            # pass verification as if it had been read.
            index.record_read(requested.as_posix(), None)
            rejected.append((requested.as_posix(), str(error)))
        except RefusedAfterRead as error:
            # Refused on what the file says rather than on its name, so what
            # was read is evidence: a stable tree matches the hash, a script
            # swapped and restored around the probe does not.
            index.record_read(requested.as_posix(), error.content_hash)
            rejected.append((requested.as_posix(), str(error)))
        except ValueError as error:
            rejected.append((requested.as_posix(), str(error)))
    resolved.sort(key=lambda item: item[1])
    for skipped, message in sorted(rejected):
        emit(_unscannable_contribution(skipped, message))

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
        source = read_bounded(absolute, index.max_bytes)
    except OSError as error:
        # `safe_file` stats the path, and the file can still be deleted or made
        # unreadable before this read. Nothing about that is specific to the
        # batch, so it costs only its own file — the same treatment discovery
        # gives a file it could not resolve. Recorded as a failed read, since the
        # contribution standing in for the file carries none of its facts.
        index.record_read(relative, None)
        emit(_unscannable_contribution(relative, str(error)))
        return
    if len(source) > index.max_bytes:
        index.record_read(relative, None)
        emit(_unscannable_contribution(relative, "Python input exceeds the configured byte limit."))
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


INPUT_HASHES_PART_BYTES = 256_000
"""Serialized bytes one ``scan/input_hashes`` notification carries at most.

Well under the core's 1,000,000-byte line cap. A single entry longer than this
still travels alone, and the core's path rules bound an entry, so no frame this
produces approaches the cap.
"""


def input_hash_parts(input_hashes: dict[str, str | None], part_bytes: int | None = None) -> list[dict[str, str | None]]:
    """Split a request's ``input_hashes`` map into parts that each fit one frame.

    The map covers every module the request's index read, which on a large tree
    outgrows the one line a scan result travels on however few files the batch
    names. All parts but the last go out as ``scan/input_hashes`` notifications;
    the last is the result's own field, which marks that the worker finished
    reporting, so there is always at least one part, ``{}`` when nothing was read.
    """

    budget = INPUT_HASHES_PART_BYTES if part_bytes is None else part_bytes
    parts: list[dict[str, str | None]] = []
    part: dict[str, str | None] = {}
    # The serialized part: its braces, less the comma its last entry lacks.
    size = 1
    for relative, content_hash in input_hashes.items():
        # `"path":"<64 hex>",` or `"path":null,`
        entry = len(json.dumps(relative, ensure_ascii=False).encode()) + (4 if content_hash is None else 66) + 2
        if part and size + entry > budget:
            parts.append(part)
            part = {}
            size = 1
        part[relative] = content_hash
        size += entry
    parts.append(part)
    return parts


def handle(request: dict[str, Any]) -> None:
    """Validate and dispatch one NDJSON JSON-RPC worker request."""

    method, request_id = request.get("method"), request.get("id")
    params = request.get("params", {})
    if not isinstance(method, str) or not isinstance(params, dict):
        raise ValueError("Method and object params are required.")
    if method == "cancel":
        return
    result: dict[str, Any]
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
        scanned = scan(
            params,
            lambda contribution: write({"jsonrpc": "2.0", "method": "scan/contribution", "params": contribution}),
        )
        parts = input_hash_parts(scanned["input_hashes"])
        for part in parts[:-1]:
            write({"jsonrpc": "2.0", "method": "scan/input_hashes", "params": {"input_hashes": part}})
        result = {**scanned, "input_hashes": parts[-1]}
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
