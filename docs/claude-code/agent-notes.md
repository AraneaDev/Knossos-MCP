# Notes for the model

The mod tells the model a few facts at the moment they help, so it keeps to
the architecture instead of being told afterwards that it broke it. Every
note is one short line, said once, and none costs a process: the figures
come from the last dashboard load.

**Before an edit.** When the model Reads a file with at least
`fanInThreshold` dependent files, or a file in a boundary a declared policy
binds, it reads one line after the file:

```text
knossos: src/Boundary/BoundaryInference.php (core) has 269 dependent files. Policy: core may not depend on php-worker, typescript-worker, python-worker, rust-worker, tooling, tests.
```

The rules of a boundary are stated once per session: the next file read in
`core` gets its count alone (`knossos: src/Bundle/GraphBundleDecoder.php
(core) has 244 dependent files.`), and a quiet file there gets nothing. A
quiet file in a policed boundary nobody read yet gets the boundary and its
rules (`knossos: workers/php/bin/worker is in php-worker. Policy: php-worker
may not depend on core.`). Only a declared boundary is named; a file in an
inferred one only, or in none, gets its count. A rule with an allow list
reads `edge may depend only on itself, core, unassigned code`, and one
limited to some dependency kinds names them in brackets. The rules come with
the dashboard (`policy.rules`), with each policed boundary
(`policy.boundaries`): a boundary declared by `path_prefix` binds every file
under that prefix, however large the project. Only the files of a boundary
placed by namespace are listed (`policy.files`, at most 2,000 files). When
that list stops at its cap before it reaches a file, the note does not read
as "no rules": it says `knossos: lib/Late.php: rules may bind this file; run
check_architecture.`

**On an edit.** An edit or write of a file with at least `fanInThreshold`
dependent files that the model was not told about on Read (Claude Code reads
a file before it edits it, so this is rare) adds one line, which you also see
as a toast:

```text
knossos: src/Router.php has 41 dependent files across 3 boundaries; run test_impact before finishing.
```

**After a turn.** When the turn's scan finds tests that reach the files it
changed, the model reads which, and the command that runs them, chosen as on
the Changes tab. A test the turn already ran after its last edit is left
out: a Bash command that runs its runner and names the test, a directory
holding it, or no test at all (the whole suite, such as `vendor/bin/phpunit`
or `npm run test:mod`). A test named in an earlier note is not named again.
Tests the command names itself (PHPUnit, Vitest, Jest, pytest) are not
listed twice. A JavaScript test runs with the runner its nearest
`package.json` names (Vitest or Jest, in its dependencies or its `test`
script); when that is ambiguous or unknown, the test is listed and no
command is given for it:

```text
knossos: 3 tests reach this turn's changes. Run: vendor/bin/phpunit --filter '(DashboardServiceTest|FileFanInQueryTest|TurnBriefServiceTest)'
```

This line and the policy note below arrive together, as one note.

**Limits.** Each file is noted once per session and per agent: a subagent
gets its own notes, since what the main loop read was never in its context.
At most three notes follow tool results in one turn; a note held back by
that cap is said at the next Read or edit of the file. A note that fails
leaves the tool result as it was and one line in the debug log. A turn ends with at most one
note. `agentNotes` turns them all off; the toast stays.

**After a commit.** When a Bash call in any loop made a commit in the
project's repository, the call's result carries one note. A commit is told by
the project's HEAD: the mod reads it (`git rev-parse`) before and after the
command, and when it moved, reads HEAD's reflog for an entry since then that
made a commit (`commit`, an amend, `cherry-pick`, `revert`, or a merge that
made a merge commit). So a `git commit` inside a string, a dry run, nothing to
commit, a reprinted commit line, a commit in another repository, a checkout, a
reset or a fast-forward says nothing, while a merge commit, a `--quiet`
commit, one piped through `tail` and one made through an alias or a script are
all seen. Without a reflog, git's own line for a commit
(`[main abc1234] subject`) in what the command printed decides.

The note says what this session's changes leave behind, as far as the graph
knows: the boundary-policy violations the session's turns introduced that the
policy check still reports (only a check at least as new as the turn that
found a violation can drop it: while the pane's graph is older, after a reload
that failed or has not landed, the violation is kept), the files the session
changed (not ones changed outside it since it began) that no test reaches,
and the cycles new since the session began. A cycle is named new only when
the graph listed every cycle it held as the session began; past that list's
cap only the count that grew is said. The note speaks of the session's
changes, not of the commit, since a commit need not hold them all:

```text
knossos: this session's changes carry 1 changed file no test reaches (src/Kernel.php); 1 dependency cycle new since the session began (Router → Kernel). Check them before you push.
```

Nothing new, no note. The same note is said once per loop, counts against
the three notes a turn, and `agentNotes` turns it off.

**On request.** The mod registers a tool the model calls itself,
`knossos_context` (listed as `mcp__knossos__knossos_context`; the mod answers
it by the name its registration returns), with one
argument, `path` (relative to the project or absolute). One call answers in
a few lines, at most 2,000 characters: the file's boundary, language, lines
and components; how many files depend on it, in which boundaries, and the
closest five; the declared rules that bind it (or that a cap leaves that
open); the tests that reach it, nearest first; its three latest commits;
and whether this session changed it (a change made outside the session
since it began is not). It reads `knossos file-context <file>` through the
wrapper when it is called (never in a drawing), and answers a path outside
the project, a project never scanned, a root that is not allowed (naming the
root to allow) or a session without a knossos binary with a sentence that
says so and what to do instead. It is registered at the session's start, with the same retries as
`/knossos`, whatever `agentNotes` says: the model asks for it.

Approximate cost on this repository: 20 to 60 tokens for a Read note (60
only for the first file read in a policed boundary), about 35 for an edit
note, about 50 for a tests note naming three PHPUnit classes (up to about
150 at the brief's cap of 20 tests), about 55 for one policy violation.

## Policy violations

When the project declares boundary policies (in `knossos.json`) and
`enforcePolicies` is on, the post-turn brief reports only the violations the
turn introduced. Before the scan it evaluates the policies for violations
whose source component lives in a file the turn edited; after the scan it
does the same for those files again. A violation in the second set
and not the first is new. One that was already there, in an edited file or in
a file that merely depends on one, is not reported. New violations reach the
model as a note it reads before its next step, each once a session:

```text
knossos: this turn introduced 1 boundary-policy violation. Fix it before finishing:
- domain-isolation: App\Domain\Order → App\Infra\Db
```

The count is per violating dependency, so one call can count twice (once for
the class, once for the method). The check walks only the dependencies of the
components declared in the edited files, so its cost follows the edit rather
than the size of the graph, within a five-second budget. It stops at 100
violations in those files, at its edge budget and at that time limit. When it
stops early the brief says so (`policy.truncated`), the count is a bound
rather than exact, and the note adds `(check was truncated; run
check_architecture)`. A truncated check that found nothing new still sends a
note, because the turn's violations may be past the cap: it tells the model to
run `check_architecture` on the files it edited.

Only files the turn edited with Edit, Write or NotebookEdit count. A file
changed by other means (a branch checkout, a formatter, a shell command) never
contributes to the policy verdict, so switching branches does not hand the
model a whole branch's worth of violations to fix.
