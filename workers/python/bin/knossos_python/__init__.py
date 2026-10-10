"""The Python worker's analysis, split out of ``bin/worker.py``, which keeps the protocol.

Loaded only by ``worker.py``, which puts this directory's parent on ``sys.path``
itself, because the core runs the worker under ``python3 -I`` and the script's own
directory is then not on it."""
