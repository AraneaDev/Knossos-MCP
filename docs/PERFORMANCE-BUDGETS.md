# Performance budgets

Knossos ships a deterministic generated benchmark corpus and enforced budgets
for cold scans, incremental scans, representative queries, peak process-tree
resident memory, and SQLite size.

Run the benchmark in the pinned quality image:

```sh
docker build --target quality -t knossos-mcp:quality .
docker run --rm \
  --mount type=bind,source="$PWD/coverage",target=/opt/knossos/coverage \
  --entrypoint tools/benchmark knossos-mcp:quality
```

The full quality profile runs the same command automatically. Results are
written to `coverage/benchmarks/report.json`, which CI uploads in the
`quality-reports` artifact. Any exceeded budget makes the command exit `1`;
invalid benchmark configuration or execution failure exits `2` or higher.

## Corpus and measurements

Each size generates equal PHP, TypeScript, and Python dependency chains from a
fixed template. The report records a SHA-256 digest over sorted relative paths
and contents, making corpus drift reviewable. No generated corpus is checked in
or retained after the run.

For every size, the runner uses an isolated SQLite database and records:

- a full cold scan;
- a one-file edit followed by an incremental scan;
- an `architecture-summary` query;
- peak RSS for the CLI and its scanner-worker process tree; and
- the resulting SQLite database size.

Each cold and incremental result also carries `stages_ms` for configuration,
discovery, incremental planning, each language scanner, classification/
boundary analysis, and reconciliation. These in-process timers identify the
responsible subsystem without changing protocol output or requiring a profiler
extension. On the current 300-file reference run, the compiler-backed
TypeScript worker startup dominates at roughly 1.18 seconds; reconciliation is
the next largest measured stage at roughly 148 ms cold and 276 ms while also
archiving the prior snapshot incrementally.

The graph repository now caches prepared statements for its repeated file,
node, edge, diagnostic, classification, boundary, membership, and contribution
writes within a reconciliation. A targeted regression test proves repeated hot
writes reuse the prepared statement while the full-vs-incremental differential
suite proves graph equivalence. The post-change reference run measured
small/medium/large cold scans at 1.37/1.42/1.51 seconds and incremental scans at
1.30/1.36/1.57 seconds. The earlier reference was 6.15/3.26/3.12 seconds cold
and 2.87/2.83/3.18 seconds incremental; because host load and caches differ,
these are recorded observations rather than attribution of the entire change
to statement reuse.

Budgets live in [`benchmarks/budgets.json`](../benchmarks/budgets.json). The
small, medium, and large profiles currently generate 18, 90, and 300 source
files respectively. Limits are intentionally high enough for shared CI runners
but low enough to catch hangs, lost incremental behavior, runaway workers, and
gross storage regressions.

Timing and RSS values vary with hardware, load, filesystem, and cold runtime
caches. Compare trends from equivalent pinned runners. Tightening a budget
requires a successful report from representative CI; relaxing one requires the
before/after artifact and a reviewed explanation.

The measured improvement allowed monotonic tightening of every time/storage
budget and the medium/large RSS budgets. Current ceilings are deliberately
still above both reference runs to accommodate shared-runner variance; they are
regression limits, not expected performance.
