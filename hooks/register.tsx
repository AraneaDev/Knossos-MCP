/**
 * The hooks module: the session state's atoms, the engine port the mod's
 * modules reach the engine through (see `mod/port.ts` for why), and the
 * hooks, each wired to what `mod/` does. The engine loads this file and
 * every file it imports from the plugin.
 */
import { atom, read, update } from 'claude-code'
import type { EngineInterface, Register } from 'claude-code'

import type { AllowState, BranchState, ChurnState, CouplingState, DetailState, DiffFold, DiffState, Feedback, GitHead, Inspected, KnossosView, LiveState, NoteState, RefreshState, RescanState, RingsState, RouteState, SearchState, SessionChanges, SessionRev } from '../types'
import { begin, counts, finish } from './lib/activity'
import { committedSince } from './lib/agent'
import type { JobState } from './lib/band'
import type { SessionLedger } from './lib/envelopes'
import type { Flash } from './lib/flash'
import { NO_CHANGES } from './lib/layout'
import { LIVE_OFF } from './lib/live'
import { copyCommand, handlersOf, twinTarget } from './mod/actions'
import { answerContext, commitNoteFor, headAt, noteEdit, noteRead, noteSafely, projectRoot, reflogAt } from './mod/agent'
import { request } from './mod/loaders'
import type { Port } from './mod/port'
import { drawBand, drawPane } from './mod/render'
import { endSession, endTurn, openPane, registerCommand, retryRegister, runCommand, startUp } from './mod/session'
import { CONTEXT_TOOL_NAME, editedPath, mod, PANE, reset, takeNoteSlot } from './mod/state'
import type { StartCycles } from './mod/state'

/**
 * Whether a Bash call marks the turn dirty. Off: a no-change incremental scan
 * of a real project takes about four seconds, over the two-second budget that
 * made scanning after every shell command acceptable. One line to turn back on.
 */
const BASH_MARKS_DIRTY = false
const EDIT_TOOLS = ['Edit', 'Write', 'NotebookEdit'] as const
/** The Bash commands kept per turn to tell whether it ran the tests. */
const COMMANDS_KEPT = 50
/** The finder before anything was typed. */
const NO_SEARCH: SearchState = { query: '', for: null, phase: 'idle', answer: null }

