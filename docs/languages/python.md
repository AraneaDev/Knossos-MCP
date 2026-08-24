# Python scanner support

Knossos supports Python 3.11 and newer projects through the same versioned,
out-of-process scanner protocol used by the PHP and TypeScript workers. The
bundled worker uses only Python's standard-library `ast` module and starts with
isolated, bytecode-disabled interpreter flags. It never imports or runs the
target project.

## Discovered inputs

- `pyproject.toml` project units, names, PEP 621 dependencies and optional
  dependencies, Poetry's `[tool.poetry.dependencies]`, `dev-dependencies`, and
  `group.<name>.dependencies` tables, and `[project.scripts]` or Poetry
  `[tool.poetry.scripts]` entry points
- `requirements.txt` and `requirements-*.txt` dependency units for projects
  that keep dependencies outside `pyproject.toml`
- `.py` implementation files and `.pyi` stub files
- packages identified by `__init__.py`
- ordinary and relative imports, including aliases

A console script such as `shop.cli:main` is mapped to the exact source path
`shop/cli.py`. Classification only applies when that path produced a scanner
node, so a manifest token cannot invent an entry point.

Virtual environments and tool caches such as `.venv`, `venv`, `__pycache__`,
`.tox`, `.mypy_cache`, and `.pytest_cache` are ignored by default.

## Emitted facts

The worker emits evidence-backed modules, packages, classes, functions,
methods, containment, imports, inheritance, and statically resolvable calls.
Async status and decorator names are retained as node attributes. A module with
a shebang or an `if __name__ == "__main__":` guard is marked `executable`, which
keeps a script run by a shell or `python -m` off the dead-code report.
Cross-file references are resolved when their declarations are in the same
scan request; other references remain explicit unresolved or external graph
facts.

Framework enrichment is structural and import-free. The core detects common
framework dependencies from `pyproject.toml` and requirements files and passes
those hints to the worker. The worker also preserves its structural fallback,
so recognizable code remains enrichable when a project uses an incomplete
manifest or dependency metadata is absent.

- FastAPI `FastAPI`/`APIRouter` objects, HTTP decorators, typed async or sync
  handlers, `Depends` in parameters/decorator lists, router mounts, and explicit
  middleware produce routes and dependency, mount, and middleware edges.
- Django `path`/`re_path` lists, function and class-based views, model bases,
  callable middleware, and common settings produce route, role, and setting
  facts.
- Flask `Flask` and `Blueprint` objects, `@app.route` or `@blueprint.route`
  decorators, blueprint mounts, and `add_url_rule` calls produce route nodes
  and `routes_to` or `mounts` edges. Flask route methods are retained,
  including multiple methods on one decorator. `MethodView` subclasses receive
  the `flask.view` role and route handlers receive `flask.route_handler`.
- Celery-style `task`/`shared_task` decorators produce an explicit task role.

Dynamic route paths are diagnosed with `PY_DYNAMIC_ROUTE_PATH` instead of being
guessed. This includes Flask converter paths such as `/users/<int:user_id>`.
Results are deterministic and participate in the same contribution cache,
classification, boundary, and mixed-language reconciliation pipeline as other
scanners.

## Limits

Dynamic imports, monkey-patching, runtime decorator effects, metaclass behavior,
and dynamically selected call targets are not executed or inferred. Decorator
names are structural evidence, not a claim about their runtime behavior.
Settings are limited to statically literal architecture-relevant names; Django
settings modules are never imported, and FastAPI dependency callables are never
invoked. Blueprint prefixes and route paths are combined only when they are
literal values in the same file.

Syntax failures produce `PY_SYNTAX_ERROR` diagnostics for the affected file and
do not prevent other files from contributing. A malformed or oversized file
likewise costs only its own contribution.

## Verification

The worker is checked with `ruff`, `mypy`, its Python test suite, and the shared
scanner-conformance protocol check. The repository's PHP scanner integration
suite also drives the real worker process over NDJSON-RPC.
