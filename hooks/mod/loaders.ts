import type { BranchState, ChurnState, ComponentDetail, CouplingState, Dashboard, DetailState, DiffState, FileDetail, Inspected, KnossosView, RefreshState, RingsState, RouteState } from '../../types'
import { alertKeys, freshAlerts } from '../lib/alerts'
import { BASELINES_KEY, baselinesOf, remember } from '../lib/baseline'
import type { Baseline } from '../lib/baseline'
import { parseBlastRadius, parseBranchDiff, parseChurn, parseComponentDetail, parseCouplings, parseDashboard, parseFileDetail, parsePathBetween, parseSessionDiff, parseSessionHead, parseSessionRev } from '../lib/envelopes'
import { flashKeys } from '../lib/flash'
import { couplingPair, PEEK_TABS } from '../lib/layout'
import { SingleFlight } from '../lib/scheduler'
import { disable, light, wrapper } from './port'
import type { Cell, Port } from './port'
import { currentInput, currentList } from './render'
import { cyclesOf, mod } from './state'

/** The wrapper's own limits (30 s and 15 s) plus room for it to exit on its own. */
const DASHBOARD_TIMEOUT_MS = 35_000
const DETAIL_TIMEOUT_MS = 20_000
/** The wrapper bounds session-head and session-diff at 15 s. */
const HEAD_TIMEOUT_MS = 20_000
const DIFF_TIMEOUT_MS = 20_000
/** The wrapper bounds boundary-couplings at 15 s, and branch-diff (two whole graphs compared) at 30 s. */
const COUPLINGS_TIMEOUT_MS = 20_000
const BRANCH_TIMEOUT_MS = 35_000

/** How long the detail beside the tab waits after the marker moves before it looks the marked row up: a run of j presses is one lookup. */
const PEEK_DEBOUNCE_MS = 120

/** The wrapper bounds churn, blast-radius and path-between at 15 s. */
const CHURN_TIMEOUT_MS = 20_000
const RINGS_TIMEOUT_MS = 20_000
const ROUTE_TIMEOUT_MS = 20_000

/**
 * Loads the dashboard into state; false when nothing was stored. Silence, or
 * an error envelope over good figures, keeps the figures there and marks the
 * refresh failed so the pane says how old they are.
 */
export async function refreshDashboard(io: Port): Promise<boolean> {
  if (mod.disabled) return false
  // A request during a load coalesces into one rerun after it, so the last load to land is the newest.
  await (mod.dashboardFlight ??= new SingleFlight(() => loadDashboard(io))).request()
  return mod.dashboardStored
}

/** One dashboard load; records in `mod.dashboardStored` whether it stored what it read. */
async function loadDashboard(io: Port): Promise<void> {
  mod.dashboardStored = false
  if (mod.disabled) return
  mod.dashboardTriedAt = await io.clock.now()
  const stdout = await wrapper(io, 'dashboard', [`--fan-in-threshold=${mod.threshold}`], DASHBOARD_TIMEOUT_MS)
  const parsed = parseDashboard(stdout)
  if (parsed?.status === 'no-binary') {
    await disable(io)
    return
  }
  if (parsed === null || (parsed.status === 'error' && (await io.state.dashboard.read())?.status === 'ok')) {
    await io.state.refresh.update(r => ({ ...r, failed: true }))
    return
  }
  const now = await io.clock.now()
  const before = await io.state.dashboard.read()
  // The rows the new snapshot changed light up for a moment once it lands.
  await light(io, flashKeys(before, parsed as Dashboard), now)
  await io.state.dashboard.update(() => parsed)
  // A cycle or a policy violation the scan brought: said once, as a toast, unless the person turned that off.
  if (mod.alertsOn) {
    for (const alert of freshAlerts(before, parsed as Dashboard, mod.toasted)) {
      alertKeys(alert).forEach(key => mod.toasted.add(key))
      io.ui.toast(alert.text)
    }
  }
  await io.state.refresh.update((): RefreshState => ({ fetchedAt: now, failed: false }))
  mod.dashboardStored = true
  if (parsed.status === 'ok') mod.snapshot = parsed.snapshot_id ?? mod.snapshot
  // The session's changes are read since the first snapshot it saw, kept for when it is continued in another process.
  if (parsed.status === 'ok' && parsed.snapshot_id !== null && (await io.state.sessionStart.read()) === null) {
    await io.state.sessionStart.update(() => parsed.snapshot_id)
    await saveBaseline(io)
  }
  // The cycles as the session found them: what the note after a commit counts as new.
  if (parsed.status === 'ok' && (await io.state.startCycles.read()) === null) await io.state.startCycles.update(() => cyclesOf(parsed))
  // The pane open on a component follows the graph: a new snapshot looks it up again.
  const shown = (await io.state.view.read()).inspect
  if (shown !== null) await requestDetail(io, shown)
  // And the Boundaries tab's cell: its couplings are read for the graph on show; and the Branch tab's comparison.
  await request(io, 'couplings')
  await request(io, 'branch')
  await request(io, 'peek')
  await request(io, 'route')
}

