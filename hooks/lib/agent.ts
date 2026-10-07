/**
 * What the mod tells the model on its own account beyond the notes after a
 * Read, an edit or a turn: the note after a `git commit`.
 *
 * Pure: the figures in, one compact text out, every list cut to a few and
 * the whole bounded, since the model reads all of it.
 */

/** How many of each list a note names before it counts the rest. */
const NAMED = 5
/** The longest a note runs: past it, it is cut and says so. */
const TEXT_MAX = 2_000

/** A list named up to {@link NAMED}, with how many more there are. */
const named = (items: string[], total = items.length): string => `${items.slice(0, NAMED).join(', ')}${total > Math.min(NAMED, items.length) ? `, and ${total - Math.min(NAMED, items.length)} more` : ''}`

/** `text` cut to {@link TEXT_MAX}, saying so when it was. */
const bounded = (text: string): string => (text.length <= TEXT_MAX ? text : `${text.slice(0, TEXT_MAX - 13)}… (cut short)`)

/**
 * Whether a shell command's output holds git's own line for a commit,
 * `[branch abc1234] subject` (`[main (root-commit) abc1234]`,
 * `[detached HEAD abc1234]`). Only the last resort of
 * {@link committedSince}: a reprinted line (a `git log`, a saved CI log)
 * or a commit in another repository prints one too, and `--quiet` prints
 * none.
 */
export function madeCommit(output: string): boolean {
  return /^\[[^[\]\n]+ [0-9a-f]{7,64}\] [^\n]/m.test(output)
}

/** How many of HEAD's reflog entries {@link committedSince} reads back, newest first. */
export const REFLOG_READ = 20

/**
 * A reflog entry for a commit made in the repository: `commit`, `commit
 * (amend)`, `commit (initial)`, `commit (merge)`, `cherry-pick`, `revert`,
 * and a merge that made a merge commit (`merge topic: Merge made by the
 * 'ort' strategy.`), never a fast-forward. A checkout, a reset, a rebase or
 * a pull moves HEAD without the session committing anything.
 */
const COMMIT_ENTRY = /^(?:(?:commit(?: \([a-z]+\))?|cherry-pick|revert): |merge [^:]*: (?!Fast-forward))/

/**
 * Whether a commit was made in the project's repository while a shell
 * command ran, from HEAD before and after it (`git rev-parse`: the commit,
 * '' on a branch with no commit yet, null when the project is not in a
 * repository or git did not answer) and HEAD's reflog after it (`reflog`,
 * `<sha>\x1f<subject>` lines, newest first; '' when there is none).
 *
 * HEAD that did not move made no commit, whatever the command printed: a
 * `git commit` in a string, a dry run, nothing to commit, a reprinted
 * commit line, a commit in another repository. HEAD that moved made one when
 * a reflog entry newer than the HEAD before it is a commit's: a merge
 * commit (which prints no `[branch sha]` line), a `--quiet` commit and a
 * commit piped through `tail` are all seen, a checkout or a reset is not.
 * Without a reflog, git's own line in `output` decides.
 */
export function committedSince(before: string | null, after: string | null, reflog: string, output: string): boolean {
  if (before === null || after === null || after === '' || before === after) return false
  const entries = reflog
    .split('\n')
    .filter(line => line !== '')
    .slice(0, REFLOG_READ)
    .map(line => {
      const at = line.indexOf('\x1f')
      return at < 0 ? { sha: line, subject: '' } : { sha: line.slice(0, at), subject: line.slice(at + 1) }
    })
  if (entries.length === 0) return madeCommit(output)
  const back = entries.findIndex(e => e.sha === before)
  return (back < 0 ? entries : entries.slice(0, back)).some(e => COMMIT_ENTRY.test(e.subject))
}

/** The dashboard figures {@link stillReported} judges by: its policy check, and the snapshots it reaches back over. */
export type PolicyView = {
  snapshot_id: string | null
  trend: { snapshot_id: string }[]
  policy?: { status: string; truncated: boolean; total: number; items: { source: string; target: string }[] }
}

/**
 * The violations of `violations` (`source → target`) the dashboard's policy
 * check still reports: one fixed since a turn introduced it is not the
 * session's to carry any more. A violation is dropped only when that check
 * is at least as new as the brief that recorded it (`recordedAt`, the
 * brief's snapshot by violation): the dashboard's snapshot is that one, or
 * its trend (oldest first, ending at its snapshot) passes through it. A
 * dashboard older than the brief, one whose reload failed or has not landed
 * yet, never saw the violation, so its silence says nothing; nor does a
 * violation recorded without a snapshot. All of them when the check cannot
 * say (not evaluated, or its list cut short).
 */
export function stillReported(violations: string[], recordedAt: Readonly<Record<string, string>> | undefined, d: PolicyView): string[] {
  const policy = d.policy
  if (policy === undefined || policy.status !== 'evaluated' || policy.truncated || policy.items.length < policy.total) return violations
  const reported = new Set(policy.items.map(v => `${v.source} → ${v.target}`))
  const reached = new Set([...d.trend.map(t => t.snapshot_id), ...(d.snapshot_id === null ? [] : [d.snapshot_id])])
  return violations.filter(v => {
    const at = recordedAt?.[v]
    return reported.has(v) || at === undefined || !reached.has(at)
  })
}

/** A cycle as the dashboard lists it: its size and members. */
type ListedCycle = { size: number; members: string[] }

/**
 * The cycles new since the session began, against what the graph held then:
 * `keys` the listed cycles by sorted members, `complete` whether that list
 * was every cycle, `exact` whether the count was a whole count (absent in
 * state recorded before it existed, and then taken as whole).
 *
 * A cycle is named new only against a complete list. The count that grew is
 * said only against a whole count: a search that stopped early counted what
 * it reached, and every cycle past that would read as new.
 */
export function cyclesSinceStart<C extends ListedCycle>(start: { count: number; keys: string[]; complete: boolean; exact?: boolean } | null, now: { count: number; largest: C[] }): { count: number; fresh: C[] } {
  if (start === null) return { count: 0, fresh: [] }
  const keys = new Set(start.keys)
  const fresh = start.complete ? now.largest.filter(c => !keys.has([...c.members].sort().join('\u0000'))) : []
  return { count: start.exact === false ? fresh.length : Math.max(now.count - start.count, fresh.length), fresh }
}

/**
 * The note after a commit: what this session's changes leave behind, as far
 * as the graph says: boundary-policy violations its turns introduced that
 * the policy check still reports, the files it changed that no test reaches,
 * and cycles that are new since the session began. A commit need not hold
 * all of them, so the note speaks of the session's changes, not of the
 * commit. Null when there is nothing to say.
 */
export function commitNote(violations: string[], untested: string[], cycles: { count: number; chains: string[] }): string | null {
  const parts: string[] = []
  if (violations.length > 0) parts.push(`${violations.length} boundary-policy ${violations.length === 1 ? 'violation' : 'violations'} this session introduced (${named(violations)})`)
  if (untested.length > 0) parts.push(`${untested.length} changed ${untested.length === 1 ? 'file' : 'files'} no test reaches (${named(untested)})`)
  if (cycles.count > 0) parts.push(`${cycles.count} dependency ${cycles.count === 1 ? 'cycle' : 'cycles'} new since the session began${cycles.chains.length > 0 ? ` (${named(cycles.chains, cycles.count)})` : ''}`)
  if (parts.length === 0) return null
  return bounded(`knossos: this session's changes carry ${parts.join('; ')}. Check them before you push.`)
}
