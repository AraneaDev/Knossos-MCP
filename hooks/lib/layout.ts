/**
 * The pane's layout: every row it draws, fitted to the columns it has.
 *
 * Pure: a view of the dashboard and the mod's state in, rows of styled
 * segments out. The render hook only turns segments into elements, so every
 * decision about width, order and colour is made here and tested here. The
 * shared primitives (segments, cutting, bars, the self-fitting table) are in
 * `rows.ts`; the Issues and Cycles tabs and the component detail in `views.ts`.
 */
import type { AllowState, CouplingState, Dashboard, Feedback, GitHead, HubSort, KnossosView, LiveState, PaneTab, Ranked, RefreshState, RescanState, SessionChanges, TurnBrief } from '../../types'
import { formatAge } from './band'
import { boundariesArrangement, boundariesInput, boundariesList, couplingView, heatBlock, markedCell } from './boundaries'
import type { BoundariesInput, CouplingView } from './boundaries'
import { changesArrangement, changesInput, changesList, lookAtList, lookAtOf, lookAtSection, NO_CHANGES } from './changes'
import type { ChangesInput, LookAt } from './changes'
import { countLabel } from './envelopes'
import { driftInput, driftList, driftSection, fileDetailArrangement, fileDetailList } from './files'
import type { DriftInput } from './files'
import { ACCENT, boundaryLabel, CHIP_BG, declaredOf, HEADING, huesOf, ON_FILL, STATUS_COLOURS } from './palette'
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
  rowWidth,
  segmentsWidth,
  spaces,
  spread,
  tableHead,
  tableRow,
  tableSpec,
  tierOf,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment, TableSpec, Tier } from './rows'
import { LIVE_OFF } from './live'
import { CARD_MAX, componentFigures, fileFigures, previewCard } from './hover'
import { tilesBlock } from './tiles'
import type { Stat } from './tiles'
import { trendBlock } from './trend'
import type { Series } from './trend'
import { cyclesArrangement, cyclesInput, cyclesList, detailArrangement, detailList, issueCount, issuesArrangement, issuesInput, issuesList, locIn, superscript } from './views'
import { arrange, DEFAULT_ROWS, fitBlocks, moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import type { CyclesInput, DetailInput, IssuesInput, Openable } from './views'

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
export type { Series } from './trend'

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

export type LastTurn = {
  files: number
  dependents: number
  tests: number
  impact: { name: string; path: string; boundary: string | null; dependents: number; loc: Loc | null }[]
}

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
  lastTurn: LastTurn | null
  /** The stat tiles across the top of the Overview. */
  stats: Stat[]
  /** The series the Overview's trend chart may draw. */
  trend: Series[]
  /** The files most depended on, most first. */
  fileHubs: FileHub[]
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
}

/** A refused root the pane offers to allow: the root, the roots file it would join, and where the action stands. */
export type AllowInput = { root: string; rootsFile: string | null; phase: AllowState['phase']; reason: string | null }

/** The tabs in hotkey order; Changes came last, so the older tabs keep their digits. */
export const TABS: { id: PaneTab; full: string; hotkey: string }[] = [
  { id: 'overview', full: 'Overview', hotkey: '1' },
  { id: 'hubs', full: 'Hubs', hotkey: '2' },
  { id: 'boundaries', full: 'Boundaries', hotkey: '3' },
  { id: 'cycles', full: 'Cycles', hotkey: '4' },
  { id: 'issues', full: 'Issues', hotkey: '5' },
  { id: 'changes', full: 'Changes', hotkey: '6' },
]

export const SORTS: HubSort[] = ['in', 'out', 'cross']

/** The fewest components and last-turn files the overview lists, however short the pane. */
const TOP_MIN = 3
const TURN_MIN = 3
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
export type ListInput = Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'issues' | 'detail' | 'changes' | 'cycles' | 'boundaries' | 'lookAt' | 'lastTurn' | 'drift' | 'driftOpen' | 'fileHubs'>

