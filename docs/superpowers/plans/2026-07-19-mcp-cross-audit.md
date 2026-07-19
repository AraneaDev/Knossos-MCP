# Knossos MCP Packaging and Cross-Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Knossos reachable over MCP from a committed, portable registration and a compose file, then run Knossos and Chaos-MCP against each other and produce a fact-checked triage report of both repositories' findings and both tools' defects.

**Architecture:** No server code is written. Knossos already implements MCP stdio (`src/Mcp/StdioServer.php`) and Streamable HTTP (`bin/http-router.php`); Tasks 1–3 are packaging that makes those reachable without absolute paths. Tasks 4–6 are investigation: each produces a written artifact, and findings are fact-checked against source before being asserted.

**Tech Stack:** PHP 8.3+, custom test harness (`tests/run.php`), Docker Compose v5, prettier, markdownlint-cli2, Chaos-MCP over MCP (`estimate_audit`, `audit_code_resilience`, `triage_test_coverage`).

## Global Constraints

- Spec of record: `docs/superpowers/specs/2026-07-19-mcp-cross-audit-design.md`.
- Branch: `dogfeed/mcp-cross-audit` in Knossos; create `dogfeed/knossos-audit` in Chaos-MCP before writing anything there.
- JSON and YAML files are indented **2 spaces** (`.editorconfig` `[*.{json,yaml,yml}]`). PHP and everything else is 4.
- All of `**/*.{js,json,jsonc,yaml,yml,md}` is prettier-checked by `npm run format:check`. Every file created here must pass it.
- **Do not copy indentation out of this plan's code fences.** Prettier formats JSON and YAML embedded in a `.md` file using the markdown 4-space rule, so the blocks below display at 4 spaces. The real `.json` and `.yml` files must be written at 2 spaces. Write the file, then run `npx --no-install prettier --write <file>` and let it settle the indentation.
- `tools/repository-check.php` parses every `.json` file in the repo and rejects CR line endings and secret-shaped strings. Use LF only.
- The Dockerfile's **final stage is `quality`, not `runtime`**. Every compose service MUST declare `target: runtime` explicitly or it builds the wrong image.
- Tests are added to `tests/run.php` as `$tests['name'] = static function (): void { ... };` with an optional `$testGroups['name'] = 'group';`. Assertions available: `assertSame`, `assertNotSame`, `assertContains`, `assertArrayContains`, `assertThrows`, `captureThrows`.
- Run a group with `php tests/run.php --group=cli`. Run everything with `composer test`.
- Do not modify Knossos source to suit Chaos's engines. Blockers are evidence.
- No source change in either repository before the specific finding is approved by the user.
- **Two review protocols.** Tasks 1–3 produce code and get the standard task review (spec compliance + code quality). Tasks 4–6 produce written reports and get a **fact-check review** instead: the reviewer independently opens every `file:line` the report cites and confirms the claim, verifies that each recorded tool output matches what the command actually returns, and rejects any finding it cannot reproduce. A fact-check reviewer judges evidence, not code style.

---

### Task 1: Characterize existing allowed-root behaviour

**These are characterization tests, not TDD tests.** They pin behaviour that
already exists and will pass on their first run. That is the point: Task 2's
registration depends on relative-root resolution working, and the spec
explicitly refused to change the missing-root error into a working-directory
default. Both need a regression pin before anything is built on them. Do not
attempt to make them fail first, and do not modify source to create a red
state.

To confirm each test actually exercises what it claims, verify it by
temporarily breaking the code path (see Step 2) rather than by expecting an
initial failure.

**Files:**

- Modify: `tests/run.php` (append tests before the `$failed = 0;` runner block, currently near line 4392)

**Interfaces:**

- Consumes: `Knossos\Discovery\RootGuard::resolve()` (existing, `src/Discovery/RootGuard.php:12`), which calls `realpath()` on each configured root and therefore resolves relative roots against the process working directory.
- Produces: two passing regression tests in group `cli`. Task 2 depends on the relative-resolution behaviour they pin.

- [ ] **Step 1: Write the characterization tests**

Append to `tests/run.php`, immediately before the line `$failed = 0;`:

