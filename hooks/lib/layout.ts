/**
 * The pane's layout: every row it draws, fitted to the columns it has.
 *
 * Pure: a view of the dashboard and the mod's state in, rows of styled
 * segments out. The render hook only turns segments into elements, so every
 * decision about width, order and colour is made here and tested here. The
 * shared primitives (segments, cutting, bars, the self-fitting table) are in
 * `rows.ts`; the Issues and Cycles tabs and the component detail in `views.ts`.
 */
import type { AllowState, BranchState, ChurnState, CouplingState, Dashboard, Feedback, GitHead, HubSort, KnossosView, LiveState, NoteState, PaneTab, Ranked, RefreshState, RescanState, RingsState, RouteState, SearchState, SessionChanges, TurnBrief } from '../../types'
import { formatAge } from './band'
import { boundariesArrangement, boundariesInput, boundariesList, couplingView, markedCell } from './boundaries'
import type { BoundariesInput, CouplingView } from './boundaries'
import { changesArrangement, changesInput, changesList, lookAtOf, NO_CHANGES } from './changes'
import type { ChangesInput, LookAt } from './changes'
import { countLabel } from './envelopes'
import { driftInput, driftList, driftSection, fileDetailArrangement, fileDetailList } from './files'
import type { DriftInput } from './files'
import { ACCENT, boundaryLabel, CHIP_BG, declaredOf, FAINT, HEADING, huesOf, ON_FILL, STATUS_COLOURS } from './palette'
import type { Hues, Tone } from './palette'
import {
  baseName,
  blank,
  button,
  cells,
  clip,
  dimRow,
  displayName,
  fit,
  grouped,
  numberWidth,
  padEnd,
  placeOf,
  plural,
  rowsHeight,
  rowWidth,
  segmentsWidth,
  spaces,
  spread,
  tableHead,
  tableRow,
  tableSpec,
  tierOf,
  tinted,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment, TableSpec, Tier } from './rows'
