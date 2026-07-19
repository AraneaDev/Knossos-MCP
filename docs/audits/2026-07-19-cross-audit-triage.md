# Cross-audit triage — Knossos ↔ Chaos-MCP, 2026-07-19

Input to a Phase D fix-planning session. Synthesises two already fact-checked reports:

- `/root/Chaos-MCP/docs/audits/2026-07-19-knossos-architecture-scan.md` (Knossos analysing Chaos)
- `/root/Knossos-MCP/docs/audits/2026-07-19-chaos-mutation-audit.md` (Chaos analysing Knossos)

Every entry below was re-opened at its cited `file:line` on 2026-07-19 and re-confirmed, or
re-measured live against the same scan snapshot
(`project_f96c0e638b40794e42aac9dfee685905829dad0fb88862e8fa539aeb51f4c3a6`). A finding is
carried only if it can be stated as a concrete failure: specific input or state, specific
wrong output. Nine did not clear that bar and are listed under "Dropped". No finding struck
by a prior fact-check was reinstated. Nothing was fixed.

## 1. Chaos-MCP source findings

### 1.1 `walk` has no depth or breadth bound, so discovery materialises the whole tree — LOW

`src/triage.ts:56` recurses at `src/triage.ts:66` and appends at `src/triage.ts:69` with no
`depth` parameter and no cap on `out`; its sibling `collectByName` (`src/test-file.ts:74`)
guards with `if (depth > 8 || out.length >= 16) return;`.

**Failure.** Call `triage_test_coverage` with `paths: ["."]` on a monorepo holding 200 000
supported source files outside `IGNORE_DIRS` (`src/triage.ts:35`–`:45`): `walk` builds a
200 000-entry array, which `discoverFiles` (`src/triage.ts:88`) then dedupes, sorts, and
slices to `maxFiles` — default 25 (`src/triage-handler.ts:34`). 199 975 paths are collected
and immediately discarded, at full walk and sort cost.

**Fix.** Thread a depth parameter and an early return once `out.length >= maxFiles * k` into
`walk`, matching the bounds `collectByName` already applies.

## 2. Knossos source findings

### 2.1 `workers/python/bin/worker.py` is 697 lines with zero tests — HIGH

`workers/python/bin/worker.py:1`–`:697`. No `test_*.py` or `*_test.py` exists anywhere in the
repo outside `node_modules`.

**Failure.** Introduce any regression in the Python worker's protocol handling and run the
project's gates: `python3 -m pytest -x -q` at the repo root exits 5, `no tests ran`, and
`composer test` never invokes Python at all. The break reaches users unflagged. The Chaos
estimator sizes the untested surface at ~425 mutants.

**Fix.** Add a `pytest` suite covering the worker's stdio protocol and parse paths, and wire
it into the repo gate.

### 2.2 The mutation gate enforces `minimum_msi` against exactly one hard-coded file — MEDIUM

`tools/mutation-test.php:19` pins `$sourcePath` to `src/Scanner/Protocol/RelativePath.php`
and `:83` reports that single path as `target`; `benchmarks/mutation-score.json` sets
`minimum_msi: 90`.

**Failure.** Delete every assertion from the tests covering `RootGuard`, `QueryCommand`, or
any other PHP source file and re-run `php tools/mutation-test.php`: it still passes at ≥ 90
MSI, because the eight hard-coded mutations only ever touch `RelativePath.php`. A green
mutation gate is read as repo-wide evidence it does not supply.

**Fix.** Either widen the target list or rename the gate and its report field to state that
its scope is a single file.

### 2.3 No PHPUnit or Infection configuration exists, so no standard PHP mutation tool can start — MEDIUM

`composer.json:44` defines `"test": "php tests/run.php"`; `phpunit.xml*` and `infection.json*`
do not exist; `require-dev` carries php-cs-fixer and phpstan only.