```php
$tests['RootGuard resolves relative allowed roots against the working directory'] = static function (): void {
    $guard = new RootGuard(['.']);
    assertSame(str_replace('\\', '/', (string) realpath(getcwd())), $guard->resolve('.'));

    $parent = new RootGuard(['..']);
    $resolved = $parent->resolve(dirname(__DIR__));
    assertSame(str_replace('\\', '/', (string) realpath(dirname(__DIR__))), $resolved);

    $narrow = new RootGuard([dirname(__DIR__) . '/src']);
    assertThrows(static fn() => $narrow->resolve(dirname(__DIR__) . '/tests'), DiscoveryException::class);
};
$testGroups['RootGuard resolves relative allowed roots against the working directory'] = 'cli';

$tests['serve refuses to start without an explicit allowed root'] = static function (): void {
    $binary = dirname(__DIR__) . '/bin/knossos';
    $previous = getenv('KNOSSOS_ALLOWED_ROOTS');
    putenv('KNOSSOS_ALLOWED_ROOTS');
    try {
        [$exit, , $stderr] = runFixtureCommandOutput([PHP_BINARY, $binary, 'serve']);
        assertSame(2, $exit);
        assertContains('--allow-root', $stderr);
    } finally {
        if (is_string($previous)) {
            putenv('KNOSSOS_ALLOWED_ROOTS=' . $previous);
        }
    }
};
$testGroups['serve refuses to start without an explicit allowed root'] = 'cli';
```

The second test pins the behaviour the spec explicitly refused to change: omitting `--allow-root` must stay a hard error rather than defaulting to the working directory.

- [ ] **Step 2: Run the tests, then prove each one bites**

Run: `php tests/run.php --group=cli`

Expected: PASS for both new tests. They characterize existing behaviour, so passing immediately is correct.

A test that passes on first run has not yet been shown to test anything. Prove each one bites by breaking its subject temporarily and confirming the test goes red:

```bash
# 1. Break relative resolution: make RootGuard reject non-absolute roots.
#    In src/Discovery/RootGuard.php, temporarily change line 21 from
#    `$allowed = realpath($allowedRoot);` to `$allowed = $allowedRoot;`
php tests/run.php --group=cli   # expect: FAIL RootGuard resolves relative allowed roots
git checkout -- src/Discovery/RootGuard.php

# 2. Break the required-root guard: in src/Cli/Command/ServeCommand.php,
#    temporarily change line 32's throw to `$allowedRoots = [getcwd()];`
php tests/run.php --group=cli   # expect: FAIL serve refuses to start without an explicit allowed root
git checkout -- src/Cli/Command/ServeCommand.php
```

Both reverts must leave `git status` clean before continuing. Record in your report that both tests were confirmed to bite, and name the failure message each produced. If a test stays green while its subject is broken, the test is wrong — fix the test, not the source.

- [ ] **Step 3: Verify the suite and commit**

Run: `php tests/run.php --group=cli && git status --short`

Expected: `0 failures`, and `git status --short` shows only `tests/run.php` modified.

```bash
git add tests/run.php
git commit -m "test: pin relative allowed-root resolution and required-root guard"
```

---

### Task 2: Committed, portable MCP registration

**Files:**

- Create: `.mcp.json`
- Modify: `tests/run.php` (append before the `$failed = 0;` runner block)
- Modify: `docs/INSTALLATION.md`

**Interfaces:**

- Consumes: the relative-root resolution pinned by Task 1.
- Produces: a committed `.mcp.json` whose `mcpServers.knossos.args` is exactly `["bin/knossos", "serve", "--allow-root=."]` and contains no absolute path.

- [ ] **Step 1: Write the failing test**

Append to `tests/run.php`, immediately before the line `$failed = 0;`:

```php
$tests['committed MCP registration is portable and explicitly scoped'] = static function (): void {
    $path = dirname(__DIR__) . '/.mcp.json';
    $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $server = $config['mcpServers']['knossos'];
    assertSame('php', $server['command']);
    // RootGuard::resolve() realpath()s each configured root against the process
    // working directory, so args must stay relative to remain portable across checkouts.
    assertSame(['bin/knossos', 'serve', '--allow-root=.'], $server['args']);
};
$testGroups['committed MCP registration is portable and explicitly scoped'] = 'cli';
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/run.php --group=cli`

Expected: FAIL on `committed MCP registration is portable and explicitly scoped` with a `JsonException` (`Syntax error`), because `.mcp.json` does not exist and `file_get_contents` returns an empty string.

- [ ] **Step 3: Create the registration file**

Create `.mcp.json`. The block below is displayed at markdown indentation; write the file, then run `npx --no-install prettier --write .mcp.json` to settle it at 2 spaces.

```json
{
    "mcpServers": {
        "knossos": {
            "command": "php",
            "args": ["bin/knossos", "serve", "--allow-root=."]
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/run.php --group=cli`

Expected: PASS, and `0 failures` in the group summary.

- [ ] **Step 5: Document the registration**

In `docs/INSTALLATION.md`, add a section immediately after the existing Docker `mcpServers` JSON block:

````markdown
## Native stdio (repository checkout)

