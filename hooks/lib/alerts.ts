/**
 * What a scan introduced that the person should hear about while they
 * work: a dependency cycle or a policy violation the graph did not hold
 * before. Each becomes a toast, once.
 *
 * Pure: the dashboard before a scan and the one after in, the alerts out,
 * each keyed by what it is about (`cycle:<members>`, `policy:<rule> <from>
 * <to>`) so the mod says it once a session however often it comes and
 * goes. Only between two snapshots of the same project: the first
 * dashboard of a session, or one of another project, is where the person
 * starts from, not news. When a scan brings more than a few at once, the
 * rest are said as one count.
 */
import type { Dashboard } from '../../types'
import { shortName } from './rows'

/** One thing to say: its key (said once) and its words. */
export type Alert = { key: string; text: string }

/** The most alerts a scan says one by one; past them, one more says how many else. */
export const ALERTS_MAX = 3

/** A cycle's key: its members, in order of name. */
const cycleKey = (members: string[]): string => `cycle:${[...members].sort().join('\u0000')}`

/** A cycle as a toast names it: its first members by short name, closed back on the first. */
function chain(members: string[]): string {
  const names = members.slice(0, 4).map(shortName)
  return `${names.join(' → ')}${members.length > 4 ? ' → …' : ''} → ${names[0] ?? ''}`
}

/**
 * The alerts for what `after` holds that `before` did not: new listed
 * cycles, then new listed violations, leaving out what `told` already
 * holds. A count that grew past what the lists show is said as a count.
 */
export function freshAlerts(before: Dashboard | null, after: Dashboard, told: ReadonlySet<string> = new Set()): Alert[] {
  if (before === null || before.status !== 'ok' || after.status !== 'ok') return []
  if (before.project_id !== after.project_id || before.snapshot_id === after.snapshot_id) return []
  const alerts: Alert[] = []
  const had = new Set(before.cycles.largest.map(c => cycleKey(c.members)))
  const cycles = after.cycles.largest.filter(c => !had.has(cycleKey(c.members)))
  for (const c of cycles) alerts.push({ key: cycleKey(c.members), text: `knossos: a new dependency cycle of ${c.size}: ${chain(c.members)}` })
  if (cycles.length === 0 && after.cycles.count > before.cycles.count) {
    alerts.push({ key: `cycles:${after.cycles.count}`, text: `knossos: the graph now has ${after.cycles.count} dependency cycles, ${after.cycles.count - before.cycles.count} more than before` })
  }
  const policy = (d: Dashboard) => (d.policy?.status === 'evaluated' ? d.policy : null)
  const was = policy(before)
  const now = policy(after)
  if (was !== null && now !== null) {
    const key = (v: { policy_id: string; source: string; target: string }) => `policy:${v.policy_id} ${v.source} ${v.target}`
    const seen = new Set(was.items.map(key))
    const fresh = now.items.filter(v => !seen.has(key(v)))
    for (const v of fresh) alerts.push({ key: key(v), text: `knossos: a new policy violation (${v.policy_id}): ${shortName(v.source)} → ${shortName(v.target)}` })
    if (fresh.length === 0 && now.total > was.total) alerts.push({ key: `violations:${now.total}`, text: `knossos: ${now.total - was.total} new policy ${now.total - was.total === 1 ? 'violation' : 'violations'}, ${now.total} in all` })
  }
  const unsaid = alerts.filter(a => !told.has(a.key))
  if (unsaid.length <= ALERTS_MAX) return unsaid
  const rest = unsaid.slice(ALERTS_MAX - 1)
  return [...unsaid.slice(0, ALERTS_MAX - 1), { key: rest.map(a => a.key).join('\u0001'), text: `knossos: and ${rest.length} more new cycles or violations; the Issues and Cycles tabs list them` }]
}

/** The keys an alert stands for: a summed-up one stands for each it counts. */
export const alertKeys = (alert: Alert): string[] => alert.key.split('\u0001')
