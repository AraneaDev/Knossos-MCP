# Chaos-MCP mutation audit of Knossos — 2026-07-19

Cross-audit dogfeed: Chaos-MCP v1.2.4 (a mutation-testing MCP server) was pointed at
Knossos-MCP. Chaos was expected to fail on most of Knossos; the object under study is the
**quality of each failure** — whether the error names the true cause, how long the user waits
before learning it, and whether a cheaper check was available first.

No Knossos source was modified, and no engine was installed or downgraded to make a tool work.

## 1. What was run

All timings are wall-clock, measured inside a single stdio JSON-RPC client process
(spawn server → `initialize` → `tools/call`). Server start-up is ~0.14 s and is included in
every number, so 0.14 s is the measurement floor.

| #   | Call                    | Arguments                                                                 | Workspace | Wall-clock | Outcome                                 |
| --- | ----------------------- | ------------------------------------------------------------------------- | --------- | ---------- | --------------------------------------- |
| 1   | `estimate_audit`        | `filePath: /root/Chaos-MCP/src/gate.ts`                                   | Knossos   | < 0.1 s    | Rejected: path outside workspace        |
| 2   | `estimate_audit`        | `filePath: src/gate.ts`                                                   | Chaos-MCP | 0.14 s     | OK — 35 mutants (control)               |
| 3   | `audit_code_resilience` | `src/gate.ts`, `lineScope 1–40`, `timeoutMs 300000`                       | Chaos-MCP | 139.75 s   | OK — 39/39 killed, 100 % (control)      |
| 4   | `estimate_audit`        | `src/Discovery/RootGuard.php`                                             | Knossos   | 0.15 s     | OK — 24 mutants                         |
| 5   | `estimate_audit`        | `src/Discovery/RootGuard.php`, `withTiming: true`                         | Knossos   | 1.01 s     | OK — 24 mutants, `(timing unavailable)` |
| 6   | `audit_code_resilience` | `src/Discovery/RootGuard.php`, `timeoutMs 240000`                         | Knossos   | 1.03 s     | Halted — no PHPUnit config              |
| 7   | `estimate_audit`        | `workers/typescript/src/scanner.js`                                       | Knossos   | 0.14 s     | OK — 564 mutants                        |
| 8   | `audit_code_resilience` | `workers/typescript/src/scanner.js`, `lineScope 1–60`, `timeoutMs 240000` | Knossos   | 2.50 s     | Halted — StrykerJS not installed        |
| 9   | `estimate_audit`        | `workers/python/bin/worker.py`                                            | Knossos   | 0.14 s     | OK — 425 mutants                        |
| 10  | `audit_code_resilience` | `workers/python/bin/worker.py`, `timeoutMs 240000`                        | Knossos   | 1.74 s     | Halted — cosmic-ray baseline            |

Call 1 established that the MCP server's workspace is fixed to its process CWD
(`rootCwd = resolve(process.cwd())`), so the control had to be run against a second server
instance rooted in the Chaos repo. Calls 2 and 3 are that control: the full
sandbox → Stryker → report pipeline works end to end in this environment, which makes every
Knossos failure below attributable rather than ambiguous.

Workspace sizes measured before any sandbox work:

```text
257M  /root/Knossos-MCP
 81M  /root/Knossos-MCP/.knossos
 63M  /root/Knossos-MCP/workers/typescript/node_modules
 47M  /root/Knossos-MCP/node_modules
 36M  /root/Knossos-MCP/vendor
 19M  /root/Knossos-MCP/coverage
4.4M  /root/Knossos-MCP/.git
```

## 2. Findings about Knossos

**K1 — No engine could reach a single Knossos mutant.** Three languages, three different
blockers, zero mutation data. Knossos's testability from the perspective of off-the-shelf
mutation tooling is currently nil.

**K2 — The PHP suite is invisible to standard tooling.** Knossos runs `php tests/run.php`, a
custom closure-registering runner with no `phpunit.xml`, no `infection.json`, and no
PHPUnit/Pest adapter. Infection 0.34.0 _is_ installed globally on this machine and pcov is
present, so the only thing standing between Knossos and real PHP mutation data is an
Infection config targeting the custom runner. That is a genuine, actionable gap — recorded
here as an observation, not fixed, per this task's scope.

**K3 — The TypeScript worker has no mutation tooling of its own.**
`workers/typescript/package.json` declares `vitest ^3.2.0` and `@vitest/coverage-v8` but no
`@stryker-mutator/*` dependency, so no mutation run can start from inside that package.
The estimator puts `src/scanner.js` at ~564 mutants — the single largest untested-for-
resilience surface in the repo.

**K4 — The Python worker has no tests at all.** `workers/python/bin/worker.py` is 697 lines
with zero `test_*.py` files anywhere in the repo; `python3 -m pytest -x -q` at the repo root
exits 5 ("no tests were collected"). The estimator puts the file at ~425 mutants. This is the
clearest real finding of the exercise: an untested 697-line worker.

**K5 — `.knossos/` is 81 MB inside the repo.** It is the largest directory in the tree after
the vendored dependency trees, and it is neither source nor a dependency. Any tool that
snapshots the workspace pays for it (see C3).

