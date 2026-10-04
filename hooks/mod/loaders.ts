import type { BranchState, ChurnState, ComponentDetail, CouplingState, Dashboard, DetailState, DiffState, FileDetail, Inspected, KnossosView, RefreshState, RingsState, RouteState } from '../../types'
import { alertKeys, freshAlerts } from '../lib/alerts'
import { BASELINES_KEY, baselinesOf, remember } from '../lib/baseline'
import type { Baseline } from '../lib/baseline'
import { parseBlastRadius, parseBranchDiff, parseChurn, parseComponentDetail, parseCouplings, parseDashboard, parseFileDetail, parsePathBetween, parseSessionDiff, parseSessionHead, parseSessionRev } from '../lib/envelopes'
import { flashKeys } from '../lib/flash'
import { couplingPair, PEEK_TABS } from '../lib/layout'
import { SingleFlight } from '../lib/scheduler'
import { disable, light, wrapper } from './port'
import type { Port } from './port'
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
  await requestCouplings(io)
  await requestBranch(io)
  await requestPeek(io)
  await requestRoute(io)
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

/** Starts a lookup of what `shown` names unless one is running or done for this snapshot; silence is asked again. */
export async function requestDetail(io: Port, shown: Inspected): Promise<void> {
  if (mod.disabled) return
  await requestDiff(io, shown)
  await requestRings(io, shown)
  const snapshot = (await io.state.dashboard.read())?.snapshot_id ?? null
  const { name } = shown
  const file = shown.file === true
  const key = `${snapshot ?? ''}\u0000${file ? 'file' : 'component'}\u0000${name}`
  const loading = (): DetailState => ({ snapshot_id: snapshot, name, ...(file ? { file: true } : {}), detail: null, phase: 'loading' })
  if (mod.fetching.has(key)) {
    // A lookup for this name is already in flight. When the detail has since
    // moved to another name (A, then B, then A again inside one lookup), put
    // the loading state back so the in-flight answer is stored when it lands.
    if (!sameDetail(await io.state.detail.read(), shown, snapshot)) await io.state.detail.update(loading)
    return
  }
  mod.fetching.add(key)
  const current = await io.state.detail.read()
  if (sameDetail(current, shown, snapshot) && current?.phase === 'done' && (file ? current.fileDetail : current.detail) != null) {
    mod.fetching.delete(key)
    return
  }
  await io.state.detail.update(loading)
  io.clock.after(0, () => void loadDetail(io, snapshot, shown, key))
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
  await requestChurn(io)
}

/**
 * Starts reading the Boundaries tab's marked heat map cell (the component
 * pairs behind it) unless that read is done or running for this cell of this
 * graph. On a timer, never in a render: the card says it is reading until
 * the answer is stored, and an answer for a cell the marker has left is
 * dropped.
 */
