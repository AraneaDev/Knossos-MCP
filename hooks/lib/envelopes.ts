import type { BoundaryRef, Dashboard, FanIn, TurnBrief, Violation } from '../../types'

// The envelope shapes are written once, in the plugin's contract, and re-exported
// here so the rest of the mod keeps importing them from this module.
export type { BoundaryRef, Dashboard, FanIn, TurnBrief, Violation }

const BRIEF = new Set(['ok', 'not-allowed', 'missing', 'unscanned', 'scan-failed', 'error'])
const DASH = new Set(['ok', 'unscanned', 'error'])

const BRIEF_ARRAYS = ['changed_files', 'added_files', 'deleted_files', 'tests']
const BRIEF_OBJECTS = ['policy']
const DASH_ARRAYS = ['hubs', 'hotspots', 'trend', 'fan_in']
const DASH_OBJECTS = ['cycles', 'freshness']

const isObject = (v: unknown): boolean => typeof v === 'object' && v !== null && !Array.isArray(v)

function parse(
  stdout: string,
  statuses: Set<string>,
  arrays: string[],
  objects: string[],
): Record<string, unknown> | null {
  try {
    const value: unknown = JSON.parse(stdout)
    if (typeof value !== 'object' || value === null) return null
    const status = (value as { status?: unknown }).status
    if (typeof status !== 'string' || !statuses.has(status)) return null
    const record = value as Record<string, unknown>
    if (status === 'ok' && !(arrays.every(k => Array.isArray(record[k])) && objects.every(k => isObject(record[k])))) {
      return null
    }
    return record
  } catch {
    return null
  }
}

/** A turn brief from the wrapper's stdout; null for silence or anything unexpected. */
export function parseTurnBrief(stdout: string): TurnBrief | null {
  return parse(stdout, BRIEF, BRIEF_ARRAYS, BRIEF_OBJECTS) as TurnBrief | null
}

/** A dashboard from the wrapper's stdout; null for silence or anything unexpected. */
export function parseDashboard(stdout: string): Dashboard | null {
  return parse(stdout, DASH, DASH_ARRAYS, DASH_OBJECTS) as Dashboard | null
}

/** A count as shown on the pane: "50+" when the producer stopped counting early. */
export function countLabel(n: number, truncated: boolean): string {
  return truncated ? `${n}+` : `${n}`
}
