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

/** How many related components a detail line names before it stops at an ellipsis. */
const NAMED = 5

type Related = { component?: { canonical_name?: unknown; display_name?: unknown } }

/** "used by 2: Kernel, Console": one direction of a component's relationships, each name once. */
function relatedLine(label: string, edges: unknown, truncated: boolean): string {
  const list = Array.isArray(edges) ? (edges as Related[]) : []
  const names = [
    ...new Set(
      list.map(e => e?.component?.display_name ?? e?.component?.canonical_name).filter((n): n is string => typeof n === 'string'),
    ),
  ]
  const head = `${label} ${countLabel(names.length, truncated)}`
  if (names.length === 0) return head
  return `${head}: ${names.slice(0, NAMED).join(', ')}${names.length > NAMED ? ' …' : ''}`
}

/**
 * What the pane shows for one component, from `inspect-component --json`:
 * the envelope's summary, then where the component is and who it touches.
 * Null for silence or anything without a summary; an unmatched or ambiguous
 * name is its summary alone, which already says so.
 */
export function componentDetail(stdout: string): string[] | null {
  let value: unknown
  try {
    value = JSON.parse(stdout)
  } catch {
    return null
  }
  if (!isObject(value)) return null
  const { summary, data, evidence } = value as { summary?: unknown; data?: unknown; evidence?: unknown }
  if (typeof summary !== 'string') return null
  const { component, limits } = (isObject(data) ? data : {}) as { component?: unknown; limits?: { truncation_reasons?: unknown } }
  if (!isObject(component)) return [summary]
  const c = component as { kind?: unknown; boundaries?: unknown; incoming?: unknown; outgoing?: unknown }
  const reasons = Array.isArray(limits?.truncation_reasons) ? (limits.truncation_reasons as unknown[]) : []
  const place = Array.isArray(evidence) && isObject(evidence[0]) ? (evidence[0] as { path?: unknown; start_line?: unknown }) : null
  const where = typeof place?.path === 'string' ? ` · ${place.path}${typeof place.start_line === 'number' ? `:${place.start_line}` : ''}` : ''
  const lines = [summary, `${typeof c.kind === 'string' ? c.kind : 'component'}${where}`]
  const boundaries = Array.isArray(c.boundaries)
    ? (c.boundaries as { name?: unknown }[]).map(b => b?.name).filter((n): n is string => typeof n === 'string')
    : []
  if (boundaries.length > 0) lines.push(`boundaries: ${boundaries.join(', ')}`)
  lines.push(relatedLine('used by', c.incoming, reasons.includes('incoming_relationship_limit')))
  lines.push(relatedLine('uses', c.outgoing, reasons.includes('outgoing_relationship_limit')))
  return lines
}
