#!/usr/bin/env python3
"""Knossos Python scanner worker. Parses target files; never imports them."""

import ast
import hashlib
import json
import sys
from collections.abc import Callable
from pathlib import Path, PurePosixPath
from typing import Any

# The core runs this script under ``python3 -I``, which leaves the script's own
# directory off ``sys.path``. Put the installation's ``bin`` directory, resolved
# from this file and never from the working directory or the scanned project,
# first on the path so ``knossos_python`` is always the copy shipped beside it.
sys.path.insert(0, str(Path(__file__).resolve().parent))

# A name imported ``as`` itself is not used here: it is re-exported for the unit
# suite, which loads this script as a module and reaches the package through it.
from knossos_python.ast_helpers import names_main_guard as names_main_guard
from knossos_python.ast_helpers import prefixed_path as prefixed_path
from knossos_python.ast_helpers import router_constructors as router_constructors
from knossos_python.ast_helpers import top_level_declarations as top_level_declarations
from knossos_python.collector import PythonAstFactCollector
from knossos_python.exclusions import Exclusions
from knossos_python.module_index import ProjectModuleIndex
from knossos_python.module_index import module_name as module_name
from knossos_python.safe_io import (
    RefusedAfterRead,
    UnreadableInput,
    assert_scannable_path,
    read_bounded,
    safe_file,
    safe_root,
    starts_with_shebang,
)
from knossos_python.safe_io import names_python_in_shebang as names_python_in_shebang
from knossos_python.safe_io import shebang_refusal_evidence as shebang_refusal_evidence
from knossos_python.safe_io import walk_key as walk_key
from knossos_python.safe_io import walk_path as walk_path

VERSION = "0.5.1"


# Every frame is ASCII (``\\u`` escapes decode to the same JSON), so a name that
# is not valid Unicode, or a locale whose stdout cannot encode it, cannot fail the write.
def write(message: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(message, separators=(",", ":"), ensure_ascii=True) + "\n")
    sys.stdout.flush()


Emit = Callable[[dict[str, Any]], None]


def scan(params: dict[str, Any], emit: Emit, heartbeat: Callable[[], None] | None = None) -> dict[str, Any]:
    """Parse a bounded file set and emit one owned contribution per input."""

    # Each contribution carries ``reads``, the files its facts came from, and
    # the result carries what the request shares (``reads``), what it read for
    # discovered modules whose own contributions name it
    # (``unattributed_reads``), and the digest of the source roots every
    # contribution with facts was derived under (``environments``).
    # Contributions are held until the request ends, because a later read of
    # the same file can still turn its ``input_hashes`` value into ``None``,
    # and every ``reads`` value must be the one the request ends with;
    # ``heartbeat`` is called after each file meanwhile.

    root = safe_root(params.get("root"))
    files = params.get("files")
    raw_limits = params.get("limits")
    limits: dict[str, Any] = raw_limits if isinstance(raw_limits, dict) else {}
    max_files = int(limits.get("max_files", 100_000))
    max_bytes = int(limits.get("max_file_bytes", 2_000_000))
    if not isinstance(files, list) or len(files) > max_files:
        raise ValueError("Python scan files must be a bounded list.")
    source_files = params.get("source_files")
    if source_files is not None and not (
        isinstance(source_files, list) and all(isinstance(item, str) for item in source_files)
    ):
        raise ValueError("Python source_files must be a list of project-relative paths.")

    # A path this worker refuses, a file deleted between discovery and scan, or
    # one over the byte cap is reported per file rather than raised: aborting the
    # request would discard the facts every other file in the batch contributes,
    # so a single unscannable file produced no graph at all. A request that
    # cannot be interpreted — checked above — is still fatal, because that means
    # the caller is broken rather than the tree.
    index = ProjectModuleIndex(
        root,
        max_bytes,
        Exclusions(params.get("exclusions")),
        None if source_files is None else frozenset(source_files),
    )
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
            index.record_read(requested.as_posix(), None, attributed=False)
            rejected.append((requested.as_posix(), str(error)))
        except RefusedAfterRead as error:
            # Refused on what the file says rather than on its name, so what
            # was read is evidence: a stable tree matches the hash, a script
            # swapped and restored around the probe does not.
            index.record_read(requested.as_posix(), error.content_hash, attributed=False)
            rejected.append((requested.as_posix(), str(error)))
        except ValueError as error:
            rejected.append((requested.as_posix(), str(error)))
    resolved.sort(key=lambda item: item[1])
    held: list[tuple[dict[str, Any], set[str]]] = [
        (_unscannable_contribution(skipped, message), set()) for skipped, message in sorted(rejected)
    ]
    for absolute, relative in resolved:
        held.append(_scan_one(absolute, relative, index))
        if heartbeat is not None:
            heartbeat()
    # Asked of every request that scanned a file, so a request whose files
    # all failed to parse still reports the source roots it ran under.
    environment = index.environment() if resolved else None
    for contribution, keys in held:
        own = contribution["owner_key"].removeprefix("knossos.python:file:")
        contribution["reads"] = {key: index.read_hashes[key] for key in sorted(keys - {own})}
        if environment is not None and contribution["nodes"]:
            contribution["program"] = "python"
            contribution["environment"] = environment
        emit(contribution)
    return {
        "files_scanned": len(resolved) + len(rejected),
        "parser": "python.ast",
        "input_hashes": index.read_hashes,
        "reads": {key: index.read_hashes[key] for key in sorted(index.shared_reads)},
        "unattributed_reads": {key: index.read_hashes[key] for key in sorted(index.unattributed_reads)},
        **({"environments": {"python": environment}} if environment is not None else {}),
    }


