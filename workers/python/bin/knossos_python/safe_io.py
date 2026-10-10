"""Bounded, regular-file-only reads and the kernel-faithful path walk they rely on."""

import hashlib
import os
import re
from pathlib import Path, PurePosixPath
from typing import Any, BinaryIO, NamedTuple

# Bytes read when probing an extensionless file's shebang; one short line is enough.
SHEBANG_PROBE_BYTES = 256
# The UTF-8 byte-order mark, spelled out rather than reached for through
# `codecs`: a single constant does not earn a module dependency, and this file's
# import count is a budgeted maintainability metric.
UTF8_BOM = b"\xef\xbb\xbf"


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


class PathWalk(NamedTuple):
    """How the kernel's lookup of one path went; see :func:`walk_path`.

    ``links`` holds each symlink followed, in order, with the components that
    were still to walk after it at that moment.
    """

    kind: str
    location: str | None
    links: tuple[tuple[str, tuple[str, ...]], ...]


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