/**
 * Shows one component (or, with `file`, one file) on the pane and starts its
 * lookup. The lookup runs on a timer, never inside a render: the pane draws
 * a loading line from state until the answer is stored.
 */
export async function showComponent(io: Port, shown: Inspected): Promise<void> {
  // The detail's marker starts on its first row; the tab's is kept for `b` to put back.
  await io.state.view.update(v => ({ ...v, inspect: shown, selected: 0, opened: v.inspect === null ? v.selected : v.opened, route: null, picking: null }))
  await requestDetail(io, shown)
}

/** Whether a stored detail is the one asked for: the same name, kind and snapshot. */
const sameDetail = (state: DetailState | null, shown: Inspected, snapshot: string | null): boolean =>
  state !== null && state.name === shown.name && (state.file === true) === (shown.file === true) && state.snapshot_id === snapshot

/** Which row of which tab and graph the detail beside the tab stands for: what tells a row already looked at. */
export const peekKey = (v: KnossosView, snapshot: string | null): string => `${v.tab}\u0000${v.selected}\u0000${v.filter}\u0000${snapshot ?? ''}`

/** Where a read of the graph on show runs: its project root, or the session's root when there is no graph. */
const rootOf = (d: Dashboard | null): string | undefined => (d?.status === 'ok' ? (d.project_root ?? undefined) : undefined)

/** What one read asks the wrapper: the subcommand, its arguments and bound, and the directory it runs in (the session's root when absent). */
export type Ask = { sub: string; args: string[]; timeoutMs: number; root?: string }

/**
 * One kind of read the pane draws from: what it is a read of now (`want`,
 * null when there is nothing to read, as on a tab that shows none), whether
 * the cell already holds that or is reading it (`holds`), the cell while it
 * reads (`loading`), what it asks the wrapper (`ask`) and how the answer is
 * parsed, and the cell once the answer lands (`landed`), left as it is when
 * the cell has moved on to something else meanwhile.
 *
 * `flight` keeps a read already on its way from starting a second (the
 * component detail: its cell can move to another component and back within
 * one lookup); `debounceMs` waits that long after the last request (the
 * detail beside the tab: a run of moves is one lookup); `prepare` runs as the
 * read starts.
 */
export type Loader<S, W, P extends { status: string }> = {
  cell: (io: Port) => Cell<S | null>
  want: (io: Port, shown?: Inspected) => Promise<W | null>
  holds: (current: S | null, want: W) => boolean
  loading: (current: S | null, want: W) => S
  ask: (io: Port, want: W) => Promise<Ask>
  parse: (stdout: string, want: W) => P | null
  landed: (current: S | null, want: W, parsed: P | null) => S | null
  flight?: { key: (want: W) => string; same: (current: S | null, want: W) => boolean }
  debounceMs?: number
  prepare?: (io: Port, want: W) => Promise<void>
}

