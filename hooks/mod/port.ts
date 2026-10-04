import type { EngineInterface, PluginState } from 'claude-code'

import type { Feedback, SessionChanges } from '../../types'
import { ledgerChanges, ownTimeline } from '../lib/changes'
import { FLASH_MS } from '../lib/flash'
import type { Flash } from '../lib/flash'
import { FEEDBACK_MS } from '../lib/layout'
import { isWatching, LIVE_OFF } from '../lib/live'
import { relativise } from '../lib/paths'
import { mod, stopWatcher } from './state'

/**
 * One value of the session state as the mod's modules reach it: read, or
 * changed by a function of what it holds. The engine's `read` and `update`
 * take only an atom declared in the hooks module itself, so `register.tsx`
 * declares every atom and hands each one over as a cell.
 */
export type Cell<T> = { read(): Promise<T>; update(change: (value: T) => T): Promise<T> }

/** Every value the mod keeps in the session state, by its key in the contract (`types/index.d.ts`). */
type Cells = { [K in keyof PluginState['knossos']]-?: Cell<PluginState['knossos'][K]> }

type Engine = EngineInterface

/**
 * The engine as the mod's modules reach it: the calls the mod makes, each
 * passed straight through to `$`, and the session state as {@link Cells}.
 *
 * The engine follows `$` only into functions declared in the hooks module
 * itself, never across an import, and refuses `$.<noun>` used as a value:
 * `register.tsx` builds one of these from the hook's own `$` on every
 * dispatch (`portOf`), spelling each call out, and the modules take it
 * instead of `$`.
 */
export type Port = {
  clock: Pick<Engine['clock'], 'now' | 'after' | 'every'>
  ui: Pick<Engine['ui'], 'log' | 'toast' | 'copy' | 'focus' | 'panes' | 'invalidate' | 'open' | 'close'>
  session: Pick<Engine['session'], 'root' | 'id' | 'append'>
  process: Pick<Engine['process'], 'run' | 'spawn'>
  fs: Pick<Engine['fs'], 'stat'>
  store: Pick<Engine['store'], 'get' | 'set'>
  config: Pick<Engine['config'], 'list'>
  command: Pick<Engine['command'], 'register'>
  tool: Pick<Engine['tool'], 'register'>
  prompt: Pick<Engine['prompt'], 'submit'>
  plugin: { root: string }
  state: Cells
}

/** How long the signal to a stopped watcher may take. */
const KILL_TIMEOUT_MS = 5_000

/** The most paths kept as the session's own edits, and the most snapshots kept as its own scans. */
const EDITS_KEPT = 1_000
const SCANS_KEPT = 1_000

/** Why the Changes tab lists only the turn briefs' files. */
const NO_WATCHER = "No live watcher, so only what this session's turns reported is listed."

/**
 * Where a path lands with every link followed, so a checkout reached through
 * a linked directory still lies under the project root (a real path). A file
 * that is not there (deleted) lands through its directory; a path that cannot
 * be placed at all is kept as given.
 */
export async function placed(io: Port, path: string): Promise<string> {
  const own = await io.fs.stat(path, { resolve: true }).catch(() => undefined)
  if (own?.realPath !== undefined) return own.realPath
  const cut = path.lastIndexOf('/')
  if (cut < 0) return path
  const dir = await io.fs.stat(cut === 0 ? '/' : path.slice(0, cut), { resolve: true }).catch(() => undefined)
  return dir?.realPath === undefined ? path : `${dir.realPath.replace(/\/+$/, '')}/${path.slice(cut + 1)}`
}

