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
  blank,
  boundaryStyle,
  button,
  cells,
  clip,
  dimRow,
  displayName,
  fit,
  fitStart,
  MARK,
  numberWidth,
  padEnd,
  padStart,
  placeOf,
  plural,
  sectionRow,
  shortName,
  spaces,
  spread,
  tableRow,
  tableSpec,
  wrapGroups,
  wrapWords,
} from './rows'
import type { Row, Segment } from './rows'

/** A component a row can open: `name` to show, `canonical` to look it up by. */
export type Openable = { name: string; canonical: string }

export type ViolationLine = { source: Openable & { boundary: string | null }; target: string; targetBoundary: string | null; place: string }
export type DeadLine = Openable & { boundary: string | null; place: string; testOnly: boolean }
export type DiagnosticLine = { severity: string; text: string; place: string }

export type IssuesInput = {
  /** Null when the knossos that answered sends no policy section. */
  policy: { evaluated: boolean; total: string; count: number; items: ViolationLine[] } | null
  diagnostics: { errors: number; warnings: number; infos: number; items: DiagnosticLine[] } | null
  deadCode: { total: string; items: DeadLine[] }
  largest: { path: string; lines: number }[]
}

export type CycleLine = { size: number; nodes: { name: string; boundary: string | null }[]; more: number }
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
    usedBy: Side
    uses: Side
    annotations: { kind: string; value: string }[]
  } | null
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