/** A component's detail or a file's, as both the detail and the detail beside the tab look it up: a file by its path under the project root. */
async function lookup(io: Port, shown: Inspected): Promise<Ask> {
  const root = shown.file === true ? ((await io.state.dashboard.read())?.project_root ?? undefined) : undefined
  return { sub: shown.file === true ? 'file-detail' : 'component-detail', args: [shown.name], timeoutMs: DETAIL_TIMEOUT_MS, root }
}

/** A lookup's answer as a detail holds it: a file's, or a component's. */
const looked = (shown: Inspected, stdout: string): FileDetail | ComponentDetail | null => (shown.file === true ? parseFileDetail(stdout) : parseComponentDetail(stdout))
const answerOf = (shown: Inspected, parsed: FileDetail | ComponentDetail | null) => (shown.file === true ? { fileDetail: parsed as FileDetail | null } : { detail: parsed as ComponentDetail | null })

/** Every read the pane draws from that is keyed to what it shows, by name. Each runs on a timer, never in a render. */
export const LOADERS = {
  /** The Boundaries tab's marked heat map cell spelled out: the component pairs behind it, for this cell of this graph. */
  couplings: {
    cell: io => io.state.couplings,
    want: async io => {
      const input = await currentInput(io, true)
      const pair = input === null ? null : couplingPair(input)
      if (pair === null) return null
      const d = await io.state.dashboard.read()
      return { from: pair.from, to: pair.to, snapshot: d?.snapshot_id ?? null, root: rootOf(d) }
    },
    holds: (c, w) => c !== null && c.from === w.from && c.to === w.to && c.snapshot === w.snapshot,
    loading: (_, w): CouplingState => ({ snapshot: w.snapshot, from: w.from, to: w.to, phase: 'loading', answer: null }),
    ask: async (_, w) => ({ sub: 'boundary-couplings', args: [`--from=${w.from}`, `--to=${w.to}`], timeoutMs: COUPLINGS_TIMEOUT_MS, root: w.root }),
    parse: parseCouplings,
    landed: (c, w, parsed) => (c !== null && c.from === w.from && c.to === w.to && c.snapshot === w.snapshot ? { ...c, phase: 'done', answer: parsed } : c),
  } satisfies Loader<CouplingState, { from: string; to: string; snapshot: string | null; root: string | undefined }, NonNullable<ReturnType<typeof parseCouplings>>>,
  /**
   * How a changed file (one opened from the session's changes) changed since
   * the session began, for this graph: a new snapshot (the file changed
   * again) reads it again. Without the session's commit there is nothing to
   * read, and the detail says why.
   */
  diff: {
    cell: io => io.state.fileDiff,
    want: async (io, shown) => {
      if (shown?.file !== true || shown.changed !== true) return null
      const rev = await io.state.sessionRev.read()
      if (rev?.status !== 'ok') return null
      const d = await io.state.dashboard.read()
      return { name: shown.name, rev: rev.rev, snapshot: d?.snapshot_id ?? null, root: rootOf(d) }
    },
    holds: (c, w) => c !== null && c.name === w.name && c.rev === w.rev && c.snapshot === w.snapshot,
    loading: (_, w): DiffState => ({ name: w.name, rev: w.rev, snapshot: w.snapshot, phase: 'loading', diff: null }),
    ask: async (_, w) => ({ sub: 'session-diff', args: [`--rev=${w.rev}`, `--file=${w.name}`], timeoutMs: DIFF_TIMEOUT_MS, root: w.root }),
    parse: parseSessionDiff,
    landed: (c, w, parsed) => (c !== null && c.name === w.name && c.rev === w.rev && c.snapshot === w.snapshot ? { ...c, phase: 'done', diff: parsed } : c),
  } satisfies Loader<DiffState, { name: string; rev: string; snapshot: string | null; root: string | undefined }, NonNullable<ReturnType<typeof parseSessionDiff>>>,
  /** What the detail shows, a component or a file, for this snapshot; silence is asked again on the next request. */
  detail: {
    cell: io => io.state.detail,
    want: async (io, shown) => (shown === undefined ? null : { shown, snapshot: (await io.state.dashboard.read())?.snapshot_id ?? null }),
    holds: (c, w) => sameDetail(c, w.shown, w.snapshot) && c?.phase === 'done' && (w.shown.file === true ? c.fileDetail : c.detail) != null,
    loading: (_, w): DetailState => ({ snapshot_id: w.snapshot, name: w.shown.name, ...(w.shown.file === true ? { file: true } : {}), detail: null, phase: 'loading' }),
    ask: (io, w) => lookup(io, w.shown),
    parse: (stdout, w) => looked(w.shown, stdout),
    landed: (c, w, parsed) => (sameDetail(c, w.shown, w.snapshot) ? { ...c!, ...answerOf(w.shown, parsed), phase: 'done' } : c),
    // A lookup for this name already in flight: when the detail has since moved to another name (A, then B, then A
    // again inside one lookup), its loading state is put back so the answer on its way is stored when it lands.
    flight: { key: w => `${w.snapshot ?? ''}\u0000${w.shown.file === true ? 'file' : 'component'}\u0000${w.shown.name}`, same: (c, w) => sameDetail(c, w.shown, w.snapshot) },
  } satisfies Loader<DetailState, { shown: Inspected; snapshot: string | null }, FileDetail | ComponentDetail>,
  /**
   * The marked row looked up for the detail beside the tab, while the pane is
   * drawn wide on a tab that has one: a component as its detail, a file as the
   * file's (with its change, when it is one of the session's). A row with
   * nothing to show (a boundary, a chart's bar) shows none.
   */
  peek: {
    cell: io => io.state.peek,
    want: async io => {
      if (!mod.paneWide) return null
      const v = await io.state.view.read()
      if (v.inspect !== null || v.finding === true || v.drift === true || !PEEK_TABS.includes(v.tab)) return null
      const list = await currentList(io)
      const item = list[Math.min(Math.max(0, v.selected), Math.max(0, list.length - 1))]
      if (item === undefined || item.inert === true || item.jump !== undefined) {
        mod.peekNone = peekKey(v, (await io.state.dashboard.read())?.snapshot_id ?? null)
        if ((await io.state.peek.read()) !== null) await io.state.peek.update(() => null)
        return null
      }
      mod.peekNone = null
      const shown: Inspected = { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}), ...(item.changed === true ? { changed: true } : {}) }
      return { shown, snapshot: (await io.state.dashboard.read())?.snapshot_id ?? null }
    },
    holds: (c, w) => c !== null && c.shown.name === w.shown.name && (c.shown.file === true) === (w.shown.file === true) && c.detail.snapshot_id === w.snapshot,
    loading: (_, w) => ({ shown: w.shown, detail: { snapshot_id: w.snapshot, name: w.shown.name, ...(w.shown.file === true ? { file: true as const } : {}), detail: null, phase: 'loading' as const } }),
    ask: (io, w) => lookup(io, w.shown),
    parse: (stdout, w) => looked(w.shown, stdout),
    landed: (c, w, parsed) => (c !== null && c.shown.name === w.shown.name && c.detail.snapshot_id === w.snapshot ? { shown: c.shown, detail: { ...c.detail, ...answerOf(w.shown, parsed), phase: 'done' as const } } : c),
    debounceMs: PEEK_DEBOUNCE_MS,
    // A file shows its change beside the tab too.
    prepare: (io, w) => requestDiff(io, w.shown),
  } satisfies Loader<{ shown: Inspected; detail: DetailState }, { shown: Inspected; snapshot: string | null }, FileDetail | ComponentDetail>,
  /** The branch compared with its merge base while the Branch tab is open, for this graph: the last answer stays on show meanwhile. */
  branch: {
    cell: io => io.state.branch,
    want: async io => {
      const v = await io.state.view.read()
      if (v.tab !== 'branch' || v.inspect !== null) return null
      const d = await io.state.dashboard.read()
      return d?.status === 'ok' ? { snapshot: d.snapshot_id ?? null, root: d.project_root ?? undefined } : null
    },
    holds: (c, w) => c !== null && c.snapshot === w.snapshot,
    loading: (c, w): BranchState => ({ snapshot: w.snapshot, phase: 'loading', answer: c?.answer ?? null }),
    ask: async (_, w) => ({ sub: 'branch-diff', args: [], timeoutMs: BRANCH_TIMEOUT_MS, root: w.root }),
    parse: parseBranchDiff,
    landed: (c, w, parsed) => (c !== null && c.snapshot === w.snapshot ? { snapshot: w.snapshot, phase: 'done', answer: parsed } : c),
  } satisfies Loader<BranchState, { snapshot: string | null; root: string | undefined }, NonNullable<ReturnType<typeof parseBranchDiff>>>,
  /**
   * The churn hotspots while the Churn or the Branch tab is open, for the
   * commit the checkout is at: the history is kept per commit, so a new
   * commit, not a new scan, reads it again. The last answer stays on show
   * meanwhile.
   */
  churn: {
    cell: io => io.state.churn,
    want: async io => {
      const v = await io.state.view.read()
      // The Branch tab shows the hotspots too, where it has room.
      if ((v.tab !== 'churn' && v.tab !== 'branch') || v.inspect !== null || (v.route ?? null) !== null) return null
      const d = await io.state.dashboard.read()
      if (d?.status !== 'ok') return null
      return { head: (await io.state.gitHead.read())?.rev ?? null, root: d.project_root ?? undefined }
    },
    holds: (c, w) => c !== null && c.head === w.head,
    loading: (c, w): ChurnState => ({ head: w.head, phase: 'loading', answer: c?.answer ?? null }),
    ask: async (_, w) => ({ sub: 'churn', args: [], timeoutMs: CHURN_TIMEOUT_MS, root: w.root }),
    parse: parseChurn,
    landed: (c, w, parsed) => (c !== null && c.head === w.head ? { head: w.head, phase: 'done', answer: parsed } : c),
  } satisfies Loader<ChurnState, { head: string | null; root: string | undefined }, NonNullable<ReturnType<typeof parseChurn>>>,
  /** A component's blast radius for its detail, for this graph (a file's detail has none): the last rings stay on show meanwhile. */
  rings: {
    cell: io => io.state.rings,
    want: async (io, shown) => {
      if (shown === undefined || shown.file === true) return null
      const d = await io.state.dashboard.read()
      return { name: shown.name, snapshot: d?.snapshot_id ?? null, root: rootOf(d) }
    },
    holds: (c, w) => c !== null && c.name === w.name && c.snapshot === w.snapshot,
    loading: (c, w): RingsState => ({ name: w.name, snapshot: w.snapshot, phase: 'loading', answer: c !== null && c.name === w.name ? c.answer : null }),
    ask: async (_, w) => ({ sub: 'blast-radius', args: [`--component=${w.name}`], timeoutMs: RINGS_TIMEOUT_MS, root: w.root }),
    parse: parseBlastRadius,
    landed: (c, w, parsed) => (c !== null && c.name === w.name && c.snapshot === w.snapshot ? { ...c, phase: 'done', answer: parsed } : c),
  } satisfies Loader<RingsState, { name: string; snapshot: string | null; root: string | undefined }, NonNullable<ReturnType<typeof parseBlastRadius>>>,
  /** The route the path explorer draws between its two ends, in this graph. */
  route: {
    cell: io => io.state.route,
    want: async io => {
      const shown = (await io.state.view.read()).route ?? null
      if (shown === null) return null
      const d = await io.state.dashboard.read()
      return { from: shown.from.name, to: shown.to.name, snapshot: d?.snapshot_id ?? null, root: rootOf(d) }
    },
    holds: (c, w) => c !== null && c.from === w.from && c.to === w.to && c.snapshot === w.snapshot,
    loading: (c, w): RouteState => ({ from: w.from, to: w.to, snapshot: w.snapshot, phase: 'loading', answer: c !== null && c.from === w.from && c.to === w.to ? c.answer : null }),
    ask: async (_, w) => ({ sub: 'path-between', args: [`--from=${w.from}`, `--to=${w.to}`], timeoutMs: ROUTE_TIMEOUT_MS, root: w.root }),
    parse: parsePathBetween,
    landed: (c, w, parsed) => (c !== null && c.from === w.from && c.to === w.to && c.snapshot === w.snapshot ? { ...c, phase: 'done', answer: parsed } : c),
  } satisfies Loader<RouteState, { from: string; to: string; snapshot: string | null; root: string | undefined }, NonNullable<ReturnType<typeof parsePathBetween>>>,
}

