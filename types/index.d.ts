/**
 * One file's fan-in: how many files depend on it and the boundaries those
 * dependents sit in (`boundaries`, where a change reaches), and the file's
 * own boundary label (`boundary`, where it sits; null when none of its
 * components is in a boundary, absent from a knossos older than the mod).
 * In a turn brief, `tests` says how many test files reach the file, where
 * that is known.
 */
export type FanIn = { path: string; dependent_files: number; boundaries: string[]; boundary?: string | null; top_dependents?: string[]; tests?: number }
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
  /** Whether the brief scanned itself (absent from an older knossos): false when a watcher's scan already held its files. */
  scanned?: boolean
  reason: string | null
  roots_file: string | null
  /** With `not-allowed`: the root to allow, the path itself or the ancestor project root that would be scanned. */
  refused_root: string | null
  path: string
  changed_files: string[]
  added_files: string[]
  deleted_files: string[]
  impact: Record<string, FanIn>
  /** `js_runner` on a JavaScript test: the runner its nearest package.json names, null when that is unknown. */
  tests: { path: string; distance: number; js_runner?: JsRunner | null }[]
  /** Whether `tests` is cut: more reach the files than it lists, or the search ran out of time (absent from an older knossos). */
  tests_truncated?: boolean
  /** Violations this turn introduced; `truncated` when the check hit its cap or time limit, so `total` is a bound. */
  policy: { status: string; total: number; violations: Violation[]; truncated: boolean }
}
/** A component the pane lists: `name` to show, `canonical_name` to look it up by. */
export type Listed = { name: string; canonical_name: string; kind: string }
/**
 * A ranked component's degrees and the boundary the pane labels it with.
 * Optional: a knossos older than the mod sends hotspots without them.
 */
export type Ranked = Listed & {
  /** How many other files reference it; absent from a knossos older than the pane's wide tables. */
  dependent_files?: number
  /** The files that reference it most, at most three (absent from a knossos older than the pane's hover cards). */
  top_dependents?: string[]
  boundary?: string | null
  /** Where it is declared, relative to the project root; absent from a knossos older than the mod's live watcher. */
  path?: string | null
  line?: number | null
  in_degree?: number
  out_degree?: number
  cross_boundary_degree?: number
}
/** A cycle member as the pane colours it; `name` to show, `canonical_name` to look it up by. */
export type CycleNode = Listed & { boundary: string | null }
/** A dead-code candidate with where it is declared. */
export type DeadCode = Listed & {
  boundary: string | null
  reachability: string
  confidence: string
  path: string | null
  line: number | null
}
/** What the project holds; `*_truncated` when a breakdown lists fewer categories than exist. */
export type Summary = {
  components: number
  kinds: { kind: string; count: number }[]
  kinds_truncated: boolean
  files: number
  languages: { language: string; files: number }[]
  languages_truncated: boolean
}
export type Diagnostic = { severity: string; code: string; message: string; path: string | null; line: number | null }
/** A JavaScript test runner the mod can hand a command for. */
export type JsRunner = 'vitest' | 'jest'

/**
 * A declared policy as a rule: `from` may not depend on `deny`, or (with
 * `allow`) only on itself and `allow`; `@unassigned` stands for code in no
 * boundary; `edge_kinds` narrows it to those dependencies when not empty.
 */
