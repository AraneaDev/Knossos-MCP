#!/usr/bin/env python3
"""Knossos Python scanner worker. Parses target files; never imports them."""

from __future__ import annotations

import ast
import json
import os
import sys
from collections.abc import Callable
from pathlib import Path, PurePosixPath
from typing import Any

VERSION = "0.2.0"
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


def safe_file(root: Path, value: Any, max_bytes: int) -> tuple[Path, str]:
    if not isinstance(value, str) or not value or "\0" in value or "\\" in value:
        raise ValueError("Python input must be a normalized project-relative path.")
    relative = PurePosixPath(value)
    if relative.is_absolute() or any(part in {"", ".", ".."} for part in relative.parts):
        raise ValueError("Python input path is unsafe.")
    absolute = (root / Path(*relative.parts)).resolve(strict=True)
    try:
        absolute.relative_to(root)
    except ValueError as error:
        raise ValueError("Python input path escapes the project root.") from error
    if not absolute.is_file() or absolute.suffix.lower() not in {".py", ".pyi"}:
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
    """

    def __init__(self, root: Path, max_bytes: int) -> None:
        self.root = root
        self.max_bytes = max_bytes
        self.prefixes = self._source_root_prefixes()
        self._cache: dict[str, dict[str, str]] = {}

    def _source_root_prefixes(self) -> list[tuple[str, ...]]:
        prefixes: list[tuple[str, ...]] = [()]
        try:
            for child in sorted(self.root.iterdir()):
                if child.name in EXCLUDED or not child.is_dir():
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
        try:
            if not path.is_file():
                return False
            resolved = path.resolve()
            return resolved.is_relative_to(self.root) and resolved.stat().st_size <= self.max_bytes
        except OSError:
            return False

    def module_declarations(self, module: str) -> dict[str, str]:
        cached = self._cache.get(module)
        if cached is not None:
            return cached
        declarations: dict[str, str] = {}
        path = self.module_file(module)
        if path is not None:
            try:
                tree = ast.parse(path.read_bytes())
                for child in tree.body:
                    if isinstance(child, ast.ClassDef):
                        declarations[child.name] = ref("class", f"{module}.{child.name}")
                    elif isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef)):
                        declarations[child.name] = ref("function", f"{module}.{child.name}")
            except (SyntaxError, ValueError, OSError, RecursionError):
                declarations = {}
        self._cache[module] = declarations
        return declarations

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
        return sorted(set(roles))

    def function_roles(self, decorators: list[str], has_fastapi_route: bool) -> list[str]:
        roles: list[str] = []
        framework_decorators = [decorator_short(name) for name in decorators]
        if any(name in {"api_view", "action"} for name in framework_decorators):
            roles.append("django.view")
        if any(name in {"shared_task", "task"} for name in framework_decorators):
            roles.append("python.task")
        if has_fastapi_route:
            roles.append("fastapi.route_handler")
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

    def register_assignment(self, variable: str, value: ast.AST) -> None:
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


class PythonAstFactCollector(ast.NodeVisitor):
    """Coordinate one AST traversal and delegate fact enrichment."""

    def __init__(
        self,
        relative: str,
        tree: ast.Module,
        index: ProjectModuleIndex,
        module_collision: bool = False,
    ) -> None:
        self.relative = relative
        self.index = index
        self.module = index.module_for(relative)
        self.is_package = PurePosixPath(relative).stem == "__init__"
        self.module_collision = module_collision
        self.tree = tree
        self.aliases: dict[str, str] = {}
        self.containers: list[tuple[str, str, str]] = []
        self.local_function_scopes: list[dict[str, str]] = []
        self.module_id = ref("module", self.module)
        self.facts = PythonFactAccumulator(relative)
        self.roles = PythonFrameworkRoleEnricher()
        self.fastapi = FastApiFactEnricher(self.facts, self.module, self.module_id, self.aliases, self.resolve_name)
        self.django = DjangoFactEnricher(self.facts, self.module, self.module_id, self.aliases, self.resolve_name)

    def collect(self) -> dict[str, Any]:
        self.facts.add_node(
            self.module_id, "module", self.module, self.module, self.tree, {"stub": self.relative.endswith(".pyi")}
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
        route_decorators = self.route_decorators(node)
        roles = self.roles.function_roles(decorators, bool(route_decorators))
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
        self.fastapi.enrich_function(node, local_id, canonical, route_decorators)
        self.containers.append((local_id, canonical, kind))
        self.local_function_scopes.append(self.local_function_declarations(node, canonical))
        self.generic_visit(node)
        self.local_function_scopes.pop()
        self.containers.pop()

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
        self.generic_visit(node)

    def visit_Call(self, node: ast.Call) -> None:
        name = dotted(node.func)
        target = None
        if name:
            if name.startswith("self.") and self.containers:
                class_container = next((item for item in reversed(self.containers) if item[2] == "class"), None)
                if class_container:
                    target = ref("method", f"{class_container[1]}::{name.split('.', 1)[1]}")
            target = target or self.resolve_name(name, "function")
        if target:
            self.facts.add_edge("calls", self.current(), target, node)
        self.fastapi.enrich_call(node, name)
        self.generic_visit(node)

    def route_decorators(self, node: ast.FunctionDef | ast.AsyncFunctionDef) -> list[tuple[str, str, ast.AST]]:
        return self.fastapi.route_decorators(node)


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

    # Validate every path and byte cap up front so an unsafe or oversized input
    # aborts the request before any partial contribution is streamed.
    resolved = [safe_file(root, value, max_bytes) for value in files]
    resolved.sort(key=lambda item: item[1])

    index = ProjectModuleIndex(root, max_bytes)
    for absolute, relative in resolved:
        # Parse and collect one file at a time and release its tree before the
        # next, so peak memory stays bounded by the largest single file rather
        # than the whole batch. Each file is isolated: a syntax error, an
        # oversized recursion, or an unexpected fault degrades to a per-file
        # diagnostic and never discards facts for the other inputs.
        try:
            tree = ast.parse(absolute.read_bytes(), filename=relative, type_comments=True)
        except (SyntaxError, UnicodeDecodeError, ValueError) as error:
            emit(_diagnostic_contribution(relative, "PY_SYNTAX_ERROR", "error", error, line_of(error)))
            continue
        except RecursionError as error:
            emit(_diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1))
            continue
        try:
            collision = index.collides(absolute, PurePosixPath(relative).stem == "__init__")
            contribution = PythonAstFactCollector(relative, tree, index, collision).collect()
        except RecursionError as error:
            emit(_diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1))
            continue
        except Exception as error:
            emit(_diagnostic_contribution(relative, "PY_INTERNAL_ERROR", "error", error, 1))
            continue
        finally:
            del tree  # drop the parsed tree before the next file to bound memory
        emit(contribution)
    return {"files_scanned": len(resolved), "parser": "python.ast"}


def line_of(error: BaseException) -> int:
    return max(1, int(getattr(error, "lineno", 1) or 1))


def _diagnostic_contribution(
    relative: str, code: str, severity: str, error: BaseException, line: int
) -> dict[str, Any]:
    return {
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


def discover(params: dict[str, Any]) -> dict[str, Any]:
    """Discover sorted Python configuration and package markers below a safe root."""

    root = safe_root(params.get("root"))
    configs, packages = [], []
    for directory, names, files in os.walk(root):
        names[:] = sorted(name for name in names if name not in EXCLUDED)
        relative_directory = Path(directory).relative_to(root)
        for filename in sorted(files):
            relative = (relative_directory / filename).as_posix()
            if filename == "pyproject.toml":
                configs.append(relative)
            if filename == "__init__.py":
                packages.append(relative)
    return {"root": root.as_posix(), "config_files": configs, "package_files": packages}


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
            "capabilities": ["discover", "partial_ast", "cancel"],
        }
    elif method == "discover":
        result = discover(params)
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
