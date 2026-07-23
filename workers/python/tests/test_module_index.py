"""Filesystem-backed module resolution: source roots, mapping, collisions."""

from __future__ import annotations

from types import ModuleType


def test_module_name_strips_source_root_segments(worker: ModuleType) -> None:
    assert worker.module_name("app/models.py") == "app.models"
    assert worker.module_name("src/app/models.py", 1) == "app.models"
    assert worker.module_name("shop/__init__.py") == "shop"
    assert worker.module_name("main.py") == "main"
    assert worker.module_name("__init__.py") == "__root__"


def test_source_roots_include_bare_root_and_non_package_dirs(worker: ModuleType, project) -> None:
    root = project(
        {
            "src/app/__init__.py": "",
            "src/app/models.py": "class User: ...\n",
            "pkg/__init__.py": "",
        }
    )
    index = worker.ProjectModuleIndex(root, 2_000_000)
    # `src` has no __init__.py -> source root; `pkg` is a package -> not a root.
    assert () in index.prefixes
    assert ("src",) in index.prefixes
    assert ("pkg",) not in index.prefixes


def test_module_for_prefers_deepest_source_root(worker: ModuleType, project) -> None:
    root = project(
        {
            "src/app/__init__.py": "",
            "src/app/models.py": "class User: ...\n",
            "pkg/__init__.py": "",
        }
    )
    index = worker.ProjectModuleIndex(root, 2_000_000)
    # src-layout: the `src` prefix wins over the bare root.
    assert index.module_for("src/app/models.py") == "app.models"
    # Top-level package keeps its full dotted path.
    assert index.module_for("pkg/__init__.py") == "pkg"


def test_module_file_prefers_package_over_module(worker: ModuleType, project) -> None:
    root = project(
        {
            "shop/__init__.py": "",
            "shop/service.py": "class Gateway: ...\n",
            # A module `orders.py` and a package `orders/` collide on id `orders`.
            "orders.py": "x = 1\n",
            "orders/__init__.py": "y = 2\n",
        }
    )
    index = worker.ProjectModuleIndex(root, 2_000_000)
    assert index.module_file("shop.service") == root / "shop" / "service.py"
    # The package __init__.py is preferred over the same-named module file.
    assert index.module_file("orders") == root / "orders" / "__init__.py"
    assert index.module_file("does.not.exist") is None


def test_module_file_rejects_oversized_files(worker: ModuleType, project) -> None:
    root = project({"big.py": "x = 1\n" * 100})
    index = worker.ProjectModuleIndex(root, 3)
    assert index.module_file("big") is None


def test_module_declarations_are_parsed_and_memoized(worker: ModuleType, project) -> None:
    root = project(
        {
            "shop/__init__.py": "",
            "shop/service.py": "class Gateway:\n    pass\n\ndef helper():\n    pass\n\nCONST = 1\n",
        }
    )
    index = worker.ProjectModuleIndex(root, 2_000_000)
    declarations = index.module_declarations("shop.service")
    assert declarations["Gateway"] == "py:class:shop.service.Gateway"
    assert declarations["helper"] == "py:function:shop.service.helper"
    assert "CONST" not in declarations  # module-level variables are not declarations
    # Memoized: the same dict object is returned on the second call.
    assert index.module_declarations("shop.service") is declarations


def test_module_declarations_tolerate_broken_targets(worker: ModuleType, project) -> None:
    root = project({"broken.py": "def (:\n"})
    index = worker.ProjectModuleIndex(root, 2_000_000)
    assert index.module_declarations("broken") == {}


def test_collides_detects_module_and_package_pairs(worker: ModuleType, project) -> None:
    root = project(
        {
            "orders.py": "x = 1\n",
            "orders/__init__.py": "y = 2\n",
            "lone.py": "z = 3\n",
        }
    )
    index = worker.ProjectModuleIndex(root, 2_000_000)
    assert index.collides(root / "orders.py", is_package=False) is True
    assert index.collides(root / "orders" / "__init__.py", is_package=True) is True
    assert index.collides(root / "lone.py", is_package=False) is False
