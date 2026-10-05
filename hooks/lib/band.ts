import type { JobState, TurnBrief } from '../../types'
import { boundaryLabel, NO_HUES } from './palette'
import type { Hues } from './palette'

export type { JobState }
/** `copy`: a command the band offers to copy, whole, where its text could only name part of it. */
export type BandModel = { tone: 'normal' | 'warn' | 'alert'; text: string; showDetails: boolean; copy?: string } | null

/** 12s, 14m, 3h. */
export function formatAge(ms: number): string {
  const s = Math.max(0, Math.round(ms / 1000))
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.round(s / 60)}m`
  return `${Math.round(s / 3600)}h`
}

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`

/** How many boundaries the band names before it counts the rest. */
const REACH_NAMED = 2

/**
 * The turn's figures, most telling first: files and dependents, deletions,
 * violations, tests. The boundaries the dependents sit in come apart, as
 * `reach`: they go last, after the age, since a narrow band cuts from the end.
 */
function figures(b: TurnBrief, declared: ReadonlySet<string>, hues: Hues): { body: string | null; reach: string } {
  const files = b.changed_files.length + b.added_files.length
  const dependents = Object.values(b.impact).reduce((sum, f) => sum + f.dependent_files, 0)
  const parts: string[] = []
  if (files > 0) parts.push(`${plural(files, 'file', 'files')} → ${plural(dependents, 'dependent', 'dependents')}`)
  if (b.deleted_files.length > 0) parts.push(`${b.deleted_files.length} deleted`)
  if (b.policy.total > 0) parts.push(plural(b.policy.total, 'policy violation', 'policy violations'))
  if (b.tests.length > 0) parts.push(plural(b.tests.length, 'test', 'tests'))
  // Short labels, each once, the boundary holding the most dependents first.
  const weight = new Map<string, number>()
  for (const f of Object.values(b.impact)) {
    // With boundaries declared, only those: the inferred ones and the one spanning the repository say little here.
    for (const name of declared.size === 0 ? f.boundaries : f.boundaries.filter(n => declared.has(n))) {
      const label = boundaryLabel(name, hues)
      if (label !== '') weight.set(label, (weight.get(label) ?? 0) + f.dependent_files)
    }
  }
  const labels = [...weight.entries()].sort((x, y) => y[1] - x[1] || x[0].localeCompare(y[0])).map(([label]) => label)
  const more = labels.length - REACH_NAMED
  const reach = labels.length === 0 ? '' : ` · reaching ${labels.slice(0, REACH_NAMED).join(', ')}${more > 0 ? ` +${more}` : ''}`
  return { body: parts.length === 0 ? null : parts.join(' · '), reach }
}

const quote = (s: string) => `'${s.replace(/'/g, `'\\''`)}'`

/**
 * What the band says, or null to let the engine draw nothing. `declared`
 * holds the project's declared boundaries (from the dashboard): when there
 * are any, the band names only those its dependents reach.
 *
 * Units: `scanned_at` is Unix seconds (it comes from PHP's time()); `now` and
 * `lastAttemptAt` are the mod clock in milliseconds.
 */
export function bandModel(brief: TurnBrief | null, job: JobState, now: number, declared: ReadonlySet<string> = new Set(), hues: Hues = NO_HUES): BandModel {
  if (brief === null) {
    if (job.phase === 'scanning') return { tone: 'normal', text: 'knossos · scanning…', showDetails: false }
    if (job.phase === 'failed') return { tone: 'warn', text: 'knossos · scan failed', showDetails: false }
    return null
  }
  if (brief.status === 'not-allowed') {
    // The roots file the brief actually read (a baked data directory moves it) and the root that has to be allowed.
    // The command does not fit a band: the band names the root's last part and copies the command whole;
    // its details open the pane, which offers to allow the root.
    const refused = brief.refused_root ?? brief.path
    const file = brief.roots_file ? `KNOSSOS_ROOTS_FILE=${quote(brief.roots_file)} ` : ''
    const name = refused.replace(/\/+$/, '').split('/').pop() || refused
    return { tone: 'warn', text: `knossos · not allowed: ${name}`, showDetails: true, copy: `${file}knossos allow-root ${quote(refused)} --execute` }
  }
  if (brief.status === 'scan-failed') {
    const why = brief.reason ? `: ${brief.reason}` : ''
    return { tone: 'warn', text: `knossos · scan failed${why}`, showDetails: false }
  }
  if (brief.status !== 'ok') return null
  const { body, reach } = figures(brief, declared, hues)
  const tone = brief.policy.total > 0 ? 'alert' : 'normal'
  const age = brief.scanned_at === null ? null : formatAge(now - brief.scanned_at * 1000)
  if (job.phase === 'scanning') {
    const last = body ? ` · last: ${body}${age === null ? '' : ` · as of ${age} ago`}${reach}` : ''
    return { tone, text: `knossos · scanning…${last}`, showDetails: true }
  }
  if (job.phase === 'failed') {
    const from = age === null ? '' : `, figures from ${age} ago`
    return { tone: 'warn', text: `knossos · scan failed${from}${body ? ` · ${body}${reach}` : ''}`, showDetails: true }
  }
  if (body === null) return null
  return { tone, text: `knossos · ${body}${age === null ? '' : ` · as of ${age} ago`}${reach}`, showDetails: true }
}
