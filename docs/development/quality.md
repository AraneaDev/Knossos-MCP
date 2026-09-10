# Quality gates

Knossos uses one versioned quality profile locally, in Git hooks, and in CI.
The recommended command is container-backed, so host tool versions do not
affect results:

```sh
tools/quality-container fast
tools/quality-container full
```

## Lanes

The gate is one linear script, and locally it runs as one. CI splits it into
six lanes that run at the same time, because most of the work does not depend
on the rest of it. Both commands take an optional second argument naming a
lane, so a lane that failed in CI can be reproduced here instead of only in the
workflow:

```sh
tools/quality-container full release
tools/quality full static
```

| lane       | holds                                                                                                     |
| ---------- | --------------------------------------------------------------------------------------------------------- |
| `static`   | linters, PHP-CS-Fixer, PHPStan, Ruff, mypy, ShellCheck, Hadolint, the documentation and repository checks |
| `tests`    | the PHPUnit suite, the worker's vitest suite, pytest, scanner conformance                                 |
| `rust`     | `cargo fmt`, `clippy` and `test`, which are slow and self-contained                                       |
| `release`  | audits, supply chain, benchmark, release lifecycle, the runtime image                                     |
| `coverage` | the pcov run and the coverage floors                                                                      |
| `gate`     | Knossos scanned by Knossos, held to the budgets in `knossos.json`                                         |

Omitting the argument runs every lane, which is the local default.

The split is by independence and not by language, and coverage is why. A single
PHPUnit run under pcov produces the PHP, JavaScript **and** Python figures, the
latter two from worker subprocesses that run drives. Splitting coverage per
language would measure three suites that never exercise the workers and report
floors nothing earns.

### What the `gate` lane enforces

The lane scans this repository twice into a throwaway database, then holds the
second scan to the budgets in `knossos.json`. Nothing ran those budgets before,
so they were advisory: between 2026-08-02 and 2026-09-10 `unreferenced_candidates`
drifted from 68 to 158 against a limit of 110 and no run said so.

Its baseline is this same tree, which makes the two delta budgets inert here.
`new_cycles` and `hub_degree_growth` are both `max(0, after - before)`, and two
scans of one tree cannot differ, so they can only report 0. They are still
passed to the gate rather than dropped, because `knossos.json` is the one place
the limits are written and a second CI-only copy would drift from it.

What the lane really enforces is the four absolute budgets, which are also the
ones that caught the drift above: `boundary_violations`, `error_diagnostics`,
`warning_diagnostics` and `unreferenced_candidates`. Each is computed from the
active snapshot alone.

Making the deltas meaningful needs a baseline scanned from the merge base, and
the image cannot produce one: it is built with `.git` excluded, so no history
reaches it. That would take mounting the runner's checkout and fetching enough
depth to reach the base ref. A lane that enforces four budgets honestly is
worth more than one that appears to enforce six.

## How CI runs it

One job builds the quality image and pushes it to the repository's registry,
tagged by commit, and the six lanes pull it. An aggregating job named
`quality` fails unless the whole matrix succeeded, which is the check branch
protection requires: a lane that is skipped or cancelled fails it just as a red
lane does.

Measured on the run this arrangement replaced, the gate was a single job taking
about ten minutes, of which every lane would have spent between 60 and 138
seconds merely acquiring the image. Pulling from a registry in the same
datacentre costs about 44.

External link checking is the one thing a pull request does not do. It asks
whether a web server is answering right now, which is not a property of the
change under review. A pull request touching only documentation once failed
while the site the README badge points at was mid-deploy, and that site is
ours: a link check on the critical path turns every deploy anywhere into a red
build here. It runs on `main` and on the schedule, where a failure informs
rather than blocks. Internal link checking is unaffected: that runs in the
`static` lane and is entirely ours to keep true.

A release-please pull request runs `static` alone. Its diff is version files, a
manifest and a changelog entry, so every other lane would re-verify code
identical to the `main` it was cut from, which had just passed. The full matrix
runs again on the push to `main` after it merges, so nothing reaches a tag
unchecked.

