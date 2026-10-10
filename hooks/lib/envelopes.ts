import type { AllowRoot, Annotate, BlastRadius, BoundaryCouplings, BoundaryRef, BranchDiff, BranchItem, Churn, ComponentDetail, Dashboard, FanIn, FileDetail, GitHead, GraphSearch, Listed, PathBetween, Related, Rescan, SessionDiff, SessionLedger, SessionRev, TurnBrief, Violation } from '../../types'
import { printableDeep } from './printable'

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
    const value: unknown = printableDeep(JSON.parse(stdout))
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

/** The bounds that stop a cycle search before it has seen the whole graph, rather than cut what it found. */
const SEARCH_STOPS = ['time_limit', 'node_limit', 'edge_limit']

/**
 * Whether a cycle search stopped before it had seen the whole graph, so its
 * count is not a count of the graph's cycles: one that names a bound that
 * stops the search, or a truncated one that found none (an older knossos
 * names no reasons).
 */
export function cycleSearchStopped(cycles: { count: number; truncated: boolean; truncation_reasons?: string[] }): boolean {
  return cycles.truncated && (cycles.count === 0 || (cycles.truncation_reasons ?? []).some(r => SEARCH_STOPS.includes(r)))
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
  // What it depends on came later: an answer without it, or with it misshapen, draws its dependents alone.
  if (file !== null && file !== undefined && file.uses !== undefined && !Array.isArray(file.uses?.items)) delete file.uses
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

const DIFF = new Set(['ok', 'no-git', 'unknown-rev', 'error', 'no-binary'])

/** A file's change since the session began from the wrapper's stdout; null for silence, or an `ok` whose diff is not text. */
export function parseSessionDiff(stdout: string): SessionDiff | null {
  const parsed = parse(stdout, DIFF, [], []) as SessionDiff | null
  return parsed?.status === 'ok' && typeof parsed.diff !== 'string' ? null : parsed
}

/**
 * The commit the session began at from `session-head`'s stdout: its id, or
 * that there is no git history (`no-git`); null for silence or anything else.
 * `no-binary` is left to the caller.
 */
export function parseSessionRev(stdout: string): SessionRev | null {
  const parsed = parse(stdout, new Set(['ok', 'no-git']), [], []) as { status: string; rev?: unknown } | null
  if (parsed?.status === 'no-git') return { status: 'no-git' }
  return parsed?.status === 'ok' && typeof parsed.rev === 'string' && /^[0-9a-f]{7,64}$/.test(parsed.rev) ? { status: 'ok', rev: parsed.rev } : null
}

/**
 * Where the checkout stands, from `session-head`'s stdout: its commit and
 * branch (null on a detached head), null for a project git does not hold,
 * undefined for silence or anything unexpected (asked again later).
 */
export function parseSessionHead(stdout: string): GitHead | undefined {
  const parsed = parse(stdout, new Set(['ok', 'no-git']), [], []) as { status: string; rev?: unknown; branch?: unknown } | null
  if (parsed?.status === 'no-git') return null
  if (parsed?.status !== 'ok' || typeof parsed.rev !== 'string' || !/^[0-9a-f]{7,64}$/.test(parsed.rev)) return undefined
  // A branch name that carried a control character is not printed: the
  // parser has already made most of them inert, and a name with a hole in it
  // would only mislead. Tab and newline pass that parser unchanged, and either
  // one would break the line the name is drawn on, so they are refused here.
  const branch = typeof parsed.branch === 'string' && parsed.branch !== '' && !/[\t\n�]/.test(parsed.branch) ? parsed.branch : null
  return { rev: parsed.rev, branch }
}

/** One heat map cell spelled out, from `boundary-couplings`' stdout; null for silence or anything unexpected. */
export function parseCouplings(stdout: string): BoundaryCouplings | null {
  const parsed = parse(stdout, DASH, ['couplings'], []) as BoundaryCouplings | null
  if (parsed === null || parsed.status !== 'ok') return parsed
  const listed = (c: unknown): boolean => {
    const end = (e: unknown) => typeof e === 'object' && e !== null && typeof (e as Listed).canonical_name === 'string' && typeof (e as Listed).name === 'string'
    const pair = c as { source?: unknown; target?: unknown; edges?: unknown }
    return typeof c === 'object' && c !== null && end(pair.source) && end(pair.target) && typeof pair.edges === 'number'
  }
  return typeof parsed.edges === 'number' && parsed.couplings.every(listed) ? { ...parsed, truncated: parsed.truncated === true } : null
}

/** Whether `v` names a component or file the pane can open: a shown and a full name, a kind, and where it is (or null). */
const isPlaced = (v: unknown): v is BranchItem => {
  const r = v as Partial<BranchItem> | null
  return typeof v === 'object' && v !== null && typeof r?.name === 'string' && typeof r.canonical_name === 'string' && typeof r.kind === 'string'
}

/** The finder's answer from the wrapper's stdout; null for silence or anything unexpected. */
export function parseGraphSearch(stdout: string): GraphSearch | null {
  const parsed = parse(stdout, DASH, ['results'], []) as GraphSearch | null
  if (parsed === null || parsed.status !== 'ok') return parsed
  const ok = parsed.results.every(r => isPlaced(r) && (r.type === 'component' || r.type === 'file'))
  return ok ? { ...parsed, truncated: parsed.truncated === true } : null
}

const BRANCH = new Set(['ok', 'no-snapshot', 'on-default', 'no-git', 'no-default', 'unscanned', 'error', 'no-binary'])

/** The Branch tab's comparison from the wrapper's stdout; null for silence or anything unexpected (a list item that names nothing). */
export function parseBranchDiff(stdout: string): BranchDiff | null {
  const parsed = parse(stdout, BRANCH, [], []) as BranchDiff | null
  const f = parsed?.files
  if (f !== undefined && f !== null && !(typeof f === 'object' && typeof f.count === 'number' && Array.isArray(f.items) && f.items.every(i => typeof i.path === 'string' && typeof i.added === 'number' && typeof i.deleted === 'number' && typeof i.dependents === 'number'))) return null
  const c = parsed?.comparison
  if (parsed === null || c === undefined || c === null) return parsed
  const lists = [c.crossing, c.cycles, c.hubs, c.dead_code]
  if (!lists.every(l => typeof l === 'object' && l !== null && typeof l.count === 'number' && Array.isArray(l.items))) return null
  const placed =
    c.crossing.items.every(i => isPlaced(i.source) && isPlaced(i.target)) &&
    c.cycles.items.every(i => typeof i.size === 'number' && Array.isArray(i.members) && i.members.every(isPlaced)) &&
    c.hubs.items.every(i => isPlaced(i.component) && typeof i.before === 'number' && typeof i.after === 'number') &&
    c.dead_code.items.every(isPlaced) &&
    (c.violations === null || (typeof c.violations === 'object' && Array.isArray(c.violations.items)))
  return placed ? parsed : null
}

const CHURN = new Set(['ok', 'no-git', 'unreadable', 'unscanned', 'error', 'no-binary'])

/** The Churn tab's hotspots from the wrapper's stdout; null for silence or anything unexpected (a file without its figures). */
export function parseChurn(stdout: string): Churn | null {
  const parsed = parse(stdout, CHURN, ['files'], []) as Churn | null
  if (parsed === null || parsed.status !== 'ok') return parsed === null ? null : { ...parsed, files: [] }
  const ok = parsed.files.every(f => typeof f.path === 'string' && typeof f.commits === 'number' && typeof f.dependents === 'number' && typeof f.score === 'number')
  return ok ? { ...parsed, files: parsed.files.map(f => ({ ...f, boundary: f.boundary ?? null })) } : null
}

/** A component a ring or a route names: its names and kind, as a string each. */
const isNamed = (v: unknown): boolean => {
  const n = v as Record<string, unknown> | null
  return typeof n === 'object' && n !== null && typeof n.name === 'string' && typeof n.canonical_name === 'string' && typeof n.kind === 'string'
}

/** A component's blast radius from the wrapper's stdout; null for silence or anything unexpected. */
export function parseBlastRadius(stdout: string): BlastRadius | null {
  const parsed = parse(stdout, DETAIL, [], []) as BlastRadius | null
  if (parsed === null || parsed.status !== 'ok') return parsed === null ? null : { ...parsed, component: null, rings: [], truncated: false }
  const rings = Array.isArray(parsed.rings) ? parsed.rings : null
  const ok = isNamed(parsed.component) && rings !== null && rings.every(r => typeof r.hop === 'number' && typeof r.count === 'number' && typeof r.tested === 'number' && Array.isArray(r.items) && r.items.every(isNamed) && Array.isArray(r.tests?.items))
  return ok ? { ...parsed, truncated: parsed.truncated === true } : null
}

const PATH = new Set(['ok', 'not-found', 'ambiguous', 'unscanned', 'error', 'no-binary'])

/** The routes between two components from the wrapper's stdout; null for silence or anything unexpected. */
export function parsePathBetween(stdout: string): PathBetween | null {
  const parsed = parse(stdout, PATH, [], []) as PathBetween | null
  if (parsed === null || parsed.status !== 'ok') return parsed === null ? null : { ...parsed, from: null, to: null, reversed: false, routes: [], truncated: false }
  const routes = Array.isArray(parsed.routes) ? parsed.routes : null
  const ok = isNamed(parsed.from) && isNamed(parsed.to) && routes !== null && routes.every(r => Array.isArray(r.nodes) && r.nodes.length >= 2 && r.nodes.every(isNamed) && Array.isArray(r.hops) && r.hops.length === r.nodes.length - 1)
  return ok ? { ...parsed, reversed: parsed.reversed === true, truncated: parsed.truncated === true } : null
}

const NOTE = new Set(['ok', 'refused', 'unscanned', 'error', 'no-binary'])

/** A note's preview or record from the wrapper's stdout; null for silence or anything unexpected. */
export function parseAnnotate(stdout: string): Annotate | null {
  const parsed = parse(stdout, NOTE, [], []) as Annotate | null
  if (parsed === null || parsed.status !== 'ok') return parsed
  return typeof parsed.component === 'string' && typeof parsed.executed === 'boolean' ? parsed : null
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
    value = printableDeep(JSON.parse(stdout))
  } catch {
    return null
  }
  if (!isObject(value)) return null
  const v = value as Record<string, unknown>
  if (v.status === 'no-binary') return { status: 'no-binary' }
  if (typeof v.path !== 'string' || typeof v.added !== 'boolean' || v.preview === true) return null
  return { path: v.path, added: v.added, ...(typeof v.roots_file === 'string' ? { roots_file: v.roots_file } : {}) }
}
