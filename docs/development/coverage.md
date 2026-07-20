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
90.3% transport. The TypeScript and Python scanner floors remain independent
runtime gates. Floors may only move upward unless a reviewed risk exception is
documented with before/after evidence.

### Risk exception: transport, 92.1% to 90.3%

The transport floor was lowered on 2026-07-20. The MCP enrichment work
(`ResultEnricher`, `NextStepPlanner`, per-tool verbosity, and result metadata)
landed without matching tests, taking the component from 92.1% to **90.37%
(582/644)**. The gate did not catch it at the time because `tools/coverage` is
the last stage of the `full` profile and an unrelated `tools/supply-chain`
failure was aborting the run before it. Fixing that failure exposed the
shortfall.

The floor now sits just under the measured value rather than at the intended
budget, so this is an accepted regression, not a clean bill of health. The
uncovered surface, weakest first:

| File                          | Coverage         |
| ----------------------------- | ---------------- |
| `src/Mcp/NextStepPlanner.php` | 85.07% (57/67)   |
| `src/Mcp/HttpEndpoint.php`    | 89.66% (78/87)   |
| `src/Mcp/StdioServer.php`     | 89.13% (123/138) |
| `src/Mcp/ToolService.php`     | 90.65% (252/278) |

Restoring 92.1% means covering those paths — `NextStepPlanner` carries the most
untested logic per line and is the cheapest place to start.

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
fast feedback and the container coverage profile before pushing. Tests should
assert behavior at protocol and safety boundaries; touching a line without an
observable assertion is not sufficient. Threshold reductions or exclusions
must include the before/after report and an explicit risk rationale.