export type PolicyRule = { id: string; from: string; deny: string[]; allow: string[]; edge_kinds: string[] }
export type PolicyViolation = {
  policy_id: string
  source: string
  source_kind: string
  source_boundary: string | null
  target: string
  target_kind: string
  target_boundary: string | null
  path: string | null
  line: number | null
}
export type Dashboard = {
  status: 'ok' | 'unscanned' | 'error' | 'no-binary'
  path: string
  project_root: string | null
  project_id: string | null
  snapshot_id: string | null
  /**
   * `drifted` (absent from an older knossos): the first files that changed
   * since the snapshot, by path, each with how (`changed`, `added`,
   * `deleted`) and its own boundary label; `drifted_truncated` when there are
   * more than it names.
   */
  freshness: { state: string; age_seconds: number | null; drift_files: number; drifted?: Drifted[]; drifted_truncated?: boolean }
  hubs: (Ranked & { in_degree: number; out_degree: number; cross_boundary_degree: number })[]
  /** The degree walk stopped at a node, edge or time limit: hubs and hotspots rank only what it reached. */
  hubs_truncated: boolean
  hubs_truncation_reasons: string[]
  hotspots: (Ranked & { score: number })[]
  dead_code_candidates: number
  dead_code_truncated: boolean
  cycles: {
    count: number
    truncated: boolean
    truncation_reasons: string[]
    /** `nodes` and `nodes_truncated` are optional: a knossos older than the mod sends names only. */
    largest: { size: number; members: string[]; nodes?: CycleNode[]; nodes_truncated?: boolean }[]
  }
  /**
   * Oldest first, ending at the active snapshot. `dead_code` (the candidates
   * nothing references), `diagnostics` (errors and warnings) and
   * `components` are absent from a knossos older than the Overview's charts.
   */
  trend: { snapshot_id: string; cycles: number; max_degree: number; dead_code?: number; diagnostics?: number; components?: number }[]
  fan_in: FanIn[]
  fan_in_truncated: boolean
  // Optional from here on: a knossos older than the mod sends none of them.
  /** The first dead-code candidates; `dead_code_candidates` counts them all. */
  dead_code?: DeadCode[]
  summary?: Summary
  /**
   * The largest boundaries with their member counts, and (absent from an
   * older knossos) the name of every declared one, at most 200
   * (`declared_truncated` past that), whether or not the short list has room.
   */
  boundaries?: { items: { name: string; source: string; members: number }[]; truncated: boolean; declared?: string[]; declared_truncated?: boolean }
  diagnostics?: { total: number; errors: number; warnings: number; infos: number; items: Diagnostic[] }
  /**
   * The files a change is riskiest in: lines times dependent files, the
   * highest first (absent from an older knossos).
   */
  complexity_hotspots?: { path: string; language: string; lines: number; dependent_files: number; score: number }[]
  /**
   * The files holding a PHP function longer than the project's
   * `max_php_function_lines` budget (`source`, at its root), the longest
   * first, `total` counting them all; null when the project declares no such
   * budget, absent from an older knossos.
   */
  over_budget?: { source: string; max_function_lines: number; total: number; files: { path: string; functions: number; longest: number; line: number | null }[] } | null
  /**
   * `truncated` when the check stopped at its edge or time limit, so `total`
   * is a floor. `rules`, `boundaries` and `files` (absent from an older
   * knossos): the declared policies with boundary names, each policed
   * boundary, and each file a rule binds through a boundary no path prefix
   * places, with the boundaries it binds it through; `files_truncated` when
   * that list stopped at its cap.
   */
  policy?: {
    status: string
    total: number
    truncated: boolean
    truncation_reasons: string[]
    items: PolicyViolation[]
    rules?: PolicyRule[]
    /**
     * Each policed boundary by name: the ids of the rules that bind it, the
     * path prefixes that place its files, and `listed` when some of its files
     * are placed otherwise (by namespace) and so come only through `files`.
     */
    boundaries?: Record<string, { rules: string[]; path_prefixes: string[]; listed: boolean }>
    files?: Record<string, string[]>
    files_truncated?: boolean
  }
  boundary_matrix?: BoundaryMatrix
  /**
   * How the newest snapshot moved since the one retained before it (`against`),
   * each figure the newer less the older; null with one snapshot only.
   */
  deltas?: Deltas | null
  /**
   * Every component the hub ranking could hold (tests and external code left
   * out), bucketed by how many depend on it; `to` null for the open top
   * bucket, `truncated` when the walk stopped early.
   */
  in_degree?: { buckets: InDegreeBucket[]; truncated: boolean }
}