/** Turns the mod off for the session: the wrapper found no knossos binary to run. */
export async function disable(io: Port): Promise<void> {
  if (mod.disabled) return
  mod.disabled = true
  mod.ticker?.cancel()
  mod.ticker = null
  // Nor a debounced search or lookup: a loader that would run after this finds the mod off and reads nothing.
  mod.searchTimer?.cancel()
  mod.searchTimer = null
  mod.loadTimers.forEach(timer => timer.cancel())
  mod.loadTimers.clear()
  // Nothing to register a command for: no timed retry runs on, and no turn end asks again.
  mod.registerGen++
  mod.registerTimer?.cancel()
  mod.registerTimer = null
  await endWatcher(io).catch(() => undefined)
  io.ui.log('knossos: no knossos binary found; the band and pane are off for this session.')
  io.ui.invalidate('ui.render')
}

/** Runs the wrapper in `dir` (the session's root by default); its stdout, or '' for any failure (the wrapper's contract is silence). */
export async function wrapper(io: Port, sub: string, args: string[], timeoutMs: number, dir?: string): Promise<string> {
  try {
    const root = dir ?? (await io.session.root())
    const script = `${io.plugin.root}/hooks/scripts/knossos-run.sh`
    const { stdout } = await io.process.run(['sh', script, sub, root, ...args], { timeoutMs })
    return stdout
  } catch {
    return ''
  }
}

/** Lights `keys` for {@link FLASH_MS} from `now`, beside whatever is still lit; nothing to light changes nothing. */
export async function light(io: Port, keys: string[], now: number): Promise<void> {
  if (keys.length === 0) return
  await io.state.flash.update((f): Flash => ({ keys: [...new Set([...(f !== null && now < f.until ? f.keys : []), ...keys])], until: now + FLASH_MS }))
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
export async function shownChanges(io: Port, root: string | null): Promise<SessionChanges> {
  const turns = await io.state.changes.read()
  // Without the ledger the timeline holds the scans the session's own turns and rescans made.
  if (mod.watcher === null || !isWatching(await io.state.live.read())) return { ...turns, fallback: NO_WATCHER, timeline: ownTimeline(await io.state.sessionScans.read()) }
  const ledger = await io.state.sessionLedger.read()
  if (ledger === null || ledger.since !== (await io.state.sessionStart.read())) return turns
  const edits = (await io.state.sessionEdits.read()).map(p => (p.startsWith('/') && root !== null ? (relativise(root, p) ?? p) : p))
  return ledgerChanges(ledger, turns, new Set(edits), await io.state.sessionScans.read(), await io.state.sessionBegan.read())
}

/** What the footer says for {@link FEEDBACK_MS} after an action: the mod's clock decides when it fades (see `tickAge`). */
export async function say(io: Port, text: string, tone: Feedback['tone']): Promise<void> {
  const until = (await io.clock.now()) + FEEDBACK_MS
  await io.state.feedback.update((): Feedback => ({ text, tone, until }))
}

/** Keeps a path the session's own edit tools wrote, for the Changes tab's "this session". */
export async function keepEdit(io: Port, path: string): Promise<void> {
  await io.state.sessionEdits.update(edits => (edits.includes(path) || edits.length >= EDITS_KEPT ? edits : [...edits, path]))
}

/** Keeps a snapshot as one the session's own work produced: every file its scan changed is this session's. */
export async function keepScan(io: Port, snapshot: string): Promise<void> {
  await io.state.sessionScans.update(scans => (scans.includes(snapshot) ? scans : [...scans, snapshot].slice(-SCANS_KEPT)))
}

/**
 * Stops the live watcher ({@link stopWatcher}) and says so: the header
 * stops saying live at once. `return()` on the stream ends the child only
 * once the read it waits on ends (its next line, up to a heartbeat away), so
 * the child is also signalled now: its lock is free for the next session at
 * once, not 15 s later.
 */
export async function endWatcher(io: Port): Promise<void> {
  const pid = stopWatcher()
  if (pid !== null) void io.process.run(['kill', '-TERM', String(pid)], { timeoutMs: KILL_TIMEOUT_MS }).catch(() => undefined)
  await io.state.live.update(() => LIVE_OFF)
}

/** Resolves after `ms` on the mod's clock. */
export const sleep = (io: Port, ms: number): Promise<void> => new Promise(resolve => void io.clock.after(ms, () => resolve()))
