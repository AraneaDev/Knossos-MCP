# How the mod is built

The mod is one hooks module, `hooks/register.tsx`, and the files it imports
from the plugin. Claude Code loads them together. An install copies each one
by name, and an install over an earlier one prunes what it no longer ships. This
page is for you if you want to change the pane or the notes. To use the mod,
start at the [pane guide](../claude-code/pane.md).

## Layout

- `hooks/register.tsx` declares the session state the band and the pane draw
  from and wires each hook to the code under `hooks/mod/`.
- `hooks/mod/` holds what the hooks do, one file for each concern:

| file         | holds                                                                  |
| ------------ | ---------------------------------------------------------------------- |
| `state.ts`   | the mod's own state and its reset                                      |
| `port.ts`    | how the other modules reach Claude Code                                |
| `loaders.ts` | the dashboard and every read keyed to what the pane shows              |
| `watcher.ts` | the live watcher, the scans, and whose change a scan took in           |
| `agent.ts`   | the notes for the model and its `knossos_context` tool                 |
| `actions.ts` | what a press on the pane does                                          |
| `render.tsx` | what the band and the pane draw                                        |
| `session.ts` | start-up, the age tick, the end of a turn or a session, and `/knossos` |

- `hooks/lib/` holds the pure parts: parsing what the wrapper prints, laying
  the pane out, and the notes' text. Nothing there touches Claude Code, which
  is why most of the vitest specs live beside it.

## The port, and why `$` cannot cross an import

Claude Code hands each hook its interface to the session as `$`. It follows
`$` only into functions of the hooks module itself, never across an import, and
it refuses `$.<noun>` used as a value. A helper in `hooks/mod/` that received
`$` could not use it.

So `register.tsx` builds a `Port` from the hook's own `$` on every dispatch
(`portOf`) and passes that to the modules. The port has one function for each
thing the mod asks of Claude Code (`clock`, `ui`, `session`, `process`, `fs`,
`store`, `config`, `command`, `tool`, `prompt`, `plugin`), each written out as
`(args) => $.ui.copy(args)` and nothing else. `hooks/mod/port.ts` holds the
type. A call the modules need that the port does not list has to be added in
both places.

## The atoms that stay in register.tsx

The engine takes `read` and `update` only on an atom declared in the hooks
module itself. Every atom of the session state is therefore declared in
`register.tsx` (`brief`, `dashboard`, `view`, `detail`, `live` and the rest),
under the plugin name `knossos` and its own key. The port hands each one over
as a cell with a `read()` and an `update(change)`, so a module reads and
changes state without ever seeing an atom. The shape of every value is in
`types/index.d.ts`, and the `Cells` type in `port.ts` is derived from it, so a
new atom that is missing from the port fails the type-check.

## The keyed loader

Every read the pane draws from that is keyed to what it shows goes through one
loader, `request(io, key)` in `hooks/mod/loaders.ts`. `LOADERS` names the
reads: `couplings`, `diff`, `detail`, `peek`, `branch`, `churn`, `rings` and
`route`. A loader says what it wants now, whether its cell already holds or is
reading that, what to ask the wrapper, how to parse the answer, and how the
cell looks once the answer lands. The loader then guarantees four things:

- A read never runs inside a render. It starts on a timer after `request`
  resolves, and the pane draws a loading line from state until the answer is
  stored.
- A cell that already holds, or is already reading, what is wanted does not
  start a second read. `flight` extends that to a read that is on its way while
  the marker has moved to another component and back.
- `debounceMs` makes a run of presses one read. The detail beside the tab uses
  it, so holding `j` is one lookup.
- An answer lands only while the cell still wants it. A read that finished
  after the marker moved on is dropped.

A new read is one more entry in `LOADERS`.

## The press table

A press on the pane arrives as the id of the element pressed. `PRESSES` in
`hooks/mod/actions.ts` maps it to what it does. An id either names an action
outright (`find`, `route`, `back`) or carries what it acts on after a colon
(`row:3`, `tab:boundaries`), and the table is keyed by the part up to and
including the first colon. No outright id holds a colon, so the two never meet.

