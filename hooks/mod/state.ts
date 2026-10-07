/**
 * The mod's own state for one load, and what it is reset to: the module
 * variables every other module reads and writes (`mod`), the constants more
 * than one of them needs, and the few helpers that touch only that state.
 * Nothing here reaches the engine.
 */
import type { PluginOptions, Timer } from 'claude-code'

import type { Dashboard, SessionChanges } from '../../types'
import { noActivity } from '../lib/activity'
import { cycleSearchStopped } from '../lib/envelopes'
import type { Activity } from '../lib/activity'
import { watchPollMsOf } from '../lib/live'
import { SingleFlight } from '../lib/scheduler'

/** The pane's id, as the engine opens and draws it. */
export const PANE = 'knossos'

/**
 * The key a field (the finder's, a note's, the hubs filter's) is drawn under,
 * and the one `$.ui.focus` names to move into it. Never the field's own id:
 * the Button that opens a field shares that id, and Claude Code resolves a
 * focus call by key against the drawing it holds when the call arrives, the
 * first element under the key in document order. That can be the Button,
 * still drawn: the ring lands on it, the field never gets the keys, and once
 * the next drawing drops the Button the keyboard goes back to the prompt.
 */
export const fieldKey = (id: string): string => `field:${id}`

/** The fan-in threshold when the options give none the dashboard command accepts. */
const DEFAULT_THRESHOLD = 20
/** The largest threshold the dashboard command accepts. */
const MAX_THRESHOLD = 100_000

/**
 * The mod's own state for one load, shared by its modules: what the session
 * state (`$.state`) does not hold because nothing draws from it. `register`
 * resets it ({@link reset}), as a reload starts the module's variables over.
 */
export const mod = {
  threshold: 20,
  /**
   * The session id whose baseline is in state, once it was read back or
   * recorded (see `lib/baseline.ts`); null until then, and after a session
   * ends. The ending session's id, while the process may still answer it.
   */
  baselineOf: null as string | null,
  endedSession: null as string | null,
  /** Whether this session's commit was asked for: a silent answer is not asked again. */
  headAsked: false,
  /** Whether where the checkout stands was asked for this load: start-up asks only when the baseline's own read did not. */
  gitAsked: false,
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
  /** Reads of the changes since the session began, one at a time, a request during one coalescing into one more. */
  ledgerFlight: null as SingleFlight | null,
  /** What the band last drew, or null when it drew nothing: the age tick compares against it. */
  bandText: null as string | null,
  /** The pane's header status as last drawn, or null when the pane drew none: the age tick compares against it too. */
  paneText: null as string | null,
  /** Whether the pane last drew its no-figures state: the age tick retries a failed load while it shows. */
  emptyShown: false,
  /** When the last dashboard load started (mod clock, ms). */
  dashboardTriedAt: 0,
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
  /** The running watcher's process id, as it said: signalled when it is stopped. */
  watcherPid: null as number | null,
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
  /** When the session's tool calls ran, every loop's: whose a scanned change was. */
  activity: noActivity() as Activity,
  /** Numbers each call the activity keeps, so two calls never share an id. */
  callSeq: 0,
  /** When the scan on its way began (the watcher's `scan_started`, or the leader's while following); null when none is. */
  scanStartedAt: null as number | null,
  /** When the last scan the mod heard of began and landed: a change made while it ran is noticed only after it. */
  lastScan: null as { start: number; end: number } | null,
  /** The finder's searches, one at a time, a keystroke during one coalescing into one more; and the pending debounce. */
  searchFlight: null as SingleFlight | null,
  searchTimer: null as Timer | null,
  /** Set by Enter before the answer for what is typed landed: its first match opens when it does. */
  openWhenFound: false,
  /** Where the tab's marker stood when the finder opened over it: closing the finder puts it back. */
  findFrom: 0,
  /** Whether the pane was last drawn wide (master-detail), as the render saw it. */
  paneWide: false,
  /** The pending reads that wait out a pause (the lookup of the marked row beside the tab), by loader. */
  loadTimers: new Map<string, Timer>(),
  /** The row (by `peekKey`) last found to have nothing to show beside the tab, so the tick does not look again. */
  peekNone: null as string | null,
  /** Whether a pane too large to draw however few rows it gave was logged: once a load. */
  tooLargeLogged: false,
  /** Commit notes already given, by loop and text: the same note is never said twice. */
  commitNoted: new Set<string>(),
  /** Whether a scan's new cycles and violations are toasted (userConfig `notifications`), and the ones already said. */
  alertsOn: true,
  toasted: new Set<string>(),
}