A checked-in `.mcp.json` at the repository root registers the server for
clients that read project-scoped configuration:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "php",
            "args": ["bin/knossos", "serve", "--allow-root=."]
        }
    }
}
```

Both paths are relative on purpose. `bin/knossos` and `--allow-root=.` resolve
against the working directory the client launches the server in, so the file is
valid on any checkout without editing. A client that launches the server
somewhere unexpected fails immediately on the relative binary path rather than
silently granting access to the wrong tree.

For a client that configures servers imperatively instead:

```sh
claude mcp add knossos -- php bin/knossos serve --allow-root=.
```

`--allow-root` is a security boundary, not a convenience flag. It is the only
thing standing between the server and the rest of the filesystem, so it is
required: `serve` exits with `KNOSSOS_INVALID_ARGUMENT` when no root is given
through the flag or `KNOSSOS_ALLOWED_ROOTS`. Pass the flag once per tree that
should be readable, and grant the narrowest tree that works.
````

- [ ] **Step 6: Verify formatting and links**

Run: `npx --no-install prettier --check .mcp.json docs/INSTALLATION.md && npx --no-install markdownlint-cli2 docs/INSTALLATION.md && php tools/repository-check.php && php tools/documentation-check.php`

Expected: prettier reports no issues, markdownlint reports `0 error(s)`, repository-check prints `Repository JSON, size, line-ending, and secret checks passed.`, documentation-check prints `Documentation links passed`.

If prettier rewrites `.mcp.json`, re-run the Task 2 test — the assertion on `args` is order- and value-exact.

- [ ] **Step 7: Commit**

```bash
git add .mcp.json tests/run.php docs/INSTALLATION.md
git commit -m "feat(mcp): commit portable project-scoped stdio registration"
```

---

### Task 3: docker-compose.yml with CLI, stdio, and HTTP profiles

**Files:**

- Create: `docker-compose.yml`
- Create: `.env.example`
- Modify: `tools/quality` (add compose validation next to the existing `hadolint` block)
- Modify: `Dockerfile` (install the pinned Compose CLI plugin in the `quality` stage; also `COPY` `docker-compose.yml` and `.env.example` into that stage so the gate has a file to validate)
- Modify: `tests/run.php`
- Modify: `docs/CONTAINER.md`

**Interfaces:**

- Consumes: the `runtime` stage of `Dockerfile`, whose entrypoint is `/opt/knossos/bin/knossos`; `bin/http-router.php`, which reads `KNOSSOS_ALLOWED_ROOTS`, `KNOSSOS_HTTP_BEARER_TOKEN`, `KNOSSOS_HTTP_ALLOWED_HOSTS`, `KNOSSOS_HTTP_ALLOWED_ORIGINS`.
- Produces: services `knossos` (default), `knossos-mcp` (profile `mcp`), `knossos-http` (profile `http`); volume `knossos-data`.

- [ ] **Step 1: Verify the nested-interpolation assumption before depending on it**

The spec flags `${KNOSSOS_SOURCE:-${PWD}}` as its one unverified claim. Settle it first:

```bash
cd /tmp && printf 'services:\n  probe:\n    image: alpine\n    volumes:\n      - type: bind\n        source: ${KNOSSOS_SOURCE:-${PWD}}\n        target: /workspace\n        read_only: true\n' > /tmp/compose-probe.yml
cd /root/Knossos-MCP && docker compose -f /tmp/compose-probe.yml config 2>&1 | grep -A3 'source:'
```

Expected if supported: `source: /root/Knossos-MCP` (the invocation directory, not `/tmp`).

If it prints an error or an empty source, **use `${KNOSSOS_SOURCE:-.}` instead** everywhere below. That resolves against the compose file's directory, making the Knossos repo itself the default mount — a sane dogfood default. Record which form was used in the commit message. Do not proceed on an unverified interpolation.

- [ ] **Step 2: Write the failing tests**

Append to `tests/run.php`, immediately before `$failed = 0;`:

```php
$tests['compose file pins the runtime stage and never exposes a public port'] = static function (): void {
    $compose = (string) file_get_contents(dirname(__DIR__) . '/docker-compose.yml');

    // The Dockerfile's final stage is `quality`; every service must pin `runtime`.
    assertSame(1, substr_count($compose, 'target: runtime'));
    assertSame(false, str_contains($compose, 'target: quality'));

    // Both server services are opt-in, so `docker compose up` starts nothing that listens.
    assertContains('profiles:', $compose);
    assertContains('- mcp', $compose);
    assertContains('- http', $compose);

    // Source is mounted read-only, and the HTTP token is required rather than defaulted.
    assertContains('read_only: true', $compose);
    assertContains('KNOSSOS_HTTP_BEARER_TOKEN:?', $compose);

    // No absolute developer paths leak into a committed file.
    assertSame(false, str_contains($compose, '/root/'));

    // Docker-free backstop for the resolved-config port check below, which skips
    // when compose is unavailable: every published port entry must bind loopback.
    $portEntries = [];
    $portsIndent = null;
    foreach (explode("\n", $compose) as $line) {
        if (preg_match('/^(\s*)ports:\s*$/', $line, $matches) === 1) {
            $portsIndent = strlen($matches[1]);
            continue;
        }
        if ($portsIndent === null || trim($line) === '') {
            continue;
        }
        if (preg_match('/^(\s*)-\s*(\S.*?)\s*$/', $line, $matches) === 1 && strlen($matches[1]) > $portsIndent) {
            $portEntries[] = trim($matches[2], "\"'");
            continue;
        }
        $portsIndent = null;
    }

    assertNotSame([], $portEntries);
    foreach ($portEntries as $portEntry) {
        assertSame(true, str_starts_with($portEntry, '127.0.0.1:'));
    }
};
$testGroups['compose file pins the runtime stage and never exposes a public port'] = 'cli';