**Failure.** Point any off-the-shelf PHP mutation tool at this repo — the Chaos-MCP run did
exactly that against `src/Discovery/RootGuard.php` — and it halts before a single mutant:
Infection 0.34.0 is installed globally and pcov is present, so the sole blocker is the
missing config. Repo-wide PHP mutation data is unobtainable without one.

**Fix.** Ship an `infection.json` whose `testFrameworkOptions` drives `php tests/run.php`,
superseding `tools/mutation-test.php`.

### 2.4 The TypeScript worker package declares no mutation tooling — MEDIUM

`workers/typescript/package.json:18`–`:21` lists `vitest ^3.2.0` and `@vitest/coverage-v8`
only; `workers/typescript/node_modules/@stryker-mutator` does not exist.

**Failure.** Run any Stryker-based audit against `workers/typescript/src/scanner.js` (1 007
lines, estimated ~564 mutants) and `npx --no-install stryker` cannot resolve a binary; the
run dies at spawn. The largest single resilience-untested surface in the repo.

**Fix.** Add `@stryker-mutator/core` to the worker package's devDependencies with a config
targeting its vitest suite.

### 2.5 `.knossos/` is an 81 MB gitignored artifact directory inside the repo root — LOW

`du -sm .knossos` → 81; `.gitignore:7` → `/.knossos/`.

**Failure.** Any tool that snapshots the workspace by a fixed exclusion list copies it: the
Chaos PHP sandbox measured 123 MB, of which 81 MB was `.knossos/` — 66 % of the copy, for
data with no bearing on the operation.

**Fix.** Relocate the cache outside the repo root (e.g. under the user cache dir) or make its
path configurable.

## 3. Chaos-MCP tool defects

### 3.1 The Python engine reports "the test suite fails" when no tests exist — HIGH

`src/engines/python.ts:253`.

