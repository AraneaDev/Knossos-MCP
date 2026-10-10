"""Module naming and the filesystem-backed, batch-independent module index."""

import ast
import hashlib
import sys
import tomllib
from pathlib import Path, PurePosixPath
from typing import Any

from .ast_helpers import (
    absolute_import,
    assigned_declaration,
    declared_protocols,
    dotted,
    module_statements,
    ref,
    router_constructors,
    top_level_declarations,
)
from .exclusions import RESOLUTION_ALLOWED_EXCLUDED, Exclusions
from .safe_io import (
    S_IFMT,
    S_IFREG,
    PathWalk,
    UnreadableInput,
    _inside,
    names_python_in_shebang,
    read_bounded,
    walk_keys,
    walk_path,
)

# ``sys.stdlib_module_names`` exists from Python 3.10; an older interpreter
# resolves no script-directory imports rather than risk shadowing the stdlib.
STDLIB_MODULE_NAMES: frozenset[str] = frozenset(getattr(sys, "stdlib_module_names", ()))
# Opens the path that qualifies the id of a file another file's id shadows:
# ``utils.<src/utils.py>``. No dotted import name contains it.
QUALIFIED = ".<"


def module_name(relative: str, strip: int = 0) -> str:
    path = PurePosixPath(relative)
    parts = list(path.with_suffix("").parts)[strip:]
    if parts and parts[-1] == "__init__":
        parts.pop()
    return ".".join(parts) or "__root__"


def _table(data: Any, key: str) -> dict[str, Any]:
    """The table ``data[key]``, or an empty one when either is not a table."""
    value = data.get(key) if isinstance(data, dict) else None
    return value if isinstance(value, dict) else {}


def _list(data: dict[str, Any], key: str) -> list[Any]:
    """The array ``data[key]``, or an empty one when it is not an array."""
    value = data.get(key)
    return value if isinstance(value, list) else []