/** How the newest snapshot's figures moved since `against`, the snapshot retained before it. */
export type Deltas = { against: string; components: number; cycles: number; max_degree: number; dead_code: number; diagnostics: number }

/** One bucket of the in-degree histogram: the in-degrees it spans (`to` null for no upper end) and how many components. */
export type InDegreeBucket = { from: number; to: number | null; components: number }

/**
 * How much each boundary depends on each other one: `cells[from][to]` counts
 * the dependency edges from a component labelled `boundaries[from]` to one
 * labelled `boundaries[to]`. `forbidden` lists the [from, to] cells a declared
 * policy forbids; `truncated` when the count stopped at its edge or time
 * limit, so the cells are floors; `boundaries_truncated` when more boundaries
 * label components than the axes hold.
 */
export type BoundaryMatrix = {
  boundaries: string[]
  members: number[]
  boundaries_truncated: boolean
  cells: number[][]
  forbidden: [number, number][]
  /** Every component that carries a label, the axes' or not (absent from an older knossos). */
  labelled?: number
  /** The strongest cells off the diagonal, by axis index, most edges first (absent from an older knossos). */
  flows?: { from: number; to: number; edges: number; forbidden: boolean }[]
  edges: number
  truncated: boolean
  truncation_reasons: string[]
}

/** A component on the other end of some relationships: how many of them, and its boundary. */
export type Counterpart = Listed & { boundary: string | null; edges: number }
/**
 * One direction of a component's relationships: how many distinct components,
 * the first distinct names, the most connected few (`items`, absent from an
 * older knossos), and `truncated` when the counts are floors.
 */
export type Related = { count: number; truncated: boolean; names: string[]; items?: Counterpart[] }
export type ComponentDetail = {
  status: 'ok' | 'unscanned' | 'not-found' | 'ambiguous' | 'error' | 'no-binary'
  path: string
  name: string
  project_id: string | null
  snapshot_id: string | null
  component: {
    name: string
    display_name?: string
    kind: string
    path: string | null
    line: number | null
    /** The one boundary the pane labels it with, as the dashboard does. */
    boundary?: string | null
    boundaries: string[]
    used_by: Related
    uses: Related
    annotations?: { kind: string; value: string }[]
  } | null
  candidates: string[]
}

/** A file that drifted since the snapshot, and how. */
export type Drifted = { path: string; change: 'changed' | 'added' | 'deleted'; boundary: string | null }

/**
 * One file as `file-detail` answers: the files that depend on it (exact
 * `count`, the boundaries they sit in, the most connected few with their
 * relationship counts) and the components it declares (the most used few,
 * each with how many components in other files use it). `truncated` when a
 * list holds fewer than its count.
 */
export type FileDetail = {
  status: 'ok' | 'unscanned' | 'not-found' | 'error' | 'no-binary'
  path: string
  project_id: string | null
  snapshot_id: string | null
  file: {
    path: string
    language: string
    lines: number | null
    boundary: string | null
    dependents: { count: number; truncated: boolean; boundaries: string[]; items: { path: string; edges: number; boundary: string | null }[] }
    /** The files it depends on, most connected first; absent from an older knossos. */
    uses?: { count: number; truncated: boolean; items: { path: string; edges: number; boundary: string | null }[] }
    components: { count: number; truncated: boolean; items: (Listed & { line: number | null; boundary: string | null; used_by: number })[] }
  } | null
}

/**
 * The pane's detail for one component or, with `file`, one file (`name` its
 * project-relative path): loading, or done with what knossos answered (null
 * when it said nothing), in `detail` for a component and `fileDetail` for a file.
 */
export type DetailState = { snapshot_id: string | null; name: string; file?: true; detail: ComponentDetail | null; fileDetail?: FileDetail | null; phase: 'loading' | 'done' }

export type JobState = { phase: 'idle' | 'scanning' | 'failed'; lastAttemptAt: number | null }

