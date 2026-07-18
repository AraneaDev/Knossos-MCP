# Python scanner support

Knossos supports Python 3.11–3.13 projects through the same versioned,
out-of-process scanner protocol used by the PHP and TypeScript workers. The
bundled worker uses only Python's standard-library `ast` module and starts with
isolated, bytecode-disabled interpreter flags. It never imports or runs the
target project.

## Discovered inputs

- `pyproject.toml` project units and project names
- `.py` implementation files and `.pyi` stub files
- packages identified by `__init__.py`
- ordinary and relative imports, including aliases

Virtual environments and tool caches such as `.venv`, `venv`, `__pycache__`,
`.tox`, `.mypy_cache`, and `.pytest_cache` are ignored by default.

## Emitted facts

The worker emits evidence-backed modules, packages, classes, functions,
methods, containment, imports, inheritance, and statically resolvable calls.
Async status and decorator names are retained as node attributes. Cross-file
references are resolved when their declarations are in the same scan; other
references remain explicit unresolved/external graph facts.

Framework enrichment is structural and import-free:

- FastAPI `FastAPI`/`APIRouter` objects, HTTP decorators, typed async or sync
  handlers, `Depends` in parameters/decorator lists, router mounts, and explicit
  middleware produce routes and dependency/mount/middleware edges.
- Django `path`/`re_path` lists, function and class-based views, model bases,
  callable middleware, and common settings produce route, role, and setting
  facts.
- Celery-style `task`/`shared_task` decorators produce an explicit task role.
- Dynamic route paths produce `PY_DYNAMIC_ROUTE_PATH` rather than a guessed
  route. Nested routers remain explicit mounts when their full runtime prefix
  cannot be safely assembled in one file.

Syntax failures produce `PY_SYNTAX_ERROR` diagnostics for the affected file and
do not prevent other files from contributing. Results are deterministic and
participate in the same contribution cache, classification, boundary, and
mixed-language reconciliation pipeline as other scanners.

## Limits

Dynamic imports, monkey-patching, runtime decorator effects, metaclass behavior,
and dynamically selected call targets are not executed or inferred. Decorator
names are structural evidence, not a claim about their runtime behavior.
Settings are limited to statically literal architecture-relevant names; Django
settings modules are never imported, and FastAPI dependency callables are never
invoked.