/** The name of a read in {@link LOADERS}. */
export type LoaderKey = keyof typeof LOADERS

/** Any loader, as the keyed functions below drive it. */
type AnyLoader = Loader<unknown, unknown, { status: string }>

/**
 * Starts the read `key` names (for `shown`, where it reads for a shown
 * component or file) unless the mod is off, there is nothing to read, or
 * its cell already holds or is reading what is wanted. The read itself runs
 * on a timer once this has resolved, never inside a render.
 */
export async function request(io: Port, key: LoaderKey, shown?: Inspected): Promise<void> {
  if (mod.disabled) return
  const loader = LOADERS[key] as unknown as AnyLoader
  const want = await loader.want(io, shown)
  if (want === null) return
  const cell = loader.cell(io)
  // Checked and set with no await between, so two presses in the same tick cannot both start one.
  const flight = loader.flight?.key(want)
  if (flight !== undefined) {
    if (mod.fetching.has(flight)) {
      if (!loader.flight!.same(await cell.read(), want)) await cell.update(c => loader.loading(c, want))
      return
    }
    mod.fetching.add(flight)
  }
  if (loader.holds(await cell.read(), want)) {
    if (flight !== undefined) mod.fetching.delete(flight)
    return
  }
  await cell.update(c => loader.loading(c, want))
  const run = () => void load(io, loader, want, flight).catch(() => undefined)
  if (loader.debounceMs === undefined) {
    io.clock.after(0, run)
    return
  }
  mod.loadTimers.get(key)?.cancel()
  mod.loadTimers.set(
    key,
    io.clock.after(loader.debounceMs, () => {
      mod.loadTimers.delete(key)
      run()
    }),
  )
}

