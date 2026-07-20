# Quality gates

Knossos uses one versioned quality profile locally, in Git hooks, and in CI.
The recommended command is container-backed, so host tool versions do not
affect results:

```sh
tools/quality-container fast
tools/quality-container full
```

`fast` runs dependency/lock integrity, PHP syntax, PHP-CS-Fixer, PHPStan,
ESLint, Prettier, markdownlint, Ruff, mypy, JSON/large-file/line-ending/secret
checks, pre-commit configuration validation, ShellCheck, Hadolint, and all
tests. `full` additionally runs Composer/npm security audits, the pinned MCP
Inspector smoke, a clean runtime build, `doctor`, performance budgets, and the
coverage floors in [coverage policy](coverage.md). Mutation score is measured
on demand rather than in a profile -- see
[adversarial testing](adversarial-testing.md).

## Tool inventory

| Surface                      | Enforced tools                                                                 |
| ---------------------------- | ------------------------------------------------------------------------------ |
| PHP                          | PHP syntax, PHP-CS-Fixer 3.95.12, PHPStan 2.2.5                                |
| JavaScript/TypeScript worker | ESLint 10.7.0, Prettier 3.9.5, TypeScript compiler checks                      |
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
subprocesses, which is why the repository needs no vitest or pytest suite to
prove the workers behave. Groups mirror the architecture areas and are selectable
with `vendor/bin/phpunit --group=store`.

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
the full profile. Developers without native tools can run the container-backed
commands before committing; CI always uses the quality image.

## Maintenance

Upgrade one ecosystem at a time, regenerate its lockfile, rebuild the quality
image, and run the full profile. Tool suppressions require a specific rationale
in this document. Generated dependency directories and coverage output are not
committed. Security audits depend on current registry advisory data and should
also run on a schedule in the repository host.
