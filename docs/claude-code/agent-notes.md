# Notes for the model

The [Claude Code mod](pane.md) tells the model a few facts at the moment they
help, so it keeps to the architecture while it works. There are four notes,
each one short and said once. For one file's context on demand, the model
calls the MCP server's [`file_context`](#one-files-context) tool.

| note                           | fires                                                                     | arrives                                 |
| ------------------------------ | ------------------------------------------------------------------------- | --------------------------------------- |
| [Read](#the-read-note)         | after a Read of a heavily depended-on or policed file                     | after the file's contents               |
| [edit](#the-edit-note)         | after an edit of a file at the fan-in threshold that the Read note missed | after the edit's result, and as a toast |
| [turn end](#the-turn-end-note) | after a turn that edited files, once it is scanned                        | as a message before the next step       |
| [commit](#the-commit-note)     | after a Bash call that made a commit                                      | after the command's output              |

No note costs a process of its own: the figures come from the last dashboard
load and the turn's brief. `agentNotes` turns every note off, and
`enforcePolicies` turns off the policy part of the turn-end and commit notes
(see [settings](pane.md#settings)).

## The Read note

When the model Reads a file with at least `fanInThreshold` dependent files (20
by default), or a file in a boundary a declared policy binds, it reads one line
after the file:

```text
knossos: src/Query/ResultEnvelope.php (core) has 47 dependent files. Policy: core may not depend on php-worker, tests.
```

The rules of a boundary are stated once per loop: the main loop and each
subagent get them once each. The next busy file read
in `core` gets its count alone:

```text
knossos: src/Store/StableId.php (core) has 59 dependent files.
```

A quiet file in a policed boundary the model has not read from yet gets the
boundary and its rules, and a quiet file there after that gets nothing:

```text
knossos: src/Cli/Quiet.php is in core. Policy: core may not depend on php-worker, tests.
```

What the note names:

- Only a declared boundary, or one a rule binds. A file in an inferred boundary
  only, or in none, gets its count alone.
- A rule with an allow list reads `edge may depend only on itself, core,
unassigned code`. A rule limited to some dependency kinds names them in
  brackets: `(calls)`.
- The rules come with the dashboard. A boundary declared by path prefix binds
  every file under that prefix. A boundary placed by namespace lists its files,
  at most 2,000. When that list stops before it reaches the file, the note says
  so rather than imply there are no rules:

```text
knossos: lib/Late.php: rules may bind this file; run check_architecture.
```

## The edit note

An Edit, Write or NotebookEdit of a file with at least `fanInThreshold`
dependent files that the model was not told about on Read adds one line. Claude
Code reads a file before it edits it, so this is rare. You see the same line as
a toast, even with `agentNotes` off:

```text
knossos: src/Router.php has 41 dependent files across 3 boundaries; run test_impact before finishing.
```

The note counts the dependents' boundaries, and says `across N boundaries`
only when there is more than one. Only the fan-in threshold triggers it: a
quiet file in a policed boundary gets no edit note.

## The turn-end note

After a turn that edited files, the mod scans the project (see
[what it writes](pane.md#what-it-writes)) and the turn's brief says what the
turn did. The model then reads one note before its next step, with up to two
parts.

**Policy violations the turn introduced**, when the project declares boundary
policies in `knossos.json` and `enforcePolicies` is on:

```text
knossos: this turn introduced 1 boundary-policy violation. Fix it before finishing:
- domain-isolation: App\Domain\Order → App\Infra\Db
```

**Tests that reach the turn's changes**, and the command that runs them:

```text
knossos: 2 tests reach this turn's changes. Run: vendor/bin/phpunit --filter '(DashboardServiceTest|TurnBriefServiceTest)'
```

When both have something to say they arrive as one note, the violations first.

### How the policy part is worked out

The brief evaluates the policies for violations whose source component lives in
a file the turn edited, once before the scan and once after. A violation in the
second set and not the first is the turn's. One that was already there, in an
edited file or in a file that merely depends on one, is not reported. Each
violation is reported once a session.

- The count is per violating dependency, so one call can count twice: once for
  the class, once for the method.
- The note lists at most ten violations, then `- …and 2 more (run review_diff)`.
- Only files the turn edited with Edit, Write or NotebookEdit count. A branch
  checkout, a formatter or a shell command never adds to the verdict, so
  switching branches does not hand the model a branch's worth of violations.
- When the scan ledger cannot account for every scan since the turn began (a
  scan that was not recorded, or one older than the ledger keeps), or an entry
  was cut because it changed more than 2,000 files (a branch switch), the brief
  still names the turn's files but leaves the policy unevaluated, and the note
  has no policy part. See
  [the scan ledger](../contribute/mod-internals.md#scan-ledger).
- The check walks only the dependencies of the components declared in the
  edited files, so its cost follows the edit. It stops at 100 violations, at
  its edge budget, or after five seconds. When it stops early, the count is a
  bound and the note says so:

```text
knossos: this turn introduced 3 boundary-policy violations (check was truncated; run check_architecture). Fix them before finishing:
- domain-isolation: App\Domain\Order → App\Infra\Db
- …and 2 more (run review_diff)
```

A truncated check that found nothing still sends a note, once a session,
because the turn's violations may be past the cap:

```text
knossos: the boundary-policy check was truncated; run check_architecture to see violations in the files this turn edited.
```

The full mechanics of policies are in
[declared rules and budgets](../concepts/architecture-rules.md).

### How the tests part is worked out

The brief names at most twenty tests that reach the turn's changes, nearest
first. The note leaves out:

- a test the turn already ran after its last edit: a Bash command that runs its
  runner and names the test, a directory holding it, or no test at all (the
  whole suite, such as `vendor/bin/phpunit` or `npm run test:mod`);
- a test an earlier note already named;
- a file no runner runs, such as a helper or a fixture.

The command is chosen as on the [Changes tab](pane.md#changes). Tests the
command names itself (PHPUnit, pytest, and Vitest or Jest scripts) are not
listed again. Tests run by package (`go test`, `cargo test`) are listed, at
most five, then `and N more`. A JavaScript test whose runner the nearest
`package.json` does not make plain is listed with no command, since a guessed
runner may not be the project's. When the session sits below the project's
root, the command starts with `cd <project root> &&`.

## The commit note

When a Bash call in any loop made a commit in the project's repository, the
call's result carries one note. It says what this session's changes leave
behind, as far as the graph knows:

```text
knossos: this session's changes carry 1 changed file no test reaches (src/Kernel.php); 1 dependency cycle new since the session began (Router → Kernel). Check them before you push.
```

Up to three parts, each naming at most five items:

- **Boundary-policy violations the session's turns introduced** that the policy
  check still reports. A violation is dropped only by a check at least as new
  as the turn that found it, so a pane whose reload failed or has not landed
  keeps it.
- **Files the session changed that no test reaches.** Files changed outside the
  session since it began are not counted.
- **Cycles new since the session began**, each as its first four members. A
  cycle is named only when the graph listed every cycle it held when the
  session began; otherwise only the count that grew is said.

The note speaks of the session's changes, since a commit need not hold them
all. Nothing to say, no note. The same note is said once per
loop.

A commit is told by the project's HEAD. The mod reads it (`git rev-parse`)
before and after the command, and when it moved, reads HEAD's reflog for an
entry since then that made a commit: `commit`, an amend, `cherry-pick`,
`revert`, or a merge that made a merge commit.

| seen as a commit                      | not seen as a commit                         |
| ------------------------------------- | -------------------------------------------- |
| a merge commit                        | `git commit` inside a string, or a dry run   |
| `git commit --quiet`                  | nothing to commit                            |
| a commit piped through `tail`         | a reprinted commit line, such as a `git log` |
| a commit through an alias or a script | a commit in another repository               |
|                                       | a checkout, a reset or a fast-forward        |

Without a reflog, git's own line for a commit (`[main abc1234] subject`) in
what the command printed decides.

## Limits

- Each file is noted once per session and per agent. A subagent gets its own
  notes, since what the main loop read was never in its context.
- At most three notes follow tool results in one turn of a loop, the commit
  note included. A Read or edit note held back by that cap is said at the next
  Read or edit of the file.
- A turn ends with at most one note.
- A note that fails leaves the tool result as it was, and one line in the debug
  log. A turn-end note the session refuses is written to the debug log, so a
  lost note is never silent.
- A new session in the same process (after `/clear`) starts the notes over:
  its model never read the old ones.

## One file's context

The model asks for one file's context with the MCP server's `file_context`
tool (`mcp__knossos__file_context`): the file's boundary and the declared
rules whose source is that boundary, how many files depend on it and the
closest five, the tests that reach it, and its three latest commits. The
[MCP tool reference](../reference/mcp-tools.md#file_context) has its input and
output.

The mod registers no tool of its own. Claude Code serves a tool a plugin
registers under the plugin's name, `knossos`, and keeps one server per name,
so such a tool would hide the Knossos MCP server and every tool it has.
