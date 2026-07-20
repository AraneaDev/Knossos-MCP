# Property, fuzz, differential, and mutation testing

Knossos complements example-based tests with deterministic adversarial checks
for trust boundaries and incremental correctness. They run without target-code
execution, network access, or nondeterministic fuzz seeds.

## Property and fuzz corpus

The standard test suite includes fixed-seed generated cases for:

- normalized relative paths, traversal, absolute paths, separators, null bytes,
  and malformed segments;
- JSONC comments, escaped comment-like strings, and trailing commas;
- invalid checked-in configuration and scanner contributions with stable error
  families;
- JSON-RPC request shapes and bounded responses;
- stable ID determinism, domain separation, and collision checks; and
- seeded edit sequences compared after incremental and full scans.

Run only these checks with:

```sh
vendor/bin/phpunit --group=property
vendor/bin/phpunit --group=mutation-critical
```

Seeds and case counts are checked in. A failure can therefore be reproduced by
running the same command, while additions to a discovered corpus become normal
reviewable regression cases.

## Mutation score

[Infection](https://infection.github.io/) mutates all of `src` and judges each
mutant against the PHPUnit suite.

Mutation testing is **not** part of `tools/quality` or the Quality workflow: a
full-src run takes over an hour, which is too slow to gate a push. It runs
nightly at 03:00 UTC via `.github/workflows/mutation.yml`, which also accepts
`workflow_dispatch`. To run it locally:

```sh
php -d pcov.enabled=1 vendor/bin/infection --no-interaction --no-progress --threads=4
```

Scope it while iterating with `--filter=src/Mcp` (or any path) to keep the
feedback loop short.

The floor is defined by `minMsi` in `infection.json5`, currently **53%**. That
is the measured baseline, not a target: a full-src run on 2026-07-20 generated
8,782 mutants and 4,098 escaped, for an MSI of 53.34% in 1h20m. Mutation code
coverage is 100%, so those mutants _are_ executed by the suite -- they are just
not asserted against. Treat 53 as a ratchet to raise whenever tests improve;
`src/Mcp` alone measures 96.47%, so the headroom is in the rest of `src`.

The same engine backs Chaos-MCP's `audit_code_resilience` tool, so a green run
means that tool works against this repository too.

Equivalent mutants must be replaced with behavior-changing mutants rather than
counted as survivors or excluded. Lowering the floor requires the surviving
mutant report, a risk explanation, and explicit review.
