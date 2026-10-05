# Quality gates

Knossos uses one versioned quality profile locally, in Git hooks, and in CI. The
recommended command is container-backed, so host tool versions do not affect
results:

```sh
tools/quality-container fast
tools/quality-container full
```

`tools/quality-container` builds the `quality` image, mounts the checkout
read-only at `/workspace` so the `gate` lane can reach the history, mounts
`coverage/` back into the checkout, and runs `tools/quality` inside.

`tools/quality` also runs on the host, when the tools are installed there. As
root, Composer refuses to run its scripts until you say so, and a run that hits
that aborts at `composer lint`:

```sh
COMPOSER_ALLOW_SUPERUSER=1 tools/quality fast
```

`composer.json` raises Composer's `process-timeout` to 1200 seconds, because the
default 300 would kill `composer test` partway through the suite on a slow
machine.

## Profiles and lanes

`fast` is what the commit hook runs. `full` is what the push hook and CI run. The
script is one linear gate, and locally it runs as one. CI splits it into six
lanes that run at the same time, because most of the work does not depend on the
rest of it. Both commands take an optional second argument naming a lane, so a
lane that failed in CI can be reproduced here:

```sh
tools/quality-container full release
tools/quality full static
```

| lane       | profiles | holds                                                                                                                                                                                                                               |
| ---------- | -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `static`   | both     | Composer and npm validation, linters, PHP-CS-Fixer, PHPStan, ESLint, `typecheck:mod`, Prettier, markdownlint, Ruff, mypy, the documentation and repository checks, pre-commit config, ShellCheck, Hadolint, `docker compose config` |
| `tests`    | both     | the PHPUnit suite and the hook shell tests, the TypeScript worker's checks, pytest, scanner conformance, the mod's vitest suite and plugin checks                                                                                   |
| `rust`     | both     | `cargo fmt`, `clippy` and `test`, and conformance of the Rust worker, which are slow and self-contained                                                                                                                             |
| `release`  | `full`   | Composer and npm audits, the external link check, the MCP Inspector smoke, the runtime image build and `doctor`, the release lifecycle, supply chain, the benchmark                                                                 |
| `coverage` | `full`   | the pcov run and the coverage floors                                                                                                                                                                                                |
| `gate`     | `full`   | Knossos scanned by Knossos, held to the budgets in `knossos.json`                                                                                                                                                                   |

Omitting the argument runs every lane the profile has, which is the local
default.

The split follows independence. Coverage shows why it cannot follow language: a
single PHPUnit run under pcov produces the PHP figure and, from the worker
subprocesses that run drives, the JavaScript and Python figures. Splitting it per
language would measure three suites that never exercise the workers and report
floors nothing earns.

Mutation testing is in no profile, see [adversarial testing](adversarial-testing.md).

### What the `gate` lane enforces

The lane scans two trees, the commit the change is measured against and the
change itself, then holds the second scan to the budgets and policies in
`knossos.json`. All six budgets are live. `new_cycles` and `hub_degree_growth`
compare the two scans. `boundary_violations`, `error_diagnostics`,
`warning_diagnostics` and `unreferenced_candidates` are read off the second one.

The baseline is what CI works out from the event, a pull request's target
commit or the tip a push replaced. Failing that the lane takes the point the
branch left the default branch, and failing that `HEAD^`. `KNOSSOS_GATE_BASE_REF`
names it explicitly. If nothing resolves, the lane stops. Scanning one tree twice would zero both delta
budgets and report a pass that checked nothing.

Both trees come from `git archive` and are scanned at the same path. The path
matters: a project's identity is its root, so a baseline scanned elsewhere
would be a different project with nothing to compare against. Extracting both
sides the same way matters too, since reading the active side off the
container's baked source would diff the change against `.dockerignore` along
with it. One consequence is that `git archive HEAD` is the committed tree, so
uncommitted work is not gated. That is what CI measures anyway.

Budgets and policies are read from the tree under test, not the baseline, so a change that needs a limit raised is reviewed as the diff that
raises it.

The image bakes this source without `.git`, so the lane is handed the checkout
on a read-only mount and given the baseline commit by name. The CI lane checks
out with `fetch-depth: 0`, because a shallow clone cannot reach its own
baseline.

## How CI runs it

One job builds the quality image and pushes it to the repository's registry,
tagged by commit, and the lanes pull it. An aggregating job named `quality`
fails unless the whole matrix succeeded, which is the check branch protection
requires: a lane that is skipped or cancelled fails it just as a red lane does.
A second push to a pull request cancels the run in progress.

A release-please pull request runs `static` alone. Its diff is version files, a
manifest and a changelog entry, so every other lane would re-verify code
identical to the `main` it was cut from, which had just passed. The full matrix
runs again on the push to `main` after it merges, so nothing reaches a tag
unchecked.