/** The Issues tab's view of a dashboard. */
export function issuesInput(d: Dashboard): IssuesInput {
  const policy = d.policy
  const diagnostics = d.diagnostics
  return {
    policy:
      policy === undefined
        ? null
        : {
            evaluated: policy.status === 'evaluated',
            total: countLabel(policy.total, policy.truncated),
            count: policy.total,
            items: policy.items.map(v => ({
              source: { name: shortName(v.source), canonical: v.source, boundary: v.source_boundary },
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
            items: diagnostics.items.map(x => ({ severity: x.severity, text: `${x.code} ${x.message}`, place: placeOf(x.path, x.line) })),
          },
    deadCode: {
      total: countLabel(d.dead_code_candidates, d.dead_code_truncated),
      items: (d.dead_code ?? []).map(c => ({
        name: displayName(c),
        canonical: c.canonical_name,
        boundary: c.boundary,
        place: placeOf(c.path, c.line),
        testOnly: c.reachability === 'test_only',
      })),
    },
    largest: (d.largest_files ?? []).map(f => ({ path: f.path, lines: f.lines })),
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
      const nodes = c.nodes?.map(n => ({ name: displayName(n), boundary: n.boundary })) ?? c.members.map(name => ({ name, boundary: null }))
      return { size: c.size, nodes, more: Math.max(0, c.size - nodes.length) }
    }),
  }
}

const sideOf = (title: string, related: { count: number; truncated: boolean; names: string[]; items?: Counterpart[] }): Side => ({
  title,
  count: countLabel(related.count, related.truncated),
  items:
    related.items?.map(i => ({ name: displayName(i), canonical: i.canonical_name, boundary: i.boundary, edges: i.edges })) ??
    // An older knossos names them only: listed without counts, opened by that name (which may be ambiguous).
    related.names.map(name => ({ name, canonical: name, boundary: null, edges: 0 })),
})

/** The detail's view of the component on show, from the stored lookup. */
export function detailInput(shown: Inspected, state: DetailState | null): DetailInput {
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

/** An issue row's columns: its main text, then the boundary, then the place against the right edge. */
type EntrySpec = { main: number; boundary: number; place: number }
type Entry = { selected?: boolean; mark?: Segment; main: (width: number) => Segment[]; boundary?: string | null; place: string }

/** Fits issue rows to `columns`: the boundary goes first, then the place; the main text keeps at least {@link MAIN_MIN}. */
function entrySpec(columns: number, entries: Entry[]): EntrySpec {
  const placeNeed = Math.min(Math.max(12, Math.floor(columns * PLACE_SHARE)), Math.max(0, ...entries.map(e => cells(e.place))))
  const boundaryNeed = Math.min(12, Math.max(0, ...entries.map(e => cells(boundaryLabel(e.boundary ?? null)))))
  const attempt = (boundary: number, place: number): EntrySpec | null => {
    const main = columns - MARK - (boundary > 0 ? boundary + 1 : 0) - (place > 0 ? place + 1 : 0)
    return main >= MAIN_MIN ? { main, boundary, place } : null
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
  if (spec.boundary > 0) segments.push({ text: ' ' }, { text: padEnd(fit(boundaryLabel(e.boundary ?? null), spec.boundary), spec.boundary), ...boundaryStyle(e.boundary ?? null, hues) })
  if (spec.place > 0) segments.push({ text: ` ${padStart(fitStart(e.place, spec.place), spec.place)}`, dim: true })
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
  const rows: Row[] = []
  const policy = issues.policy
  const violations = policy?.items ?? []
  // Policy violations.
  const verdict: Segment[] =
    policy === null
      ? [{ text: 'not reported', dim: true }]
      : !policy.evaluated
        ? [{ text: 'no policies declared', dim: true }]
        : policy.count === 0
          ? [{ text: '✓ 0', color: STATUS_COLOURS.ok }]
          : [{ text: `▲ ${policy.total}`, color: STATUS_COLOURS.alert }]
  rows.push(blank('gap-policy'), spread('policy-head', [{ text: fit('Policy violations', columns), bold: true, color: 'text' }], verdict, columns))
  const violationEntries: Entry[] = violations.map((v, i) => ({
    selected: i === selected,
    mark: { text: '▲', color: STATUS_COLOURS.alert },
    boundary: v.source.boundary,
    place: v.place,
    main: width => {
      const [a, b] = pair(v.source.name, v.target, width)
      return [button(`row:${i}`, a), { text: ' → ', dim: true }, { text: b, ...boundaryStyle(v.targetBoundary, hues) }]
    },
  }))
  const vSpec = entrySpec(columns, violationEntries)
  violationEntries.forEach((e, i) => rows.push(entryRow(`pol-${i}`, e, vSpec, hues)))
  if (policy !== null && policy.evaluated && policy.count > violations.length) rows.push(dimRow('pol-more', `   +${policy.count - violations.length} more`, columns))

  // Diagnostics.
  const d = issues.diagnostics
  const counts =
    d === null
      ? 'not reported'
      : [plural(d.errors, 'error', 'errors'), plural(d.warnings, 'warning', 'warnings'), ...(d.infos > 0 ? [plural(d.infos, 'note', 'notes')] : [])].join(' · ')
  rows.push(blank('gap-diag'), sectionRow('diag-head', 'Diagnostics', counts, columns))
  const diagEntries: Entry[] = (d?.items ?? []).map(x => ({
    mark: x.severity === 'error' ? { text: '✖', color: STATUS_COLOURS.alert } : { text: '▲', color: STATUS_COLOURS.warn },
    place: x.place,
    main: width => [{ text: fit(x.text, width) }],
  }))
  const dSpec = entrySpec(columns, diagEntries)
  diagEntries.forEach((e, i) => rows.push(entryRow(`diag-${i}`, e, dSpec, hues)))
  if (d !== null && d.items.length === 0 && d.errors + d.warnings === 0) rows.push(none('diag-none'))

  // Dead code.
  const dead = issues.deadCode
  const deadNote = dead.items.length > 0 && dead.total !== String(dead.items.length) ? `${dead.total} · first ${dead.items.length}` : dead.total
  rows.push(blank('gap-dead'), sectionRow('dead-head', 'Dead code', deadNote, columns))
  const offset = violations.length
  const deadEntries: Entry[] = dead.items.map((c, i) => ({
    selected: offset + i === selected,
    mark: c.testOnly ? { text: '◇', dim: true } : undefined,
    boundary: c.boundary,
    place: c.place,
    main: width => [button(`row:${offset + i}`, fit(c.name, width))],
  }))
  const deadSpec = entrySpec(columns, deadEntries)
  deadEntries.forEach((e, i) => rows.push(entryRow(`dead-${i}`, e, deadSpec, hues)))
  if (dead.items.length === 0) rows.push(none('dead-none'))

  // Largest files.
  rows.push(blank('gap-large'), sectionRow('large-head', 'Largest files', issues.largest.length > 0 ? 'lines' : '', columns))
  if (issues.largest.length === 0) rows.push(none('large-none'))
  const spec = tableSpec(columns, issues.largest.map(f => f.path), [], [numberWidth('lines', issues.largest.map(f => f.lines))], 56)
  const max = Math.max(0, ...issues.largest.map(f => f.lines))
  issues.largest.forEach((f, i) => rows.push(tableRow(`large-${i}`, { name: f.path, boundary: null, values: [f.lines], max, cutStart: true }, spec, hues)))
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
export function cycleRows(input: CyclesInput, columns: number, hues: Hues = NO_HUES): Row[] {
  const shown = input.cycles.length
  const note = shown === 0 ? 'none' : `${input.count}${String(shown) === input.count ? '' : ` · ${shown} shown`} · largest first`
  const rows: Row[] = [blank('gap-cycles'), sectionRow('cycles-head', 'Cycles', note, columns)]
  if (shown === 0) return [...rows, { key: 'cycles-none', segments: [{ text: '   No dependency cycles.', dim: true }] }]
  // A legend for the members drawn in colour (those outside their cycle's own boundary), in the order they first appear.
  const seen = new Map<string, Segment[]>()
  for (const node of input.cycles.flatMap(c => c.nodes.filter(n => n.boundary !== homeBoundary(c)))) {
    if (node.boundary === null || seen.has(node.boundary)) continue
    seen.set(node.boundary, [{ text: `■ ${boundaryLabel(node.boundary)}`, color: boundaryColour(node.boundary, hues) }])
  }
  if (seen.size > 0) rows.push(...wrapGroups('cycles-legend', [...seen.values()], columns, 2, MARK))
  input.cycles.forEach((cycle, i) => {
    // The boundary most members share is named once, in its colour; only members outside it are coloured: they are where the cycle crosses.
    const home = homeBoundary(cycle)
    const head: Segment[] = [{ text: `   cycle ${i + 1} · ${plural(cycle.size, 'member', 'members')}`, dim: true }]
    if (home !== null) head.push({ text: ' · ', dim: true }, { text: boundaryLabel(home), ...boundaryStyle(home, hues) })
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
function sideRows(prefix: string, side: Side, offset: number, width: number, hues: Hues): Row[] {
  const rows: Row[] = [sectionRow(`${prefix}-head`, `${side.title} ${side.count}`, side.items.some(i => i.edges > 0) ? 'edges' : '', width)]
  if (side.items.length === 0) return [...rows, none(`${prefix}-none`)]
  const counted = side.items.some(i => i.edges > 0)
  const spec = counted
    ? tableSpec(width, side.items.map(i => i.name), side.items.map(i => boundaryLabel(i.boundary)), [numberWidth('', side.items.map(i => i.edges))])
    : { ...tableSpec(width, side.items.map(i => i.name), [], []), bar: 0 }
  const max = Math.max(0, ...side.items.map(i => i.edges))
  side.items.forEach((item, i) =>
    rows.push(tableRow(`${prefix}-${i}`, { name: item.name, boundary: item.boundary, values: counted ? [item.edges] : [], max, press: `rel:${offset + i}` }, spec, hues)),
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
export function detailRows(detail: DetailInput, columns: number, hues: Hues = NO_HUES): Row[] {
  const c = detail.component
  if (c === null) {
    const lines = detail.loading ? [`Inspecting ${detail.label}…`] : (detail.messages ?? [])
    return [
      blank('gap-detail'),
      { key: 'detail-name', segments: [{ text: fit(detail.label, columns), bold: true, color: 'text' }] },
      ...lines.flatMap((line, i) => wrapWords(line, columns).map((part, j) => dimRow(`detail-line-${i}-${j}`, part, columns))),
    ]
  }
  const label: Segment[] = [{ text: c.kind, dim: true }, ...(c.boundary === null ? [] : [{ text: ' · ', dim: true }, { text: boundaryLabel(c.boundary), ...boundaryStyle(c.boundary, hues) }])]
  const rows: Row[] = [blank('gap-detail'), spread('detail-name', [{ text: fit(c.name, columns), bold: true, color: 'text' }], label, columns)]
  if (c.place !== null) rows.push(dimRow('detail-place', fitStart(c.place, columns), columns))
  if (c.canonical !== c.name) rows.push(dimRow('detail-canonical', c.canonical, columns))
  rows.push(blank('gap-sides'))
  const usedBy = c.usedBy
  if (columns >= SIDE_BY_SIDE) {
    const half = Math.floor((columns - 2) / 2)
    rows.push(...besideRows(sideRows('used', usedBy, 0, half, hues), sideRows('uses', c.uses, usedBy.items.length, columns - half - 2, hues), half, 2))
  } else {
    rows.push(...sideRows('used', usedBy, 0, columns, hues), blank('gap-uses'), ...sideRows('uses', c.uses, usedBy.items.length, columns, hues))
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
