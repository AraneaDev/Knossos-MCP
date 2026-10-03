/**
 * The pane's layout: every row it draws, fitted to the columns it has.
 *
 * Pure: a view of the dashboard and the mod's state in, rows of styled
 * segments out. The render hook only turns segments into elements, so every
 * decision about width, order and colour is made here and tested here. The
 * shared primitives (segments, cutting, bars, the self-fitting table) are in
 * `rows.ts`; the Issues and Cycles tabs and the component detail in `views.ts`.
 */
import type { AllowState, Dashboard, HubSort, KnossosView, LiveState, PaneTab, Ranked, RefreshState, RescanState, SessionChanges, TurnBrief } from '../../types'
import { formatAge } from './band'
import { boundariesArrangement, boundariesInput, boundariesList, heatSection } from './boundaries'
import type { BoundariesInput } from './boundaries'
import { changesArrangement, changesInput, changesList, lookAtList, lookAtOf, lookAtSection, NO_CHANGES } from './changes'
import type { ChangesInput, LookAt } from './changes'
import { countLabel } from './envelopes'
import { driftInput, driftList, driftSection, fileDetailArrangement, fileDetailList } from './files'
import type { DriftInput } from './files'
import { ACCENT, boundaryLabel, declaredOf, FAINT, HEADING, huesOf, STATUS_COLOURS } from './palette'
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
  joinFitting,
  numberWidth,
  padEnd,
  padStart,
  placeOf,
  plural,
  rowWidth,
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
import { sparkline } from './sparkline'
import { LIVE_OFF } from './live'
import { cyclesArrangement, cyclesInput, cyclesList, detailArrangement, detailList, issueCount, issuesArrangement, issuesInput, issuesList, locIn, superscript } from './views'
import { arrange, DEFAULT_ROWS, fitBlocks, moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import type { CyclesInput, DetailInput, IssuesInput, Openable } from './views'

// Everything the specs and the render hook draw with, from one module.
export { bar, button, cells, displayName, fileHref, fit, linkMarkdown, locOf, locText, rowWidth, tableSpec, tierOf, wrapWords } from './rows'
export type { Field, Loc, Press, Row, Segment, TableSpec, Tier } from './rows'
export { DEFAULT_ROWS, paneHeight } from './cards'
export type { DetailInput, FileView, Openable } from './views'
export type { BoundariesInput } from './boundaries'
export { accumulate, cdFor, NO_CHANGES } from './changes'
export type { ChangesInput, LookAt } from './changes'
export { detailInput } from './views'
export { driftInput, fileDetailInput } from './files'
export type { DriftInput } from './files'

/** One component in a list the selection walks: hubs and hotspots merged. */
export type Item = {
  name: string
  canonical: string
  kind: string
  boundary: string | null
  in: number
  out: number
  cross: number
  /** Ranked as a hotspot but not as a hub: marked `◆`. */
  hotspotOnly: boolean
  /** Where it is declared, so `e` opens its file; null when the dashboard places it nowhere. */
  loc: Loc | null
}

export type PaneStatus = { tone: Tone; text: string }

export type LastTurn = {
  files: number
  dependents: number
  tests: number
  impact: { name: string; path: string; boundary: string | null; dependents: number; loc: Loc | null }[]
}

export type Health = {
  cycles: string
  maxDegree: number | null
  cyclesTrend: number[]
  degreeTrend: number[]
  deadCode: string
  /** Policy violations: the project's when the dashboard reports them, else the last turn's; null when neither was checked. */
  policy: string | null
  /** Diagnostic errors and warnings, or null when the dashboard reports none. */
  diagnostics: number | null
}

/** Everything the pane draws, read from state once per render. */
export type PaneInput = {
  project: string
  status: PaneStatus
  canRescan: boolean
  summary: string[]
  tab: PaneTab
  selected: number
  showKeys: boolean
  /** Whether plain Buttons are drawn as text (`1: label`): the terminal. Elsewhere they are native. */
  terminal: boolean
  items: Item[]
  partial: boolean
  lastTurn: LastTurn | null
  health: Health
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
/** The widest Health spreads a figure and its trend, so they never drift apart on a wide card. */
const HEALTH_MAX = 48
/** A trend is drawn only with this many snapshots, and only when it moves. */
const TREND_MIN_POINTS = 5
const TREND_MAX_POINTS = 24
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
      hotspotOnly,
      loc: locIn(d.project_root ?? null, r.path ?? null, r.line ?? null),
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

/** What a list addresses: the fields the selection, `o`, `e`, `c` and `q` read. */
export type ListInput = Pick<PaneInput, 'tab' | 'items' | 'filter' | 'sort' | 'issues' | 'detail' | 'changes' | 'cycles' | 'boundaries' | 'lookAt' | 'lastTurn' | 'drift' | 'driftOpen'>

/** A file row of a list: it opens as the file's detail, and `e` opens the file. */
const fileRow = (path: string, loc: Loc | null): Openable => ({ name: path, canonical: path, loc, file: true })

/**
 * The Overview's walkable rows, top to bottom as drawn: the file "Look at
 * now" points at, the last turn's files, then the most depended on.
 */
export function overviewList(input: Pick<PaneInput, 'items' | 'lookAt' | 'lastTurn'>): Openable[] {
  const turn = (input.lastTurn?.impact ?? []).map(f => fileRow(f.path, f.loc))
  return [...lookAtList(input.lookAt), ...turn, ...input.items]
}

/** The rows the selection walks: the detail's counterparts, else the drifted files when listed, else the tab's list. */
export function listFor(input: ListInput): Openable[] {
  if (input.detail !== null) return input.detail.file === undefined ? detailList(input.detail) : input.detail.file === null ? [] : fileDetailList(input.detail.file)
  if (input.driftOpen && input.drift !== null) return driftList(input.drift)
  if (input.tab === 'overview') return overviewList(input)
  if (input.tab === 'hubs') return hubList(input.items, input.filter, input.sort)
  if (input.tab === 'changes') return changesList(input.changes)
  if (input.tab === 'cycles') return cyclesList(input.cycles)
  if (input.tab === 'boundaries') return boundariesList(input.boundaries)
  return input.tab === 'issues' ? issuesList(input.issues) : []
}

/**
 * The header's status: the snapshot's state and age, or what the rescan,
 * the refresh or the live watcher is doing. While the watcher keeps a fresh
 * graph current it says `live` (`live · watched by another session` when
 * another session's watcher leads) instead of an age, which a live graph
 * does not have.
 */
export function paneStatus(d: Dashboard, refresh: RefreshState, rescan: RescanState, now: number, live: LiveState = LIVE_OFF): PaneStatus {
  const sinceFetch = refresh.fetchedAt === null ? 0 : now - refresh.fetchedAt
  const age = d.freshness.age_seconds === null ? '' : ` · ${formatAge(d.freshness.age_seconds * 1000 + sinceFetch)}`
  // The figures on show are still the old snapshot's while a scan runs: say how old, never only that a scan runs.
  if (rescan.phase === 'scanning') return { tone: 'warn', text: `scanning… · ${refresh.failed ? 'refresh failed' : d.freshness.state}${age}` }
  if (rescan.phase === 'failed') return { tone: 'alert', text: `rescan failed${rescan.reason ? `: ${rescan.reason}` : ''}` }
  if (refresh.failed) return { tone: 'alert', text: `refresh failed${age}` }
  if (live.phase === 'scanning') return { tone: 'warn', text: `scanning… · ${d.freshness.state}${age}` }
  // The leader's watcher is stuck or gone quiet: the graph is not being kept current, whatever its age says.
  if (live.phase === 'following' && live.stale === true) return { tone: 'warn', text: `${d.freshness.state}${age} · another session's watcher is stuck` }
  if ((live.phase === 'live' || live.phase === 'following') && d.freshness.state === 'fresh' && d.freshness.drift_files === 0) {
    return { tone: 'ok', text: live.phase === 'live' ? 'live' : 'live · watched by another session' }
  }
  return { tone: d.freshness.state === 'fresh' ? 'ok' : 'warn', text: `${d.freshness.state}${age}` }
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
  const languages = d.summary.languages.map(l => LANGUAGES[l.language] ?? l.language.toUpperCase()).join(' ')
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
): PaneInput {
  const items = mergeRanked(d)
  const issues = issuesInput(d)
  const hues = huesOf(d)
  const changes = changesInput(session, d.project_root, hues, sessionRoot)
  const drift = driftInput(d)
  const turn = brief?.status === 'ok' && brief.policy.status === 'evaluated' ? String(brief.policy.total) : null
  const policy = issues.policy?.evaluated ? issues.policy.total : turn
  const diagnostics = issues.diagnostics === null ? null : issues.diagnostics.errors + issues.diagnostics.warnings
  return {
    project: baseName(d.project_root ?? d.path) || (d.project_root ?? d.path),
    status: paneStatus(d, refresh, rescan, now, live),
    // A watcher that is scanning already does what a rescan would.
    canRescan: rescan.phase !== 'scanning' && live.phase !== 'scanning' && needsRescan(d),
    summary: summaryParts(d, items.length),
    tab: view.tab,
    selected: view.selected,
    showKeys: view.showKeys,
    terminal,
    items,
    partial: d.hubs_truncated,
    lastTurn: lastTurnOf(brief, d.project_root),
    health: {
      cycles: countLabel(d.cycles.count, d.cycles.truncated),
      maxDegree: d.trend.at(-1)?.max_degree ?? null,
      cyclesTrend: d.trend.map(t => t.cycles),
      degreeTrend: d.trend.map(t => t.max_degree),
      deadCode: countLabel(d.dead_code_candidates, d.dead_code_truncated),
      policy,
      diagnostics,
    },
    filter: view.filter ?? '',
    filtering: view.filtering ?? false,
    sort: view.sort ?? 'in',
    issues,
    cycles: cyclesInput(d),
    boundaries: boundariesInput(d),
    detail,
    allow: allowInput(brief, rescan, allow),
    hues,
    changes,
    lookAt: lookAtOf(changes),
    drift,
    driftOpen: drift !== null && view.drift === true,
  }
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

function headerRows(input: PaneInput, columns: number): Row[] {
  const dot: Segment = { text: '● ', color: STATUS_COLOURS[input.status.tone] }
  const said: Segment = { text: input.status.text, dim: true }
  const rescan = input.canRescan ? [{ text: '  ' }, button('rescan', 'rescan', 'r', { dim: false })] : []
  // The rescan action and the dot come first; the project name gives way, then the status text.
  const rightMax = Math.max(0, columns - 1 - Math.min(cells(input.project), 4))
  let right: Segment[] = [dot, said, ...rescan]
  if (right.reduce((n, s) => n + cells(s.text), 0) > rightMax) {
    const room = rightMax - rescan.reduce((n, s) => n + cells(s.text), 0)
    right = room >= 3 ? [dot, { ...said, text: fit(said.text, room - cells(dot.text)) }, ...rescan] : clip(rescan.slice(1), columns)
  }
  const rightWidth = right.reduce((n, s) => n + cells(s.text), 0)
  const title: Segment = { text: fit(input.project, Math.max(0, columns - rightWidth - 1)), bold: true, color: 'text' }
  return [spread('title', [title], right, columns), summaryRow(input, columns)]
}

/**
 * The summary line under the title. When the dashboard names the files that
 * drifted, their count is drawn in the accent and the word after it is a
 * button that lists them (as `d` does): the one thing on a quiet line that
 * can be pressed looks like it.
 */
function summaryRow(input: PaneInput, columns: number): Row {
  const text = joinFitting(input.summary, columns)
  const at = input.drift === null ? -1 : input.summary.findIndex(p => p.endsWith(' drifted'))
  const shown = text === '' ? 0 : text.split(' · ').length
  if (at < 0 || at >= shown) return { key: 'summary', segments: [{ text, dim: true }] }
  const before = input.summary.slice(0, at).join(' · ')
  const after = input.summary.slice(at + 1, shown).join(' · ')
  return {
    key: 'summary',
    segments: [
      { text: before === '' ? '' : `${before} · `, dim: true },
      { text: `${input.summary[at]!.slice(0, input.summary[at]!.indexOf(' '))} `, color: ACCENT },
      button('drifted', 'drifted', undefined, { dim: false }),
      { text: after === '' ? '' : ` · ${after}`, dim: true },
    ].filter(s => s.text !== ''),
  }
}

/**
 * The tab strip and the rule under it. Every tab by its full name when all
 * fit; else the active tab by its full name and the others by their digit
 * (each with its badge, the Issues and Changes counts), then with the gaps
 * closed, then digits alone. Never an abbreviation. On the terminal the rule
 * marks the active tab; elsewhere tabs are native buttons whose widths the
 * pane cannot know.
 *
 * A Button with a hotkey always draws `N: label`, so a tab drawn as its digit
 * alone is a Button without one, and its hotkey rides on a hidden twin
 * (`tabkey:<id>`, no text) placed just before it: the digit keys still switch
 * every tab. The pane's focus hook moves a ring landing on a twin onto its
 * visible tab.
 */
export function tabRows(active: PaneTab, columns: number, terminal: boolean, badges: Partial<Record<PaneTab, string>> = {}): Row[] {
  type Tab = (typeof TABS)[number]
  const full = (t: Tab) => (badges[t.id] ? `${t.full} ${badges[t.id]}` : t.full)
  const digit = (t: Tab) => `${t.hotkey}${badges[t.id] ?? ''}`
  // Each variant: whether a tab shows its name, and the gap between tabs.
  const variants: [(t: Tab) => boolean, number][] = [
    [() => true, 2],
    [t => t.id === active, 2],
    [t => t.id === active, 1],
    [() => false, 1],
  ]
  const width = (named: (t: Tab) => boolean, gap: number) =>
    TABS.reduce((n, t) => n + cells(named(t) ? `${t.hotkey}: ${full(t)}` : digit(t)), 0) + gap * (TABS.length - 1)
  const [named, gap] = variants.find(([l, g]) => width(l, g) <= columns) ?? variants[variants.length - 1]!
  const segments: Segment[] = []
  let rule = ''
  TABS.forEach((t, i) => {
    if (i > 0) {
      segments.push({ text: spaces(gap) })
      rule += '─'.repeat(gap)
    }
    const on = t.id === active
    const style = on ? {} : { dim: true }
    if (named(t)) {
      segments.push(button(`tab:${t.id}`, full(t), t.hotkey, style))
    } else {
      segments.push({ text: '', hidden: true, press: { id: `tabkey:${t.id}`, label: t.full, hotkey: t.hotkey } }, button(`tab:${t.id}`, digit(t), undefined, style))
    }
    rule += (on ? '━' : '─').repeat(cells(segments[segments.length - 1]!.text))
  })
  const strip = { key: 'tabs', segments: clip(segments, columns) }
  if (!terminal) return [strip]
  const shown = [...(rule + '─'.repeat(Math.max(0, columns - cells(rule))))].slice(0, columns).join('')
  const start = shown.indexOf('━')
  const end = shown.lastIndexOf('━') + 1
  const ruleRow: Row =
    start < 0
      ? { key: 'tab-rule', segments: [{ text: shown, color: FAINT }] }
      : {
          key: 'tab-rule',
          segments: [
            { text: shown.slice(0, start), color: FAINT },
            { text: shown.slice(start, end), color: ACCENT },
            { text: shown.slice(end), color: FAINT },
          ].filter(s => s.text !== ''),
        }
  return [strip, ruleRow]
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

/** A component's numbers as a table shows them: in, out and cross, or in alone. */
const degreesOf = (item: Item, withDegrees: boolean): number[] => (withDegrees ? [item.in, item.out, item.cross] : [item.in])
const degreeTitles = (withDegrees: boolean): string[] => (withDegrees ? ['in', 'out', 'cross'] : ['in'])

/** Where a component is declared, as a table's file column shows it: its file's name and the line. */
const placeIn = (item: Item): string => (item.loc === null ? '' : placeOf(item.loc.path, item.loc.line))

/**
 * How a table of components fits `columns`: without the boundary column when
 * every row shares one, and from the medium tier on with the file each is
 * declared in.
 */
function componentSpec(items: Item[], columns: number, withDegrees: boolean, hues: Hues, tier: Tier): TableSpec {
  const widths = degreeTitles(withDegrees).map((t, i) => numberWidth(t, items.map(item => degreesOf(item, withDegrees)[i] ?? 0)))
  const labels = sharedBoundary(items) === null ? items.map(i => boundaryLabel(i.boundary, hues)) : []
  const places = tier === 'narrow' ? [] : items.map(placeIn)
  return tableSpec(columns, items.map(i => i.name), labels, widths, undefined, { tier, places })
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
function componentRows(prefix: string, items: Item[], selected: number, spec: TableSpec, withDegrees: boolean, hues: Hues, sort: HubSort, offset: number, window: { start: number; end: number }): Row[] {
  const titles = degreeTitles(withDegrees)
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
          values: degreesOf(item, withDegrees),
          barValue: item[sort],
          max,
          selected: offset + i === selected,
          hotspotOnly: item.hotspotOnly,
          press: `row:${offset + i}`,
          repeat: i > window.start && item.boundary !== null && item.boundary === items[i - 1]!.boundary,
          place: placeIn(item),
          placeLoc: item.loc,
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
  const spec = tableSpec(columns, turn.impact.map(f => f.name), turn.impact.map(f => boundaryLabel(f.boundary, hues)), [numberWidth('', turn.impact.map(f => f.dependents))], undefined, { tier })
  const max = Math.max(0, ...turn.impact.map(f => f.dependents))
  const body = turn.impact.slice(window.start, window.end).map((f, n) => {
    const i = window.start + n
    const repeat = i > window.start && f.boundary !== null && f.boundary === turn.impact[i - 1]!.boundary
    return tableRow(`turn-${i}`, { name: f.name, boundary: f.boundary, values: [f.dependents], max, selected: offset + i === selected, press: `row:${offset + i}`, repeat }, spec, hues)
  })
  return { key: 'turn', title: 'Last turn', note: noteOf(note), body: [...body, ...moreRows('turn-window', window, turn.impact.length, columns)] }
}

/** Glyphs for a trend that is long enough and moves; otherwise nothing, since a flat line says nothing. */
function trendGlyphs(values: number[]): string {
  if (values.length < TREND_MIN_POINTS || Math.min(...values) === Math.max(...values)) return ''
  return sparkline(values.slice(-TREND_MAX_POINTS))
}

/** A count as a verdict: `✓ 0` in green, else `▲ n` in `tone`. */
function verdict(value: string, tone: Tone): Segment {
  return value === '0' ? { text: '✓ 0', color: STATUS_COLOURS.ok } : { text: `▲ ${value}`, color: STATUS_COLOURS[tone] }
}

/**
 * Health, one figure per row in one column: cycles, the largest degree and
 * dead code as plain numbers (each with its trend, when it moves), then the
 * policy violations and diagnostics as verdicts, green at zero.
 */
function healthSection(h: Health, columns: number): Section {
  const cyclesTrend = trendGlyphs(h.cyclesTrend)
  const degreeTrend = trendGlyphs(h.degreeTrend)
  const note = cyclesTrend !== '' || degreeTrend !== '' ? `trend (${Math.max(h.cyclesTrend.length, h.degreeTrend.length)} scans)` : ''
  const verdicts: [string, string, string, Tone][] = [
    ...(h.policy === null ? [] : [['health-policy', 'policy', h.policy, 'alert'] as [string, string, string, Tone]]),
    ...(h.diagnostics === null ? [] : [['health-diagnostics', 'diagnostics', String(h.diagnostics), 'warn'] as [string, string, string, Tone]]),
  ]
  const values = [h.cycles, h.maxDegree === null ? '' : String(h.maxDegree), h.deadCode, ...verdicts.map(([, , v, tone]) => verdict(v, tone).text)]
  const valueWidth = Math.max(...values.map(cells))
  const spreadTo = Math.min(columns, HEALTH_MAX)
  const label = (text: string): Segment => ({ text: `   ${padEnd(text, HEALTH_LABEL)}`, dim: true })
  const metric = (key: string, name: string, value: string, trend: string): Row =>
    spread(key, [label(name), { text: padStart(value, valueWidth), bold: true, color: 'text' }], trend === '' ? [] : [{ text: trend, dim: true }], spreadTo)
  return {
    key: 'health',
    title: 'Health',
    note: noteOf(note),
    body: [
      metric('health-cycles', 'cycles', h.cycles, cyclesTrend),
      ...(h.maxDegree === null ? [] : [metric('health-degree', 'max degree', String(h.maxDegree), degreeTrend)]),
      metric('health-dead', 'dead code', h.deadCode, ''),
      ...verdicts.map(([key, name, value, tone]): Row => {
        const v = verdict(value, tone)
        return { key, segments: [label(name), { ...v, text: padStart(v.text, valueWidth) }] }
      }),
    ],
  }
}

/** The width of Health's labels: its longest, and a space. */
const HEALTH_LABEL = 'diagnostics'.length + 1

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
  ['f s x', 'on Hubs: filter (type, then Enter), sort by in, out or cross, clear'],
  ['r', 'rescan a stale snapshot'],
  ['a', 'allow a refused root (asks first)'],
]
const KEY_WIDTH = Math.max(...KEY_HELP.map(([k]) => cells(k))) + 2

/** The key buttons, wrapped onto as many rows as they need: a key that does not fit is never dropped, since it would stop working. */
function footerRows(input: PaneInput, columns: number, hasList: boolean): Row[] {
  const keys: Segment[] = []
  const onTab = input.detail === null && !input.driftOpen
  if (input.detail !== null) keys.push(button('back', 'back', 'b'))
  if (hasList) keys.push(button('down', '↓', 'j'), button('up', '↑', 'k'))
  // A boundary has nothing to open: the marker only shows what it depends on.
  if (hasList && !listFor(input).every(item => item.inert === true)) keys.push(button('open', 'open', 'o'))
  // The same keys on every list: `e` whenever the marked row has a file.
  if (editTarget(input) !== null) keys.push(button('edit', 'edit', 'e'))
  if (subjectOf(input) !== null) keys.push(button('copy', 'copy', 'c'), button('ask', 'ask Claude', 'q'))
  // On Overview `t` stands beside the tests it copies, in "Look at now".
  if (onTab && input.tab === 'changes' && input.changes.command !== null) keys.push(button('tests', 'copy test command', 't'))
  if (input.detail === null && input.drift !== null) keys.push(button('drift', input.driftOpen ? 'hide drifted' : 'drifted files', 'd'))
  if (onTab && input.tab === 'hubs') {
    keys.push(button('filter', 'filter', 'f'), button('sort', `sort: ${input.sort}`, 's'))
    if (input.filter !== '') keys.push(button('clear', 'clear', 'x'))
  }
  keys.push(button('keys', input.showKeys ? 'hide keys' : 'keys', 'h'))
  const rows = wrapGroups('keys', keys.map(k => [{ ...k, dim: true }]), columns)
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
      const window = windowOf(list.length, limit, selected)
      return section([...rows, ...componentRows('hub', list, selected, spec, true, input.hues, input.sort, 0, window), ...moreRows('hub-window', window, list.length, columns)])
    },
  }
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
 * The Overview: what to look at now (once this session changed something),
 * the last turn, health and the most depended on; wide, the last stands
 * right, with a small boundary map under it. One marker walks the lists in
 * the order they are drawn on a narrow pane.
 */
function overviewArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  const looked = lookAtList(input.lookAt).length
  const turned = input.lastTurn?.impact.length ?? 0
  const left: Block[] = []
  const look = input.lookAt
  if (look !== null) left.push({ key: 'look', make: columns => lookAtSection(look, columns, input.hues, selected) })
  const turn = input.lastTurn
  if (turn !== null) left.push({ key: 'turn', grow: { length: turned, min: TURN_MIN }, make: (columns, limit) => lastTurnSection(turn, columns, limit, tier, input.hues, selected, looked) })
  left.push({ key: 'health', make: columns => healthSection(input.health, columns) })
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
      return section([...componentRows('top', input.items, selected, spec, withDegrees, input.hues, 'in', offset, window), ...moreRows('top-window', window, input.items.length, columns)])
    },
  }
  const map: Block[] = tier === 'wide' ? [{ key: 'map', make: columns => heatSection(input.boundaries, columns, input.hues, false) }] : []
  return { left, right: [top, ...map], order: [...left, top] }
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
  const rows: Row[] = [...headerRows(input, width)]
  if (input.allow !== null) rows.push(...allowRows(input.allow, width), blank('gap-allow'))
  const footer = [blank('gap-keys'), ...footerRows(input, width, list.length > 0)]
  const room = () => height - rows.length - footer.length
  if (input.detail !== null) {
    const detail = input.detail
    rows.push(...arrange(detail.file === undefined ? detailArrangement(detail, tier, input.hues, selected) : fileDetailArrangement(detail, tier, input.hues, selected), width, room()))
  } else {
    // Listed under the header, the drifted files hold the marker; the tab below shows none. They take a third of the room.
    const drift = input.drift
    if (input.driftOpen && drift !== null) {
      const min = Math.max(3, Math.floor(room() / 3))
      rows.push(...fitBlocks([{ key: 'drift', grow: { length: drift.items.length, min }, make: (inner, limit) => driftSection(drift, inner, limit, input.hues, selected) }], width, tier, 0))
      rows.push(blank('gap-drift-end'))
    }
    const tabSelected = input.driftOpen ? -1 : selected
    const count = issueCount(input.issues)
    const touched = input.changes.files.length
    const badges: Partial<Record<PaneTab, string>> = {
      ...(count.n > 0 ? { issues: superscript(count.n, count.plus) } : {}),
      ...(touched > 0 ? { changes: superscript(touched, input.changes.truncated) } : {}),
    }
    rows.push(...tabRows(input.tab, width, input.terminal, badges))
    rows.push(...arrange(tabArrangement(input, tabSelected, tier), width, room()))
  }
  rows.push(...footer)
  return rows.map(row => (row.code !== undefined || rowWidth(row) <= width ? row : { ...row, segments: clip(row.segments, width) }))
}

/** The open tab's cards. */
function tabArrangement(input: PaneInput, selected: number, tier: Tier): Arrangement {
  if (input.tab === 'overview') return overviewArrangement(input, selected, tier)
  if (input.tab === 'changes') return changesArrangement(input.changes, selected, tier, input.hues)
  if (input.tab === 'hubs') return { left: [hubsBlock(input, hubList(input.items, input.filter, input.sort), selected, tier)] }
  if (input.tab === 'issues') return issuesArrangement(input.issues, selected, tier, input.hues)
  if (input.tab === 'cycles') return cyclesArrangement(input.cycles, input.hues, selected)
  return boundariesArrangement(input.boundaries, tier, input.hues, selected)
}