/** When the dashboard was last stored (mod clock, ms), and whether the latest refresh since failed. */
export type RefreshState = { fetchedAt: number | null; failed: boolean }

/** The component (or, with `file`, the file) the pane shows: `name` is what it is looked up by, `label` what the pane prints. */
export type Inspected = {
  name: string
  label: string
  file?: true
  /** Opened from the session's changes: the detail shows the file's change since the session began, below its dependents. */
  changed?: true
}

/**
 * The `session-diff` subcommand's answer: how one file changed since the
 * commit the session began at. `diff` holds unified-diff hunks only (no
 * headers), at most 2,000 lines; `lines` is how many the whole diff had.
 * `kind` is `renamed` with `from` and `to` for a file git moved, `absent`
 * for one git never had, `unchanged` for one put back as it was.
 */
export type SessionDiff = {
  status: 'ok' | 'no-git' | 'unknown-rev' | 'error' | 'no-binary'
  file?: string
  kind?: 'changed' | 'added' | 'deleted' | 'renamed' | 'unchanged' | 'absent' | null
  from?: string | null
  to?: string | null
  binary?: boolean
  diff?: string
  lines?: number
  truncated?: boolean
  /** Git would not print the diff within its bounds, or failed. */
  unreadable?: boolean
}

/** The commit the session began at, from `session-head`: its id, or that the project has no git history. */
export type SessionRev = { status: 'ok'; rev: string } | { status: 'no-git' }

/** Where the project's checkout stands, as the header names it: the commit and the branch (null on a detached head); null when it is no git checkout. */
export type GitHead = { rev: string; branch: string | null } | null

/**
 * The `boundary-couplings` subcommand's answer: one heat map cell spelled
 * out, the component pairs from boundary `from` to boundary `to` with the
 * most dependency edges first, and how many edges the cell holds.
 */
export type BoundaryCouplings = {
  status: 'ok' | 'unscanned' | 'error' | 'no-binary'
  from?: string
  to?: string
  snapshot_id?: string | null
  edges: number
  couplings: { source: Listed; target: Listed; edges: number }[]
  truncated: boolean
}

/**
 * The `graph-search` subcommand's answer: the components and files whose
 * name holds the typed letters in order, the closest first (at most 20);
 * `truncated` when a candidate read stopped at its bound.
 */
export type GraphSearch = {
  status: 'ok' | 'unscanned' | 'error' | 'no-binary'
  query?: string
  results: { type: 'component' | 'file'; name: string; canonical_name: string; kind: string; path: string | null; line: number | null; boundary: string | null }[]
  truncated: boolean
}

/** The finder's search: what was typed, and the answer for the query last read (`for`), null until one lands. */
export type SearchState = { query: string; for: string | null; phase: 'idle' | 'searching'; answer: GraphSearch | null }

/** One component (or file) as the Branch tab lists it. */
export type BranchItem = { name: string; canonical_name: string; kind: string; path: string | null; line: number | null; boundary: string | null }

/**
 * The `branch-diff` subcommand's answer: the checked-out branch against the
 * retained snapshot at (or nearest before) its merge base with the default
 * branch. `base.match`: `exact`, `before` (`commits` before the merge base)
 * or `after` (`commits` of the branch already in it: a partial comparison,
 * status `no-snapshot`). Each list counts all and names the first few.
 * `files`: what the branch's commits touched since the merge base, the
 * files the graph holds the most depended on first; it needs no snapshot,
 * and is null on the default branch or when git cannot say.
 */
