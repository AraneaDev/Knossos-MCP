/**
 * The live watcher and the scans: the watcher child and its events, whose a
 * scanned change was (change attribution), the changes since the session
 * began as the scan ledger keeps them, the turn's brief, and the pane's
 * rescan.
 */
import type { LiveState, RescanState, WatchEvent } from '../../types'
import { activeBetween, FOLLOWED_SCAN_MS, lookbackMs, scanWindow } from '../lib/activity'
import type { JobState } from '../lib/band'
import { parseRescan, parseSessionLedger, parseTurnBrief, rescanReason } from '../lib/envelopes'
import type { TurnBrief } from '../lib/envelopes'
import { ledgerFlashKeys } from '../lib/flash'
import { accumulate, cdFor } from '../lib/layout'
import { isWatching, LIVE_OFF, liveAfter, snapshotOf, watchLines } from '../lib/live'
import { SingleFlight } from '../lib/scheduler'
import { deliverNote, turnEndNote } from './agent'
import { refreshDashboard } from './loaders'
import { disable, keepScan, light, sleep, wrapper } from './port'
import type { Port } from './port'
import { exclusively, mod } from './state'

/** The wrapper bounds a turn brief at 60 s, plus room for it to exit on its own. */
const BRIEF_TIMEOUT_MS = 70_000

/** The wrapper bounds a rescan at 60 s, as a turn brief. */
const RESCAN_TIMEOUT_MS = 70_000

/** The watcher's debounce (its default): how long it waits after a change before it scans. */
const WATCH_DEBOUNCE_MS = 300

/** The longest a turn's brief waits for the watcher to take the turn's edits in before it scans them itself. */
const WATCH_SETTLE_MAX_MS = 20_000

/** How often a watcher that ended on its own is started again, and the pause before each try (times the try's number). */
const WATCH_RESTARTS = 3
const WATCH_RESTART_MS = 30_000

/** The wrapper bounds session-changes at 15 s. */
const LEDGER_TIMEOUT_MS = 20_000

/**
 * Asks for the changes since the session began again (from the scan ledger),
 * coalesced with a read in flight. Only while a watcher keeps that ledger:
 * without one the Changes tab shows the turn briefs. Called from the
 * watcher's loop and after a turn's brief, never inside a render.
 */
function requestLedger(io: Port): void {
  if (mod.disabled || mod.watcher === null) return
  void (mod.ledgerFlight ??= new SingleFlight(() => loadLedger(io))).request().catch(() => undefined)
}

/** One read of the changes since the session began; silence or an error keeps the last one. */
async function loadLedger(io: Port): Promise<void> {
  const since = await io.state.sessionStart.read()
  if (since === null || mod.disabled) return
  const parsed = parseSessionLedger(await wrapper(io, 'session-changes', [`--since=${since}`], LEDGER_TIMEOUT_MS))
  if (parsed?.status === 'no-binary') return disable(io)
  if (parsed?.status !== 'ok') return
  // A session that began again (a /clear) while this read ran reads since its own start.
  if ((await io.state.sessionStart.read()) !== since) return
  await light(io, ledgerFlashKeys(await io.state.sessionLedger.read(), parsed), await io.clock.now())
  await io.state.sessionLedger.update(() => parsed)
}