/** A file row of a list: it opens as the file's detail, and `e` opens the file. */
const fileRow = (path: string, loc: Loc | null): Openable => ({ name: path, canonical: path, loc, file: true })

/** The pane's state the layout does not read from the dashboard: where the checkout stands, the footer's word, and the Boundaries tab's cell. */
export type PaneExtras = { git?: GitHead | undefined; feedback?: Feedback | null; couplings?: CouplingState | null }

/**
 * The Overview's walkable rows, in the order a narrow pane draws them: the
 * file "Look at now" points at, the last turn's files, the components most
 * depended on, then the files most depended on.
 */
export function overviewList(input: Pick<PaneInput, 'items' | 'lookAt' | 'lastTurn' | 'fileHubs'>): Openable[] {
  const turn = (input.lastTurn?.impact ?? []).map(f => fileRow(f.path, f.loc))
  return [...lookAtList(input.lookAt), ...turn, ...input.items, ...input.fileHubs.map(f => fileRow(f.path, f.loc))]
}

/** The rows the selection walks: the detail's counterparts, else the drifted files when listed, else the tab's list. */
export function listFor(input: ListInput): Openable[] {
  if (input.detail !== null) return input.detail.file === undefined ? detailList(input.detail) : input.detail.file === null ? [] : fileDetailList(input.detail.file)
  if (input.driftOpen && input.drift !== null) return driftList(input.drift)
  if (input.tab === 'overview') return overviewList(input)
  if (input.tab === 'hubs') return [...hubList(input.items, input.filter, input.sort), ...fileHubList(input.fileHubs, input.filter).map(f => fileRow(f.path, f.loc))]
  if (input.tab === 'changes') return changesList(input.changes)
  if (input.tab === 'cycles') return cyclesList(input.cycles)
  if (input.tab === 'boundaries') return boundariesList(input.boundaries)
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

/** Whether a rescan would change anything: the snapshot is stale or files drifted since it. */
export function needsRescan(d: Dashboard): boolean {
  return d.freshness.state !== 'fresh' || d.freshness.drift_files > 0
}

/**
 * The last turn's impact from a fresh brief, or null when there is none to
 * show. Each file is labelled with its own boundary (none when it sits in
 * none), never with its dependents'. `root` places the files on disk, so
 * their names open them.
 */
export function lastTurnOf(brief: TurnBrief | null, root: string | null = null): LastTurn | null {
  if (brief === null || brief.status !== 'ok') return null
  const impact = Object.values(brief.impact)
    .sort((a, b) => b.dependent_files - a.dependent_files || a.path.localeCompare(b.path))
    .map(f => ({ name: baseName(f.path), path: f.path, boundary: f.boundary ?? null, dependents: f.dependent_files, loc: locIn(root, f.path) }))
  const files = brief.changed_files.length + brief.added_files.length
  if (files === 0 && impact.length === 0) return null
  return { files, dependents: impact.reduce((n, f) => n + f.dependents, 0), tests: brief.tests.length, impact }
}

const LANGUAGES: Record<string, string> = { php: 'PHP', javascript: 'JS', typescript: 'TS', python: 'PY', rust: 'RS', go: 'GO', ruby: 'RB', java: 'JAVA' }

/** The project's languages as the summary line names them, most files first; empty when the dashboard does not say. */
const languagesOf = (d: Dashboard): string => (d.summary?.languages ?? []).map(l => LANGUAGES[l.language] ?? l.language.toUpperCase()).join(' ')

/**
 * The stat tiles: components and boundaries (from a dashboard that reports
 * them), cycles and the largest degree with their trends, dead code, the
 * drifted files (a press that lists them, when the dashboard names them),
 * then the policy violations and diagnostics when they were checked. Each
 * says how it deviates: cycles and diagnostics above zero warn, violations
 * above zero are errors, drift above zero is the accent.
 */
export function statsOf(d: Dashboard, summary: string[], policy: string | null, diagnostics: number | null, drift: boolean): Stat[] {
  const above = (value: string) => value !== '0'
  const cycles = countLabel(d.cycles.count, d.cycles.truncated)
  const dead = countLabel(d.dead_code_candidates, d.dead_code_truncated)
  const maxDegree = d.trend.at(-1)?.max_degree ?? null
  const drifted = d.freshness.drift_files
  // From the summary line's own parts, so a figure reads the same in both: `9,008 components`, `7+ boundaries`.
  const counted = (part: string | undefined, label: string): Stat[] => (part === undefined ? [] : [{ key: label, label, value: part.slice(0, part.indexOf(' ')) }])
  return [
    ...(d.summary === undefined ? [] : [...counted(summary[0], 'components'), ...counted(summary[1], summary[1]?.endsWith('boundary') ? 'boundary' : 'boundaries')]),
    { key: 'cycles', label: d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles', value: cycles, trend: d.trend.map(t => t.cycles), ...(above(cycles) ? { tone: 'warn' as const } : {}) },
    ...(maxDegree === null ? [] : [{ key: 'degree', label: 'max degree', value: String(maxDegree), trend: d.trend.map(t => t.max_degree) }]),
    { key: 'dead', label: 'dead code', value: dead },
    { key: 'drifted', label: 'drifted', value: grouped(drifted), ...(drifted > 0 ? { tone: 'accent' as const } : {}), ...(drift ? { press: 'drifted' } : {}) },
    ...(policy === null ? [] : [{ key: 'policy', label: 'policy', value: policy, ...(above(policy) ? { tone: 'alert' as const } : {}) }]),
    ...(diagnostics === null ? [] : [{ key: 'diagnostics', label: 'diagnostics', value: grouped(diagnostics), ...(diagnostics > 0 ? { tone: 'warn' as const } : {}) }]),
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
      `${countLabel(d.cycles.count, d.cycles.truncated)} ${d.cycles.count === 1 && !d.cycles.truncated ? 'cycle' : 'cycles'}`,
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
): PaneInput {
  const items = mergeRanked(d)
  const summary = summaryParts(d, items.length)
  const issues = issuesInput(d)
  const hues = huesOf(d)
  const changes = changesInput(session, d.project_root, hues, sessionRoot)
  const drift = driftInput(d)
  const boundaries = boundariesInput(d)
  const turn = brief?.status === 'ok' && brief.policy.status === 'evaluated' ? String(brief.policy.total) : null
  const policy = issues.policy?.evaluated ? issues.policy.total : turn
  const diagnostics = issues.diagnostics === null ? null : issues.diagnostics.errors + issues.diagnostics.warnings
  return {
    project: baseName(d.project_root ?? d.path) || (d.project_root ?? d.path),
    status: paneStatus(d, refresh, rescan, now, live),
    // A watcher that is scanning already does what a rescan would.
    canRescan: rescan.phase !== 'scanning' && live.phase !== 'scanning' && needsRescan(d),
    summary,
    languages: languagesOf(d),
    tab: view.tab,
    selected: view.selected,
    showKeys: view.showKeys,
    terminal,
    items,
    partial: d.hubs_truncated,
    lastTurn: lastTurnOf(brief, d.project_root),
    stats: statsOf(d, summary, policy, diagnostics, drift !== null),
    trend: [
      { label: 'cycles', values: d.trend.map(t => t.cycles) },
      { label: 'max degree', values: d.trend.map(t => t.max_degree) },
    ],
    fileHubs: [...d.fan_in]
      .sort((a, b) => b.dependent_files - a.dependent_files || a.path.localeCompare(b.path))
      .map(f => ({ path: f.path, dependents: f.dependent_files, boundary: f.boundary ?? null, loc: locIn(d.project_root, f.path), top: f.top_dependents ?? [] })),
    filter: view.filter ?? '',
    filtering: view.filtering ?? false,
    sort: view.sort ?? 'in',
    issues,
    cycles: cyclesInput(d),
    boundaries,
    detail,
    allow: allowInput(brief, rescan, allow),
    hues,
    changes,
    lookAt: lookAtOf(changes),
    drift,
    driftOpen: drift !== null && view.drift === true,
    git: extras.git,
    feedback: extras.feedback !== undefined && extras.feedback !== null && now < extras.feedback.until ? extras.feedback : null,
    target: view.target ?? null,
    couplings: shownCouplings(boundaries, view, d.snapshot_id ?? null, extras.couplings ?? null),
  }
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
  if (input.detail !== null) {
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
 * when a rescan would change anything. In a detail the name becomes the way
 * back: `project › Tab › what is shown`. As the width shrinks the chips go
 * first, then the commit, the branch and the reason; then the name is cut,
 * never below four cells, then the pill's words. Narrow, the name, the
 * pill and, while it fits, why.
 */
export function titleRow(input: PaneInput, columns: number, tier: Tier): Row {
  const rescan: Segment[] = input.canRescan ? [{ text: '  ' }, button('rescan', 'rescan', 'r', { dim: false })] : []
  const note: Segment[] = input.status.note === undefined ? [] : [{ text: `${input.status.note}  `, dim: true }]
  const name: Segment = { text: input.project, bold: true, color: HEADING }
  const crumbs: Segment[] =
    input.detail === null
      ? [name]
      : [name, { text: ' › ', dim: true }, { text: TABS.find(t => t.id === input.tab)?.full ?? '', dim: true }, { text: ' › ', dim: true }, { text: input.detail.label, bold: true, color: HEADING }]
  const git = gitLabel(input.git)
  const where: Segment[] = git === '' || tier === 'narrow' ? [] : [{ text: `  ${git}`, dim: true }]
  const chips: Segment[] =
    tier === 'narrow' || input.detail !== null || input.languages === '' ? [] : input.languages.split(' ').flatMap((l): Segment[] => [{ text: ' ' }, { text: ` ${l} `, dim: true, bg: CHIP_BG }])
  const chipsLead: Segment[] = chips.length === 0 ? [] : [{ text: ' ' }, ...chips]
  // Each layout from the fullest down; the first that fits wins.
  const variants: [Segment[], Segment[]][] = [
    [[...crumbs, ...(input.detail === null ? where : []), ...chipsLead], [...note, pill(input.status), ...rescan]],
    [[...crumbs, ...(input.detail === null ? where : [])], [...note, pill(input.status), ...rescan]],
    [[...crumbs, ...(input.detail === null && input.git?.branch ? [{ text: `  ${input.git.branch}`, dim: true }] : [])], [...note, pill(input.status), ...rescan]],
    [crumbs, [...note, pill(input.status), ...rescan]],
    [crumbs, [pill(input.status), ...rescan]],
  ]
  for (const [left, right] of variants) {
    if (segmentsWidth(left) + 1 + segmentsWidth(right) <= columns) return spread('title', left, right, columns)
  }
  // Nothing fits whole: the right side first, then the way back cut from its middle, then the pill's words cut.
  const right: Segment[] = [pill(input.status), ...rescan]
  const room = columns - 1 - segmentsWidth(right)
  if (room >= Math.min(4, cells(input.project))) return spread('title', cutCrumbs(crumbs, room), right, columns)
  const keep = Math.max(0, columns - 1 - segmentsWidth(rescan) - Math.min(4, cells(input.project)))
  const shortPill: Segment = { ...pill(input.status), text: fit(pill(input.status).text, keep) }
  const left = cutCrumbs(crumbs, Math.max(0, columns - 1 - cells(shortPill.text) - segmentsWidth(rescan)))
  return spread('title', left, keep >= 4 ? [shortPill, ...rescan] : clip(rescan.slice(1), columns), columns)
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
    ...(narrow ? [] : [[() => true, 1] as [(t: Tab) => boolean, number]]),
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
function componentRows(prefix: string, items: Item[], selected: number, spec: TableSpec, withDegrees: boolean, hues: Hues, sort: HubSort, offset: number, window: { start: number; end: number }, room = CARD_MAX): Row[] {
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
          repeat: i > window.start && item.boundary !== null && item.boundary === items[i - 1]!.boundary,
          place: placeIn(item),
          placeLoc: item.loc,
          preview: previewCard(`card-${prefix}-${i}`, { name: item.name, boundary: item.boundary, figures: componentFigures(item.files, item.in, item.out), top: item.top }, room, hues),
          ...(withDegrees ? { sorted: SORTS.indexOf(sort) } : {}),
        },
        spec,
        hues,
      )
    }),
  ]
}

/** The last turn: what it touched and how much depends on it, `limit` files around the marker, each a row it walks from `offset`. */
function lastTurnSection(turn: LastTurn, columns: number, limit: number, tier: Tier, hues: Hues, selected = -1, offset = 0): Section {
  const note = `${plural(turn.files, 'file', 'files')} → ${grouped(turn.dependents)} dependents · ${plural(turn.tests, 'test', 'tests')}`
  const local = selected - offset
  const window = windowOf(turn.impact.length, limit, local >= 0 && local < turn.impact.length ? local : -1)
  const spec = tableSpec(columns, turn.impact.map(f => f.name), turn.impact.map(f => boundaryLabel(f.boundary, hues)), [numberWidth('', turn.impact.map(f => f.dependents), tier)], undefined, { tier })
  const max = Math.max(0, ...turn.impact.map(f => f.dependents))
  const body = turn.impact.slice(window.start, window.end).map((f, n) => {
    const i = window.start + n
    const repeat = i > window.start && f.boundary !== null && f.boundary === turn.impact[i - 1]!.boundary
    return tableRow(`turn-${i}`, { name: f.name, boundary: f.boundary, values: [f.dependents], max, selected: offset + i === selected, press: `row:${offset + i}`, repeat }, spec, hues)
  })
  return { key: 'turn', title: 'Last turn', note: noteOf(note), body: [...body, ...moreRows('turn-window', window, turn.impact.length, columns)] }
}

/** The keys and what each does, for the key list. */
const KEY_HELP: [string, string][] = [
  ['1–6', 'switch tabs (or click one)'],
  ['j k', 'move the marker (or Tab, or a click)'],
  ['o', 'open the marked row: a component or a file shows what depends on it'],
  ['e', "open the marked row's file in your editor"],
  ['b', 'back from a detail'],
  ['c', "copy the marked row's full name or path"],
  ['q', 'ask Claude about the marked row: your press sends the prompt'],
  ['t', "copy the command for the tests that reach this session's changes"],
  ['d', 'list the files drifted since the snapshot, or hide them'],
  ['l', "on Boundaries: move the marked cell to the next boundary the marked one depends on, and spell it out"],
  ['f s x', 'on Hubs: filter (type, then Enter), sort by in, out or cross, clear'],
  ['r', 'rescan a stale snapshot'],
  ['a', 'allow a refused root (asks first)'],
]
const KEY_WIDTH = Math.max(...KEY_HELP.map(([k]) => cells(k))) + 2

/** How long the footer's word after an action stays, in milliseconds. */
export const FEEDBACK_MS = 2_000

/**
 * The key hints, grouped: moving about (the marker, back) dim on the left,
 * what can be done to the marked row on the right, and between them, for a
 * moment after an action, what it did (`✓ copied`, `✗ no editor`). Only the
 * keys that do something in this view are offered. Each group stays whole;
 * past the width the actions wrap under the moves, and a key is never
 * dropped, since it would stop working.
 */
function footerRows(input: PaneInput, columns: number, hasList: boolean): Row[] {
  const moves: Segment[] = []
  const actions: Segment[] = []
  const onTab = input.detail === null && !input.driftOpen
  if (input.detail !== null) moves.push(button('back', 'back', 'b'))
  if (hasList) moves.push(button('down', '↓', 'j'), button('up', '↑', 'k'))
  // A boundary has nothing to open: the marker only shows what it depends on.
  if (hasList && !listFor(input).every(item => item.inert === true)) actions.push(button('open', 'open', 'o'))
  // The same keys on every list: `e` whenever the marked row has a file.
  if (editTarget(input) !== null) actions.push(button('edit', 'edit', 'e'))
  if (subjectOf(input) !== null) actions.push(button('copy', 'copy', 'c'), button('ask', 'ask Claude', 'q'))
  if (onTab && input.tab === 'boundaries' && input.boundaries !== null && markedCell(input.boundaries, input.selected, input.target) !== null) actions.push(button('target', 'next cell', 'l'))
  // On Overview `t` stands beside the tests it copies, in "Look at now".
  if (onTab && input.tab === 'changes' && input.changes.command !== null) actions.push(button('tests', 'copy test command', 't'))
  if (input.detail === null && input.drift !== null) actions.push(button('drift', input.driftOpen ? 'hide drifted' : 'drifted files', 'd'))
  if (onTab && input.tab === 'hubs') {
    actions.push(button('filter', 'filter', 'f'), button('sort', `sort: ${input.sort}`, 's'))
    if (input.filter !== '') actions.push(button('clear', 'clear', 'x'))
  }
  actions.push(button('keys', input.showKeys ? 'hide keys' : 'keys', 'h'))
  const said: Segment[] = input.feedback === null ? [] : [{ text: input.feedback.text, color: input.feedback.tone === 'ok' ? STATUS_COLOURS.ok : STATUS_COLOURS.alert }]
  const left = joinGroups(moves.map(k => [{ ...k, dim: true }]))
  const right = joinGroups(actions.map(k => [{ ...k, dim: true }]))
  const rows: Row[] = []
  const middle = said.length === 0 ? [] : [{ text: '   ' }, ...said]
  if (segmentsWidth(left) + segmentsWidth(middle) + 2 + segmentsWidth(right) <= columns) {
    rows.push(spread('keys', [...left, ...middle], right, columns))
  } else {
    if (said.length > 0) rows.push({ key: 'keys-said', segments: clip(said, columns) })
    if (moves.length > 0) rows.push(...wrapGroups('keys', moves.map(k => [{ ...k, dim: true }]), columns))
    rows.push(...wrapGroups(moves.length > 0 ? 'keys-actions' : 'keys', actions.map(k => [{ ...k, dim: true }]), columns))
  }
  if (input.showKeys) {
    // One key a line, its action wrapped beside it: a list to look up, not a paragraph to read.
    let n = 0
    for (const [key, action] of [...KEY_HELP, ['', 'A file:line opens in your editor on a click. Every key has a button.'] as [string, string]]) {
      wrapWords(action, Math.max(1, columns - KEY_WIDTH)).forEach((line, i) =>
        rows.push({ key: `help-${n++}`, segments: [{ text: padEnd(i === 0 ? key : '', KEY_WIDTH), color: ACCENT }, { text: line, dim: true }] }),
      )
    }
  }
  return rows
}

/** Groups of segments on one line, two cells apart. */
function joinGroups(groups: Segment[][]): Segment[] {
  return groups.flatMap((g, i) => (i === 0 ? g : [{ text: '  ' }, ...g]))
}

/** The hubs tab: one card with the filter field or line, and the filtered, sorted table, as long as the pane allows. */
function hubsBlock(input: PaneInput, list: Item[], selected: number, tier: Tier): Block {
  const hotspots = input.items.some(i => i.hotspotOnly) ? '◆ hotspot only' : ''
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
      const section = (body: Row[]): Section => ({ key: 'hubs', title: 'Hubs and hotspots', subtitle: `sorted by ${input.sort}`, note: noteOf(note), body })
      if (list.length === 0) return section([...rows, dimRow('hubs-none', input.filter === '' ? '   none' : `   no hub matches "${input.filter}"`, columns)])
      const spec = componentSpec(list, columns, true, input.hues, tier)
      const local = selected < list.length ? selected : -1
      const window = windowOf(list.length, limit, local)
      return section([...rows, ...componentRows('hub', list, selected, spec, true, input.hues, input.sort, 0, window, columns), ...moreRows('hub-window', window, list.length, columns)])
    },
  }
}