export type BranchDiff = {
  status: 'ok' | 'no-snapshot' | 'on-default' | 'no-git' | 'no-default' | 'unscanned' | 'error' | 'no-binary'
  branch?: string | null
  default_branch?: string | null
  merge_base?: { rev: string; at: number } | null
  ahead?: number | null
  base?: { snapshot_id: string; rev: string; at: string; match: 'exact' | 'before' | 'after'; commits: number } | null
  comparison?: {
    crossing: { count: number; items: { source: BranchItem; target: BranchItem }[] }
    cycles: { count: number; items: { size: number; members: BranchItem[] }[] }
    hubs: { count: number; items: { component: BranchItem; before: number; after: number }[] }
    dead_code: { count: number; items: BranchItem[] }
    violations: { count: number; items: { policy_id: string; source: string; source_kind: string; target: string; target_kind: string }[]; truncated: boolean } | null
  } | null
  files?: { count: number; items: { path: string; added: number; deleted: number; dependents: number; boundary: string | null }[] } | null
}

/** The Branch tab's comparison as last read, for the snapshot it was read at; loading until it lands. */
export type BranchState = { snapshot: string | null; phase: 'loading' | 'done'; answer: BranchDiff | null }

/**
 * The `churn` subcommand's answer: the files changed most in the last `days`
 * days (by commits, at most `commits` read, ending at `head`) times the
 * files depending on each, the highest score first. `no-git` when git is not
 * there or did not answer; `unreadable` when git named the commit but could
 * not print its log, so nothing was read.
 */
export type Churn = {
  status: 'ok' | 'no-git' | 'unreadable' | 'unscanned' | 'error' | 'no-binary'
  days?: number
  head?: string | null
  commits?: number
  truncated?: boolean
  files: { path: string; commits: number; dependents: number; score: number; boundary: string | null }[]
}

/** The Churn tab's answer as last read, for the commit (`head`) it was read at; loading until it lands. */
export type ChurnState = { head: string | null; phase: 'loading' | 'done'; answer: Churn | null }

/** One component a ring or a route names. */
export type RingItem = { name: string; canonical_name: string; kind: string; path?: string | null; line?: number | null; boundary: string | null }

/**
 * The `blast-radius` subcommand's answer: what depends on one component in
 * rings (one hop, two hops, three and more), each with how many of its
 * components a test reaches, the first few of them (the untested first) and
 * the test files that reach the ring, nearest first.
 */
export type BlastRadius = {
  status: 'ok' | 'not-found' | 'unscanned' | 'error' | 'no-binary'
  component: RingItem | null
  rings: { hop: number; count: number; tested: number; items: (RingItem & { tested: boolean })[]; tests: { count: number; items: { path: string; hop: number }[] } }[]
  truncated: boolean
}

/** The rings of the component the detail shows, read for its name and snapshot; loading until they land. */
export type RingsState = { name: string; snapshot: string | null; phase: 'loading' | 'done'; answer: BlastRadius | null }

/**
 * The `path-between` subcommand's answer: the routes from one component to
 * another, the strongest first, each its components and the hops between
 * them (kind, and where the hop is written). `reversed` when only the other
 * way had a route: `from` and `to` stay as asked.
 */
export type PathBetween = {
  status: 'ok' | 'not-found' | 'ambiguous' | 'unscanned' | 'error' | 'no-binary'
  unresolved?: 'from' | 'to'
  from: RingItem | null
  to: RingItem | null
  reversed: boolean
  routes: { nodes: RingItem[]; hops: { kind: string; confidence: string; path: string | null; line: number | null }[] }[]
  truncated: boolean
}

/** The route the pane draws: its two ends as picked, and the answer read for them at a snapshot. */
export type RouteState = { from: string; to: string; snapshot: string | null; phase: 'loading' | 'done'; answer: PathBetween | null }

/** The `annotate` subcommand's answer: the note previewed (`executed` false) or recorded, and the one it replaces; `refused` with why. */
export type Annotate = {
  status: 'ok' | 'refused' | 'unscanned' | 'error' | 'no-binary'
  component?: string
  kind?: string
  action?: string
  executed?: boolean
  value?: string | null
  previous?: string | null
  reason?: string
}

/**
 * A note being added to the component the detail shows: typed (`editing`),
 * checked with knossos (`previewing`), waiting for the person's yes
 * (`confirming`), being recorded (`saving`), or refused with why (`failed`).
 */