Pull request titles are checked by the `pr-title` workflow with
`tools/check-commit-style.sh`, the same script the `commit-msg` hook uses,
because the squash-merged title is what release-please reads.

## The documentation checks

`php tools/documentation-check.php` runs in the `static` lane. It reads
`README.md`, `CONTRIBUTING.md` and every Markdown file under `docs/`, `skills/`
and `plugins/`, and fails on:

- a link or an image whose target file does not exist;
- a `#anchor` that matches no heading in the file it points at (`#L12` and
  `#L12-L20` line anchors are left to GitHub);
- a `tools/` or `bin/` command in a `sh`, `shell` or `bash` block that does not
  exist;
- a link scheme other than `https://`.

Fenced code is not prose and is skipped, following the CommonMark fence rules.
`php tools/generate-reference.php --check` fails when `docs/reference/cli.md`,
`mcp-tools.md` or `api.md` differ from what the code generates, and
`php tools/api-documentation-check.php` and `php tools/docstring-report.php`
hold the docblock coverage.

External links are fetched only by `php tools/documentation-check.php --external`,
in the `release` lane. A dead link there fails the lane. `tools/quality` runs the
check whenever `KNOSSOS_EXTERNAL_LINKS` is unset, under `set -e`, so it also fails
the `quality` check on `main` and a local `tools/quality full`, which is the
pre-push hook, and blocks the push.

A dead link means a 404, a 410, any other error status, a DNS failure or a TLS
failure. A rate limit is not a dead link: GitHub answers a shared CI runner with
HTTP 429 on pages that are fine. The checker retries a 429, and a 503 that sends
`Retry-After`, at most twice, waiting as long as `Retry-After` says and never more
than 30 seconds in total for one URL. If the limit persists, it prints
`warning: external link rate-limited and not checked: <url>` and the check still
passes. I chose retry-then-warn over adding `github.com` to the skipped hosts,
because most GitHub links are answered normally and a skip would stop catching
the ones that really die. The tests replace curl with the `KNOSSOS_CURL` variable.

Pull requests set `KNOSSOS_EXTERNAL_LINKS=0`, because whether a web server is
answering right now is not a property of the change under review. Set it to `0`
yourself to skip the fetch locally. The badge hosts `img.shields.io` and
`mcpobservatory.com` are counted but never fetched.

## The Claude Code mod