export async function requestCouplings(io: Port): Promise<void> {
  if (mod.disabled) return
  const input = await currentInput(io, true)
  const pair = input === null ? null : couplingPair(input)
  if (pair === null) return
  const d = await io.state.dashboard.read()
  const snapshot = d?.snapshot_id ?? null
  const current = await io.state.couplings.read()
  if (current !== null && current.from === pair.from && current.to === pair.to && current.snapshot === snapshot) return
  await io.state.couplings.update((): CouplingState => ({ snapshot, from: pair.from, to: pair.to, phase: 'loading', answer: null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  io.clock.after(0, () => void loadCouplings(io, pair.from, pair.to, snapshot, root).catch(() => undefined))
}

/** One read of a cell's couplings; stored only while the pane still marks that cell of that graph. */
async function loadCouplings(io: Port, from: string, to: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseCouplings(await wrapper(io, 'boundary-couplings', [`--from=${from}`, `--to=${to}`], COUPLINGS_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.couplings.update((c): CouplingState | null => (c !== null && c.from === from && c.to === to && c.snapshot === snapshot ? { ...c, phase: 'done', answer: parsed } : c))
}

/**
 * Starts reading how a changed file (one opened from the session's changes)
 * changed since the session began, unless that read is done or running for
 * this graph: a new snapshot (the file changed again) reads it again. Runs
 * on a timer, never in a render; without the session's commit there is
 * nothing to read, and the detail says why.
 */
async function requestDiff(io: Port, shown: Inspected): Promise<void> {
  if (shown.file !== true || shown.changed !== true || mod.disabled) return
  const rev = await io.state.sessionRev.read()
  if (rev?.status !== 'ok') return
  const d = await io.state.dashboard.read()
  const snapshot = d?.snapshot_id ?? null
  const current = await io.state.fileDiff.read()
  if (current !== null && current.name === shown.name && current.rev === rev.rev && current.snapshot === snapshot) return
  await io.state.fileDiff.update((): DiffState => ({ name: shown.name, rev: rev.rev, snapshot, phase: 'loading', diff: null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  io.clock.after(0, () => void loadDiff(io, shown.name, rev.rev, snapshot, root).catch(() => undefined))
}

/** One read of a file's change; stored only while the detail still asks for that file, commit and graph. */
async function loadDiff(io: Port, name: string, rev: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const stdout = await wrapper(io, 'session-diff', [`--rev=${rev}`, `--file=${name}`], DIFF_TIMEOUT_MS, root)
  const parsed = parseSessionDiff(stdout)
  if (parsed?.status === 'no-binary') return disable(io)
  const now = await io.state.fileDiff.read()
  if (now !== null && now.name === name && now.rev === rev && now.snapshot === snapshot) await io.state.fileDiff.update((): DiffState => ({ ...now, phase: 'done', diff: parsed }))
}

/**
 * One lookup; its answer is stored only while the detail still asks for that
 * component (or file) and snapshot. A file is read by its path under the
 * project root, where the dashboard placed it.
 */
async function loadDetail(io: Port, snapshot: string | null, shown: Inspected, key: string): Promise<void> {
  if (mod.disabled) return
  try {
    const file = shown.file === true
    const root = file ? ((await io.state.dashboard.read())?.project_root ?? undefined) : undefined
    const stdout = await wrapper(io, file ? 'file-detail' : 'component-detail', [shown.name], DETAIL_TIMEOUT_MS, root)
    const parsed = file ? parseFileDetail(stdout) : parseComponentDetail(stdout)
    if (parsed?.status === 'no-binary') {
      await disable(io)
      return
    }
    const answer = file ? { fileDetail: parsed as FileDetail | null } : { detail: parsed as ComponentDetail | null }
    await io.state.detail.update((current): DetailState | null => (sameDetail(current, shown, snapshot) ? { ...current!, ...answer, phase: 'done' } : current))
  } catch {
    // A lost write leaves the loading line; the next press asks again.
  } finally {
    mod.fetching.delete(key)
  }
}

/**
 * Starts looking up the marked row for the detail beside the tab, while the
 * pane is drawn wide on a tab that has one: a component as its detail, a
 * file as the file's (with its change, when it is one of the session's).
 * After a short pause, so a run of moves is one lookup; never in a render.
 * A row with nothing to show (a boundary, a chart's bar) shows none.
 */
export async function requestPeek(io: Port): Promise<void> {
  if (mod.disabled || !mod.paneWide) return
  const v = await io.state.view.read()
  if (v.inspect !== null || v.finding === true || v.drift === true || !PEEK_TABS.includes(v.tab)) return
  const list = await currentList(io)
  const item = list[Math.min(Math.max(0, v.selected), Math.max(0, list.length - 1))]
  if (item === undefined || item.inert === true || item.jump !== undefined) {
    mod.peekNone = peekKey(v, (await io.state.dashboard.read())?.snapshot_id ?? null)
    if ((await io.state.peek.read()) !== null) await io.state.peek.update(() => null)
    return
  }
  mod.peekNone = null
  const shown: Inspected = { name: item.canonical, label: item.name, ...(item.file === true ? { file: true } : {}), ...(item.changed === true ? { changed: true } : {}) }
  const snapshot = (await io.state.dashboard.read())?.snapshot_id ?? null
  const current = await io.state.peek.read()
  if (current !== null && current.shown.name === shown.name && (current.shown.file === true) === (shown.file === true) && current.detail.snapshot_id === snapshot) return
  await io.state.peek.update(() => ({ shown, detail: { snapshot_id: snapshot, name: shown.name, ...(shown.file === true ? { file: true as const } : {}), detail: null, phase: 'loading' as const } }))
  mod.peekTimer?.cancel()
  mod.peekTimer = io.clock.after(PEEK_DEBOUNCE_MS, () => {
    mod.peekTimer = null
    void loadPeek(io, shown, snapshot).catch(() => undefined)
  })
}

/** Which row of which tab and graph the detail beside the tab stands for: what tells a row already looked at. */
export const peekKey = (v: KnossosView, snapshot: string | null): string => `${v.tab}\u0000${v.selected}\u0000${v.filter}\u0000${snapshot ?? ''}`

/** One lookup for the detail beside the tab; stored only while that row is still the one shown. */
async function loadPeek(io: Port, shown: Inspected, snapshot: string | null): Promise<void> {
  if (mod.disabled) return
  await requestDiff(io, shown)
  const file = shown.file === true
  const root = file ? ((await io.state.dashboard.read())?.project_root ?? undefined) : undefined
  const stdout = await wrapper(io, file ? 'file-detail' : 'component-detail', [shown.name], DETAIL_TIMEOUT_MS, root)
  const parsed = file ? parseFileDetail(stdout) : parseComponentDetail(stdout)
  if (parsed?.status === 'no-binary') return disable(io)
  const answer = file ? { fileDetail: parsed as FileDetail | null } : { detail: parsed as ComponentDetail | null }
  await io.state.peek.update(p => (p !== null && p.shown.name === shown.name && p.detail.snapshot_id === snapshot ? { shown: p.shown, detail: { ...p.detail, ...answer, phase: 'done' as const } } : p))
}

/**
 * Starts comparing the branch with its merge base while the Branch tab is
 * open, unless that comparison is done or running for this graph: a new
 * snapshot compares again, the last answer kept on show meanwhile. On a
 * timer, never in a render.
 */
export async function requestBranch(io: Port): Promise<void> {
  if (mod.disabled) return
  const v = await io.state.view.read()
  if (v.tab !== 'branch' || v.inspect !== null) return
  const d = await io.state.dashboard.read()
  if (d?.status !== 'ok') return
  const snapshot = d.snapshot_id ?? null
  const current = await io.state.branch.read()
  if (current !== null && current.snapshot === snapshot) return
  await io.state.branch.update((b): BranchState => ({ snapshot, phase: 'loading', answer: b?.answer ?? null }))
  const root = d.project_root ?? undefined
  io.clock.after(0, () => void loadBranch(io, snapshot, root).catch(() => undefined))
}

/** One comparison; stored only while it is still for the graph on show. */
async function loadBranch(io: Port, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseBranchDiff(await wrapper(io, 'branch-diff', [], BRANCH_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.branch.update((b): BranchState | null => (b !== null && b.snapshot === snapshot ? { snapshot, phase: 'done', answer: parsed } : b))
}

/**
 * Starts reading the churn hotspots while the Churn or the Branch tab is open, unless
 * they were read (or are being read) for the commit the checkout is at:
 * the history is kept per commit, so a new commit, not a new scan, reads
 * it again. The last answer stays on show meanwhile. On a timer, never in
 * a render.
 */
export async function requestChurn(io: Port): Promise<void> {
  if (mod.disabled) return
  const v = await io.state.view.read()
  // The Branch tab shows the hotspots too, where it has room.
  if ((v.tab !== 'churn' && v.tab !== 'branch') || v.inspect !== null || (v.route ?? null) !== null) return
  const d = await io.state.dashboard.read()
  if (d?.status !== 'ok') return
  const head = (await io.state.gitHead.read())?.rev ?? null
  const current = await io.state.churn.read()
  if (current !== null && current.head === head) return
  await io.state.churn.update((c): ChurnState => ({ head, phase: 'loading', answer: c?.answer ?? null }))
  const root = d.project_root ?? undefined
  io.clock.after(0, () => void loadChurn(io, head, root).catch(() => undefined))
}

/** One read of the churn hotspots; stored only while it is still for the commit on show. */
async function loadChurn(io: Port, head: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseChurn(await wrapper(io, 'churn', [], CHURN_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.churn.update((c): ChurnState | null => (c !== null && c.head === head ? { head, phase: 'done', answer: parsed } : c))
}

/**
 * Starts reading a component's blast radius for its detail, unless it was
 * read (or is being read) for this component and graph; a file's detail
 * has none. A new snapshot reads it again, the last rings on show
 * meanwhile. On a timer, never in a render.
 */
async function requestRings(io: Port, shown: Inspected): Promise<void> {
  if (mod.disabled || shown.file === true) return
  const d = await io.state.dashboard.read()
  const snapshot = d?.snapshot_id ?? null
  const current = await io.state.rings.read()
  if (current !== null && current.name === shown.name && current.snapshot === snapshot) return
  await io.state.rings.update((r): RingsState => ({ name: shown.name, snapshot, phase: 'loading', answer: r !== null && r.name === shown.name ? r.answer : null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  io.clock.after(0, () => void loadRings(io, shown.name, snapshot, root).catch(() => undefined))
}

/** One read of a blast radius; stored only while the detail still asks for that component and graph. */
async function loadRings(io: Port, name: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parseBlastRadius(await wrapper(io, 'blast-radius', [`--component=${name}`], RINGS_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.rings.update((r): RingsState | null => (r !== null && r.name === name && r.snapshot === snapshot ? { ...r, phase: 'done', answer: parsed } : r))
}

/**
 * Starts looking for the route the path explorer shows, unless it was
 * looked for (or is being) between these two components in this graph. On
 * a timer, never in a render.
 */
export async function requestRoute(io: Port): Promise<void> {
  if (mod.disabled) return
  const shown = (await io.state.view.read()).route ?? null
  if (shown === null) return
  const d = await io.state.dashboard.read()
  const snapshot = d?.snapshot_id ?? null
  const [from, to] = [shown.from.name, shown.to.name]
  const current = await io.state.route.read()
  if (current !== null && current.from === from && current.to === to && current.snapshot === snapshot) return
  await io.state.route.update((r): RouteState => ({ from, to, snapshot, phase: 'loading', answer: r !== null && r.from === from && r.to === to ? r.answer : null }))
  const root = d?.status === 'ok' ? (d.project_root ?? undefined) : undefined
  io.clock.after(0, () => void loadRoute(io, from, to, snapshot, root).catch(() => undefined))
}

/** One route search; stored only while the explorer still shows these two ends in this graph. */
async function loadRoute(io: Port, from: string, to: string, snapshot: string | null, root: string | undefined): Promise<void> {
  if (mod.disabled) return
  const parsed = parsePathBetween(await wrapper(io, 'path-between', [`--from=${from}`, `--to=${to}`], ROUTE_TIMEOUT_MS, root))
  if (parsed?.status === 'no-binary') return disable(io)
  await io.state.route.update((r): RouteState | null => (r !== null && r.from === from && r.to === to && r.snapshot === snapshot ? { ...r, phase: 'done', answer: parsed } : r))
}