/** Stores what a turn brief says, by its status; resolves true when it is a fresh `ok`. */
async function settleBrief(io: Port, parsed: TurnBrief | null, now: number): Promise<boolean> {
  if (parsed?.status === 'no-binary') {
    await io.state.job.update((): JobState => ({ phase: 'idle', lastAttemptAt: now }))
    await disable(io)
    return false
  }
  if (parsed === null || parsed.status === 'error') {
    await io.state.job.update((): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  if (parsed.status === 'scan-failed') {
    // The first failure shows its reason; later ones keep the last good figures with their age.
    const previous = await io.state.brief.read()
    if (previous?.status !== 'ok') await io.state.brief.update(() => parsed)
    await io.state.job.update((): JobState => ({ phase: 'failed', lastAttemptAt: now }))
    return false
  }
  await io.state.brief.update(() => parsed)
  if (parsed.status === 'ok') await io.state.changes.update(c => accumulate(c, parsed))
  await io.state.job.update((): JobState => ({ phase: 'idle', lastAttemptAt: now }))
  return parsed.status === 'ok'
}

/** One run of the scan job: the turn brief for every path edited since the last run. */
async function scan(io: Port): Promise<void> {
  // Drained at the start of each run, so a coalesced rerun scans every path reported since.
  const files = [...mod.edited]
  mod.edited = new Set()
  // The turn's start goes with its files: a later turn's first edit takes the snapshot of its own.
  const base = mod.turnBase
  mod.turnBase = null
  let parsed: TurnBrief | null = null
  try {
    await io.state.job.update((j): JobState => ({ ...j, phase: 'scanning' }))
    // With a watcher keeping the graph current, the turn's edits are (or are about to be) scanned already:
    // the brief waits for that and reads them from the ledger instead of scanning again.
    // Only a watcher that is running now: the header's state alone may outlive it.
    const watching = mod.watcher !== null && isWatching(await io.state.live.read())
    if (watching) await settleWatcher(io)
    const args = [...files.map(f => `--files=${f}`), ...(base === null ? [] : [`--since=${base}`]), ...(watching ? ['--reuse-scan'] : []), ...(mod.enforce ? [] : ['--no-policies'])]
    parsed = parseTurnBrief(await exclusively(() => wrapper(io, 'turn-brief', args, BRIEF_TIMEOUT_MS)))
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
  if (parsed?.status === 'ok' && parsed.scanned === true && parsed.snapshot_id !== null) await keepScan(io, parsed.snapshot_id)
  if (!(await settleBrief(io, parsed, await io.clock.now())) || parsed === null) return
  const note = turnEndNote(parsed, cdFor(parsed.project_root, await io.state.sessionRoot.read()))
  if (note !== null) await deliverNote(io, note)
  // The brief's own scan is in the ledger too.
  requestLedger(io)
  await refreshDashboard(io)
}

/** The scan job's body: a run that throws leaves the job marked failed instead of rejecting. */
export async function scanSafely(io: Port): Promise<void> {
  try {
    await scan(io)
  } catch {
    const now = await io.clock.now().catch(() => null)
    await io.state.job.update((j): JobState => ({ phase: 'failed', lastAttemptAt: now ?? j.lastAttemptAt })).catch(
      () => undefined,
    )
  }
}

/**
 * Whether the scan that produced `snapshot`, begun at `started` and landed
 * at `ended`, took in changes made while the session's tools ran; keeps it
 * as the session's when it did. Remembered as the last scan either way.
 */
async function attributeScan(io: Port, snapshot: string, started: number, ended: number): Promise<void> {
  const { from, to } = scanWindow(started, ended, lookbackMs(mod.watchPollMs, WATCH_DEBOUNCE_MS), mod.lastScan)
  mod.lastScan = { start: started, end: ended }
  if (activeBetween(mod.activity, from, to)) await keepScan(io, snapshot)
}

/**
 * Starts the pane's rescan once the press has resolved. A press while one is
 * queued or running adds nothing: the scan it asks for is already coming.
 */
export function requestRescan(io: Port): void {
  const flight = (mod.rescanFlight ??= new SingleFlight(() => rescanSafely(io)))
  if (mod.rescanQueued || flight.isRunning) return
  mod.rescanQueued = true
  io.clock.after(0, () => {
    mod.rescanQueued = false
    void flight.request()
  })
}

/** One rescan; a run that throws leaves the header saying it failed rather than scanning forever. */
async function rescanSafely(io: Port): Promise<void> {
  try {
    await runRescan(io)
  } catch {
    await io.state.rescan.update((): RescanState => ({ phase: 'failed', reason: null })).catch(() => undefined)
  }
}

/**
 * Rescans the project incrementally through the wrapper's `scan`, then
 * reloads the dashboard so the pane draws the new snapshot. The header shows
 * `scanning…` meanwhile and the reason when it does not land.
 */
async function runRescan(io: Port): Promise<void> {
  if (mod.disabled) return
  await io.state.rescan.update((): RescanState => ({ phase: 'scanning', reason: null }))
  const started = await io.clock.now()
  const parsed = parseRescan(await exclusively(() => wrapper(io, 'scan', [], RESCAN_TIMEOUT_MS)))
  if (parsed?.status === 'ok' && typeof parsed.snapshot_id === 'string') await attributeScan(io, parsed.snapshot_id, started, await io.clock.now())
  if (parsed?.status === 'no-binary') {
    await io.state.rescan.update((): RescanState => ({ phase: 'idle', reason: null }))
    await disable(io)
    return
  }
  if (parsed?.status !== 'ok') {
    // A refused root is kept, so the pane can offer to allow it.
    const refused = parsed?.status === 'not-allowed' ? (parsed.refused_root ?? null) : null
    await io.state.rescan.update((): RescanState => ({ phase: 'failed', reason: rescanReason(parsed), refusedRoot: refused }))
    return
  }
  await refreshDashboard(io)
  await io.state.rescan.update((): RescanState => ({ phase: 'idle', reason: null }))
}

/**
 * Starts the live watcher when it should run and does not: switched on, not
 * refused, past any pause before a restart, and with a dashboard of an
 * allowed, scanned project to watch. Called at start-up and on every age
 * tick, so a watcher that ended is started again from here.
 */
export async function ensureWatcher(io: Port): Promise<void> {
  if (!mod.watchOn || mod.disabled || mod.watcher !== null || mod.watchStarting || mod.watchRefused) return
  if ((await io.clock.now()) < mod.watchRetryAt) return
  const d = await io.state.dashboard.read()
  if (d?.status !== 'ok' || d.project_root === null) return
  mod.watchStarting = true
  const gen = ++mod.watchGen
  // The loop runs for the session's life: never inside a hook's dispatch.
  io.clock.after(0, () => {
    mod.watchStarting = false
    void runWatcher(io, gen, d.project_root!).catch(() => undefined)
  })
}

/**
 * One watcher child, `knossos watch --shared` through the wrapper, read to
 * its end. Each event moves the header's phase; one that names a newer
 * snapshot reloads the dashboard (one load at a time). A watcher that ends
 * on its own after coming up is started again a few times; one that never
 * said a word is not offered here and is not asked again.
 */
async function runWatcher(io: Port, gen: number, root: string): Promise<void> {
  if (gen !== mod.watchGen) return
  let stream: AsyncGenerator<{ stream: string; text: string }, unknown>
  try {
    stream = io.process.spawn({ argv: ['sh', `${io.plugin.root}/hooks/scripts/knossos-run.sh`, 'watch', root, `--poll-ms=${mod.watchPollMs}`] })
  } catch {
    mod.watchRefused = true
    return
  }
  mod.watcher = stream
  await io.state.live.update((): LiveState => ({ phase: 'starting' }))
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
        await onWatchEvent(io, event)
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
    if (mod.watcher === null && !mod.watchStarting) await io.state.live.update(() => LIVE_OFF)
    return
  }
  mod.watchPending = false
  await io.state.live.update(() => LIVE_OFF)
  if (!heard) mod.watchRefused = true
  if (mod.watchRefused || mod.disabled) return
  // Started again by the age tick once the pause is over; past the last try, not at all.
  mod.watchFailures++
  mod.watchRetryAt = mod.watchFailures > WATCH_RESTARTS ? Number.POSITIVE_INFINITY : (await io.clock.now()) + WATCH_RESTART_MS * mod.watchFailures
}

/** What one watcher event changes: the phase, whether changes wait, and the dashboard when the snapshot moved. */
async function onWatchEvent(io: Port, event: WatchEvent): Promise<void> {
  if (event.status === 'no-binary') return disable(io)
  if (event.event === 'refused') mod.watchRefused = true
  if (event.event === 'ready' || event.event === 'following') mod.watchFailures = 0
  if (event.event === 'changes') mod.watchPending = true
  if (event.event === 'scan_completed' || event.event === 'absorbed' || event.event === 'stopped') mod.watchPending = false
  if (typeof event.pid === 'number' && Number.isInteger(event.pid) && event.pid > 1) mod.watcherPid = event.pid
  const before = await io.state.live.read()
  const after = liveAfter(event, before)
  if (after.phase !== before.phase || after.stale !== before.stale) await io.state.live.update(() => after)
  const snapshot = snapshotOf(event)
  const moved = snapshot !== null && snapshot !== mod.snapshot
  await attributeEvent(io, event, snapshot, moved)
  if (moved) {
    mod.snapshot = snapshot
    // Coalesced with any load in flight: the last to land is the newest.
    void refreshDashboard(io).catch(() => undefined)
  }
  // What changed since the session began: read once the watcher is up, and again after each scan it saw.
  if (moved || event.event === 'ready' || event.event === 'leading' || event.event === 'following') requestLedger(io)
}

/**
 * Whose changes a watcher event's scan took in. `scan_started` (or, while
 * following, `leader_scanning`) marks when a scan began; the watcher's own
 * `scan_completed` lands it, and a `snapshot` or `absorbed` that moves the
 * graph lands another writer's, begun at the mark or, unseen, a scan's time
 * before the poll that saw it.
 */
async function attributeEvent(io: Port, event: WatchEvent, snapshot: string | null, moved: boolean): Promise<void> {
  const now = await io.clock.now()
  if (event.event === 'scan_started' || event.event === 'leader_scanning') {
    mod.scanStartedAt = now
    return
  }
  const landed = event.event === 'scan_completed' || (moved && (event.event === 'snapshot' || event.event === 'absorbed'))
  if (!landed || snapshot === null) return
  const started = mod.scanStartedAt ?? (event.event === 'scan_completed' ? now : now - mod.watchPollMs - FOLLOWED_SCAN_MS)
  mod.scanStartedAt = null
  await attributeScan(io, snapshot, started, now)
}

/**
 * Waits, at most {@link WATCH_SETTLE_MAX_MS}, for the watcher to take the
 * turn's last edits in: until it has had a poll and its debounce to notice
 * them, and is neither scanning nor holding changes. The brief then finds
 * its files already in the graph and scans nothing itself.
 */
async function settleWatcher(io: Port): Promise<void> {
  const quiet = mod.watchPollMs + WATCH_DEBOUNCE_MS + 250
  const until = (await io.clock.now()) + WATCH_SETTLE_MAX_MS
  for (;;) {
    const now = await io.clock.now()
    const busy = (await io.state.live.read()).phase === 'scanning' || mod.watchPending
    if (now >= until || (!busy && now - mod.lastEditAt >= quiet)) return
    await sleep(io, 100)
  }
}
