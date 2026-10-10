"""Framework enrichers: roles, FastAPI, Django and Flask routes on top of the core facts."""

from __future__ import annotations

import ast
from collections.abc import Callable
from typing import Any

from .accumulator import PythonFactAccumulator
from .ast_helpers import decorator_short, dotted, keyword_string, positional_string, prefixed_path, ref


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