export type NoteState = { component: string; phase: 'editing' | 'previewing' | 'confirming' | 'saving' | 'failed'; value: string; previous: string | null; reason: string | null }

/** The cell the pane spelled out, loading until its answer lands (null: nothing answered). */
export type CouplingState = { snapshot: string | null; from: string; to: string; phase: 'loading' | 'done'; answer: BoundaryCouplings | null }

/** What the pane says for a moment after an action in it: `✓ copied`, `✗ no editor`; drawn until `until` (mod clock, ms). */
export type Feedback = { text: string; tone: 'ok' | 'alert'; until: number }

/** The diff the file detail shows: of `name` against `rev` at `snapshot`, loading until it lands (null: nothing answered). */
export type DiffState = { name: string; rev: string; snapshot: string | null; phase: 'loading' | 'done'; diff: SessionDiff | null }

/**
 * How far the file detail's diff is opened, for the diff of `name` against `rev` at `snapshot`: the
 * hunks shown whole (`open`, by index) and the first hunk shown (`from`). A diff read again starts closed.
 */
export type DiffFold = { name: string; rev: string; snapshot: string | null; open: number[]; from: number }

/** The pane's tabs, in their hotkey order (1 to 8). */
export type PaneTab = 'overview' | 'hubs' | 'boundaries' | 'cycles' | 'issues' | 'changes' | 'branch' | 'churn'

/** How a file this session touched stands now: the last turn that named it decides. */
export type TouchStatus = 'changed' | 'added' | 'deleted'

/**
 * Everything this session's turn briefs reported, accumulated: each file
 * touched with its latest dependents and the boundaries they are in, every
 * test that reached a change (by its nearest distance), and the policy
 * violations introduced, by `source → target`. `truncated` when a cap
 * stopped a list growing, so the counts are floors.
 */
export type SessionChanges = {
  turns: number
  /** `boundaries` are the dependents' (where a change reaches), `boundary` the file's own label; `tests` how many test files reach it, absent when not known. */
  files: Record<string, { status: TouchStatus; dependents: number; boundaries: string[]; boundary: string | null; tests?: number }>
  tests: Record<string, number>
  /** The runner of each JavaScript test as its brief said; absent or null when unknown. */
  js_runners?: Record<string, JsRunner | null>
  violations: string[]
  /** The snapshot of the latest turn brief that reported each violation, by `source → target`; absent for one reported without a snapshot. */
  violation_snapshots?: Record<string, string>
  truncated: boolean
  /**
   * Set when the files and tests come from the scan ledger (every change in
   * the project since the session began, whoever made it) rather than from
   * the turn briefs: where each file's change came from, `session` for the
   * session's own edit tools (main loop or subagent), `outside` otherwise.
   */
  origins?: Record<string, 'session' | 'outside'>
  /** What the view says about where its files start (no live watcher, a ledger that does not reach back far enough, an approximate start); absent when there is nothing to say. */
  fallback?: string
  /** Where the ledger's files start when it is not where the session began, as the header says it (`since 2026-10-04 09:14`). */
  since?: string
  /** The session's scans, oldest first, each by whose changes it took in; `timeline_truncated` when older ones were left out. */
  timeline?: { snapshot: string; origin: 'session' | 'outside' }[]
  timeline_truncated?: boolean
}

/**
 * The `session-changes` subcommand's answer: every file a recorded scan
 * changed since the session's first snapshot, as the graph holds it now, with
 * its dependents and boundaries, and the tests that reach them. `complete`
 * false (and no files) when the ledger cannot account for every scan since.
 */
