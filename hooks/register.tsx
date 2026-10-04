import { atom, read, update } from 'claude-code'
import type { Elements, EngineInterface, Register, RenderElement, RenderNode, RenderSurface, Timer } from 'claude-code'

import { activeBetween, begin, counts, finish, FOLLOWED_SCAN_MS, lookbackMs, noActivity, scanWindow } from './lib/activity'
import type { Activity } from './lib/activity'
import { bandModel } from './lib/band'
import { BASELINES_KEY, baselinesOf, remember } from './lib/baseline'
import type { Baseline } from './lib/baseline'
import type { JobState } from './lib/band'
import { diffView } from './lib/diff'
import { parseAllowRoot, parseAnnotate, parseBlastRadius, parseBranchDiff, parseChurn, parseComponentDetail, parseCouplings, parseDashboard, parseFileContext, parseFileDetail, parseGraphSearch, parsePathBetween, parseRescan, parseSessionDiff, parseSessionHead, parseSessionLedger, parseSessionRev, parseTurnBrief, rescanReason } from './lib/envelopes'
import type { SessionLedger, TurnBrief } from './lib/envelopes'
import { ledgerChanges, ownTimeline } from './lib/changes'
import {
  accumulate,
  allowInput,
  askPrompt,
  cdFor,
  couplingPair,
  detailInput,
  editTarget,
  emptyRows,
  FEEDBACK_MS,
  fileDetailInput,
  fit,
  linkMarkdown,
  listFor,
  locOf,
  locText,
  nextTarget,
  NO_CHANGES,
  noGraphOf,
  paneHeight,
  paneInput,
  paneLayout,
  paneStatus,
  PEEK_TABS,
  peekList,
  refusedRoot,
  rowWidth,
  SCAN_PROMPT,
  shrinkRows,
  SORTS,
  subjectOf,
  TABS,
  tierOf,
} from './lib/layout'
import type { Loc, Openable, PaneInput, Preview, Row, Segment } from './lib/layout'
import { commitNote, committedSince, CONTEXT_DESCRIPTION, CONTEXT_SCHEMA, CONTEXT_TOOL, contextAnswer, REFLOG_READ, stillReported } from './lib/agent'
import { alertKeys, freshAlerts } from './lib/alerts'
import { FLASH_MS, flashKeys, ledgerFlashKeys } from './lib/flash'
import type { Flash } from './lib/flash'
import { boundOf, editNote, fanInIndex, freshViolations, readNote, ruleText, testsNote, violationKey, violationNote } from './lib/notes'
import { isWatching, LIVE_OFF, liveAfter, snapshotOf, watchLines, watchPollMsOf } from './lib/live'
import { CARD_BG, declaredOf, huesOf, SELECTED_BG } from './lib/palette'
import { relativise } from './lib/paths'
import { rasterOf, rasterTheme } from './lib/raster'
import { cells, dimRow, pressLabel, textStyle } from './lib/rows'
import { SingleFlight } from './lib/scheduler'
import type { AllowState, BranchState, ChurnState, ComponentDetail, Dashboard, NoteState, RingsState, RouteState, SearchState, CouplingState, DetailState, DiffState, Feedback, GitHead, SessionRev, FileDetail, Inspected, KnossosView, LiveState, PaneTab, RefreshState, RescanState, SessionChanges, WatchEvent } from '../types'

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
/** Session ends after which the process goes no further with this project: no watcher is started again. */
const FINAL_ENDS: readonly string[] = ['prompt_input_exit', 'logout', 'other']
/** How long the signal to a stopped watcher may take. */
const KILL_TIMEOUT_MS = 5_000
/** How often the pane with no figures, after a load that failed, asks for the dashboard again. */
const EMPTY_RETRY_MS = 15_000
/** The wrapper bounds session-changes, session-head and session-diff at 15 s. */
const LEDGER_TIMEOUT_MS = 20_000
const HEAD_TIMEOUT_MS = 20_000
const DIFF_TIMEOUT_MS = 20_000
/** The wrapper bounds boundary-couplings at 15 s. */
const COUPLINGS_TIMEOUT_MS = 20_000
/** The most paths kept as the session's own edits, and the most snapshots kept as its own scans. */
const EDITS_KEPT = 1_000
const SCANS_KEPT = 1_000
/** Why the Changes tab lists only the turn briefs' files. */
const NO_WATCHER = "No live watcher, so only what this session's turns reported is listed."
/** The finder before anything was typed. */
const NO_SEARCH: SearchState = { query: '', for: null, phase: 'idle', answer: null }
/** How long the finder waits after a keystroke before it asks: a word typed fast is one search. */
const SEARCH_DEBOUNCE_MS = 150
/** The wrapper bounds graph-search at 15 s, and branch-diff (two whole graphs compared) at 30 s. */
const SEARCH_TIMEOUT_MS = 20_000
const BRANCH_TIMEOUT_MS = 35_000
/** How long the detail beside the tab waits after the marker moves before it looks the marked row up: a run of j presses is one lookup. */
const PEEK_DEBOUNCE_MS = 120
/** The wrapper bounds file-context at 15 s. */
const CONTEXT_TIMEOUT_MS = 20_000
/** The wrapper bounds churn, blast-radius, path-between and annotate at 15 s. */
const CHURN_TIMEOUT_MS = 20_000
const RINGS_TIMEOUT_MS = 20_000
const ROUTE_TIMEOUT_MS = 20_000
const NOTE_TIMEOUT_MS = 20_000
/**
 * The model's tool as the engine lists it (`mcp__<plugin>__<name>`), until a
 * registration names it: the name `$.tool.register` returns is the one its
 * calls carry ({@link mod}`.contextTool`).
 */
const CONTEXT_TOOL_NAME = `mcp__knossos__${CONTEXT_TOOL}`

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
  /** Whether the pane was last drawn wide (master-detail), as the render saw it; and the pending lookup of the marked row. */
  paneWide: false,
  peekTimer: null as Timer | null,
  /** The row (by {@link peekKey}) last found to have nothing to show beside the tab, so the tick does not look again. */
  peekNone: null as string | null,
  /** Whether a pane too large to draw however few rows it gave was logged: once a load. */
  tooLargeLogged: false,
  /** The model's `knossos_context` tool by the full name its registration returned (null: not registered), and whether a refused registration was logged. */
  contextTool: null as string | null,
  toolFailureLogged: false,
  /** Commit notes already given, by loop and text: the same note is never said twice. */
  commitNoted: new Set<string>(),
  /** Whether a scan's new cycles and violations are toasted (userConfig `notifications`), and the ones already said. */
  alertsOn: true,
  toasted: new Set<string>(),
}

/**
 * Registers the model's `knossos_context` tool (one file's context in one
 * call); false when the engine refuses, as it does until a session is bound.
 * The first refusal leaves one debug line.
 */
async function registerTool($: EngineInterface): Promise<boolean> {
  if (mod.contextTool !== null) return true
  try {
    const { tool } = await $.tool.register({ name: CONTEXT_TOOL, description: CONTEXT_DESCRIPTION, inputSchema: CONTEXT_SCHEMA as unknown as Record<string, unknown> })
    mod.contextTool = typeof tool === 'string' && tool !== '' ? tool : CONTEXT_TOOL_NAME
    return true
  } catch (err) {
    if (!mod.toolFailureLogged) {
      mod.toolFailureLogged = true
      $.ui.log(`knossos: could not register the knossos_context tool yet, retrying (${err instanceof Error ? err.message : String(err)})`, { to: 'debug' })
    }
    return false
  }
}

/**
 * The `knossos_context` tool's answer for `asked` (a path, relative to the
 * project or absolute): `file-context` for it, the rules the dashboard says
 * bind it, and how this session changed it. Bounded and compact; never
 * throws, whatever the wrapper does.
 */