import { LIVE_OFF } from './live'
import { CARD_MAX, componentFigures, fileFigures, previewCard } from './hover'
import { tilesBlock } from './tiles'
import type { Stat } from './tiles'
import { compositionBlock, concentrationBlock, flowsBlock, healthBlock, overviewData, overviewList as overviewWalk, sessionBlock } from './overview'
import type { OverviewData } from './overview'
import { branchArrangement, branchCount, branchInput, branchList } from './branch'
import { churnArrangement, churnInput, churnList } from './churn'
import type { ChurnInput } from './churn'
import { ringsInput } from './rings'
import { routeArrangement, routeInput, routeList } from './route'
import type { RouteInput } from './route'
import type { BranchInput } from './branch'
import { litAt } from './flash'
import { finderBlock, finderInput, finderList } from './finder'
import type { FinderInput } from './finder'
import type { Flash } from './flash'
import { cycleCount, cycleSteps, cyclesArrangement, cyclesInput, cyclesList, unfoldPress } from './cycles'
import type { CyclesInput } from './cycles'
import { detailArrangement, detailList, issueCount, issuesArrangement, issuesInput, issuesList, locIn, superscript } from './views'
import { arrange, besideRows, cardInner, DEFAULT_ROWS, fillColumn, fitBlocks, gridColumns, moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import type { DetailInput, IssuesInput, NoteInput, Openable } from './views'

// Everything the specs and the render hook draw with, from one module.
export { bar, button, cells, displayName, fileHref, fit, linkMarkdown, locOf, locText, rowWidth, tableSpec, tierOf, wrapWords } from './rows'
export type { Field, Loc, Press, Preview, Row, Segment, TableSpec, Tier } from './rows'
export { DEFAULT_ROWS, paneHeight } from './cards'
export type { DetailInput, FileView, Openable } from './views'
export type { BoundariesInput, CouplingView, HeatCell } from './boundaries'
export { markedCell, nextTarget } from './boundaries'
export { accumulate, cdFor, NO_CHANGES } from './changes'
export type { ChangesInput, LookAt } from './changes'
export { detailInput } from './views'
export { driftInput, fileDetailInput } from './files'
export type { DriftInput } from './files'
export type { Stat } from './tiles'
export type { OverviewData } from './overview'

/** One component in a list the selection walks: hubs and hotspots merged. */
export type Item = {
  name: string
  canonical: string
  kind: string
  boundary: string | null
  in: number
  out: number
  cross: number
  /** How many other files reference it; null from a knossos that does not say. */
  files: number | null
  /** Ranked as a hotspot but not as a hub: marked `◆`. */
  hotspotOnly: boolean
  /** Where it is declared, so `e` opens its file; null when the dashboard places it nowhere. */
  loc: Loc | null
  /** The files that depend on it most, for its hover card. */
  top: string[]
}

/** The header's status: its tone, the words in its pill, and why, when the pill alone does not say. */
export type PaneStatus = { tone: Tone; text: string; note?: string }

/** A file many others depend on, as the fan-in map lists it: `loc` opens it. */
export type FileHub = { path: string; dependents: number; boundary: string | null; loc: Loc | null; top: string[] }

/** Everything the pane draws, read from state once per render. */
export type PaneInput = {
  project: string
  status: PaneStatus
  canRescan: boolean
  summary: string[]
  /** The project's languages as the summary line names them (`PHP TS`), or empty. */
  languages: string
  tab: PaneTab
  selected: number
  showKeys: boolean
  /** Whether plain Buttons are drawn as text (`1: label`): the terminal. Elsewhere they are native. */
  terminal: boolean
  items: Item[]
  partial: boolean
  /** The stat tiles across the top of the Overview. */
  stats: Stat[]
  /** What the Overview's charts draw. */
  overview: OverviewData
  /** The files most depended on, most first. */
  fileHubs: FileHub[]
  /** The in-degree range Hubs is narrowed to (an Overview bucket pressed), and how many components the bucket counts; null for none. */
  degree: { from: number; to: number | null; components: number | null } | null
  /** The hubs tab's filter text, whether its field is open, and its sort. */
  filter: string
  filtering: boolean
  sort: HubSort
  issues: IssuesInput
  cycles: CyclesInput
  /** Null when the knossos that answered sends no boundary matrix. */
  boundaries: BoundariesInput | null
  /** The component on show instead of a tab, or null. */
  detail: DetailInput | null
  /** The allow-root offer and its progress, or null when no root was refused. */
  allow: AllowInput | null
  /** Each boundary's colour, the project's largest first. */
  hues: Hues
  /** Everything this session changed, for the Changes tab. */
  changes: ChangesInput
  /** The Overview's "Look at now", or null before anything changed. */
  lookAt: LookAt | null
  /** The files drifted since the snapshot, when the dashboard names any. */
  drift: DriftInput | null
  /** Whether they are listed under the header: the marker walks them then. */
  driftOpen: boolean
  /** Where the checkout stands; undefined until read, null without git. */
  git: GitHead | undefined
  /** The footer's word after an action, while it lasts. */
  feedback: Feedback | null
  /** The boundary the Boundaries tab's cell runs to, as last stepped to; null for the marked one's top dependency. */
  target: string | null
  /** That cell spelled out, as last read. */
  couplings: CouplingView | null
  /** The rows the latest scan changed, lit for a moment after it landed (see `flash.ts`). */
  lit: ReadonlySet<string>
  /** The finder, while it is open over the pane (`f`); null otherwise. */
  finder: FinderInput | null
  /** The Branch tab: the branch against the snapshot at its merge base. */
  branch: BranchInput
  /** The marked row's detail, drawn beside the tab on a wide pane (master-detail); null when there is none to show. */
  peek: DetailInput | null
  /** The Churn tab: the files changed most that much depends on. */
  churn: ChurnInput
  /** The route the path explorer draws instead of a tab or a detail, or null. */
  route: RouteInput | null
}

/** A refused root the pane offers to allow: the root, the roots file it would join, and where the action stands. */
export type AllowInput = { root: string; rootsFile: string | null; phase: AllowState['phase']; reason: string | null }

/** The tabs in hotkey order; each new one came last, so the older tabs keep their digits. */
export const TABS: { id: PaneTab; full: string; hotkey: string }[] = [
  { id: 'overview', full: 'Overview', hotkey: '1' },
  { id: 'hubs', full: 'Hubs', hotkey: '2' },
  { id: 'boundaries', full: 'Boundaries', hotkey: '3' },
  { id: 'cycles', full: 'Cycles', hotkey: '4' },
  { id: 'issues', full: 'Issues', hotkey: '5' },
  { id: 'changes', full: 'Changes', hotkey: '6' },
  { id: 'branch', full: 'Branch', hotkey: '7' },
  { id: 'churn', full: 'Churn', hotkey: '8' },
]

export const SORTS: HubSort[] = ['in', 'out', 'cross']

/** The fewest hubs the Hubs tab lists, however short the pane. */
const HUBS_MIN = 5
/** The fewest files most depended on any tab lists, however short the pane. */
const FILES_MIN = 3
/** The widest the empty pane's prose runs: a line of text past it is hard to read. */
export const CONTENT_MAX = 100

/**
 * Hubs and hotspots as one list, one row per component: the two rankings
 * mostly name the same components, so listing both repeats them. Most
 * depended on first; a hotspot that is not a hub keeps its place after the
 * hubs with the same in-degree.
 */
export function mergeRanked(d: Pick<Dashboard, 'hubs' | 'hotspots'> & Partial<Pick<Dashboard, 'project_root'>>): Item[] {
  const seen = new Map<string, Item>()
  const add = (r: Ranked, hotspotOnly: boolean) => {
    if (seen.has(r.canonical_name)) return
    seen.set(r.canonical_name, {
      name: displayName(r),
      canonical: r.canonical_name,
      kind: r.kind,
      boundary: r.boundary ?? null,
      in: r.in_degree ?? 0,
      out: r.out_degree ?? 0,
      cross: r.cross_boundary_degree ?? 0,
      files: r.dependent_files ?? null,
      hotspotOnly,
      loc: locIn(d.project_root ?? null, r.path ?? null, r.line ?? null),
      top: r.top_dependents ?? [],
    })
  }
  d.hubs.forEach(hub => add(hub, false))
  d.hotspots.forEach(spot => add(spot, true))
  return [...seen.values()].sort((a, b) => b.in - a.in || Number(a.hotspotOnly) - Number(b.hotspotOnly))
}

/**
 * The hubs tab's list: the components whose name (shown or canonical) holds
 * the filter, any case, most first by the sort, ties by in-degree then name.
 */
export function hubList(items: Item[], filter: string, sort: HubSort): Item[] {
  const needle = filter.trim().toLowerCase()
  const kept = needle === '' ? items : items.filter(i => i.name.toLowerCase().includes(needle) || i.canonical.toLowerCase().includes(needle))
  return [...kept].sort((a, b) => b[sort] - a[sort] || b.in - a.in || a.name.localeCompare(b.name))
}

/** The files most depended on whose path holds the filter, any case: the Hubs tab's second list. */
export function fileHubList(files: FileHub[], filter: string): FileHub[] {
  const needle = filter.trim().toLowerCase()
  return needle === '' ? files : files.filter(f => f.path.toLowerCase().includes(needle))
}

/** What a list addresses: the fields the selection, `o`, `e`, `c` and `q` read. */
export type ListInput = Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'degree' | 'issues' | 'detail' | 'changes' | 'cycles' | 'boundaries' | 'lookAt' | 'overview' | 'drift' | 'driftOpen' | 'fileHubs'> & Partial<Pick<PaneInput, 'finder' | 'branch' | 'churn' | 'route'>>

/** A file row of a list: it opens as the file's detail, and `e` opens the file. */
const fileRow = (path: string, loc: Loc | null): Openable => ({ name: path, canonical: path, loc, file: true })

/** The pane's state the layout does not read from the dashboard: where the checkout stands, the footer's word, and the Boundaries tab's cell. */
export type PaneExtras = {
  git?: GitHead | undefined
  feedback?: Feedback | null
  couplings?: CouplingState | null
  flash?: Flash | null
  search?: SearchState | null
  branch?: BranchState | null
  churn?: ChurnState | null
  /** The blast radius of the component the detail shows, as last read. */
  rings?: RingsState | null
  route?: RouteState | null
  /** A note being added on the detail. */
  note?: NoteState | null
  /** Whether the Changes list holds only the files no test reaches. */
  untestedOnly?: boolean
}

/**
 * The Overview's walkable rows, in the order a narrow pane draws them: the
 * way to this session's changes, the in-degree buckets, then the
 * cross-boundary flows. Each opens another tab (see `overview.ts`).
 */
export function overviewList(input: Pick<PaneInput, 'overview' | 'changes'>): Openable[] {
  return overviewWalk(input.overview, input.changes)
}

/** The Hubs list, narrowed to the in-degree range when one is set. */
function hubsShown(input: Pick<PaneInput, 'items' | 'filter' | 'sort' | 'degree'>): Item[] {
  const listed = hubList(input.items, input.filter, input.sort)
  const range = input.degree
  return range === null ? listed : listed.filter(i => i.in >= range.from && (range.to === null || i.in <= range.to))
}

/** The files Hubs lists beside the components: none while an in-degree range narrows it, since a file has no in-degree. */
const filesShown = (input: Pick<PaneInput, 'fileHubs' | 'filter' | 'degree'>): FileHub[] => (input.degree === null ? fileHubList(input.fileHubs, input.filter) : [])

/** The rows the selection walks: the detail's counterparts, else the drifted files when listed, else the tab's list. */
export function listFor(input: ListInput): Openable[] {
  // The finder stands over everything: its matches are what the marker walks.
  if (input.finder !== undefined && input.finder !== null) return finderList(input.finder)
  if (input.route !== undefined && input.route !== null) return routeList(input.route)
  if (input.detail !== null) return input.detail.file === undefined ? detailList(input.detail) : input.detail.file === null ? [] : fileDetailList(input.detail.file)
  if (input.driftOpen && input.drift !== null) return driftList(input.drift)
  if (input.tab === 'overview') return overviewList(input)
  if (input.tab === 'hubs') return [...hubsShown(input), ...filesShown(input).map(f => fileRow(f.path, f.loc))]
  if (input.tab === 'changes') return changesList(input.changes)
  if (input.tab === 'cycles') return cyclesList(input.cycles)
  if (input.tab === 'boundaries') return boundariesList(input.boundaries)
  if (input.tab === 'branch') return input.branch === undefined ? [] : branchList(input.branch)
  if (input.tab === 'churn') return input.churn === undefined ? [] : churnList(input.churn)
  return input.tab === 'issues' ? issuesList(input.issues) : []
}

/**
 * The header's status, as its pill says it: the snapshot's state and age
 * (`stale 38m`), or what the rescan, the refresh or the live watcher is
 * doing. While the watcher keeps a fresh graph current it says `live` and how
 * long ago it last moved (`live · 7s`; `following · 7s` when another
 * session's watcher leads). Why, when the pill alone does not say, is its
 * `note`: a rescan's reason, a stuck leader.
 */
export function paneStatus(d: Dashboard, refresh: RefreshState, rescan: RescanState, now: number, live: LiveState = LIVE_OFF): PaneStatus {
  const sinceFetch = refresh.fetchedAt === null ? 0 : now - refresh.fetchedAt
  const age = d.freshness.age_seconds === null ? '' : formatAge(d.freshness.age_seconds * 1000 + sinceFetch)
  const aged = (words: string, joint = ' ') => (age === '' ? words : `${words}${joint}${age}`)
  const state = d.freshness.state
  // The figures on show are still the old snapshot's while a scan runs: say how old, never only that a scan runs.
  if (rescan.phase === 'scanning') return { tone: 'warn', text: refresh.failed ? aged('scanning… · refresh failed') : state === 'fresh' ? 'scanning…' : aged(`scanning… · ${state}`) }
  if (rescan.phase === 'failed') return { tone: 'alert', text: 'scan failed', ...(rescan.reason ? { note: rescan.reason } : {}) }
  if (refresh.failed) return { tone: 'alert', text: aged('refresh failed') }
  if (live.phase === 'scanning') return { tone: 'warn', text: state === 'fresh' ? 'scanning…' : aged(`scanning… · ${state}`) }
  // The leader's watcher is stuck or gone quiet: the graph is not being kept current, whatever its age says.
  if (live.phase === 'following' && live.stale === true) return { tone: 'warn', text: aged(state), note: "another session's watcher is stuck" }
  if ((live.phase === 'live' || live.phase === 'following') && state === 'fresh' && d.freshness.drift_files === 0) {
    return { tone: 'ok', text: aged(live.phase === 'live' ? 'live' : 'following', ' · ') }
  }
  return { tone: state === 'fresh' ? 'ok' : 'warn', text: aged(state) }
}

const LANGUAGES: Record<string, string> = { php: 'PHP', javascript: 'JS', typescript: 'TS', python: 'PY', rust: 'RS', go: 'GO', ruby: 'RB', java: 'JAVA' }

/** The project's languages as the summary line names them, most files first; empty when the dashboard does not say. */
const languagesOf = (d: Dashboard): string => (d.summary?.languages ?? []).map(l => LANGUAGES[l.language] ?? l.language.toUpperCase()).join(' ')

/**
 * The stat tiles: components and boundaries (from a dashboard that reports
 * them), cycles, the largest degree, dead code and the diagnostics (when they
 * were read), each but dead code with how it moved since the previous
 * snapshot and its trend when every snapshot carries one, then the policy
 * violations (when checked) and the drifted files (a press that lists them,
 * when the dashboard names them). Dead code is the candidates the Issues tab
 * lists; the snapshots carry only the quality gate's wider count, which the
 * health card draws under its own name, so the tile has no delta or trend
 * of its own. Each says how it deviates: cycles and diagnostics above zero
 * warn, violations above zero are errors, drift above zero is the accent.
 * Cycles and diagnostics are statuses: their deltas take the status colours.
 */
export function statsOf(d: Dashboard, summary: string[], policy: string | null, diagnostics: number | null, drift: boolean): Stat[] {
  const above = (value: string) => value !== '0'
  const cycles = cycleCount(d.cycles)
  const dead = countLabel(d.dead_code_candidates, d.dead_code_truncated)
  const maxDegree = d.trend.at(-1)?.max_degree ?? null
  const drifted = d.freshness.drift_files
  const deltas = d.deltas ?? null
  // How a figure moved since the previous snapshot, when the dashboard says; `worse` for a figure that is a status.
  const moved = (figure: 'components' | 'cycles' | 'max_degree' | 'diagnostics', worse = false): Pick<Stat, 'delta' | 'worse'> =>
    deltas === null || typeof deltas[figure] !== 'number' ? {} : { delta: deltas[figure], ...(worse ? { worse: 'up' as const } : {}) }
  // A series only when every snapshot carries it: an older knossos sends cycles and the largest degree alone.
  const series = (pick: (t: Dashboard['trend'][number]) => number | undefined): Pick<Stat, 'trend'> => {
    const values = d.trend.map(pick)
    return values.every((v): v is number => typeof v === 'number') ? { trend: values } : {}
  }
  // From the summary line's own parts, so a figure reads the same in both: `9,008 components`, `7+ boundaries`.
  const counted = (part: string | undefined, label: string, extra: Partial<Stat> = {}): Stat[] => (part === undefined ? [] : [{ key: label, label, value: part.slice(0, part.indexOf(' ')), ...extra }])
  // A figure that counts a set the pane lists presses to that list (see the `stat:` press); at zero there is nothing to go to.
  const listing = (key: string, value: string): Pick<Stat, 'press'> => (above(value) ? { press: `stat:${key}` } : {})
  // A summary part's figure, as its tile shows it: `9,008 components` gives `9,008`.
  const figureOf = (part: string | undefined): string => (part === undefined ? '0' : part.slice(0, part.indexOf(' ')))
  return [
    ...(d.summary === undefined
      ? []
      : [
          ...counted(summary[0], 'components', { ...moved('components'), ...series(t => t.components), ...listing('components', figureOf(summary[0])) }),
          ...counted(summary[1], summary[1]?.endsWith('boundary') ? 'boundary' : 'boundaries', listing('boundaries', figureOf(summary[1]))),
        ]),
    { key: 'cycles', label: d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles', value: cycles, ...series(t => t.cycles), ...moved('cycles', true), ...(above(cycles) ? { tone: 'warn' as const } : {}), ...listing('cycles', cycles) },
    ...(maxDegree === null ? [] : [{ key: 'degree', label: 'max degree', value: String(maxDegree), ...series(t => t.max_degree), ...moved('max_degree') }]),
    // The candidates Issues lists; the trend's `dead_code` is the gate's wider count, drawn on the health card under its own name.
    { key: 'dead', label: 'dead code', value: dead, ...listing('dead', dead) },
    ...(diagnostics === null ? [] : [{ key: 'diagnostics', label: 'diagnostics', value: grouped(diagnostics), ...series(t => t.diagnostics), ...moved('diagnostics', true), ...(diagnostics > 0 ? { tone: 'warn' as const } : {}), ...listing('diagnostics', grouped(diagnostics)) }]),
    ...(policy === null ? [] : [{ key: 'policy', label: 'policy', value: policy, ...(above(policy) ? { tone: 'alert' as const } : {}), ...listing('policy', policy) }]),
    { key: 'drifted', label: 'drifted', value: grouped(drifted), ...(drifted > 0 ? { tone: 'accent' as const } : {}), ...(drift ? { press: 'drifted' } : {}) },
  ]
}

/**
 * The header's summary line: components, boundaries, drift and languages
 * from a dashboard that reports them; hubs, cycles and dead code otherwise.
 * Boundaries count the declared ones when there are any.
 */
export function summaryParts(d: Dashboard, hubs: number): string[] {
  const drift = `${d.freshness.drift_files} drifted`
  if (d.summary === undefined) {
    return [
      plural(hubs, 'hub', 'hubs'),
      `${cycleCount(d.cycles)} ${d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles'}`,
      `${countLabel(d.dead_code_candidates, d.dead_code_truncated)} dead code`,
      drift,
    ]
  }
  const all = d.boundaries?.items ?? []
  const declared = declaredOf(d).size
  const boundaries = declared > 0 ? declared : all.length
  // Cut short: the declared names past their own cap, or, without declared ones, the list past its.
  // An older knossos names declared ones only in the list: a floor when they fill it.
  const listedDeclared = all.filter(b => b.source === 'explicit').length
  const more =
    d.boundaries?.declared !== undefined && declared > 0
      ? d.boundaries.declared_truncated === true
      : d.boundaries?.truncated === true && (listedDeclared === 0 || listedDeclared === all.length)
  const languages = languagesOf(d)
  // Languages last: the line drops parts from its end, and drift says more than the languages.
  return [
    `${grouped(d.summary.components)} components`,
    `${countLabel(boundaries, more)} ${boundaries === 1 && !more ? 'boundary' : 'boundaries'}`,
    drift,
    ...(languages === '' ? [] : [languages]),
  ]
}

/** Everything the pane draws, from state. `d` is an `ok` dashboard; `detail` the component on show, if any. */
export function paneInput(
  d: Dashboard,
  brief: TurnBrief | null,
  refresh: RefreshState,
  rescan: RescanState,
  view: KnossosView,
  now: number,
  terminal: boolean,
  detail: DetailInput | null = null,
  allow: AllowState | null = null,
  session: SessionChanges = NO_CHANGES,
  sessionRoot: string | null = null,
  live: LiveState = LIVE_OFF,
  extras: PaneExtras = {},
  peek: DetailInput | null = null,
): PaneInput {
  const items = mergeRanked(d)
  const summary = summaryParts(d, items.length)
  const issues = issuesInput(d)
  const hues = huesOf(d)
  const changes = changesInput(session, d.project_root, hues, sessionRoot, extras.untestedOnly === true)
  const drift = driftInput(d)
  const boundaries = boundariesInput(d)
  const turn = brief?.status === 'ok' && brief.policy.status === 'evaluated' ? String(brief.policy.total) : null
  const policy = issues.policy?.evaluated ? issues.policy.total : turn
  const diagnostics = issues.diagnostics === null ? null : issues.diagnostics.errors + issues.diagnostics.warnings
  const lit = litAt(extras.flash ?? null, now)
  const allowing = allowInput(brief, rescan, allow)
  const route = view.route ?? null
  return {
    project: baseName(d.project_root ?? d.path) || (d.project_root ?? d.path),
    status: paneStatus(d, refresh, rescan, now, live),
    // Always on offer, fresh or not: freshness is the snapshot's own account,
    // and a person who knows the code moved should not have to wait for it to
    // agree. Only a scan already running, a rescan or the watcher's, holds it back.
    canRescan: rescan.phase !== 'scanning' && live.phase !== 'scanning',
    summary,
    languages: languagesOf(d),
    tab: view.tab,
    selected: view.selected,
    showKeys: view.showKeys,
    terminal,
    items,
    partial: d.hubs_truncated,
    stats: statsOf(d, summary, policy, diagnostics, drift !== null).map(stat => (lit.has(`tile:${stat.key}`) ? { ...stat, lit: true as const } : stat)),
    overview: overviewData(d, hues),
    fileHubs: [...d.fan_in]
      .sort((a, b) => b.dependent_files - a.dependent_files || a.path.localeCompare(b.path))
      .map(f => ({ path: f.path, dependents: f.dependent_files, boundary: f.boundary ?? null, loc: locIn(d.project_root, f.path), top: f.top_dependents ?? [] })),
    degree: degreeOf(d, view),
    filter: view.filter ?? '',
    filtering: view.filtering ?? false,
    sort: view.sort ?? 'in',
    issues,
    cycles: cyclesInput(d, view.unfolded ?? []),
    boundaries,
    detail: componentDetail(detail, d.project_root, extras, allowing?.phase !== 'confirming', view.inspect?.name ?? null),
    allow: allowing,
    hues,
    changes,
    lookAt: lookAtOf(changes),
    drift,
    driftOpen: drift !== null && view.drift === true,
    git: extras.git,
    feedback: extras.feedback !== undefined && extras.feedback !== null && now < extras.feedback.until ? extras.feedback : null,
    target: view.target ?? null,
    couplings: shownCouplings(boundaries, view, d.snapshot_id ?? null, extras.couplings ?? null),
    lit,
    finder: view.finding === true && extras.search !== undefined && extras.search !== null ? finderInput(extras.search, d.project_root, view.picking?.label ?? null) : null,
    branch: branchInput(extras.branch ?? null, d.project_root),
    peek,
    churn: churnInput(extras.churn ?? null, d.project_root),
    route: route === null ? null : routeInput(extras.route ?? null, route.from, route.to, route.index, d.project_root),
  }
}

/**
 * A component's own detail with what only it draws: its blast radius and
 * the notes card that adds one (`m`), with the note under way. A file's
 * detail, and a detail still loading, are left as they are.
 */
function componentDetail(detail: DetailInput | null, root: string | null, extras: PaneExtras, keys: boolean, asked: string | null): DetailInput | null {
  if (detail === null || detail.file !== undefined || detail.component === null) return detail
  const canonical = detail.component.canonical
  // The rings are read by the name the detail was asked for (a short name typed after /knossos inspect, or the full one).
  const rings = extras.rings ?? null
  const ringsOf = rings !== null && (rings.name === asked || rings.name === canonical) ? rings.name : canonical
  const note = extras.note ?? null
  const shown: NoteInput | null = note === null || note.component !== canonical ? null : { phase: note.phase, value: note.value, previous: note.previous, reason: note.reason, keys }
  return { ...detail, rings: ringsInput(rings, ringsOf, root), note: shown, notable: true }
}

/** The in-degree range Hubs is narrowed to, with how many components the Overview's bucket for it counts. */
function degreeOf(d: Dashboard, view: KnossosView): PaneInput['degree'] {
  const range = view.degree
  if (range === undefined || range === null) return null
  const bucket = (d.in_degree?.buckets ?? []).find(b => b.from === range.from && b.to === range.to)
  return { from: range.from, to: range.to, components: bucket?.components ?? null }
}

/**
 * The marked cell's couplings as the card draws them: the stored answer when
 * it is for this cell of this graph, else loading (a read of this one is on
 * its way); null when no cell is marked.
 */
function shownCouplings(boundaries: BoundariesInput | null, view: KnossosView, snapshot: string | null, state: CouplingState | null): CouplingView | null {
  if (boundaries === null || view.tab !== 'boundaries') return null
  const cell = markedCell(boundaries, Math.min(Math.max(0, view.selected), boundaries.boundaries.length - 1), view.target)
  if (cell === null) return null
  const from = boundaries.boundaries[cell.from]!.name
  const to = boundaries.boundaries[cell.to]!.name
  const same = state !== null && state.from === from && state.to === to && state.snapshot === snapshot
  return couplingView(same ? state : { phase: 'loading', answer: null })
}

/** The heat map cell the Boundaries tab marks, by its two boundaries' names: what the couplings are read for; null off that tab or with none. */
export function couplingPair(input: Pick<PaneInput, 'tab' | 'detail' | 'boundaries' | 'selected' | 'target'>): { from: string; to: string } | null {
  if (input.detail !== null || input.tab !== 'boundaries' || input.boundaries === null || input.boundaries.boundaries.length === 0) return null
  const cell = markedCell(input.boundaries, Math.min(Math.max(0, input.selected), input.boundaries.boundaries.length - 1), input.target)
  return cell === null ? null : { from: input.boundaries.boundaries[cell.from]!.name, to: input.boundaries.boundaries[cell.to]!.name }
}

/**
 * The root a scan was refused for: the turn brief's, else the pane's last
 * rescan's; null when neither was refused.
 */
export function refusedRoot(brief: TurnBrief | null, rescan: RescanState): { root: string; rootsFile: string | null } | null {
  if (brief?.status === 'not-allowed') return { root: brief.refused_root ?? brief.path, rootsFile: brief.roots_file }
  return rescan.refusedRoot ? { root: rescan.refusedRoot, rootsFile: null } : null
}

/** The allow-root offer: the action under way for its root, else an offer for a refused root, else null. */
export function allowInput(brief: TurnBrief | null, rescan: RescanState, allow: AllowState | null): AllowInput | null {
  const refused = refusedRoot(brief, rescan)
  if (allow !== null && allow.phase !== 'idle' && allow.root !== null) {
    return { root: allow.root, rootsFile: refused?.root === allow.root ? refused.rootsFile : null, phase: allow.phase, reason: allow.reason }
  }
  return refused === null ? null : { ...refused, phase: 'idle', reason: null }
}

/**
 * The component a copy or an "Ask Claude" is about: the one on show in the
 * detail, else the marked row of the tab's list; null when there is none.
 */
export function subjectOf(input: ListInput & Pick<PaneInput, 'selected'>): Openable | null {
  // A route drawn over a detail: the marked box is the subject, as on any list.
  if (input.detail !== null && (input.route === undefined || input.route === null)) {
    const f = input.detail.file
    if (f !== undefined) return f === null ? null : fileRow(f.path, f.loc)
    const c = input.detail.component
    return c === null ? null : { name: c.name, canonical: c.canonical, loc: c.loc }
  }
  const list = listFor(input)
  const marked = list[Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))]
  if (marked === undefined) return null
  const { name, canonical, loc, file, inert, copy, ask } = marked
  return { name, canonical, loc: loc ?? null, ...(file ? { file } : {}), ...(inert ? { inert } : {}), ...(copy === undefined ? {} : { copy }), ...(ask === undefined ? {} : { ask }) }
}