**Failure.** Audit `workers/python/bin/worker.py` in a repo with no Python tests. The command
Chaos resolves (`python3 -m pytest -x -q`) exits 5 — pytest's documented "no tests were
collected" — yet the emitted message is `cosmic-ray baseline failed (exit 1). The test suite
fails before mutation testing begins. Fix the failing tests first.` The user hunts a broken
test that does not exist. Chaos already knew: `findPythonTestSelection`
(`src/handler.ts:376`) returned empty and the result was discarded.

**Fix.** Fail at `src/handler.ts:376` with "no Python test files found" when the selection is
empty, and branch on pytest exit 5 before asserting failing tests.

### 3.2 `estimate_audit` returns a confident count for targets no engine can mutate — MEDIUM-HIGH

`src/tool-schema.ts:433` positions it as "decide whether to audit now, scope down, or skip";
the estimator is a pure source heuristic that never touches the toolchain.

**Failure.** Call `estimate_audit` on `workers/typescript/src/scanner.js`: 564 mutants in
0.14 s, no warning — for a package where `audit_code_resilience` cannot start. Worse with
`withTiming: true`: the baseline was attempted, it failed, and the whole report of that is
the appended note suffix `(timing unavailable)` (`src/estimate.ts:144`, `:156`) with `isError` unset.
Chaos held the decisive fact and dropped it.

**Fix.** Run the existing engine-availability pre-flight during estimation and surface the
blocker (and the baseline failure reason) in the result.

### 3.3 The TypeScript engine's error names neither the cause nor a remedy — MEDIUM

`src/engines/typescript.ts:321`.

**Failure.** Audit a package whose `node_modules` lacks `@stryker-mutator/core`. The message
is `StrykerJS configuration or internal error (exit 1): npm error npx canceled due to missing
packages and no YES option: ["stryker@1.0.1"]`. The true cause is a missing dependency, not
configuration; `stryker@1.0.1` is an unrelated legacy npm package nobody in this stack uses,
so a reader plausibly tries to install it. No remedy is offered.

**Fix.** Read `node_modules/@stryker-mutator/core/package.json` in the same pass as
`installedVitestMajor` and emit "install `@stryker-mutator/core` in `<package>`" pre-flight.

### 3.4 The sandbox exclusion list is a fixed name set, so project caches are copied whole — LOW-MEDIUM

`src/utils/sandbox.ts:127` (`ALWAYS_EXCLUDE`), `:158` (`SYMLINK_DIRS`), `:386` (size warning).

**Failure.** Audit any `.php` file in Knossos: the sandbox copies `.knossos/` (81 MB) because
that name is in neither set, producing a 123 MB sandbox. `MAX_WORKSPACE_SIZE_BYTES`
(`src/utils/sandbox.ts:200`) is exceeded, a `warn()` fires at `:387`, and the copy proceeds
unconditionally.

**Fix.** Honour `.gitignore` entries (or skip directories above a size threshold) when
building the exclusion set.

### 3.5 The PHP config pre-flight runs inside the sandbox, after the copy — LOW

`src/engines/php.ts:241` calls `existsSync(join(cwd, n))` where `cwd` is the sandbox workDir.

**Failure.** Audit `src/Discovery/RootGuard.php`: Chaos copies 123 MB, launches Infection,
and only then checks the seven `PHPUNIT_CONFIG_NAMES` (`src/engines/php.ts:17`–`:25`) — a
check that costs seven `existsSync` calls against the real workspace. Every copied byte is
discarded. (The message itself is the best of the three and should be kept verbatim.)

**Fix.** Run the same `PHPUNIT_CONFIG_NAMES` check against the real workspace at the existing
`isComposerPhpAudit` hook (`src/utils/sandbox.ts:366`), before the copy.

## 4. Knossos tool defects

### 4.1 `architecture-summary --json` prints its payload twice, producing invalid JSON — HIGH

`src/Cli/Command/QueryCommand.php:141`–`:142` call `$c->output(...)` twice with identical
arguments.

**Failure.** `php bin/knossos architecture-summary "$PID" --json > out.json` then
`json_decode(file_get_contents('out.json'))` returns `null`: the file holds two concatenated
JSON documents. Every scripted consumer of this command silently gets nothing. It is the only
one of the query commands that does this.

**Fix.** Delete line 142.

### 4.2 Two thirds of dead-code candidates are test modules, which cannot have inbound edges — MEDIUM-HIGH

Nomination guard `src/Query/GraphTopologyQueryService.php:299` is `in_degree === 0`.

**Failure.** `architecture-health "$PID" --limit=100` on Chaos-MCP returns 100 candidates of
which 67 are `src/__tests__/*.test.ts` (re-measured live today). A Vitest module is discovered
by glob and invoked by the runner, so its in-degree is 0 by construction in every project —
these 67 are structurally guaranteed noise, not a judgement about the repo. With the 21 in
4.3, 88 of 100 rows are unusable.

**Fix.** Exclude test and config entrypoints from nomination by default, or tag each candidate
with a role so callers can filter.

### 4.3 Dead-code detection cannot see identifier-as-value references, and states the negative as fact — MEDIUM-HIGH

`src/Query/GraphTopologyQueryService.php:299`–`:308`; the `reason` string is hard-coded to
"No selected inbound static dependency references this component."

**Failure.** `src/triage.ts#compareTriageRows` is exported, statically imported at
`src/triage-handler.ts:11`, and used at `src/triage-handler.ts:407` and `src/triage.ts:162` —
yet it is nominated dead with `confidence: "probable"` and that reason, which is false, not
merely uncertain. Same for all 15 `src/tool-args-validation.ts#validate*Arg` (referenced in
the `TOOL_ARG_VALIDATORS` literal at `:270`–`:286`), the four `parse*Config` functions
(`src/utils/config-loader.ts:340`–`:343`) and `detectRawPhpRunner`
(`src/utils/project-detector.ts:559`) — 21 provably-referenced symbols.

**Fix.** Emit a `references` edge when an identifier is used as a value, and downgrade
unresolved cases to `possible` with uncertainty text that names registry/callback references.

### 4.4 The dead-code slice is sorted alphabetically before truncation, at a limit that cannot be raised — MEDIUM

`src/Query/GraphTopologyQueryService.php:318` sorts `$deadCandidates` by `canonical_name`
alone; `:327` then slices to `$limit`. `hubs`/`hotspots` are score-ranked at `:312`–`:317`.

**Failure.** `--limit=100` returns `truncated: true`,
`truncation_reasons: ["result_limit"]`, and `--limit=101` is rejected outright
(`KNOSSOS_INVALID_ARGUMENT: --limit must be between 1 and 100.`). Verified live: the 100
returned names are in strict alphabetical order, `eslint.config.js` … `vitest.config.ts`. The
user therefore sees the alphabetically-first 100 candidates, never the most probable ones, and
no command pages past them.

**Fix.** Rank `$deadCandidates` (by `out_degree`, `confidence`, or role) before the slice.

### 4.5 Hub ranking is dominated by ambient TypeScript types and `node_modules` declarations — MEDIUM

`src/Query/GraphTopologyQueryService.php:292` scores hubs by raw degree with no kind filter.

**Failure.** On Chaos-MCP the top three hubs are `Promise` (86), `Error` (75), `Record` (66),
all `external_class`; six of the top 14 carry `node_modules/` paths (re-measured live). The
first screen of `architecture-health` on a TypeScript repo describes the language, not the
codebase. File discovery correctly excluded `node_modules`, so a user who checked the scan
output for leakage is actively surprised here.

**Fix.** Exclude `external_*` kinds from hub ranking by default, or add `--first-party-only`.

### 4.6 `architecture-context` replaces oversized sections with stubs instead of trimming them — MEDIUM

**Failure.** `architecture-context "$PID" src/handler.ts --json` at the default budget: both
`change_impact` (56 532 chars) and `dossiers` (12 561) come back
`status: "truncated", reason: "section_budget"`, and `actual_chars` is 1 685 of a 30 000
budget — 94 % unused. Raising to the CLI maximum (`--max-chars=100000`; 100 001 is rejected)
recovers `dossiers` but not `change_impact`, whose 56 532 chars still exceed the 30 000
allocated. Re-measured live today: that section is unreachable for this file at any permitted
budget, on a repo whose largest module is 969 lines.

**Fix.** Truncate a section's content to its allocation and reallocate unused sibling budget,
rather than dropping the section.

### 4.7 Inferred boundaries do not partition anything, so boundary metrics are inert — MEDIUM

**Failure.** `list-boundaries "$PID"` returns 6 boundaries; three (`node:chaos-mcp`, both
`typescript:tsconfig*`) have an empty `path_prefix` and 1 070 members each — the whole repo —
and the two tsconfig boundaries are indistinguishable. Consequence measured live:
`cross_boundary_degree` is 0 for all 100 hubs, so the hotspot score
(`src/Query/GraphTopologyQueryService.php:296`,
`degree + 2·cross_boundary_degree + 3·cycle_participant`) collapses onto the hub score
(`:292`, `degree`) and both lists return the same 100 components in the same order. The one
metric that depends on boundaries contributes nothing.

**Fix.** Drop empty-prefix boundaries from membership tagging and add a test/production split.

### 4.8 `next_steps` is emitted for four tools only, and points at a language built-in — MEDIUM

`src/Mcp/NextStepPlanner.php:24`–`:29` matches `find_component`, `inspect_component`,
`impact_analysis`, `architecture_health` and returns `[]` otherwise; `afterHealth`
(`:113`–`:128`) takes `static_hotspots[0]` with no kind filter.

**Failure.** Over stdio JSON-RPC, `architecture_health` on Chaos-MCP returns exactly one
suggestion: `inspect_component {"component":"Promise"}`, "inspect the top structural
hotspot" — sending the caller to inspect a TypeScript built-in. Meanwhile
`dependency_cycles` returns `next_steps: null` after reporting 3 cycles, as do
`architecture_summary`, `file_metrics`, `list_boundaries` and `architecture_context`.
Separately, `ResultEnricher` is wired only into `ServeCommand`
(`src/Cli/Command/ServeCommand.php:34`), so CLI users get no `staleness` signal at all.

**Fix.** Skip `external_*` hotspots when choosing the suggestion, and add planner arms for
`dependency_cycles` and `file_metrics`.

### 4.9 `dependency-cycles` reports single-function recursion as a dependency cycle — LOW-MEDIUM

**Failure.** Re-measured live: on Chaos-MCP the command reports
`"Found 3 dependency cycle components."` and all three are size-1 SCCs — a function calling
itself (`src/test-file.ts#collectByName`, `src/triage.ts#walk`,
`src/__tests__/e2e-mcp.test.ts#walkForHash`). Chaos-MCP has no genuine multi-module cycle at
all, i.e. a good result, and the tool headlines it as three defects. Dismissing them cost
real time on the Phase A run.

**Fix.** Exclude size-1 SCCs from the cycle count by default, or report them under a separate
`self_recursive` key.

### 4.10 Scans report diagnostic and unresolved-node counts that no command can display — LOW

**Failure.** Every scan returns `"diagnostics": 248` and `"unresolved_nodes": 304`.
`php bin/knossos --help` lists no command matching "diagnos" or "unresolved" (grep returns
nothing). A user cannot see which 248, so cannot judge whether the 4 455 edges are
trustworthy, and cannot act on the number at all.

**Fix.** Add a `diagnostics <project-id>` command (or a `--with-diagnostics` flag on `scan`).

## 5. Dropped during re-verification

Nine entries from the two reports are not carried. None is softened; each failed the
concrete-failure test or is not a defect.

| Dropped                                             | Origin            | Why                                                                                                                                                                      |
| --------------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `handleTriageCall` is a ~370-line function          | Knossos scan §2.1 | Size and fan-out only; no input produces a wrong output. The report itself rates it "no defect proven".                                                                  |
| `src/handler.ts` is 969 lines with a 287-line entry | Knossos scan §2.2 | Same: a responsibility count, not a failure.                                                                                                                             |
| Test code outweighs product code 2:1                | Knossos scan §2.4 | Maintainability observation; no behaviour depends on the ratio.                                                                                                          |
| Shared arg validation is entrypoint-specific        | Knossos scan §2.5 | The correctness half was already withdrawn (§4.5: `timeoutMs` is independently guarded, schema is `additionalProperties: false`); the residual asymmetry has no failure. |
| Incremental scan reports 12 fewer edges             | Knossos scan §4.3 | Unreproducible — Knossos keys re-parse on content hash, so it could not be re-triggered without editing Chaos source.                                                    |
| Unbounded recursion in `walk` as a crash risk       | Knossos scan §4.4 | `Dirent.isDirectory()` is false for symlinks, so no symlink loop is reachable; only the breadth issue survives, as 1.1.                                                  |
| Workspace scoping is enforced correctly             | Chaos audit §C6   | Correct behaviour with a good message — recorded as a positive, not a defect.                                                                                            |
| Cleanup and workspace safety are sound              | Chaos audit §C7   | Correct behaviour; the only correction was wording (`/tmp/chaos-mcp-runs` persists by design).                                                                           |
| "Zero mutation data exists for Knossos"             | Chaos audit §K1   | Overbroad as stated; the actionable, verifiable core is kept as 2.2 (the gate covers exactly one file).                                                                  |

## 6. Calibration of the advance predictions

### Prediction 1 — Chaos source: "import cycles and one oversized module" — PARTIAL

The oversized module landed: `src/handler.ts` is 969 lines with a 287-line `handleToolCall`
(`src/handler.ts:683`) and `handleTriageCall` spans `src/triage-handler.ts:48`–`:417`. But it
landed as an observation, not a defect, and was dropped from this triage for exactly that
reason — the prediction was right about the shape and wrong about it mattering.

Import cycles were **refuted**. `dependency-cycles` reports 3 components and all three are
size-1 self-recursion; Chaos-MCP has no multi-module import cycle. The prediction's hit rate
here is zero, and the cycles result inverted into a Knossos tool defect (4.9) instead.

### Prediction 2 — Chaos tool: "poor-to-mediocre error messages on both blocked engines" — PARTIAL

Three engines blocked, not two. Two messages were poor or worse: TypeScript named the wrong
cause and an unrelated package (3.3), and Python asserted something false (3.1). The third
refutes the prediction outright: the PHP message (`src/engines/php.ts:244`–`:248`) names the
dependency chain, lists all seven filenames it searched, infers the correct diagnosis, and
offers two valid remedies. It is the best error of the run and the audit records it as a good
result. So: confirmed on two engines, refuted on one, and the prediction did not anticipate
that the most impactful Chaos defect would be the _silent_ one (3.2, `estimate_audit`), which
emits no error at all.

### Prediction 3 — Knossos tool: "dead-code false positives on MCP entrypoints" — REFUTED

The prediction concerned MCP entrypoints reached via a JSON-RPC dispatch table. Knossos got
that case right. Re-measured live today: `src/index.ts:97`–`:105` dispatches by tool name and
Knossos resolved those references — `handleToolCall` in-degree 4, `handleTriageCall` 2,
`handleEstimateCall` 2 — and none of `src/index.ts` or the three handlers appears among the
100 dead-code candidates (the only "handle*" rows in that list are test modules).

Knossos _does_ have a large dead-code false-positive problem (4.2, 4.3, 88 of 100 rows), and
it would be easy to score that as a hit. It is not one. Those false positives come from two
mechanisms the prediction did not name: in-degree-0 test modules, which are noise by
construction in every project, and identifier-as-value references inside registry literals.
Dispatch-table resolution — the specific thing predicted — works. Recorded as refuted.

**Net: 0 of 3 clean hits, 2 partial, 1 refuted.** The predictions did not shape the triage;
the two highest-severity items on each side (4.1 duplicate JSON output, 3.1 false Python
diagnosis, 2.1 untested Python worker) were anticipated by none of them.

## 7. Suggested Phase D ordering

Phase D is not part of this task. Ranked by impact ÷ effort:

1. **4.1 — duplicate `architecture-summary --json` output.** One line to delete
   (`QueryCommand.php:142`); it currently makes a shipped command's machine-readable output
   undecodable. Highest ratio in the document by a wide margin.
2. **4.2 + 4.3 — dead-code nomination filter and identifier-as-value references.** One
   role/kind filter plus one edge kind turns the flagship "unreferenced code" result from
   88 % noise into something a caller can read. Both live in one query service.
3. **3.1 — Python "the test suite fails" when no tests exist.** A branch on pytest exit 5
   plus surfacing the already-computed empty `findPythonTestSelection`; removes an actively
   false diagnosis that sends users hunting a nonexistent bug.

Next tier, in order: 3.2 (`estimate_audit` pre-flight — highest user-time cost but needs real
engine-availability plumbing), 3.3 (TypeScript pre-flight dependency check), 4.4 (rank before
slice), 4.5 (`external_*` filter), 4.9 (drop size-1 SCCs). Deferred as genuinely larger work:
2.1 (Python test suite), 2.3 (Infection config), 4.6 (section budget reallocation), 4.7
(boundary model).

## 8. Verification

- `git status --short` in both repositories: empty before this triage was written. No
  production source was modified in either repository by Phases A–C.
- Re-measurements above were taken against the existing scan snapshot; no rescan was needed
  and none was performed.
