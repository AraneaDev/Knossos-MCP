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
