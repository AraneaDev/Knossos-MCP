"""Shared fixtures for the Python scanner worker unit suite.

The worker is an executable script (``bin/worker.py``) whose NDJSON loop only
runs under ``if __name__ == "__main__"``. These tests import it as a module so
the pure functions and collectors can be exercised in isolation.
"""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path
from types import ModuleType

WORKER_PATH = Path(__file__).resolve().parents[1] / "bin" / "worker.py"


def _load_worker() -> ModuleType:
    spec = importlib.util.spec_from_file_location("knossos_python_worker", WORKER_PATH)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


import pytest  # noqa: E402


def patch_everywhere(monkeypatch: pytest.MonkeyPatch, worker: ModuleType, name: str, value: object) -> None:
    """Replace ``name`` in the worker and in every ``knossos_python`` module bound to the same object.

    The worker's analysis lives in the package beside it, and each module binds
    what it imports under its own name, so a stand-in set on the worker alone
    would not reach a caller in another module.
    """
    original = getattr(worker, name)
    package = [module for key, module in sys.modules.items() if key.split(".")[0] == "knossos_python"]
    for module in [worker, *package]:
        if getattr(module, name, None) is original:
            monkeypatch.setattr(module, name, value)


@pytest.fixture(scope="session")
def worker() -> ModuleType:
    return _load_worker()


@pytest.fixture
def project(tmp_path: Path):
    """Materialize a ``{relative: contents}`` map into a fresh project root."""

    def build(files: dict[str, str]) -> Path:
        for relative, contents in files.items():
            path = tmp_path / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(contents, encoding="utf-8")
        return tmp_path

    return build


@pytest.fixture
def scan_collect(worker: ModuleType):
    """Run ``worker.scan`` and return the emitted contributions in order."""

    def run(root: Path, files: list[str], limits: dict | None = None) -> list[dict]:
        emitted: list[dict] = []
        params: dict = {"root": str(root), "files": files}
        if limits is not None:
            params["limits"] = limits
        worker.scan(params, emitted.append)
        return emitted

    return run
