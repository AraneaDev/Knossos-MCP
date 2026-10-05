# Session brief

When a Claude Code session starts in a scanned project, the
[plugin](plugin.md) injects a short brief before anything else happens. It
tells the model whether the graph can be trusted, what the project's boundary
rules and recorded notes are, and, when the graph is fresh, where execution
enters and which components carry the most weight. For a small project whose
files changed since its scan:

```text
STALE (2 files, 1m). Run scan_project path=/home/me/shop first.
Knossos project_b003edea9a1368da547daaad922429bad1e5828ffb41edda0e3e1fb8e4131283 (shop)
Rules:
  app -x-> cli, tests
  cli --> only app
Notes: App\Kernel: boots twice in tests; the second boot is the one that counts.
Ask before grepping for structure: the `knossos:graph` skill.
```

Once the graph is fresh, two graph sections, Entry and Hubs, follow the notes.
This project declares no rules or notes and has no entry points, so only Hubs
shows:

```text
FRESH (scanned 3m ago).
Knossos project_4e273d56974562dde33b88495d8118a6604b19633c9dacd56efa42ec8c0eee3f (shop)
Hubs:
  run (method, degree 2)
  B (class, degree 2)
Ask before grepping for structure: the `knossos:graph` skill.
```

The brief reflects the last scan, not the working tree. For anything current,
the model calls `scan_project`, then the query tools the last line points at.

## The verdict line

The first line is the verdict, one of five states:

| state        | meaning                                                                                                                    |
| ------------ | -------------------------------------------------------------------------------------------------------------------------- |
| `fresh`      | scanned, and a drift probe found nothing changed since                                                                     |
| `stale`      | scanned, but files changed since the last scan                                                                             |
| `unverified` | scanned, but the drift probe was skipped: over 20,000 tracked files with no usable git history, or the root is unavailable |
| `missing`    | the path belongs to a known project that has no graph                                                                      |
| `unscanned`  | the path belongs to no project                                                                                             |

Each renders one line. The placeholders are filled in when it renders:

| state        | verdict                                                                                     |
| ------------ | ------------------------------------------------------------------------------------------- |
| `fresh`      | `FRESH (scanned {age} ago).`                                                                |
| `stale`      | `STALE ({n} files, {age}). Run scan_project path={path} first.`                             |
| `unverified` | `UNVERIFIED ({n} files, over probe limit; scanned {age} ago). Rescan if exactness matters.` |
| `missing`    | `NO GRAPH. Run scan_project path={path} first.`                                             |
| `unscanned`  | `NOT SCANNED. Run scan_project path={path} to map this repository.`                         |

`age` is a coarse, single-token duration (`17d`, `3h`, `9m`).

Every state except `fresh` has two more forms, because a `scan_project` the
server would refuse is advice nobody can follow:

- When the path is outside the allowed roots, the instruction becomes the fix:
  `{state}, and {path} is not an allowed root in {roots_file}. Add it: knossos allow-root {path} --execute`.
  `roots_file` names the file this brief read, since a machine can have more
  than one; compare it with `server_info` when a scan is refused anyway. The
  clause is dropped when there was no roots file to read.
- When the path does not exist:
  `{state}, and {path} does not exist. Neither scan_project nor allow-root will accept it.`

`fresh` asks for nothing, so it never carries a root warning.

The root check reuses the containment logic a real `scan_project` call runs,
but it cannot see roots a server was given with `--allow-root` flags on its own
command line. A path allowed only that way is reported as not allowed. The
advice is still safe: the roots file is always added to whatever static roots
are active, so appending a root that is already allowed widens nothing.

## What follows the verdict

**The identity line**: `Knossos {project_id} ({name})`. When the path you
started in resolved to an ancestor project, it says so, in one of two forms:

- `…, rooted at {root}. {path} lies inside it and is not a scanned project of its own.`
- `…, rooted at {root}. {path} does not exist; this describes the project it would lie inside.`

That matters for a repository nested inside a scanned one: everything below the
line describes the ancestor.

**Rules**: the boundary policies from `knossos.json` on disk, so a policy edited
since the last scan is still the one CI enforces. `core -x-> tests` means core
may not depend on tests; `edge --> only core` means edge may depend only on
core. See [declared rules and budgets](../concepts/architecture-rules.md).

