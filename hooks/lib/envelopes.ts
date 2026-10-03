import type { AllowRoot, BoundaryRef, ComponentDetail, Dashboard, FanIn, FileDetail, Listed, Related, Rescan, SessionLedger, TurnBrief, Violation } from '../../types'

// The envelope shapes are written once, in the plugin's contract, and re-exported
// here so the rest of the mod keeps importing them from this module.
export type { AllowRoot, BoundaryRef, ComponentDetail, Dashboard, FanIn, FileDetail, Listed, Related, Rescan, SessionLedger, TurnBrief, Violation }

// `no-binary` is the wrapper's own answer when there is nothing to run; every envelope may be it.
const BRIEF = new Set(['ok', 'not-allowed', 'missing', 'unscanned', 'scan-failed', 'error', 'no-binary'])
const DASH = new Set(['ok', 'unscanned', 'error', 'no-binary'])

const BRIEF_ARRAYS = ['changed_files', 'added_files', 'deleted_files', 'tests']
const BRIEF_OBJECTS = ['policy']
const DASH_ARRAYS = ['hubs', 'hotspots', 'trend', 'fan_in']
const DASH_OBJECTS = ['cycles', 'freshness']
const DETAIL = new Set(['ok', 'unscanned', 'not-found', 'ambiguous', 'error', 'no-binary'])
const DETAIL_ARRAYS = ['candidates']
const DETAIL_OBJECTS = ['component']
const FILE = new Set(['ok', 'unscanned', 'not-found', 'error', 'no-binary'])
const FILE_OBJECTS = ['file']
const LEDGER_ARRAYS = ['tests']
const LEDGER_OBJECTS = ['files']
const RESCAN = new Set(['ok', 'not-allowed', 'missing', 'unscanned', 'scan-failed', 'error', 'no-binary'])

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

/** The changes since the session began from the wrapper's stdout; null for silence or anything unexpected. */
export function parseSessionLedger(stdout: string): SessionLedger | null {
  const parsed = parse(stdout, DASH, LEDGER_ARRAYS, LEDGER_OBJECTS) as SessionLedger | null
  return parsed === null || (parsed.status === 'ok' && typeof parsed.complete !== 'boolean') ? null : parsed
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

/** A file's detail from the wrapper's stdout; null for silence or anything unexpected. */
export function parseFileDetail(stdout: string): FileDetail | null {
  const parsed = parse(stdout, FILE, [], FILE_OBJECTS) as FileDetail | null
  // The lists are drawn as they come: an `ok` without them is as unexpected as one without the file.
  const file = parsed?.status === 'ok' ? parsed.file : null
  if (file !== null && file !== undefined && !(Array.isArray(file.dependents?.items) && Array.isArray(file.components?.items))) return null
  return parsed
}

/** What the pane says instead of a file's detail, by the status knossos answered with; `path` is the file asked about. */
export function fileDetailLines(detail: FileDetail, path: string): string[] {
  if (detail.status === 'not-found') return [`${path} is not in the graph: never scanned, ignored, or gone before the snapshot.`]
  if (detail.status === 'unscanned') return ['No Knossos data for this project. Scan it with knossos scan.']
  return [`knossos could not read ${path}.`]
}

/** A rescan's answer from the wrapper's stdout; null for silence or anything unexpected. */
export function parseRescan(stdout: string): Rescan | null {
  return parse(stdout, RESCAN, [], []) as Rescan | null
}

/** Why a rescan did not land, in a few words for the pane's header. */
export function rescanReason(rescan: Rescan | null): string {
  if (rescan === null) return 'knossos said nothing'
  if (rescan.status === 'not-allowed') return 'not an allowed root'
  if (rescan.status === 'missing') return 'the project is gone'
  if (rescan.status === 'unscanned') return 'never scanned'
  if (rescan.status === 'scan-failed') return rescan.reason ?? 'the scan failed'
  return 'knossos could not run it'
}

/**
 * The allow-root answer from the wrapper's stdout: the root now granted (or
 * already present), `no-binary`, or null for silence, a preview that wrote
 * nothing, or anything unexpected.
 */
export function parseAllowRoot(stdout: string): AllowRoot | null {
  let value: unknown
  try {
    value = JSON.parse(stdout)
  } catch {
    return null
  }
  if (!isObject(value)) return null
  const v = value as Record<string, unknown>
  if (v.status === 'no-binary') return { status: 'no-binary' }
  if (typeof v.path !== 'string' || typeof v.added !== 'boolean' || v.preview === true) return null
  return { path: v.path, added: v.added, ...(typeof v.roots_file === 'string' ? { roots_file: v.roots_file } : {}) }
}