export type SessionLedger = {
  status: 'ok' | 'unscanned' | 'error' | 'no-binary'
  since?: string
  snapshot_id?: string | null
  complete: boolean
  /** `scans`: the snapshots the recorded scans that changed the file produced, the newest last; `tests`: how many test files reach it, where known (both absent from an older knossos). */
  files: Record<string, { status: TouchStatus; dependents: number; boundaries: string[]; boundary: string | null; scans?: string[]; tests?: number }>
  files_truncated: boolean
  tests: { path: string; distance: number; js_runner?: JsRunner | null }[]
  tests_truncated: boolean
  /** The recorded scans since the session began, oldest first: each one's snapshot, when (Unix seconds) and how many files it changed; `merged` for scans the ledger keeps as one (absent from an older knossos). */
  scans?: { snapshot_id: string; at: number; files: number; merged?: number }[]
  scans_truncated?: boolean
  /** With `complete` false: the oldest snapshot the ledger still answers for and when (Unix seconds); the files are the changes since it. Null or absent when there is none (or from an older knossos). */
  reached?: { snapshot_id: string; at: number } | null
  /** Whether the session began among scans the ledger keeps merged, so a file changed just before it may be listed. */
  start_approximate?: boolean
}

/** The degree the hubs tab sorts by, most first. */
export type HubSort = 'in' | 'out' | 'cross'

/**
 * What the pane shows: a component's detail, or a tab with the row under the
 * selection marker (an index into that tab's list), and the key help line.
 * The hubs tab keeps its filter text, whether its field is open, and its sort.
 */
export type KnossosView = {
  inspect: Inspected | null
  isBandHidden: boolean
  tab: PaneTab
  selected: number
  showKeys: boolean
  filter: string
  filtering: boolean
  sort: HubSort
  /** Whether the files drifted since the snapshot are listed under the header (absent: not). */
  drift?: boolean
  /** Where the tab's marker stood when a detail opened from it: `b` puts it back there. */
  opened?: number
  /** On the Boundaries tab, the boundary the marked one's heat map cell runs to (its top dependency when absent or gone). */
  target?: string
  /** On the Cycles tab, the cycles whose folded middle the person opened, by index. */
  unfolded?: number[]
  /** On the Hubs tab, the in-degree range the list is narrowed to (an Overview bucket's), `to` null for no upper end. */
  degree?: { from: number; to: number | null } | null
  /** Whether the finder is open over the pane (`f`): its field and what it found. */
  finding?: boolean
  /** The component a route starts at, while the finder picks where it ends (`p`). */
  picking?: Inspected | null
  /** The route on show, from one component to another, instead of a tab or a detail; `back` is what `b` returns to. */
  route?: { from: Inspected; to: Inspected; index: number; back: Inspected | null } | null
}

/** The `scan` subcommand's answer: an incremental rescan the person asked for from the pane. */
export type Rescan = {
  status: 'ok' | 'not-allowed' | 'missing' | 'unscanned' | 'scan-failed' | 'error' | 'no-binary'
  snapshot_id?: string | null
  reason?: string | null
  /** With `not-allowed`: the root to allow and the roots file the refusal read. */
  refused_root?: string | null
  roots_file?: string | null
}

/**
 * The pane's rescan: running, or failed with why (null when knossos said
 * nothing). `refusedRoot` is the root a `not-allowed` answer named.
 */
export type RescanState = { phase: 'idle' | 'scanning' | 'failed'; reason: string | null; refusedRoot?: string | null }

/**
 * The live watcher (`knossos watch --shared`) as the pane and band show it:
 * `starting` until it says it is ready, `live` while it watches,
 * `scanning` while its scan runs, `following` while another session's
 * watcher leads (this one only reads the snapshot), `off` when there is
 * none (switched off, refused, not offered, or stopped). `stale` while
 * following a leader whose heartbeat stopped (its watcher is stuck or gone
 * quiet).
 */
export type LiveState = { phase: 'off' | 'starting' | 'live' | 'scanning' | 'following'; stale?: true }