/** The fan-in threshold from the options: an integer the dashboard command accepts (1 to 100000), else the default. */
function thresholdOf(value: unknown): number {
  const n = Number(value)
  return Number.isInteger(n) && n >= 1 && n <= MAX_THRESHOLD ? n : DEFAULT_THRESHOLD
}

/**
 * Lets the live watcher go: its loop ends at its next piece, and `return()`
 * ends the child once the read it waits on ends. Never leaves one running:
 * the engine also ends it when the module unloads, and the watcher stops
 * itself once the process that started it is gone. Resolves to the process
 * id the running child named, if any.
 */
export function stopWatcher(): number | null {
  mod.watchGen++
  const running = mod.watcher
  const pid = mod.watcherPid
  mod.watcher = null
  mod.watcherPid = null
  mod.watchPending = false
  if (running === null) return null
  void running.return(undefined).catch(() => undefined)
  return pid
}

/**
 * Starts the module's own state over for a load with `options`: a reload
 * runs `register` again, and nothing of the old load's may carry over.
 */
export function reset(options: PluginOptions): void {
  mod.threshold = thresholdOf(options.fanInThreshold)
  mod.enforce = options.enforcePolicies !== false
  mod.dirty = false
  mod.edited = new Set()
  mod.disabled = false
  mod.baselineOf = null
  mod.endedSession = null
  mod.headAsked = false
  mod.gitAsked = false
  mod.flight = null
  mod.rescanFlight = null
  mod.rescanQueued = false
  mod.scanning = Promise.resolve()
  mod.dashboardFlight = null
  mod.dashboardStored = false
  mod.ledgerFlight = null
  mod.bandText = null
  mod.paneText = null
  mod.emptyShown = false
  mod.dashboardTriedAt = 0
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
  mod.activity = noActivity()
  mod.callSeq = 0
  mod.scanStartedAt = null
  mod.lastScan = null
  mod.searchFlight = null
  mod.searchTimer?.cancel()
  mod.searchTimer = null
  mod.openWhenFound = false
  mod.findFrom = 0
  mod.paneWide = false
  mod.peekNone = null
  mod.tooLargeLogged = false
  mod.loadTimers.forEach(timer => timer.cancel())
  mod.loadTimers = new Map()
  mod.commitNoted = new Set()
  mod.alertsOn = options.notifications !== false
  mod.toasted = new Set()
}

/** The most notes the model reads after tool results in one turn of one loop; past it, notes wait for the next turn. */
const NOTES_PER_TURN = 3

/** The edited file's path from an edit tool's input: `notebook_path` for NotebookEdit. */
export function editedPath(e: object): string | null {
  const { file_path: file, notebook_path: notebook } = e as { file_path?: unknown; notebook_path?: unknown }
  if (typeof file === 'string') return file
  return typeof notebook === 'string' ? notebook : null
}

/** The cycles a dashboard holds, by members: what tells a cycle new since the session began. */
const cycleKeys = (d: Dashboard): string[] => d.cycles.largest.map(c => [...c.members].sort().join('\u0000'))

/**
 * The cycles the graph holds as a session begins: the count, the listed ones,
 * whether the list is all of them, and whether the count is a whole count
 * rather than what a search that stopped early reached. `exact` is absent in
 * state recorded before it existed.
 */
export type StartCycles = { count: number; keys: string[]; complete: boolean; exact?: boolean }

export const cyclesOf = (d: Dashboard): StartCycles => ({ count: d.cycles.count, keys: cycleKeys(d), complete: !d.cycles.truncated && d.cycles.largest.length >= d.cycles.count, exact: !cycleSearchStopped(d.cycles) })

/** Whether `path`'s change is this session's own: always, without the scan ledger's origins (the turns reported it). */
export const ownChange = (session: SessionChanges, path: string): boolean => session.origins === undefined || session.origins[path] === 'session'

/** Whether `loop` may get another note this turn; counts it when it may. */
export function takeNoteSlot(loop: string): boolean {
  const used = mod.turnNotes.get(loop) ?? 0
  if (used >= NOTES_PER_TURN) return false
  mod.turnNotes.set(loop, used + 1)
  return true
}

/**
 * Runs `job` once every scan queued before it has settled, so a turn's scan
 * and a rescan never write one graph at once. A failed job does not stall
 * the queue.
 */
export function exclusively<T>(job: () => Promise<T>): Promise<T> {
  const run = mod.scanning.then(job, job)
  mod.scanning = run.catch(() => undefined)
  return run
}
