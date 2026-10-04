/**
 * The pane's Issues tab and its component detail: a view model
 * read from the envelopes, and the rows each draws at a given width.
 *
 * Pure, like the rest of the layout: envelopes in, rows of styled segments
 * out, every width decision made and tested here. Rows that list components
 * are pressable by an index into the view's walkable list, so the marker,
 * `o` and a click all address the same component.
 */
import { diffBlock } from './diff'
import type { DiffView } from './diff'
import { moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import type { ComponentDetail, Counterpart, Dashboard, DetailState, Inspected, PaneTab } from '../../types'
import { countLabel, detailLines } from './envelopes'
import { ACCENT, boundaryColour, boundaryLabel, FAINT, NO_HUES, SELECTED_BG, STATUS_COLOURS } from './palette'
import { DIAGRAM_MIN, neighbourhood } from './diagram'
import type { Neighbour } from './diagram'
import type { Hues } from './palette'
import {
  absolute,
  blank,
  button,
  cells,
  chip,
  dimRow,
  displayName,
  fit,
  fitStart,
  pathSegments,
  grouped,
  linked,
  MARK,
  numberWidth,
  placeOf,
  plural,
  shortName,
  segmentsWidth,
  spaces,
  SWATCH,
  tableHead,
  tableRow,
  tableSpec,
  tinted,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment, Tier } from './rows'

/**
 * A row the marker can walk to: `name` to show, `canonical` to look it up by,
 * and `loc` where its file is, when known, for `e` to open. A `file` row is a
 * file, not a component: opening it opens the file itself.
 */
export type Openable = {
  name: string
  canonical: string
  loc?: Loc | null
  file?: boolean
  /** Nothing to open (a boundary): `o` only marks it. */
  inert?: true
  /** What `c` copies instead of the canonical name (a cycle's chain). */
  copy?: string
  /** What "Ask Claude" asks instead of what depends on it (a cycle, a boundary). */
  ask?: string
  /** A file the session's changes list: its detail shows the change since the session began. */
  changed?: true
  /** Opens another tab instead of a detail (an Overview chart's bar): the tab, and how it opens. */
  jump?: Jump
}

/**
 * Where a press on an Overview chart takes the pane: a tab, the in-degree
 * range Hubs narrows to, the row its marker starts on, and the boundary a
 * Boundaries cell runs to.
 */
export type Jump = { tab: PaneTab; degree?: { from: number; to: number | null }; selected?: number; target?: string }

export type ViolationLine = { source: Openable & { boundary: string | null }; target: string; targetBoundary: string | null; place: string }
export type DeadLine = Openable & { boundary: string | null; place: string; testOnly: boolean }
export type DiagnosticLine = { severity: string; text: string; place: string; loc: Loc | null }

export type IssuesInput = {
  /** Null when the knossos that answered sends no policy section. */
  policy: { evaluated: boolean; total: string; count: number; items: ViolationLine[] } | null
  diagnostics: { errors: number; warnings: number; infos: number; items: DiagnosticLine[] } | null
  deadCode: { total: string; items: DeadLine[] }
  /** The files a change is riskiest in: lines times dependents, the highest first. */
  hotspots: { path: string; lines: number; dependents: number; score: number; loc: Loc | null }[]
  /** The files over the project's function-length budget; null without a budget, undefined when the knossos that answered does not say. */
  budget: { source: string; max: number; total: number; files: { path: string; functions: number; longest: number; loc: Loc | null }[] } | null | undefined
}

export type Side = { title: string; count: string; items: (Openable & { boundary: string | null; edges: number })[] }
export type DetailInput = {
  label: string
  loading: boolean
  /** What to say instead of a component: not found, ambiguous, nothing answered. */
  messages: string[] | null
  component: {
    name: string
    canonical: string
    kind: string
    boundary: string | null
    place: string | null
    loc: Loc | null
    usedBy: Side
    uses: Side
    annotations: { kind: string; value: string }[]
  } | null
  /** Present (null until it is read) when the detail is a file's, not a component's. */
  file?: FileView | null
  /** A changed file's change since the session began, shown below its dependents; absent when it was not opened as a change. */
  diff?: DiffView | null
}

/** One file's detail as the pane draws it; `loc` places each file (and component) on disk. */
export type FileView = {
  path: string
  language: string
  lines: number | null
  boundary: string | null
  loc: Loc | null
  dependents: { count: number; truncated: boolean; boundaries: string[]; items: { path: string; edges: number; boundary: string | null; loc: Loc | null }[] }
  /** The files it depends on; null from a knossos that does not say. */
  uses: { count: number; truncated: boolean; items: { path: string; edges: number; boundary: string | null; loc: Loc | null }[] } | null
  components: { count: number; truncated: boolean; items: { name: string; canonical: string; kind: string; boundary: string | null; usedBy: number; loc: Loc | null }[] }
}

/** The share of a row a place (`file:line`) may take before it is cut from the front. */
const PLACE_SHARE = 0.35
/** The narrowest the main text of an issue row is allowed before the boundary, then the place, give way. */
const MAIN_MIN = 16

const SUPERSCRIPT = ['⁰', '¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹']

/** A count as a superscript (`³`, `¹²`, `⁹⁺`), for a tab label. */
export function superscript(n: number, plus = false): string {
  return [...String(n)].map(d => SUPERSCRIPT[Number(d)] ?? '').join('') + (plus ? '⁺' : '')
}

/** Where `path` (project-relative) is on disk under `root`, at `line`; null without a path or a root. */
export function locIn(root: string | null, path: string | null, line: number | null = null): Loc | null {
  return root === null || path === null || path === '' ? null : { path: absolute(root, path), line }
}

/** The Issues tab's view of a dashboard. */
export function issuesInput(d: Dashboard): IssuesInput {
  const policy = d.policy
  const diagnostics = d.diagnostics
  const root = d.project_root
  return {
    policy:
      policy === undefined
        ? null
        : {
            evaluated: policy.status === 'evaluated',
            total: countLabel(policy.total, policy.truncated),
            count: policy.total,
            items: policy.items.map(v => ({
              source: { name: shortName(v.source), canonical: v.source, boundary: v.source_boundary, loc: locIn(root, v.path, v.line) },
              target: shortName(v.target),
              targetBoundary: v.target_boundary,
              place: placeOf(v.path, v.line),
            })),
          },
    diagnostics:
      diagnostics === undefined
        ? null
        : {
            errors: diagnostics.errors,
            warnings: diagnostics.warnings,
            infos: diagnostics.infos,
            items: diagnostics.items.map(x => ({ severity: x.severity, text: `${x.code} ${x.message}`, place: placeOf(x.path, x.line), loc: locIn(root, x.path, x.line) })),
          },
    deadCode: {
      total: countLabel(d.dead_code_candidates, d.dead_code_truncated),
      items: (d.dead_code ?? []).map(c => ({
        name: displayName(c),
        canonical: c.canonical_name,
        boundary: c.boundary,
        place: placeOf(c.path, c.line),
        loc: locIn(root, c.path, c.line),
        testOnly: c.reachability === 'test_only',
      })),
    },
    hotspots: (d.complexity_hotspots ?? []).map(f => ({ path: f.path, lines: f.lines, dependents: f.dependent_files, score: f.score, loc: locIn(root, f.path) })),
    budget:
      d.over_budget === undefined || d.over_budget === null
        ? d.over_budget
        : {
            source: d.over_budget.source,
            max: d.over_budget.max_function_lines,
            total: d.over_budget.total,
            files: d.over_budget.files.map(f => ({ path: f.path, functions: f.functions, longest: f.longest, loc: locIn(root, f.path, f.line) })),
          },
  }
}

/** How many issues the tab label counts: policy violations, errors and warnings; `plus` when that is a floor. */
export function issueCount(issues: IssuesInput): { n: number; plus: boolean } {
  const policy = issues.policy?.evaluated ? issues.policy.count : 0
  const diagnostics = issues.diagnostics === null ? 0 : issues.diagnostics.errors + issues.diagnostics.warnings
  return { n: policy + diagnostics, plus: issues.policy?.total.endsWith('+') ?? false }
}

/** The rows the Issues tab walks: each violation's source, each dead-code candidate, then the hotspot files and the files over budget. */
export function issuesList(issues: IssuesInput): Openable[] {
  const file = (path: string, loc: Loc | null): Openable => ({ name: path, canonical: path, loc, file: true })
  return [
    ...(issues.policy?.items ?? []).map(v => v.source),
    ...issues.deadCode.items,
    ...issues.hotspots.map(f => file(f.path, f.loc)),
    ...(issues.budget?.files ?? []).map(f => file(f.path, f.loc)),
  ]
}

const sideOf = (title: string, related: { count: number; truncated: boolean; names: string[]; items?: Counterpart[] }): Side => ({
  title,
  count: countLabel(related.count, related.truncated),
  items:
    related.items?.map(i => ({ name: displayName(i), canonical: i.canonical_name, boundary: i.boundary, edges: i.edges })) ??
    // An older knossos names them only: listed without counts, opened by that name (which may be ambiguous).
    related.names.map(name => ({ name, canonical: name, boundary: null, edges: 0 })),
})

/** The detail's view of the component on show, from the stored lookup; `root` places its file on disk. */
export function detailInput(shown: Inspected, state: DetailState | null, root: string | null = null): DetailInput {
  const label = shown.label
  if (state === null || state.name !== shown.name || state.phase === 'loading') return { label, loading: true, messages: null, component: null }
  const answer: ComponentDetail | null = state.detail
  if (answer === null) return { label, loading: false, messages: [`No details for ${label}: knossos said nothing.`], component: null }
  const c = answer.component
  if (answer.status !== 'ok' || c === null) return { label, loading: false, messages: detailLines(answer, shown.name), component: null }
  const name = displayName({ name: c.display_name ?? label, canonical_name: c.name, kind: c.kind })
  return {
    label,
    loading: false,
    messages: null,
    component: {
      name,
      canonical: c.name,
      kind: c.kind,
      boundary: c.boundary ?? c.boundaries[0] ?? null,
      place: c.path === null ? null : `${c.path}${c.line === null ? '' : `:${c.line}`}`,
      loc: locIn(root, c.path, c.line),
      usedBy: sideOf('Used by', c.used_by),
      uses: sideOf('Uses', c.uses),
      annotations: c.annotations ?? [],
    },
  }
}

/** The components the detail can open: everything that uses it, then everything it uses. */
export function detailList(detail: DetailInput): Openable[] {
  return detail.component === null ? [] : [...detail.component.usedBy.items, ...detail.component.uses.items]
}

/** An issue row's columns: its main text, then the boundary, then the place. */
type EntrySpec = { main: number; boundary: number; place: number }
/** One issue row: `need` is how wide its main text is uncut; `loc` makes its place a link. */
type Entry = { selected?: boolean; mark?: Segment; need: number; main: (width: number) => Segment[]; boundary?: string | null; place: string; loc?: Loc | null }

/**
 * Fits issue rows to `columns`, packed to the left like a table: the main
 * text takes what the longest needs, the boundary and the place follow it,
 * and width left over stays at the right edge. As the width shrinks the
 * boundary goes first, then the place; the main text keeps at least
 * {@link MAIN_MIN}.
 */
function entrySpec(columns: number, entries: Entry[], hues: Hues = NO_HUES): EntrySpec {
  const placeNeed = Math.min(Math.max(12, Math.floor(columns * PLACE_SHARE)), Math.max(0, ...entries.map(e => cells(e.place))))
  // A boundary is a chip: its swatch and a space before its label.
  const longest = Math.max(0, ...entries.map(e => cells(boundaryLabel(e.boundary ?? null, hues))))
  const boundaryNeed = longest === 0 ? 0 : Math.min(12, longest) + 2
  const mainNeed = Math.max(1, ...entries.map(e => e.need))
  const attempt = (boundary: number, place: number): EntrySpec | null => {
    const room = columns - MARK - (boundary > 0 ? boundary + 1 : 0) - (place > 0 ? place + 1 : 0)
    return room >= Math.min(MAIN_MIN, mainNeed) ? { main: Math.min(room, mainNeed), boundary, place } : null
  }
  return attempt(boundaryNeed, placeNeed) ?? attempt(0, placeNeed) ?? attempt(0, 0) ?? { main: Math.max(1, columns - MARK), boundary: 0, place: 0 }
}

function entryRow(key: string, e: Entry, spec: EntrySpec, hues: Hues): Row {
  const main = e.main(spec.main)
  const used = main.reduce((n, s) => n + cells(s.text), 0)
  const segments: Segment[] = [
    { text: e.selected ? '›' : ' ', color: ACCENT, bold: true },
    e.mark ?? { text: ' ' },
    { text: ' ' },
    ...main,
    { text: spaces(spec.main - used) },
  ]
  if (spec.boundary > 0) {
    const chipped = chip(e.boundary ?? null, hues, spec.boundary)
    segments.push({ text: ' ' }, ...chipped, { text: spaces(spec.boundary - segmentsWidth(chipped)) })
  }
  // Places are left-aligned: a column of file names reads down its left edge.
  if (spec.place > 0) segments.push({ text: ' ' }, linked(fitStart(e.place, spec.place), e.loc ?? null, { dim: true }))
  const kept = segments.filter(s => s.text !== '')
  return e.selected === true ? { key, segments: tinted(kept, SELECTED_BG), tint: SELECTED_BG } : { key, segments: kept }
}

/** Two names joined by an arrow in `width`, each cut only as far as it must be. */
function pair(a: string, b: string, width: number): [string, string] {
  const room = width - 3
  if (cells(a) + cells(b) <= room) return [a, b]
  const half = Math.floor(room / 2)
  const aw = Math.min(cells(a), Math.max(half, room - cells(b)))
  return [fit(a, aw), fit(b, room - aw)]
}

const none = (key: string): Row => ({ key, segments: [{ text: '   none', dim: true }] })

/** The fewest rows each list on the Issues tab shows, however short the pane. */
const ISSUES_MIN = 3

/**
 * The Issues tab's cards: policy violations, diagnostics, dead code, the
 * complexity hotspots (lines times dependents) and the files over the
 * project's function-length budget, each list as long as the pane allows.
 * Wide, they stand on a grid of equal rows (see {@link issueGrid}).
 */
export function issuesArrangement(issues: IssuesInput, selected: number, tier: Tier, hues: Hues = NO_HUES): Arrangement {
  const policy = issues.policy
  const violations = policy?.items ?? []
  const violationEntries: Entry[] = violations.map((v, i) => ({
    selected: i === selected,
    mark: { text: '▲', color: STATUS_COLOURS.alert },
    boundary: v.source.boundary,
    place: v.place,
    loc: v.source.loc ?? null,
    need: cells(v.source.name) + 3 + (v.targetBoundary === null ? 0 : 2) + cells(v.target),
    main: width => {
      // The target's boundary on a swatch before its name: the name stays in the text tone.
      const swatch: Segment[] = v.targetBoundary === null ? [] : [{ text: `${SWATCH} `, color: boundaryColour(v.targetBoundary, hues) ?? FAINT }]
      const [a, b] = pair(v.source.name, v.target, width - swatch.length * 2)
      return [button(`row:${i}`, a), { text: ' → ', dim: true }, ...swatch, { text: b }]
    },
  }))
  const d = issues.diagnostics
  const diagEntries: Entry[] = (d?.items ?? []).map(x => ({
    mark: x.severity === 'error' ? { text: '✗', color: STATUS_COLOURS.alert } : { text: '▲', color: STATUS_COLOURS.warn },
    place: x.place,
    loc: x.loc,
    need: cells(x.text),
    main: width => [{ text: fit(x.text, width) }],
  }))
  const dead = issues.deadCode
  const offset = violations.length
  const deadEntries: Entry[] = dead.items.map((c, i) => ({
    selected: offset + i === selected,
    mark: c.testOnly ? { text: '◇', dim: true } : undefined,
    boundary: c.boundary,
    place: c.place,
    loc: c.loc ?? null,
    need: cells(c.name),
    main: width => [button(`row:${offset + i}`, fit(c.name, width))],
  }))
  /** A list of entries `limit` long around the marker (`local`: its index in this list), with what is above and below. */
  const listed = (key: string, entries: Entry[], columns: number, limit: number, local: number): Row[] => {
    const spec = entrySpec(columns, entries, hues)
    const window = windowOf(entries.length, limit, local)
    return [...entries.slice(window.start, window.end).map((e, n) => entryRow(`${key}-${window.start + n}`, e, spec, hues)), ...moreRows(`${key}-window`, window, entries.length, columns)]
  }

  const policyBlock: Block = {
    key: 'policy',
    grow: { length: violationEntries.length, min: ISSUES_MIN },
    make: (columns, limit) => {
      const verdict: Segment[] =
        policy === null
          ? [{ text: 'not reported', dim: true }]
          : !policy.evaluated
            ? [{ text: 'no policies declared', dim: true }]
            : policy.count === 0
              ? [{ text: '✓ 0', color: STATUS_COLOURS.ok }]
              : [{ text: `▲ ${policy.total}`, color: STATUS_COLOURS.alert }]
      const body = listed('pol', violationEntries, columns, limit, selected < offset ? selected : -1)
      if (policy !== null && policy.evaluated && policy.count > violations.length) body.push(dimRow('pol-more', `   +${policy.count - violations.length} not listed`, columns))
      return { key: 'policy', title: 'Policy violations', note: verdict, body, empty: 'none' }
    },
  }
  const diagBlock: Block = {
    key: 'diag',
    grow: { length: diagEntries.length, min: ISSUES_MIN },
    make: (columns, limit) => {
      const counts =
        d === null
          ? 'not reported'
          : [plural(d.errors, 'error', 'errors'), plural(d.warnings, 'warning', 'warnings'), ...(d.infos > 0 ? [plural(d.infos, 'note', 'notes')] : [])].join(' · ')
      const body = listed('diag', diagEntries, columns, limit, -1)
      return { key: 'diag', title: 'Diagnostics', note: noteOf(counts), body, empty: 'none' }
    },
  }
  const deadBlock: Block = {
    key: 'dead',
    grow: { length: deadEntries.length, min: ISSUES_MIN },
    make: (columns, limit) => {
      const deadNote = dead.items.length > 0 && dead.total !== String(dead.items.length) ? `${dead.total} · first ${dead.items.length}` : dead.total
      const body = listed('dead', deadEntries, columns, limit, selected >= offset ? selected - offset : -1)
      return { key: 'dead', title: 'Dead code', note: noteOf(deadNote), body, empty: 'none' }
    },
  }
  const spotOffset = offset + dead.items.length
  const hotBlock: Block = {
    key: 'hotspots',
    grow: { length: issues.hotspots.length, min: ISSUES_MIN },
    make: (columns, limit) => {
      const list = issues.hotspots
      const local = selected - spotOffset
      const window = windowOf(list.length, limit, local >= 0 && local < list.length ? local : -1)
      const shown = list.slice(window.start, window.end)
      // The bar draws the score (lines times dependents); the two figures it is made of stand beside it.
      const spec = tableSpec(columns, shown.map(f => f.path), [], [numberWidth('lines', shown.map(f => f.lines), tier), numberWidth('deps', shown.map(f => f.dependents), tier)], 56, { tier })
      const max = Math.max(0, ...list.map(f => f.score))
      const body: Row[] = list.length === 0 ? [] : [tableHead('hot-cols', spec, { name: 'file', boundary: '', numbers: ['lines', 'deps'] })]
      shown.forEach((f, n) => {
        const i = window.start + n
        body.push(tableRow(`hot-${i}`, { name: f.path, boundary: null, values: [f.lines, f.dependents], barValue: f.score, max, path: true, press: `row:${spotOffset + i}`, selected: spotOffset + i === selected }, spec, hues))
      })
      body.push(...moreRows('hot-window', window, list.length, columns))
      return { key: 'hotspots', title: 'Complexity hotspots', note: noteOf(list.length > 0 ? 'lines × dependents' : ''), body, empty: 'none' }
    },
  }
  const budget = issues.budget
  const overOffset = spotOffset + issues.hotspots.length
  const budgetBlock: Block = {
    key: 'budget',
    grow: { length: budget?.files.length ?? 0, min: ISSUES_MIN },
    make: (columns, limit) => {
      const title = 'Over the maintainability budget'
      if (budget === undefined) return { key: 'budget', title, note: noteOf('not reported'), body: [], empty: 'not reported' }
      if (budget === null) return { key: 'budget', title, note: [{ text: 'no maintainability-budgets.json', dim: true }], body: [], empty: 'none' }
      const rule = `functions over ${budget.max} lines`
      if (budget.files.length === 0) return { key: 'budget', title, note: [{ text: '✓ 0', color: STATUS_COLOURS.ok }, { text: ` ${rule}`, dim: true }], body: [], empty: 'none' }
      const local = selected - overOffset
      const window = windowOf(budget.files.length, limit, local >= 0 && local < budget.files.length ? local : -1)
      const shown = budget.files.slice(window.start, window.end)
      const spec = { ...tableSpec(columns, shown.map(f => f.path), [], [numberWidth('over', shown.map(f => f.functions), tier), numberWidth('longest', shown.map(f => f.longest), tier)], 56, { tier }), bar: 0 }
      const body: Row[] = [tableHead('budget-cols', spec, { name: 'file', boundary: '', numbers: ['over', 'longest'] })]
      shown.forEach((f, n) => {
        const i = window.start + n
        body.push(tableRow(`budget-${i}`, { name: f.path, boundary: null, values: [f.functions, f.longest], max: 0, path: true, press: `row:${overOffset + i}`, selected: overOffset + i === selected, mark: { text: '▲', color: STATUS_COLOURS.warn } }, spec, hues))
      })
      body.push(...moreRows('budget-window', window, budget.files.length, columns))
      if (budget.total > budget.files.length) body.push(dimRow('budget-more', `   +${budget.total - budget.files.length} not listed`, columns))
      return { key: 'budget', title, note: [{ text: `▲ ${grouped(budget.total)}`, color: STATUS_COLOURS.warn }, { text: ` ${rule}`, dim: true }], body, empty: 'none' }
    },
  }
  return { left: [policyBlock, diagBlock, budgetBlock], right: [deadBlock, hotBlock], rows: issueGrid([policyBlock, diagBlock, deadBlock, hotBlock, budgetBlock]) }
}

/**
 * How the wide Issues tab sets its cards in rows of equal height: the cards
 * with nothing listed first, side by side as short rows of at most three,
 * the rows as even as they can be (four as two and two, five as three and
 * two), then the lists in pairs, each pair as tall as its longer list
 * allows; a list left over spans the pane.
 */
export function issueGrid(blocks: Block[]): Block[][] {
  const empty = blocks.filter(b => (b.grow?.length ?? 0) === 0)
  const listed = blocks.filter(b => (b.grow?.length ?? 0) > 0)
  const chunks = (list: Block[], size: number): Block[][] => Array.from({ length: Math.ceil(list.length / size) }, (_, i) => list.slice(i * size, i * size + size))
  const per = empty.length === 0 ? 1 : Math.ceil(empty.length / Math.ceil(empty.length / 3))
  return [...chunks(empty, per), ...chunks(listed, 2)]
}

/** One side of the detail ("used by" or "uses") as a card: a small table `limit` rows long around the marker. */
function sideSection(prefix: string, side: Side, offset: number, columns: number, limit: number, tier: Tier, hues: Hues, selected = -1): Section {
  const counted = side.items.some(i => i.edges > 0)
  // Bars that are all one length compare nothing: the counts say it alone.
  const flat = side.items.length > 1 && side.items.every(i => i.edges === side.items[0]!.edges)
  const boundaries = side.items.map(i => boundaryLabel(i.boundary, hues))
  const spec = counted
    ? { ...tableSpec(columns, side.items.map(i => i.name), boundaries, [numberWidth('', side.items.map(i => i.edges))], undefined, { tier, compact: false }), ...(flat ? { bar: 0 } : {}) }
    : { ...tableSpec(columns, side.items.map(i => i.name), boundaries, []), bar: 0 }
  const section = (body: Row[]): Section => ({ key: prefix, title: side.title, subtitle: side.count, note: noteOf(counted ? 'edges' : ''), body })
  if (side.items.length === 0) return section([none(`${prefix}-none`)])
  const max = Math.max(0, ...side.items.map(i => i.edges))
  const local = selected - offset
  const window = windowOf(side.items.length, limit, local >= 0 && local < side.items.length ? local : -1)
  const rows = side.items
    .slice(window.start, window.end)
    .map((item, n) =>
      tableRow(`${prefix}-${window.start + n}`, { name: item.name, boundary: item.boundary, values: counted ? [item.edges] : [], max, press: `rel:${offset + window.start + n}`, selected: offset + window.start + n === selected }, spec, hues),
    )
  rows.push(...moreRows(`${prefix}-window`, window, side.items.length, columns))
  const count = Number.parseInt(side.count, 10)
  if (count > side.items.length) rows.push(dimRow(`${prefix}-more`, `   +${count - side.items.length} not listed`, columns))
  return section(rows)
}

/**
 * The detail of one component: a card naming it (kind, boundary, place),
 * who uses it and what it uses (side by side when wide), its annotations,
 * and below them its change, when it was opened as one.
 */
export function detailArrangement(detail: DetailInput, tier: Tier, hues: Hues = NO_HUES, selected = -1): Arrangement {
  const c = detail.component
  const diff = detail.diff ? [diffBlock(detail.diff)] : []
  if (c === null) {
    const lines = detail.loading ? [`Inspecting ${detail.label}…`] : (detail.messages ?? [])
    const head: Block = {
      key: 'detail',
      make: columns => ({ key: 'detail', title: detail.label, body: lines.flatMap((line, i) => wrapWords(line, columns).map((part, j) => dimRow(`detail-line-${i}-${j}`, part, columns))) }),
    }
    return { left: [head, ...diff] }
  }
  const label: Segment[] = [{ text: c.kind, dim: true }, ...(c.boundary === null ? [] : [{ text: ' · ', dim: true }, ...chip(c.boundary, hues)])]
  const head: Block = {
    key: 'detail',
    make: columns => {
      const body: Row[] = []
      if (c.place !== null) body.push({ key: 'detail-place', segments: pathSegments(c.place, columns, c.loc) })
      if (c.canonical !== c.name) body.push(dimRow('detail-canonical', c.canonical, columns))
      if (body.length === 0) body.push(dimRow('detail-place', 'declared nowhere the graph knows', columns))
      return { key: 'detail', title: c.name, note: label, body }
    },
  }
  const items = (side: Side, offset: number): HoodItem[] => side.items.map((item, i) => ({ name: item.name, boundary: item.boundary, edges: item.edges, press: `rel:${offset + i}` }))
  const hood = hoodBlock(
    { name: c.name, boundary: c.boundary },
    { title: c.usedBy.title, count: Number.parseInt(c.usedBy.count, 10) || c.usedBy.items.length, items: items(c.usedBy, 0) },
    { title: c.uses.title, count: Number.parseInt(c.uses.count, 10) || c.uses.items.length, items: items(c.uses, c.usedBy.items.length) },
    selected,
    hues,
    (columns, limit) => [
      ...sideSection('used', c.usedBy, 0, columns, limit, tier, hues, selected).body.map(r => ({ ...r, key: `hood-${r.key}` })),
      blank('hood-sides-gap'),
      { key: 'hood-uses-title', segments: [{ text: `   ${c.uses.title} · ${c.uses.count}`, dim: true }] },
      ...sideSection('uses', c.uses, c.usedBy.items.length, columns, limit, tier, hues, selected).body.map(r => ({ ...r, key: `hood-${r.key}` })),
    ],
  )
  const notes: Block[] =
    c.annotations.length === 0
      ? []
      : [
          {
            key: 'notes',
            make: columns => ({
              key: 'notes',
              title: 'Annotations',
              body: c.annotations.flatMap((a, i) =>
                wrapWords(`${a.kind.replace(/_/g, ' ')}: ${a.value}`, Math.max(1, columns - MARK)).map((line, j) => ({ key: `note-${i}-${j}`, segments: [{ text: spaces(MARK) }, { text: line }] })),
              ),
            }),
          },
        ]
  return { top: [head], left: [hood], bottom: [...notes, ...diff] }
}

/** One neighbour as the neighbourhood draws it: its name, boundary, edge count and the press that opens it. */
export type HoodItem = { name: string; boundary: string | null; edges: number; press: string }
/** One side of a neighbourhood: its title, how many there are in all, and those listed, in walking order from `items[0]`'s press. */
export type HoodSide = { title: string; count: number; items: HoodItem[] }

/** The fewest neighbours each side of the neighbourhood shows, however short the pane. */
const HOOD_MIN = 3

/**
 * The neighbourhood as a card: the thing on show in the middle, what uses it
 * fanning in, what it uses fanning out, each neighbour a box carrying its
 * count on its edge and a press that opens it (see `diagram.ts`). Each side
 * shows `limit` neighbours around the marker; the rest, and those knossos
 * did not list, are one `+N more` box. Below {@link DIAGRAM_MIN} columns,
 * `fallback`'s rows (the two lists as tables) instead.
 */
export function hoodBlock(centre: { name: string; boundary: string | null }, usedBy: HoodSide, uses: HoodSide, selected: number, hues: Hues, fallback: (columns: number, limit: number) => Row[]): Block {
  const note = noteOf(usedBy.items.some(i => i.edges > 0) || uses.items.some(i => i.edges > 0) ? 'edges' : '')
  const subtitle = `used by ${usedBy.count} · uses ${uses.count}`
  return {
    key: 'hood',
    grow: { length: Math.max(usedBy.items.length, uses.items.length) + 1, min: HOOD_MIN },
    make: (columns, limit) => {
      if (columns < DIAGRAM_MIN) return { key: 'hood', title: usedBy.title, subtitle: String(usedBy.count), note, body: fallback(columns, limit) }
      const side = (s: HoodSide): Neighbour[] => {
        const pressed = s.items.findIndex(i => pressIndex(i.press) === selected)
        const room = s.items.length > limit || s.count > s.items.length ? Math.max(1, limit - 1) : limit
        const window = windowOf(s.items.length, room, pressed)
        const shown = s.items.slice(window.start, window.end).map((item): Neighbour => {
          const colour = boundaryColour(item.boundary, hues)
          return {
            node: { key: item.press, label: item.name, ...(colour === undefined ? {} : { color: colour }), press: { id: item.press, label: item.name }, selected: pressIndex(item.press) === selected },
            edge: item.edges > 0 ? grouped(item.edges) : '',
          }
        })
        const hidden = s.count - shown.length
        return hidden > 0 ? [...shown, { node: { key: `${s.title}-more`, label: `+${hidden} more`, dim: true }, edge: '' }] : shown
      }
      const colour = boundaryColour(centre.boundary, hues)
      const drawn = neighbourhood({ key: 'centre', label: centre.name, ...(colour === undefined ? {} : { color: colour }) }, side(usedBy), side(uses), columns, { usedBy: 'nothing uses it', uses: 'uses nothing' }, 'hood')
      return { key: 'hood', title: 'Dependencies', subtitle, note, body: drawn.rows }
    },
  }
}

/** The list index a `rel:N` or `row:N` press names. */
const pressIndex = (press: string): number => Number(press.slice(press.indexOf(':') + 1))