/**
 * The place `e` opens in the editor: the file of what the detail shows, else
 * of the marked row, whatever list the marker is in; null when it has none
 * (a hub, a cycle, a boundary).
 */
export function editTarget(input: ListInput & Pick<PaneInput, 'selected'>): Loc | null {
  return subjectOf(input)?.loc ?? null
}

/** The prompt "Ask Claude" submits for a component. */
export const askPrompt = (canonical: string): string =>
  `Using the Knossos graph, what depends on ${canonical} and what would break if I changed it?`

/**
 * The allow-root rows: the offer with its button, the question with its two
 * answers, the action running, or how it ended. The offer never runs
 * anything itself: `a` only asks, and only `y` on the question allows.
 */
export function allowRows(allow: AllowInput, columns: number): Row[] {
  const lines = (key: string, text: string, style: Omit<Segment, 'text'>): Row[] =>
    wrapWords(text, Math.max(1, columns - 3)).map((line, i) => ({ key: `${key}-${i}`, segments: [{ text: i === 0 ? '▲  ' : '   ', ...style }, { text: line, ...style }] }))
  const answers = (groups: Segment[][]) => wrapGroups('allow-keys', groups, columns, 2, 3)
  if (allow.phase === 'confirming') {
    const where = allow.rootsFile === null ? 'the roots file knossos reads' : allow.rootsFile
    return [
      ...lines('allow-ask', `Allow knossos to scan ${allow.root}? This adds it to ${where}.`, { color: STATUS_COLOURS.warn }),
      ...answers([[button('allow-yes', 'allow', 'y', { dim: false })], [button('allow-no', 'cancel', 'n', { dim: true })]]),
    ]
  }
  if (allow.phase === 'running') return [dimRow('allow-running', `   allowing ${allow.root}…`, columns)]
  if (allow.phase === 'done') {
    return wrapWords(`✓ ${allow.root} allowed · the next turn scans it`, columns).map((line, i) => ({
      key: `allow-done-${i}`,
      segments: [{ text: line, color: STATUS_COLOURS.ok }],
    }))
  }
  const said = allow.phase === 'failed' ? `allow-root failed${allow.reason ? `: ${allow.reason}` : ''} · ${allow.root}` : `${allow.root} is not an allowed root`
  return [
    ...lines('allow-offer', said, { color: allow.phase === 'failed' ? STATUS_COLOURS.alert : STATUS_COLOURS.warn }),
    ...answers([[button('allow', 'allow root', 'a', { dim: false })]]),
  ]
}