$tests['compose configuration parses and keeps servers behind profiles'] = static function (): void {
    [$probeExit] = runFixtureCommandOutput(['docker', 'compose', 'version']);
    if ($probeExit !== 0) {
        return; // Docker is not available in this environment; the text test above still applies.
    }

    $root = dirname(__DIR__);
    putenv('KNOSSOS_HTTP_BEARER_TOKEN=test-token-not-a-secret');
    [$exit, $stdout, $stderr] = runFixtureCommandOutput(
        ['docker', 'compose', '--project-directory', $root, '-f', $root . '/docker-compose.yml', 'config', '--services'],
    );
    putenv('KNOSSOS_HTTP_BEARER_TOKEN');

    if ($exit !== 0) {
        throw new RuntimeException('docker compose config failed: ' . $stderr);
    }

    // Only the default-profile service is listed without --profile flags.
    assertSame('knossos', trim($stdout));
    assertSame(false, str_contains($stdout, 'knossos-http'));
    assertSame(false, str_contains($stdout, 'knossos-mcp'));
};
$testGroups['compose configuration parses and keeps servers behind profiles'] = 'cli';

$tests['compose resolved ports are loopback-only across every profile'] = static function (): void {
    [$probeExit] = runFixtureCommandOutput(['docker', 'compose', 'version']);
    if ($probeExit !== 0) {
        return; // Docker is not available in this environment; the text test above still applies.
    }

    $root = dirname(__DIR__);
    putenv('KNOSSOS_HTTP_BEARER_TOKEN=test-token-not-a-secret');
    [$exit, $stdout, $stderr] = runFixtureCommandOutput([
        'docker', 'compose', '--project-directory', $root, '-f', $root . '/docker-compose.yml',
        '--profile', 'http', '--profile', 'mcp', 'config', '--format', 'json',
    ]);
    putenv('KNOSSOS_HTTP_BEARER_TOKEN');

    if ($exit !== 0) {
        throw new RuntimeException('docker compose config failed: ' . $stderr);
    }

    /** @var array{services?: array<string, array{ports?: list<array{host_ip?: string}>}>} $config */
    $config = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    $services = $config['services'] ?? [];
    $serviceNames = array_keys($services);
    sort($serviceNames);
    assertSame(['knossos', 'knossos-http', 'knossos-mcp'], $serviceNames);

    // Every published port, on every service resolved from every profile, must be loopback-only.
    $publishedPortCount = 0;
    foreach ($services as $service) {
        foreach ($service['ports'] ?? [] as $port) {
            ++$publishedPortCount;
            assertSame('127.0.0.1', $port['host_ip'] ?? null);
        }
    }

    // At least one port must actually be published, so this cannot pass vacuously.
    assertSame(true, $publishedPortCount > 0);
};
$testGroups['compose resolved ports are loopback-only across every profile'] = 'cli';
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php tests/run.php --group=cli`

Expected: FAIL on `compose file pins the runtime stage...` — `file_get_contents` on a missing `docker-compose.yml` yields an empty string, so the `assertSame(1, substr_count(...))` assertion fails with `expected 1, actual 0`.

- [ ] **Step 4: Create the compose file**

Create `docker-compose.yml` (2-space indent). Substitute `${KNOSSOS_SOURCE:-.}` for `${KNOSSOS_SOURCE:-${PWD}}` throughout if Step 1 showed nested interpolation is unsupported:

```yaml
name: knossos

x-knossos-base: &knossos-base
    build:
        context: .
        # The Dockerfile's final stage is `quality`. Pin `runtime` or compose builds
        # the full toolchain image instead of the distributable one.
        target: runtime
    image: knossos-mcp:dev
    volumes:
        - type: bind
          source: ${KNOSSOS_SOURCE:-${PWD}}
          target: /workspace
          read_only: true
        - type: volume
          source: knossos-data
          target: /data
    environment:
        KNOSSOS_DATA_DIR: /data

