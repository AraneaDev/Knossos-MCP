"""The AST visitor that collects one Python file's facts."""

import ast
from pathlib import PurePosixPath
from typing import Any

from .accumulator import PythonFactAccumulator
from .ast_helpers import (
    absolute_import,
    bound_names,
    declared_protocols,
    dotted,
    is_none,
    is_protocol_base,
    is_type_checking_guard,
    names_main_guard,
    optional_inner,
    ref,
)
from .frameworks import DjangoFactEnricher, FastApiFactEnricher, FlaskFactEnricher, PythonFrameworkRoleEnricher
from .module_index import ProjectModuleIndex


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
        # How many `if TYPE_CHECKING:` bodies the walk is inside: an import
        # there is read by the type checker and skipped at runtime.
        self.type_checking_depth = 0
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

    def visit_If(self, node: ast.If) -> None:
        """Walk an ``if TYPE_CHECKING:`` body as type-only; its ``else`` runs."""
        if not is_type_checking_guard(node.test):
            self.generic_visit(node)
            return
        self.visit(node.test)
        self.type_checking_depth += 1
        try:
            for statement in node.body:
                self.visit(statement)
        finally:
            self.type_checking_depth -= 1
        for statement in node.orelse:
            self.visit(statement)

    def import_attributes(self, attributes: dict[str, Any]) -> dict[str, Any]:
        """An import's attributes, marked type-only when only a type checker runs it."""
        return {**attributes, "type_only": True} if self.type_checking_depth else attributes

    def visit_Import(self, node: ast.Import) -> None:
        for alias in node.names:
            spelled = self.script_import(alias.name)
            target = ref("module", self.index.canonical_module(spelled))
            # `import a.b` binds `a`, the spelling less the segments after the
            # first; `import a.b as m` binds `m` to `a.b`.
            depth = 0 if alias.asname else alias.name.count(".")
            bound = target if depth == 0 else ref("module", self.index.canonical_module(spelled.rsplit(".", depth)[0]))
            self.aliases[alias.asname or alias.name.split(".")[0]] = bound
            self.facts.add_edge(
                "imports", self.module_id, target, node, self.import_attributes({"alias": alias.asname})
            )

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
        self.facts.add_edge(
            "imports",
            self.module_id,
            ref("module", module),
            node,
            self.import_attributes({"relative_level": node.level}),
        )
        for alias in node.names:
            if alias.name == "*":
                continue
            target = self.index.module_declarations(spelled).get(alias.name)
            if target is None and self.index.module_file(f"{spelled}.{alias.name}") is not None:
                # `from .tools import cors` names the submodule `tools/cors.py`
                # when the package declares no `cors` of its own.
                target = ref("module", self.index.canonical_module(f"{spelled}.{alias.name}"))
                self.facts.add_edge(
                    "imports", self.module_id, target, node, self.import_attributes({"relative_level": node.level})
                )
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

        if isinstance(value, ast.BoolOp) and isinstance(value.op, ast.Or):
            # `injected or Default()`: the attribute holds one class when every
            # side that can be truthy names the same one.
            sides = {self.held_class(side) for side in value.values if not is_none(side)}
            return sides.pop() if len(sides) == 1 else None
        for candidate in (optional_inner(annotation), value.func if isinstance(value, ast.Call) else None):
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