const brief = atom({ plugin: 'knossos', key: 'brief' } as const, null)
const dashboard = atom({ plugin: 'knossos', key: 'dashboard' } as const, null)
const job = atom({ plugin: 'knossos', key: 'job' } as const, { phase: 'idle', lastAttemptAt: null } as JobState)
const view = atom({ plugin: 'knossos', key: 'view' } as const, {
  inspect: null,
  isBandHidden: false,
  tab: 'overview',
  selected: 0,
  showKeys: false,
  filter: '',
  filtering: false,
  sort: 'in',
} as KnossosView)
const detail = atom({ plugin: 'knossos', key: 'detail' } as const, null as DetailState | null)
const refresh = atom({ plugin: 'knossos', key: 'refresh' } as const, { fetchedAt: null, failed: false } as RefreshState)
const rescan = atom({ plugin: 'knossos', key: 'rescan' } as const, { phase: 'idle', reason: null } as RescanState)
const allow = atom({ plugin: 'knossos', key: 'allow' } as const, { phase: 'idle', root: null, reason: null } as AllowState)
/** The person's Claude Code theme by name (`dark`, `light`, ...): what the heat map's raw colours are resolved for. */
const theme = atom({ plugin: 'knossos', key: 'theme' } as const, 'dark' as string)
/** Everything this session's turn briefs reported, added up: what the Changes tab and "Look at now" draw. */
const changes = atom({ plugin: 'knossos', key: 'changes' } as const, NO_CHANGES as SessionChanges)
/** The session's root with its links followed: a test command run from it changes to the project root first when they differ. */
const sessionRoot = atom({ plugin: 'knossos', key: 'sessionRoot' } as const, null as string | null)
/** The live watcher, as the header shows it. */
const live = atom({ plugin: 'knossos', key: 'live' } as const, LIVE_OFF as LiveState)
/** Everything the scan ledger says changed in the project since the session began, whoever changed it; null until read. */
const sessionLedger = atom({ plugin: 'knossos', key: 'sessionLedger' } as const, null as SessionLedger | null)
/** The snapshot the graph was at when the session began. */
const sessionStart = atom({ plugin: 'knossos', key: 'sessionStart' } as const, null as string | null)
/** When the session began (milliseconds), as its stored baseline says: what Changes names when the scan record does not reach back to it. */
const sessionBegan = atom({ plugin: 'knossos', key: 'sessionBegan' } as const, null as number | null)
/** The paths the session's own edit tools wrote, main loop and subagents alike: the Changes tab's "this session". */
const sessionEdits = atom({ plugin: 'knossos', key: 'sessionEdits' } as const, [] as string[])
/** The snapshots of the scans that took in changes made while the session's tools ran (see `lib/activity.ts`). */
const sessionScans = atom({ plugin: 'knossos', key: 'sessionScans' } as const, [] as string[])
/** The commit the project was at when the session began: what a changed file's diff is taken against. */
const sessionRev = atom({ plugin: 'knossos', key: 'sessionRev' } as const, null as SessionRev | null)
/** The change since the session began of the file the detail shows, as last read. */
const fileDiff = atom({ plugin: 'knossos', key: 'fileDiff' } as const, null as DiffState | null)
/** How far the detail's diff is opened (its hunks shown whole, the first hunk shown), for the diff it was opened on. */
const diffFold = atom({ plugin: 'knossos', key: 'diffFold' } as const, null as DiffFold | null)
/** Whether the Changes list holds only the changed files no test reaches: the press on that count, and `u`. */
const untestedOnly = atom({ plugin: 'knossos', key: 'untestedOnly' } as const, false as boolean)
/** Where the checkout stands (commit and branch), for the header; null until read, and without git. */
const gitHead = atom({ plugin: 'knossos', key: 'gitHead' } as const, null as GitHead)
/** The Boundaries tab's marked heat map cell spelled out, as last read. */
const couplings = atom({ plugin: 'knossos', key: 'couplings' } as const, null as CouplingState | null)
/** What the footer says for a moment after an action in the pane. */
const feedback = atom({ plugin: 'knossos', key: 'feedback' } as const, null as Feedback | null)
/** The finder's search: what was typed, and what `graph-search` answered for the query last read. */
const search = atom({ plugin: 'knossos', key: 'search' } as const, NO_SEARCH as SearchState)
/** The marked row's detail, drawn beside the tab on a wide pane: what is shown, and its lookup. */
const peek = atom({ plugin: 'knossos', key: 'peek' } as const, null as { shown: Inspected; detail: DetailState } | null)
/** The Branch tab's comparison with the merge base, for the snapshot it was read at. */
const branch = atom({ plugin: 'knossos', key: 'branch' } as const, null as BranchState | null)
/** The rows the latest scan changed, lit for a moment after it landed. */
const flash = atom({ plugin: 'knossos', key: 'flash' } as const, null as Flash | null)
/** The Churn tab's hotspots, for the commit they were read at. */
const churn = atom({ plugin: 'knossos', key: 'churn' } as const, null as ChurnState | null)
/** The blast radius of the component the detail shows, for its name and snapshot. */
const rings = atom({ plugin: 'knossos', key: 'rings' } as const, null as RingsState | null)
/** The route the path explorer draws, for its two ends and snapshot. */
const route = atom({ plugin: 'knossos', key: 'route' } as const, null as RouteState | null)
/** A note being added on the detail, until it is recorded or dropped. */
const note = atom({ plugin: 'knossos', key: 'note' } as const, null as NoteState | null)
/**
 * The cycles the graph held when the session began: how many, each listed one by its members, and
 * whether the list held them all. What a commit note counts as new; state, so a reload keeps it.
 */
