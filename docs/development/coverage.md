# Coverage policy

Run the reproducible coverage profile with:

```sh
tools/quality-container full
```

Inside the pinned quality image, `tools/coverage` is the shorter coverage-only
entrypoint. The container wrapper mounts `coverage/` back into the checkout;
CI uploads that directory as the `coverage-reports` artifact.

## Enforced floors

| Runtime                  | Line/statement floor | Branch floor | Current result                |
| ------------------------ | -------------------- | ------------ | ----------------------------- |
| PHP core and PHP scanner | 91%                  | n/a          | 91.04% lines                  |
| TypeScript/JavaScript    | 94.6%                | 79.2%        | 94.68% lines, 79.27% branches |
| Python scanner           | 96%                  | tracked      | 96% combined report           |

PHP uses PCOV, which records executable-line coverage but not branch coverage.
The JavaScript V8 report therefore carries the explicit ratcheted branch floor;
Python branch data is collected and included in coverage.py's enforced total.
A well-covered runtime cannot hide another because all three gates must pass.

PHP additionally enforces checked-in component floors from
`coverage-budgets.json`. The current floors are 87% bundle/Git/watch, 87.5%
discovery/configuration, 87.7% maintenance/runtime, 90.6% PHP scanner, 92.6%
query/analysis, 90.6% reconciliation, 90.6% scanner runtime, 92.2% storage, and
92.1% transport. The TypeScript and Python scanner floors remain independent
runtime gates. Floors may only move upward unless a reviewed risk exception is
documented with before/after evidence.

Transport briefly ran under a lowered 90.3% floor on 2026-07-20: the MCP
enrichment work (`ResultEnricher`, `NextStepPlanner`, per-tool verbosity, and
result metadata) had landed without matching tests, taking the component to
90.37% (582/644), and the shortfall stayed hidden because an unrelated
`tools/supply-chain` failure was aborting the `full` profile before
`tools/coverage` ran. Covering the planner's null-guard branches and the session
store's malformed-id guard restored it to 92.24% (594/644) the same day, so the
92.1% floor is back and the exception is closed.

The only first-party exclusion is `src/Application.php`: it is a constant-only
CLI composition/dispatch adapter, while its invoked commands and services are
covered through the CLI, MCP, and service tests. Vendor code, installed
dependencies, generated reports, and test fixtures are outside the measured
first-party source sets. New exclusions require a documented rationale and a
reviewed configuration change.

## Reports

- PHP: `coverage/php/summary.json` plus console per-file and per-component line
  reports.
- TypeScript/JavaScript: `coverage/js/lcov.info`,
  `coverage/js/cobertura-coverage.xml`, and `coverage/js/index.html`.
- Python: `coverage/python/cobertura.xml` and
  `coverage/python/html/index.html`.

Add regression tests at the lowest useful layer, then run `composer test` for
fast feedback and the container coverage profile before pushing. `composer test`
runs the single PHPUnit suite (`vendor/bin/phpunit`); the coverage profile runs
it under the pcov prepend. Tests should assert behavior at protocol and safety
boundaries; touching a line without an observable assertion is not sufficient.
Threshold reductions or exclusions must include the before/after report and an
explicit risk rationale.
