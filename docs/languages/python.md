# Python

Knossos scans Python through the same out-of-process scanner protocol as the
other languages. The worker needs Python 3.11 or newer. The bundled worker uses
only the standard-library `ast` module and starts with the isolated,
bytecode-disabled flags `-I -B`, plus `-W ignore::SyntaxWarning` so that the
parser's warnings about invalid escapes in your source stay off stderr. It
never imports or runs your project.

## What the scanner reads

- `pyproject.toml`: the project unit and its name, PEP 621 dependencies and
  optional dependencies, Poetry's `[tool.poetry.dependencies]`,
  `dev-dependencies` and `group.<name>.dependencies` tables, and the entry
  points under `[project.scripts]` or `[tool.poetry.scripts]`
- `requirements.txt` and `requirements-*.txt`, for projects that keep
  dependencies outside `pyproject.toml`
- `.py` source files and `.pyi` stubs
- packages, identified by `__init__.py`
- ordinary and relative imports, with their aliases. An import under
  `if TYPE_CHECKING:` is type-only: it runs for the type checker only, so a
  cycle closed by nothing but such imports is not reported, unless the same
  module is also imported at runtime
- the source roots your packages live in: `src/` when it holds no
  `__init__.py`, and the directories `pyproject.toml` declares through
  setuptools (`packages.find.where`, the `""` entry of `package-dir`), Poetry
  (`packages[].from`), Hatch (wheel `packages`) or PDM (`build.package-dir`)

A console script such as `shop.cli:main` maps to the exact path `shop/cli.py`,
and a `.py` file listed in `[tool.vulture] paths` (a whitelist vulture reads as
source) is an entry point too. The mapping only applies when that path produced
a scanner node, so a manifest cannot invent an entry point.

Discovery skips `.venv`, `venv`, `__pycache__`, `.tox`, `.mypy_cache` and
`.pytest_cache` by default, along with the other directories every language
skips.

## Module names

A module is named by its dotted path below the source root it sits in, and
the bare project root is always the first source root. So `src/shop/cart.py`
in a src layout is `shop.cart`, while `app/models/user.py` is
`app.models.user` whether or not `app/` holds an `__init__.py`: any other
top-level directory is a package or a namespace package, not a source root.

An import names the module by the file it finds, so `import shop.cart` and
`import src.shop.cart` both reach `shop.cart`. A bare import in a script, or
in a module whose directory holds no `__init__.py` (such as a test module
pytest runs), also finds the file beside it, because that directory is first
on `sys.path` when it runs.

Two files never share a module id. When an import of a file's name finds
another file (`utils.py` at the root and `src/utils.py`, or `mod.py` beside a
`mod/` package), the file the import finds keeps the name and the other one is
named by its path, `utils.<src/utils.py>`, with a `PY_MODULE_ID_COLLISION`
warning. A stub beside its module (`mod.pyi` beside `mod.py`) is named the
same way, `mod.<mod.pyi>`, without a warning. A stub with no module beside it,
such as the stub of an extension module, is the module an import finds, so
its names resolve. Every symbol of a `.pyi` file is a declaration that the
dead-code analysis leaves out.

## What ends up in the graph

The worker emits modules, packages, classes, functions, methods, containment,
imports, inheritance and the calls it can resolve statically. Async status and
decorator names are kept as node attributes. A reference to a function or class
that is not a call, such as one passed as an argument, becomes a `references`
edge.

A cross-file reference resolves when the declaration is in the same scan
request. Anything else stays an explicit unresolved or external fact.

Some code is never called or imported by name. The scanner marks it, so it stays
off the [dead-code candidates](../concepts/dead-code-candidates.md) list:

- A module with a shebang, or with an `if __name__ == "__main__":` guard, is
  `executable`. So is a module that builds a served app at module level:
  `FastAPI()`, `Flask(__name__)`, Starlette, Quart, Litestar, or Django's
  `get_wsgi_application()` and `get_asgi_application()`. A server such as
  uvicorn loads that module by name.
- A function a decorator hands to an object (`@queue.register("scan")`,
  `@bus.on`) is `runtime_invoked`.
- A module whose file name no import can spell (`029_seed.py`) is
  `runtime_invoked`, together with its public top-level functions, because a
  loader reads it by path.

A name a package imports is an attribute of that package, so
`config.staging_dir()` reaches the submodule that `config/__init__.py`
re-exports it from. A class declared under `if TYPE_CHECKING:` is a module-level
name.

## Frameworks

The worker recognizes a framework from what a file imports. It reads no
framework hint from the request, so recognizable code is enriched even when the
dependency metadata is incomplete or absent.

| Framework | Recognized source                                                                                                                                                                                                   | Graph facts                                                                                                                                             |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| FastAPI   | `FastAPI` and `APIRouter` objects, HTTP decorators, `Depends` in parameters and decorator lists, `include_router`, `add_middleware`                                                                                 | `route` nodes, `routes_to`, `depends_on`, `mounts` and `uses_middleware` edges, and the `fastapi.route_handler` role                                    |
| Django    | `path` and `re_path` lists in `urlpatterns`, function and class-based views, `models.Model` bases, `__call__` middleware, `INSTALLED_APPS`, `MIDDLEWARE`, `ROOT_URLCONF`, `ASGI_APPLICATION` and `WSGI_APPLICATION` | `route` and `setting` nodes, `routes_to` and `configures` edges, and the `django.model`, `django.view` and `django.middleware` roles                    |
| Flask     | `Flask` and `Blueprint` objects, `@app.route` and `@blueprint.route`, `register_blueprint`, `add_url_rule`, `MethodView` subclasses                                                                                 | `route` nodes, `routes_to` and `mounts` edges, with every method of a multi-method decorator kept, and the `flask.view` and `flask.route_handler` roles |
| Celery    | `task` and `shared_task` decorators                                                                                                                                                                                 | the `python.task` role                                                                                                                                  |

Blueprint prefixes and route paths combine only when both are literals in the
same file, for a decorated route and for `add_url_rule` alike. An `APIRouter`
or `Blueprint` assigned to a name at module level is a `router` node,
`py:router:<module>.<name>`, and a `mounts` edge from `include_router` or
`register_blueprint` reaches the router node it names, in the same file or
imported from another module. A router built inside a function belongs to each
call of it, so it has no node, though routes declared on it keep its prefix. A
router handed in as a parameter has no node of its own either, so its mount is
kept only when its target resolves. One module mounting one router twice keeps
one `mounts` edge, with the prefix of the first mount: edges are unique by kind,
source and target.

## Limits

- Dynamic imports, monkey-patching, runtime decorator effects, metaclass
  behavior and dynamically selected call targets are not executed or inferred. A
  decorator name is structural evidence, not a claim about what it does at
  runtime.
- A dynamic route path is skipped with `PY_DYNAMIC_ROUTE_PATH`. That includes
  Flask converter paths such as `/users/<int:user_id>`.
- Django settings are limited to the five names above with statically literal
  values, and a settings module is never imported. FastAPI dependency callables
  are never invoked.
- A syntax error produces `PY_SYNTAX_ERROR` for that file, a file the worker
  cannot read or that is oversized produces `PY_UNSCANNABLE_FILE`, and neither
  stops the other files from contributing.
- A relative import that climbs above the project root emits no edge and reports
  `PY_UNRESOLVED_RELATIVE_IMPORT`. A module file and a package that share one
  module id both report `PY_MODULE_ID_COLLISION`; the package keeps the id.
