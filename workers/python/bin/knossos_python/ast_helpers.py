"""Pure helpers over a parsed module: declarations, bindings, names and evidence."""

import ast
from collections.abc import Iterator
from typing import Any


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


def assigned_declaration(value: ast.AST | None, declarations: dict[str, str], imported: dict[str, str]) -> str | None:
    """What a module-level name assigned ``value`` stands for, if a class is involved.

    ``repo = Repo()`` is an instance of ``Repo``; ``RepoDep = Annotated[Repo,
    Depends(get_repo)]`` names ``Repo`` itself, the class a parameter typed by
    the alias holds.
    """
    annotated = annotated_class_name(value)
    if annotated is not None:
        target = declarations.get(annotated) or imported.get(annotated)
        return target if target is not None and target.startswith("py:class:") else None
    class_name = value.func.id if isinstance(value, ast.Call) and isinstance(value.func, ast.Name) else None
    target = None if class_name is None else declarations.get(class_name) or imported.get(class_name)
    if target is not None and target.startswith("py:class:"):
        return "py:instance:" + target.removeprefix("py:class:")
    return None


def annotated_class_name(value: ast.AST | None) -> str | None:
    """The class name ``Annotated[Name, ...]`` wraps, or None for anything else."""
    if not isinstance(value, ast.Subscript) or (dotted(value.value) or "").split(".")[-1] != "Annotated":
        return None
    first = value.slice.elts[0] if isinstance(value.slice, ast.Tuple) and value.slice.elts else value.slice
    return first.id if isinstance(first, ast.Name) else None


ROUTER_CLASSES = {"fastapi": ("APIRouter",), "flask": ("Blueprint",)}
"""The framework classes whose instances another module mounts, by the module that exports them."""


def router_constructors(tree: ast.Module) -> set[str]:
    """The callee names that build a mountable router in ``tree``: ``APIRouter``, ``fastapi.APIRouter`` or an alias."""
    names: set[str] = set()
    for child in module_statements(tree):
        if isinstance(child, ast.ImportFrom) and child.level == 0 and child.module in ROUTER_CLASSES:
            exported = ROUTER_CLASSES[child.module]
            names.update(alias.asname or alias.name for alias in child.names if alias.name in exported)
        elif isinstance(child, ast.Import):
            for alias in child.names:
                for name in ROUTER_CLASSES.get(alias.name, ()):
                    names.add(f"{alias.asname or alias.name}.{name}")
    return names


def is_type_checking_guard(test: ast.expr) -> bool:
    """Whether an ``if`` test is ``TYPE_CHECKING`` or ``typing.TYPE_CHECKING``, true only to a type checker."""
    if isinstance(test, ast.Name):
        return test.id == "TYPE_CHECKING"
    return (
        isinstance(test, ast.Attribute)
        and test.attr == "TYPE_CHECKING"
        and dotted(test.value) in ("typing", "typing_extensions")
    )


def is_protocol_base(base: ast.expr) -> bool:
    """Whether a class base is ``Protocol``, generic (``Protocol[T]``) or not."""
    named = base.value if isinstance(base, ast.Subscript) else base
    return (dotted(named) or "").split(".")[-1] == "Protocol"


def declared_protocols(tree: ast.Module, module: str) -> set[str]:
    """The structural protocols ``tree`` declares at module level."""
    return {
        f"{module}.{child.name}"
        for child in module_statements(tree)
        if isinstance(child, ast.ClassDef) and any(is_protocol_base(base) for base in child.bases)
    }


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
    """The names a function binds: its parameters, and every name it assigns.

    Assigning covers every binding form: `=`, `for`, `with ... as`, `:=` and
    `except ... as`. An import inside the function is not counted: the import
    aliases resolve it to what it names. A name the function declares
    `global` is the module's however it is assigned.
    """
    arguments = node.args
    names = {argument.arg for argument in [*arguments.posonlyargs, *arguments.args, *arguments.kwonlyargs]}
    for extra in (arguments.vararg, arguments.kwarg):
        if extra is not None:
            names.add(extra.arg)
    for child in ast.walk(node):
        if isinstance(child, ast.Name) and isinstance(child.ctx, ast.Store):
            names.add(child.id)
        elif isinstance(child, ast.ExceptHandler) and child.name is not None:
            names.add(child.name)
    return frozenset(names - declared_global(node))


def declared_global(node: ast.FunctionDef | ast.AsyncFunctionDef) -> set[str]:
    """The names ``node`` itself declares ``global``; a nested function's declarations are its own."""
    names: set[str] = set()
    pending: list[ast.AST] = list(node.body)
    while pending:
        child = pending.pop()
        if isinstance(child, ast.Global):
            names.update(child.names)
        elif not isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef, ast.Lambda)):
            pending.extend(ast.iter_child_nodes(child))
    return names


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


def prefixed_path(prefix: str, path: str) -> str:
    """A route path under its router's prefix, one slash between segments: ``/bp`` and ``x/`` give ``/bp/x``."""
    return "/" + "/".join(part.strip("/") for part in (prefix, path) if part.strip("/"))


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
