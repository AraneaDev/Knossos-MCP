import { atom, read, update } from 'claude-code'
import type { Elements, EngineInterface, Register, RenderSurface, Timer } from 'claude-code'

import { bandModel } from './lib/band'
import type { JobState } from './lib/band'
import { parseAllowRoot, parseComponentDetail, parseDashboard, parseFileDetail, parseRescan, parseTurnBrief, rescanReason } from './lib/envelopes'
import type { TurnBrief } from './lib/envelopes'
import {
  accumulate,
  allowInput,
  askPrompt,
  cdFor,
  CONTENT_MAX,
  detailInput,
  editTarget,
  emptyRows,
  fileDetailInput,
  fit,
  linkMarkdown,
  listFor,
  locOf,
  locText,
  NO_CHANGES,
  paneInput,
  paneRows,
  paneStatus,
  refusedRoot,
  rowWidth,
  SCAN_PROMPT,
  SORTS,
  subjectOf,
  TABS,
} from './lib/layout'
import type { Loc, Openable, PaneInput, Row } from './lib/layout'
import { editNote, fanInIndex, freshViolations, readNote, testsNote, violationKey, violationNote } from './lib/notes'
import { isWatching, LIVE_OFF, phaseAfter, snapshotOf, watchLines, watchPollMsOf } from './lib/live'
import { declaredOf, huesOf } from './lib/palette'
import { relativise } from './lib/paths'
import { rasterOf, rasterTheme } from './lib/raster'
import { textStyle } from './lib/rows'
import { SingleFlight } from './lib/scheduler'
import type { AllowState, ComponentDetail, DetailState, FileDetail, Inspected, KnossosView, LiveState, PaneTab, RefreshState, RescanState, SessionChanges, WatchEvent } from '../types'

const PANE = 'knossos'
/**
 * Whether a Bash call marks the turn dirty. Off: a no-change incremental scan
 * of a real project takes about four seconds, over the two-second budget that
 * made scanning after every shell command acceptable. One line to turn back on.
 */
const BASH_MARKS_DIRTY = false
const EDIT_TOOLS = ['Edit', 'Write', 'NotebookEdit'] as const
/** The wrapper's own limits (60 s, 30 s and 15 s) plus room for it to exit on its own. */
const BRIEF_TIMEOUT_MS = 70_000
const DASHBOARD_TIMEOUT_MS = 35_000
const DETAIL_TIMEOUT_MS = 20_000
/** The wrapper bounds a rescan at 60 s, as a turn brief. */
const RESCAN_TIMEOUT_MS = 70_000
/** The wrapper bounds allow-root at 15 s. */
const ALLOW_TIMEOUT_MS = 20_000
/** How long the editor's command may take to hand a file to the editor. */
const EDITOR_TIMEOUT_MS = 10_000
/**
 * The command that opens `path:line` in the person's editor: VS Code's
 * `code -g`, which Cursor and the other forks also answer to. A terminal
 * editor (`$EDITOR`) needs a terminal of its own, which a press cannot give.
 */
const EDITOR = ['code', '-g'] as const
const USAGE = 'Usage: /knossos to toggle the architecture pane; /knossos inspect <component> to open it on one component.'
/** formatAge's finest step is a second; the tick redraws only when the text would change. */
const AGE_TICK_MS = 1_000
const DEFAULT_THRESHOLD = 20
/** The most notes the model reads after tool results in one turn of one loop; past it, notes wait for the next turn. */
const NOTES_PER_TURN = 3
/** The Bash commands kept per turn to tell whether it ran the tests. */
const COMMANDS_KEPT = 50
/** The largest threshold the dashboard command accepts. */
const MAX_THRESHOLD = 100_000
/** The watcher's debounce (its default): how long it waits after a change before it scans. */
const WATCH_DEBOUNCE_MS = 300
/** The longest a turn's brief waits for the watcher to take the turn's edits in before it scans them itself. */
const WATCH_SETTLE_MAX_MS = 20_000
/** How often a watcher that ended on its own is started again, and the pause before each try (times the try's number). */
const WATCH_RESTARTS = 3
const WATCH_RESTART_MS = 30_000

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

/** The edited file's path from an edit tool's input: `notebook_path` for NotebookEdit. */
function editedPath(e: object): string | null {
  const { file_path: file, notebook_path: notebook } = e as { file_path?: unknown; notebook_path?: unknown }
  if (typeof file === 'string') return file
  return typeof notebook === 'string' ? notebook : null
}

/**
 * The module's own state for one load. The engine lets `$` reach only
 * functions declared at the top of the module, so the helpers below read
 * this rather than closing over `register`'s scope; `register` resets it,
 * as a reload starts the module's variables over.
 */
const mod = {
  threshold: 20,
  enforce: true,
  /** Set by an edit inside the project, cleared when a turn's scan is scheduled. */
  dirty: false,
  /** Paths edited since the last scan began: project-relative, or absolute before the first dashboard. */
  edited: new Set<string>(),
  /**
   * Set when the wrapper answers `no-binary`: nothing to run, so the mod stays
   * out of the way. Silence (a timeout, a crash) never sets it; that is asked
   * again at the next dirty turn.
   */
  disabled: false,
  flight: null as SingleFlight | null,
  /** The pane's rescans, one at a time. */
  rescanFlight: null as SingleFlight | null,
  /** Set between a rescan press and the timer that starts it. */
  rescanQueued: false,
  /** The tail of the scans queued so far: a turn's scan and a rescan never write one graph at once. */
  scanning: Promise.resolve() as Promise<unknown>,
  /** Dashboard loads, one at a time: an older, slower load can never land over a newer one. */
  dashboardFlight: null as SingleFlight | null,
  /** Whether the latest dashboard load stored what it read. */
  dashboardStored: false,
  /** What the band last drew, or null when it drew nothing: the age tick compares against it. */
  bandText: null as string | null,
  /** The pane's header status as last drawn, or null when the pane drew none: the age tick compares against it too. */
  paneText: null as string | null,
  /** Keeps the band's age current while the mod is on. */
  ticker: null as Timer | null,
  /**
   * Component lookups in flight, by snapshot and name: a press while one runs
   * starts no second. Checked and set with no await between, so two presses
   * in the same tick cannot both start one.
   */
  fetching: new Set<string>(),
  /**
   * Set from the confirming press until its allow-root run settles: a second
   * `y` in the same tick, before the state says running, starts nothing.
   */
  allowing: false,
  /** Whether the model gets notes at all (userConfig `agentNotes`). */
  notesOn: true,
  /**
   * What the model was told this session, so nothing is said twice. Keyed by
   * loop (`''` for the main one, else the agent id) and a NUL: a subagent's
   * context never held what the main loop read. `noted` holds files given
   * context (on Read or edit), `ruled` boundaries whose rules were stated.
   */
  noted: new Set<string>(),
  ruled: new Set<string>(),
  /** Tests a turn-end note named, and violations one reported; whether a truncated check was mentioned. */
  testsNamed: new Set<string>(),
  violationsSeen: new Set<string>(),
  truncationSaid: false,
  /** Whether a failed note after a tool call was logged this session: once is enough. */
  noteFailureLogged: false,
  /** Notes after tool results this turn, by loop; cleared when that loop's turn ends. */
  turnNotes: new Map<string, number>(),
  /** Bash commands run since the turn's last edit; at the turn's end they become `turnRan`, what its scan's note checks. */
  ranCommands: [] as string[],
  turnRan: [] as string[],
  /** The pane element the focus ring last landed on: a tab's hidden twin is walked past by where it came from. */
  focused: null as string | null,
  /** Whether `/knossos` is registered; until it is, retries run on a timer and at each turn's end. */
  commandRegistered: false,
  /** The pending registration retry, if any. */
  registerTimer: null as Timer | null,
  /**
   * Bumped by each session start and by turning the mod off: a retry belongs to
   * the generation it was scheduled in, so one still in flight when a new
   * session starts ends there instead of chaining beside the new session's own.
   */
  registerGen: 0,
  /** Whether a refused registration was logged this load: once is enough. */
  registerFailureLogged: false,
  /** Whether the live watcher runs at all (userConfig `watch`), and its poll interval (`watchPollMs`). */
  watchOn: true,
  watchPollMs: 1_000,
  /** The watcher child's stream while it runs: leaving its loop, or `return()` on it, ends the child. */
  watcher: null as AsyncGenerator<unknown, unknown> | null,
  /** Bumped by every start and stop: a loop of an older generation ends at its next piece instead of acting. */
  watchGen: 0,
  /**
   * Set when the watcher said this project is not its to watch (not allowed,
   * not scanned) or ended without a word (none offered, as a container
   * installation): not asked again until a root is allowed.
   */
  watchRefused: false,
  /** Restarts of a watcher that ended on its own, since the last one that came up. */
  watchFailures: 0,
  /** Not started again before this (mod clock, ms): the pause after a watcher ended on its own. */
  watchRetryAt: 0,
  /** Set between deciding to start the watcher and the timer that starts it: one start at a time. */
  watchStarting: false,
  /** Whether the watcher has seen changes it has not taken in yet. */
  watchPending: false,
  /** The newest snapshot the mod knows of: from the dashboard, a turn brief or the watcher. */
  snapshot: null as string | null,
  /**
   * The snapshot the graph was at when the turn's first edit landed: the turn
   * brief's `--since`, so it still reports the turn when the watcher (or
   * anyone) scanned its edits first. Null between turns.
   */
  turnBase: null as string | null,
  /** When the last edit was seen (mod clock, ms): the watcher needs a moment to notice it. */
  lastEditAt: 0,
}

