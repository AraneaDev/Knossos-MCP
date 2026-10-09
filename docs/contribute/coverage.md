# Coverage policy

Run the reproducible coverage profile with:

```sh
tools/quality-container full
```

Inside the pinned quality image, `tools/coverage` is the shorter coverage-only
entrypoint. The container wrapper mounts `coverage/` back into the checkout;
CI uploads that directory as the `quality-reports` artifact.

## Running in parts

`tools/coverage` with no argument runs the suite under pcov and then the
reports. The two halves can also run on their own, which is how CI splits the
suite over several jobs:

| command                            | does                                                                 |
| ---------------------------------- | -------------------------------------------------------------------- |
| `tools/coverage run [--shard=I/N]` | the PHPUnit run only; with `--shard`, only the test files of shard I |
| `tools/coverage merge RAW_DIR`     | gathers the data of every shard under `RAW_DIR` into `coverage/`     |
| `tools/coverage report`            | the reports and every floor below, over what `coverage/` holds       |

`tools/phpunit-shard` picks the files of a shard: the suite's test files
sorted by name and dealt out in turn, so the split depends only on the file
names. The merge refuses unless every shard of the same N finished, left PHP
coverage data, and every test file ran in exactly one shard, the one it was
assigned to. Splitting cannot change a figure: pcov's data merges by the highest
hit count per line, and the JavaScript and Python data by union.

## Enforced floors

The floors live in `coverage-budgets.json`, and the number there is the
number that is enforced.

| Runtime                  | Line floor | Branch floor | Also enforced                       |
| ------------------------ | ---------: | -----------: | ----------------------------------- |
| PHP core and PHP scanner |      93.5% |          n/a | nine component floors, see below    |
| TypeScript/JavaScript    |        90% |        79.2% | 97% functions, 90% statements       |
| Python scanner           |        90% |      tracked | branch data is in the total         |
| Rust scanner             |      90.7% |          n/a | `cargo llvm-cov --fail-under-lines` |

PHP uses PCOV, which records executable-line coverage but not branch coverage.
The JavaScript V8 report therefore carries the explicit ratcheted branch floor,
and Python branch data is collected and included in coverage.py's enforced
total. Rust is measured by `cargo llvm-cov`. A well-covered runtime cannot hide
another, because all four gates must pass.

PHP also enforces a floor per component, so a well-covered area cannot carry a
neglected one:

| component             |  floor |
| --------------------- | -----: |
| `bundle-git-watch`    |  88.5% |
| `discovery-config`    | 95.63% |
| `maintenance-runtime` | 94.72% |
| `php-scanner`         | 93.62% |
| `query-analysis`      | 94.09% |
| `reconciliation`      | 98.49% |
| `scanner-runtime`     |    94% |
| `storage`             | 96.47% |
| `transport`           | 92.29% |

Floors may only move upward unless a reviewed risk exception is documented with
before/after evidence.

[Quality gates](quality.md#profiles-and-lanes) explains why coverage is one lane. The
Claude Code mod is covered by its own suites (see
[how the mod is built](mod-internals.md#the-tests)) and sits outside these
gates.

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
- Rust: `coverage/rust/coverage.json`, `coverage/rust/lcov.info`, and
  `coverage/rust/html/index.html`.

Add regression tests at the lowest useful layer, then run `composer test` for
fast feedback and the container coverage profile before pushing. `composer test`
runs the single PHPUnit suite (`vendor/bin/phpunit`); the coverage profile runs
it under the pcov prepend. Tests should assert behavior at protocol and safety
boundaries; touching a line without an observable assertion is not sufficient.
Threshold reductions or exclusions must include the before/after report and an
explicit risk rationale.
