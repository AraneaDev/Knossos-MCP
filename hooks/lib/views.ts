/**
 * The pane's Issues and Cycles tabs and its component detail: a view model
 * read from the envelopes, and the rows each draws at a given width.
 *
 * Pure, like the rest of the layout: envelopes in, rows of styled segments
 * out, every width decision made and tested here. Rows that list components
 * are pressable by an index into the view's walkable list, so the marker,
 * `o` and a click all address the same component.
 */
import type { ComponentDetail, Counterpart, Dashboard, DetailState, Inspected } from '../../types'
import { countLabel, detailLines } from './envelopes'
import { ACCENT, boundaryColour, boundaryLabel, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import {
  absolute,
  blank,
  boundaryStyle,
  button,
  cells,
  clip,
  dimRow,
  displayName,
  fit,
  fitStart,
  linked,
  MARK,
  numberWidth,
  padEnd,
  placeOf,
  plural,
  sectionRow,
  sectionWidth,
  shortName,
  spaces,
  specWidth,
  spread,
  tableRow,
  tableSpec,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment } from './rows'

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
}

export type ViolationLine = { source: Openable & { boundary: string | null }; target: string; targetBoundary: string | null; place: string }
export type DeadLine = Openable & { boundary: string | null; place: string; testOnly: boolean }
export type DiagnosticLine = { severity: string; text: string; place: string; loc: Loc | null }

export type IssuesInput = {
  /** Null when the knossos that answered sends no policy section. */
  policy: { evaluated: boolean; total: string; count: number; items: ViolationLine[] } | null
  diagnostics: { errors: number; warnings: number; infos: number; items: DiagnosticLine[] } | null
  deadCode: { total: string; items: DeadLine[] }
  largest: { path: string; lines: number; loc: Loc | null }[]
}

export type CycleLine = { size: number; nodes: { name: string; canonical: string; boundary: string | null }[]; more: number }
export type CyclesInput = { count: string; cycles: CycleLine[] }

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
}

/** One file's detail as the pane draws it; `loc` places each file (and component) on disk. */
export type FileView = {
  path: string
  language: string
  lines: number | null
  boundary: string | null
  loc: Loc | null
  dependents: { count: number; truncated: boolean; boundaries: string[]; items: { path: string; edges: number; boundary: string | null; loc: Loc | null }[] }
  components: { count: number; truncated: boolean; items: { name: string; canonical: string; kind: string; boundary: string | null; usedBy: number; loc: Loc | null }[] }
}

/** Below this many columns the detail stacks "used by" over "uses"; at or above it they sit side by side. */
export const SIDE_BY_SIDE = 72
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
    largest: (d.largest_files ?? []).map(f => ({ path: f.path, lines: f.lines, loc: locIn(root, f.path) })),
  }
}

/** How many issues the tab label counts: policy violations, errors and warnings; `plus` when that is a floor. */
export function issueCount(issues: IssuesInput): { n: number; plus: boolean } {
  const policy = issues.policy?.evaluated ? issues.policy.count : 0
  const diagnostics = issues.diagnostics === null ? 0 : issues.diagnostics.errors + issues.diagnostics.warnings
  return { n: policy + diagnostics, plus: issues.policy?.total.endsWith('+') ?? false }
}

/** The components the Issues tab walks: each violation's source, then each dead-code candidate. */
export function issuesList(issues: IssuesInput): Openable[] {
  return [...(issues.policy?.items ?? []).map(v => v.source), ...issues.deadCode.items]
}

/** The Cycles tab's view of a dashboard: the largest first, each member with its boundary. */
export function cyclesInput(d: Dashboard): CyclesInput {
  return {
    count: countLabel(d.cycles.count, d.cycles.truncated),
    cycles: d.cycles.largest.map(c => {
      const nodes = c.nodes?.map(n => ({ name: displayName(n), canonical: n.canonical_name, boundary: n.boundary })) ?? c.members.map(name => ({ name, canonical: name, boundary: null }))
      return { size: c.size, nodes, more: Math.max(0, c.size - nodes.length) }
    }),
  }
}

/**
 * The cycles the marker walks, one per cycle. Opening one shows the member
 * where it leaves its own boundary (else its first): its uses include the
 * edge that closes the loop. `c` copies the chain and "Ask Claude" asks how
 * to break it.
 */