/** How long to wait before each retry of a refused registration, in milliseconds; after the last, turn ends retry. */
const REGISTER_RETRY_MS = [500, 1_000, 2_000, 4_000, 8_000, 16_000, 30_000] as const

/**
 * Registers `/knossos`; false when the engine refuses, as it does when no
 * session is bound in the process yet (the moment after a hot reload). The
 * first refusal leaves one debug line.
 */
async function registerCommand($: EngineInterface): Promise<boolean> {
  if (mod.commandRegistered) return true
  try {
    await $.command.register({
      name: 'knossos',
      description: 'Toggle the Knossos architecture pane; /knossos inspect <component> to drill in',
    })
    mod.commandRegistered = true
    return true
  } catch (err) {
    if (!mod.registerFailureLogged) {
      mod.registerFailureLogged = true
      $.ui.log(`knossos: could not register /knossos yet, retrying (${err instanceof Error ? err.message : String(err)})`, { to: 'debug' })
    }
    return false
  }
}

/** Retries a refused registration after the `attempt`-th delay, then the next; past the last, the end of a turn tries again. */
function retryRegister($: EngineInterface, attempt: number, gen = mod.registerGen): void {
  const delay = REGISTER_RETRY_MS[attempt]
  if (delay === undefined || mod.commandRegistered || mod.disabled || gen !== mod.registerGen) return
  try {
    mod.registerTimer = $.clock.after(delay, () => {
      mod.registerTimer = null
      if (gen !== mod.registerGen) return
      void registerCommand($)
        .then(done => (done ? undefined : retryRegister($, attempt + 1, gen)))
        .catch(() => undefined)
    })
  } catch {
    mod.registerTimer = null
  }
}

/** The fan-in threshold from the options: an integer the dashboard command accepts (1 to 100000), else the default. */
function thresholdOf(value: unknown): number {
  const n = Number(value)
  return Number.isInteger(n) && n >= 1 && n <= MAX_THRESHOLD ? n : DEFAULT_THRESHOLD
}

/**
 * Redraws the band when the text it would draw now differs from what it drew:
 * a render answer is reused until state changes, so without this an age such
 * as "as of 12s ago" would stand still while the figures grow old.
 */
async function tickAge($: EngineInterface): Promise<void> {
  // The watcher is kept running from here, never from what its own events start: no call loops back on itself.
  await ensureWatcher($)
  // A pane closed by any means (the command, its own close key) stops drawing; stop ticking for it.
  if (mod.paneText !== null && !(await $.ui.panes()).some(pane => pane.id === PANE)) mod.paneText = null
  if (mod.bandText === null && mod.paneText === null) return
  const now = await $.clock.now()
  const d = await read($, dashboard)
  const model = bandModel(await read($, brief), await read($, job), now, d?.status === 'ok' ? declaredOf(d) : undefined, d?.status === 'ok' ? huesOf(d) : undefined)
  const pane = d?.status === 'ok' ? paneStatus(d, await read($, refresh), await read($, rescan), now, await read($, live)).text : null
  const bandStale = mod.bandText !== null && (model?.text ?? null) !== mod.bandText
  const paneStale = mod.paneText !== null && pane !== mod.paneText
  if (bandStale || paneStale) $.ui.invalidate('ui.render')
}

/**
 * Where a path lands with every link followed, so a checkout reached through
 * a linked directory still lies under the project root (a real path). A file
 * that is not there (deleted) lands through its directory; a path that cannot
 * be placed at all is kept as given.
 */
async function placed($: EngineInterface, path: string): Promise<string> {
  const own = await $.fs.stat(path, { resolve: true }).catch(() => undefined)
  if (own?.realPath !== undefined) return own.realPath
  const cut = path.lastIndexOf('/')
  if (cut < 0) return path
  const dir = await $.fs.stat(cut === 0 ? '/' : path.slice(0, cut), { resolve: true }).catch(() => undefined)
  return dir?.realPath === undefined ? path : `${dir.realPath.replace(/\/+$/, '')}/${path.slice(cut + 1)}`
}

/** Turns the mod off for the session: the wrapper found no knossos binary to run. */
async function disable($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  mod.disabled = true
  mod.ticker?.cancel()
  mod.ticker = null
  // Nothing to register a command for: no timed retry runs on, and no turn end asks again.
  mod.registerGen++
  mod.registerTimer?.cancel()
  mod.registerTimer = null
  stopWatcher()
  $.ui.log('knossos: no knossos binary found; the band and pane are off for this session.')
  $.ui.invalidate('ui.render')
}

/** Runs the wrapper in `dir` (the session's root by default); its stdout, or '' for any failure (the wrapper's contract is silence). */
async function wrapper($: EngineInterface, sub: string, args: string[], timeoutMs: number, dir?: string): Promise<string> {
  try {
    const root = dir ?? (await $.session.root())
    const script = `${$.plugin.root}/hooks/scripts/knossos-run.sh`
    const { stdout } = await $.process.run(['sh', script, sub, root, ...args], { timeoutMs })
    return stdout
  } catch {
    return ''
  }
}

/**
 * Loads the dashboard into state; false when nothing was stored. Silence, or
 * an error envelope over good figures, keeps the figures there and marks the
 * refresh failed so the pane says how old they are.
 */
async function refreshDashboard($: EngineInterface): Promise<boolean> {
  if (mod.disabled) return false
  // A request during a load coalesces into one rerun after it, so the last load to land is the newest.
  await (mod.dashboardFlight ??= new SingleFlight(() => loadDashboard($))).request()
  return mod.dashboardStored
}