/** The Hubs tab: the components most depended on and, beside them when wide, the files; the filter narrows both. */
function hubsArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  const list = hubList(input.items, input.filter, input.sort)
  const hubs = hubsBlock(input, list, selected, tier)
  const files = fileHubsBlock(fileHubList(input.fileHubs, input.filter), selected, list.length, tier, input.hues, input.filter === '' ? '' : `filter "${input.filter}"`)
  // The components' table has more columns than the files': it takes the larger share.
  return files === null ? { left: [hubs] } : { left: [hubs], right: [files], split: 0.6 }
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
function fileHubsBlock(files: FileHub[], selected: number, offset: number, tier: Tier, hues: Hues, note = ''): Block | null {
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
        const repeat = i > window.start && f.boundary !== null && f.boundary === files[i - 1]!.boundary
        const preview = previewCard(`card-files-${i}`, { name: baseName(f.path), boundary: f.boundary, figures: fileFigures(f.dependents), top: f.top }, columns, hues)
        return tableRow(`files-${i}`, { name: f.path, boundary: f.boundary, values: [f.dependents], max, cutStart: true, selected: offset + i === selected, press: `row:${offset + i}`, repeat, preview }, spec, hues)
      })
      const said = [shared === null ? '' : `all in ${boundaryLabel(shared, hues)}`, note, 'dependent files'].filter(t => t !== '').join(' · ')
      return { key: 'files', title: 'Files most depended on', note: noteOf(said), body: [...body, ...moreRows('files-window', window, files.length, columns)] }
    },
  }
}

