"""How the worker decides a module is a script rather than a library.

The scan path is covered end to end by the PHPUnit suite, which drives the
worker as a subprocess and asserts a shebang file and a ``__main__``-guarded
file both come back executable. That test cannot reach the edges these two pure
functions promise to handle — reversed operands, a nested guard, a comparison
that is not ``==`` — and a mutation run over this file confirmed it: every
branch in ``names_main_guard`` survived, because nothing in *this* suite
exercised the function at all.
"""

from __future__ import annotations

import ast
from types import ModuleType


def test_a_shebang_marks_the_source_executable(worker: ModuleType) -> None:
    assert worker.starts_with_shebang(b"#!/usr/bin/env python3\nprint(1)\n") is True


def test_source_without_a_shebang_is_not_executable(worker: ModuleType) -> None:
    assert worker.starts_with_shebang(b'"""A library module."""\n') is False


def test_a_byte_order_mark_may_precede_the_shebang(worker: ModuleType) -> None:
    assert worker.starts_with_shebang(b"\xef\xbb\xbf#!/usr/bin/env python3\n") is True


def test_an_empty_file_is_not_executable(worker: ModuleType) -> None:
    assert worker.starts_with_shebang(b"") is False


def test_a_main_guard_marks_the_module_runnable(worker: ModuleType) -> None:
    tree = ast.parse('if __name__ == "__main__":\n    run()\n')
    assert worker.names_main_guard(tree) is True


def test_either_operand_order_is_accepted(worker: ModuleType) -> None:
    tree = ast.parse('if "__main__" == __name__:\n    run()\n')
    assert worker.names_main_guard(tree) is True


def test_the_guard_is_found_after_the_statements_that_precede_it(worker: ModuleType) -> None:
    # The realistic shape: imports and definitions come first. A scan that
    # stopped at the first non-`if` statement would never reach the guard.
    source = 'import sys\n\n\ndef main():\n    return 0\n\n\nif __name__ == "__main__":\n    sys.exit(main())\n'
    assert worker.names_main_guard(ast.parse(source)) is True


def test_an_unrelated_conditional_does_not_end_the_search(worker: ModuleType) -> None:
    # An `if` that is not a guard must be skipped, not treated as a verdict.
    tree = ast.parse('if sys.platform == "win32":\n    pass\nif __name__ == "__main__":\n    run()\n')
    assert worker.names_main_guard(tree) is True


def test_a_module_with_no_guard_is_not_runnable(worker: ModuleType) -> None:
    tree = ast.parse("def main() -> int:\n    return 0\n")
    assert worker.names_main_guard(tree) is False


def test_a_guard_nested_inside_a_function_does_not_count(worker: ModuleType) -> None:
    # Only module-level statements declare that the file is meant to be run.
    tree = ast.parse('def main():\n    if __name__ == "__main__":\n        run()\n')
    assert worker.names_main_guard(tree) is False


def test_a_comparison_that_is_not_equality_does_not_count(worker: ModuleType) -> None:
    tree = ast.parse('if __name__ != "__main__":\n    run()\n')
    assert worker.names_main_guard(tree) is False


def test_a_chained_comparison_does_not_count(worker: ModuleType) -> None:
    # `len(test.ops) != 1` rejects these: the operands no longer pair up the way
    # the guard's meaning depends on.
    tree = ast.parse('if __name__ == "__main__" == other:\n    run()\n')
    assert worker.names_main_guard(tree) is False


def test_a_guard_naming_something_else_does_not_count(worker: ModuleType) -> None:
    tree = ast.parse('if __file__ == "__main__":\n    run()\n')
    assert worker.names_main_guard(tree) is False