services:
    # One-shot CLI. Networking is disabled: scanning never needs it.
    #   docker compose run --rm knossos scan /workspace --json
    knossos:
        <<: *knossos-base
        network_mode: none

    # MCP stdio server. Requires -T so compose does not allocate a TTY and
    # corrupt the JSON-RPC framing:
    #   docker compose --profile mcp run --rm -T knossos-mcp
    knossos-mcp:
        <<: *knossos-base
        profiles:
            - mcp
        network_mode: none
        stdin_open: true
        tty: false
        command: ["serve", "--allow-root=/workspace"]

    # Streamable HTTP MCP profile, opt-in and loopback-only:
    #   KNOSSOS_HTTP_BEARER_TOKEN=... docker compose --profile http up knossos-http
    #
    # docs/HTTP-THREAT-MODEL.md states that PHP's development server is
    # single-process and unsuitable for hostile traffic, and that stdio remains
    # the recommended transport. Compose makes "always up" easy, which is exactly
    # why that caveat belongs here at the point of use. Do not bind this to
    # 0.0.0.0 or place it on a shared network without a TLS/OAuth gateway.
    knossos-http:
        <<: *knossos-base
        profiles:
            - http
        ports:
            - "127.0.0.1:8080:8080"
        environment:
            KNOSSOS_DATA_DIR: /data
            KNOSSOS_ALLOWED_ROOTS: /workspace
            # Required, not defaulted: an unauthenticated listener is a real exposure,
            # so compose must fail loudly rather than start one.
            KNOSSOS_HTTP_BEARER_TOKEN: ${KNOSSOS_HTTP_BEARER_TOKEN:?set KNOSSOS_HTTP_BEARER_TOKEN to a random secret}
        entrypoint:
            ["php", "-S", "0.0.0.0:8080", "/opt/knossos/bin/http-router.php"]

volumes:
    knossos-data:
```

The `0.0.0.0` in the entrypoint binds inside the container's own namespace; the published port is loopback-only, which is what limits exposure. This mirrors the `docker run` invocation already documented in `docs/HTTP-THREAT-MODEL.md`.

- [ ] **Step 5: Create the environment example**

Create `.env.example`:

```sh
# Directory mounted read-only at /workspace. Defaults to the directory you run
# `docker compose` from. PowerShell does not export PWD, so Windows users must
# set this explicitly.
KNOSSOS_SOURCE=/absolute/path/to/project

# Required only by the `http` profile. Use a random secret; never put it in a URL.
KNOSSOS_HTTP_BEARER_TOKEN=replace-with-a-random-secret
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php tests/run.php --group=cli`

Expected: PASS for both compose tests.

- [ ] **Step 7: Add compose validation to the quality gate**

In `tools/quality`, immediately after the existing `hadolint` block, add:

```sh
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    KNOSSOS_HTTP_BEARER_TOKEN=quality-gate-placeholder docker compose config --quiet
elif [ "${KNOSSOS_QUALITY_CONTAINER:-0}" = "1" ]; then
    echo "docker compose is required in the quality container" >&2
    exit 1
fi
```

This follows the existing `command -v` / container-required pattern used for `shellcheck` and `hadolint`.

The `elif` arm only holds if the quality container actually ships Compose. Debian's `docker.io` package provides the Docker CLI without the Compose plugin, so the `quality` stage of `Dockerfile` installs it explicitly, using the same pinned-download-plus-checksum convention as `hadolint`, `trivy`, and `cosign`:

```dockerfile
# Debian's `docker.io` ships the CLI without the Compose plugin, so `tools/quality`
# would skip (or fail) its `docker compose config` gate. Install the plugin explicitly.
RUN mkdir -p /usr/libexec/docker/cli-plugins \
    && curl --fail --location --silent --show-error \
        --output /usr/libexec/docker/cli-plugins/docker-compose \
        https://github.com/docker/compose/releases/download/v5.3.1/docker-compose-linux-x86_64 \
    && printf '%s  %s\n' f9ebc6ebdb19d769b793c245a736caaeb198c62587f13b25c660c13b4987f959 \
        /usr/libexec/docker/cli-plugins/docker-compose > /tmp/compose.sha256 \
    && sha256sum --check --strict /tmp/compose.sha256 \
    && chmod 0755 /usr/libexec/docker/cli-plugins/docker-compose \
    && rm -f /tmp/compose.sha256
