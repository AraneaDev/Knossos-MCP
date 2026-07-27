# Contributing to Knossos MCP

## Development Setup

### Prerequisites

The supported path is Docker — it pins PHP 8.4, Node 24, Python 3.11, Composer,
SQLite, and every linter the quality profile runs, so you need none of them on
the host:

- **Docker** with the Compose plugin
- **Git**

Running the tooling directly on the host additionally needs PHP >= 8.3 (<8.5)
with `ext-json`, `ext-pdo`, and `ext-pdo_sqlite`, Node.js 24, Python 3.11, and
Composer.

### Setup

```bash
git clone https://github.com/AraneaDev/Knossos-MCP.git
cd Knossos-MCP
docker build -t knossos-mcp:dev .
docker run --rm knossos-mcp:dev doctor --json
```

## The `tools/quality-container` Pipeline

All contributions must pass the quality profile before being merged. One
versioned profile runs locally, in Git hooks, and in CI — there is no separate
CI-only configuration:

```bash
tools/quality-container fast    # what you run while iterating
tools/quality-container full    # what CI runs
```

| Profile | Covers                                                                                                                                                                                                                                                        |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `fast`  | Dependency validation, PHP/JS/Markdown/Python linting, PHP-CS-Fixer, PHPStan, formatting, repository hygiene, generated-reference and documentation checks, maintainability budgets, and the full PHPUnit test suite                                          |
| `full`  | Everything in `fast`, plus security audits, external documentation-link checks, MCP Inspector tool listing, runtime image build and `doctor`, release lifecycle, supply-chain assurance (SBOM, CVE gates, signed provenance), benchmarks, and coverage floors |

`full` is the gate — see [quality gates](docs/development/quality.md) for what
each stage asserts and how to read its report.

### Install the Git hooks

```bash
tools/install-hooks
```

## Test Suite Invariants

Two rules exist because breaking either one silently disables mutation testing
for the whole repository, with an error message that points somewhere else
entirely. `.github/workflows/mutation.yml` and Chaos-MCP's PHP engine both drive
`vendor/bin/infection --filter=<file>`.

**The suite must write nothing to STDERR.** Verify with:

```bash
vendor/bin/phpunit 2>/tmp/suite.err && test ! -s /tmp/suite.err
```

Infection's `InitialTestsRunner` stops the test process on the _first byte_ it
writes to STDERR (Symfony `Process::ERR`), whether or not a test failed. PHPUnit
is then SIGTERMed mid-suite and Infection reports `PHPUnit reported an exit code
of 143` under a "Project tests must be in a passing state" banner — pointing at
the suite's health and the coverage driver, neither of which is the cause. Code
that emits diagnostics must take an injectable stream (see
`Knossos\Cli\CliErrorRenderer`) so tests can render into `php://memory` and
assert the output instead of leaking it.

**No test may carry a coverage-target attribute** (`#[CoversClass]`,
`#[UsesClass]`, and friends). `--filter` narrows the generated initial-run
PHPUnit config's `<source>` to the single target file, which invalidates every
coverage target pointing elsewhere; Infection injects `stopOnDefect="true"` into
that same config, so the resulting warning aborts the suite.
`tests/phpunit/NoCoverageTargetAttributesTest` enforces this. Express targeted
coverage through `<source>` in `phpunit.xml` instead.

## Commit Messages

This repository releases through
[release-please](https://github.com/googleapis/release-please), which reads
[Conventional Commits](https://www.conventionalcommits.org/) to decide the next
version and to write `CHANGELOG.md`. Commit messages are therefore part of the
release process, not just documentation:

| Prefix                                         | Effect                  |
| ---------------------------------------------- | ----------------------- |
| `fix:`                                         | Patch release           |
| `feat:`                                        | Minor release           |
| `feat!:` / `BREAKING CHANGE:` footer           | Major release           |
| `docs:`, `chore:`, `test:`, `refactor:`, `ci:` | No release on their own |

A scope is encouraged — `fix(query): …`, `feat(scanner): …`.

The **pull request title** follows the same rules, and on a branch with more
than one commit it matters more than the commits do: a squash merge uses the PR
title as the subject of the single commit that lands on `main`, and that subject
is what release-please reads. A title like `Fix/my branch name` classifies as
nothing, so the release it should have cut is skipped silently — the individual
commit subjects survive only as body text, which release-please does not parse.
The `PR Title` workflow rejects a non-conforming title before it can merge.

## Releases

Releases are automated. Merging to `main` opens or updates a Release PR that
accumulates the changelog and the next version number. Merging **that** PR cuts
the tag and GitHub Release, and re-runs the full quality profile against the
released tree.

The version lives in `version.txt` and is mirrored into `src/Application.php`
and the `Dockerfile` image label by release-please. Do not bump those by hand.

## Adding a Language Scanner

Scanners are out-of-process workers that speak a versioned NDJSON protocol; they
never install dependencies, import a module, or boot an application framework.
Start from the [scanner SDK](docs/reference/scanner-sdk.md) and validate with
`tools/scanner-conformance`.