/** One dashboard load; records in `mod.dashboardStored` whether it stored what it read. */
async function loadDashboard($: EngineInterface): Promise<void> {
  mod.dashboardStored = false
  if (mod.disabled) return
  const stdout = await wrapper($, 'dashboard', [`--fan-in-threshold=${mod.threshold}`], DASHBOARD_TIMEOUT_MS)
  const parsed = parseDashboard(stdout)
  if (parsed?.status === 'no-binary') {
    await disable($)
    return
  }
  if (parsed === null || (parsed.status === 'error' && (await read($, dashboard))?.status === 'ok')) {
    await update($, refresh, r => ({ ...r, failed: true }))
    return
  }
  const now = await $.clock.now()
  await update($, dashboard, () => parsed)
  await update($, refresh, (): RefreshState => ({ fetchedAt: now, failed: false }))
  mod.dashboardStored = true
  if (parsed.status === 'ok') mod.snapshot = parsed.snapshot_id ?? mod.snapshot
  // The pane open on a component follows the graph: a new snapshot looks it up again.
  const shown = (await read($, view)).inspect
  if (shown !== null) await requestDetail($, shown)
}

/**
 * Stores the person's theme from `/config`, for the colours a Raster cannot
 * take as theme keys. A config that cannot be read leaves the dark default.
 */
async function readTheme($: EngineInterface): Promise<void> {
  const row = (await $.config.list().catch(() => [])).find(r => r.key === 'theme')
  if (typeof row?.value === 'string') await update($, theme, () => row.value as string)
}

/**
 * The first dashboard. A silent one is not a reason to stop: the next dirty
 * turn asks again. Only the wrapper's `no-binary` turns the mod off.
 */
async function startUp($: EngineInterface, openOnStart: boolean): Promise<void> {
  await readTheme($)
  const root = await $.session.root().then(r => placed($, r)).catch(() => null)
  await update($, sessionRoot, () => root)
  const stored = await refreshDashboard($)
  if (mod.disabled) return
  mod.ticker ??= $.clock.every(AGE_TICK_MS, () => void tickAge($).catch(() => undefined))
  await ensureWatcher($)
  // A refused or unplaced pane is the person's layout, not a failure of the mod.
  if (openOnStart) await openPane($, !stored)
}

/**
 * Shows one component (or, with `file`, one file) on the pane and starts its
 * lookup. The lookup runs on a timer, never inside a render: the pane draws
 * a loading line from state until the answer is stored.
 */
async function showComponent($: EngineInterface, shown: Inspected): Promise<void> {
  // The detail's marker starts on its first row; the tab's is kept for `b` to put back.
  await update($, view, v => ({ ...v, inspect: shown, selected: 0, opened: v.inspect === null ? v.selected : v.opened }))
  await requestDetail($, shown)
}

/** Whether a stored detail is the one asked for: the same name, kind and snapshot. */
const sameDetail = (state: DetailState | null, shown: Inspected, snapshot: string | null): boolean =>
  state !== null && state.name === shown.name && (state.file === true) === (shown.file === true) && state.snapshot_id === snapshot

/** Starts a lookup of what `shown` names unless one is running or done for this snapshot; silence is asked again. */
async function requestDetail($: EngineInterface, shown: Inspected): Promise<void> {
  const snapshot = (await read($, dashboard))?.snapshot_id ?? null
  const { name } = shown
  const file = shown.file === true
  const key = `${snapshot ?? ''}\u0000${file ? 'file' : 'component'}\u0000${name}`
  const loading = (): DetailState => ({ snapshot_id: snapshot, name, ...(file ? { file: true } : {}), detail: null, phase: 'loading' })
  if (mod.fetching.has(key)) {
    // A lookup for this name is already in flight. When the detail has since
    // moved to another name (A, then B, then A again inside one lookup), put
    // the loading state back so the in-flight answer is stored when it lands.
    if (!sameDetail(await read($, detail), shown, snapshot)) await update($, detail, loading)
    return
  }
  mod.fetching.add(key)
  const current = await read($, detail)
  if (sameDetail(current, shown, snapshot) && current?.phase === 'done' && (file ? current.fileDetail : current.detail) != null) {
    mod.fetching.delete(key)
    return
  }
  await update($, detail, loading)
  $.clock.after(0, () => void loadDetail($, snapshot, shown, key))
}

/**
 * One lookup; its answer is stored only while the detail still asks for that
 * component (or file) and snapshot. A file is read by its path under the
 * project root, where the dashboard placed it.
 */
async function loadDetail($: EngineInterface, snapshot: string | null, shown: Inspected, key: string): Promise<void> {
  try {
    const file = shown.file === true
    const root = file ? ((await read($, dashboard))?.project_root ?? undefined) : undefined
    const stdout = await wrapper($, file ? 'file-detail' : 'component-detail', [shown.name], DETAIL_TIMEOUT_MS, root)
    const parsed = file ? parseFileDetail(stdout) : parseComponentDetail(stdout)
    if (parsed?.status === 'no-binary') {
      await disable($)
      return
    }
    const answer = file ? { fileDetail: parsed as FileDetail | null } : { detail: parsed as ComponentDetail | null }
    await update($, detail, (current): DetailState | null => (sameDetail(current, shown, snapshot) ? { ...current!, ...answer, phase: 'done' } : current))
  } catch {
    // A lost write leaves the loading line; the next press asks again.
  } finally {
    mod.fetching.delete(key)
  }
}

/**
 * Opens the pane and, unless the dashboard was just loaded, refreshes it on a
 * timer so the pane never presents old figures as current. A refused or
 * unplaced pane is the person's layout, not a failure of the mod.
 */
async function openPane($: EngineInterface, refreshFirst = true): Promise<void> {
  if (refreshFirst) $.clock.after(0, () => void refreshDashboard($).catch(() => undefined))
  await $.ui.open({ id: PANE, title: 'Knossos' }).catch(() => undefined)
}

/** `/knossos` toggles the pane, `/knossos inspect <component>` opens it on one component. */
async function runCommand($: EngineInterface, args: string): Promise<string> {
  const verb = args.trim().split(/\s+/)[0] ?? ''
  if (verb === 'inspect') {
    const name = args.trim().slice('inspect'.length).trim()
    if (name === '') return USAGE
    // The snapshot first, so the lookup is keyed to the graph it reads.
    const loaded = (await read($, dashboard)) === null && (await refreshDashboard($))
    await showComponent($, { name, label: name })
    await openPane($, !loaded)
    return 'Knossos pane opened.'
  }
  if (verb !== '') return USAGE
  if ((await $.ui.panes()).some(pane => pane.id === PANE)) {
    await $.ui.close({ id: PANE }).catch(() => undefined)
    mod.paneText = null
    return 'Knossos pane closed.'
  }
  await update($, view, v => ({ ...v, inspect: null }))
  await openPane($)
  return 'Knossos pane opened.'
}

