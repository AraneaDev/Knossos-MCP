/**
 * Whose change a watcher scan took in: the session's, or someone else's.
 *
 * The scan ledger says which files each scan changed, never who changed
 * them. The session's tools change files from every loop (the main one and
 * each subagent's) and by every route: the edit tools, but also a shell
 * command (`sed -i`, a heredoc, `git mv`, a formatter's `--write`). So the
 * mod keeps when its session's tool calls that can write ran, and a scan
 * whose changes were noticed while one ran (or within a moment after it ended) is the
 * session's; one whose changes were noticed while the session was idle came
 * from outside it: the person's editor, another terminal, another session.
 *
 * Pure, on the mod's clock (milliseconds): `register.tsx` feeds it the calls'
 * starts and ends and asks it about each scan.
 */

/**
 * Tools that wait rather than write: a call to one is in flight while the
 * session waits on someone (the person's answer, a subagent's run, a
 * background task), so it would make every change made meanwhile the
 * session's. A subagent's own tool calls reach the mod on their own.
 */
export const WAITING_TOOLS: ReadonlySet<string> = new Set([
  'Agent',
  'Task',
  'AskUserQuestion',
  'TaskOutput',
  'TaskStop',
  'Monitor',
  'SendMessage',
  'EnterPlanMode',
  'ExitPlanMode',
])

/**
 * Tools that only read: a call to one changes no file, so the time it runs is
 * not the session at work on the project, and a change another writer made
 * meanwhile is not the session's. The mod's own `knossos_context` tool is
 * matched by its name in whatever plugin namespace the engine lists it.
 */
export const READING_TOOLS: ReadonlySet<string> = new Set(['Read', 'Grep', 'Glob'])

/** How long after a call ends a change it made may still turn up, on top of the watcher's poll and debounce. */
export const ACTIVITY_GRACE_MS = 1_500
/**
 * How long another session's watcher may take to scan once it noticed a
 * change, when its start was not seen: a typical incremental scan of a
 * project of some hundreds of files, with room to spare.
 */
export const FOLLOWED_SCAN_MS = 10_000
/** How long ended calls are kept: far longer than any window asks about. */
const KEEP_MS = 10 * 60_000
/** The most ended calls kept. */
const KEEP_SPANS = 500

/** When the session's calls ran: those running now by id with their start, and the ended ones, oldest first. */
export type Activity = { running: Map<string, number>; spans: { start: number; end: number }[] }

export const noActivity = (): Activity => ({ running: new Map(), spans: [] })

/** Whether a call to `tool` counts as the session at work: one that can write, not one that waits or only reads. */
export const counts = (tool: string): boolean => !WAITING_TOOLS.has(tool) && !READING_TOOLS.has(tool) && tool !== 'knossos_context' && !tool.endsWith('__knossos_context')

/** A call `id` started at `now`. */
export function begin(activity: Activity, id: string, now: number): void {
  activity.running.set(id, now)
}

/** Call `id` ended at `now`: kept as a span, the oldest let go past the limits. */
export function finish(activity: Activity, id: string, now: number): void {
  const start = activity.running.get(id)
  if (start === undefined) return
  activity.running.delete(id)
  activity.spans.push({ start, end: now })
  const keepFrom = now - KEEP_MS
  while (activity.spans.length > KEEP_SPANS || (activity.spans.length > 0 && activity.spans[0]!.end < keepFrom)) activity.spans.shift()
}

/** Whether any of the session's calls ran at some moment from `from` to `to`. */
export function activeBetween(activity: Activity, from: number, to: number): boolean {
  for (const start of activity.running.values()) if (start <= to) return true
  return activity.spans.some(s => s.start <= to && s.end >= from)
}

/**
 * How long before a scan started a change it took in may have been made:
 * the watcher notices a change within a poll, waits out its debounce, then
 * scans; plus the grace.
 */
export const lookbackMs = (pollMs: number, debounceMs: number): number => pollMs + debounceMs + ACTIVITY_GRACE_MS

/**
 * The moments a scan's changes may have been made in. `started` is when the
 * scan began (when the watcher said so, or the best guess), `ended` when it
 * landed. A change made while the scan before it ran is noticed only after
 * that one ended, so when the scan before ended inside the window the
 * window reaches back to where that one began.
 */
export function scanWindow(started: number, ended: number, lookback: number, before: { start: number; end: number } | null): { from: number; to: number } {
  const from = started - lookback
  return { from: before !== null && before.end >= from ? Math.min(from, before.start) : from, to: ended }
}
