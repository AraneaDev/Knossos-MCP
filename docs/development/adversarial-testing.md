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
php tests/run.php --group=property
php tests/run.php --group=mutation-critical
```

Seeds and case counts are checked in. A failure can therefore be reproduced by
running the same command, while additions to a discovered corpus become normal
reviewable regression cases.

## Mutation score

`tools/mutation-test` applies reviewed semantic mutants to the critical
project-relative path validator. The mutants model acceptance of empty,
absolute, traversal, null-byte, and non-normalized paths. Each mutant runs in
an isolated process through `auto_prepend_file`; production source is never
rewritten.

```sh
tools/mutation-test
```

The gate writes `coverage/mutation/report.json`. Its versioned floor is defined
in [`benchmarks/mutation-score.json`](../../benchmarks/mutation-score.json) and is
currently 90% mutation score. The initial suite kills all eight reviewed
mutants for a 100% score. The pinned full quality profile enforces the floor and
CI retains the report.

Equivalent mutants must be replaced with behavior-changing mutants rather than
counted as survivors or excluded. Lowering the floor requires the surviving
mutant report, a risk explanation, and explicit review.
