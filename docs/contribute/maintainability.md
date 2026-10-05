# Maintainability ratchets

The `static` lane publishes `coverage/quality/maintainability.json` and
fails when any checked-in budget in `maintainability-budgets.json` regresses.
The report covers all first-party PHP, TypeScript/JavaScript, and Python runtime
sources. The Rust worker is the one first-party runtime outside it: `tools/quality` runs formatting, Clippy with `-D warnings`, and tests only when `cargo` is available; missing Cargo skips these checks outside the quality container, while the quality container treats its absence as a failure.

The budgets in `maintainability-budgets.json` are:

- no more than 13 normalized cross-file duplicate blocks;
- PHP function cyclomatic complexity no higher than 60 and function length no
  higher than 205 lines;
- direct per-file dependency fanout no higher than 10;
- docstring coverage of 100% for `src`, `workers-php`, `tools` and overall, and
  type-docblock coverage of 100%.

The TypeScript/JavaScript and Python limits sit in the linters' own
configuration and fail there: ESLint holds function complexity to 29 and function
length to 101 non-comment lines, and Ruff holds McCabe complexity to 20, with its
branch and statement limits.

These maxima are ratchets, not design targets. New or changed functions should
stay substantially below them. When a decomposition lowers the real maximum,
lower the checked-in limit with it. Raising a limit requires before/after
evidence and an explicit review. PHPStan, ESLint,
Ruff/mypy, Clippy, and compiler syntax gates run before the report, so unused
variables, unreachable Python paths, and invalid typed dependencies cannot be
hidden by the metric report.

Generated dependencies, fixtures, and tests are excluded from product-code
metrics. Duplicate blocks shorter than eight logical lines or 160 normalized
characters are omitted to avoid treating ordinary language structure as a
refactoring target.
