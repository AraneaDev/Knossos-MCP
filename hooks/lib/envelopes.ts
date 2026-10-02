export type FanIn = { path: string; dependent_files: number; boundaries: string[]; top_dependents?: string[] }
export type BoundaryRef = { id: string; name: string; source: string }
export type Violation = {
  policy_id: string
  source: string
  target: string
  source_boundaries: BoundaryRef[]
  target_boundaries: BoundaryRef[]
}
export type TurnBrief = {
  status: 'ok' | 'not-allowed' | 'missing' | 'unscanned' | 'scan-failed' | 'error'
  project_root: string | null
  project_id: string | null
  snapshot_id: string | null
  scanned_at: number | null
  scan_ms: number | null
  reason: string | null
  roots_file: string | null
  path: string
  changed_files: string[]
  added_files: string[]
  deleted_files: string[]
  impact: Record<string, FanIn>
  tests: { path: string; distance: number }[]
  policy: { status: string; total: number; violations: Violation[] }
}
export type Dashboard = {
  status: 'ok' | 'unscanned' | 'error'
  path: string
  project_root: string | null
  project_id: string | null
  snapshot_id: string | null
  freshness: { state: string; age_seconds: number | null; drift_files: number }
  hubs: { name: string; kind: string; in_degree: number; out_degree: number; cross_boundary_degree: number }[]
  hotspots: { name: string; kind: string; score: number }[]
  dead_code_candidates: number
  dead_code_truncated: boolean
  cycles: {
    count: number
    truncated: boolean
    truncation_reasons: string[]
    largest: { size: number; members: string[] }[]
  }
  trend: { snapshot_id: string; cycles: number; max_degree: number }[]
  fan_in: FanIn[]
  fan_in_truncated: boolean
}

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