/** One event of the live watcher, one JSON object per line on its stdout; `no-binary` comes from the wrapper. */
export type WatchEvent = {
  /** `leader_scanning`: while following, the leading session's watcher began a scan. */
  event?: 'ready' | 'refused' | 'following' | 'leading' | 'changes' | 'scan_started' | 'scan_completed' | 'absorbed' | 'snapshot' | 'leader_scanning' | 'overflow' | 'error' | 'stopped'
  status?: string
  snapshot_id?: string | null
  retryable?: boolean
  /** On `error`: `scan_timeout` when a scan ran past its time limit (the watcher stops after 3 in a row). */
  code?: string
  reason?: string
  /** The watcher's own process id (on `ready`, `leading`, `following`): what a session end signals. */
  pid?: number
  /** On `following`: whether the leader's heartbeat stopped, and whether the leader was started by this same process. */
  stale?: boolean
  same_process?: boolean
}

/** The `allow-root` subcommand's answer: the root granted and the roots file it now stands in. */
export type AllowRoot = { status?: 'no-binary'; path?: string; roots_file?: string; added?: boolean }

/**
 * The pane's allow-root action for `root`: asking the person to confirm, then
 * running, then done or failed with why. `idle` draws only the offer.
 */
export type AllowState = { phase: 'idle' | 'confirming' | 'running' | 'done' | 'failed'; root: string | null; reason: string | null }

declare module 'claude-code' {
  interface PluginState {
    knossos: {
      brief: TurnBrief | null
      dashboard: Dashboard | null
      job: JobState
      view: KnossosView
      detail: DetailState | null
      refresh: RefreshState
      rescan: RescanState
      allow: AllowState
      theme: string
      changes: SessionChanges
      /** The session's root with its links followed, or null before it is known: where a copied command runs. */
      sessionRoot: string | null
      live: LiveState
      /** What the scan ledger says changed since the session began, as last read; null before the first read. */
      sessionLedger: SessionLedger | null
      /** The snapshot the graph was at when the session began: what `session-changes` reads since. */
      sessionStart: string | null
      /** When the session began (milliseconds), as its stored baseline says; null until read. */
      sessionBegan: number | null
      /** The paths the session's own edit tools wrote (project-relative, or absolute before the root was known). */
      sessionEdits: string[]
      /** The snapshots of the scans that took in changes made while the session's tools ran: whose a ledgered change was. */
      sessionScans: string[]
      /** The commit the project was at when the session began; null when it was not (yet) read. */
      sessionRev: SessionRev | null
      /** The change since the session began of the file the detail shows. */
      fileDiff: DiffState | null
      /** How far the detail's diff is opened: its hunks shown whole, and the first one shown. */
      diffFold: DiffFold | null
      /** Whether the Changes list holds only the changed files no test reaches. */
      untestedOnly: boolean
      /** Where the checkout stands now, for the header; null until read, and when there is no git. */
      gitHead: GitHead
      /** The heat map cell spelled out on the Boundaries tab, as last read. */
      couplings: CouplingState | null
      /** The footer's word after an action, until it fades. */
      feedback: Feedback | null
      /** The finder's search: what was typed and what was found. */
      search: SearchState
      /** The Branch tab's comparison with the merge base, as last read. */
      branch: BranchState | null
      /** The marked row's detail drawn beside the tab on a wide pane: what is shown, and its lookup. */
      peek: { shown: Inspected; detail: DetailState } | null
      /** The rows the latest scan changed (`hub:`, `file:`, `tile:`, `boundary:`, `change:` keys), lit until `until` (mod clock, ms). */
      flash: { keys: string[]; until: number } | null
      /** The Churn tab's hotspots, as last read for a commit. */
      churn: ChurnState | null
      /** The blast radius of the component the detail shows. */
      rings: RingsState | null
      /** The route the path explorer draws. */
      route: RouteState | null
      /** A note being added on the detail, until it is recorded or dropped. */
      note: NoteState | null
      /** The cycles the graph held when the session began: how many, each listed one by its members, and whether the list held them all. */
      startCycles: { count: number; keys: string[]; complete: boolean } | null
    }
  }
}