/** One read: its answer is stored only while the cell still wants it ({@link Loader}`.landed`); `no-binary` turns the mod off. */
async function load(io: Port, loader: AnyLoader, want: unknown, flight: string | undefined): Promise<void> {
  if (mod.disabled) return
  try {
    await loader.prepare?.(io, want)
    const ask = await loader.ask(io, want)
    const parsed = loader.parse(await wrapper(io, ask.sub, ask.args, ask.timeoutMs, ask.root), want)
    if (parsed?.status === 'no-binary') return await disable(io)
    await loader.cell(io).update(c => loader.landed(c, want, parsed))
  } finally {
    // A lost write leaves the loading line; the next request asks again.
    if (flight !== undefined) mod.fetching.delete(flight)
  }
}

/** Starts reading a changed file's diff ({@link LOADERS}`.diff`). */
function requestDiff(io: Port, shown: Inspected): Promise<void> {
  return request(io, 'diff', shown)
}

/** Starts the reads the detail on `shown` draws from: a changed file's diff, a component's rings, and the detail itself. */
export async function requestDetail(io: Port, shown: Inspected): Promise<void> {
  if (mod.disabled) return
  await requestDiff(io, shown)
  await request(io, 'rings', shown)
  await request(io, 'detail', shown)
}

/** The session's id, or null when the engine does not say, or still names the session that ended. */
export async function currentSession(io: Port): Promise<string | null> {
  const id = await io.session.id().catch(() => null)
  return typeof id === 'string' && id !== '' && id !== mod.endedSession ? id : null
}