/** The active tab's ground: the theme's selection colour, under which a plain Button's own text stays legible in every theme. */
export const TAB_BG = 'selectionBg'

/** Where the checkout stands, as the header names it: the branch and the short commit, or the commit alone on a detached head. */
export function gitLabel(git: GitHead | undefined): string {
  if (git === undefined || git === null) return ''
  const short = git.rev.slice(0, 7)
  return git.branch === null ? `@ ${short}` : `${git.branch} · ${short}`
}

/** The status as a pill: the dot and the words on the status colour, in the text set on a fill. */
function pill(status: PaneStatus): Segment {
  return { text: ` ● ${status.text} `, bg: STATUS_COLOURS[status.tone], color: ON_FILL, bold: true }
}

/**
 * The header's first row. On a tab: the project's name in bold, where the
 * checkout stands (branch and short commit, dim; nothing without git) and
 * the languages as small chips, then against the right edge why the status
 * is what it is, the status as a pill in its colour and the rescan action
 * whenever no scan is running. In a detail the name becomes the way
 * back: `project › Tab › what is shown`. As the width shrinks the chips go
 * first, then the commit, the branch and the reason; then the name is cut,
 * never below four cells, then the pill's words. Narrow, the name, the
 * pill and, while it fits, why.
 */
export function titleRow(input: PaneInput, columns: number, tier: Tier): Row {
  const rescan: Segment[] = input.canRescan ? [{ text: '  ' }, button('rescan', 'rescan', 'r', { dim: false })] : []
  // Where the header is short of room the label goes before the reason does;
  // the hotkey stays, on a twin drawn out of sight, as a tab's digit does.
  const rescanKey: Segment[] = input.canRescan ? [{ text: '', hidden: true, press: { id: 'rescan', label: 'rescan', hotkey: 'r' } }] : []
  const note: Segment[] = input.status.note === undefined ? [] : [{ text: `${input.status.note}  `, dim: true }]
  const name: Segment = { text: input.project, bold: true, color: HEADING }
  const crumbs: Segment[] =
    input.route !== null
      ? [name, { text: ' › ', dim: true }, { text: 'Route', dim: true }, { text: ' › ', dim: true }, { text: `${input.route.from} → ${input.route.to}`, bold: true, color: HEADING }]
      : input.detail === null
        ? [name]
        : [name, { text: ' › ', dim: true }, { text: TABS.find(t => t.id === input.tab)?.full ?? '', dim: true }, { text: ' › ', dim: true }, { text: input.detail.label, bold: true, color: HEADING }]
  const git = gitLabel(input.git)
  const where: Segment[] = git === '' || tier === 'narrow' ? [] : [{ text: `  ${git}`, dim: true }]
  const away = input.detail !== null || input.route !== null
  const chips: Segment[] =
    tier === 'narrow' || away || input.languages === '' ? [] : input.languages.split(' ').flatMap((l): Segment[] => [{ text: ' ' }, { text: ` ${l} `, dim: true, bg: CHIP_BG }])
  const chipsLead: Segment[] = chips.length === 0 ? [] : [{ text: ' ' }, ...chips]
  // Each layout from the fullest down; the first that fits wins.
  const variants: [Segment[], Segment[]][] = [
    [[...crumbs, ...(away ? [] : where), ...chipsLead], [...note, pill(input.status), ...rescan]],
    [[...crumbs, ...(away ? [] : where)], [...note, pill(input.status), ...rescan]],
    [[...crumbs, ...(!away && input.git?.branch ? [{ text: `  ${input.git.branch}`, dim: true }] : [])], [...note, pill(input.status), ...rescan]],
    [crumbs, [...note, pill(input.status), ...rescan]],
    [crumbs, [...note, pill(input.status), ...rescanKey]],
    [crumbs, [pill(input.status), ...rescan]],
    [crumbs, [pill(input.status), ...rescanKey]],
  ]
  for (const [left, right] of variants) {
    if (segmentsWidth(left) + 1 + segmentsWidth(right) <= columns) return spread('title', left, right, columns)
  }
  // Nothing fits whole: the right side first, then the way back cut from its middle, then the pill's words cut.
  // The rescan label is the first thing given up; its hotkey stays.
  const right: Segment[] = [pill(input.status), ...rescanKey]
  const room = columns - 1 - segmentsWidth(right)
  if (room >= Math.min(4, cells(input.project))) return spread('title', cutCrumbs(crumbs, room), right, columns)
  const keep = Math.max(0, columns - 1 - Math.min(4, cells(input.project)))
  const shortPill: Segment = { ...pill(input.status), text: fit(pill(input.status).text, keep) }
  const left = cutCrumbs(crumbs, Math.max(0, columns - 1 - cells(shortPill.text)))
  return spread('title', left, keep >= 4 ? [shortPill, ...rescanKey] : rescanKey, columns)
}

