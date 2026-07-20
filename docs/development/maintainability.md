# Maintainability ratchets

The fast quality profile publishes `coverage/quality/maintainability.json` and
fails when any checked-in budget in `maintainability-budgets.json` regresses.
The report covers all first-party PHP, TypeScript/JavaScript, and Python runtime
sources.

Current monotonic budgets are:

- no more than 18 normalized cross-file duplicate blocks;
- PHP function cyclomatic complexity no higher than 64 and function length no
  higher than 275 lines;
- TypeScript/JavaScript function complexity no higher than 29 and function
  length no higher than 101 non-comment lines through ESLint;
- Python McCabe complexity no higher than 20, with branch and statement limits
  enforced by Ruff;
- direct per-file dependency fanout no higher than 9.

The initial duplicate-process refactor moved timeout, byte-limit, exit, pipe,
and cleanup behavior from two Git providers into one typed `GitProcessRunner`.
This reduced detected cross-file duplicate windows from 24 to 18 while keeping
the two provider contracts and their deterministic tests unchanged.

After the CLI dispatcher, query service, scan orchestration, and MCP tool
registry decompositions, the PHP limits were tightened from complexity 126 to
64, function length from 462 to 275, and dependency fanout from 25 to 9. The
current maxima are `ArchitecturePolicyQueryService::suggestLocation` for both
PHP function metrics.

These maxima are ratchets, not design targets. New or changed functions should
stay substantially below them. Further decomposition of the large query
algorithms should lower the checked-in limits again; raising a limit requires
before/after evidence and an explicit review. PHPStan, ESLint,
Ruff/mypy, and compiler syntax gates run before the report, so unused variables,
unreachable Python paths, and invalid typed dependencies cannot be hidden by
the metric report.

Generated dependencies, fixtures, and tests are excluded from product-code
metrics. Duplicate blocks shorter than eight logical lines or 160 normalized
characters are omitted to avoid treating ordinary language structure as a
refactoring target.