## 3. Findings about Chaos-MCP

### C1 — PHP: excellent error, but paid for after the copy

Verbatim:

```text
Chaos Engine Halted: Infection could not run: no PHPUnit configuration found (looked for
phpunit.xml, phpunit.xml.dist, phpunit.dist.xml, phpunit.yml, phpunit.yml.dist,
phpunit.dist.yml, phpunit.php). Chaos-MCP's PHP engine drives Infection, which requires
PHPUnit; this project appears to use a different or custom test runner. Add a phpunit.xml,
or ship an Infection config (infection.json/infection.json5) that targets your framework.
```

- **Did it name the true cause?** Yes, exactly. It lists every filename it looked for, states
  the dependency chain (Chaos → Infection → PHPUnit), infers the correct diagnosis ("this
  project appears to use a different or custom test runner"), and gives two valid remedies.
  This is the best error of the three and is worth recording as a good result.
- **How long did the user wait?** 1.03 s.
- **Cheaper check available first?** Yes. The seven filenames were checked _inside the
  sandbox_, after a 123 MB copy. The identical check against the real workspace costs seven
  `existsSync` calls. Chaos already runs `isComposerPhpAudit()` on the real workspace before
  copying, so a pre-flight hook exists at the right point in the code.

### C2 — TypeScript: fast, but the error does not name the cause

Verbatim:

```text
Chaos Engine Halted: StrykerJS configuration or internal error (exit 1): npm error npx canceled
due to missing packages and no YES option: ["stryker@1.0.1"]
```

- **Did it name the true cause?** No. The true cause is that
  `workers/typescript/node_modules` contains no `@stryker-mutator/core`. The message says
  "configuration or internal error", which points the user at their config; and the raw npm
  text names `stryker@1.0.1`, an unrelated legacy npm package that nobody in this stack uses.
  A reader would plausibly conclude Chaos wants a package called `stryker` at version 1.0.1.
  No remedy is suggested — the correct one is `npm i -D @stryker-mutator/core` in the target
  package.
- **How long did the user wait?** 2.50 s. Fast, and the sandbox was only 1 MB (see C3), so the
  cost of the wrong message is confusion, not time.
- **Cheaper check available first?** Yes, and trivially: Chaos already resolves the package
  root and already reads `node_modules/vitest/package.json` to detect the vitest major
  version. Reading `node_modules/@stryker-mutator/core/package.json` in the same pass would
  turn this into a precise pre-flight error.

**Detection order note.** The predicted blocker — StrykerJS 9's vitest-runner versus
vitest 3 — never fired, because a more basic missing-dependency failure came first, and
because Chaos already mitigates the vitest-3 case (see N1). The ordering here is correct:
cheap dependency failure before expensive runner negotiation.

### C3 — Sandbox cost: correct in TypeScript, wasteful in PHP and Python

Measured by polling `/tmp/chaos-mcp-*` at 0.4–0.5 s intervals during each run.

| Run        | Sandbox peak | `node_modules` | `vendor`       | Copy time | Copy that was used                |
| ---------- | ------------ | -------------- | -------------- | --------- | --------------------------------- |
| PHP        | 123 MB       | symlinked      | copied (36 MB) | ~1 s      | none — halted before any test ran |
| TypeScript | 1 MB         | symlinked      | n/a            | < 0.5 s   | none — halted at `npx`            |
| Python     | 87 MB        | symlinked      | copied         | ~1 s      | none — halted at baseline         |

What Chaos gets right: `node_modules`, `.git`, `coverage`, `dist`, `build`, `.venv`,
`__pycache__` are excluded or symlinked, including the _nested_
`workers/typescript/node_modules` (63 MB); and for a TypeScript target it correctly narrows
the workspace root to the `workers/typescript` package, producing a 1 MB sandbox instead of a
123 MB one. Copying `vendor/` rather than symlinking it for Composer targets is a deliberate,
documented correctness decision (`__DIR__` resolves through symlinks in PHP), not sloppiness.

What it gets wrong: 81 MB of that 123 MB PHP sandbox is `.knossos/`, a repo-local artifact
directory with no bearing on mutation testing. The exclusion list is a fixed set of
well-known names, so any project-specific cache directory is copied in full. A size-based or
`.gitignore`-aware guard would help. Chaos does compute a workspace-size estimate and warns
above 200 MB, so the mechanism to notice this already exists — it just does not act on it.

Every byte copied in all three Knossos runs was wasted work, because all three runs halted
before executing a single test.

### C4 — Python: the error asserts something that is false

Verbatim:

```text
Chaos Engine Halted: cosmic-ray baseline failed (exit 1). The test suite fails before mutation
testing begins. Fix the failing tests first. Details: Command exited with code 1: cosmic-ray
```

- **Did it name the true cause?** No — and this is the worst of the three, because the message
  is not merely vague but wrong. "The test suite fails" and "Fix the failing tests first" both
  assert failing tests. There are none. Running the exact command Chaos resolves
  (`python3 -m pytest -x -q`, with no path selection because Chaos's own
  `findPythonTestSelection` found no test files) gives `no tests ran in 0.27s`, exit code 5 —
  pytest's documented "no tests were collected" code, not a failure code. A user following
  this message would go hunting for a broken test that does not exist. The `Details:` field
  adds nothing (`Command exited with code 1: cosmic-ray`); cosmic-ray's stderr is discarded.
- **How long did the user wait?** 1.74 s.
- **Cheaper check available first?** Yes, twice over. Chaos calls `findPythonTestSelection`
  and it returned empty — Chaos already _knew_ there were no test files and proceeded anyway.
  Failing at that point with "no Python test files found under tests/ or alongside the target"
  would be both faster and true. Separately, distinguishing pytest exit 5 from a real failure
  is a one-line classification.

### C5 — `estimate_audit` is cheerful about repositories that cannot be audited

All four estimates returned confident mutant counts (24 / 564 / 425) in ~0.15 s for files that
no engine can actually mutate in this repo. The estimator is a pure source heuristic and never
touches the toolchain, so it gives no signal about feasibility. The tool's own description
positions it as the pre-flight step — "Use this before `audit_code_resilience` to decide
whether to audit now, scope down, or skip" — but it cannot support a skip decision, because it
never checks whether an engine could run.

The `withTiming: true` variant is the sharper version of the same problem: it _did_ try to
measure a baseline, the baseline _did_ fail, and the entire report of that fact was the note
suffix `(timing unavailable)` — no reason, no warning, no error. At that moment Chaos held
exactly the information the user needed and discarded it.

### C6 — Workspace scoping is enforced correctly and instantly

```text
Error: filePath must resolve within the workspace (/root/Knossos-MCP); received "/root/Chaos-MCP/src/gate.ts".
```

Immediate, names the boundary, names the received value, names the workspace root. Correct
behaviour and a good message. Worth noting as an operational constraint: because the workspace
is the server's process CWD, one server instance can only ever audit one repo, which is why
the control run required a second instance.

### C7 — Cleanup and workspace safety are sound

After ten calls: `git status --short` is empty, no `/tmp/chaos-mcp-*` directories remain, and
none of `.chaos-mcp/`, `.stryker-tmp/`, `chaos-cosmic-ray.toml`, `.chaos-infection-tmp/`, or a
generated `stryker.config.json` leaked into the real tree. Generated engine configs were
observed inside the sandbox only. The "your real working tree is never touched" claim held
under three engines and two failure modes.

## 4. Not findings

**N1 — "StrykerJS 9 is incompatible with vitest 3."** Investigated and discarded as a _current_
blocker. Chaos detects the installed vitest major version by reading
`node_modules/vitest/package.json` and falls back from `@stryker-mutator/vitest-runner` to
Stryker's built-in command runner when it sees vitest 3. The control run (call 3) audited
Chaos's own vitest-3 codebase end to end and returned 39/39 mutants killed, so the
incompatibility is mitigated in v1.2.4 and never became relevant to Knossos. The cost of the
mitigation is visible, though: 139.75 s for 39 mutants on a small file, because the command
runner re-runs a scoped test command per mutant with no coverage instrumentation.

**N2 — "Chaos tried to download `stryker@1.0.1` from the network."** Discarded. The npm text is
alarming, but `src/engines/typescript.ts` passes `npx --no-install`, and the message
("canceled due to missing packages and no YES option") is precisely npm reporting that it
_declined_ to install. No supply-chain issue. The finding here is message quality (C2), not
behaviour.

**N3 — "Infection is missing, so the PHP audit could never work."** Discarded — this was the
task's stated premise and it is factually wrong on this machine. `/usr/local/bin/infection`
reports version 0.34.0, and Chaos's PHP engine explicitly falls back from
`vendor/bin/infection` to a global `infection` on PATH. Infection ran; it failed on the
missing PHPUnit configuration, which is a different and more interesting failure.

**N4 — "The sandbox copies the vendored trees."** Discarded as stated. `node_modules` (both the
root 47 MB and the nested 63 MB), `.git`, and `coverage` were all confirmed absent from or
symlinked into every sandbox. `vendor/` is copied only for Composer PHP targets and only for a
documented correctness reason. The real waste is `.knossos/` (C3), which is a different claim.

**N5 — "Chaos hung / needed to be timed out."** Discarded. No call approached the guard. The
three Knossos audits returned in 1.03 s, 2.50 s, and 1.74 s; the slowest call in the whole
exercise was the deliberate 139.75 s control against Chaos's own repo. Failing fast is a
strength of this tool, and the `timeoutMs` bound was never exercised.

**N6 — "Chaos modified the Knossos worktree."** Investigated because the sandbox symlinks
`node_modules` back into the real tree, which is a plausible write path. Nothing was written:
`git status --short` was empty before and after, and no untracked engine artifacts appeared.
See C7.

**N7 — Triage across the whole repo (`triage_test_coverage`).** Not run. With every engine
blocked at step one, a batch run would have produced N copies of the same three errors and N
sandbox copies of a 257 MB workspace, at real cost and zero additional information.