/** The header's left side cut to `room`: a detail's label first, then the tab between, then the project's name. */
function cutCrumbs(crumbs: Segment[], room: number): Segment[] {
  if (segmentsWidth(crumbs) <= room) return crumbs
  if (crumbs.length > 1) {
    const label = crumbs[crumbs.length - 1]!
    const head = [crumbs[0]!, { text: ' › ', dim: true }]
    const cut = room - segmentsWidth(head)
    if (cut >= 6) return [...head, { ...label, text: fit(label.text, cut) }]
  }
  return [{ ...crumbs[0]!, text: fit(crumbs[0]!.text, Math.max(0, room)) }]
}

/**
 * The tab bar: every tab by its name with its count as a superscript badge
 * (`Issues²`), the open one on {@link TAB_BG}, a cell of room either side of
 * each; where the names do not fit, and always when `narrow`, the others by
 * their digit (the open one keeps its name), then with no room between.
 * The digits that switch tabs are said in the `h` help, not on the bar.
 *
 * Every tab's hotkey rides on a hidden twin (`tabkey:<id>`, no text) just
 * before it: a Button with a hotkey always draws `N: label`. The pane's focus
 * hook moves a ring landing on a twin onto its visible tab.
 */
export function tabRows(active: PaneTab, columns: number, terminal: boolean, badges: Partial<Record<PaneTab, string>> = {}, narrow = false): Row[] {
  type Tab = (typeof TABS)[number]
  const full = (t: Tab) => `${t.full}${badges[t.id] ?? ''}`
  const digit = (t: Tab) => `${t.hotkey}${badges[t.id] ?? ''}`
  const variants: [(t: Tab) => boolean, number][] = [
    ...(narrow ? [] : [[() => true, 2] as [(t: Tab) => boolean, number], [() => true, 1] as [(t: Tab) => boolean, number]]),
    [t => t.id === active, 2],
    [t => t.id === active, 1],
    [t => t.id === active, 0],
    [() => false, 0],
  ]
  const label = (t: Tab, named: (t: Tab) => boolean) => ` ${named(t) ? full(t) : digit(t)} `
  const width = (named: (t: Tab) => boolean, gap: number) => TABS.reduce((n, t) => n + cells(label(t, named)), 0) + gap * (TABS.length - 1)
  const [named, gap] = variants.find(([l, g]) => width(l, g) <= columns) ?? variants[variants.length - 1]!
  const segments: Segment[] = []
  TABS.forEach((t, i) => {
    if (i > 0 && gap > 0) segments.push({ text: spaces(gap) })
    const on = t.id === active
    segments.push({ text: '', hidden: true, press: { id: `tabkey:${t.id}`, label: t.full, hotkey: t.hotkey } }, button(`tab:${t.id}`, label(t, named), undefined, on ? { bg: TAB_BG } : { dim: true }))
  })
  // Elsewhere tabs are native buttons whose widths the pane cannot know: the bar is the same row.
  void terminal
  return [{ key: 'tabs', segments: clip(segments, columns) }]
}

/**
 * The one boundary every listed row is in, when there are two rows or more
 * and they all share it: a column repeating it on every row says nothing,
 * so the section's note says it once instead.
 */
export function sharedBoundary(rows: { boundary: string | null }[]): string | null {
  const first = rows[0]?.boundary ?? null
  return rows.length > 1 && first !== null && rows.every(r => r.boundary === first) ? first : null
}

/** A component's numbers as a table shows them: in, out, cross and (wide, when known) the files depending on it, or in alone. */
const degreesOf = (item: Item, withDegrees: boolean, withFiles = false): number[] => (withDegrees ? [item.in, item.out, item.cross, ...(withFiles ? [item.files ?? 0] : [])] : [item.in])
const degreeTitles = (withDegrees: boolean, withFiles = false): string[] => (withDegrees ? ['in', 'out', 'cross', ...(withFiles ? ['files'] : [])] : ['in'])

/** Whether a table of `items` at `tier` adds the dependent files: wide, and only when the dashboard counts them. */
const filesColumn = (items: Item[], tier: Tier): boolean => tier === 'wide' && items.some(i => i.files !== null)

/** Where a component is declared, as a table's file column shows it: its file's name and the line. */
const placeIn = (item: Item): string => (item.loc === null ? '' : placeOf(item.loc.path, item.loc.line))

/**
 * How a table of components fits `columns`: without the boundary column when
 * every row shares one, from the medium tier on with the file each is
 * declared in, and wide with each one's kind and dependent files too.
 */
function componentSpec(items: Item[], columns: number, withDegrees: boolean, hues: Hues, tier: Tier): TableSpec {
  const withFiles = withDegrees && filesColumn(items, tier)
  const widths = degreeTitles(withDegrees, withFiles).map((t, i) => numberWidth(t, items.map(item => degreesOf(item, withDegrees, withFiles)[i] ?? 0), tier))
  const labels = sharedBoundary(items) === null ? items.map(i => boundaryLabel(i.boundary, hues)) : []
  const places = tier === 'narrow' ? [] : items.map(placeIn)
  const kinds = tier === 'wide' ? items.map(i => i.kind) : []
  return tableSpec(columns, items.map(i => i.name), labels, widths, undefined, { tier, places, kinds })
}

/** A section's note with the boundary every row shares said once, in front: `all in core · in`. */
function sharedNote(items: Item[], note: string, hues: Hues): string {
  const shared = sharedBoundary(items)
  return shared === null ? note : [`all in ${boundaryLabel(shared, hues)}`, note].filter(s => s !== '').join(' · ')
}

/**
 * The listed components in `window` as table rows laid out by `spec`, with
 * the selection marker on `selected` (an index into the walkable list, the
 * first item being `offset`); the bar draws `sort`.
 */
function componentRows(prefix: string, items: Item[], selected: number, spec: TableSpec, withDegrees: boolean, hues: Hues, sort: HubSort, offset: number, window: { start: number; end: number }, room = CARD_MAX, lit: ReadonlySet<string> = new Set()): Row[] {
  // The spec has a number column per title: a fourth is the dependent files.
  const withFiles = withDegrees && spec.numbers.length > 3
  const titles = degreeTitles(withDegrees, withFiles)
  const max = Math.max(0, ...items.map(i => i[sort]))
  return [
    ...(withDegrees ? [tableHead(`${prefix}-head`, spec, { name: 'name', boundary: 'boundary', numbers: titles })] : []),
    ...items.slice(window.start, window.end).map((item, n) => {
      const i = window.start + n
      return tableRow(
        `${prefix}-${i}`,
        {
          name: item.name,
          boundary: item.boundary,
          values: degreesOf(item, withDegrees, withFiles),
          barValue: item[sort],
          kind: item.kind,
          max,
          selected: offset + i === selected,
          hotspotOnly: item.hotspotOnly,
          press: `row:${offset + i}`,
          place: placeIn(item),
          placeLoc: item.loc,
          preview: previewCard(`card-${prefix}-${i}`, { name: item.name, boundary: item.boundary, figures: componentFigures(item.files, item.in, item.out), top: item.top }, room, hues),
          lit: lit.has(`hub:${item.canonical}`),
          ...(withDegrees ? { sorted: SORTS.indexOf(sort) } : {}),
        },
        spec,
        hues,
      )
    }),
  ]
}

/** The keys and what each does, for the key list. */
const KEY_HELP: [string, string][] = [
  ['1–8', 'switch tabs (or click one)'],
  ['j k', 'move the marker (or Tab, or a click)'],
  ['o', 'open the marked row: a component or a file shows what depends on it, an Overview bar the tab it counts'],
  ['e', "open the marked row's file in your editor"],
  ['b', 'back from a detail'],
  ['c', "copy the marked row's full name or path"],
  ['q', 'ask Claude about the marked row: your press sends the prompt'],
  ['t', "on Overview and Changes: copy the command for the tests that reach this session's changes"],
  ['d', 'list the files drifted since the snapshot, or hide them'],
  ['u', 'on Overview and Changes: list only the changed files no test reaches, or every file again'],
  ['l', "on Boundaries: move the marked cell to the next boundary the marked one depends on, and spell it out"],
  ['f', 'find any component or file by the letters of its name; Enter opens the first match, x closes the finder'],
  ['p', "in a component's detail: pick another component in the finder and draw the route between them"],
  ['m', "in a component's detail: add a note to it; knossos checks it first, and y records it"],
  ['n s x', 'on Hubs: narrow the list (type, then Enter), sort by in, out or cross, clear the narrowing or the in-degree range'],
  ['r', 'rescan the project now, fresh or not'],
  ['a', 'allow a refused root (asks first)'],
]
const KEY_WIDTH = Math.max(...KEY_HELP.map(([k]) => cells(k))) + 2

/** How long the footer's word after an action stays, in milliseconds. */
export const FEEDBACK_MS = 2_000