async function answerContext($: EngineInterface, asked: unknown): Promise<string> {
  if (typeof asked !== 'string' || asked.trim() === '') return 'knossos_context: give the file as `path`, relative to the project root or absolute.'
  // Advice the model can act on: a missing binary and a refused root are not cured by a scan.
  if (mod.disabled) return 'knossos_context: no knossos binary was found in this session, so there is no graph to answer from; read the file and grep for its callers instead.'
  const d = await read($, dashboard)
  if (d?.status !== 'ok' || d.project_root === null) {
    const refused = refusedRoot(await read($, brief), await read($, rescan))
    if (refused !== null) return `knossos_context: knossos may not scan ${refused.root}: it is not an allowed root. Ask the person to allow it (the knossos band offers the command), then call this again.`
    return 'knossos_context: knossos has no graph of this project yet; scan it with the knossos MCP tools first.'
  }
  const root = d.project_root
  const placedPath = asked.startsWith('/') ? await placed($, asked) : asked.replace(/^\.\//, '')
  const relative = placedPath.startsWith('/') ? relativise(root, placedPath) : placedPath
  if (relative === null || relative === '' || relative.split('/').includes('..')) return `knossos_context: ${asked} is outside the project knossos scanned (${root}).`
  const context = parseFileContext(await wrapper($, 'file-context', [relative], CONTEXT_TIMEOUT_MS, root))
  if (context?.status === 'no-binary') return 'knossos_context: no knossos binary is installed.'
  const { bound, unsure } = boundOf(relative, d.policy)
  const rules = (d.policy?.rules ?? []).filter(r => bound.includes(r.from)).map(ruleText)
  // Only a change of this session's own: one made outside it since it began is not the session's doing.
  const shown = await shownChanges($, root)
  const session = ownChange(shown, relative) ? (shown.files[relative]?.status ?? null) : null
  return contextAnswer(asked, context, rules, unsure, session)
}

/** The cycles a dashboard holds, by members: what tells a cycle new since the session began. */
const cycleKeys = (d: Dashboard): string[] => d.cycles.largest.map(c => [...c.members].sort().join('\u0000'))

/** The cycles the graph holds as a session begins: the count, the listed ones, and whether the list is all of them. */
type StartCycles = { count: number; keys: string[]; complete: boolean }
const cyclesOf = (d: Dashboard): StartCycles => ({ count: d.cycles.count, keys: cycleKeys(d), complete: !d.cycles.truncated && d.cycles.largest.length >= d.cycles.count })

/** How long git may take to name HEAD or print its reflog around a shell command. */
const GIT_PROBE_TIMEOUT_MS = 2_000

/** The repository a commit note speaks for: the graph's project root, else the session's. */
async function projectRoot($: EngineInterface): Promise<string> {
  const d = await read($, dashboard)
  return d?.status === 'ok' && d.project_root !== null ? d.project_root : await $.session.root()
}

/**
 * HEAD in `repo`: the commit, '' on a branch with no commit yet, null when
 * `repo` is no repository or git did not answer. Run around a shell command
 * in its hook, never in a render.
 */
async function headAt($: EngineInterface, repo: string): Promise<string | null> {
  try {
    const { exitCode, stdout } = await $.process.run(['git', '--no-optional-locks', '-C', repo, 'rev-parse', '-q', '--verify', 'HEAD'], { timeoutMs: GIT_PROBE_TIMEOUT_MS })
    if (exitCode === 1) return ''
    const head = stdout.trim()
    return exitCode === 0 && /^[0-9a-f]{40,64}$/.test(head) ? head : null
  } catch {
    return null
  }
}

/** HEAD's newest reflog entries in `repo`, `<sha>\x1f<subject>` a line; '' when there is none or git did not answer. */
async function reflogAt($: EngineInterface, repo: string): Promise<string> {
  try {
    const { exitCode, stdout } = await $.process.run(['git', '--no-optional-locks', '-C', repo, 'reflog', '-n', String(REFLOG_READ), '--format=%H%x1f%gs', 'HEAD'], { timeoutMs: GIT_PROBE_TIMEOUT_MS })
    return exitCode === 0 ? stdout : ''
  } catch {
    return ''
  }
}

/**
 * The note after a commit in `loop`, or null when there is nothing new to
 * say: of this session's own changes (its files, not ones changed outside
 * it since it began), the violations its turns introduced that the policy
 * check still reports, the files no test reaches, and the cycles new since
 * the session began. A cycle is named new only when the graph listed every
 * cycle it held as the session began; otherwise only the count that grew
 * is said.
 */
async function commitNoteFor($: EngineInterface, loop: string): Promise<string | null> {
  const d = await read($, dashboard)
  if (!mod.notesOn || d?.status !== 'ok') return null
  const session = await shownChanges($, d.project_root)
  const untested = Object.entries(session.files)
    .filter(([path, f]) => f.status !== 'deleted' && f.tests === 0 && ownChange(session, path))
    .map(([path]) => path)
    .sort()
  const start = await read($, startCycles)
  const keys = new Set(start?.keys ?? [])
  const fresh = start?.complete === true ? d.cycles.largest.filter(c => !keys.has([...c.members].sort().join('\u0000'))) : []
  const count = start === null ? 0 : Math.max(d.cycles.count - start.count, fresh.length)
  const chains = fresh.map(c => `${c.members.slice(0, 4).join(' → ')}${c.members.length > 4 ? ' → …' : ''}`)
  const note = commitNote(mod.enforce ? stillReported(session.violations, session.violation_snapshots, d) : [], untested, { count, chains })
  if (note === null) return null
  const key = `${loop}\u0000${note}`
  if (mod.commitNoted.has(key) || !takeNoteSlot(loop)) return null
  mod.commitNoted.add(key)
  return note
}

/** Whether `path`'s change is this session's own: always, without the scan ledger's origins (the turns reported it). */
const ownChange = (session: SessionChanges, path: string): boolean => session.origins === undefined || session.origins[path] === 'session'

/** How long to wait before each retry of a refused registration, in milliseconds; after the last, turn ends retry. */
const REGISTER_RETRY_MS = [500, 1_000, 2_000, 4_000, 8_000, 16_000, 30_000] as const

/**
 * Registers `/knossos` and the model's `knossos_context` tool; false when
 * the engine refuses either, as it does when no session is bound in the
 * process yet (the moment after a hot reload). The first refusal leaves one
 * debug line.
 */
async function registerCommand($: EngineInterface): Promise<boolean> {
  // The model's tool rides on the same registration, and its retries: both need a session bound.
  const tool = await registerTool($)
  if (mod.commandRegistered) return tool
  try {
    await $.command.register({
      name: 'knossos',
      description: 'Toggle the Knossos architecture pane; /knossos inspect <component> to drill in',
    })
    mod.commandRegistered = true
    return tool
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
  if (delay === undefined || (mod.commandRegistered && mod.contextTool !== null) || mod.disabled || gen !== mod.registerGen) return
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
  // After a /clear or a resume the process may name the new session only later: its baseline is settled once it does.
  if (mod.baselineOf === null && !mod.disabled && (await currentSession($)) !== null) await settleBaseline($)
  // The watcher is kept running from here, never from what its own events start: no call loops back on itself.
  await ensureWatcher($)
  await retryEmpty($)
  // The footer's word after an action fades once its time is up: a change of state, so the pane redraws without it.
  const said = await read($, feedback)
  if (said !== null && (await $.clock.now()) >= said.until) await update($, feedback, () => null)
  // The rows a scan lit go back once their moment is over.
  const lit = await read($, flash)
  if (lit !== null && (await $.clock.now()) >= lit.until) await update($, flash, () => null)
  // A pane drawn wide shows the marked row's detail beside the tab: looked up from here the first time it is drawn so.
  // A row already found to have nothing to show is not looked at again each tick: that would lay the pane out every second.
  if (mod.paneWide && (await read($, peek)) === null && mod.peekNone !== peekKey(await read($, view), (await read($, dashboard))?.snapshot_id ?? null)) await requestPeek($)
  // A pane closed by any means (the command, its own close key) stops drawing; stop ticking for it, and it is no longer wide.
  if (mod.paneText !== null && !(await $.ui.panes()).some(pane => pane.id === PANE)) {
    mod.paneText = null
    mod.paneWide = false
  }
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
 * While the pane shows no figures because a load failed (silent, or an
 * error), asks for the dashboard again every {@link EMPTY_RETRY_MS}: the
 * pane says it is retrying, so it does.
 */
async function retryEmpty($: EngineInterface): Promise<void> {
  if (!mod.emptyShown || mod.disabled || mod.dashboardFlight?.isRunning === true) return
  if (!(await $.ui.panes()).some(pane => pane.id === PANE)) {
    mod.emptyShown = false
    return
  }
  const d = await read($, dashboard)
  if (d?.status === 'ok' || noGraphOf(d, await read($, refresh)) !== 'unreadable') return
  if ((await $.clock.now()) - mod.dashboardTriedAt < EMPTY_RETRY_MS) return
  void refreshDashboard($).catch(() => undefined)
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
  // Nor a debounced search or lookup: a loader that would run after this finds the mod off and reads nothing.
  mod.searchTimer?.cancel()
  mod.searchTimer = null
  mod.peekTimer?.cancel()
  mod.peekTimer = null
  // Nothing to register a command for: no timed retry runs on, and no turn end asks again.
  mod.registerGen++
  mod.registerTimer?.cancel()
  mod.registerTimer = null
  await endWatcher($).catch(() => undefined)
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
  mod.dashboardTriedAt = await $.clock.now()
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
  const before = await read($, dashboard)
  // The rows the new snapshot changed light up for a moment once it lands.
  await light($, flashKeys(before, parsed as Dashboard), now)
  await update($, dashboard, () => parsed)
  // A cycle or a policy violation the scan brought: said once, as a toast, unless the person turned that off.
  if (mod.alertsOn) {
    for (const alert of freshAlerts(before, parsed as Dashboard, mod.toasted)) {
      alertKeys(alert).forEach(key => mod.toasted.add(key))
      $.ui.toast(alert.text)
    }
  }
  await update($, refresh, (): RefreshState => ({ fetchedAt: now, failed: false }))
  mod.dashboardStored = true
  if (parsed.status === 'ok') mod.snapshot = parsed.snapshot_id ?? mod.snapshot
  // The session's changes are read since the first snapshot it saw, kept for when it is continued in another process.
  if (parsed.status === 'ok' && parsed.snapshot_id !== null && (await read($, sessionStart)) === null) {
    await update($, sessionStart, () => parsed.snapshot_id)
    await saveBaseline($)
  }
  // The cycles as the session found them: what the note after a commit counts as new.
  if (parsed.status === 'ok' && (await read($, startCycles)) === null) await update($, startCycles, () => cyclesOf(parsed))
  // The pane open on a component follows the graph: a new snapshot looks it up again.
  const shown = (await read($, view)).inspect
  if (shown !== null) await requestDetail($, shown)
  // And the Boundaries tab's cell: its couplings are read for the graph on show; and the Branch tab's comparison.
  await requestCouplings($)
  await requestBranch($)
  await requestPeek($)
  await requestRoute($)
}

/**
 * Asks for the changes since the session began again (from the scan ledger),
 * coalesced with a read in flight. Only while a watcher keeps that ledger:
 * without one the Changes tab shows the turn briefs. Called from the
 * watcher's loop and after a turn's brief, never inside a render.
 */
function requestLedger($: EngineInterface): void {
  if (mod.disabled || mod.watcher === null) return
  void (mod.ledgerFlight ??= new SingleFlight(() => loadLedger($))).request().catch(() => undefined)
}

/** One read of the changes since the session began; silence or an error keeps the last one. */
async function loadLedger($: EngineInterface): Promise<void> {
  const since = await read($, sessionStart)
  if (since === null || mod.disabled) return
  const parsed = parseSessionLedger(await wrapper($, 'session-changes', [`--since=${since}`], LEDGER_TIMEOUT_MS))
  if (parsed?.status === 'no-binary') return disable($)
  if (parsed?.status !== 'ok') return
  // A session that began again (a /clear) while this read ran reads since its own start.
  if ((await read($, sessionStart)) !== since) return
  await light($, ledgerFlashKeys(await read($, sessionLedger), parsed), await $.clock.now())
  await update($, sessionLedger, () => parsed)
}

/** Lights `keys` for {@link FLASH_MS} from `now`, beside whatever is still lit; nothing to light changes nothing. */
async function light($: EngineInterface, keys: string[], now: number): Promise<void> {
  if (keys.length === 0) return
  await update($, flash, (f): Flash => ({ keys: [...new Set([...(f !== null && now < f.until ? f.keys : []), ...keys])], until: now + FLASH_MS }))
}

/**
 * The session's changes as the Changes tab and "Look at now" show them:
 * while a watcher keeps the scan ledger, everything that changed since the
 * session began, whoever changed it, each file labelled by whether the
 * session's own edits made it; otherwise what the turn briefs reported, with
 * why. The notes after a turn never read this: they stay the turns' own. The
 * note after a commit and the `knossos_context` answer do, and keep only the
 * files whose origin is this session ({@link ownChange}).
 */
async function shownChanges($: EngineInterface, root: string | null): Promise<SessionChanges> {
  const turns = await read($, changes)
  // Without the ledger the timeline holds the scans the session's own turns and rescans made.
  if (mod.watcher === null || !isWatching(await read($, live))) return { ...turns, fallback: NO_WATCHER, timeline: ownTimeline(await read($, sessionScans)) }
  const ledger = await read($, sessionLedger)
  if (ledger === null || ledger.since !== (await read($, sessionStart))) return turns
  const edits = (await read($, sessionEdits)).map(p => (p.startsWith('/') && root !== null ? (relativise(root, p) ?? p) : p))
  return ledgerChanges(ledger, turns, new Set(edits), await read($, sessionScans), await read($, sessionBegan))
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
  // First: where the session began, read back for a session continued here, else the commit it begins at, before anything of it can be committed.
  await settleBaseline($)
  // Where the checkout stands, for the header: the baseline's own head read, when there was one, said it already.
  if (!mod.gitAsked) await readGitHead($)
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
  await update($, view, v => ({ ...v, inspect: shown, selected: 0, opened: v.inspect === null ? v.selected : v.opened, route: null, picking: null }))
  await requestDetail($, shown)
}

/** Whether a stored detail is the one asked for: the same name, kind and snapshot. */
const sameDetail = (state: DetailState | null, shown: Inspected, snapshot: string | null): boolean =>
  state !== null && state.name === shown.name && (state.file === true) === (shown.file === true) && state.snapshot_id === snapshot

/** Starts a lookup of what `shown` names unless one is running or done for this snapshot; silence is asked again. */
async function requestDetail($: EngineInterface, shown: Inspected): Promise<void> {
  if (mod.disabled) return
  await requestDiff($, shown)
  await requestRings($, shown)
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

/** The session's id, or null when the engine does not say, or still names the session that ended. */
async function currentSession($: EngineInterface): Promise<string | null> {
  const id = await $.session.id().catch(() => null)
  return typeof id === 'string' && id !== '' && id !== mod.endedSession ? id : null
}

/** The baselines kept in the plugin's store, by session id; none when it cannot be read. */
async function storedBaselines($: EngineInterface): Promise<Record<string, Baseline>> {
  return baselinesOf(await $.store.get(BASELINES_KEY).catch(() => undefined))
}

/**
 * Where the session began. A session this mod has seen before (continued
 * or resumed, in this process or another) gets its commit and its first
 * snapshot back from the store, so its Changes and diffs keep their
 * baseline; any other session records the commit it begins at. Either way
 * the baseline is stored under the session's id.
 */
async function settleBaseline($: EngineInterface): Promise<void> {
  const id = await currentSession($)
  const kept = id === null ? undefined : (await storedBaselines($))[id]
  if (id !== null && kept !== undefined) {
    if (kept.rev !== null) await update($, sessionRev, () => kept.rev)
    if (kept.snapshot !== null) await update($, sessionStart, () => kept.snapshot)
    await update($, sessionBegan, () => kept.startedAt)
    mod.baselineOf = id
    return
  }
  // Asked once per session: a head read later would name a commit made during it.
  if (!mod.headAsked) {
    mod.headAsked = true
    await recordHead($)
  }
  await saveBaseline($)
}

/** Stores the session's baseline as it stands, under its id; a store that refuses is no failure. */
async function saveBaseline($: EngineInterface): Promise<void> {
  const id = await currentSession($)
  if (id === null || mod.disabled) return
  const all = await storedBaselines($)
  const baseline: Baseline = { rev: await read($, sessionRev), snapshot: await read($, sessionStart), startedAt: await $.clock.now() }
  await $.store.set(BASELINES_KEY, remember(all, id, baseline)).catch(() => undefined)
  // A session saved before keeps the moment it began.
  await update($, sessionBegan, () => all[id]?.startedAt ?? baseline.startedAt)
  mod.baselineOf = id
}

/**
 * Records the commit the project is at as the one the session began at,
 * unless one is recorded. Silence leaves none: a later read would name a
 * commit made during the session, and the diff would leave its changes out.
 */
async function recordHead($: EngineInterface): Promise<void> {
  if (mod.disabled || (await read($, sessionRev)) !== null) return
  const stdout = await wrapper($, 'session-head', [], HEAD_TIMEOUT_MS)
  if (parseSessionDiff(stdout)?.status === 'no-binary') return disable($)
  const rev = parseSessionRev(stdout)
  if (rev !== null) await update($, sessionRev, () => rev)
  await noteGitHead($, stdout)
}

/**
 * Reads where the checkout stands, its commit and branch, for the header:
 * at start-up and after each of the main loop's turns (one may commit or
 * switch branches), always on a timer, never in a render.
 */
async function readGitHead($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  const stdout = await wrapper($, 'session-head', [], HEAD_TIMEOUT_MS)
  if (parseSessionDiff(stdout)?.status === 'no-binary') return disable($)
  await noteGitHead($, stdout)
}

/** Keeps what a `session-head` answer says of the checkout; silence keeps what was read before. */
async function noteGitHead($: EngineInterface, stdout: string): Promise<void> {
  mod.gitAsked = true
  const head = parseSessionHead(stdout)
  if (head !== undefined) await update($, gitHead, () => head)
  // The Churn tab's history ends at the checkout's commit: a new one reads it again.
  await requestChurn($)
}

/**
 * Starts reading the Boundaries tab's marked heat map cell (the component
 * pairs behind it) unless that read is done or running for this cell of this
 * graph. On a timer, never in a render: the card says it is reading until
 * the answer is stored, and an answer for a cell the marker has left is
 * dropped.
 */
async function requestCouplings($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  const input = await currentInput($, true)
  const pair = input === null ? null : couplingPair(input)
  if (pair === null) return
  const d = await read($, dashboard)
  const snapshot = d?.snapshot_id ?? null
  const current = await read($, couplings)
  if (current !== null && current.from === pair.from && current.to === pair.to && current.snapshot === snapshot) return
  await update($, couplings, (): CouplingState => ({ snapshot, from: pair.from, to: pair.to, phase: 'loading', answer: null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  $.clock.after(0, () => void loadCouplings($, pair.from, pair.to, snapshot, root).catch(() => undefined))
}

/** One read of a cell's couplings; stored only while the pane still marks that cell of that graph. */
async function loadCouplings($: EngineInterface, from: string, to: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseCouplings(await wrapper($, 'boundary-couplings', [`--from=${from}`, `--to=${to}`], COUPLINGS_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable($)
  await update($, couplings, (c): CouplingState | null => (c !== null && c.from === from && c.to === to && c.snapshot === snapshot ? { ...c, phase: 'done', answer: parsed } : c))
}

/** What the footer says for {@link FEEDBACK_MS} after an action: the mod's clock decides when it fades (see `tickAge`). */
async function say($: EngineInterface, text: string, tone: Feedback['tone']): Promise<void> {
  const until = (await $.clock.now()) + FEEDBACK_MS
  await update($, feedback, (): Feedback => ({ text, tone, until }))
}

/**
 * Starts reading how a changed file (one opened from the session's changes)
 * changed since the session began, unless that read is done or running for
 * this graph: a new snapshot (the file changed again) reads it again. Runs
 * on a timer, never in a render; without the session's commit there is
 * nothing to read, and the detail says why.
 */
async function requestDiff($: EngineInterface, shown: Inspected): Promise<void> {
  if (shown.file !== true || shown.changed !== true || mod.disabled) return
  const rev = await read($, sessionRev)
  if (rev?.status !== 'ok') return
  const d = await read($, dashboard)
  const snapshot = d?.snapshot_id ?? null
  const current = await read($, fileDiff)
  if (current !== null && current.name === shown.name && current.rev === rev.rev && current.snapshot === snapshot) return
  await update($, fileDiff, (): DiffState => ({ name: shown.name, rev: rev.rev, snapshot, phase: 'loading', diff: null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  $.clock.after(0, () => void loadDiff($, shown.name, rev.rev, snapshot, root).catch(() => undefined))
}

/** One read of a file's change; stored only while the detail still asks for that file, commit and graph. */
async function loadDiff($: EngineInterface, name: string, rev: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const stdout = await wrapper($, 'session-diff', [`--rev=${rev}`, `--file=${name}`], DIFF_TIMEOUT_MS, root)
  const parsed = parseSessionDiff(stdout)
  if (parsed?.status === 'no-binary') return disable($)
  const now = await read($, fileDiff)
  if (now !== null && now.name === name && now.rev === rev && now.snapshot === snapshot) await update($, fileDiff, (): DiffState => ({ ...now, phase: 'done', diff: parsed }))
}

/**
 * One lookup; its answer is stored only while the detail still asks for that
 * component (or file) and snapshot. A file is read by its path under the
 * project root, where the dashboard placed it.
 */
async function loadDetail($: EngineInterface, snapshot: string | null, shown: Inspected, key: string): Promise<void> {
  if (mod.disabled) return
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
    mod.paneWide = false
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
    // Only a watcher that is running now: the header's state alone may outlive it.
    const watching = mod.watcher !== null && isWatching(await read($, live))
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
  // A brief that scanned took in the turn's own edits: its scan is the session's.
  if (parsed?.status === 'ok' && parsed.scanned === true && parsed.snapshot_id !== null) await keepScan($, parsed.snapshot_id)
  if (!(await settleBrief($, parsed, await $.clock.now())) || parsed === null) return
  const note = turnEndNote(parsed, cdFor(parsed.project_root, await read($, sessionRoot)))
  if (note !== null) await deliverNote($, note)
  // The brief's own scan is in the ledger too.
  requestLedger($)
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

/** Keeps a path the session's own edit tools wrote, for the Changes tab's "this session". */
async function keepEdit($: EngineInterface, path: string): Promise<void> {
  await update($, sessionEdits, edits => (edits.includes(path) || edits.length >= EDITS_KEPT ? edits : [...edits, path]))
}

/** Keeps a snapshot as one the session's own work produced: every file its scan changed is this session's. */
async function keepScan($: EngineInterface, snapshot: string): Promise<void> {
  await update($, sessionScans, scans => (scans.includes(snapshot) ? scans : [...scans, snapshot].slice(-SCANS_KEPT)))
}

/**
 * Whether the scan that produced `snapshot`, begun at `started` and landed
 * at `ended`, took in changes made while the session's tools ran; keeps it
 * as the session's when it did. Remembered as the last scan either way.
 */
async function attributeScan($: EngineInterface, snapshot: string, started: number, ended: number): Promise<void> {
  const { from, to } = scanWindow(started, ended, lookbackMs(mod.watchPollMs, WATCH_DEBOUNCE_MS), mod.lastScan)
  mod.lastScan = { start: started, end: ended }
  if (activeBetween(mod.activity, from, to)) await keepScan($, snapshot)
}

/**
 * Records an edit that landed inside the project, with `base` (the snapshot
 * from before it) as the turn's start when the turn has none yet. Resolves
 * to the fan-in note for the model with the file it is about, or null when
 * the file is quiet or outside.
 */
async function noteEdit($: EngineInterface, reported: string, base: string | null): Promise<{ text: string; path: string } | null> {
  const path = await placed($, reported)
  const current = await read($, dashboard)
  const root = current?.project_root ?? null
  if (root === null) {
    // No dashboard yet: the project root is unknown, so keep the absolute
    // path (the brief accepts those) when it lies under the session's root.
    if (relativise(await placed($, await $.session.root()), path) === null) return null
    mod.dirty = true
    mod.edited.add(path)
    mod.turnBase ??= base
    await keepEdit($, path)
    return null
  }
  const relative = relativise(root, path)
  if (relative === null) return null
  mod.dirty = true
  mod.edited.add(relative)
  mod.turnBase ??= base
  await keepEdit($, relative)
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
  const started = await $.clock.now()
  const parsed = parseRescan(await exclusively(() => wrapper($, 'scan', [], RESCAN_TIMEOUT_MS)))
  if (parsed?.status === 'ok' && typeof parsed.snapshot_id === 'string') await attributeScan($, parsed.snapshot_id, started, await $.clock.now())
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
 * Lets the live watcher go: its loop ends at its next piece, and `return()`
 * ends the child once the read it waits on ends. Never leaves one running:
 * the engine also ends it when the module unloads, and the watcher stops
 * itself once the process that started it is gone. Resolves to the process
 * id the running child named, if any.
 */
function stopWatcher(): number | null {
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
 * Stops the live watcher ({@link stopWatcher}) and says so: the header
 * stops saying live at once. `return()` on the stream ends the child only
 * once the read it waits on ends (its next line, up to a heartbeat away), so
 * the child is also signalled now: its lock is free for the next session at
 * once, not 15 s later.
 */
async function endWatcher($: EngineInterface): Promise<void> {
  const pid = stopWatcher()
  if (pid !== null) void $.process.run(['kill', '-TERM', String(pid)], { timeoutMs: KILL_TIMEOUT_MS }).catch(() => undefined)
  await update($, live, () => LIVE_OFF)
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
    if (mod.watcher === stream) {
      mod.watcher = null
      mod.watcherPid = null
    }
  }
  if (gen !== mod.watchGen) {
    // Stopped from outside: the header says nothing is live unless a newer watcher took over.
    if (mod.watcher === null && !mod.watchStarting) await update($, live, () => LIVE_OFF)
    return
  }
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
  if (typeof event.pid === 'number' && Number.isInteger(event.pid) && event.pid > 1) mod.watcherPid = event.pid
  const before = await read($, live)
  const after = liveAfter(event, before)
  if (after.phase !== before.phase || after.stale !== before.stale) await update($, live, () => after)
  const snapshot = snapshotOf(event)
  const moved = snapshot !== null && snapshot !== mod.snapshot
  await attributeEvent($, event, snapshot, moved)
  if (moved) {
    mod.snapshot = snapshot
    // Coalesced with any load in flight: the last to land is the newest.
    void refreshDashboard($).catch(() => undefined)
  }
  // What changed since the session began: read once the watcher is up, and again after each scan it saw.
  if (moved || event.event === 'ready' || event.event === 'leading' || event.event === 'following') requestLedger($)
}

/**
 * Whose changes a watcher event's scan took in. `scan_started` (or, while
 * following, `leader_scanning`) marks when a scan began; the watcher's own
 * `scan_completed` lands it, and a `snapshot` or `absorbed` that moves the
 * graph lands another writer's, begun at the mark or, unseen, a scan's time
 * before the poll that saw it.
 */
async function attributeEvent($: EngineInterface, event: WatchEvent, snapshot: string | null, moved: boolean): Promise<void> {
  const now = await $.clock.now()
  if (event.event === 'scan_started' || event.event === 'leader_scanning') {
    mod.scanStartedAt = now
    return
  }
  const landed = event.event === 'scan_completed' || (moved && (event.event === 'snapshot' || event.event === 'absorbed'))
  if (!landed || snapshot === null) return
  const started = mod.scanStartedAt ?? (event.event === 'scan_completed' ? now : now - mod.watchPollMs - FOLLOWED_SCAN_MS)
  mod.scanStartedAt = null
  await attributeScan($, snapshot, started, now)
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
  if (shown !== null && v.inspect !== null) shown.diff = diffView(v.inspect, await read($, fileDiff), await read($, sessionRev))
  const extras = {
    git: await read($, gitHead),
    feedback: await read($, feedback),
    couplings: await read($, couplings),
    flash: await read($, flash),
    search: await read($, search),
    branch: await read($, branch),
    churn: await read($, churn),
    rings: await read($, rings),
    route: await read($, route),
    note: await read($, note),
  }
  // The marked row's detail beside the tab: only while the pane is drawn wide.
  const peeked = mod.paneWide ? await read($, peek) : null
  const side = peeked === null ? null : peeked.shown.file === true ? fileDetailInput(peeked.shown, peeked.detail, d.project_root, huesOf(d)) : detailInput(peeked.shown, peeked.detail, d.project_root)
  if (side !== null && peeked !== null) side.diff = diffView(peeked.shown, await read($, fileDiff), await read($, sessionRev))
  return paneInput(d, await read($, brief), await read($, refresh), await read($, rescan), v, await $.clock.now(), terminal, shown, await read($, allow), await shownChanges($, d.project_root), await read($, sessionRoot), await read($, live), extras, side)
}

/** The rows the selection walks on what the pane shows, from state. */
async function currentList($: EngineInterface): Promise<Openable[]> {
  const input = await currentInput($, true)
  return input === null ? [] : listFor(input)
}

/** Moves the selection marker by `by` rows, kept inside the list. */
async function moveSelection($: EngineInterface, by: number): Promise<void> {
  const length = (await currentList($)).length
  // A boundary marked anew starts on what it depends on most.
  await update($, view, v => ({ ...v, selected: Math.min(Math.max(0, v.selected + by), Math.max(0, length - 1)), target: undefined }))
}

/** Opens the row at `index` (the marker's when absent) and leaves the marker there: a component or a file as its detail. */
async function openRow($: EngineInterface, index?: number): Promise<void> {
  const at = index ?? (await read($, view)).selected
  const item = (await currentList($))[at]
  if (item === undefined) return
  // A match opened from the finder closes it: the detail opens over the tab, whose marker stays where it stood.
  if ((await read($, view)).finding === true) {
    // Picking where a route ends: the match is that end, and the route is drawn.
    if (((await read($, view)).picking ?? null) !== null) return showRoute($, { name: item.canonical, label: item.name })
    mod.openWhenFound = false
    await update($, view, (v): KnossosView => ({ ...v, finding: false, selected: mod.findFrom }))
    return showComponent($, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}) })
  }
  await update($, view, v => ({ ...v, selected: at }))
  // An Overview chart's bar opens the tab it counts: Hubs narrowed to a bucket, a flow's cell on Boundaries, Changes.
  const jump = item.jump
  if (jump !== undefined) {
    await update($, view, (v): KnossosView => ({ ...v, tab: jump.tab, selected: jump.selected ?? 0, degree: jump.degree ?? null, filtering: false, drift: false, target: jump.target }))
    return
  }
  // A boundary opens nothing: marking it is what spells it out.
  if (item.inert === true) return
  await showComponent($, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}), ...(item.changed === true ? { changed: true } : {}) })
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
  if (opened) return say($, '✓ opened in editor', 'ok')
  const copied = await $.ui.copy({ text: target, ...(surface === undefined ? {} : { surface }) })
  await say($, copied.isCopied ? '✗ no editor · path copied' : '✗ no editor', 'alert')
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
  await say($, copied.isCopied ? `✓ copied the command for ${tests === 1 ? '1 test' : `${tests} tests`}` : '✗ the surface refused the copy', copied.isCopied ? 'ok' : 'alert')
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

/**
 * Opens the finder over the pane and moves the focus into its field once it
 * is drawn (on a timer, never inside the press or a render). What was
 * typed last is kept, so a second look picks up where the first left off.
 */
async function openFinder($: EngineInterface): Promise<void> {
  const v = await read($, view)
  if (v.finding !== true) mod.findFrom = v.selected
  await update($, view, (w): KnossosView => ({ ...w, finding: true, filtering: false, selected: 0 }))
  $.clock.after(0, () => void $.ui.focus({ requestId: PANE, key: 'find' }).catch(() => undefined))
}

/** Closes the finder: the tab (or the detail) under it comes back with its marker where it stood. */
async function closeFinder($: EngineInterface): Promise<void> {
  mod.openWhenFound = false
  await update($, view, (v): KnossosView => (v.finding === true ? { ...v, finding: false, picking: null, selected: mod.findFrom } : v))
}

/** What was typed into the finder, kept at once; the search for it runs after a short pause in the typing. */
async function typeQuery($: EngineInterface, text: string): Promise<void> {
  await update($, search, (f): SearchState => ({ ...f, query: text }))
  await update($, view, v => ({ ...v, selected: 0 }))
  requestSearch($)
}

/** Enter in the finder: opens the marked match once the answer for what is typed is in; an empty field closes the finder. */
async function submitQuery($: EngineInterface, text: string): Promise<void> {
  if (text.trim() === '') return closeFinder($)
  const found = await read($, search)
  if (found.query !== text) await update($, search, (f): SearchState => ({ ...f, query: text }))
  if (found.for === text.trim() && found.phase === 'idle') return openRow($)
  mod.openWhenFound = true
  requestSearch($)
}

/** Asks for the matches of what is typed after {@link SEARCH_DEBOUNCE_MS}, one search at a time. */
function requestSearch($: EngineInterface): void {
  if (mod.disabled) return
  mod.searchTimer?.cancel()
  mod.searchTimer = $.clock.after(SEARCH_DEBOUNCE_MS, () => {
    mod.searchTimer = null
    void (mod.searchFlight ??= new SingleFlight(() => loadSearch($))).request().catch(() => undefined)
  })
}

/** One search for what is typed now; stored with the query it answers, so a stale answer is never shown as current. */
async function loadSearch($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  const query = (await read($, search)).query.trim()
  if (query === '') {
    await update($, search, (f): SearchState => ({ ...f, for: '', phase: 'idle', answer: null }))
    return
  }
  await update($, search, (f): SearchState => ({ ...f, phase: 'searching' }))
  const parsed = parseGraphSearch(await wrapper($, 'graph-search', [`--query=${query}`], SEARCH_TIMEOUT_MS))
  if (parsed?.status === 'no-binary') return disable($)
  await update($, search, (f): SearchState => ({ ...f, for: query, phase: 'idle', answer: parsed }))
  // Enter was pressed before this answer landed: its first match opens now, if the field still says the same.
  if (mod.openWhenFound && (await read($, search)).query.trim() === query) {
    mod.openWhenFound = false
    if (parsed?.status === 'ok' && parsed.results.length > 0) await openRow($, 0)
  }
}

/** Copies the marked row's canonical name (a file's path) onto the clipboard of the surface the press came from. */
async function copySubject($: EngineInterface, surface?: RenderSurface): Promise<void> {
  const input = await currentInput($, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject === null) return
  // A cycle copies its chain, said by its name in the toast; anything else its full name.
  const text = subject.copy ?? subject.canonical
  const copied = await $.ui.copy({ text, ...(surface === undefined ? {} : { surface }) })
  await say($, copied.isCopied ? `✓ copied ${subject.name}` : '✗ the surface refused the copy', copied.isCopied ? 'ok' : 'alert')
}

/**
 * "Ask Claude" about the marked component (a cycle: how to break it; a
 * boundary: what its dependencies are for). One of the two prompts the mod
 * submits, both only from the person's press (the other is the no-data
 * pane's scan, {@link askScan}): the person asked for it, so it does not
 * start a turn on the mod's own account. One press, one prompt.
 */
async function askClaude($: EngineInterface): Promise<void> {
  const input = await currentInput($, true)
  const subject = input === null ? null : subjectOf(input)
  if (subject !== null) await $.prompt.submit({ text: subject.ask ?? askPrompt(subject.canonical) })
}

/**
 * The no-data pane's `q`: asks Claude to scan the project, as "Ask Claude"
 * does, only on the person's press, and only while knossos says the
 * project was never scanned. A press that lands after the pane moved on (a
 * load started, or one failed) sends nothing.
 */
async function askScan($: EngineInterface): Promise<void> {
  const d = await read($, dashboard)
  if (d?.status === 'unscanned' && noGraphOf(d, await read($, refresh)) === 'unscanned') await $.prompt.submit({ text: SCAN_PROMPT })
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

/** What a press on the pane does, then the couplings of the cell it leaves marked, when it marks one. */
async function pressPane($: EngineInterface, id: string, surface?: RenderSurface): Promise<void> {
  await pressAction($, id, surface)
  await requestCouplings($)
  await requestBranch($)
  await requestPeek($)
  await requestChurn($)
}

/**
 * Starts looking up the marked row for the detail beside the tab, while the
 * pane is drawn wide on a tab that has one: a component as its detail, a
 * file as the file's (with its change, when it is one of the session's).
 * After a short pause, so a run of moves is one lookup; never in a render.
 * A row with nothing to show (a boundary, a chart's bar) shows none.
 */
async function requestPeek($: EngineInterface): Promise<void> {
  if (mod.disabled || !mod.paneWide) return
  const v = await read($, view)
  if (v.inspect !== null || v.finding === true || v.drift === true || !PEEK_TABS.includes(v.tab)) return
  const list = await currentList($)
  const item = list[Math.min(Math.max(0, v.selected), Math.max(0, list.length - 1))]
  if (item === undefined || item.inert === true || item.jump !== undefined) {
    mod.peekNone = peekKey(v, (await read($, dashboard))?.snapshot_id ?? null)
    if ((await read($, peek)) !== null) await update($, peek, () => null)
    return
  }
  mod.peekNone = null
  const shown: Inspected = { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}), ...(item.changed === true ? { changed: true } : {}) }
  const snapshot = (await read($, dashboard))?.snapshot_id ?? null
  const current = await read($, peek)
  if (current !== null && current.shown.name === shown.name && (current.shown.file === true) === (shown.file === true) && current.detail.snapshot_id === snapshot) return
  await update($, peek, () => ({ shown, detail: { snapshot_id: snapshot, name: shown.name, ...(shown.file === true ? { file: true as const } : {}), detail: null, phase: 'loading' as const } }))
  mod.peekTimer?.cancel()
  mod.peekTimer = $.clock.after(PEEK_DEBOUNCE_MS, () => {
    mod.peekTimer = null
    void loadPeek($, shown, snapshot).catch(() => undefined)
  })
}

/** Which row of which tab and graph the detail beside the tab stands for: what tells a row already looked at. */
const peekKey = (v: KnossosView, snapshot: string | null): string => `${v.tab}\u0000${v.selected}\u0000${v.filter}\u0000${snapshot ?? ''}`

/** One lookup for the detail beside the tab; stored only while that row is still the one shown. */
async function loadPeek($: EngineInterface, shown: Inspected, snapshot: string | null): Promise<void> {
  if (mod.disabled) return
  await requestDiff($, shown)
  const file = shown.file === true
  const root = file ? ((await read($, dashboard))?.project_root ?? undefined) : undefined
  const stdout = await wrapper($, file ? 'file-detail' : 'component-detail', [shown.name], DETAIL_TIMEOUT_MS, root)
  const parsed = file ? parseFileDetail(stdout) : parseComponentDetail(stdout)
  if (parsed?.status === 'no-binary') return disable($)
  const answer = file ? { fileDetail: parsed as FileDetail | null } : { detail: parsed as ComponentDetail | null }
  await update($, peek, p => (p !== null && p.shown.name === shown.name && p.detail.snapshot_id === snapshot ? { shown: p.shown, detail: { ...p.detail, ...answer, phase: 'done' as const } } : p))
}

/** A press in the detail beside the tab (`peek:N`): opens what it lists as the detail itself. */
async function openPeeked($: EngineInterface, index: number): Promise<void> {
  const input = await currentInput($, true)
  const item = input?.peek === null || input?.peek === undefined ? undefined : peekList(input.peek)[index]
  if (item !== undefined) await showComponent($, { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}) })
}

/**
 * Starts comparing the branch with its merge base while the Branch tab is
 * open, unless that comparison is done or running for this graph: a new
 * snapshot compares again, the last answer kept on show meanwhile. On a
 * timer, never in a render.
 */
async function requestBranch($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  const v = await read($, view)
  if (v.tab !== 'branch' || v.inspect !== null) return
  const d = await read($, dashboard)
  if (d?.status !== 'ok') return
  const snapshot = d.snapshot_id ?? null
  const current = await read($, branch)
  if (current !== null && current.snapshot === snapshot) return
  await update($, branch, (b): BranchState => ({ snapshot, phase: 'loading', answer: b?.answer ?? null }))
  const root = d.project_root ?? undefined
  $.clock.after(0, () => void loadBranch($, snapshot, root).catch(() => undefined))
}

/** One comparison; stored only while it is still for the graph on show. */
async function loadBranch($: EngineInterface, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseBranchDiff(await wrapper($, 'branch-diff', [], BRANCH_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable($)
  await update($, branch, (b): BranchState | null => (b !== null && b.snapshot === snapshot ? { snapshot, phase: 'done', answer: parsed } : b))
}

/**
 * Starts reading the churn hotspots while the Churn or the Branch tab is open, unless
 * they were read (or are being read) for the commit the checkout is at:
 * the history is kept per commit, so a new commit, not a new scan, reads
 * it again. The last answer stays on show meanwhile. On a timer, never in
 * a render.
 */
async function requestChurn($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  const v = await read($, view)
  // The Branch tab shows the hotspots too, where it has room.
  if ((v.tab !== 'churn' && v.tab !== 'branch') || v.inspect !== null || (v.route ?? null) !== null) return
  const d = await read($, dashboard)
  if (d?.status !== 'ok') return
  const head = (await read($, gitHead))?.rev ?? null
  const current = await read($, churn)
  if (current !== null && current.head === head) return
  await update($, churn, (c): ChurnState => ({ head, phase: 'loading', answer: c?.answer ?? null }))
  const root = d.project_root ?? undefined
  $.clock.after(0, () => void loadChurn($, head, root).catch(() => undefined))
}

/** One read of the churn hotspots; stored only while it is still for the commit on show. */
async function loadChurn($: EngineInterface, head: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseChurn(await wrapper($, 'churn', [], CHURN_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable($)
  await update($, churn, (c): ChurnState | null => (c !== null && c.head === head ? { head, phase: 'done', answer: parsed } : c))
}

/**
 * Starts reading a component's blast radius for its detail, unless it was
 * read (or is being read) for this component and graph; a file's detail
 * has none. A new snapshot reads it again, the last rings on show
 * meanwhile. On a timer, never in a render.
 */
async function requestRings($: EngineInterface, shown: Inspected): Promise<void> {
  if (mod.disabled || shown.file === true) return
  const d = await read($, dashboard)
  const snapshot = d?.snapshot_id ?? null
  const current = await read($, rings)
  if (current !== null && current.name === shown.name && current.snapshot === snapshot) return
  await update($, rings, (r): RingsState => ({ name: shown.name, snapshot, phase: 'loading', answer: r !== null && r.name === shown.name ? r.answer : null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  $.clock.after(0, () => void loadRings($, shown.name, snapshot, root).catch(() => undefined))
}

/** One read of a blast radius; stored only while the detail still asks for that component and graph. */
async function loadRings($: EngineInterface, name: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseBlastRadius(await wrapper($, 'blast-radius', [`--component=${name}`], RINGS_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable($)
  await update($, rings, (r): RingsState | null => (r !== null && r.name === name && r.snapshot === snapshot ? { ...r, phase: 'done', answer: parsed } : r))
}

/**
 * Starts looking for the route the path explorer shows, unless it was
 * looked for (or is being) between these two components in this graph. On
 * a timer, never in a render.
 */
async function requestRoute($: EngineInterface): Promise<void> {
  if (mod.disabled) return
  const shown = (await read($, view)).route ?? null
  if (shown === null) return
  const d = await read($, dashboard)
  const snapshot = d?.snapshot_id ?? null
  const [from, to] = [shown.from.name, shown.to.name]
  const current = await read($, route)
  if (current !== null && current.from === from && current.to === to && current.snapshot === snapshot) return
  await update($, route, (r): RouteState => ({ from, to, snapshot, phase: 'loading', answer: r !== null && r.from === from && r.to === to ? r.answer : null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  $.clock.after(0, () => void loadRoute($, from, to, snapshot, root).catch(() => undefined))
}

/** One route search; stored only while the explorer still shows these two ends in this graph. */
async function loadRoute($: EngineInterface, from: string, to: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parsePathBetween(await wrapper($, 'path-between', [`--from=${from}`, `--to=${to}`], ROUTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable($)
  await update($, route, (r): RouteState | null => (r !== null && r.from === from && r.to === to && r.snapshot === snapshot ? { ...r, phase: 'done', answer: parsed } : r))
}

/**
 * `p` on a component's detail: the finder opens to pick where a route from
 * it ends; the component it opens on is the other end. Only components are
 * offered.
 */
async function startRoute($: EngineInterface): Promise<void> {
  const v = await read($, view)
  if (v.inspect === null || v.inspect.file === true || (v.route ?? null) !== null) return
  const from = v.inspect
  await update($, view, (w): KnossosView => ({ ...w, picking: from }))
  await openFinder($)
}

/** The route from the component picked with `p` to `to`, drawn instead of the detail; `b` goes back to it. */
async function showRoute($: EngineInterface, to: Inspected): Promise<void> {
  const v = await read($, view)
  const from = v.picking ?? null
  if (from === null) return
  mod.openWhenFound = false
  await update($, view, (w): KnossosView => ({ ...w, finding: false, picking: null, selected: 0, route: { from, to, index: 0, back: w.inspect } }))
  await requestRoute($)
}

/**
 * `m` on a component's detail: a field opens in its notes card, holding the
 * component's note when it has one, and takes the focus once it is drawn.
 * Nothing is written by this, nor by typing.
 */
async function startNote($: EngineInterface): Promise<void> {
  const v = await read($, view)
  if (v.inspect === null || v.inspect.file === true) return
  const stored = await read($, detail)
  const component = stored?.detail?.component?.name ?? v.inspect.name
  const existing = stored?.detail?.component?.annotations?.find(a => a.kind === 'note')?.value ?? ''
  await update($, note, (): NoteState => ({ component, phase: 'editing', value: existing, previous: null, reason: null }))
  $.clock.after(0, () => void $.ui.focus({ requestId: PANE, key: 'note' }).catch(() => undefined))
}

/** What was typed into the note's field, kept as it is typed. */
async function typeNote($: EngineInterface, text: string): Promise<void> {
  await update($, note, (n): NoteState | null => (n !== null && n.phase === 'editing' ? { ...n, value: text } : n))
}

/**
 * Enter in the note's field: knossos checks the note (a preview, nothing
 * written) and the card asks before recording it. An empty field drops it.
 */
async function submitNote($: EngineInterface, text: string): Promise<void> {
  const asked = await read($, note)
  if (asked === null || asked.phase !== 'editing') return
  const value = text.trim()
  if (value === '') {
    await update($, note, () => null)
    return
  }
  await update($, note, (): NoteState => ({ ...asked, phase: 'previewing', value }))
  $.clock.after(0, () => void previewNote($, asked.component, value).catch(() => undefined))
}

/** The preview of a note: what it would replace, or why knossos refuses it. Writes nothing. */
async function previewNote($: EngineInterface, component: string, value: string): Promise<void> {
  const root = (await read($, dashboard))?.project_root ?? undefined
  const parsed = parseAnnotate(await wrapper($, 'annotate', [`--component=${component}`, `--value=${value}`], NOTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable($)
  await update($, note, (n): NoteState | null => {
    if (n === null || n.component !== component || n.value !== value || n.phase !== 'previewing') return n
    if (parsed?.status === 'ok' && parsed.executed === false) return { ...n, phase: 'confirming', previous: parsed.previous ?? null }
    return { ...n, phase: 'failed', reason: parsed?.status === 'refused' ? (parsed.reason ?? 'it refused') : 'it said nothing' }
  })
}

/**
 * `y` on the card's question: records the note, then reads the detail
 * again so it shows it. Only from the question: a press at any other moment
 * writes nothing.
 */
async function confirmNote($: EngineInterface): Promise<void> {
  const asked = await read($, note)
  if (asked === null || asked.phase !== 'confirming') return
  await update($, note, (): NoteState => ({ ...asked, phase: 'saving' }))
  $.clock.after(0, () => void recordNote($, asked.component, asked.value).catch(() => undefined))
}

/** Records a confirmed note; the detail is read again once it is. */
async function recordNote($: EngineInterface, component: string, value: string): Promise<void> {
  const root = (await read($, dashboard))?.project_root ?? undefined
  const parsed = parseAnnotate(await wrapper($, 'annotate', [`--component=${component}`, `--value=${value}`, '--execute'], NOTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable($)
  if (parsed?.status !== 'ok' || parsed.executed !== true) {
    await update($, note, (n): NoteState | null => (n !== null && n.component === component ? { ...n, phase: 'failed', reason: parsed?.status === 'refused' ? (parsed.reason ?? 'it refused') : 'it said nothing' } : n))
    return
  }
  await update($, note, () => null)
  await say($, `✓ noted on ${component.slice(component.lastIndexOf('\\') + 1)}`, 'ok')
  const shown = (await read($, view)).inspect
  if (shown === null) return
  // The stored detail predates the note: read it again.
  await update($, detail, () => null)
  await requestDetail($, shown)
}

/** `l`, or a press on one of what the marked boundary depends on: the heat map cell moves to that column. */
async function moveCell($: EngineInterface, to: number | null): Promise<void> {
  const input = await currentInput($, true)
  const boundaries = input?.boundaries ?? null
  if (input === null || boundaries === null) return
  const marked = Math.min(Math.max(0, input.selected), boundaries.boundaries.length - 1)
  const target = to === null ? nextTarget(boundaries, marked, input.target) : (boundaries.boundaries[to]?.name ?? null)
  if (target !== null) await update($, view, v => ({ ...v, target }))
}

/** `o` on a cycle's fold (`unfold:<cycle>:<row>`), or a press on its box (`fold:…`): the cycle shows every member, the marker on the first it hid. */
async function unfoldCycle($: EngineInterface, id: string): Promise<void> {
  const [cycle, row] = id.slice(id.indexOf(':') + 1).split(':').map(Number)
  if (!Number.isInteger(cycle) || !Number.isInteger(row)) return
  await update($, view, v => ({ ...v, unfolded: [...new Set([...(v.unfolded ?? []), cycle!])], selected: row! }))
}

/** What a press on the pane does, by the pressed element's id; `surface` is where the press came from. */
async function pressAction($: EngineInterface, id: string, surface?: RenderSurface): Promise<unknown> {
  if (id.startsWith('tab:') || id.startsWith('tabkey:')) {
    const tab = id.slice(id.indexOf(':') + 1) as PaneTab
    if (TABS.some(t => t.id === tab)) await update($, view, v => ({ ...v, tab, selected: 0, filtering: false, finding: false, drift: false, target: undefined, degree: null }))
    return
  }
  if (id === 'find') return openFinder($)
  if (id === 'find-close') return closeFinder($)
  if (id === 'target') return moveCell($, null)
  if (id.startsWith('cell:')) return moveCell($, Number(id.slice(5)))
  if (id === 'drift' || id === 'drifted') return update($, view, v => ({ ...v, drift: v.drift !== true, selected: 0 }))
  if (id.startsWith('row:')) {
    // A boundary has nothing to open: pressing one marks it, starting on what it depends on most.
    if ((await read($, view)).tab === 'boundaries') await update($, view, v => ({ ...v, target: undefined }))
    return openRow($, Number(id.slice(4)))
  }
  if (id.startsWith('rel:')) return openRelated($, Number(id.slice(4)))
  if (id === 'route') return startRoute($)
  if (id.startsWith('route-pick:')) return update($, view, (v): KnossosView => (v.route === null || v.route === undefined ? v : { ...v, route: { ...v.route, index: Math.max(0, Number(id.slice(11)) || 0) }, selected: 0 }))
  if (id === 'note') return startNote($)
  if (id === 'note-yes') return confirmNote($)
  if (id === 'note-no') return update($, note, () => null)
  if (id.startsWith('peek:')) return openPeeked($, Number(id.slice(5)))
  // On Cycles the layout names the row `j` and `k` (and a cycle's line in the list) move to: what the diagram shows depends on its width.
  if (/^(next|prev|mark):/.test(id)) return update($, view, v => ({ ...v, selected: Math.max(0, Number(id.slice(5)) || 0) }))
  if (id.startsWith('unfold:') || id.startsWith('fold:')) return unfoldCycle($, id)
  if (id === 'down' || id === 'up') return moveSelection($, id === 'down' ? 1 : -1)
  if (id === 'open') return openRow($)
  if (id === 'edit') return openEditTarget($, surface)
  if (id === 'tests') return copyTestCommand($, surface)
  // Back from a route goes to the detail it was picked from; from a detail, to the tab.
  if (id === 'back') return update($, view, (v): KnossosView => ((v.route ?? null) !== null ? { ...v, route: null, selected: 0 } : { ...v, inspect: null, selected: v.opened ?? 0 }))
  if (id === 'keys') return update($, view, v => ({ ...v, showKeys: !v.showKeys }))
  if (id === 'filter') return openFilter($)
  if (id === 'clear') return update($, view, v => ({ ...v, filter: '', filtering: false, degree: null, selected: 0 }))
  if (id === 'sort') return update($, view, v => ({ ...v, sort: SORTS[(SORTS.indexOf(v.sort) + 1) % SORTS.length] ?? 'in', selected: 0 }))
  if (id === 'rescan') return requestRescan($)
  if (id === 'copy') return copySubject($, surface)
  if (id === 'ask') return askClaude($)
  // The no-data pane's one action: the person's press sends it, as "Ask Claude" does.
  if (id === 'scan-ask') return askScan($)
  if (id === 'allow') return offerAllow($)
  if (id === 'allow-yes') return confirmAllow($)
  if (id === 'allow-no') return update($, allow, (a): AllowState => (a.phase === 'confirming' ? { phase: 'idle', root: null, reason: null } : a))
}

/** A hover group's name for one card: the plugin's prefix and the card's key, within the engine's 64 characters. */
const scopeOf = (preview: Preview): string => `knossos:${preview.key}`.slice(0, 64)

/**
 * One segment as an element: a pressable segment is a plain Button, a
 * segment with a place a Markdown link to its `file:` URL (where `links`),
 * a field an Input, any other a Text. A Button or a link with a background
 * (the open tab, the marked row) stands in a Box of that colour, which they
 * cannot take themselves. `scope` joins the segment to its row's hover card.
 *
 * Nothing a segment draws can wrap or push its row wider: every Text cuts
 * at its edge (`truncate-end`) inside a row exactly as wide as the pane; a
 * Button draws the segment's own text as its label ({@link pressLabel}), not
 * the whole name its press may carry, so it takes exactly the cells the
 * layout gave it; one on a tint stands in a Box of that tint and width, and
 * a field's Box takes the rest of its row (`room`). Off the terminal a
 * Button, a link and a field are the surface's own, whose width the pane
 * cannot know in cells: the row's width and overflow clip them at its edge.
 *
 * Every tree the engine takes is bounded (20,000 nodes, 32 deep, 100,000
 * characters serialized): a Text carries no key and no Box of its own, and a
 * Box is keyed only where a key is needed (a tint, a hidden twin), so a tall
 * pane stays well within them.
 */
function drawSegment($: EngineInterface, ui: Elements[RenderSurface], row: Row, s: Segment, i: number, press: (id: string, surface?: RenderSurface) => void, links: boolean, terminal: boolean, room: number, scope?: string) {
  const { Box, Button, Markdown, Text } = ui
  // Every surface but mobile has a text field; there the filter is shown as text.
  const Input = 'Input' in ui ? ui.Input : undefined
  const hover = scope === undefined ? {} : { hover: { scope } }
  const native = s.press !== undefined || s.field !== undefined || (s.link !== undefined && links)
  const width = s.field !== undefined && Input !== undefined ? Math.max(1, room) : cells(s.text)
  const sized = !native || terminal ? { width } : {}
  // The marked row's Button or link keeps its `-bg` Box: that Box is what carries the tint.
  const framed = (element: RenderNode, key?: string, bg?: string) => (
    <Box {...(key === undefined ? {} : { key })} {...sized} flexShrink={0} {...(bg === undefined ? {} : { backgroundColor: bg })}>
      {element}
    </Box>
  )
  // A Button or link draws exactly its segment's cells: only a tint needs a Box under it.
  const ground = (key: string, element: RenderNode) => (s.bg === undefined ? element : framed(element, `${key}-bg`, s.bg))
  if (s.field && Input !== undefined) {
    return framed(
      <Input
        key={s.field.id}
        value={s.field.value}
        placeholder={s.field.placeholder}
        submitLabel="keep"
        autoFocus
        onInput={(value: string) => void (s.field!.id === 'find' ? typeQuery($, value) : s.field!.id === 'note' ? typeNote($, value) : typeFilter($, value)).catch(() => undefined)}
        onSubmit={(value: string) => void (s.field!.id === 'find' ? submitQuery($, value) : s.field!.id === 'note' ? submitNote($, value) : submitFilter($, value)).catch(() => undefined)}
      />,
    )
  }
  if (s.press && s.hidden) {
    // Out of sight, there only for its hotkey (a tab drawn as its digit alone).
    return (
      <Box key={s.press.id} display="none">
        <Button key={s.press.id} plain label={s.press.label} {...(s.press.hotkey === undefined ? {} : { hotkey: s.press.hotkey })} onPress={pressed => press(s.press!.id, pressed.surface)} />
      </Box>
    )
  }
  if (s.press) {
    return ground(
      s.press.id,
      <Button
        key={s.press.id}
        plain
        label={pressLabel(s)}
        {...(s.press.hotkey === undefined ? {} : { hotkey: s.press.hotkey })}
        {...(s.dim ? { dimColor: true } : {})}
        {...hover}
        onPress={pressed => press(s.press!.id, pressed.surface)}
      />,
    )
  }
  if (s.link && links) {
    return ground(
      `${row.key}-link-${i}`,
      <Markdown
        key={`${row.key}-link-${i}`}
        text={linkMarkdown(s.text, s.link)}
        {...(s.dim ? { dimColor: true } : {})}
        onLinkPress={(link, pressed) => void openLink($, link.href, pressed.surface).catch(() => undefined)}
      />,
    )
  }
  return (
    <Text wrap="truncate-end" {...textStyle(s)} {...hover}>
      {s.text}
    </Text>
  )
}

/**
 * One laid-out row as elements (see {@link drawSegment}): a Box exactly
 * `columns` wide that clips what passes its edge, so a row can neither wrap
 * nor widen the pane. A row that hangs a hover card joins only the name the
 * card is about to the card's group: the pointer on that name shows it.
 *
 * A link is the surface's own: ctrl- or cmd-click opens it as a link in a
 * reply would, and a plain click (`onLinkPress`) opens it in the editor.
 */
function drawRow($: EngineInterface, ui: Elements[RenderSurface], row: Row, press: (id: string, surface?: RenderSurface) => void, columns: number, terminal: boolean, links = false, hovers = false) {
  const { Box, Code } = ui
  // A change's hunks: the engine's own diff, gutters, markers and colours as Claude Code draws them.
  if (row.code !== undefined) {
    return (
      <Box key={row.key} flexDirection="column" width={columns} overflow="hidden">
        <Code key={`${row.key}-code`} source={row.code.source} path={row.code.path} format="diff" wrap="truncate-end" />
      </Box>
    )
  }
  const at = hovers ? row.segments.findIndex(s => s.preview !== undefined) : -1
  const preview = at < 0 ? undefined : row.segments[at]!.preview!
  // The name the card is about joins its group: resting the pointer on it shows the card.
  const scoped = (i: number) => (preview !== undefined && i === at ? scopeOf(preview) : undefined)
  const { Text } = ui
  // A run of plain text (no press, field or link, no hover group) is one Text that cuts at the row's edge,
  // its styled pieces nested in it and the rest bare strings: a tall pane stays far inside the engine's bounds.
  const plain = (s: Segment): boolean => s.press === undefined && s.field === undefined && !(s.link !== undefined && links)
  const drawn: RenderNode[] = []
  let x = 0
  for (let i = 0; i < row.segments.length; i++) {
    const s = row.segments[i]!
    if (s.field !== undefined) {
      // A field takes the blank cells laid out after it, and stops where the row's next drawn segment (a card's frame) begins.
      let next = i + 1
      while (next < row.segments.length && plain(row.segments[next]!) && row.segments[next]!.bg === undefined && /^ *$/.test(row.segments[next]!.text)) next++
      const trailing = row.segments.slice(next).reduce((n, t) => n + (t.hidden === true ? 0 : cells(t.text)), 0)
      const room = Math.max(1, columns - x - trailing)
      drawn.push(drawSegment($, ui, row, s, i, press, links, terminal, room, scoped(i)))
      x += room
      i = next - 1
      continue
    }
    if (!plain(s)) {
      drawn.push(drawSegment($, ui, row, s, i, press, links, terminal, columns - x, scoped(i)))
      x += s.hidden === true ? 0 : cells(s.text)
      continue
    }
    const run: Segment[] = [s]
    // One hover group per run: the outer Text joins it (a nested Text follows its group but cannot light it).
    const scope = scoped(i)
    while (i + 1 < row.segments.length && plain(row.segments[i + 1]!) && scoped(i + 1) === scope) run.push(row.segments[++i]!)
    x += run.reduce((n, r) => n + cells(r.text), 0)
    drawn.push(textRun(Text, run, scope))
  }
  return (
    <Box key={row.key} flexDirection="row" width={columns} overflow="hidden">
      {drawn}
    </Box>
  )
}

/**
 * A run of plain segments as one Text that cuts at its edge: a lone segment
 * styled itself, else its styled pieces nested in it and the rest bare
 * strings. `scope` joins it to a hover group.
 */
function textRun(Text: Elements[RenderSurface]['Text'], run: Segment[], scope?: string): RenderNode {
  const hover = scope === undefined ? {} : { hover: { scope } }
  const pieces = mergedRun(run)
  if (pieces.length === 1) {
    return (
      <Text wrap="truncate-end" {...textStyle(pieces[0]!)} {...hover}>
        {pieces[0]!.text}
      </Text>
    )
  }
  return (
    <Text wrap="truncate-end" {...hover}>
      {pieces.map(r => (Object.keys(textStyle(r)).length === 0 ? r.text : <Text {...textStyle(r)}>{r.text}</Text>))}
    </Text>
  )
}

/** A run of segments with neighbours of one style joined, so each style change is one piece; blank cells take any style. */
function mergedRun(run: Segment[]): Segment[] {
  const out: Segment[] = []
  // Blank cells without a ground look the same in any colour: they carry no style, and join their neighbours.
  for (const piece of run) {
    const r = piece.bg === undefined && /^ *$/.test(piece.text) ? { text: piece.text } : piece
    const last = out[out.length - 1]
    const same = last !== undefined && JSON.stringify(textStyle(last)) === JSON.stringify(textStyle(r))
    if (same) out[out.length - 1] = { ...last, text: last.text + r.text }
    else out.push(r)
  }
  return out
}

/**
 * How much of the engine's tree bounds the pane lets itself use: its tree
 * serialized stays under this many characters (the engine's limit is
 * 100,000) and so, with room to spare, under its 20,000 nodes.
 */
const TREE_BUDGET = 70_000
/** The fewest rows the pane lays its lists out for when it gives rows back to fit its budget. */
const SHORT_ROWS = 16

/** A drawn tree's size as the engine bounds it: its length serialized (handlers are not data). */
const treeWeight = (tree: RenderElement): number => JSON.stringify(tree)?.length ?? 0

/** The most hover cards a pane hangs: those on the rows nearest the marked one. */
const CARDS_MAX = 8

/** A hover card where the pointer can reach it: under its row, or over it when the rows below cannot hold it; never past the right edge. */
function cardPlace(row: number, rows: number, x: number, preview: Preview, columns: number): { top: number; left: number } {
  const height = preview.rows.length
  const below = row + 1 + height <= rows || row < height
  return { top: below ? row + 1 : row - height, left: Math.max(0, Math.min(x, columns - preview.width)) }
}

/**
 * The hover cards the rows hang, drawn out of the flow over the rows below
 * their own (`position: absolute`), hidden until the pointer rests on a
 * segment of their group. Nothing crosses to the mod when one shows: the
 * surface reveals it alone. Only where the surface has a pointer. A card is
 * one Text of its lines on the card's ground, as wide as the card and cut at
 * its edge: two nodes, however many rows hang one, so a tall list keeps the
 * tree far inside the engine's bounds.
 */
function drawCards(ui: Elements[RenderSurface], rows: Row[], columns: number) {
  const { Box, Text } = ui
  const cards = []
  // At most CARDS_MAX cards, on the rows nearest the marked one: a tall list cannot grow the tree past its bounds.
  const marked = Math.max(0, rows.findIndex(r => r.tint === SELECTED_BG))
  const hung = rows.map((r, y) => ({ y, has: r.segments.some(s => s.preview !== undefined) })).filter(r => r.has).sort((a, b) => Math.abs(a.y - marked) - Math.abs(b.y - marked) || a.y - b.y).slice(0, CARDS_MAX).map(r => r.y)
  for (const y of hung.sort((a, b) => a - b)) {
    const row = rows[y]!
    let x = 0
    for (const s of row.segments) {
      const preview = s.preview
      if (preview !== undefined) {
        const place = cardPlace(y, rows.length, x, preview, columns)
        const width = Math.min(preview.width, columns)
        cards.push(
          <Box key={preview.key} position="absolute" top={place.top} left={place.left} width={width} height={preview.rows.length} display="none" backgroundColor={CARD_BG} hover={{ scope: scopeOf(preview), display: 'flex' }}>
            <Text wrap="truncate-end">{preview.rows.map(line => line.segments.map(c => c.text).join('')).join('\n')}</Text>
          </Box>,
        )
        break
      }
      x += [...s.text].length
    }
  }
  return cards
}

/**
 * The laid-out rows as elements. On the terminal, consecutive rows that share
 * a `raster` key (the heat map) are one `Raster` of coloured cells; every
 * other surface draws them as text, glyphs and colours alike. Where the
 * surface has a pointer (all but mobile), the hover cards follow the rows.
 */
function drawRows($: EngineInterface, ui: Elements[RenderSurface], surface: RenderSurface, themeName: string, rows: Row[], columns: number, press: (id: string, surface?: RenderSurface) => void, lean = false) {
  const terminal = surface === 'terminal'
  const links = surface !== 'mobile'
  // Lean (a tree near the engine's bounds): no hover cards, and only the marked row's places are links.
  const hovers = surface !== 'mobile' && !lean
  const drawn = []
  for (let i = 0; i < rows.length; i++) {
    const block = rows[i]!.raster
    if (!terminal || block === undefined) {
      drawn.push(drawRow($, ui, rows[i]!, press, columns, terminal, links && (!lean || rows[i]!.tint === SELECTED_BG), hovers))
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
  return hovers ? [...drawn, ...drawCards(ui, rows, columns)] : drawn
}

export const register: Register = (on, options) => {
  if (options.enabled === false) return
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
  mod.peekTimer?.cancel()
  mod.peekTimer = null
  mod.contextTool = null
  mod.toolFailureLogged = false
  mod.commitNoted = new Set()
  mod.alertsOn = options.notifications !== false
  mod.toasted = new Set()
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

  // The session ends (exit, /clear, resume): its watcher ends with it. After a /clear or an
  // in-process resume the process goes on (no session.start fires), so the next tick starts a
  // watcher again; after an exit, a logout or a signal, none.
  on('session.end', async ($, e, next) => {
    await endWatcher($).catch(() => undefined)
    if (FINAL_ENDS.includes(e.reason)) {
      mod.watchRetryAt = Number.POSITIVE_INFINITY
    } else {
      mod.watchFailures = 0
      mod.watchRetryAt = 0
      // A new session in the same process: its changes start now, at the graph as it stands, and its cycles too.
      await update($, sessionStart, () => mod.snapshot)
      const now = await read($, dashboard)
      await update($, startCycles, () => (now?.status === 'ok' ? cyclesOf(now) : null))
      mod.commitNoted = new Set()
      mod.toasted = new Set()
      // What the notes told the session that ended: the next one's model never read it, so it is news again.
      mod.noted = new Set()
      mod.ruled = new Set()
      mod.testsNamed = new Set()
      mod.violationsSeen = new Set()
      mod.truncationSaid = false
      await update($, sessionBegan, () => null)
      await update($, sessionEdits, () => [])
      await update($, sessionScans, () => [])
      // Its diffs are taken against the commit it begins at.
      await update($, sessionRev, () => null)
      await update($, fileDiff, () => null)
      // The session that ended keeps its baseline; the one that follows reads its own back (a resume) or records one.
      mod.endedSession = e.sessionId
      mod.baselineOf = null
      mod.headAsked = false
      $.clock.after(0, () => void settleBaseline($).catch(() => undefined))
      await update($, sessionLedger, () => null)
      await update($, changes, () => NO_CHANGES)
    }
    return next(e)
  })

  // Every call of the session's, in every loop, by any tool that can write rather than wait or read: while one runs
  // (and a moment after), what the watcher scans is the session's own, whatever route the change took.
  on('tool.call', async ($, e, next) => {
    // The model's own tool, one file's context in one call, by the name its registration returned: answered
    // here, and no other hook answers it. The engine allows one hook on every call, so it shares this one.
    if (e.tool === (mod.contextTool ?? CONTEXT_TOOL_NAME)) return { result: await answerContext($, (e as { path?: unknown }).path).catch(() => 'knossos_context: the answer failed; try again.') }
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
    // Before the edit lands: the snapshot the turn's brief diffs against, whoever scans the edit first.
    // It becomes the turn's start only once the edit is known to have landed inside the project.
    const base = mod.snapshot
    const ran = await next(e)
    if (!mod.disabled) mod.lastEditAt = await $.clock.now()
    if (mod.disabled || ran.deny !== undefined || ran.isError === true) return ran
    // Tests run before this edit ran the old code.
    mod.ranCommands = []
    return noteSafely($, ran, async () => {
      const path = editedPath(e)
      const note = path === null ? null : await noteEdit($, path, base)
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
    // Where the project's HEAD stood before the command: only a command that moved it can have committed there.
    const repo = mod.disabled || !mod.notesOn ? null : await projectRoot($)
    const before = repo === null ? null : await headAt($, repo)
    const ran = await next(e)
    const command = (e as { command?: unknown }).command
    if (ran.deny === undefined && typeof command === 'string' && mod.ranCommands.length < COMMANDS_KEPT) mod.ranCommands.push(command)
    if (BASH_MARKS_DIRTY) mod.dirty = true
    // A commit, in any loop: what it carries that the graph knows of, said once.
    if (mod.disabled || ran.deny !== undefined || repo === null || before === null) return ran
    return noteSafely($, ran, async () => {
      const after = await headAt($, repo)
      const reflog = after === null || after === before ? '' : await reflogAt($, repo)
      if (!committedSince(before, after, reflog, ran.text ?? '')) return ran
      const note = await commitNoteFor($, e.agentId ?? '')
      return note === null ? ran : { ...ran, context: [...(ran.context ?? []), note] }
    })
  })

  on('turn.complete', async ($, e, next) => {
    const result = await next(e)
    mod.turnNotes.delete(e.agentId ?? '')
    // A subagent's turn ends inside the main one; its edits and commands ride the main turn's scan.
    if (e.agentId !== undefined) return result
    mod.turnRan = mod.ranCommands
    mod.ranCommands = []
    // Still refused once the timed retries ran out: each turn's end asks again, a session being bound by now.
    if (!mod.disabled && !(mod.commandRegistered && mod.contextTool !== null) && mod.registerTimer === null) await registerCommand($)
    // The turn may have committed or switched branches: the header reads where the checkout stands again.
    if (!mod.disabled) $.clock.after(0, () => void readGitHead($).catch(() => undefined))
    // No graph to draw yet (the person may just have asked Claude to scan): look again, once the turn is over.
    if (!mod.disabled && (await read($, dashboard))?.status !== 'ok') $.clock.after(0, () => void refreshDashboard($).catch(() => undefined))
    // Nothing to brief, now or held for later: no turn start may carry over to a later turn.
    if (!mod.dirty && mod.edited.size === 0) mod.turnBase = null
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
      <Box key="band" width={e.props.bodyColumns} overflow="hidden">
        <Box key="band-text" width={cells(fit(model.text, room)) + 1} flexShrink={0} overflow="hidden">
          <Text color={color} wrap="truncate-end">
            {fit(model.text, room)}{' '}
          </Text>
        </Box>
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
    if (moved.deny === undefined && Number.isInteger(index)) {
      await update($, view, v => (v.selected === index ? v : { ...v, selected: index, target: undefined }))
      await requestCouplings($)
      await requestPeek($)
    }
    return moved
  })

  on('ui.render', { component: 'Pane', requestId: PANE }, async ($, e) => {
    const ui = $.ui.resolve(e)
    const { Box } = ui
    mod.paneText = null
    mod.emptyShown = false
    if (mod.disabled) return <Box key="off" />
    const d = await read($, dashboard)
    const v = await read($, view)
    const columns = Math.max(1, e.props.bodyColumns)
    // Whether the pane is wide enough for the detail beside the tab: the lookup it needs runs from the next press or tick.
    mod.paneWide = tierOf(columns) === 'wide'
    const press = (id: string, surface?: RenderSurface) => void pressPane($, id, surface).catch(() => undefined)
    if (d === null || d.status !== 'ok') {
      const offer = allowInput(await read($, brief), await read($, rescan), await read($, allow))
      mod.emptyShown = true
      return (
        <Box key="empty" flexDirection="column">
          {emptyRows(noGraphOf(d, await read($, refresh)), offer, columns).map(row => drawRow($, ui, row, press, columns, e.surface === 'terminal'))}
        </Box>
      )
    }
    const input = await currentInput($, e.surface === 'terminal')
    if (input === null) return <Box key="empty" />
    mod.paneText = input.status.text
    // No loop variable may be called `h`: JSX compiles to h(...), and a
    // parameter of that name shadows the element factory inside its callback.
    // A press that outlives the session (a teardown under it) fails quietly.
    const height = paneHeight(e.props.scroll)
    const offset = Math.max(0, e.props.scroll?.offset ?? 0)
    const themeName = await read($, theme)
    const key = v.inspect === null ? 'pane' : 'detail'
    // The engine refuses a tree past its bounds (100,000 characters serialized, 20,000 nodes) and draws its own:
    // past TREE_BUDGET the pane drops its hover cards and most links, then gives its lists fewer rows, until it fits.
    // How many rows the last layout drew with something in them (the blank fill under a short tab left out).
    let used = height
    const draw = (rows: number, lean: boolean): RenderElement => {
      const laidOut = paneLayout(input, columns, rows, offset)
      used = laidOut.body.filter(r => !r.key.startsWith('fill-')).length + laidOut.footer.length
      if (!laidOut.pinned) {
        return (
          <Box key={key} flexDirection="column">
            {drawRows($, ui, e.surface, themeName, [...laidOut.body, ...laidOut.footer], columns, press, lean)}
          </Box>
        )
      }
      // Taller than the window: the bar is drawn over its last rows wherever it is scrolled to, and the body ends in room for it.
      const reserve = laidOut.footer.map((_, i) => ({ key: `bar-room-${i}`, segments: [{ text: ' ' }] }))
      const top = Math.min(offset, laidOut.body.length + reserve.length - height) + height - laidOut.footer.length
      return (
        <Box key={key} flexDirection="column">
          {drawRows($, ui, e.surface, themeName, [...laidOut.body, ...reserve], columns, press, lean)}
          <Box key="bar" position="absolute" top={Math.max(0, top)} left={0} width={columns} flexDirection="column">
            {laidOut.footer.map(row => drawRow($, ui, row, press, columns, e.surface === 'terminal'))}
          </Box>
        </Box>
      )
    }
    let tree = draw(height, false)
    if (treeWeight(tree) <= TREE_BUDGET) return tree
    tree = draw(height, true)
    // Lists make most of the weight: fewer rows each try, in proportion to the budget, down to SHORT_ROWS at the last.
    const lean = { tree, weight: treeWeight(tree) }
    const fitted = shrinkRows(Math.min(height, used), SHORT_ROWS, TREE_BUDGET, lean, rows => {
      const drawn = draw(rows, true)
      return { tree: drawn, weight: treeWeight(drawn) }
    })
    if (fitted.weight <= TREE_BUDGET) return fitted.tree
    const { weight, rows } = fitted
    // Still past the budget at the fewest rows: one line says so, rather than a tree the engine would refuse.
    if (!mod.tooLargeLogged) {
      mod.tooLargeLogged = true
      $.ui.log(`knossos: the pane was too large to draw (${weight} characters at ${rows} rows), so it drew one line instead`, { to: 'debug' })
    }
    return (
      <Box key={key} flexDirection="column">
        {drawRows($, ui, e.surface, themeName, [dimRow('too-large', ' Too large to draw here: close the detail, or make the pane narrower.', columns)], columns, press, true)}
      </Box>
    )
  })
}