def _project_directory(value: Any) -> tuple[str, ...] | None:
    """A pyproject's project-relative directory as path segments; ``None`` for the root itself or an unsafe path."""
    # Discovery cannot name a path with a control character, so a probe below
    # one would be a read of no known file.
    if not isinstance(value, str) or "\\" in value or any(ord(c) < 32 or ord(c) == 127 for c in value):
        return None
    path = PurePosixPath(value)
    parts = tuple(part for part in path.parts if part != ".")
    if path.is_absolute() or ".." in parts or not parts:
        return None
    return parts


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

    def __init__(
        self,
        root: Path,
        max_bytes: int,
        exclusions: Exclusions | None = None,
        source_files: frozenset[str] | None = None,
    ) -> None:
        self.root = root
        self.max_bytes = max_bytes
        self.exclusions = exclusions or Exclusions()
        self.read_hashes: dict[str, str | None] = {}
        # Every discovered Python file, when the request lists them: a module
        # among them has a contribution of its own that names what it read.
        self.source_files = source_files
        # Reads the request shares: the probes that decide the source roots.
        self.shared_reads: set[str] = set()
        # Reads made for a discovered module, which its own contribution names.
        self.unattributed_reads: set[str] = set()
        # The keys each open file or module computation has read, innermost last.
        self._scopes: list[set[str]] = []
        # What a module's declarations cost an importer that uses them.
        self._exposed: dict[str, set[str]] = {}
        # Whether a probe that finds a module file hashes it (:meth:`_record_probe`).
        self._hash_found = True
        self._prefixes: list[tuple[str, ...]] | None = None
        self._cache: dict[str, dict[str, str]] = {}
        # The import that found each path-qualified module, which is how its declarations are read.
        self._spellings: dict[str, str] = {}
        self._protocols: set[str] = set()

    @property
    def prefixes(self) -> list[tuple[str, ...]]:
        """The source roots, detected on first use.

        Detecting them probes the tree, and those probes are recorded, so a
        request that resolves nothing, such as one whose every file was
        refused, reports no reads it did not need. They decide every file's
        module id and every import, so they are the request's shared reads,
        whichever file happened to ask first.
        """
        if self._prefixes is None:
            scopes, self._scopes = self._scopes, []
            try:
                self._prefixes = self._source_root_prefixes()
            finally:
                self._scopes = scopes
        return self._prefixes

    def environment(self) -> str:
        """The digest of the source roots, one per line in search order, the bare root as an empty line.

        A new top-level directory is a source root no import could have
        probed, so the core compares this with the digest a reused
        contribution was derived under instead.
        """
        listed = "\n".join("/".join(prefix) for prefix in self.prefixes)
        return hashlib.sha256(listed.encode("utf-8")).hexdigest()

    def record_read(self, relative: str, content_hash: str | None, attributed: bool = True) -> None:
        """Record one read for ``input_hashes``; a disagreeing repeat read records ``None``.

        An attributed read is also named by whatever is being resolved: a
        requested file's read of its own bytes is not, since its
        contribution's ``content_hash`` covers it.
        """
        if relative in self.read_hashes and self.read_hashes[relative] != content_hash:
            content_hash = None
        self.read_hashes[relative] = content_hash
        if attributed:
            self._note({relative})

    def _note(self, keys: set[str]) -> None:
        """Name ``keys`` as read by the innermost open scope, or by the whole request outside any."""
        (self._scopes[-1] if self._scopes else self.shared_reads).update(keys)

    def open_scope(self) -> None:
        """Start collecting the keys one file or module computation reads."""
        self._scopes.append(set())

    def close_scope(self) -> set[str]:
        """Stop collecting for the innermost scope and return what it read."""
        return self._scopes.pop()

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

    def _record_probe(self, walked: PathWalk, present: bool, hashed: bool = True) -> None:
        """Record an existence probe whose answer feeds facts.

        A probe answering absent, or not a file, records ``None`` under every key
        its walk gives: resolution goes on as if the file were not there, so a
        discovered file missing for that moment must fail verification. A probe
        answering present records ``None`` under the linked keys, so a
        discovered path that had become a link is still caught, and the hash
        of the in-root file it found under the location it reached: the file
        being there is a fact, and only a read's key lets its deletion reach
        the reader. ``hashed`` false leaves that location out, for a probe
        that only its absence makes matter. Discovery never reports an absent
        path or a link, so a stable tree is unaffected.
        """
        final, linked = walk_keys(self.root, walked)
        if not present and final is not None:
            self.record_read(final, None)
        for relative in linked:
            self.record_read(relative, None)
        location = self._in_root_file(walked) if present and hashed else None
        if location is None or final is None:
            return
        if final in self.read_hashes:
            self._note({final})
            return
        try:
            source = read_bounded(location, self.max_bytes)
            content_hash = hashlib.sha256(source).hexdigest() if len(source) <= self.max_bytes else None
        except OSError:
            content_hash = None
        self.record_read(final, content_hash)

    def _source_root_prefixes(self) -> list[tuple[str, ...]]:
        """The source roots: the bare root first, then ``src/`` and the pyproject's package directories, sorted.

        ``src/`` is a source root when it is a directory and not a package
        (the src layout). Whether ``src/__init__.py`` is a file decides every
        module id below it, so that probe is recorded (:meth:`_record_probe`).
        Any other top-level directory is an ordinary directory, a package or a
        namespace package below the bare root, so ``app/models/user.py`` is
        ``app.models.user``, the name an import of it spells. The pyproject's
        bytes are not recorded: the core's configuration hash covers every
        ``pyproject.toml``, so editing one rebuilds every Python file.
        """
        roots = {prefix for prefix in self._declared_roots() if not self.exclusions.excludes(prefix)}
        source = self.root / "src"
        if not self.exclusions.excludes(("src",)) and source.is_dir():
            marker = source / "__init__.py"
            present = marker.is_file()
            # Only the marker's absence is a read: deleting it is a layout
            # change the core rebuilds the whole language for, and an edit
            # of its bytes moves no source root.
            self._record_probe(walk_path(marker), present, hashed=False)
            if not present:
                roots.add(("src",))
        return [(), *sorted(roots)]

    def _declared_roots(self) -> set[tuple[str, ...]]:
        """The directories the project's ``pyproject.toml`` says its packages live in.

        Read from setuptools (``packages.find.where``, the ``""`` entry of
        ``package-dir``), Poetry (``packages[].from``), Hatch (the parent of
        each wheel ``packages`` path) and PDM (``build.package-dir``). A file
        that is missing, excluded, outside the root, over the byte cap or not
        TOML declares none, and so does a path that is absolute or climbs out.

        The probe is recorded, hashed, as the request's shared read: a
        pyproject discovery leaves out (a gitignored one) is no unit, so no
        configuration hash covers it, and editing, creating or deleting it
        must still reach every file.
        """
        walked = walk_path(self.root / "pyproject.toml")
        location = self._in_root_file(walked)
        self._record_probe(walked, location is not None)
        try:
            if location is None or self.exclusions.excludes(("pyproject.toml",)):
                return set()
            source = read_bounded(location, self.max_bytes)
            if len(source) > self.max_bytes:
                return set()
            data = tomllib.loads(source.decode("utf-8"))
        except (OSError, UnicodeDecodeError, tomllib.TOMLDecodeError):
            return set()
        tool = _table(data, "tool")
        setuptools = _table(tool, "setuptools")
        declared: list[Any] = list(_list(_table(_table(setuptools, "packages"), "find"), "where"))
        declared.append(_table(setuptools, "package-dir").get(""))
        declared.extend(
            item.get("from") for item in _list(_table(tool, "poetry"), "packages") if isinstance(item, dict)
        )
        wheel = _table(_table(_table(_table(tool, "hatch"), "build"), "targets"), "wheel")
        declared.extend(
            PurePosixPath(item).parent.as_posix() for item in _list(wheel, "packages") if isinstance(item, str)
        )
        declared.append(_table(_table(tool, "pdm"), "build").get("package-dir"))
        return {prefix for prefix in map(_project_directory, declared) if prefix}

    def module_for(self, relative: str) -> str:
        parts = PurePosixPath(relative).parts
        best: tuple[str, ...] = ()
        for prefix in self.prefixes:
            if len(prefix) < len(parts) and parts[: len(prefix)] == prefix and len(prefix) > len(best):
                best = prefix
        return module_name(relative, len(best))

    def module_file(self, module: str) -> Path | None:
        """The file ``module`` resolves to, or ``None``; every candidate tried is recorded.

        A path-qualified id (:meth:`file_identity`) names a file no import
        spells, so nothing is probed for it.
        """
        parts = module.split(".")
        if not parts or "" in parts or QUALIFIED in module:
            return None
        for prefix in self.prefixes:
            base = self.root.joinpath(*prefix).joinpath(*parts)
            # Prefer the package (``mod/__init__.py``) over a same-named module
            # (``mod.py``) so a colliding pair resolves to a single stable id.
            for candidate in (base / "__init__.py", base.with_suffix(".py")):
                if self._is_project_file(candidate):
                    return candidate
            # Then the suffixless file itself. Discovery admits an extensionless
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
            # Last, a stub with no module beside it: the declarations of an
            # extension module, which is all an importer can resolve against.
            for candidate in (base / "__init__.pyi", base.with_suffix(".pyi")):
                if self._is_project_file(candidate):
                    return candidate
        return None

    def file_identity(self, relative: str, absolute: Path, found_as: str | None = None) -> tuple[str, str, Path | None]:
        """How the module file at ``relative`` is named: its location, its id, and the file its location finds.

        The location is :meth:`module_for`'s dotted name, which relative
        imports climb from. When an import of the location finds another file
        (a ``mod.py`` beside ``mod/__init__.py``, a module at the bare root and
        one in ``src/``, a stub beside its module), this file is named
        ``<location>.<relative>`` instead, so no two files share one id; the
        probes deciding that are recorded. A stub with no module beside it owns
        its location. A location no import finds names the file as it is, its
        own scan then declares nothing under it, and the owner returned is
        ``None``. ``found_as`` is a module whose import just found this file;
        when it is the location, the location is not probed again.
        """
        location = self.module_for(relative)
        owner = Path(absolute) if found_as == location else self.module_file(location)
        if owner is None or owner.resolve() == absolute.resolve():
            return location, location, owner
        return location, f"{location}{QUALIFIED}{relative}>", owner

    def canonical_module(self, module: str) -> str:
        """The id of the file an import of ``module`` finds, or ``module`` when it finds none.

        ``import src.shop.cart`` finds ``src/shop/cart.py``, whose id is
        ``shop.cart`` when ``src/`` is a source root, so the import names
        ``shop.cart``, as that file's own scan does.
        """
        path = self.module_file(module)
        if path is None:
            return module
        name = self.file_identity(path.relative_to(self.root).as_posix(), path, module)[1]
        if QUALIFIED in name:
            # Only an import spells a path-qualified module, so its declarations are found through that spelling.
            self._spellings.setdefault(name, module)
        return name

    def script_sibling_module(self, importer: str, module: str) -> str | None:
        """How to spell the module an absolute import names in the importer's own directory, if one is there.

        Running ``python3 app/main.py`` puts ``app/`` first on ``sys.path``, so
        the script's bare ``import monitor`` loads ``app/monitor.py`` whether or
        not ``app/`` is a package, and pytest does the same for a test module
        in a directory that is no package. A module something else imports has
        no directory of its own on the path. A name the standard library owns
        is left alone, so a sibling ``json.py`` never captures ``import json``.
        """
        parts = module.split(".")
        if not parts or "" in parts or parts[0] in STDLIB_MODULE_NAMES:
            return None
        directory = PurePosixPath(importer).parent
        if not directory.parts:
            return None  # the bare root is already a source root
        base = self.root.joinpath(*directory.parts, *parts)
        for candidate in (
            base / "__init__.py",
            base.with_suffix(".py"),
            base / "__init__.pyi",
            base.with_suffix(".pyi"),
        ):
            if self._is_project_file(candidate):
                # Spelled from the bare root, which is searched first, so an import of it finds this very file.
                return module_name(candidate.relative_to(self.root).as_posix())
        return None

    def is_package_directory(self, directory: PurePosixPath) -> bool:
        """Whether the project directory ``directory`` holds an ``__init__.py``; the probe is recorded."""
        marker = self.root.joinpath(*directory.parts, "__init__.py")
        present = marker.is_file()
        self._record_probe(walk_path(marker), present)
        return present

    def _is_python_script(self, path: Path) -> bool:
        """Whether a suffixless path is a project file whose shebang names Python.

        A file its shebang rules out was still decided by its bytes, so it
        is recorded by its hash, which an edit that makes it Python changes.
        """
        if path.suffix or not self._is_project_file(path):
            return False
        try:
            named = names_python_in_shebang(path)
        except UnreadableInput:
            named = False
        if not named:
            self._record_probe(walk_path(path), True)
        return named

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
            parts = path.relative_to(self.root).parts
            allowed = [index for index, segment in enumerate(parts) if segment in RESOLUTION_ALLOWED_EXCLUDED]
            # Below a dependency tree its own layout governs, not the project's.
            governed = parts[: allowed[0]] if allowed else parts
            if governed and self.exclusions.excludes(governed):
                return False
        except ValueError:
            pass
        walked = walk_path(path)
        location = self._in_root_file(walked)
        try:
            status = None if location is None else location.stat()
            if status is not None and status.st_mode & S_IFMT == S_IFREG and status.st_size <= self.max_bytes:
                self._record_probe(walked, True, self._hash_found)
                return True
        except OSError:
            pass
        self._record_walk(walked, None)
        return False

    def module_declarations(self, module: str) -> dict[str, str]:
        """The top-level declarations of ``module``, memoised, named as read by whatever asked.

        Whoever asks, first or from the memo, names what finding and reading
        the module cost (:meth:`_expose`). The declarations are named by the
        file found (:meth:`file_identity`), so ``src.shop.cart`` and
        ``shop.cart`` give the same ids. What the module re-exports was read
        for the module's own sake: a discovered module whose own scan derives
        the same declarations names it in its own contribution, so it is
        reported unattributed rather than charged to every importer; any
        other module passes it on to the importer.
        """
        cached = self._cache.get(module)
        if cached is None and QUALIFIED in module:
            spelled = self._spellings.get(module)
            return {} if spelled is None else self.module_declarations(spelled)
        if cached is None:
            self.open_scope()
            try:
                path, tree = self._read_module(module)
                location, name, owner = (
                    (module, module, None)
                    if path is None
                    else self.file_identity(path.relative_to(self.root).as_posix(), path, module)
                )
            except BaseException:
                # What was read before the failure is in input_hashes, so the
                # caller names it, and the failure reaches the file it fails.
                self._note(self.close_scope())
                raise
            located = self.close_scope()
            self._exposed[module] = located
            cached = {}
            self._cache[module] = cached
            if path is not None and tree is not None:
                self.open_scope()
                try:
                    cached = self._declare(tree, name, path.stem == "__init__", location, module)
                except BaseException:
                    located |= self.close_scope()
                    self._note(located)
                    raise
                declared = self.close_scope()
                derived = owner is not None
                if derived and self._owns(path):
                    self.unattributed_reads |= declared
                else:
                    located |= declared
                if derived and name != module:
                    # Asked for under the file's own id later, the memo answers.
                    self._cache.setdefault(name, cached)
                    self._exposed.setdefault(name, located)
        self._expose(module)
        return cached

    def _expose(self, module: str) -> None:
        """Name what ``module``'s declarations cost as read by the open scope."""
        self._note(self._exposed.get(module, set()))

    def _owns(self, path: Path) -> bool:
        """Whether ``path`` has a contribution of its own that names what it re-exports.

        Only a discovered file does, and only one whose own scan declares its
        id; without the request's list of discovered files, none is assumed to.
        """
        return self.source_files is not None and path.relative_to(self.root).as_posix() in self.source_files

    def _read_module(self, module: str) -> tuple[Path | None, ast.Module | None]:
        """Find and parse ``module``'s file, recording every probe and read; ``None`` parts when it cannot."""
        # The read below hashes the file module_file finds, so the probe
        # that found it does not read it first.
        self._hash_found = False
        try:
            path = self.module_file(module)
        finally:
            self._hash_found = True
        # One walk decides whether the module may be read, the file its bytes
        # are read from, and the key the read goes under, so none can disagree.
        walked = None if path is None else walk_path(path)
        location = None if walked is None else self._in_root_file(walked)
        if walked is None:
            return None, None
        if location is None:
            # Accepted by module_file, then retargeted out of the root or gone
            # before this read resolved it: not read, and recorded as refused.
            self._record_walk(walked, None)
            return path, None
        try:
            source = read_bounded(location, self.max_bytes)
        except OSError:
            self._record_walk(walked, None)
            return path, None
        if len(source) > self.max_bytes:
            # Grew past the cap after _is_project_file checked it.
            self._record_walk(walked, None)
            return path, None
        # Hashed before parsing, so a module that fails to parse still
        # reports the bytes this request saw.
        self._record_walk(walked, hashlib.sha256(source).hexdigest())
        try:
            return path, ast.parse(source)
        except (SyntaxError, ValueError, RecursionError):
            return path, None

    def _declare(
        self, tree: ast.Module, name: str, is_package: bool, location: str | None = None, key: str | None = None
    ) -> dict[str, str]:
        """Derive and memoise the declarations of the module file ``tree`` parsed.

        ``name`` is the id its symbols take, ``location`` the dotted name its
        relative imports climb from, and ``key`` the memo entry; each defaults
        to ``name``. Memoised before the re-exports are followed, so modules
        importing each other resolve without recursing.
        """
        location = location or name
        declarations = top_level_declarations(tree, name)
        self._protocols |= declared_protocols(tree, name)
        self._cache[key or name] = declarations
        self._add_reexports(tree, location, is_package, declarations)
        self._add_instances(tree, location, is_package, declarations, name)
        return declarations

    def is_protocol(self, owner: str) -> bool:
        """Whether ``owner`` is a structural protocol a project module declares."""
        self.module_declarations(owner.rpartition(".")[0])
        return owner in self._protocols

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

    def _add_instances(
        self, tree: ast.Module, module: str, is_package: bool, declarations: dict[str, str], name: str
    ) -> None:
        """Add the module-level instances ``tree`` creates to its declarations.

        ``user_repo = UserRepository()`` at module level is how a service hands
        out one shared object, and the rest of the codebase imports that name.
        Recorded as ``py:instance:<class>`` so an importer can type a call on
        it. The class is found among the module's own classes or its
        ``from ... import`` names; anything else is left out. A FastAPI
        ``APIRouter`` or Flask ``Blueprint`` is recorded as the ``py:router``
        node its own scan emits (``name`` is the module's id), so a mount in
        another module names it. The declarations are cached before this
        runs, so two modules importing each other resolve without recursing.
        """
        routers = router_constructors(tree)
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
            variable, constructor = None, None
            if isinstance(child, ast.Assign) and len(child.targets) == 1 and isinstance(child.targets[0], ast.Name):
                variable, constructor = child.targets[0].id, child.value
            elif isinstance(child, ast.AnnAssign) and isinstance(child.target, ast.Name):
                variable, constructor = child.target.id, child.value
            if variable is None or variable in declarations:
                continue
            if isinstance(constructor, ast.Call) and dotted(constructor.func) in routers:
                declarations[variable] = ref("router", f"{name}.{variable}")
                continue
            value = assigned_declaration(constructor, declarations, imported)
            if value is not None:
                declarations[variable] = value

    def adopt_parsed(self, absolute: Path, relative: str, tree: ast.Module) -> tuple[str, str | None]:
        """Make a scanned file's own declarations come from the tree just parsed; return its id and its id's owner.

        ``module_declarations`` reads a module's file on its own, so an earlier
        file in the batch that imports this one may have cached declarations
        from bytes other than the ones this scan hashed. Overwriting the entry
        with the hashed tree ties the file's own local resolution to its
        ``content_hash``. A file whose location another file owns (see
        :meth:`file_identity`) is declared under its path-qualified id, which
        no import shares, so seeding it never makes another importer's targets
        depend on batch order. The owner returned is that other file's path,
        ``None`` when this file owns its id or no import finds it.
        """
        self.open_scope()
        try:
            location, name, owner = self.file_identity(relative, absolute)
        finally:
            located = self.close_scope()
        # Which file the id resolves to is this file's fact too: a package
        # added beside it takes the id over.
        self._note(located)
        if owner is None:
            return name, None
        # An importer later in the batch names this file, as a read would.
        self._exposed[name] = located
        self._declare(tree, name, PurePosixPath(relative).stem == "__init__", location)
        return name, None if name == location else owner.relative_to(self.root).as_posix()

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