/** Stores what a turn brief says, by its status; resolves true when it is a fresh `ok`. */
async function settleBrief($: EngineInterface, parsed: TurnBrief | null, now: number): Promise<boolean> {
  if (parsed?.status === 'no-binary') {
    await update($, job, (): JobState => ({ phase: 'idle', lastAttemptAt: now }))
    await disable($)
    return false
  }
  if (parsed === null || parsed.status === 'error') {
    await update($, job, (): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  if (parsed.status === 'scan-failed') {
    // The first failure shows its reason; later ones keep the last good figures with their age.
    const previous = await read($, brief)
    if (previous?.status !== 'ok') await update($, brief, () => parsed)
    await update($, job, (): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  await update($, brief, () => parsed)
  if (parsed.status === 'ok') await update($, changes, c => accumulate(c, parsed))
  await update($, job, (): JobState => ({ phase: 'idle', lastAttemptAt: now }))
  return parsed.status === 'ok'
}

/** One run of the scan job: the turn brief for every path edited since the last run. */
async function scan($: EngineInterface): Promise<void> {
  // Drained at the start of each run, so a coalesced rerun scans every path reported since.
  const files = [...mod.edited]
  mod.edited = new Set()
  // The turn's start goes with its files: a later turn's first edit takes the snapshot of its own.
  const base = mod.turnBase
  mod.turnBase = null
  let parsed: TurnBrief | null = null
  try {
    await update($, job, (j): JobState => ({ ...j, phase: 'scanning' }))
    // With a watcher keeping the graph current, the turn's edits are (or are about to be) scanned already:
    // the brief waits for that and reads them from the ledger instead of scanning again.
    const watching = isWatching(await read($, live))
    if (watching) await settleWatcher($)
    const args = [...files.map(f => `--files=${f}`), ...(base === null ? [] : [`--since=${base}`]), ...(watching ? ['--reuse-scan'] : []), ...(mod.enforce ? [] : ['--no-policies'])]
    parsed = parseTurnBrief(await exclusively(() => wrapper($, 'turn-brief', args, BRIEF_TIMEOUT_MS)))
  } finally {
    // A brief that never ran its scan (silence, an error, a refused root) saw none of these edits:
    // the next one must report them, since only reported files count toward the policy verdict.
    if (parsed === null || parsed.status !== 'ok') {
      for (const file of files) mod.edited.add(file)
      mod.turnBase ??= base
    }
  }
  if (parsed?.status === 'ok') mod.snapshot = parsed.snapshot_id ?? mod.snapshot
  if (!(await settleBrief($, parsed, await $.clock.now())) || parsed === null) return
  const note = turnEndNote(parsed, cdFor(parsed.project_root, await read($, sessionRoot)))
  if (note !== null) await deliverNote($, note)
  await refreshDashboard($)
}

/**
 * The one note the model reads after a scanned turn: the violations it
 * introduced that were not reported before (when policies are enforced), and
 * the tests that reach its changes that it did not run and no note named.
 * Null when there is nothing new, or notes are off.
 */
function turnEndNote(parsed: TurnBrief, cd: string | null): string | null {
  if (!mod.notesOn) return null
  const parts: string[] = []
  if (mod.enforce) {
    const fresh = freshViolations(parsed, mod.violationsSeen)
    const quietCut = fresh.policy.total === 0 && fresh.policy.truncated
    const violations = quietCut && mod.truncationSaid ? null : violationNote(fresh)
    if (violations !== null) parts.push(violations)
    if (quietCut) mod.truncationSaid = true
    for (const v of parsed.policy.violations) mod.violationsSeen.add(violationKey(v))
  }
  const tests = testsNote(parsed.tests, mod.turnRan, mod.testsNamed, cd)
  if (tests !== null) {
    parts.push(tests.text)
    for (const t of tests.tests) mod.testsNamed.add(t)
  }
  return parts.length === 0 ? null : parts.join('\n\n')
}

/**
 * Appends the turn-end note as a user-role row the model reads. A refusal
 * (a plugin above, or a run no plugin may shape) leaves a debug line with the
 * note, so a lost note is never silent.
 */
async function deliverNote($: EngineInterface, note: string): Promise<void> {
  let reason: string | null
  try {
    const appended = await $.session.append({ message: { type: 'user', content: [{ type: 'text', text: note }] } })
    reason = appended.deny ?? null
  } catch (err) {
    reason = err instanceof Error ? err.message : String(err)
  }
  if (reason !== null) $.ui.log(`knossos: the note did not reach the model (${reason}): ${note}`, { to: 'debug' })
}

/** The scan job's body: a run that throws leaves the job marked failed instead of rejecting. */
async function scanSafely($: EngineInterface): Promise<void> {
  try {
    await scan($)
  } catch {
    const now = await $.clock.now().catch(() => null)
    await update($, job, (j): JobState => ({ phase: 'failed', lastAttemptAt: now ?? j.lastAttemptAt })).catch(
      () => undefined,
    )
  }
}

/** Records an edit; the fan-in note for the model with the file it is about, or null when the file is quiet or outside. */
async function noteEdit($: EngineInterface, reported: string): Promise<{ text: string; path: string } | null> {
  const path = await placed($, reported)
  const current = await read($, dashboard)
  const root = current?.project_root ?? null
  if (root === null) {
    // No dashboard yet: the project root is unknown, so keep the absolute
    // path (the brief accepts those) when it lies under the session's root.
    if (relativise(await placed($, await $.session.root()), path) === null) return null
    mod.dirty = true
    mod.edited.add(path)
    return null
  }
  const relative = relativise(root, path)
  if (relative === null) return null
  mod.dirty = true
  mod.edited.add(relative)
  const entry = fanInIndex(current).get(relative)
  return entry === undefined || entry.dependent_files < mod.threshold ? null : { text: editNote(entry), path: relative }
}

/**
 * The tool result with the note `add` gives it, or `ran` unchanged when that
 * throws: a note is never worth a failed tool call. The first failure of a
 * session leaves one debug line; later ones are as quiet as the first.
 */
async function noteSafely<T>($: EngineInterface, ran: T, add: () => Promise<T>): Promise<T> {
  try {
    return await add()
  } catch (err) {
    if (!mod.noteFailureLogged) {
      mod.noteFailureLogged = true
      $.ui.log(`knossos: a note after a tool call failed and was left out (${err instanceof Error ? err.message : String(err)})`, { to: 'debug' })
    }
    return ran
  }
}

/** Whether `loop` may get another note this turn; counts it when it may. */
function takeNoteSlot(loop: string): boolean {
  const used = mod.turnNotes.get(loop) ?? 0
  if (used >= NOTES_PER_TURN) return false
  mod.turnNotes.set(loop, used + 1)
  return true
}

/**
 * The note for a Read of `reported` in `loop`: the file's dependents, its
 * boundary and the rules that bind it, before the model edits it. Null when
 * there is nothing new to say, the file lies outside the project, notes are
 * off, or this turn's notes are spent (then it is said on a later Read).
 */
async function noteRead($: EngineInterface, reported: string, loop: string): Promise<string | null> {
  const d = await read($, dashboard)
  if (!mod.notesOn || d?.status !== 'ok' || d.project_root === null) return null
  const relative = relativise(d.project_root, await placed($, reported))
  if (relative === null || mod.noted.has(`${loop}\u0000${relative}`)) return null
  const prefix = `${loop}\u0000`
  const ruled = new Set([...mod.ruled].filter(k => k.startsWith(prefix)).map(k => k.slice(prefix.length)))
  const declared = declaredOf(d)
  const note = readNote(relative, fanInIndex(d).get(relative), mod.threshold, d.policy, ruled, declared)
  if (note === null || !takeNoteSlot(loop)) return null
  mod.noted.add(`${prefix}${relative}`)
  for (const b of note.ruled) mod.ruled.add(`${prefix}${b}`)
  return note.text
}

/**
 * Runs `job` once every scan queued before it has settled, so a turn's scan
 * and a rescan never write one graph at once. A failed job does not stall
 * the queue.
 */
function exclusively<T>(job: () => Promise<T>): Promise<T> {
  const run = mod.scanning.then(job, job)
  mod.scanning = run.catch(() => undefined)
  return run
}

/**
 * Starts the pane's rescan once the press has resolved. A press while one is
 * queued or running adds nothing: the scan it asks for is already coming.
 */
function requestRescan($: EngineInterface): void {
  const flight = (mod.rescanFlight ??= new SingleFlight(() => rescanSafely($)))
  if (mod.rescanQueued || flight.isRunning) return
  mod.rescanQueued = true
  $.clock.after(0, () => {
    mod.rescanQueued = false
    void flight.request()
  })
}

/** One rescan; a run that throws leaves the header saying it failed rather than scanning forever. */
async function rescanSafely($: EngineInterface): Promise<void> {
  try {
    await runRescan($)
  } catch {
    await update($, rescan, (): RescanState => ({ phase: 'failed', reason: null })).catch(() => undefined)
  }
}

/**
 * Rescans the project incrementally through the wrapper's `scan`, then
 * reloads the dashboard so the pane draws the new snapshot. The header shows
 * `scanning…` meanwhile and the reason when it does not land.
 */
async function runRescan($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  await update($, rescan, (): RescanState => ({ phase: 'scanning', reason: null }))
  const parsed = parseRescan(await exclusively(() => wrapper($, 'scan', [], RESCAN_TIMEOUT_MS)))
  if (parsed?.status === 'no-binary') {
    await update($, rescan, (): RescanState => ({ phase: 'idle', reason: null }))
    await disable($)
    return
  }
  if (parsed?.status !== 'ok') {
    // A refused root is kept, so the pane can offer to allow it.
    const refused = parsed?.status === 'not-allowed' ? (parsed.refused_root ?? null) : null
    await update($, rescan, (): RescanState => ({ phase: 'failed', reason: rescanReason(parsed), refusedRoot: refused }))
    return
  }
  await refreshDashboard($)
  await update($, rescan, (): RescanState => ({ phase: 'idle', reason: null }))
}

/**
 * Starts the live watcher when it should run and does not: switched on, not
 * refused, past any pause before a restart, and with a dashboard of an
 * allowed, scanned project to watch. Called at start-up and on every age
 * tick, so a watcher that ended is started again from here.
 */
async function ensureWatcher($: EngineInterface): Promise<void> {
  if (!mod.watchOn || mod.disabled || mod.watcher !== null || mod.watchStarting || mod.watchRefused) return
  if ((await $.clock.now()) < mod.watchRetryAt) return
  const d = await read($, dashboard)
  if (d?.status !== 'ok' || d.project_root === null) return
  mod.watchStarting = true
  const gen = ++mod.watchGen
  // The loop runs for the session's life: never inside a hook's dispatch.
  $.clock.after(0, () => {
    mod.watchStarting = false
    void runWatcher($, gen, d.project_root!).catch(() => undefined)
  })
}

/**
 * Stops the live watcher: its loop ends at its next piece and `return()`
 * ends the child now. Never leaves one running: the engine also ends it
 * when the module unloads, and the watcher stops itself once the process
 * that started it is gone.
 */
function stopWatcher(): void {
  mod.watchGen++
  const running = mod.watcher
  mod.watcher = null
  mod.watchPending = false
  if (running !== null) void running.return(undefined).catch(() => undefined)
}

/**
 * One watcher child, `knossos watch --shared` through the wrapper, read to
 * its end. Each event moves the header's phase; one that names a newer
 * snapshot reloads the dashboard (one load at a time). A watcher that ends
 * on its own after coming up is started again a few times; one that never
 * said a word is not offered here and is not asked again.
 */
async function runWatcher($: EngineInterface, gen: number, root: string): Promise<void> {
  if (gen !== mod.watchGen) return
  let stream: AsyncGenerator<{ stream: string; text: string }, unknown>
  try {
    stream = $.process.spawn({ argv: ['sh', `${$.plugin.root}/hooks/scripts/knossos-run.sh`, 'watch', root, `--poll-ms=${mod.watchPollMs}`] })
  } catch {
    mod.watchRefused = true
    return
  }
  mod.watcher = stream
  await update($, live, (): LiveState => ({ phase: 'starting' }))
  let rest = ''
  let heard = false
  try {
    for await (const piece of stream) {
      if (gen !== mod.watchGen) break
      if (piece.stream !== 'stdout') continue
      const got = watchLines(rest, piece.text)
      rest = got.rest
      for (const event of got.events) {
        heard = true
        await onWatchEvent($, event)
      }
      // Its last word: the child ends on its own after it.
      if (got.events.some(e => e.event === 'refused' || e.event === 'stopped' || e.status === 'no-binary')) break
    }
  } catch {
    // A child that could not start, or a stream torn down under the loop: what follows decides.
  } finally {
    if (mod.watcher === stream) mod.watcher = null
  }
  if (gen !== mod.watchGen) return
  mod.watchPending = false
  await update($, live, () => LIVE_OFF)
  if (!heard) mod.watchRefused = true
  if (mod.watchRefused || mod.disabled) return
  // Started again by the age tick once the pause is over; past the last try, not at all.
  mod.watchFailures++
  mod.watchRetryAt = mod.watchFailures > WATCH_RESTARTS ? Number.POSITIVE_INFINITY : (await $.clock.now()) + WATCH_RESTART_MS * mod.watchFailures
}

/** What one watcher event changes: the phase, whether changes wait, and the dashboard when the snapshot moved. */
async function onWatchEvent($: EngineInterface, event: WatchEvent): Promise<void> {
  if (event.status === 'no-binary') return disable($)
  if (event.event === 'refused') mod.watchRefused = true
  if (event.event === 'ready' || event.event === 'following') mod.watchFailures = 0
  if (event.event === 'changes') mod.watchPending = true
  if (event.event === 'scan_completed' || event.event === 'absorbed' || event.event === 'stopped') mod.watchPending = false
  const before = (await read($, live)).phase
  const phase = phaseAfter(event, before)
  if (phase !== before) await update($, live, (): LiveState => ({ phase }))
  const snapshot = snapshotOf(event)
  if (snapshot !== null && snapshot !== mod.snapshot) {
    mod.snapshot = snapshot
    // Coalesced with any load in flight: the last to land is the newest.
    void refreshDashboard($).catch(() => undefined)
  }
}

/** Resolves after `ms` on the mod's clock. */
const sleep = ($: EngineInterface, ms: number): Promise<void> => new Promise(resolve => void $.clock.after(ms, () => resolve()))

/**
 * Waits, at most {@link WATCH_SETTLE_MAX_MS}, for the watcher to take the
 * turn's last edits in: until it has had a poll and its debounce to notice
 * them, and is neither scanning nor holding changes. The brief then finds
 * its files already in the graph and scans nothing itself.
 */
async function settleWatcher($: EngineInterface): Promise<void> {
  const quiet = mod.watchPollMs + WATCH_DEBOUNCE_MS + 250
  const until = (await $.clock.now()) + WATCH_SETTLE_MAX_MS
  for (;;) {
    const now = await $.clock.now()
    const busy = (await read($, live)).phase === 'scanning' || mod.watchPending
    if (now >= until || (!busy && now - mod.lastEditAt >= quiet)) return
    await sleep($, 100)
  }
}

/** Everything the pane draws, from state; null when there is no dashboard to draw. */
async function currentInput($: EngineInterface, terminal: boolean): Promise<PaneInput | null> {
  const d = await read($, dashboard)
  if (d?.status !== 'ok') return null
  const v = await read($, view)
  const stored = await read($, detail)
  const shown = v.inspect === null ? null : v.inspect.file ? fileDetailInput(v.inspect, stored, d.project_root, huesOf(d)) : detailInput(v.inspect, stored, d.project_root)
  return paneInput(d, await read($, brief), await read($, refresh), await read($, rescan), v, await $.clock.now(), terminal, shown, await read($, allow), await read($, changes), await read($, sessionRoot), await read($, live))
}

/** The rows the selection walks on what the pane shows, from state. */
async function currentList($: EngineInterface): Promise<Openable[]> {
  const input = await currentInput($, true)
  return input === null ? [] : listFor(input)
}

/** Moves the selection marker by `by` rows, kept inside the list. */
async function moveSelection($: EngineInterface, by: number): Promise<void> {
  const length = (await currentList($)).length
  await update($, view, v => ({ ...v, selected: Math.min(Math.max(0, v.selected + by), Math.max(0, length - 1)) }))
}

/** Opens the row at `index` (the marker's when absent) and leaves the marker there: a component or a file as its detail. */
async function openRow($: EngineInterface, index?: number): Promise<void> {
  const at = index ?? (await read($, view)).selected
  const item = (await currentList($))[at]
  if (item === undefined) return
  await update($, view, v => ({ ...v, selected: at }))
  // A boundary opens nothing: marking it is what spells it out.
  if (item.inert === true) return
  await showComponent($, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}) })
}

/**
 * Opens a place in the person's editor (see {@link EDITOR}). Where no editor
 * command answers, `path:line` goes onto the clipboard of the surface the
 * press came from instead, and a toast says which happened.
 */
async function openLocation($: EngineInterface, loc: Loc, surface?: RenderSurface): Promise<void> {
  const target = locText(loc)
  const opened = await $.process
    .run([...EDITOR, target], { timeoutMs: EDITOR_TIMEOUT_MS })
    .then(
      r => r.exitCode === 0,
      () => false,
    )
  if (opened) {
    $.ui.toast(`Opened ${target}`)
    return
  }
  const copied = await $.ui.copy({ text: target, ...(surface === undefined ? {} : { surface }) })
  $.ui.toast(copied.isCopied ? `No editor command to open it; copied ${target}` : `Could not open ${target}: ${copied.reason ?? 'no editor command, and the surface refused the copy'}`)
}

/** A press on a `file:` link the pane drew: opens its place. */
async function openLink($: EngineInterface, href: string, surface?: RenderSurface): Promise<void> {
  const loc = locOf(href)
  if (loc !== null) await openLocation($, loc, surface)
}

/** `e`: opens the file of what the detail shows, else of the marked row, whatever list the marker is in. */
async function openEditTarget($: EngineInterface, surface?: RenderSurface): Promise<void> {
  const input = await currentInput($, true)
  const loc = input === null ? null : editTarget(input)
  if (loc !== null) await openLocation($, loc, surface)
}

/** Copies a command the band offers (the allow-root command it could only name part of). */
async function copyCommand($: EngineInterface, command: string, surface?: RenderSurface): Promise<void> {
  const copied = await $.ui.copy({ text: command, ...(surface === undefined ? {} : { surface }) })
  $.ui.toast(copied.isCopied ? `Copied ${command}` : `Could not copy the command: ${copied.reason ?? 'the surface refused'}`)
}

/** Copies the command for the tests that reach this session's changes. */
async function copyTestCommand($: EngineInterface, surface?: RenderSurface): Promise<void> {
  const input = await currentInput($, true)
  const command = input?.changes.command ?? null
  if (command === null) return
  const copied = await $.ui.copy({ text: command, ...(surface === undefined ? {} : { surface }) })
  const tests = input?.changes.tests.length ?? 0
  $.ui.toast(copied.isCopied ? `Copied the command for ${tests === 1 ? '1 test' : `${tests} tests`}` : `Could not copy the test command: ${copied.reason ?? 'the surface refused'}`)
}

/** Opens a component the detail lists (who uses it, what it uses): the detail moves to that one. */
async function openRelated($: EngineInterface, index: number): Promise<void> {
  const item = (await currentList($))[index]
  if (item !== undefined) await showComponent($, { name: item.canonical, label: item.name })
}

/**
 * Opens the hubs filter's field and moves the focus into it once it is
 * drawn: the focus call waits for the next drawing, so it runs on a timer,
 * never inside the press or a render.
 */
async function openFilter($: EngineInterface): Promise<void> {
  await update($, view, (v): KnossosView => ({ ...v, tab: 'hubs', filtering: true }))
  $.clock.after(0, () => void $.ui.focus({ requestId: PANE, key: 'filter' }).catch(() => undefined))
}

/** The filter as typed, applied at once; the marker goes back to the top of the shorter list. */
async function typeFilter($: EngineInterface, text: string): Promise<void> {
  await update($, view, v => ({ ...v, filter: text, selected: 0 }))
}

/** Enter in the filter field: keep the text (an empty one clears the filter) and close the field. */
async function submitFilter($: EngineInterface, text: string): Promise<void> {
  await update($, view, v => ({ ...v, filter: text.trim(), filtering: false, selected: 0 }))
}

/** Copies the marked row's canonical name (a file's path) onto the clipboard of the surface the press came from. */
async function copySubject($: EngineInterface, surface?: RenderSurface): Promise<void> {
  const input = await currentInput($, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject === null) return
  // A cycle copies its chain, said by its name in the toast; anything else its full name.
  const text = subject.copy ?? subject.canonical
  const said = subject.copy === undefined || subject.copy === subject.canonical ? text : `${subject.name}: ${text}`
  const copied = await $.ui.copy({ text, ...(surface === undefined ? {} : { surface }) })
  $.ui.toast(copied.isCopied ? `Copied ${said}` : `Could not copy ${said}: ${copied.reason ?? 'the surface refused'}`)
}

/**
 * "Ask Claude" about the marked component (a cycle: how to break it; a
 * boundary: what its dependencies are for). The one prompt the mod ever
 * submits, and only from this press: the person asked for it, so it does not
 * start a turn on the mod's own account. One press, one prompt.
 */
async function askClaude($: EngineInterface): Promise<void> {
  const input = await currentInput($, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject !== null) await $.prompt.submit({ text: subject.ask ?? askPrompt(subject.canonical) })
}

/** `a`: asks the person to confirm allowing the refused root. Runs nothing. */
async function offerAllow($: EngineInterface): Promise<void> {
  const refused = refusedRoot(await read($, brief), await read($, rescan))
  const current = await read($, allow)
  const root = refused?.root ?? (current.phase === 'failed' ? current.root : null)
  if (root === null || current.phase === 'running') return
  await update($, allow, (): AllowState => ({ phase: 'confirming', root, reason: null }))
}

/**
 * `y` on the question: allows the root it asked about, once the press has
 * resolved. Nothing runs unless the pane is asking; a second press while a
 * run is queued or under way adds nothing.
 */
async function confirmAllow($: EngineInterface): Promise<void> {
  const asked = await read($, allow)
  if (asked.phase !== 'confirming' || asked.root === null || mod.allowing) return
  mod.allowing = true
  const root = asked.root
  await update($, allow, (): AllowState => ({ phase: 'running', root, reason: null }))
  $.clock.after(0, () => {
    void runAllow($, root)
      .catch(() => undefined)
      .finally(() => {
        mod.allowing = false
      })
  })
}

/**
 * Runs `knossos allow-root <root> --execute` through the wrapper, which
 * writes the roots file baked in at install. Allowed, the turn's scan runs
 * at once so the band and pane pick the project up.
 */
async function runAllow($: EngineInterface, root: string): Promise<void> {
  const parsed = parseAllowRoot(await wrapper($, 'allow-root', [], ALLOW_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') {
    await update($, allow, (): AllowState => ({ phase: 'idle', root: null, reason: null }))
    await disable($)
    return
  }
  if (parsed === null) {
    await update($, allow, (): AllowState => ({ phase: 'failed', root, reason: 'knossos did not allow it' }))
    return
  }
  await update($, allow, (): AllowState => ({ phase: 'done', root, reason: null }))
  await update($, rescan, (r): RescanState => ({ ...r, refusedRoot: null }))
  // A watcher refused for this root may watch it now, once the scan below lands a dashboard.
  mod.watchRefused = false
  // The edits that were refused are still unscanned: the turn's scan picks them up now.
  void (mod.flight ??= new SingleFlight(() => scanSafely($))).request()
}

/**
 * Where a focus ring headed for a tab's hidden hotkey twin (`tabkey:<id>`)
 * goes instead: onto its visible tab, or, when it comes from that tab (a
 * backward walk, as the twin sits just before it), onto the tab before; null
 * keeps it where it is (backward past the first tab). Undefined for any
 * other element.
 */
function twinTarget(element: string): string | null | undefined {
  if (!element.startsWith('tabkey:')) return undefined
  const id = element.slice('tabkey:'.length)
  if (mod.focused !== `tab:${id}`) return `tab:${id}`
  const before = TABS[TABS.findIndex(t => t.id === id) - 1]
  return before === undefined ? null : `tab:${before.id}`
}

/** What a press on the pane does, by the pressed element's id; `surface` is where the press came from. */
async function pressPane($: EngineInterface, id: string, surface?: RenderSurface): Promise<unknown> {
  if (id.startsWith('tab:') || id.startsWith('tabkey:')) {
    const tab = id.slice(id.indexOf(':') + 1) as PaneTab
    if (TABS.some(t => t.id === tab)) await update($, view, v => ({ ...v, tab, selected: 0, filtering: false, drift: false }))
    return
  }
  if (id === 'drift' || id === 'drifted') return update($, view, v => ({ ...v, drift: v.drift !== true, selected: 0 }))
  if (id.startsWith('row:')) return openRow($, Number(id.slice(4)))
  if (id.startsWith('rel:')) return openRelated($, Number(id.slice(4)))
  if (id === 'down' || id === 'up') return moveSelection($, id === 'down' ? 1 : -1)
  if (id === 'open') return openRow($)
  if (id === 'edit') return openEditTarget($, surface)
  if (id === 'tests') return copyTestCommand($, surface)
  if (id === 'back') return update($, view, v => ({ ...v, inspect: null, selected: v.opened ?? 0 }))
  if (id === 'keys') return update($, view, v => ({ ...v, showKeys: !v.showKeys }))
  if (id === 'filter') return openFilter($)
  if (id === 'clear') return update($, view, v => ({ ...v, filter: '', filtering: false, selected: 0 }))
  if (id === 'sort') return update($, view, v => ({ ...v, sort: SORTS[(SORTS.indexOf(v.sort) + 1) % SORTS.length] ?? 'in', selected: 0 }))
  if (id === 'rescan') return requestRescan($)
  if (id === 'copy') return copySubject($, surface)
  if (id === 'ask') return askClaude($)
  // The no-data pane's one action: the person's press sends it, as "Ask Claude" does.
  if (id === 'scan-ask') return $.prompt.submit({ text: SCAN_PROMPT })
  if (id === 'allow') return offerAllow($)
  if (id === 'allow-yes') return confirmAllow($)
  if (id === 'allow-no') return update($, allow, (a): AllowState => (a.phase === 'confirming' ? { phase: 'idle', root: null, reason: null } : a))
}

/**
 * One laid-out row as elements: a pressable segment is a plain Button, a
 * segment with a place a Markdown link to its `file:` URL (where `links`),
 * any other a Text. The layout already fitted the row, so nothing here wraps.
 *
 * A link is the surface's own: ctrl- or cmd-click opens it as a link in a
 * reply would, and a plain click (`onLinkPress`) opens it in the editor.
 */
function drawRow($: EngineInterface, ui: Elements[RenderSurface], row: Row, press: (id: string, surface?: RenderSurface) => void, links = false) {
  const { Box, Button, Markdown, Text } = ui
  // Every surface but mobile has a text field; there the filter is shown as text.
  const Input = 'Input' in ui ? ui.Input : undefined
  return (
    <Box key={row.key} flexDirection="row">
      {row.segments.map((s, i) =>
        s.field && Input !== undefined ? (
          <Input
            key={s.field.id}
            value={s.field.value}
            placeholder={s.field.placeholder}
            submitLabel="keep"
            autoFocus
            onInput={(value: string) => void typeFilter($, value).catch(() => undefined)}
            onSubmit={(value: string) => void submitFilter($, value).catch(() => undefined)}
          />
        ) : s.press && s.hidden ? (
          // Out of sight, there only for its hotkey (a tab drawn as its digit alone).
          <Box key={s.press.id} display="none">
            <Button key={s.press.id} plain label={s.press.label} {...(s.press.hotkey === undefined ? {} : { hotkey: s.press.hotkey })} onPress={pressed => press(s.press!.id, pressed.surface)} />
          </Box>
        ) : s.press ? (
          <Button
            key={s.press.id}
            plain
            label={s.press.label}
            {...(s.press.hotkey === undefined ? {} : { hotkey: s.press.hotkey })}
            {...(s.dim ? { dimColor: true } : {})}
            onPress={pressed => press(s.press!.id, pressed.surface)}
          />
        ) : s.link && links ? (
          <Markdown
            key={`${row.key}-link-${i}`}
            text={linkMarkdown(s.text, s.link)}
            {...(s.dim ? { dimColor: true } : {})}
            onLinkPress={(link, pressed) => void openLink($, link.href, pressed.surface).catch(() => undefined)}
          />
        ) : (
          <Text key={`${row.key}-${i}`} wrap="truncate-end" {...textStyle(s)}>
            {s.text}
          </Text>
        ),
      )}
    </Box>
  )
}

/**
 * The laid-out rows as elements. On the terminal, consecutive rows that share
 * a `raster` key (the heat map) are one `Raster` of coloured cells; every
 * other surface draws them as text, glyphs and colours alike.
 */
function drawRows($: EngineInterface, ui: Elements[RenderSurface], terminal: boolean, links: boolean, themeName: string, rows: Row[], press: (id: string, surface?: RenderSurface) => void) {
  const drawn = []
  for (let i = 0; i < rows.length; i++) {
    const block = rows[i]!.raster
    if (!terminal || block === undefined) {
      drawn.push(drawRow($, ui, rows[i]!, press, links))
      continue
    }
    let end = i
    while (end + 1 < rows.length && rows[end + 1]!.raster === block) end++
    const grid = rows.slice(i, end + 1)
    const { Raster } = ui as Elements['terminal']
    const raster = rasterOf(grid, Math.max(1, ...grid.map(rowWidth)), rasterTheme(themeName))
    drawn.push(<Raster key={`raster-${block}`} columns={raster.columns} rows={raster.rows} cells={raster.cells} />)
    i = end
  }
  return drawn
}

export const register: Register = (on, options) => {
  if (options.enabled === false) return
  mod.threshold = thresholdOf(options.fanInThreshold)
  mod.enforce = options.enforcePolicies !== false
  mod.dirty = false
  mod.edited = new Set()
  mod.disabled = false
  mod.flight = null
  mod.rescanFlight = null
  mod.rescanQueued = false
  mod.scanning = Promise.resolve()
  mod.dashboardFlight = null
  mod.dashboardStored = false
  mod.bandText = null
  mod.paneText = null
  mod.ticker?.cancel()
  mod.ticker = null
  mod.fetching = new Set()
  mod.allowing = false
  mod.focused = null
  mod.registerGen++
  mod.registerTimer?.cancel()
  mod.registerTimer = null
  mod.commandRegistered = false
  mod.registerFailureLogged = false
  mod.notesOn = options.agentNotes !== false
  mod.noted = new Set()
  mod.ruled = new Set()
  mod.testsNamed = new Set()
  mod.violationsSeen = new Set()
  mod.truncationSaid = false
  mod.noteFailureLogged = false
  mod.turnNotes = new Map()
  mod.ranCommands = []
  mod.turnRan = []
  // A reload starts the module over; the engine ends the old load's child with it, and this makes sure.
  stopWatcher()
  mod.watchOn = options.watch !== false
  mod.watchPollMs = watchPollMsOf(options.watchPollMs)
  mod.watchRefused = false
  mod.watchFailures = 0
  mod.watchRetryAt = 0
  mod.watchStarting = false
  mod.snapshot = null
  mod.turnBase = null
  mod.lastEditAt = 0
  const openOnStart = options.openPaneOnStart === true

  on('session.start', async ($, e, next) => {
    // A refused registration (no session bound yet, after a hot reload) is retried; start-up goes on regardless.
    const gen = ++mod.registerGen
    mod.registerTimer?.cancel()
    mod.registerTimer = null
    if (!(await registerCommand($))) retryRegister($, 0, gen)
    // A start-up that outlives the session (torn down under it) fails quietly, never as a stray rejection.
    $.clock.after(0, () => void startUp($, openOnStart).catch(() => undefined))
    return next(e)
  })

  // The session ends (exit, /clear, resume): its watcher ends with it. After a /clear the
  // process goes on with the same project, so the next tick starts a watcher for it again.
  on('session.end', async ($, e, next) => {
    stopWatcher()
    if (e.reason !== 'clear') mod.watchRetryAt = Number.POSITIVE_INFINITY
    return next(e)
  })

  on('tool.call', { tool: EDIT_TOOLS }, async ($, e, next) => {
    // Before the edit lands: the snapshot the turn's brief diffs against, whoever scans the edit first.
    if (!mod.disabled) mod.turnBase ??= mod.snapshot
    const ran = await next(e)
    if (!mod.disabled) mod.lastEditAt = await $.clock.now()
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    // Tests run before this edit ran the old code.
    mod.ranCommands = []
    return noteSafely($, ran, async () => {
      const path = editedPath(e)
      const note = path === null ? null : await noteEdit($, path)
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
    const ran = await next(e)
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    return noteSafely($, ran, async () => {
      const path = (e as { file_path?: unknown }).file_path
      const note = typeof path === 'string' ? await noteRead($, path, e.agentId ?? '') : null
      return note === null ? ran : { ...ran, context: [...(ran.context ?? []), note] }
    })
  })

  // Every command is kept for the turn-end note, which leaves out the tests the turn already ran.
  on('tool.call', { tool: 'Bash' }, async ($, e, next) => {
    const ran = await next(e)
    const command = (e as { command?: unknown }).command
    if (ran.deny === undefined && typeof command === 'string' && mod.ranCommands.length < COMMANDS_KEPT) mod.ranCommands.push(command)
    if (BASH_MARKS_DIRTY) mod.dirty = true
    return ran
  })

  on('turn.complete', async ($, e, next) => {
    const result = await next(e)
    mod.turnNotes.delete(e.agentId ?? '')
    // A subagent's turn ends inside the main one; its edits and commands ride the main turn's scan.
    if (e.agentId !== undefined) return result
    mod.turnRan = mod.ranCommands
    mod.ranCommands = []
    // Still refused once the timed retries ran out: each turn's end asks again, a session being bound by now.
    if (!mod.disabled && !mod.commandRegistered && mod.registerTimer === null) await registerCommand($)
    // No graph to draw yet (the person may just have asked Claude to scan): look again, once the turn is over.
    if (!mod.disabled && (await read($, dashboard))?.status !== 'ok') $.clock.after(0, () => void refreshDashboard($).catch(() => undefined))
    if (mod.disabled || !mod.dirty) return result
    mod.dirty = false
    const current = (mod.flight ??= new SingleFlight(() => scanSafely($)))
    // Never inside the hook's budget: the job starts once this dispatch has resolved.
    $.clock.after(0, () => void current.request())
    return result
  })

  on('ui.render', { component: 'AbovePrompt' }, async ($, e, next) => {
    if (mod.disabled || e.props.hasSurvey || (await read($, view)).isBandHidden) {
      mod.bandText = null
      return next(e)
    }
    const d = await read($, dashboard)
    const model = bandModel(await read($, brief), await read($, job), await $.clock.now(), d?.status === 'ok' ? declaredOf(d) : undefined, d?.status === 'ok' ? huesOf(d) : undefined)
    mod.bandText = model?.text ?? null
    if (model === null) return next(e)
    const { Box, Button, Text } = $.ui.resolve(e)
    const color = model.tone === 'alert' ? 'error' : model.tone === 'warn' ? 'warning' : 'inactive'
    // The text gives way to the buttons, each drawn `[ label ]` and a space: a cut line still names what happened.
    const labels = [...(model.showDetails ? ['details'] : []), ...(model.copy === undefined ? [] : ['copy']), 'hide']
    const room = Math.max(1, e.props.bodyColumns - labels.reduce((n, l) => n + l.length + 5, 0) - 1)
    const copy = model.copy
    return (
      <Box key="band">
        <Text color={color} wrap="truncate-end">
          {fit(model.text, room)}{' '}
        </Text>
        {model.showDetails && (
          <Button key="details" label="details" onPress={() => openPane($)} />
        )}
        {copy !== undefined && (
          <Button key="copy" label="copy" onPress={pressed => void copyCommand($, copy, pressed.surface).catch(() => undefined)} />
        )}
        <Button key="hide" label="hide" onPress={() => update($, view, v => ({ ...v, isBandHidden: true }))} />
      </Box>
    )
  })

  // A theme picked in /config redraws the heat map in its colours.
  on('config.set', { key: 'theme' }, async ($, e, next) => {
    const set = await next(e)
    const value = set.value
    if (set.deny === undefined && typeof value === 'string') await update($, theme, () => value)
    return set
  })

  on('command.run', { command: 'knossos' }, async ($, e) => ({ text: await runCommand($, e.args) }))

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
    if (moved.deny === undefined && Number.isInteger(index)) await update($, view, v => ({ ...v, selected: index }))
    return moved
  })

  on('ui.render', { component: 'Pane', requestId: PANE }, async ($, e) => {
    const ui = $.ui.resolve(e)
    const { Box } = ui
    mod.paneText = null
    if (mod.disabled) return <Box key="off" />
    const d = await read($, dashboard)
    const v = await read($, view)
    const columns = Math.max(1, Math.min(CONTENT_MAX, e.props.bodyColumns))
    const press = (id: string, surface?: RenderSurface) => void pressPane($, id, surface).catch(() => undefined)
    if (d === null || d.status !== 'ok') {
      const offer = allowInput(await read($, brief), await read($, rescan), await read($, allow))
      return (
        <Box key="empty" flexDirection="column">
          {emptyRows(offer, columns).map(row => drawRow($, ui, row, press))}
        </Box>
      )
    }
    const input = await currentInput($, e.surface === 'terminal')
    if (input === null) return <Box key="empty" />
    mod.paneText = input.status.text
    // No loop variable may be called `h`: JSX compiles to h(...), and a
    // parameter of that name shadows the element factory inside its callback.
    // A press that outlives the session (a teardown under it) fails quietly.
    return (
      <Box key={v.inspect === null ? 'pane' : 'detail'} flexDirection="column">
        {drawRows($, ui, e.surface === 'terminal', e.surface !== 'mobile', await read($, theme), paneRows(input, columns), press)}
      </Box>
    )
  })
}