```

`/usr/libexec/docker/cli-plugins` is a default plugin search path, so `docker compose version` resolves without further configuration.

Installing the plugin is necessary but not sufficient: `docker compose config` still needs a `docker-compose.yml` to read. The `quality` stage's `COPY` block (further down the same stage, alongside `.editorconfig`, `README.md`, `Dockerfile`, `docs`, etc.) does not copy the repo root's compose file into the image, so `KNOSSOS_QUALITY_CONTAINER=1` runs fail with `no configuration file provided` even once the plugin is present. Add both files the compose gate needs — `docker-compose.yml` itself and `.env.example` (kept alongside it so the copied tree isn't a confusing half-present setup, even though `tools/quality` passes `KNOSSOS_HTTP_BEARER_TOKEN` inline and doesn't strictly require the `.env.example` file to pass) — to that `COPY` list, next to the existing `COPY Dockerfile ./` line:

```dockerfile
COPY Dockerfile ./
COPY docker-compose.yml .env.example ./
COPY docs ./docs
```

Do not add these to the `runtime` stage; the distributable image must not carry compose/dev files.

- [ ] **Step 8: Document compose usage**

Append to `docs/CONTAINER.md`:

````markdown
## Compose

`docker-compose.yml` wraps the three supported invocations. Source is always
mounted read-only at `/workspace`; graph data lives in the `knossos-data`
volume.

Compose interpolates the entire file before applying `--profile` filtering, so
`KNOSSOS_HTTP_BEARER_TOKEN` must resolve for every compose command, even ones
that never touch the `http` profile. Compose loads `.env` automatically, so the
smallest fix is to add only that variable. Use `>>` (append), not `>`, so an
existing `.env` is not truncated:

```sh
printf 'KNOSSOS_HTTP_BEARER_TOKEN=unused-by-non-http-profiles\n' >> .env
```

Copying `.env.example` also works, but it sets `KNOSSOS_SOURCE` to a placeholder
path; delete or fill in that line, or the bind mount stops following the
directory compose runs from.

One-shot CLI, with networking disabled:

```sh
docker compose run --rm knossos scan /workspace --json
```

MCP stdio. `-T` is required so compose does not allocate a TTY and corrupt
JSON-RPC framing:

```sh
docker compose --profile mcp run --rm -T knossos-mcp
```

Streamable HTTP, loopback-only and opt-in:

```sh
KNOSSOS_HTTP_BEARER_TOKEN="$(openssl rand -hex 32)" \
  docker compose --profile http up knossos-http