def _scan_one(absolute: Path, relative: str, index: ProjectModuleIndex) -> tuple[dict[str, Any], set[str]]:
    """Parse and collect a single file into exactly one owned contribution and the keys it read.

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
        index.record_read(relative, None, attributed=False)
        return _unscannable_contribution(relative, str(error)), set()
    if len(source) > index.max_bytes:
        index.record_read(relative, None, attributed=False)
        return _unscannable_contribution(relative, "Python input exceeds the configured byte limit."), set()
    # Of the exact bytes handed to ast.parse, which does its own decoding and
    # BOM handling, so the core can refuse facts parsed from a file that
    # changed after discovery hashed it.
    content_hash = hashlib.sha256(source).hexdigest()
    # The index may read this file too, before or after this read, for an
    # importer's sake; if the two reads disagree, the entry becomes None.
    index.record_read(relative, content_hash, attributed=False)
    try:
        tree = ast.parse(source, filename=relative, type_comments=True)
    except (SyntaxError, UnicodeDecodeError, ValueError) as error:
        diagnostic = _diagnostic_contribution(relative, "PY_SYNTAX_ERROR", "error", error, line_of(error), content_hash)
        return diagnostic, set()
    except RecursionError as error:
        return _diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1, content_hash), set()
    # The shebang lives in a comment the parser drops, so it has to be read off
    # the source. Reduce it to a flag and release the bytes here, so the loop's
    # memory bound stays the largest single tree.
    shebang = starts_with_shebang(source)
    del source
    # Everything the index reads from here on is this file's: a fault keeps
    # the keys read so far, since they are in input_hashes and must be named.
    index.open_scope()
    try:
        name, owner = index.adopt_parsed(absolute, relative, tree)
        collision = index.collides(absolute, PurePosixPath(relative).stem == "__init__")
        contribution = PythonAstFactCollector(relative, tree, index, collision, shebang, name, owner).collect()
    except Exception as error:
        contribution = _diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1, content_hash)
        # Only what was read before the failure is named: what the file
        # re-exports may be missing, which its importers rely on.
        contribution["reads_partial"] = True
    finally:
        del tree  # drop the parsed tree before the next file to bound memory
        keys = index.close_scope()
    contribution["content_hash"] = content_hash
    return contribution, keys


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
    # The serialized part, measured in its ASCII-escaped wire form: its braces, less the comma its last entry lacks.
    size = 1
    for relative, content_hash in input_hashes.items():
        # `"path":"<64 hex>",` or `"path":null,`
        entry = len(json.dumps(relative, ensure_ascii=True).encode()) + (4 if content_hash is None else 66) + 2
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
            "capabilities": ["partial_ast", "content_hash", "input_hashes", "read_attribution"],
        }
    elif method == "scan":
        scanned = scan(
            params,
            lambda contribution: write({"jsonrpc": "2.0", "method": "scan/contribution", "params": contribution}),
            lambda: write({"jsonrpc": "2.0", "method": "scan/heartbeat"}),
        )
        parts = input_hash_parts(scanned["input_hashes"])
        for part in parts[:-1]:
            write({"jsonrpc": "2.0", "method": "scan/input_hashes", "params": {"input_hashes": part}})
        # The shared and unattributed reads grow with the tree as input_hashes
        # does, and travel in parts of their own beside an empty one.
        for field in ("reads", "unattributed_reads"):
            field_parts = input_hash_parts(scanned[field])
            for part in field_parts[:-1]:
                write({"jsonrpc": "2.0", "method": "scan/input_hashes", "params": {"input_hashes": {}, field: part}})
            scanned[field] = field_parts[-1]
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