export function cyclesList(input: CyclesInput): Openable[] {
  return input.cycles.map((cycle, i) => {
    const home = homeBoundary(cycle)
    const first = cycle.nodes.find(n => n.boundary !== home) ?? cycle.nodes[0]
    const names = cycle.nodes.map(n => n.canonical)
    const chain = `${names.join(' → ')}${cycle.more > 0 ? ` → … (+${cycle.more} more)` : names.length > 0 ? ` → ${names[0]}` : ''}`
    return {
      name: `cycle ${i + 1}`,
      canonical: first?.canonical ?? '',
      copy: chain,
      ask: `Using the Knossos graph, how could I break this dependency cycle: ${chain}? Name the edge to cut and what would have to move.`,
    }
  })
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
  const boundaryNeed = Math.min(12, Math.max(0, ...entries.map(e => cells(boundaryLabel(e.boundary ?? null, hues)))))
  const mainNeed = Math.max(1, ...entries.map(e => e.need))
  const attempt = (boundary: number, place: number): EntrySpec | null => {
    const room = columns - MARK - (boundary > 0 ? boundary + 1 : 0) - (place > 0 ? place + 1 : 0)
    return room >= Math.min(MAIN_MIN, mainNeed) ? { main: Math.min(room, mainNeed), boundary, place } : null
  }
  return attempt(boundaryNeed, placeNeed) ?? attempt(0, placeNeed) ?? attempt(0, 0) ?? { main: Math.max(1, columns - MARK), boundary: 0, place: 0 }
}

/** How many columns issue rows laid out by `spec` take. */
const entryWidth = (spec: EntrySpec): number => MARK + spec.main + (spec.boundary > 0 ? spec.boundary + 1 : 0) + (spec.place > 0 ? spec.place + 1 : 0)

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
  if (spec.boundary > 0) segments.push({ text: ' ' }, { text: padEnd(fit(boundaryLabel(e.boundary ?? null, hues), spec.boundary), spec.boundary), ...boundaryStyle(e.boundary ?? null, hues) })
  // Places are left-aligned: a column of file names reads down its left edge.
  if (spec.place > 0) segments.push({ text: ' ' }, linked(fitStart(e.place, spec.place), e.loc ?? null, { dim: true }))
  return { key, segments: segments.filter(s => s.text !== '') }
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

