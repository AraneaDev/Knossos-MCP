# Maintainability ratchets

The fast quality profile publishes `coverage/quality/maintainability.json` and
fails when any checked-in budget in `maintainability-budgets.json` regresses.
The report covers all first-party PHP, TypeScript/JavaScript, and Python runtime
sources.

Current monotonic budgets are:

- no more than 18 normalized cross-file duplicate blocks;
- PHP function cyclomatic complexity no higher than 126 and function length no
  higher than 462 lines;
- TypeScript/JavaScript function complexity no higher than 29 and function
  length no higher than 101 non-comment lines through ESLint;
- Python McCabe complexity no higher than 20, with branch and statement limits
  enforced by Ruff;
- direct per-file dependency fanout no higher than 25.

The initial duplicate-process refactor moved timeout, byte-limit, exit, pipe,
and cleanup behavior from two Git providers into one typed `GitProcessRunner`.
This reduced detected cross-file duplicate windows from 24 to 18 while keeping
the two provider contracts and their deterministic tests unchanged.

These maxima are ratchets, not design targets. New or changed functions should
stay substantially below them. Refactoring the existing CLI dispatcher and
large query algorithms should lower the checked-in limits; raising a limit
requires before/after evidence and an explicit review. PHPStan, ESLint,
Ruff/mypy, and compiler syntax gates run before the report, so unused variables,
unreachable Python paths, and invalid typed dependencies cannot be hidden by
the metric report.

Generated dependencies, fixtures, and tests are excluded from product-code
metrics. Duplicate blocks shorter than eight logical lines or 160 normalized
characters are omitted to avoid treating ordinary language structure as a
refactoring target.
