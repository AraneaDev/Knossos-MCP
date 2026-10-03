export type FanIn = { path: string; dependent_files: number; boundaries: string[]; top_dependents?: string[] }
export type BoundaryRef = { id: string; name: string; source: string }
export type Violation = {
  policy_id: string
  source: string
  target: string
  source_boundaries: BoundaryRef[]
  target_boundaries: BoundaryRef[]
}
/** `no-binary` comes from the wrapper, not knossos: no binary to run, so the mod turns itself off. */
export type TurnBrief = {
  status: 'ok' | 'not-allowed' | 'missing' | 'unscanned' | 'scan-failed' | 'error' | 'no-binary'
  project_root: string | null
  project_id: string | null
  snapshot_id: string | null
  scanned_at: number | null
  scan_ms: number | null
  reason: string | null
  roots_file: string | null
  /** With `not-allowed`: the root to allow, the path itself or the ancestor project root that would be scanned. */
  refused_root: string | null
  path: string
  changed_files: string[]
  added_files: string[]
  deleted_files: string[]
  impact: Record<string, FanIn>
  tests: { path: string; distance: number }[]
  /** Violations this turn introduced; `truncated` when the check hit its cap or time limit, so `total` is a bound. */
  policy: { status: string; total: number; violations: Violation[]; truncated: boolean }
}
/** A component the pane lists: `name` to show, `canonical_name` to look it up by. */
export type Listed = { name: string; canonical_name: string; kind: string }
export type Dashboard = {
  status: 'ok' | 'unscanned' | 'error' | 'no-binary'
  path: string
  project_root: string | null
  project_id: string | null
  snapshot_id: string | null
  freshness: { state: string; age_seconds: number | null; drift_files: number }
  hubs: (Listed & { in_degree: number; out_degree: number; cross_boundary_degree: number })[]
  /** The degree walk stopped at a node, edge or time limit: hubs and hotspots rank only what it reached. */
  hubs_truncated: boolean
  hubs_truncation_reasons: string[]
  hotspots: (Listed & { score: number })[]
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

/** One direction of a component's relationships: distinct names, `truncated` when the count is a floor. */
export type Related = { count: number; truncated: boolean; names: string[] }
export type ComponentDetail = {
  status: 'ok' | 'unscanned' | 'not-found' | 'ambiguous' | 'error' | 'no-binary'
  path: string
  name: string
  project_id: string | null
  snapshot_id: string | null
  component: {
    name: string
    kind: string
    path: string | null
    line: number | null
    boundaries: string[]
    used_by: Related
    uses: Related
  } | null
  candidates: string[]
}

/** The pane's detail for one component: loading, or done with its lines (null when knossos said nothing). */
export type DetailState = { snapshot_id: string | null; name: string; lines: string[] | null; phase: 'loading' | 'done' }

export type JobState = { phase: 'idle' | 'scanning' | 'failed'; lastAttemptAt: number | null }

/** When the dashboard was last stored (mod clock, ms), and whether the latest refresh since failed. */
export type RefreshState = { fetchedAt: number | null; failed: boolean }

/** The component the pane shows: `name` is what it is looked up by, `label` what the pane prints. */
export type Inspected = { name: string; label: string }

export type KnossosView = { inspect: Inspected | null; isBandHidden: boolean }

declare module 'claude-code' {
  interface PluginState {
    knossos: {
      brief: TurnBrief | null
      dashboard: Dashboard | null
      job: JobState
      view: KnossosView
      detail: DetailState | null
      refresh: RefreshState
    }
  }
}