/** The Issues tab: policy violations, diagnostics, dead code and the largest files, each a short list. */
export function issueRows(issues: IssuesInput, selected: number, columns: number, hues: Hues = NO_HUES): Row[] {
  const policy = issues.policy
  const violations = policy?.items ?? []
  const violationEntries: Entry[] = violations.map((v, i) => ({
    selected: i === selected,
    mark: { text: '▲', color: STATUS_COLOURS.alert },
    boundary: v.source.boundary,
    place: v.place,
    loc: v.source.loc ?? null,
    need: cells(v.source.name) + 3 + cells(v.target),
    main: width => {
      const [a, b] = pair(v.source.name, v.target, width)
      return [button(`row:${i}`, a), { text: ' → ', dim: true }, { text: b, ...boundaryStyle(v.targetBoundary, hues) }]
    },
  }))
  const d = issues.diagnostics
  const diagEntries: Entry[] = (d?.items ?? []).map(x => ({
    mark: x.severity === 'error' ? { text: '✖', color: STATUS_COLOURS.alert } : { text: '▲', color: STATUS_COLOURS.warn },
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
  const vSpec = entrySpec(columns, violationEntries, hues)
  const dSpec = entrySpec(columns, diagEntries, hues)
  const deadSpec = entrySpec(columns, deadEntries, hues)
  const spec = tableSpec(columns, issues.largest.map(f => f.path), [], [numberWidth('lines', issues.largest.map(f => f.lines))], 56)
  // Every header of the tab spreads over the widest of its lists, so the notes stand over the places and numbers.
  const listed = [violationEntries.length > 0 ? entryWidth(vSpec) : 0, diagEntries.length > 0 ? entryWidth(dSpec) : 0, deadEntries.length > 0 ? entryWidth(deadSpec) : 0]
  const tab = (title: string, note: string) => sectionWidth(Math.max(...listed, issues.largest.length > 0 ? specWidth(spec) : 0), title, note, columns)

  // Policy violations.
  const verdict: Segment[] =
    policy === null
      ? [{ text: 'not reported', dim: true }]
      : !policy.evaluated
        ? [{ text: 'no policies declared', dim: true }]
        : policy.count === 0
          ? [{ text: '✓ 0', color: STATUS_COLOURS.ok }]
          : [{ text: `▲ ${policy.total}`, color: STATUS_COLOURS.alert }]
  const policyWidth = tab('Policy violations', verdict.map(v => v.text).join(''))
  const rows: Row[] = [blank('gap-policy'), spread('policy-head', [{ text: fit('Policy violations', columns), bold: true, color: 'text' }], verdict, policyWidth)]
  violationEntries.forEach((e, i) => rows.push(entryRow(`pol-${i}`, e, vSpec, hues)))
  if (policy !== null && policy.evaluated && policy.count > violations.length) rows.push(dimRow('pol-more', `   +${policy.count - violations.length} more`, columns))

  // Diagnostics.
  const counts =
    d === null
      ? 'not reported'
      : [plural(d.errors, 'error', 'errors'), plural(d.warnings, 'warning', 'warnings'), ...(d.infos > 0 ? [plural(d.infos, 'note', 'notes')] : [])].join(' · ')
  rows.push(blank('gap-diag'), sectionRow('diag-head', 'Diagnostics', counts, tab('Diagnostics', counts)))
  diagEntries.forEach((e, i) => rows.push(entryRow(`diag-${i}`, e, dSpec, hues)))
  if (d !== null && d.items.length === 0 && d.errors + d.warnings === 0) rows.push(none('diag-none'))

  // Dead code.
  const deadNote = dead.items.length > 0 && dead.total !== String(dead.items.length) ? `${dead.total} · first ${dead.items.length}` : dead.total
  rows.push(blank('gap-dead'), sectionRow('dead-head', 'Dead code', deadNote, tab('Dead code', deadNote)))
  deadEntries.forEach((e, i) => rows.push(entryRow(`dead-${i}`, e, deadSpec, hues)))
  if (dead.items.length === 0) rows.push(none('dead-none'))

  // Largest files.
  const linesNote = issues.largest.length > 0 ? 'lines' : ''
  rows.push(blank('gap-large'), sectionRow('large-head', 'Largest files', linesNote, tab('Largest files', linesNote)))
  if (issues.largest.length === 0) rows.push(none('large-none'))
  const max = Math.max(0, ...issues.largest.map(f => f.lines))
  issues.largest.forEach((f, i) => rows.push(tableRow(`large-${i}`, { name: f.path, boundary: null, values: [f.lines], max, cutStart: true, link: f.loc }, spec, hues)))
  return rows
}

/** The boundary most of a cycle's members are in (the first such, on a tie), or null when none has one. */
function homeBoundary(cycle: CycleLine): string | null {
  const counts = new Map<string, number>()
  for (const node of cycle.nodes) if (node.boundary !== null) counts.set(node.boundary, (counts.get(node.boundary) ?? 0) + 1)
  let home: string | null = null
  for (const [name, n] of counts) if (home === null || n > counts.get(home)!) home = name
  return home
}

/**
 * The Cycles tab: each cycle, largest first, as a chain of names wrapped to
 * width, under a line naming the boundary most of its members are in. Members
 * outside that boundary are drawn in their own boundary's colour.
 */
export function cycleRows(input: CyclesInput, columns: number, hues: Hues = NO_HUES, selected = -1): Row[] {
  const shown = input.cycles.length
  const note = shown === 0 ? 'none' : `${input.count}${String(shown) === input.count ? '' : ` · ${shown} shown`} · largest first`
  const rows: Row[] = [blank('gap-cycles'), sectionRow('cycles-head', 'Cycles', note, columns)]
  if (shown === 0) return [...rows, { key: 'cycles-none', segments: [{ text: '   No dependency cycles.', dim: true }] }]
  // A legend for the members drawn in colour (those outside their cycle's own boundary), in the order they first appear.
  const seen = new Map<string, Segment[]>()
  for (const node of input.cycles.flatMap(c => c.nodes.filter(n => n.boundary !== homeBoundary(c)))) {
    if (node.boundary === null || seen.has(node.boundary)) continue
    seen.set(node.boundary, [{ text: `■ ${boundaryLabel(node.boundary, hues)}`, color: boundaryColour(node.boundary, hues) }])
  }
  if (seen.size > 0) rows.push(...wrapGroups('cycles-legend', [...seen.values()], columns, 2, MARK))
  input.cycles.forEach((cycle, i) => {
    // The boundary most members share is named once, in its colour; only members outside it are coloured: they are where the cycle crosses.
    const home = homeBoundary(cycle)
    const head: Segment[] = [
      { text: i === selected ? '›' : ' ', color: ACCENT, bold: true },
      { text: '  ' },
      button(`row:${i}`, `cycle ${i + 1}`),
      { text: ` · ${plural(cycle.size, 'member', 'members')}`, dim: true },
    ]
    if (home !== null) head.push({ text: ' · ', dim: true }, { text: boundaryLabel(home, hues), ...boundaryStyle(home, hues) })
    rows.push(blank(`gap-cycle-${i}`), { key: `cycle-${i}`, segments: clip(head, columns) })
    const room = Math.max(1, columns - MARK - 2)
    const groups: Segment[][] = cycle.nodes.map((node, j) => {
      const last = j === cycle.nodes.length - 1
      const tail = last ? (cycle.more > 0 ? ' →' : ' ↺') : ' →'
      const style = node.boundary === home ? {} : boundaryStyle(node.boundary, hues)
      return [{ text: fit(node.name, room), ...style }, { text: tail, dim: true }]
    })
    if (cycle.more > 0) groups.push([{ text: `… +${cycle.more} more`, dim: true }])
    rows.push(...wrapGroups(`chain-${i}`, groups, columns, 1, MARK))
  })
  return rows
}

/** One side of the detail ("used by" or "uses") as a small table in `width` columns. */
function sideRows(prefix: string, side: Side, offset: number, width: number, hues: Hues, selected = -1): Row[] {
  const counted = side.items.some(i => i.edges > 0)
  // Bars that are all one length compare nothing: the counts say it alone.
  const flat = side.items.length > 1 && side.items.every(i => i.edges === side.items[0]!.edges)
  const boundaries = side.items.map(i => boundaryLabel(i.boundary, hues))
  const spec = counted
    ? { ...tableSpec(width, side.items.map(i => i.name), boundaries, [numberWidth('', side.items.map(i => i.edges))]), ...(flat ? { bar: 0 } : {}) }
    : { ...tableSpec(width, side.items.map(i => i.name), boundaries, []), bar: 0 }
  const title = `${side.title} ${side.count}`
  const note = counted ? 'edges' : ''
  const rows: Row[] = [sectionRow(`${prefix}-head`, title, note, side.items.length === 0 ? width : sectionWidth(specWidth(spec), title, note, width))]
  if (side.items.length === 0) return [...rows, none(`${prefix}-none`)]
  const max = Math.max(0, ...side.items.map(i => i.edges))
  side.items.forEach((item, i) =>
    rows.push(tableRow(`${prefix}-${i}`, { name: item.name, boundary: item.boundary, values: counted ? [item.edges] : [], max, press: `rel:${offset + i}`, selected: offset + i === selected }, spec, hues)),
  )
  const count = Number.parseInt(side.count, 10)
  if (count > side.items.length) rows.push(dimRow(`${prefix}-more`, `   +${count - side.items.length} more`, width))
  return rows
}

/** Rows placed side by side: the left padded to its width, a gap, the right. */
function besideRows(left: Row[], right: Row[], leftWidth: number, gap: number): Row[] {
  const out: Row[] = []
  for (let i = 0; i < Math.max(left.length, right.length); i++) {
    const l = left[i]?.segments ?? []
    const used = l.reduce((n, s) => n + cells(s.text), 0)
    out.push({ key: `side-${i}`, segments: [...l, { text: spaces(leftWidth - used + gap) }, ...(right[i]?.segments ?? [])] })
  }
  return out
}

/** The detail of one component: what it is, where, who uses it and what it uses, and its annotations. */
export function detailRows(detail: DetailInput, columns: number, hues: Hues = NO_HUES, selected = -1): Row[] {
  const c = detail.component
  if (c === null) {
    const lines = detail.loading ? [`Inspecting ${detail.label}…`] : (detail.messages ?? [])
    return [
      blank('gap-detail'),
      { key: 'detail-name', segments: [{ text: fit(detail.label, columns), bold: true, color: 'text' }] },
      ...lines.flatMap((line, i) => wrapWords(line, columns).map((part, j) => dimRow(`detail-line-${i}-${j}`, part, columns))),
    ]
  }
  const label: Segment[] = [{ text: c.kind, dim: true }, ...(c.boundary === null ? [] : [{ text: ' · ', dim: true }, { text: boundaryLabel(c.boundary, hues), ...boundaryStyle(c.boundary, hues) }])]
  const rows: Row[] = [blank('gap-detail'), spread('detail-name', [{ text: fit(c.name, columns), bold: true, color: 'text' }], label, columns)]
  if (c.place !== null) rows.push({ key: 'detail-place', segments: [linked(fitStart(c.place, columns), c.loc, { dim: true })] })
  if (c.canonical !== c.name) rows.push(dimRow('detail-canonical', c.canonical, columns))
  rows.push(blank('gap-sides'))
  const usedBy = c.usedBy
  if (columns >= SIDE_BY_SIDE) {
    const half = Math.floor((columns - 2) / 2)
    rows.push(...besideRows(sideRows('used', usedBy, 0, half, hues, selected), sideRows('uses', c.uses, usedBy.items.length, columns - half - 2, hues, selected), half, 2))
  } else {
    rows.push(...sideRows('used', usedBy, 0, columns, hues, selected), blank('gap-uses'), ...sideRows('uses', c.uses, usedBy.items.length, columns, hues, selected))
  }
  if (c.annotations.length > 0) {
    rows.push(blank('gap-notes'), sectionRow('notes-head', 'Annotations', '', columns))
    c.annotations.forEach((a, i) =>
      wrapWords(`${a.kind.replace(/_/g, ' ')}: ${a.value}`, Math.max(1, columns - MARK)).forEach((line, j) =>
        rows.push({ key: `note-${i}-${j}`, segments: [{ text: spaces(MARK) }, { text: line }] }),
      ),
    )
  }
  return rows
}
