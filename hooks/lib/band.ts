import type { JobState, TurnBrief } from '../../types'

export type { JobState }
export type BandModel = { tone: 'normal' | 'warn' | 'alert'; text: string; showDetails: boolean } | null

/** 12s, 14m, 3h. */
export function formatAge(ms: number): string {
  const s = Math.max(0, Math.round(ms / 1000))
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.round(s / 60)}m`
  return `${Math.round(s / 3600)}h`
}

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`

function figures(b: TurnBrief): string | null {
  const files = b.changed_files.length + b.added_files.length
  const dependents = Object.values(b.impact).reduce((sum, f) => sum + f.dependent_files, 0)
  const boundaries = [...new Set(Object.values(b.impact).flatMap(f => f.boundaries))]
  const parts: string[] = []
  if (files > 0) parts.push(`${plural(files, 'file', 'files')} → ${plural(dependents, 'dependent', 'dependents')}`)
  if (b.deleted_files.length > 0) parts.push(`${b.deleted_files.length} deleted`)
  if (boundaries.length > 0) parts.push(boundaries.join(', '))
  if (b.tests.length > 0) parts.push(plural(b.tests.length, 'test', 'tests'))
  if (b.policy.total > 0) parts.push(plural(b.policy.total, 'policy violation', 'policy violations'))
  return parts.length === 0 ? null : parts.join(' · ')
}

const quote = (s: string) => `'${s.replace(/'/g, `'\\''`)}'`

/**
 * What the band says, or null to let the engine draw nothing.
 *
 * Units: `scanned_at` is Unix seconds (it comes from PHP's time()); `now` and
 * `lastAttemptAt` are the mod clock in milliseconds.
 */
export function bandModel(brief: TurnBrief | null, job: JobState, now: number): BandModel {
  if (brief === null) {
    if (job.phase === 'scanning') return { tone: 'normal', text: 'knossos · scanning…', showDetails: false }
    if (job.phase === 'failed') return { tone: 'warn', text: 'knossos · scan failed', showDetails: false }
    return null
  }
  if (brief.status === 'not-allowed') {
    // The roots file the brief actually read (a baked data directory moves it) and the root that has to be allowed.
    const file = brief.roots_file ? `KNOSSOS_ROOTS_FILE=${quote(brief.roots_file)} ` : ''
    const root = quote(brief.refused_root ?? brief.path)
    return { tone: 'warn', text: `knossos · not an allowed root: ${file}knossos allow-root ${root} --execute`, showDetails: false }
  }
  if (brief.status === 'scan-failed') {
    const why = brief.reason ? `: ${brief.reason}` : ''
    return { tone: 'warn', text: `knossos · scan failed${why}`, showDetails: false }
  }
  if (brief.status !== 'ok') return null
  const body = figures(brief)
  const tone = brief.policy.total > 0 ? 'alert' : 'normal'
  const age = brief.scanned_at === null ? null : formatAge(now - brief.scanned_at * 1000)
  if (job.phase === 'scanning') {
    const last = body ? ` · last: ${body}${age === null ? '' : ` · as of ${age} ago`}` : ''
    return { tone, text: `knossos · scanning…${last}`, showDetails: true }
  }
  if (job.phase === 'failed') {
    const from = age === null ? '' : `, figures from ${age} ago`
    return { tone: 'warn', text: `knossos · scan failed${from}${body ? ` · ${body}` : ''}`, showDetails: true }
  }
  if (body === null) return null
  return { tone, text: `knossos · ${body}${age === null ? '' : ` · as of ${age} ago`}`, showDetails: true }
}