/** The baselines kept in the plugin's store, by session id; none when it cannot be read. */
async function storedBaselines(io: Port): Promise<Record<string, Baseline>> {
  return baselinesOf(await io.store.get(BASELINES_KEY).catch(() => undefined))
}

/**
 * Where the session began. A session this mod has seen before (continued
 * or resumed, in this process or another) gets its commit and its first
 * snapshot back from the store, so its Changes and diffs keep their
 * baseline; any other session records the commit it begins at. Either way
 * the baseline is stored under the session's id.
 */
export async function settleBaseline(io: Port): Promise<void> {
  const id = await currentSession(io)
  const kept = id === null ? undefined : (await storedBaselines(io))[id]
  if (id !== null && kept !== undefined) {
    if (kept.rev !== null) await io.state.sessionRev.update(() => kept.rev)
    if (kept.snapshot !== null) await io.state.sessionStart.update(() => kept.snapshot)
    await io.state.sessionBegan.update(() => kept.startedAt)
    mod.baselineOf = id
    return
  }
  // Asked once per session: a head read later would name a commit made during it.
  if (!mod.headAsked) {
    mod.headAsked = true
    await recordHead(io)
  }
  await saveBaseline(io)
}

/** Stores the session's baseline as it stands, under its id; a store that refuses is no failure. */
async function saveBaseline(io: Port): Promise<void> {
  const id = await currentSession(io)
  if (id === null || mod.disabled) return
  const all = await storedBaselines(io)
  const baseline: Baseline = { rev: await io.state.sessionRev.read(), snapshot: await io.state.sessionStart.read(), startedAt: await io.clock.now() }
  await io.store.set(BASELINES_KEY, remember(all, id, baseline)).catch(() => undefined)
  // A session saved before keeps the moment it began.
  await io.state.sessionBegan.update(() => all[id]?.startedAt ?? baseline.startedAt)
  mod.baselineOf = id
}