/** The footer bar's ground: a faint fill across the pane, under the keys. */
export const BAR_BG = 'composerSidebarBackground'

/** The cells of room inside the header and the footer bar, either side, per tier. */
export const padOf = (tier: Tier): number => (tier === 'narrow' ? 1 : 2)

/** `row` set `pad` cells in from the left; with `bg`, that ground laid across the whole of `columns`, padding included. */
function padded(row: Row, pad: number, columns: number, bg?: string): Row {
  const used = pad + rowWidth(row)
  const segments: Segment[] = [{ text: spaces(pad) }, ...row.segments, ...(bg !== undefined && used < columns ? [{ text: spaces(columns - used) }] : [])]
  return { ...row, segments: bg === undefined ? segments.filter(s => s.text !== '' || s.hidden === true) : tinted(segments, bg) }
}

/**
 * The footer bar: the keys, grouped, on a faint ground across the pane,
 * padded in from either edge. Moving about (the marker, back) dim on the
 * left, what can be done to the marked row on the right, and between them,
 * for a moment after an action, what it did (`✓ copied`, `✗ no editor`).
 * Once the header is scrolled out of view (`scrolled`), the graph's state
 * stands at the right edge (`● live · 7s`). Only the keys that do something
 * in this view are offered. Each group stays whole; past the width the
 * actions wrap under the moves, and a key is never dropped, since it would
 * stop working. The key list `h` opens is returned apart (`help`): it is
 * drawn above the bar, not in it.
 */
export function footerRows(input: PaneInput, columns: number, hasList: boolean, scrolled = false): { bar: Row[]; help: Row[] } {
  const moves: Segment[] = []
  const actions: Segment[] = []
  if (input.finder !== null) return finderFooter(input, columns, hasList)
  const onTab = input.detail === null && input.route === null && !input.driftOpen
  if (input.detail !== null || input.route !== null) moves.push(button('back', 'back', 'b'))
  // On Cycles the marker walks the boxes the diagram draws, which only the width decides: the keys name the row they move to.
  const tier = tierOf(columns)
  const marked = Math.min(Math.max(0, input.selected), Math.max(0, listFor(input).length - 1))
  const steps = onTab && input.tab === 'cycles' ? cycleSteps(input.cycles, cardInner(columns, tier), tier, marked) : null
  const unfold = onTab && input.tab === 'cycles' ? unfoldPress(input.cycles, cardInner(columns, tier), tier, marked) : null
  if (hasList) moves.push(button(steps === null ? 'down' : `next:${steps.next}`, '↓', 'j'), button(steps === null ? 'up' : `prev:${steps.prev}`, '↑', 'k'))
  // A boundary has nothing to open: the marker only shows what it depends on. A cycle's fold opens into its members.
  if (unfold !== null) actions.push(button(unfold, 'unfold', 'o'))
  else if (hasList && !listFor(input).every(item => item.inert === true)) actions.push(button('open', 'open', 'o'))
  // The same keys on every list: `e` whenever the marked row has a file.
  if (editTarget(input) !== null) actions.push(button('edit', 'edit', 'e'))
  if (subjectOf(input) !== null) actions.push(button('copy', 'copy', 'c'), button('ask', 'ask Claude', 'q'))
  if (onTab && input.tab === 'boundaries' && input.boundaries !== null && markedCell(input.boundaries, input.selected, input.target) !== null) actions.push(button('target', 'next cell', 'l'))
  // On Overview `t` stands beside the tests it copies, in "This session".
  if (onTab && input.tab === 'changes' && input.changes.command !== null) actions.push(button('tests', 'copy test command', 't'))
  if (onTab && (input.tab === 'changes' || input.tab === 'overview') && input.changes.untested > 0) actions.push(button('untested', input.tab === 'changes' && input.changes.untestedOnly ? 'all files' : 'untested', 'u'))
  if (input.detail === null && input.drift !== null) actions.push(button('drift', input.driftOpen ? 'hide drifted' : 'drifted files', 'd'))
  if (onTab && input.tab === 'hubs') {
    actions.push(button('filter', 'narrow', 'n'), button('sort', `sort: ${input.sort}`, 's'))
    if (input.filter !== '' || input.degree !== null) actions.push(button('clear', 'clear', 'x'))
  }
  // From a component's own detail, a route to any other component (the finder picks where it ends).
  if (input.route === null && input.detail?.component !== null && input.detail?.component !== undefined && input.detail.file === undefined) actions.push(button('route', 'route to…', 'p'))
  actions.push(button('find', 'find', 'f'), button('keys', input.showKeys ? 'hide keys' : 'keys', 'h'))
  const pad = padOf(tier)
  const inner = Math.max(1, columns - 2 * pad)
  const said: Segment[] = input.feedback === null ? [] : [{ text: input.feedback.text, color: input.feedback.tone === 'ok' ? STATUS_COLOURS.ok : STATUS_COLOURS.alert }]
  const state: Segment[] = scrolled ? [{ text: `● ${input.status.text}`, color: STATUS_COLOURS[input.status.tone] }] : []
  const left = joinGroups(moves.map(k => [{ ...k, dim: true }]))
  const right = joinGroups([...actions.map(k => [{ ...k, dim: true }]), ...(state.length > 0 ? [state] : [])])
  const lines: Row[] = []
  const middle = said.length === 0 ? [] : [{ text: '   ' }, ...said]
  if (segmentsWidth(left) + segmentsWidth(middle) + 2 + segmentsWidth(right) <= inner) {
    lines.push(spread('keys', [...left, ...middle], right, inner))
  } else {
    if (said.length > 0) lines.push({ key: 'keys-said', segments: clip(said, inner) })
    if (moves.length > 0) lines.push(...wrapGroups('keys', moves.map(k => [{ ...k, dim: true }]), inner))
    lines.push(...wrapGroups(moves.length > 0 ? 'keys-actions' : 'keys', [...actions.map(k => [{ ...k, dim: true }]), ...(state.length > 0 ? [state] : [])], inner))
  }
  // One key a line, its action wrapped beside it: a list to look up, not a paragraph to read.
  return { bar: lines.map(row => padded(row, pad, columns, BAR_BG)), help: input.showKeys ? keyHelp(inner, pad, columns) : [] }
}

/**
 * The footer bar while the finder is open: moving through the matches, and
 * opening the marked one, closing the finder and the key list. While the
 * field holds the focus the keys type into it: Enter opens the first match,
 * and a click opens any.
 */
function finderFooter(input: PaneInput, columns: number, hasList: boolean): { bar: Row[]; help: Row[] } {
  const pad = padOf(tierOf(columns))
  const inner = Math.max(1, columns - 2 * pad)
  const moves = hasList ? [button('down', '↓', 'j'), button('up', '↑', 'k')] : []
  const actions = [...(hasList ? [button('open', 'open', 'o')] : []), button('find-close', 'close', 'x'), button('keys', input.showKeys ? 'hide keys' : 'keys', 'h')]
  const left = joinGroups(moves.map(k => [{ ...k, dim: true }]))
  const right = joinGroups(actions.map(k => [{ ...k, dim: true }]))
  const lines = segmentsWidth(left) + 2 + segmentsWidth(right) <= inner ? [spread('keys', left, right, inner)] : [...(moves.length > 0 ? wrapGroups('keys', moves.map(k => [{ ...k, dim: true }]), inner) : []), ...wrapGroups(moves.length > 0 ? 'keys-actions' : 'keys', actions.map(k => [{ ...k, dim: true }]), inner)]
  return { bar: lines.map(row => padded(row, pad, columns, BAR_BG)), help: input.showKeys ? keyHelp(inner, pad, columns) : [] }
}

/** The key list `h` opens: one key a line, its action wrapped beside it. */
function keyHelp(inner: number, pad: number, columns: number): Row[] {
  const help: Row[] = []
  let n = 0
  for (const [key, action] of [...KEY_HELP, ['', 'A file:line opens in your editor on a click. Every key has a button.'] as [string, string]]) {
    wrapWords(action, Math.max(1, inner - KEY_WIDTH)).forEach((line, i) =>
      help.push(padded({ key: `help-${n++}`, segments: [{ text: padEnd(i === 0 ? key : '', KEY_WIDTH), color: ACCENT }, { text: line, dim: true }] }, pad, columns)),
    )
  }
  return help
}

/** Groups of segments on one line, two cells apart. */
function joinGroups(groups: Segment[][]): Segment[] {
  return groups.flatMap((g, i) => (i === 0 ? g : [{ text: '  ' }, ...g]))
}

/** The hubs tab: one card with the filter field or line, and the filtered, sorted table, as long as the pane allows. */
function hubsBlock(input: PaneInput, list: Item[], selected: number, tier: Tier): Block {
  const hotspots = input.items.some(i => i.hotspotOnly) ? '◆ hotspot only' : ''
  const range = input.degree
  const note = sharedNote(list, [hotspots, input.partial ? 'partial' : ''].filter(s => s !== '').join(' · '), input.hues)
  return {
    key: 'hubs',
    grow: { length: list.length, min: HUBS_MIN },
    make: (columns, limit) => {
      const rows: Row[] = []
      if (input.filtering) {
        rows.push({ key: 'filter-row', segments: [{ text: '   filter: ', dim: true }, { text: input.filter, field: { id: 'filter', value: input.filter, placeholder: 'part of a name' } }] })
      } else if (input.filter !== '') {
        rows.push(dimRow('filter-row', `   filter "${input.filter}" · ${list.length} of ${input.items.length}`, columns))
      }
      if (range !== null) {
        // The list holds the most depended on only: the bucket may count many more than it lists.
        const counted = range.components === null ? '' : ` of ${grouped(range.components)}`
        rows.push(dimRow('degree-row', `   in-degree ${range.to === null ? `${range.from}+` : range.from === range.to ? `${range.from}` : `${range.from}–${range.to}`} · ${list.length} listed${counted}`, columns))
      }
      const section = (body: Row[]): Section => ({ key: 'hubs', title: 'Hubs and hotspots', subtitle: `sorted by ${input.sort}`, note: noteOf(note), body })
      if (list.length === 0) return section([...rows, dimRow('hubs-none', input.filter === '' ? (range === null ? '   none' : '   none of the listed hubs is in this range') : `   no hub matches "${input.filter}"`, columns)])
      const spec = componentSpec(list, columns, true, input.hues, tier)
      const local = selected < list.length ? selected : -1
      const window = windowOf(list.length, limit, local)
      return section([...rows, ...componentRows('hub', list, selected, spec, true, input.hues, input.sort, 0, window, columns, input.lit), ...moreRows('hub-window', window, list.length, columns, 0)])
    },
  }
}