Some ids do what another does: the hidden hotkey twin of a tab (`tabkey:<id>`
does what `tab:<id>` does), the drift line's second button, and the Cycles
moves and folds. `SAME_AS` lists them. A new action is one entry in `PRESSES`,
plus a `SAME_AS` entry if it has a twin. An id the table does not know does
nothing, on purpose.

## Field keys

A text field (the finder, a note, the filter on the hubs) is drawn under the
key `field:<id>`, built by `fieldKey` in `hooks/mod/state.ts`, and focus is
moved into it with that same key. It is never drawn under the bare id.

The Button that opens a field shares the field's id. Claude Code resolves a
focus call by key, against the drawing it holds when the call arrives, and takes
the first element under that key in document order. With one key for both, that
could be the Button, which is still on screen at that moment. The ring lands on
the Button, the field never receives keystrokes, and when the next drawing drops
the Button the keyboard goes back to the prompt. The focus call itself runs on a
timer after the press, so that the drawing which brings the field has already
happened.

## The tree budget and the engine limits

Claude Code refuses a render tree past three bounds, and draws its own tree
instead when that happens:

| bound                  | limit   |
| ---------------------- | ------- |
| characters, serialized | 100,000 |
| nodes                  | 20,000  |
| depth                  | 32      |

The pane keeps well inside them. `drawPane` in `hooks/mod/render.tsx` measures
the tree it drew (its length serialized, `treeWeight`) against `TREE_BUDGET`,
70,000 characters. Past that it drops the hover cards and most links, then gives
the lists fewer rows in proportion to how far over it is, down to 16 rows
(`SHORT_ROWS`). If it is still over at 16 rows it draws one line, "Too large to
draw here", and logs the size once to the debug log.

Two habits keep a tall pane cheap. A `Text` carries no key and no `Box` of its
own, and a `Box` gets a key only where one is needed, such as a tint or a hidden
twin.

The plugin test (below) holds every view to a stricter guard than the budget:
under 72,000 characters, under 10,000 nodes and under 16 deep, at every pane size
and surface, with the largest lists the dashboard sends. A change that pushes a
view past the guard fails there, well before the engine's own bounds.

## The tests

Three layers cover the mod, each catching what the others cannot.

**The plugin test kit.** `hooks/register.test.ts` runs the real hooks module
under `claude plugin test`, against Claude Code's own test engine
(`claude-code/testing`): it mounts the pane, presses keys, advances a fake clock
and reads the drawn tree. `tools/quality` does not run it from the repository.
The repository holds scanner fixtures named `*.test.ts` that `claude plugin test`
cannot exclude and that fail it whatever the mod does. So the gate stages a copy:
`install-agent-plugin --out=<dir>` writes exactly what an install ships, the
test file is copied in, and `claude plugin validate` and `claude plugin test`
run on that. The same staging copy gets a strict `tsc` pass over
`register.tsx` and the test, when Claude Code's API declarations are present
(`.claude-plugin/types/claude-code/index.d.ts`, which the CLI writes when a
session loads the plugin, or the file `KNOSSOS_CLAUDE_CODE_TYPES` names). The
check says so on stderr when the `claude` CLI or the declarations are missing,
instead of passing quietly. The test file is never shipped in the plugin.

**The vitest specs.** `npm run test:mod` runs the specs beside the pure code:
`hooks/lib/**/*.spec.ts` and `hooks/mod/**/*.spec.ts`, plus the tool specs under
`tools/`. They run without Claude Code. The mod brings no React package, so
vitest resolves `react/jsx-runtime` to a stub (`tools/jsx-runtime-stub.mjs`).

**The spec type-check.** Vitest strips types without reading them, and the
staged check reaches only what an install ships, so a spec could drift from the
types it builds without anything noticing. `npm run typecheck:mod` runs
`tsc -p hooks/tsconfig.json && tsc -p hooks/tsconfig.spec.json`. The spec
configuration resolves `claude-code` through the stand-in `hooks/engine.d.ts`,
where every name is `any`, because the engine's own declarations exist only
where a session has loaded the mod. The stand-in is not shipped.

See [quality gates](quality.md) for where each of these runs.