/**
 * Records the commit the project is at as the one the session began at,
 * unless one is recorded. Silence leaves none: a later read would name a
 * commit made during the session, and the diff would leave its changes out.
 */
async function recordHead(io: Port): Promise<void> {
  if (mod.disabled || (await io.state.sessionRev.read()) !== null) return
  const stdout = await wrapper(io, 'session-head', [], HEAD_TIMEOUT_MS)
  if (parseSessionDiff(stdout)?.status === 'no-binary') return disable(io)
  const rev = parseSessionRev(stdout)
  if (rev !== null) await io.state.sessionRev.update(() => rev)
  await noteGitHead(io, stdout)
}

/**
 * Reads where the checkout stands, its commit and branch, for the header:
 * at start-up and after each of the main loop's turns (one may commit or
 * switch branches), always on a timer, never in a render.
 */
export async function readGitHead(io: Port): Promise<void> {
  if (mod.disabled) return
  const stdout = await wrapper(io, 'session-head', [], HEAD_TIMEOUT_MS)
  if (parseSessionDiff(stdout)?.status === 'no-binary') return disable(io)
  await noteGitHead(io, stdout)
}

/** Keeps what a `session-head` answer says of the checkout; silence keeps what was read before. */
async function noteGitHead(io: Port, stdout: string): Promise<void> {
  mod.gitAsked = true
  const head = parseSessionHead(stdout)
  if (head !== undefined) await io.state.gitHead.update(() => head)
  // The Churn tab's history ends at the checkout's commit: a new one reads it again.
  await request(io, 'churn')
}