/** The Hubs tab: the components most depended on and, beside them when wide, the files; the filter narrows both. */
function hubsArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  const list = hubsShown(input)
  const hubs = hubsBlock(input, list, selected, tier)
  const files = fileHubsBlock(filesShown(input), selected, list.length, tier, input.hues, input.filter === '' ? '' : `filter "${input.filter}"`, input.lit)
  // The components' table has more columns than the files': it takes the larger share, in a grid row of fixed columns.
  return files === null ? { left: [hubs] } : { left: [hubs], right: [files], rows: [[hubs, files]], split: 0.6 }
}

/** What "ask Claude to scan it" asks: the model scans through the Knossos server, into the graph the pane reads. */
export const SCAN_PROMPT = 'Scan this project with Knossos (scan_project), then give me a short summary of its architecture.'

/**
 * Why the pane has no figures to draw: the first load is still on its way,
 * the last load came back silent or as an error, or knossos answered that
 * it has never scanned this project. Only the last is a reason to scan.
 */
export type NoGraph = 'loading' | 'unreadable' | 'unscanned'

/** Which {@link NoGraph} state a dashboard that is not `ok` (or none yet) is in. */
export function noGraphOf(d: Dashboard | null, refresh: RefreshState): NoGraph {
  if (refresh.failed || d?.status === 'error') return 'unreadable'
  if (d?.status === 'unscanned') return 'unscanned'
  return refresh.fetchedAt === null ? 'loading' : 'unreadable'
}

const NO_GRAPH: Record<NoGraph, { head: string; why: string }> = {
  loading: { head: 'Reading the graph…', why: 'Knossos is loading the architecture graph of this project.' },
  unreadable: { head: 'Could not read the graph', why: 'Knossos did not answer, or answered with an error; retrying.' },
  unscanned: { head: 'No architecture graph yet', why: 'Knossos has not scanned this project, so there is nothing to draw.' },
}

/**
 * The pane with no dashboard to draw: a heading saying which state it is
 * in, why, and at most one thing to do about it. When the project's root
 * was refused, that one thing is the allow-root offer. Otherwise only a
 * project knossos says it never scanned offers asking Claude to scan it:
 * a load still on its way, or one that failed, is no reason to scan, so a
 * habitual `q` there sends nothing.
 */
export function emptyRows(state: NoGraph, allow: AllowInput | null, columns: number): Row[] {
  const width = Math.max(1, Math.min(CONTENT_MAX, columns))
  const { head: heading, why } = NO_GRAPH[state]
  const head: Row[] = [
    { key: 'empty-head', segments: [{ text: fit(heading, width), bold: true, color: HEADING }] },
    ...wrapWords(why, width).map((line, i) => dimRow(`empty-${i}`, line, width)),
  ]
  if (allow !== null) return [...head, blank('gap-empty'), ...allowRows(allow, width)]
  if (state !== 'unscanned') return head
  return [...head, blank('gap-empty'), { key: 'empty-action', segments: [button('scan-ask', 'ask Claude to scan it', 'q', { dim: false })] }]
}

/**
 * The files most depended on, as a card: each file's path (cut from the
 * front, so its name stays), its boundary, a bar and how many files depend
 * on it; `limit` of them around the marker, each a row it walks from
 * `offset`. Null when the dashboard lists none.
 */
function fileHubsBlock(files: FileHub[], selected: number, offset: number, tier: Tier, hues: Hues, note = '', lit: ReadonlySet<string> = new Set()): Block | null {
  if (files.length === 0) return null
  return {
    key: 'files',
    grow: { length: files.length, min: FILES_MIN },
    make: (columns, limit) => {
      const local = selected - offset
      const window = windowOf(files.length, limit, local >= 0 && local < files.length ? local : -1)
      const shared = sharedBoundary(files)
      const labels = shared === null ? files.map(f => boundaryLabel(f.boundary, hues)) : []
      const spec = tableSpec(columns, files.map(f => f.path), labels, [numberWidth('', files.map(f => f.dependents), tier)], 56, { tier })
      const max = Math.max(0, ...files.map(f => f.dependents))
      const body = files.slice(window.start, window.end).map((f, n) => {
        const i = window.start + n
        const preview = previewCard(`card-files-${i}`, { name: baseName(f.path), boundary: f.boundary, figures: fileFigures(f.dependents), top: f.top }, columns, hues)
        return tableRow(`files-${i}`, { name: f.path, boundary: f.boundary, values: [f.dependents], max, path: true, selected: offset + i === selected, press: `row:${offset + i}`, preview, lit: lit.has(`file:${f.path}`) }, spec, hues)
      })
      const said = [shared === null ? '' : `all in ${boundaryLabel(shared, hues)}`, note, 'dependent files'].filter(t => t !== '').join(' · ')
      return { key: 'files', title: 'Files most depended on', note: noteOf(said), body: [...body, ...moreRows('files-window', window, files.length, columns, offset)] }
    },
  }
}

/**
 * The Overview: the stat tiles across the top, then the charts. Wide, a
 * fixed grid of two equal columns, the cards of a row drawn equally tall:
 * this session across the pane, composition beside dependency
 * concentration, health over time beside the cross-boundary flows.
 * Narrower, one column in the same order. The marker walks the way to
 * Changes, the buckets and the flows, in that order; the lists themselves
 * are on Hubs.
 */
function overviewArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  const data = input.overview
  const session = input.changes.files.length > 0 ? 1 : 0
  const tiles = input.stats.length > 0 ? [tilesBlock(input.stats, tier)] : []
  const sessionCard = sessionBlock(input.changes, input.lookAt, selected)
  const composition = compositionBlock(data)
  const concentration = concentrationBlock(data, selected, session)
  const health = healthBlock(data)
  const flows = flowsBlock(data, input.hues, selected, session + data.buckets.length)
  const present = (blocks: (Block | null)[]): Block[] => blocks.filter((b): b is Block => b !== null)
  // A card with nothing to show is one line: it stands on a row of its own rather than beside a card it cannot match in height.
  const quiet = data.flows.length === 0
  const rows: Block[][] = [[sessionCard], present([composition, concentration]), present([health, ...(quiet ? [] : [flows])]), ...(quiet ? [[flows]] : [])].filter(line => line.length > 0)
  // A tall pane's spare rows go to the files a change is riskiest in and the files most depended on, rather than to blank space.
  const extra = present([spotsBlock(input), topFilesBlock(input)])
  return { top: tiles, left: [], rows, split: 0.5, order: [...tiles, sessionCard, ...present([composition, concentration, health, flows])], ...(extra.length > 0 ? { extra: [extra] } : {}) }
}

/** The fewest rows a secondary list on the Overview shows when it shows at all. */
const SECONDARY_MIN = 3

/**
 * A secondary list of files on the Overview: each file's path in two tones
 * (a link that opens it), a bar and its figures. Not walked by the marker:
 * it shows only when the pane has room to spare, and the walk must not
 * depend on the height. Null with nothing to list.
 */
function fileListBlock(key: string, title: string, note: string, files: { path: string; values: number[]; bar: number; loc: Loc | null }[], titles: string[], tier: Tier, hues: Hues): Block | null {
  if (files.length === 0) return null
  return {
    key,
    grow: { length: files.length, min: SECONDARY_MIN },
    make: (columns, limit) => {
      const window = windowOf(files.length, limit)
      const shown = files.slice(0, window.end)
      const spec = tableSpec(columns, shown.map(f => f.path), [], titles.map((t, i) => numberWidth(t, shown.map(f => f.values[i] ?? 0), tier)), 56, { tier })
      const max = Math.max(0, ...files.map(f => f.bar))
      const body: Row[] = [tableHead(`${key}-cols`, spec, { name: 'file', boundary: '', numbers: titles })]
      shown.forEach((f, i) => body.push(tableRow(`${key}-${i}`, { name: f.path, boundary: null, values: f.values, barValue: f.bar, max, path: true, link: f.loc }, spec, hues)))
      body.push(...moreRows(`${key}-window`, window, files.length, columns))
      return { key, title, note: noteOf(note), body }
    },
  }
}

/** The Overview's secondary card of complexity hotspots (as Issues lists them). */
const spotsBlock = (input: PaneInput): Block | null =>
  fileListBlock('hotspots', 'Complexity hotspots', 'lines × dependents', input.issues.hotspots.map(f => ({ path: f.path, values: [f.lines, f.dependents], bar: f.score, loc: f.loc })), ['lines', 'deps'], 'medium', input.hues)

