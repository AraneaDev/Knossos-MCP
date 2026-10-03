/**
 * The live watcher's side of the mod, kept pure: reading its event lines,
 * the phase each event moves it to, and the snapshot an event says the graph
 * is at. The render hook and the header read the phase; `register.tsx` owns
 * the child process and feeds these the lines it writes.
 */
import type { LiveState, WatchEvent } from '../../types'

export const LIVE_OFF: LiveState = { phase: 'off' }

/** The poll interval when none is set: measured on a 650-file project, see docs/capabilities/claude-code-mod.md. */
export const DEFAULT_WATCH_POLL_MS = 1_000
/** The shortest and longest poll interval the option accepts. */
export const MIN_WATCH_POLL_MS = 250
export const MAX_WATCH_POLL_MS = 60_000

/** The poll interval from the options: a whole number of milliseconds in range, else the default. */
export function watchPollMsOf(value: unknown): number {
  const n = Number(value)
  return Number.isInteger(n) && n >= MIN_WATCH_POLL_MS && n <= MAX_WATCH_POLL_MS ? n : DEFAULT_WATCH_POLL_MS
}

/** The most an unfinished line may grow before it is dropped: the watcher's lines are short. */
const LINE_MAX = 64 * 1024

/** One line as an event: a JSON object naming its `event` (or the wrapper's `no-binary`), else null. */
export function parseWatchEvent(line: string): WatchEvent | null {
  try {
    const value: unknown = JSON.parse(line)
    if (value === null || typeof value !== 'object' || Array.isArray(value)) return null
    const event = value as WatchEvent
    return typeof event.event === 'string' || event.status === 'no-binary' ? event : null
  } catch {
    return null
  }
}

/**
 * The events in what the watcher wrote so far: `rest` (the unfinished line
 * from the last piece) and `text` (the new piece) joined, each complete line
 * read, and the new unfinished line kept for the next piece. A piece may end
 * mid-line or hold several lines.
 */
export function watchLines(rest: string, text: string): { events: WatchEvent[]; rest: string } {
  const all = rest + text
  const cut = all.lastIndexOf('\n')
  const tail = cut < 0 ? all : all.slice(cut + 1)
  const events = cut < 0 ? [] : all.slice(0, cut).split('\n').map(parseWatchEvent).filter((e): e is WatchEvent => e !== null)
  return { events, rest: tail.length > LINE_MAX ? '' : tail }
}

/** The phase an event leaves the watcher in, from `current`. */
export function phaseAfter(event: WatchEvent, current: LiveState['phase']): LiveState['phase'] {
  switch (event.event) {
    case 'ready':
    case 'leading':
    case 'scan_completed':
    case 'absorbed':
      return 'live'
    case 'following':
      return 'following'
    case 'scan_started':
      return 'scanning'
    case 'refused':
    case 'stopped':
      return 'off'
    case 'error':
      // A retryable failure keeps watching (it backs off and tries again); `stopped` follows any other.
      return event.retryable === true ? (current === 'scanning' ? 'live' : current) : current
    default:
      return current
  }
}

/** The snapshot an event says the graph is at, or null when it says none. */
export function snapshotOf(event: WatchEvent): string | null {
  return typeof event.snapshot_id === 'string' && event.snapshot_id !== '' ? event.snapshot_id : null
}

/** Whether the watcher is there to keep the graph current: watching, scanning, or following another session's. */
export const isWatching = (live: LiveState): boolean => live.phase === 'live' || live.phase === 'scanning' || live.phase === 'following'