/**
 * The Overview: the stat tiles across the top, what to look at now (once
 * this session changed something), the last turn, the components and the
 * files most depended on, and, with rows to spare, the trend. Wide, the
 * components stand right with the trend under them; the boundary map and
 * the files go under whichever column leaves the two closest in height. One marker walks
 * the lists in the order they are drawn on a narrow pane.
 */
function overviewArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  const looked = lookAtList(input.lookAt).length
  const turned = input.lastTurn?.impact.length ?? 0
  const left: Block[] = []
  const look = input.lookAt
  if (look !== null) left.push({ key: 'look', make: columns => lookAtSection(look, columns, input.hues, selected) })
  const turn = input.lastTurn
  if (turn !== null) left.push({ key: 'turn', grow: { length: turned, min: TURN_MIN }, make: (columns, limit) => lastTurnSection(turn, columns, limit, tier, input.hues, selected, looked) })
  const offset = looked + turned
  // Narrow, the in-degree alone; from medium on, out and cross and the file beside it.
  const withDegrees = tier !== 'narrow'
  const top: Block = {
    key: 'top',
    grow: { length: input.items.length, min: TOP_MIN },
    make: (columns, limit) => {
      const said = withDegrees ? (input.partial ? 'partial' : '') : input.partial ? 'partial · in' : 'in'
      const section = (body: Row[]): Section => ({ key: 'top', title: 'Most depended on', note: noteOf(sharedNote(input.items, said, input.hues)), body })
      if (input.items.length === 0) return section([dimRow('top-none', '   none', columns)])
      const local = selected - offset
      const window = windowOf(input.items.length, limit, local >= 0 ? local : -1)
      const spec = componentSpec(input.items, columns, withDegrees, input.hues, tier)
      return section([...componentRows('top', input.items, selected, spec, withDegrees, input.hues, 'in', offset, window, columns), ...moreRows('top-window', window, input.items.length, columns)])
    },
  }
  const tiles = input.stats.length > 0 ? [tilesBlock(input.stats, tier)] : []
  const files = fileHubsBlock(input.fileHubs, selected, offset + input.items.length, tier, input.hues)
  const trend = trendBlock(input.trend)
  const map: Block[] = tier === 'wide' && input.boundaries !== null && input.boundaries.boundaries.length > 0 ? [heatBlock(input.boundaries, input.hues, false, false)] : []
  const charts = trend === null ? [] : [trend]
  // The components' table on the right has the most columns: it takes the larger share, and the trend under it.
  return { top: tiles, left, right: [top, ...charts], float: [...map, ...(files === null ? [] : [files])], split: 0.45, order: [...tiles, ...left, top, ...(files === null ? [] : [files]), ...(trend === null ? [] : [trend])] }
}