The mod has three checks of its own, and
[how the mod is built](mod-internals.md#the-tests) explains each of them. Here is
where they run:

- `npm run typecheck:mod` runs in `static`.
- `npm run test:mod` runs in `tests`: the vitest specs under `hooks/lib`,
  `hooks/mod`, `tools/` and `tools/capture`.
- `claude plugin validate` and `claude plugin test` run in `tests`, against a
  staging copy of the plugin. The quality image pins the Claude CLI
  (`@anthropic-ai/claude-code@2.1.287`). A machine without the `claude` command
  prints that they were not run.

## Tool inventory

| Surface                      | Enforced tools                                                                  |
| ---------------------------- | ------------------------------------------------------------------------------- |
| PHP                          | PHP syntax, PHP-CS-Fixer 3.95.12, PHPStan 2.2.5                                 |
| JavaScript/TypeScript worker | ESLint 10.7.0, Prettier 3.9.5, TypeScript compiler checks, vitest suite         |
| Claude Code mod              | ESLint, `npm run typecheck:mod`, vitest, `claude plugin validate`/`test`        |
| Python worker                | Ruff 0.15.12, mypy 2.3.0, pytest, isolated compile/runtime tests                |
| Rust worker                  | `cargo fmt`, Clippy with `-D warnings`, `cargo test`, `cargo audit`             |
| Markdown                     | Prettier, markdownlint 0.41.1                                                   |
| JSON/JSONC/YAML              | Prettier, strict JSON decode, pre-commit JSON/YAML checks                       |
| Shell                        | ShellCheck 0.9.0                                                                |
| Dockerfile                   | Hadolint 2.14.0, digest-pinned base images, clean build/doctor                  |
| Dependencies                 | Composer validation/audit, npm lock integrity/audit                             |
| Repository hygiene           | private-key/access-key patterns, 2 MB file cap, conflict markers, line endings  |
| MCP contract                 | test suite plus Inspector 2.9.0 `tools/list` smoke                              |
| Adversarial testing          | fixed-seed properties/fuzz, differential scans, semantic mutation score         |
| Mutation testing             | Infection 0.34 over `src`, scheduled workflow (not a profile gate)              |
| Performance                  | mixed-language cold/incremental/query/RSS/SQLite budgets                        |
| Documentation                | generated CLI/MCP contracts, internal links, anchors and images, external links |
| Supply chain                 | CycloneDX SBOMs, Trivy runtime/config gates, provenance, Cosign verification    |
| Maintainability              | per-file size/decision metrics and normalized cross-file duplication report     |

## The PHP test suite

`tests/phpunit/` is the single PHP suite, run by `composer test`, which also runs
the two hook shell tests in `tests/shell/`. The suite drives every language
through one runner that spawns the TypeScript and Python workers as
subprocesses, so worker behaviour is proven end-to-end from PHP. The TypeScript
worker additionally has a focused vitest suite (`workers/typescript/src/__tests__/`,
run via `npm --prefix workers/typescript run check`) for scanner internals that
are awkward to reach through the protocol. Groups mirror the architecture areas
and are selectable with `vendor/bin/phpunit --group=store`.

Inside the quality container the suite runs as the unprivileged `knossos` user,
because the permission-error tests skip themselves as root, and a branch that
never runs would read as uncovered.

Because the suite is a real PHPUnit suite, [Infection](https://infection.github.io/)
can mutate all of `src`, and Chaos-MCP's `audit_code_resilience` works against
this repository unmodified. Mutation testing is not part of any `tools/quality`
profile, since a full-src run takes hours, so it runs through
`.github/workflows/mutation.yml`. See [adversarial testing](adversarial-testing.md).

There are no standalone YAML configuration files beyond the workflows and hook
configuration, and no release packaging manifest. Those categories are validated
by the generic YAML and repository checks. Actionlint is not bundled because the
workflows deliberately contain no expressions or custom action inputs beyond what
YAML parsing and running the same container command already prove.

Hadolint rule `DL3008` is narrowly disabled: Debian package revisions come from
the digest-pinned base image, and exact apt revision pins would prevent base
image security rebuilds. `DL3002` applies only to the development quality stage,
which needs the mounted Docker socket, and the shipped runtime stays
unprivileged. `DL3059` preserves separate Composer/npm cache layers. `SC2086`
applies only to the intentional package-list expansion of the base image's
`PHPIZE_DEPS`. No source-analysis baseline or blanket file exclusion is used.

The development-only quality stage requests Docker API 1.44 when it performs
its nested clean-image verification. This keeps the pinned Docker CLI compatible
with current daemons whose minimum supported API is 1.44, and does not affect
the shipped runtime image. The image also builds `pcov` from a checksum-pinned
tarball and enables `pcntl`, which the signal and watcher tests need in order to
run instead of skipping.

## Hooks

Install pinned pre-commit 4.6.0, then run:

```sh
tools/install-hooks
```

The commit hook runs the hygiene hooks and `tools/quality fast`. The pre-push hook
runs `tools/quality full`. Developers without native tools can run the
container-backed commands before committing, and CI always uses the quality image.

The script installs the `pre-commit`, `pre-push` and `commit-msg` hooks. The
`commit-msg` hook checks each subject with `tools/check-commit-style.sh`.

## Re-shooting the docs images

The images in the docs and the README GIF come from `tools/capture`, a dev-only
tool that sits outside every gate and ships with nothing. It drives a real Claude Code
session in a terminal, takes the screen and renders it to PNG, and builds the hero
GIF from the frames.

```sh
node tools/capture/shoot.mjs
node tools/capture/shoot.mjs --only=overview,hero
node tools/capture/shoot.mjs --out=/tmp/shots
```

With no options it shoots every shot into `docs/images/claude-code/`. `--only` takes
a comma-separated list of shot names from `tools/capture/shots.mjs`, and `--out`
changes the directory. You need `npm ci` for `puppeteer-core`, Chrome at
`/usr/bin/google-chrome`, `ffmpeg`, and a logged-in `claude` CLI.

The sessions never touch your own setup. Each runs under a temporary
`CLAUDE_CONFIG_DIR` that holds a copy of your `~/.claude/.credentials.json` and
nothing else, against a staged copy of the graph. Because the hero's model turn
edits a file, the sessions run in a throwaway `git worktree` of `HEAD`, never in your
checkout. The stage, the worktree and the sessions are removed however the run
ends, and the tool refuses to start a session whose data or config directory is your real one. `CAPTURE_TRACE=1` prints each step. The specs
for the tool run with `npm run test:mod`.

## Maintenance

Upgrade one ecosystem at a time, regenerate its lockfile, rebuild the quality
image, and run the full profile. Tool suppressions require a specific rationale
in this document. Generated dependency directories and coverage output are not
committed. Security audits depend on current registry advisory data, so a newly
published advisory can fail the `release` lane on a change that touched nothing
related. Prefer bumping the direct dependency over adding an override.
