# How the mod is built

The mod is one hooks module, `hooks/register.tsx`, and the files it imports
from the plugin. Claude Code loads them together; an install copies each one
by name, and an install over an earlier one prunes what it no longer ships.

- `hooks/register.tsx` declares the session state the band and the pane draw
  from and wires each hook to the code under `hooks/mod/`.
- `hooks/mod/` holds what the hooks do: `state.ts` (the mod's own state and
  its reset), `port.ts` (how the other modules reach Claude Code),
  `loaders.ts` (the dashboard and every read keyed to what the pane shows),
  `watcher.ts` (the live watcher, the scans and whose change a scan took in),
  `agent.ts` (the notes for the model and its `knossos_context` tool),
  `actions.ts` (what a press on the pane does), `render.tsx` (what the band
  and the pane draw) and `session.ts` (start-up, the age tick, the end of a
  turn or a session, and `/knossos`).
- `hooks/lib/` holds the pure parts: parsing what the wrapper prints, laying
  the pane out, and the notes' text.

Claude Code hands each hook its interface to the session, and follows it only
into functions of the hooks module itself, never across an import. So
`register.tsx` passes the modules a port built from the hook's own interface
on every call, one function for each thing the mod asks of Claude Code, and
one read-and-update pair for each value of the session state.