**Notes**: up to five `note` annotations, the newest first, as
`{component}: {note}`. These are what earlier sessions wrote down with
`annotate_component` (see [the routing skill](skill.md#writing-a-fact-back)).

**Entry**: up to four ways into the system. A component counts by its kind
(`route`, `command`, `endpoint`) or by its classification
(`application.controller`, `application.command`, `application.entry_point`,
`laravel.controller`, `laravel.command`), and never when it is classified as
test code. Kinds come first, since a scanner read them off a declaration.

**Hubs**: the top five components by degree, counted as `architecture_health`
counts it: dependencies in plus out, with `contains`, vendor code, unresolved
references and test code left out.

**The pointer**, always last:

```text
Ask before grepping for structure: the `knossos:graph` skill.
```

It is what arms the [routing skill](skill.md) for the rest of the session.

Rules and notes appear for every state that has a project, since a stale scan
says nothing about whether a declared rule or a written note still holds.
Entry and Hubs appear only when the graph is `fresh`: both are read from the
graph tables, so on a stale graph they could describe code that no longer
exists.

The brief leaves out file and component counts and the language mix. It is
billed on every session start, resume and compact, and those figures change no
decision the model is about to make. The [agent brief](../agents/agent-integration.md#agent-brief)
has them.

## Budgets

The optional sections (rules, notes, entry points, hubs) fit within a budget per
state:

| state                            | budget          |
| -------------------------------- | --------------- |
| `unscanned`                      | 210 characters  |
| `stale`, `unverified`, `missing` | 500 characters  |
| `fresh`                          | 1200 characters |

A section is kept only while the running total, pointer included, still fits.
One that does not fit is dropped whole, since a cut list reads as a complete
one.

The verdict, the identity line and the pointer are always printed, even past
the budget. A long path can therefore push the brief over its budget. That is
the price of never printing a broken command or a brief that does not say which
project it describes.

## Which database it reads

Every other CLI command creates the data directory and migrates the database
before it answers. `session-brief` must not leave a database behind in a
directory nobody scanned, so it looks for an existing one and opens it only
then:

1. `--db=FILE`, when given.
2. `KNOSSOS_DATA_DIR`, as the plugin's hooks set it (see
   [the data directory](plugin.md#the-data-directory)).
3. `.knossos/knossos.sqlite` under the path, then under each of its parents,
   the nearest that exists.

The parent walk lets a session started in `src/` reach its repository's graph.
No database found renders `NOT SCANNED` and touches nothing. The hook changes
into the project directory before it calls the binary, so the working
directory and the path agree.

## Granting a root

```sh
knossos allow-root <path> [--execute] [--db=FILE] [--json]
```

`allow-root` adds a path to the roots file without hand-editing it. Without
`--execute` it reports what it would add and changes nothing. With
`--execute` it writes the file atomically, keeping its file mode. The path must
be absolute and an existing directory. A path already present is reported as
such and nothing is written.

A running server reads the roots file on every request, so a granted root needs
no restart. Whether that reaches your server depends on how the command found
the file:

- **Named**, by `KNOSSOS_ROOTS_FILE`, `KNOSSOS_DATA_DIR` or `--db`: the command
  says a server configured with that file picks the addition up.
- **Derived** from the working directory, when none of those is set: the file
  may not be the one your server reads, so the command points at
  `server_info` instead. Under `--json` this is `roots_file_source`, `named` or
  `working-directory`.

## Failures are silent

The hook, its container variant and the command share one contract: nothing
they do costs a session more than a bounded amount of time. No binary found, a
non-zero exit, empty output, a timeout: each exits 0 and prints nothing.
`session-brief` itself never throws, unlike every other query command.

The hook finds the binary as the [plugin page](plugin.md#the-hooks-need-a-knossos-binary)
describes, and bounds the call at three seconds with `timeout`, else
`gtimeout` (Homebrew's name for the GNU tool on macOS). Without either, Claude
Code's own limit on the hook, 15 seconds in `hooks/hooks.json`, is the bound.

## Invocation

```sh
knossos session-brief [path] [--db=FILE] [--json]
```

`path` defaults to the current directory. `--json` prints the full result
envelope instead of the text a hook injects. The command always exits 0.
