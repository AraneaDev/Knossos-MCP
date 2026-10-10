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
from knossos_python.ast_helpers import (
    absolute_import,
    bound_names,
    declared_protocols,
    decorator_short,
    dotted,
    evidence,
    is_protocol_base,
    keyword_string,
    names_main_guard,
    positional_string,
    prefixed_path,
    ref,
)
from knossos_python.ast_helpers import router_constructors as router_constructors
from knossos_python.ast_helpers import top_level_declarations as top_level_declarations
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


class RouterMounts:
    """The routers a file assigns and mounts, shared by the FastAPI and Flask enrichers.

    A router assigned here is a ``py:router:<module>.<variable>`` node; one
    imported from another module is that module's node, which its own scan
    emits. A mount names the router node it reaches, so no edge points at a
    router nobody declares.
    """

    def __init__(
        self,
        facts: PythonFactAccumulator,
        module: str,
        module_id: str,
        aliases: dict[str, str],
        resolve_name: Callable[[str, str], str | None],
        declarations: Callable[[str], dict[str, str]],
    ) -> None:
        self.facts = facts
        self.module = module
        self.module_id = module_id
        self.aliases = aliases
        self.resolve_name = resolve_name
        # A module's top-level declarations, by module id (ProjectModuleIndex.module_declarations).
        self.declarations = declarations
        # Variable to (framework, prefix) for every app, router or blueprint in scope.
        self.framework_objects: dict[str, tuple[str, str]] = {}
        # The variables this file assigned a router to, each a node it emits.
        self.routers: set[str] = set()

    def emit_router(self, variable: str, value: ast.Call, framework: str, prefix: str) -> None:
        """Declare the router node a module-level ``variable = APIRouter(...)`` or ``Blueprint(...)`` builds."""
        router_id = ref("router", f"{self.module}.{variable}")
        self.facts.add_node(
            router_id,
            "router",
            f"{self.module}.{variable}",
            variable,
            value,
            {"framework": framework, "prefix": prefix},
        )
        self.facts.add_edge("contains", self.module_id, router_id, value)
        self.routers.add(variable)

    def mount(self, node: ast.Call, prefix_keyword: str) -> None:
        """Emit the ``mounts`` edge of ``include_router(router)`` or ``register_blueprint(bp)``.

        A router this file assigns, one a project module declares and an
        imported third-party name are certain targets. Anything else, such as
        a router handed in as a parameter, is a guess the core keeps only when
        it resolves.
        """
        router = dotted(node.args[0]) if node.args else None
        if router is None:
            return
        attributes: dict[str, Any] = {"prefix": keyword_string(node, prefix_keyword) or ""}
        target = ref("router", f"{self.module}.{router}")
        if router not in self.routers:
            resolved = self.resolve_name(router, "router") or ""
            if resolved.startswith("py:external_symbol:") or self.declares(resolved):
                target = resolved
            else:
                target = resolved if resolved.startswith("py:router:") else target
                attributes["speculative"] = True
        self.facts.add_edge("mounts", self.module_id, target, node, attributes)

    def declares(self, target: str) -> bool:
        """Whether ``target`` is a router a project module declares at module level."""
        if not target.startswith("py:router:"):
            return False
        module, _, variable = target.removeprefix("py:router:").rpartition(".")
        return bool(module) and self.declarations(module).get(variable) == target


class FastApiFactEnricher(RouterMounts):
    """Add FastAPI routes, dependencies, routers, and middleware facts."""

    def register_assignment(self, variable: str, value: ast.AST | None, module_level: bool = True) -> None:
        if not isinstance(value, ast.Call):
            return
        called = dotted(value.func)
        # Through resolve_name, so `fastapi.APIRouter()` off `import fastapi` counts too.
        resolved = (called and self.resolve_name(called, "class")) or ""
        if resolved.endswith(("fastapi.FastAPI", "fastapi.APIRouter")):
            prefix = keyword_string(value, "prefix") or ""
            self.framework_objects[variable] = ("fastapi", prefix)
        # A router built inside a function is that call's own, one per call:
        # no module-level node names it, though its routes keep its prefix.
        if module_level and resolved.endswith("fastapi.APIRouter"):
            self.emit_router(variable, value, "fastapi", keyword_string(value, "prefix") or "")

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
            path = prefixed_path(prefix, raw_path)
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
        if name and name.endswith(".include_router"):
            self.mount(node, "prefix")

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