```

Both server services sit behind profiles, so a bare `docker compose up` starts
nothing that listens. The HTTP profile is subject to the limits in the
[HTTP threat model](HTTP-THREAT-MODEL.md): PHP's development server is
single-process and is not intended for hostile traffic. stdio remains the
recommended transport.

The mounted directory defaults to wherever `docker compose` is run from. Set
`KNOSSOS_SOURCE` to scan a different tree; PowerShell does not export `PWD`, so
Windows users must always set it. See `.env.example`.
````

- [ ] **Step 9: Verify the full gate**

Run: `npx --no-install prettier --check docker-compose.yml docs/CONTAINER.md && npx --no-install markdownlint-cli2 docs/CONTAINER.md && php tools/repository-check.php && php tools/documentation-check.php && shellcheck tools/quality`

Expected: all clean. `documentation-check.php` verifies that `bin/`- and `tools/`-prefixed commands in the new shell blocks exist — there are none in the added blocks, so it should pass unchanged.

- [ ] **Step 10: Commit**

```bash
git add docker-compose.yml .env.example tools/quality Dockerfile tests/run.php docs/CONTAINER.md
git commit -m "feat(docker): add compose profiles for CLI, MCP stdio, and loopback HTTP"
```

---

### Task 4: Phase A — Knossos analyses Chaos-MCP

This is an investigation task. Its deliverable is a written artifact, not code. Record what the tools actually returned, including anything that looks wrong.

**Files:**

- Create: `/root/Chaos-MCP/docs/audits/2026-07-19-knossos-architecture-scan.md`

**Do not touch `.mcp.json`.** This task drives Knossos entirely through the CLI,
and `ScanCommand` passes the requested path as its own allowed root
(`src/Cli/Command/ScanCommand.php:23`) — the CLI authorizes the tree you hand
it. No second allowed root is needed, and editing the committed registration
would only turn Task 2's test red for no benefit.

- [ ] **Step 1: Check the ignore behaviour before trusting any query**

Chaos carries `node_modules/`, `build/`, and `coverage/`. Time the scan and check what it took in:

```bash
cd /root/Knossos-MCP && time php bin/knossos scan /root/Chaos-MCP --json > /tmp/chaos-scan.json; echo "exit=$?"
php -r '$d=json_decode(file_get_contents("/tmp/chaos-scan.json"),true); echo json_encode($d["counts"] ?? $d, JSON_PRETTY_PRINT), "\n";'
grep -c 'node_modules' /tmp/chaos-scan.json || true
```

If `node_modules/`, `build/`, or `coverage/` files appear in the graph, that is **Knossos finding #1** — record it with the file count and the wall-clock time as evidence.

- [ ] **Step 2: Run the query suite**

Using the `project_id` returned by Step 1:

```bash
cd /root/Knossos-MCP
PID=$(php -r '$d=json_decode(file_get_contents("/tmp/chaos-scan.json"),true); echo $d["project_id"];')
php bin/knossos architecture-summary "$PID" --json  > /tmp/chaos-summary.json
php bin/knossos file-metrics "$PID" --sort-by=line_count --order=desc --limit=20 --json > /tmp/chaos-metrics.json
php bin/knossos dependency-cycles "$PID" --json     > /tmp/chaos-cycles.json
php bin/knossos architecture-health "$PID" --json   > /tmp/chaos-health.json
php bin/knossos list-boundaries "$PID" --json       > /tmp/chaos-boundaries.json
```

- [ ] **Step 3: Pull context on the hot files**

Take the top three files by line count from `/tmp/chaos-metrics.json` and run, for each:

```bash
php bin/knossos architecture-context "$PID" <relative/path.ts> --json
```

- [ ] **Step 4: Write the report**

Create `/root/Chaos-MCP/docs/audits/2026-07-19-knossos-architecture-scan.md` on a new branch `dogfeed/knossos-audit` in that repo. Structure:

- **What was run** — exact commands, Knossos version, scan wall-clock time, node/edge counts.
- **Findings about Chaos-MCP** — one section per finding: claim, evidence (tool output excerpt), the source lines that confirm it, and severity. Every finding must cite a `file:line` that was actually opened and read.
- **Findings about Knossos** — anything the tool got wrong, missed, or reported confusingly: ignore-list behaviour, dead-code false positives (expected on MCP entrypoints reached only via the JSON-RPC dispatch table), confidence labels that do not match reality, `ResultEnricher` staleness or `next_steps` output that reads oddly against an unfamiliar repository.
- **Not findings** — candidates that were checked and discarded, with the reason. This section is mandatory and must not be empty if anything was discarded.

- [ ] **Step 5: Confirm Knossos was not modified, then commit the report**

The scan is read-only and this task writes no Knossos source:

```bash
cd /root/Knossos-MCP && git status --short && php tests/run.php --group=cli
```

Expected: `git status --short` prints nothing, and the cli group reports `0 failures`.

```bash
cd /root/Chaos-MCP && git checkout -b dogfeed/knossos-audit
git add docs/audits/2026-07-19-knossos-architecture-scan.md
git commit -m "docs: record Knossos architecture scan of Chaos-MCP"
```

---

### Task 5: Phase B — Chaos-MCP audits Knossos

**Files:**

- Create: `/root/Knossos-MCP/docs/audits/2026-07-19-chaos-mutation-audit.md`

- [ ] **Step 1: Control run — prove the tool works here at all**

Call `estimate_audit` (MCP) against a Chaos file, e.g. `filePath: "src/gate.ts"` with the Chaos repo as the workspace.

Expected: a mutant count and timing estimate. If this fails, **stop** — every later failure would be unattributable, and the environment must be fixed first. Record the output verbatim.

- [ ] **Step 2: Estimate against Knossos PHP, then audit**

Call `estimate_audit` with `filePath: "/root/Knossos-MCP/src/Discovery/RootGuard.php"`, then `audit_code_resilience` on the same file.

Expected: failure. pcov is present, but Infection is not installed and the Knossos suite is `php tests/run.php`, not PHPUnit. Record verbatim: the exact error text, whether it names the real cause (missing Infection / unsupported test runner) or something misleading, and whether it suggests a correct remedy.

Do **not** install Infection or add a PHPUnit shim. The blocker is the finding.

- [ ] **Step 3: Estimate against the TypeScript worker, then audit**

Call `estimate_audit` with `filePath: "/root/Knossos-MCP/workers/typescript/src/<largest file>"`, then `audit_code_resilience`.

Expected: failure. The worker uses vitest 3.x, which the Chaos README flags as incompatible with StrykerJS 9.x's vitest-runner. Record whether Chaos **detects and explains** the incompatibility up front or merely relays Stryker's output after doing the work.

- [ ] **Step 4: Time the sandbox copy**

Knossos carries `vendor/`, `node_modules/`, `coverage/`, and `.git/`. Measure how long each call above took before it failed, and check whether the sandbox copy was bounded:

```bash
du -sh /root/Knossos-MCP /root/Knossos-MCP/vendor /root/Knossos-MCP/node_modules /root/Knossos-MCP/coverage
ls -dt "${TMPDIR:-/tmp}"/* 2>/dev/null | head -20
```

A copy that is slow or unbounded is a Chaos defect, not an inconvenience. Record measured numbers, not impressions.

- [ ] **Step 5: Write the report**

Create `/root/Knossos-MCP/docs/audits/2026-07-19-chaos-mutation-audit.md` using the same four-section structure as Task 4 Step 4 (What was run / Findings about Knossos / Findings about Chaos / Not findings).

For each blocked engine, the report must answer three questions explicitly: did the error name the true cause; how long did the user wait before learning it; and was there a cheaper check Chaos could have run first.

- [ ] **Step 6: Verify and commit**

```bash
cd /root/Knossos-MCP
npx --no-install prettier --check docs/audits/2026-07-19-chaos-mutation-audit.md
npx --no-install markdownlint-cli2 docs/audits/2026-07-19-chaos-mutation-audit.md
php tools/documentation-check.php
git add docs/audits/2026-07-19-chaos-mutation-audit.md
git commit -m "docs: record Chaos-MCP mutation audit of Knossos"
```

Expected: prettier clean, markdownlint `0 error(s)`, documentation-check passes.

---

### Task 6: Phase C — fact-check and triage

**Files:**

- Create: `/root/Knossos-MCP/docs/audits/2026-07-19-cross-audit-triage.md`

- [ ] **Step 1: Fact-check every finding independently**

For each finding in both reports, open the cited source and confirm the claim holds. A finding survives only if you can state the concrete failure: specific inputs or state, and the specific wrong output or behaviour that results.

Discard anything that does not survive. Do not soften a failed finding into a weaker one — delete it and list it under "Not findings" with the reason it failed.

- [ ] **Step 2: Sort into the four buckets**

Write `docs/audits/2026-07-19-cross-audit-triage.md` with exactly four sections:

1. Chaos-MCP source findings
2. Knossos source findings
3. Chaos-MCP tool defects
4. Knossos tool defects

Each entry: one-sentence claim, `file:line`, concrete failure scenario, severity, and proposed fix in one sentence. Ranked most severe first within each bucket.

- [ ] **Step 3: Score the advance calibration**

The spec recorded three predictions before the run. Add a short section stating, for each, whether it was confirmed, refuted, or unresolved:

- Chaos source: import cycles and one oversized module.
- Chaos tool: poor-to-mediocre error messages on both blocked engines.
- Knossos tool: dead-code false positives on MCP entrypoints.

If all three landed exactly, say so and treat it as a reason to re-examine whether the predictions shaped the triage, rather than as a success.

- [ ] **Step 4: Verify and commit**

```bash
cd /root/Knossos-MCP
npx --no-install prettier --check docs/audits/2026-07-19-cross-audit-triage.md
npx --no-install markdownlint-cli2 docs/audits/2026-07-19-cross-audit-triage.md
git add docs/audits/2026-07-19-cross-audit-triage.md
git commit -m "docs: triage Knossos/Chaos cross-audit findings"
```

- [ ] **Step 5: Run both repositories' full gates before handing over**

```bash
cd /root/Knossos-MCP && composer test
cd /root/Chaos-MCP && npm run check
```

Expected: both green. Nothing in Tasks 1–6 changes production source in either repository, so a failure here means something unintended was touched — investigate before proceeding.

- [ ] **Step 6: Stop and hand the triage to the user**

Present the four buckets. Phase D (implementing fixes) is **not** part of this plan and must not be started here: its tasks depend on findings that do not exist until this task completes. Write a separate plan from the triage document, then get approval per finding as the spec requires.

---

## Self-Review

**Spec coverage:**

- Part 1.1 registration → Tasks 1 and 2. Task 1 pins relative-root resolution and the rejected implicit-default; Task 2 adds the committed `.mcp.json` and the `INSTALLATION.md` note.
- Part 1.2 compose → Task 3, including the three services, opt-in profiles, read-only mount, loopback binding, `${PWD}` caveat for PowerShell, and the threat-model comment at point of use.
- Part 2 Phase A → Task 4; Phase B → Task 5 (control run, PHP, TS worker, sandbox timing); Phase C → Task 6.
- Calibration section → Task 6 Step 3.
- Phase D → deliberately excluded; Task 6 Step 6 states why and what happens instead.

**Dropped from the spec:** the "local second-root override" of `.mcp.json`. `ScanCommand` passes the requested path as its own allowed root (`src/Cli/Command/ScanCommand.php:23`), so the CLI-driven Phase A never needs a second root. The override would have been dead work that also turned Task 2's test red. The spec's intent — that the audit can reach the Chaos tree — is satisfied without it.

**Gap accepted:** the spec's "assumption to verify" about client working directory cannot be verified inside this plan, because a newly registered MCP server is not live in the session that registers it. Task 2 Step 3 mitigates structurally by keeping `bin/knossos` relative, so a wrong working directory fails loudly. Verification happens on the next session start.

**Placeholder scan:** no TBDs. Every code step contains the literal file content or command. Tasks 4–6 are investigation with defined artifacts and explicit stop conditions rather than unwritten code.

**Test-rubric note:** Task 1's two tests pass on first run by design — they characterize existing behaviour rather than drive new code. Task 1 Step 2 compensates with an explicit mutation check that each test goes red when its subject is broken, so neither is accepted as a test that never demonstrated it can fail.

**Type consistency:** `RootGuard::resolve()`, `DiscoveryException`, `runFixtureCommandOutput()` (returns `[exit, stdout, stderr]`, closes stdin), `assertSame`/`assertContains`/`assertThrows`, and the `$tests[...]`/`$testGroups[...]` idiom all match `tests/run.php` as read. Service and volume names in Task 3's tests match the compose file exactly.