const startCycles = atom({ plugin: 'knossos', key: 'startCycles' } as const, null as StartCycles | null)

/**
 * The engine as the mod's modules reach it, from this dispatch's `$`. The
 * engine follows `$` only into functions of this file and takes `read` and
 * `update` only on an atom declared here, so every call the modules make is
 * spelled out once below, and every atom is handed over as a cell.
 */
function portOf($: EngineInterface): Port {
  return {
    clock: { now: () => $.clock.now(), after: (ms, run) => $.clock.after(ms, run), every: (ms, run) => $.clock.every(ms, run) },
    ui: {
      log: (text, options) => $.ui.log(text, options),
      toast: text => $.ui.toast(text),
      copy: args => $.ui.copy(args),
      focus: args => $.ui.focus(args),
      panes: () => $.ui.panes(),
      invalidate: event => $.ui.invalidate(event),
      open: args => $.ui.open(args),
      close: args => $.ui.close(args),
    },
    session: { root: () => $.session.root(), id: () => $.session.id(), append: args => $.session.append(args) },
    process: { run: (argv, options) => $.process.run(argv, options), spawn: args => $.process.spawn(args) },
    fs: { stat: (path, options) => $.fs.stat(path, options) },
    store: { get: key => $.store.get(key), set: (key, value) => $.store.set(key, value) },
    config: { list: () => $.config.list() },
    command: { register: args => $.command.register(args) },
    tool: { register: args => $.tool.register(args) },
    prompt: { submit: args => $.prompt.submit(args) },
    plugin: { root: $.plugin.root },
    state: {
      brief: { read: () => read($, brief), update: change => update($, brief, change) },
      dashboard: { read: () => read($, dashboard), update: change => update($, dashboard, change) },
      job: { read: () => read($, job), update: change => update($, job, change) },
      view: { read: () => read($, view), update: change => update($, view, change) },
      detail: { read: () => read($, detail), update: change => update($, detail, change) },
      refresh: { read: () => read($, refresh), update: change => update($, refresh, change) },
      rescan: { read: () => read($, rescan), update: change => update($, rescan, change) },
      allow: { read: () => read($, allow), update: change => update($, allow, change) },
      theme: { read: () => read($, theme), update: change => update($, theme, change) },
      changes: { read: () => read($, changes), update: change => update($, changes, change) },
      sessionRoot: { read: () => read($, sessionRoot), update: change => update($, sessionRoot, change) },
      live: { read: () => read($, live), update: change => update($, live, change) },
      sessionLedger: { read: () => read($, sessionLedger), update: change => update($, sessionLedger, change) },
      sessionStart: { read: () => read($, sessionStart), update: change => update($, sessionStart, change) },
      sessionBegan: { read: () => read($, sessionBegan), update: change => update($, sessionBegan, change) },
      sessionEdits: { read: () => read($, sessionEdits), update: change => update($, sessionEdits, change) },
      sessionScans: { read: () => read($, sessionScans), update: change => update($, sessionScans, change) },
      sessionRev: { read: () => read($, sessionRev), update: change => update($, sessionRev, change) },
      fileDiff: { read: () => read($, fileDiff), update: change => update($, fileDiff, change) },
      diffFold: { read: () => read($, diffFold), update: change => update($, diffFold, change) },
      untestedOnly: { read: () => read($, untestedOnly), update: change => update($, untestedOnly, change) },
      gitHead: { read: () => read($, gitHead), update: change => update($, gitHead, change) },
      couplings: { read: () => read($, couplings), update: change => update($, couplings, change) },
      feedback: { read: () => read($, feedback), update: change => update($, feedback, change) },
      search: { read: () => read($, search), update: change => update($, search, change) },
      peek: { read: () => read($, peek), update: change => update($, peek, change) },
      branch: { read: () => read($, branch), update: change => update($, branch, change) },
      flash: { read: () => read($, flash), update: change => update($, flash, change) },
      churn: { read: () => read($, churn), update: change => update($, churn, change) },
      rings: { read: () => read($, rings), update: change => update($, rings, change) },
      route: { read: () => read($, route), update: change => update($, route, change) },
      note: { read: () => read($, note), update: change => update($, note, change) },
      startCycles: { read: () => read($, startCycles), update: change => update($, startCycles, change) },
    },
  }
}