`fast` runs dependency/lock integrity, PHP syntax, PHP-CS-Fixer, PHPStan,
ESLint, Prettier, markdownlint, Ruff, mypy, JSON/large-file/line-ending/secret
checks, pre-commit configuration validation, ShellCheck, Hadolint, and all
tests (the PHPUnit suite plus the TypeScript worker's vitest suite). `full` additionally runs Composer/npm security audits, the pinned MCP
Inspector smoke, a clean runtime build, `doctor`, performance budgets, and the
coverage floors in [coverage policy](coverage.md). Mutation score is measured
on demand rather than in a profile -- see
[adversarial testing](adversarial-testing.md).

## Tool inventory

| Surface                      | Enforced tools                                                                 |
| ---------------------------- | ------------------------------------------------------------------------------ |
| PHP                          | PHP syntax, PHP-CS-Fixer 3.95.12, PHPStan 2.2.5                                |
| JavaScript/TypeScript worker | ESLint 10.7.0, Prettier 3.9.5, TypeScript compiler checks, vitest suite        |
| Python worker                | Ruff 0.15.12, mypy 2.3.0, isolated compile/runtime tests                       |
| Markdown                     | Prettier, markdownlint-cli2 0.23.0                                             |
| JSON/JSONC/YAML              | Prettier, strict JSON decode, pre-commit JSON/YAML checks                      |
| Shell                        | ShellCheck 0.9.0                                                               |
| Dockerfile                   | Hadolint 2.14.0, digest-pinned base images, clean build/doctor                 |
| Dependencies                 | Composer validation/audit, npm lock integrity/audit                            |
| Repository hygiene           | private-key/access-key patterns, 2 MB file cap, conflict markers, line endings |
| MCP contract                 | test suite plus Inspector 0.21.2 `tools/list` smoke                            |
| Adversarial testing          | fixed-seed properties/fuzz, differential scans, semantic mutation score        |
| Mutation testing             | Infection 0.31 over `src`, manual workflow (not a profile gate)                |
| Performance                  | mixed-language cold/incremental/query/RSS/SQLite budgets                       |
| Documentation                | generated CLI/MCP contracts plus internal and scheduled external link checks   |
| Supply chain                 | CycloneDX SBOMs, Trivy runtime/config gates, provenance, Cosign verification   |
| Maintainability              | per-file size/decision metrics and normalized cross-file duplication report    |

## The PHP test suite

`tests/phpunit/` is the single PHP suite, run by `composer test`. It drives every
language through one runner that spawns the TypeScript and Python workers as
subprocesses, so worker behaviour is proven end-to-end from PHP. The TypeScript
worker additionally has a focused vitest suite (`workers/typescript/src/__tests__/`,
run via `npm --prefix workers/typescript run check`) for scanner internals that
are awkward to reach through the protocol. Groups mirror the architecture areas
and are selectable with `vendor/bin/phpunit --group=store`.

Because the suite is a real PHPUnit suite, [Infection](https://infection.github.io/)
can mutate all of `src`, and Chaos-MCP's `audit_code_resilience` works against
this repository unmodified. Mutation testing is not part of any `tools/quality`
profile -- a full-src run takes over an hour -- so it runs on demand via
`.github/workflows/mutation.yml`. See
[adversarial testing](adversarial-testing.md).

There are currently no standalone YAML configuration files beyond the quality
workflow and hook configuration, and no release packaging manifest. Those
categories are validated by the generic YAML and repository checks; a dedicated
release validator becomes applicable when packaging is introduced. Actionlint
is not bundled because the workflow deliberately contains no expressions or
custom action inputs; YAML parsing plus execution of the same container command
provides the current useful gate.

Hadolint rule `DL3008` is narrowly disabled: Debian package revisions come from
the digest-pinned Bookworm image and exact apt revision pins would prevent base
image security rebuilds. `DL3002` applies only to the development quality stage,
which needs the mounted Docker socket; the shipped runtime remains unprivileged.
`DL3059` preserves separate Composer/npm cache layers. No source-analysis
cache layers. `SC2086` applies only to the intentional package-list expansion
of the base image's `PHPIZE_DEPS`. No source-analysis baseline or blanket file
exclusion is used.

The development-only quality stage requests Docker API 1.44 when it performs
its nested clean-image verification. This keeps Bookworm's pinned Docker CLI
compatible with current daemons whose minimum supported API is 1.44; it does
not affect the shipped runtime image.

## Hooks

Install pinned pre-commit 4.6.0, then run:

```sh
tools/install-hooks
```

The commit hook runs hygiene hooks and the fast profile. The pre-push hook runs
the full profile. Both run every lane. Developers without native tools can run
the container-backed commands before committing; CI always uses the quality
image.

## Maintenance

Upgrade one ecosystem at a time, regenerate its lockfile, rebuild the quality
image, and run the full profile. Tool suppressions require a specific rationale
in this document. Generated dependency directories and coverage output are not
committed. Security audits depend on current registry advisory data and should
also run on a schedule in the repository host.
