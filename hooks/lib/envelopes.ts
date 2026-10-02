import type { BoundaryRef, ComponentDetail, Dashboard, FanIn, Related, TurnBrief, Violation } from '../../types'

// The envelope shapes are written once, in the plugin's contract, and re-exported
// here so the rest of the mod keeps importing them from this module.
export type { BoundaryRef, ComponentDetail, Dashboard, FanIn, Related, TurnBrief, Violation }

const BRIEF = new Set(['ok', 'not-allowed', 'missing', 'unscanned', 'scan-failed', 'error'])
const DASH = new Set(['ok', 'unscanned', 'error'])

const BRIEF_ARRAYS = ['changed_files', 'added_files', 'deleted_files', 'tests']
const BRIEF_OBJECTS = ['policy']
const DASH_ARRAYS = ['hubs', 'hotspots', 'trend', 'fan_in']
const DASH_OBJECTS = ['cycles', 'freshness']
const DETAIL = new Set(['ok', 'unscanned', 'not-found', 'ambiguous', 'error'])
const DETAIL_ARRAYS = ['candidates']
const DETAIL_OBJECTS = ['component']

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

/** "used by 2: Kernel, Console": one direction of a component's relationships. */
function relatedLine(label: string, related: Related): string {
  const head = `${label} ${countLabel(related.count, related.truncated)}`
  if (related.names.length === 0) return head
  return `${head}: ${related.names.join(', ')}${related.count > related.names.length ? ' …' : ''}`
}

/** A component detail from the wrapper's stdout; null for silence or anything unexpected. */
export function parseComponentDetail(stdout: string): ComponentDetail | null {
  return parse(stdout, DETAIL, DETAIL_ARRAYS, DETAIL_OBJECTS) as ComponentDetail | null
}

/** What the pane shows for one component, by the detail's status; `name` is what was asked for. */
export function detailLines(detail: ComponentDetail, name: string): string[] {
  const c = detail.component
  if (detail.status === 'ok' && c !== null) {
    return [
      `${c.kind} ${c.name}`,
      ...(c.path === null ? [] : [`at ${c.path}${c.line === null ? '' : `:${c.line}`}`]),
      ...(c.boundaries.length === 0 ? [] : [`boundaries: ${c.boundaries.join(', ')}`]),
      relatedLine('used by', c.used_by),
      relatedLine('uses', c.uses),
    ]
  }
  if (detail.status === 'not-found') return [`No component matched "${name}".`]
  if (detail.status === 'ambiguous') return [`"${name}" names more than one component: ${detail.candidates.join(', ')}`]
  if (detail.status === 'unscanned') return ['No Knossos data for this project. Scan it with knossos scan.']
  return [`knossos could not read ${name}.`]
}