export const register: Register = (on, options) => {
  if (options.enabled === false) return
  reset(options)
  const openOnStart = options.openPaneOnStart === true

  on('session.start', async ($, e, next) => {
    const io = portOf($)
    // A refused registration (no session bound yet, after a hot reload) is retried; start-up goes on regardless.
    const gen = ++mod.registerGen
    mod.registerTimer?.cancel()
    mod.registerTimer = null
    if (!(await registerCommand(io))) retryRegister(io, 0, gen)
    // A start-up that outlives the session (torn down under it) fails quietly, never as a stray rejection.
    $.clock.after(0, () => void startUp(io, openOnStart).catch(() => undefined))
    return next(e)
  })

  on('session.end', async ($, e, next) => {
    await endSession(portOf($), e.reason, e.sessionId)
    return next(e)
  })

  // Every call of the session's, in every loop, by any tool that can write rather than wait or read: while one runs
  // (and a moment after), what the watcher scans is the session's own, whatever route the change took.
  on('tool.call', async ($, e, next) => {
    // The model's own tool, one file's context in one call, by the name its registration returned: answered
    // here, and no other hook answers it. The engine allows one hook on every call, so it shares this one.
    if (e.tool === (mod.contextTool ?? CONTEXT_TOOL_NAME)) return { result: await answerContext(portOf($), (e as { path?: unknown }).path).catch(() => 'knossos_context: the answer failed; try again.') }
    if (mod.disabled || !counts(e.tool)) return next(e)
    const id = `${++mod.callSeq}`
    begin(mod.activity, id, await $.clock.now())
    try {
      return await next(e)
    } finally {
      // A clock torn down under the call (the session ending) ends it where it began.
      finish(mod.activity, id, await $.clock.now().catch(() => mod.activity.running.get(id) ?? 0))
    }
  })

  on('tool.call', { tool: EDIT_TOOLS }, async ($, e, next) => {
    const io = portOf($)
    // Before the edit lands: the snapshot the turn's brief diffs against, whoever scans the edit first.
    // It becomes the turn's start only once the edit is known to have landed inside the project.
    const base = mod.snapshot
    const ran = await next(e)
    if (!mod.disabled) mod.lastEditAt = await $.clock.now()
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    // Tests run before this edit ran the old code.
    mod.ranCommands = []
    return noteSafely(io, ran, async () => {
      const path = editedPath(e)
      const note = path === null ? null : await noteEdit(io, path, base)
      const key = note === null ? '' : `${e.agentId ?? ''}\u0000${note.path}`
      // Told already, on its Read or an earlier edit.
      if (note === null || mod.noted.has(key)) return ran
      // Held back by this turn's cap: not delivered, so not noted; a later edit or Read says it.
      if (mod.notesOn && !takeNoteSlot(e.agentId ?? '')) return ran
      mod.noted.add(key)
      $.ui.toast(note.text)
      return mod.notesOn ? { ...ran, context: [...(ran.context ?? []), note.text] } : ran
    })
  })

  // Before an edit: what the file is to the rest of the project, so the edit keeps to its rules.
  on('tool.call', { tool: 'Read' }, async ($, e, next) => {
    const io = portOf($)
    const ran = await next(e)
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    return noteSafely(io, ran, async () => {
      const path = (e as { file_path?: unknown }).file_path
      const note = typeof path === 'string' ? await noteRead(io, path, e.agentId ?? '') : null
      return note === null ? ran : { ...ran, context: [...(ran.context ?? []), note] }
    })
  })

  // Every command is kept for the turn-end note, which leaves out the tests the turn already ran.
  on('tool.call', { tool: 'Bash' }, async ($, e, next) => {
    const io = portOf($)
    // Where the project's HEAD stood before the command: only a command that moved it can have committed there.
    const repo = mod.disabled || !mod.notesOn ? null : await projectRoot(io)
    const before = repo === null ? null : await headAt(io, repo)
    const ran = await next(e)
    const command = (e as { command?: unknown }).command
    if (ran.deny === undefined && typeof command === 'string' && mod.ranCommands.length < COMMANDS_KEPT) mod.ranCommands.push(command)
    if (BASH_MARKS_DIRTY) mod.dirty = true
    // A commit, in any loop: what it carries that the graph knows of, said once.
    if (mod.disabled || ran.deny !== undefined || repo === null || before === null) return ran
    return noteSafely(io, ran, async () => {
      const after = await headAt(io, repo)
      const reflog = after === null || after === before ? '' : await reflogAt(io, repo)
      if (!committedSince(before, after, reflog, ran.text ?? '')) return ran
      const note = await commitNoteFor(io, e.agentId ?? '')
      return note === null ? ran : { ...ran, context: [...(ran.context ?? []), note] }
    })
  })

  on('turn.complete', async ($, e, next) => {
    const result = await next(e)
    await endTurn(portOf($), e.agentId)
    return result
  })

  on('ui.render', { component: 'AbovePrompt' }, async ($, e, next) => {
    const io = portOf($)
    const band = await drawBand(io, () => $.ui.resolve(e), e.props, {
      details: () => openPane(io),
      copy: (command, surface) => void copyCommand(io, command, surface).catch(() => undefined),
      hide: () => update($, view, v => ({ ...v, isBandHidden: true })),
    })
    return band ?? next(e)
  })

  // A theme picked in /config redraws the heat map in its colours.
  on('config.set', { key: 'theme' }, async ($, e, next) => {
    const set = await next(e)
    const value = set.value
    if (set.deny === undefined && typeof value === 'string') await update($, theme, () => value)
    return set
  })

  on('command.run', { command: 'knossos' }, async ($, e) => ({ text: await runCommand(portOf($), e.args) }))

  // The arrows, Tab or a click moving the focus onto a listed row move the marker with it.
  on('ui.focus', { requestId: PANE }, async ($, e, next) => {
    // A person's move names the element; a `$.ui.focus` call may arrive as its own arguments, naming it `key`.
    const asked = e.element ?? (e as { key?: unknown }).key
    const twin = typeof asked === 'string' ? twinTarget(asked) : undefined
    if (twin === null) return {}
    const element = twin ?? asked
    mod.focused = typeof element === 'string' ? element : null
    // Redirected in the field the move named: a person's move its `element`, a `$.ui.focus` call its `key`.
    const redirected = e.element === undefined ? ({ ...e, key: twin } as typeof e) : { ...e, element: twin }
    const moved = await next(twin === undefined ? e : redirected)
    const index = typeof element === 'string' && element.startsWith('row:') ? Number(element.slice(4)) : Number.NaN
    if (moved.deny === undefined && Number.isInteger(index)) {
      const io = portOf($)
      await update($, view, v => (v.selected === index ? v : { ...v, selected: index, target: undefined }))
      await request(io, 'couplings')
      await request(io, 'peek')
    }
    return moved
  })

  on('ui.render', { component: 'Pane', requestId: PANE }, async ($, e) => {
    const io = portOf($)
    return drawPane(io, $.ui.resolve(e), e.surface, e.props, handlersOf(io))
  })
}