/** The Overview's secondary card of the files most depended on (as Hubs lists them). */
const topFilesBlock = (input: PaneInput): Block | null =>
  fileListBlock('files', 'Files most depended on', 'dependent files', input.fileHubs.map(f => ({ path: f.path, values: [f.dependents], bar: f.dependents, loc: f.loc })), ['deps'], 'medium', input.hues)

/**
 * The pane laid out: its rows from the header down (`body`) and the footer
 * bar (`footer`). When the two fit the height, the body is filled with blank
 * rows so the bar sits at the bottom of the pane; when they do not (`pinned`),
 * the bar is drawn over the window's last rows wherever the pane is scrolled
 * to, and the body ends in as many blank rows as the bar has, so its last row
 * can still be scrolled clear of it. `offset` is how far the pane is scrolled:
 * once the header is out of view, the bar says the graph's state.
 *
 * The header has room around it: a blank row above the name and another
 * between it and the tabs, both padded in from the edges, then a blank row
 * and a rule before the first card. Narrow, the row above the name goes; on
 * a short pane (24 rows or fewer) the blank rows go first, never a line that
 * says something.
 */
export function paneLayout(input: PaneInput, columns: number, height: number = DEFAULT_ROWS, offset = 0): { body: Row[]; footer: Row[]; pinned: boolean } {
  const width = Math.max(1, columns)
  const tier = tierOf(width)
  const list = listFor(input)
  const selected = Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))
  const short = height <= SHORT_PANE
  const pad = padOf(tier)
  const inner = Math.max(1, width - 2 * pad)
  const rows: Row[] = []
  if (!short && tier !== 'narrow') rows.push(blank('head-top'))
  rows.push(padded(titleRow(input, inner, tier), pad, width))
  if (input.detail === null && input.route === null) {
    const count = issueCount(input.issues)
    const touched = input.changes.files.length
    const branched = branchCount(input.branch)
    const badges: Partial<Record<PaneTab, string>> = {
      ...(count.n > 0 ? { issues: superscript(count.n, count.plus) } : {}),
      ...(touched > 0 ? { changes: superscript(touched, input.changes.truncated) } : {}),
      ...(branched > 0 ? { branch: superscript(branched) } : {}),
    }
    if (!short) rows.push(blank('head-gap'))
    rows.push(...tabRows(input.tab, inner, input.terminal, badges, tier === 'narrow').map(row => padded(row, pad, width)))
  }
  if (!short) rows.push(blank('head-end'))
  rows.push({ key: 'head-rule', segments: [{ text: '─'.repeat(width), color: FAINT }] })
  const header = rows.length
  if (input.allow !== null) rows.push(blank('gap-allow-top'), ...allowRows(input.allow, width))
  const { bar, help } = footerRows(input, width, list.length > 0, offset >= header)
  const footer = [...(short ? [] : [blank('gap-keys')]), ...bar]
  const room = () => height - rows.length - footer.length - help.length
  if (input.finder !== null) {
    // The finder stands in for the tab or the detail while it is open; closing it puts them back as they were.
    rows.push(...fillColumn(fitBlocks([finderBlock(input.finder, selected, tier, input.hues)], width, tier, room()), width, room(), tier))
  } else if (input.route !== null) {
    rows.push(...arrange(routeArrangement(input.route, selected, input.hues), width, room(), true))
  } else if (input.detail !== null) {
    rows.push(...arrange(detailOf(input.detail, tier, input.hues, selected), width, room(), true))
  } else {
    // Listed under the tabs, the drifted files hold the marker; the tab below shows none. They take a third of the room.
    const drift = input.drift
    if (input.driftOpen && drift !== null) {
      const min = Math.max(3, Math.floor(room() / 3))
      rows.push(...fitBlocks([{ key: 'drift', grow: { length: drift.items.length, min }, make: (cards, limit) => driftSection(drift, cards, limit, input.hues, selected) }], width, tier, 0))
    }
    const tabSelected = input.driftOpen ? -1 : selected
    const peek = input.driftOpen ? null : peekShown(input, tier)
    if (peek === null) {
      rows.push(...arrange(tabArrangement(input, tabSelected, tier), width, room(), true))
    } else {
      // Wide, master-detail: the tab on the left, the marked row's detail beside it, both to the pane's foot.
      // Each panel is one framed column, as a medium pane draws it, whatever its own width.
      const [left, right] = gridColumns(width, PEEK_SPLIT)
      const height = Math.max(0, room())
      const master = fillColumn(arrange(tabArrangement(input, tabSelected, PANEL), left, height, true, PANEL), left, height, PANEL, 'stretch-m')
      const detail = fillColumn(peekRows(peek, right, height, input.hues), right, master.length, PANEL, 'stretch-d')
      rows.push(...besideRows(master.length >= detail.length ? master : fillColumn(master, left, detail.length, PANEL, 'stretch-m'), detail, left))
    }
  }
  if (help.length > 0) rows.push(blank('gap-help'), ...help)
  const body = rows.map(row => (row.code !== undefined || rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
  // Counted as drawn: a diff element takes a row for each of its lines, so a long change pins the bar and scrolls.
  const fill = height - rowsHeight(body) - footer.length
  if (fill >= 0) return { body: [...body, ...Array.from({ length: fill }, (_, i) => blank(`fill-${i}`))], footer, pinned: false }
  return { body, footer, pinned: true }
}

/** The tabs whose marked row shows its detail beside the list on a wide pane. */
export const PEEK_TABS: readonly PaneTab[] = ['hubs', 'changes', 'issues', 'cycles', 'branch', 'churn']
/** The list's share of a wide pane beside the marked row's detail. */
const PEEK_SPLIT = 0.55
/** How each panel of master-detail is laid out: one column of framed cards. */
const PANEL: Tier = 'medium'

/** A component's or a file's detail arrangement. */
const detailOf = (detail: DetailInput, tier: Tier, hues: Hues, selected: number): Arrangement =>
  detail.file === undefined ? detailArrangement(detail, tier, hues, selected) : fileDetailArrangement(detail, tier, hues, selected)

/** The detail drawn beside the tab: on a wide pane, on a tab that has one, while the finder is shut and something is marked. */
function peekShown(input: PaneInput, tier: Tier): DetailInput | null {
  if (tier !== 'wide' || input.peek === null || input.finder !== null || !PEEK_TABS.includes(input.tab)) return null
  return input.peek
}

/**
 * The marked row's detail as the panel beside the tab draws it: the detail's
 * own cards at the panel's width, its presses renamed `peek:N` (they open
 * what the panel lists, not what the tab lists), and no marker of its own.
 */
function peekRows(peek: DetailInput, width: number, height: number, hues: Hues): Row[] {
  const rows = arrange(detailOf({ ...peek, panel: true }, PANEL, hues, -1), width, height, true, PANEL)
  // Its lists' presses open what it lists; the line under a cut one is text, since no marker walks the panel.
  const pressOf = (seg: Segment): Segment => {
    if (seg.press === undefined) return seg
    if (/^(rel|row):\d+$/.test(seg.press.id)) return { ...seg, press: { ...seg.press, id: `peek:${seg.press.id.slice(seg.press.id.indexOf(':') + 1)}` } }
    if (seg.press.id.startsWith('more:')) return { text: seg.text, dim: true }
    return seg
  }
  return rows.map(row => ({ ...row, key: `peek-${row.key}`, segments: row.segments.map(pressOf) }))
}

/** The rows the detail beside the tab lists, which its `peek:N` presses open. */
export function peekList(peek: DetailInput): Openable[] {
  return peek.file === undefined ? detailList(peek) : peek.file === null ? [] : fileDetailList(peek.file)
}

/** A pane this many rows tall or shorter keeps its header tight: no blank rows around it. */
export const SHORT_PANE = 24

/** The open tab's cards. */
function tabArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  if (input.tab === 'overview') return overviewArrangement(input, selected, tier)
  if (input.tab === 'changes') return changesArrangement(input.changes, selected, tier, input.hues, input.lit)
  if (input.tab === 'hubs') return hubsArrangement(input, selected, tier)
  if (input.tab === 'issues') return issuesArrangement(input.issues, selected, tier, input.hues)
  if (input.tab === 'cycles') return cyclesArrangement(input.cycles, tier, input.hues, selected)
  if (input.tab === 'branch') return branchArrangement(input.branch, selected, input.hues)
  if (input.tab === 'churn') return churnArrangement(input.churn, selected, tier, input.hues)
  return boundariesArrangement(input.boundaries, tier, input.hues, selected, input.target, input.couplings, input.lit)
}

/** How many proportional tries {@link shrinkRows} makes before it lays the lists out at their fewest rows. */
const SHRINK_TRIES = 4

/**
 * A pane drawn with fewer rows until its weight (`draw`'s, as the engine
 * bounds a tree) is within `budget`: each try lays out fewer rows than the
 * last, in proportion to the budget, and once those tries are spent with the
 * lists still above `fewest` rows, one more draw at `fewest` decides. So at
 * most {@link SHRINK_TRIES} + 1 draws, and the caller falls back to less
 * only when even `fewest` rows are too heavy. `first` is the draw already made
 * at `rows`.
 */
export function shrinkRows<T>(rows: number, fewest: number, budget: number, first: { tree: T; weight: number }, draw: (rows: number) => { tree: T; weight: number }): { tree: T; weight: number; rows: number } {
  let drawn = { ...first, rows }
  for (let tries = 0; drawn.weight > budget && drawn.rows > fewest; tries++) {
    const next = tries < SHRINK_TRIES ? Math.max(fewest, Math.min(drawn.rows - 2, Math.floor((drawn.rows * budget) / drawn.weight) - 2)) : fewest
    drawn = { ...draw(next), rows: next }
  }
  return drawn
}