/**
 * Every row of the pane for `input`, none wider than `columns`: the header,
 * then the tab (or the detail) laid out for the width's tier, its lists as
 * long as `height` rows allow once the header and the keys are drawn.
 */
export function paneRows(input: PaneInput, columns: number, height: number = DEFAULT_ROWS): Row[] {
  const width = Math.max(1, columns)
  const tier = tierOf(width)
  const list = listFor(input)
  const selected = Math.min(Math.max(0, input.selected), Math.max(0, list.length - 1))
  const rows: Row[] = [titleRow(input, width, tier)]
  if (input.allow !== null) rows.push(blank('gap-allow-top'), ...allowRows(input.allow, width))
  const footer = [blank('gap-keys'), ...footerRows(input, width, list.length > 0)]
  const room = () => height - rows.length - footer.length
  if (input.detail !== null) {
    const detail = input.detail
    rows.push(...arrange(detail.file === undefined ? detailArrangement(detail, tier, input.hues, selected) : fileDetailArrangement(detail, tier, input.hues, selected), width, room()))
  } else {
    const count = issueCount(input.issues)
    const touched = input.changes.files.length
    const badges: Partial<Record<PaneTab, string>> = {
      ...(count.n > 0 ? { issues: superscript(count.n, count.plus) } : {}),
      ...(touched > 0 ? { changes: superscript(touched, input.changes.truncated) } : {}),
    }
    rows.push(...tabRows(input.tab, width, input.terminal, badges, tier === 'narrow'))
    // Listed under the tabs, the drifted files hold the marker; the tab below shows none. They take a third of the room.
    const drift = input.drift
    if (input.driftOpen && drift !== null) {
      const min = Math.max(3, Math.floor(room() / 3))
      rows.push(...fitBlocks([{ key: 'drift', grow: { length: drift.items.length, min }, make: (inner, limit) => driftSection(drift, inner, limit, input.hues, selected) }], width, tier, 0))
    }
    const tabSelected = input.driftOpen ? -1 : selected
    rows.push(...arrange(tabArrangement(input, tabSelected, tier), width, room()))
  }
  rows.push(...footer)
  return rows.map(row => (row.code !== undefined || rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
}

/** The open tab's cards. */
function tabArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  if (input.tab === 'overview') return overviewArrangement(input, selected, tier)
  if (input.tab === 'changes') return changesArrangement(input.changes, selected, tier, input.hues)
  if (input.tab === 'hubs') return hubsArrangement(input, selected, tier)
  if (input.tab === 'issues') return issuesArrangement(input.issues, selected, tier, input.hues)
  if (input.tab === 'cycles') return cyclesArrangement(input.cycles, input.hues, selected)
  return boundariesArrangement(input.boundaries, tier, input.hues, selected, input.target, input.couplings)
}