class FlaskFactEnricher(RouterMounts):
    """Add Flask route and blueprint facts.

    Flask's primary wiring is `@app.route("/path", methods=[...])` on a
    `Flask` or `Blueprint` instance, so unlike FastAPI it names one path per
    decorator but may carry several verbs. A dynamic path (`<id>`) is a plain
    string to the parser and would make a route node that no request ever
    matches, so it is diagnosed rather than guessed, mirroring
    `PY_DYNAMIC_ROUTE_PATH`.
    """

    def register_assignment(self, variable: str, value: ast.AST | None, module_level: bool = True) -> None:
        if not isinstance(value, ast.Call):
            return
        called = dotted(value.func)
        resolved = (called and self.resolve_name(called, "class")) or ""
        if resolved.endswith(("flask.Flask", "flask.Blueprint")):
            prefix = keyword_string(value, "url_prefix") or ""
            self.framework_objects[variable] = ("flask", prefix)
        if module_level and resolved.endswith("flask.Blueprint"):
            self.emit_router(variable, value, "flask", keyword_string(value, "url_prefix") or "")

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
            path = prefixed_path(prefix, raw_path)
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
        if name and name.endswith(".register_blueprint"):
            self.mount(node, "url_prefix")
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
            # A blueprint's rule sits under its `url_prefix`, as a decorated route does.
            owner = dotted(node.func.value) if isinstance(node.func, ast.Attribute) else None
            prefix = self.framework_objects.get(owner or "", ("flask", ""))[1]
            path = prefixed_path(prefix, raw_path)
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
        name: str | None = None,
        owner: str | None = None,
    ) -> None:
        self.relative = relative
        self.has_shebang = has_shebang
        self.executable = has_shebang or names_main_guard(tree)
        # `029_seed.py` names no module an import statement can reach, so a
        # loader reads it by path and calls the public names it exposes.
        self.loaded_by_path = not PurePosixPath(relative).stem.isidentifier()
        self.index = index
        # Where the file sits, which relative imports climb from, and the id
        # its symbols take: the same unless ``owner``, the file an import of
        # this location finds, is another file (see ProjectModuleIndex.file_identity).
        self.module = index.module_for(relative)
        self.name = name or self.module
        self.owner = owner
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
        # Structural protocols this file declares: a call through one may
        # reach any class with the member, declared or not.
        self.protocols: set[str] = set()
        # Classes deriving from the standard library's AST visitor, whose
        # `visit_<Node>` hooks it dispatches to by name.
        self.ast_visitors: set[str] = set()
        self.serves_app = False
        self.module_id = ref("module", self.name)
        self.facts = PythonFactAccumulator(relative)
        self.roles = PythonFrameworkRoleEnricher()
        routing = (self.facts, self.name, self.module_id, self.aliases, self.resolve_name, index.module_declarations)
        self.fastapi = FastApiFactEnricher(*routing)
        self.django = DjangoFactEnricher(self.facts, self.name, self.module_id, self.aliases, self.resolve_name)
        self.flask = FlaskFactEnricher(*routing)

    def collect(self) -> dict[str, Any]:
        self.facts.add_node(
            self.module_id,
            "module",
            self.name,
            self.name,
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
            package = self.name
            # Displayed by where it sits: a path-qualified id ends in its path.
            display = self.module.split(".")[-1]
            self.facts.add_node(ref("package", package), "package", package, display, self.tree)
            self.facts.add_edge("contains", ref("package", package), self.module_id, self.tree)
        self.collision_diagnostic()
        # Known before any call is visited: a call may precede the class.
        self.protocols |= declared_protocols(self.tree, self.name)
        self.visit(self.tree)
        if self.serves_app:
            self.facts.nodes[self.module_id]["attributes"]["executable"] = True
        if self.untyped_calls:
            # A method by one of these names may be what such a call reaches,
            # so the core reports it as only possibly dead.
            self.facts.nodes[self.module_id]["attributes"]["unresolved_member_calls"] = sorted(self.untyped_calls)
        if self.relative.endswith(".pyi"):
            # A stub describes code rather than being it: its symbols are
            # reached through the module they describe, never by a call.
            for fact in self.facts.nodes.values():
                fact["attributes"]["declaration_file"] = True
        return self.facts.result()

    def collision_diagnostic(self) -> None:
        """Report a module id this file shares with another file, and the id it takes instead.

        A stub beside its own module shares the id by design and is named
        apart without a warning.
        """
        renamed = f" This file's symbols are named '{self.name}'." if self.name != self.module else ""
        if self.module_collision:
            message = (
                f"Module id '{self.module}' is shared by a module file and a package; "
                f"the package (__init__.py) owns it.{renamed}"
            )
        elif self.owner is not None and not (self.relative.endswith(".pyi") and self.owner == self.relative[:-1]):
            message = (
                f"Module id '{self.module}' is also the id of '{self.owner}', which an import of it finds.{renamed}"
            )
        else:
            return
        self.facts.add_diagnostic("PY_MODULE_ID_COLLISION", message, self.tree)

    def current(self) -> str:
        return self.containers[-1][0] if self.containers else self.module_id

    def is_protocol(self, owner: str) -> bool:
        """Whether ``owner`` is a structural protocol, declared here or imported."""
        return owner in self.protocols or self.index.is_protocol(owner)

    def resolve_name(self, name: str, hint: str = "class") -> str | None:
        if "." not in name:
            for scope in reversed(self.local_function_scopes):
                if name in scope:
                    return scope[name]
        if name in self.aliases:
            return self.aliases[name]
        local = self.index.module_declarations(self.name).get(name)
        if local:
            return local
        if "." in name:
            first, rest = name.split(".", 1)
            base = self.aliases.get(first)
            if base and base.startswith("py:module:"):
                return self.module_member(base.removeprefix("py:module:"), rest, hint)
        return None

    def module_member(self, module: str, rest: str, hint: str) -> str:
        """What ``<module>.<rest>`` names: a declaration of the module, or of the submodule ``rest`` reaches into.

        ``import app.models.user`` binds ``app``, so ``app.models.user.make()``
        names ``make`` in the longest submodule the import system finds,
        ``app.models.user``, unless ``app`` itself declares ``models``.
        """
        parts = rest.split(".")
        if len(parts) > 1 and parts[0] not in self.index.module_declarations(module):
            for end in range(len(parts) - 1, 0, -1):
                spelled = ".".join([module, *parts[:end]])
                if self.index.module_file(spelled) is not None:
                    module, parts = self.index.canonical_module(spelled), parts[end:]
                    break
        member = ".".join(parts)
        return self.index.module_declarations(module).get(member, ref(hint, f"{module}.{member}"))

    def script_import(self, module: str) -> str:
        """An absolute import, resolved in the importer's own directory when no source root has it.

        Only when that directory is on ``sys.path`` (:meth:`runs_from_own_directory`).
        """
        if self.index.module_file(module) is not None or not self.runs_from_own_directory():
            return module
        return self.index.script_sibling_module(self.relative, module) or module

    def runs_from_own_directory(self) -> bool:
        """Whether this file's own directory is first on ``sys.path`` when it runs.

        A script's is (``python3 tools/run.py``). So is a module's in a
        directory that is no package: pytest puts a test module's directory
        there, and a script run beside it puts it there for the modules it
        imports. Inside a package, a bare import never names a sibling.
        """
        if self.executable:
            return True
        directory = PurePosixPath(self.relative).parent
        return bool(directory.parts) and not self.index.is_package_directory(directory)

    def visit_Import(self, node: ast.Import) -> None:
        for alias in node.names:
            spelled = self.script_import(alias.name)
            target = ref("module", self.index.canonical_module(spelled))
            # `import a.b` binds `a`, the spelling less the segments after the
            # first; `import a.b as m` binds `m` to `a.b`.
            depth = 0 if alias.asname else alias.name.count(".")
            bound = target if depth == 0 else ref("module", self.index.canonical_module(spelled.rsplit(".", depth)[0]))
            self.aliases[alias.asname or alias.name.split(".")[0]] = bound
            self.facts.add_edge("imports", self.module_id, target, node, {"alias": alias.asname})

    def visit_ImportFrom(self, node: ast.ImportFrom) -> None:
        spelled = absolute_import(self.module, node.level, node.module, self.is_package)
        if node.level == 0 and spelled:
            spelled = self.script_import(spelled)
        if not spelled:
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
        # Named by the file the import finds, the id that file's own scan declares.
        module = self.index.canonical_module(spelled)
        self.facts.add_edge("imports", self.module_id, ref("module", module), node, {"relative_level": node.level})
        for alias in node.names:
            if alias.name == "*":
                continue
            target = self.index.module_declarations(spelled).get(alias.name)
            if target is None and self.index.module_file(f"{spelled}.{alias.name}") is not None:
                # `from .tools import cors` names the submodule `tools/cors.py`
                # when the package declares no `cors` of its own.
                target = ref("module", self.index.canonical_module(f"{spelled}.{alias.name}"))
                self.facts.add_edge("imports", self.module_id, target, node, {"relative_level": node.level})
            self.aliases[alias.asname or alias.name] = target or ref("external_symbol", f"{module}.{alias.name}")

    def visit_ClassDef(self, node: ast.ClassDef) -> None:
        canonical = f"{self.name}.{node.name}"
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
        if any(is_protocol_base(base) for base in node.bases):
            self.protocols.add(canonical)
        if any(self.is_ast_visitor_base(base) for base in node.bases):
            self.ast_visitors.add(canonical)
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
            parent_id, kind, canonical = self.current(), "function", f"{self.name}.{node.name}"
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
        if kind == "method" and self.overrides_supertype_member(node.name, parent_canonical, decorators):
            attributes["overrides"] = True
        self.facts.add_node(local_id, kind, canonical, node.name, node, attributes)
        self.facts.add_edge("contains", parent_id, local_id, node)
        self.fastapi.enrich_function(node, local_id, canonical, fastapi_routes)
        self.flask.enrich_function(node, local_id, canonical, flask_routes)
        self.containers.append((local_id, canonical, kind))
        self.local_function_scopes.append(self.local_function_declarations(node, canonical, self.name))
        self.local_variable_types.append({})
        self.parameter_types.append(self.annotated_parameters(node))
        self.bound_names.append(bound_names(node))
        restore_fastapi = self.fastapi.register_parameters(node)
        # An import inside the function binds its names in the function only.
        # Restored in place: the framework enrichers hold this same mapping.
        outer_aliases = dict(self.aliases)
        self.generic_visit(node)
        self.aliases.clear()
        self.aliases.update(outer_aliases)
        self.fastapi.restore_parameters(restore_fastapi)
        self.parameter_types.pop()
        self.bound_names.pop()
        self.local_variable_types.pop()
        self.local_function_scopes.pop()
        self.containers.pop()

    def is_ast_visitor_base(self, base: ast.expr) -> bool:
        """Whether a base names the standard library's ``ast.NodeVisitor`` or ``ast.NodeTransformer``."""
        name = dotted(base)
        target = self.resolve_name(name, "class") if name else None
        if target is None or self.index.module_file("ast") is not None:
            return False
        return target.split(":", 2)[-1] in {"ast.NodeVisitor", "ast.NodeTransformer"}

    def overrides_supertype_member(self, name: str, owner: str, decorators: list[str]) -> bool:
        """Whether a method fulfils a supertype's member, by the source's word or by dispatch.

        ``@override`` from ``typing`` or ``typing_extensions`` is the source
        saying so. An AST visitor's ``visit``, ``generic_visit`` and
        ``visit_<Node>`` hooks are called by ``NodeVisitor.visit`` through
        ``getattr``, so no call names them. A same-named decorator from
        anywhere else says nothing.
        """
        if owner in self.ast_visitors and (name in {"visit", "generic_visit"} or name.startswith("visit_")):
            return True
        typing_modules = {"typing", "typing_extensions"}
        imported = {f"{module}.override" for module in typing_modules}
        for decorator in decorators:
            head, _, member = decorator.rpartition(".")
            if member != "override":
                continue
            if head:
                if (self.aliases.get(head) or "").removeprefix("py:module:") in typing_modules:
                    return True
            elif (self.aliases.get("override") or "").split(":", 2)[-1] in imported:
                return True
        return False

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
        node: ast.FunctionDef | ast.AsyncFunctionDef, parent_canonical: str, module: str
    ) -> dict[str, str]:
        declarations: dict[str, str] = {}
        pending: list[ast.AST] = list(reversed(node.body))
        while pending:
            child = pending.pop()
            if isinstance(child, (ast.FunctionDef, ast.AsyncFunctionDef)):
                canonical = f"{parent_canonical}.<locals>.{child.name}"
                declarations[child.name] = ref("function", canonical)
                continue
            if isinstance(child, ast.ClassDef):
                # A class defined in a function is named at module level.
                declarations[child.name] = ref("class", f"{module}.{child.name}")
                continue
            if isinstance(child, ast.Lambda):
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

    SERVED_APPS = (
        "fastapi.FastAPI",
        "flask.Flask",
        "starlette.applications.Starlette",
        "quart.Quart",
        "litestar.Litestar",
        "django.core.wsgi.get_wsgi_application",
        "django.core.asgi.get_asgi_application",
    )

    def note_served_app(self, value: ast.AST | None) -> None:
        """Mark the module executable when it builds an app a server serves.

        ``app = FastAPI()`` at module level is what ``uvicorn main:app`` or a
        WSGI server loads by name; nothing imports the module for it.
        """
        if self.containers or not isinstance(value, ast.Call):
            return
        called = dotted(value.func) or ""
        resolved = self.aliases.get(called) or self.resolve_name(called, "class") or ""
        # `py:<kind>:<name>`: the name alone, whichever kind the import resolved to.
        if resolved.split(":", 2)[-1] in self.SERVED_APPS:
            self.serves_app = True

    def visit_Assign(self, node: ast.Assign) -> None:
        self.note_served_app(node.value)
        self.emit_value_references(node.value)
        if len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            variable = node.targets[0].id
            self.fastapi.register_assignment(variable, node.value, not self.containers)
            self.django.enrich_assignment(variable, node.value, node)
            self.flask.register_assignment(variable, node.value, not self.containers)
            self.remember_local(variable, node.value)
        if len(node.targets) == 1:
            attribute = self.self_attribute(node.targets[0])
            if attribute is not None:
                self.remember_attribute(attribute, node.value)
        self.generic_visit(node)

    def visit_AnnAssign(self, node: ast.AnnAssign) -> None:
        self.note_served_app(node.value)
        self.emit_value_references(node.value)
        attribute = self.self_attribute(node.target)
        if attribute is not None:
            # An annotation states the type outright, which beats inferring it.
            self.remember_attribute(attribute, node.value, node.annotation)
        elif isinstance(node.target, ast.Name):
            self.fastapi.register_assignment(node.target.id, node.value, not self.containers)
            self.flask.register_assignment(node.target.id, node.value, not self.containers)
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

    def fresh_instance_class(self, call: ast.Call) -> str | None:
        """The project class ``Repo()`` builds, when its name means that class here.

        A name off a module outside the project (``requests.get(url)``) reads
        as a class only because nothing says otherwise, and a parameter or
        local named like an imported class holds whatever was passed in.
        """
        callee = dotted(call.func)
        if callee is None or self.is_local_name(callee.split(".")[0]):
            return None
        held = self.held_class(call)
        return held if held is not None and self.declares_class(held) else None

    def shadows_builtin(self, name: str) -> bool:
        """Whether this scope, an import or the module binds ``name`` over the builtin."""
        return self.is_local_name(name) or name in self.aliases or name in self.index.module_declarations(self.name)

    def declares_class(self, held: str) -> bool:
        """Whether a project module declares ``held`` as a top-level class."""
        module, _, name = held.rpartition(".")
        return bool(module) and self.index.module_declarations(module).get(name) == ref("class", held)

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
                instance = self.aliases.get(receiver) or self.index.module_declarations(self.name).get(receiver)
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
            if target is None and not any(node.id in names for names in self.bound_names):
                # A name this function, or one around it, binds is a local
                # there, whatever the module declares under that name.
                target = self.aliases.get(node.id) or self.index.module_declarations(self.name).get(node.id)
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
            if member is None and isinstance(node.value, ast.Call):
                # `partial(Repo().rows, 1)`: the class just built types the read.
                held = self.fresh_instance_class(node.value)
                if held is not None:
                    member = ref("method", f"{held}::{node.attr}")
            if member is not None:
                self.facts.add_edge("references", self.current(), member, node, {"speculative": True})
            elif receiver and (self.aliases.get(receiver) or "").startswith("py:module:"):
                # `tasks.snapshot` read off an imported project module: the
                # function it declares, handed on as a value. Only a name the
                # module declares counts; `os.environ` names nothing here.
                module = self.aliases[receiver].removeprefix("py:module:")
                named = self.index.module_declarations(module).get(node.attr)
                if named is not None and named.startswith(("py:function:", "py:class:")) and named != self.current():
                    self.facts.add_edge("references", self.current(), named, node)
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
            held = self.fresh_instance_class(node.func.value)
            if held is None:
                self.untyped_calls.add(node.func.attr)
            else:
                target = ref("method", f"{held}::{node.func.attr}")
        if (
            name == "getattr"
            and not self.shadows_builtin("getattr")
            and len(node.args) >= 2
            and isinstance(node.args[1], ast.Constant)
            and isinstance(node.args[1].value, str)
        ):
            # `getattr(editor, "narrowed")` looks a member up by name on a
            # receiver the call leaves untyped.
            self.untyped_calls.add(node.args[1].value)
        if (
            target
            and target.startswith("py:method:")
            and self.is_protocol(target.removeprefix("py:method:").split("::")[0])
        ):
            self.untyped_calls.add(target.rsplit("::", 1)[1])
        # Calling an instance (`repo()`) names no declaration of its own.
        if target and not target.startswith("py:instance:"):
            self.facts.add_edge("calls", self.current(), target, node)
        self.fastapi.enrich_call(node, name)
        self.flask.enrich_call(node, name)
        self.generic_visit(node)


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
