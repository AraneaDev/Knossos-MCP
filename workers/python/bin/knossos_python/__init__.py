"""The Python worker's analysis, split out of ``bin/worker.py``, which keeps the protocol.

Loaded only by ``worker.py``, which puts this directory's parent on ``sys.path``
itself, because the core runs the worker under ``python3 -I`` and the script's own
directory is then not on it. What the script needs is re-exported here, so it
imports the package in one statement.
"""

from .ast_helpers import names_main_guard, prefixed_path, router_constructors, top_level_declarations
from .collector import PythonAstFactCollector
from .exclusions import Exclusions
from .module_index import ProjectModuleIndex, module_name
from .safe_io import (
    RefusedAfterRead,
    UnreadableInput,
    assert_scannable_path,
    names_python_in_shebang,
    read_bounded,
    safe_file,
    safe_root,
    shebang_refusal_evidence,
    starts_with_shebang,
    walk_key,
    walk_path,
)

__all__ = [
    "Exclusions",
    "ProjectModuleIndex",
    "PythonAstFactCollector",
    "RefusedAfterRead",
    "UnreadableInput",
    "assert_scannable_path",
    "module_name",
    "names_main_guard",
    "names_python_in_shebang",
    "prefixed_path",
    "read_bounded",
    "router_constructors",
    "safe_file",
    "safe_root",
    "shebang_refusal_evidence",
    "starts_with_shebang",
    "top_level_declarations",
    "walk_key",
    "walk_path",
]
