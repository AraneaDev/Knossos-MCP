/**
 * The pane's Issues and Cycles tabs and its component detail: a view model
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
import type { ComponentDetail, Counterpart, Dashboard, DetailState, Inspected } from '../../types'
import { countLabel, detailLines } from './envelopes'
import { ACCENT, boundaryColour, boundaryLabel, FAINT, NO_HUES, SELECTED_BG, STATUS_COLOURS } from './palette'
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
  shortName,
  spaces,
  tableRow,
  tableSpec,
  tinted,
  wrapGroups,
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
 * The Issues tab's cards: policy violations, diagnostics, dead code and the
 * largest files, each list as long as the pane allows. Wide, the violations
 * and diagnostics stand left, dead code and the largest files right.
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
      if (body.length === 0) body.push(none('pol-none'))
      return { key: 'policy', title: 'Policy violations', note: verdict, body }
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
      if (body.length === 0) body.push(none('diag-none'))
      return { key: 'diag', title: 'Diagnostics', note: noteOf(counts), body }
    },
  }
  const deadBlock: Block = {
    key: 'dead',
    grow: { length: deadEntries.length, min: ISSUES_MIN },
    make: (columns, limit) => {
      const deadNote = dead.items.length > 0 && dead.total !== String(dead.items.length) ? `${dead.total} · first ${dead.items.length}` : dead.total
      const body = listed('dead', deadEntries, columns, limit, selected >= offset ? selected - offset : -1)
      if (body.length === 0) body.push(none('dead-none'))
      return { key: 'dead', title: 'Dead code', note: noteOf(deadNote), body }
    },
  }
  const largeBlock: Block = {
    key: 'large',
    grow: { length: issues.largest.length, min: ISSUES_MIN },
    make: (columns, limit) => {
      const window = windowOf(issues.largest.length, limit)
      const shown = issues.largest.slice(0, window.end)
      const spec = tableSpec(columns, shown.map(f => f.path), [], [numberWidth('lines', shown.map(f => f.lines), tier)], 56, { tier })
      const max = Math.max(0, ...issues.largest.map(f => f.lines))
      const body: Row[] = shown.map((f, i) => tableRow(`large-${i}`, { name: f.path, boundary: null, values: [f.lines], max, cutStart: true, link: f.loc }, spec, hues))
      body.push(...moreRows('large-window', window, issues.largest.length, columns))
      if (body.length === 0) body.push(none('large-none'))
      return { key: 'large', title: 'Largest files', note: noteOf(issues.largest.length > 0 ? 'lines' : ''), body }
    },
  }
  return { left: [policyBlock, diagBlock], right: [deadBlock, largeBlock], rows: issueGrid([policyBlock, diagBlock, deadBlock, largeBlock]) }
}

/**
 * How the wide Issues tab sets its four cards in rows of equal height: the
 * cards with nothing listed first, side by side as one short row (two rows
 * of two when all four are empty), then the lists in pairs, each pair as
 * tall as its longer list allows; a list left over spans the pane.
 */
export function issueGrid(blocks: Block[]): Block[][] {
  const empty = blocks.filter(b => (b.grow?.length ?? 0) === 0)
  const listed = blocks.filter(b => (b.grow?.length ?? 0) > 0)
  const pairs = (list: Block[]): Block[][] => Array.from({ length: Math.ceil(list.length / 2) }, (_, i) => list.slice(i * 2, i * 2 + 2))
  return [...(empty.length > 3 ? pairs(empty) : empty.length > 0 ? [empty] : []), ...pairs(listed)]
}

/** The boundary most of a cycle's members are in (the first such, on a tie), or null when none has one. */
function homeBoundary(cycle: CycleLine): string | null {
  const counts = new Map<string, number>()
  for (const node of cycle.nodes) if (node.boundary !== null) counts.set(node.boundary, (counts.get(node.boundary) ?? 0) + 1)
  let home: string | null = null
  for (const [name, n] of counts) if (home === null || n > counts.get(home)!) home = name
  return home
}

/** The fewest cycles the Cycles tab lists, however short the pane. */
const CYCLES_MIN = 2

/**
 * The Cycles tab: each cycle, largest first, as a chain of names wrapped to
 * width, under a line naming the boundary most of its members are in.
 * Members outside that boundary are drawn in their own boundary's colour.
 * Wide, the marked cycle is spelled out beside the list, a member a row.
 */
export function cyclesArrangement(input: CyclesInput, hues: Hues = NO_HUES, selected = -1): Arrangement {
  const shown = input.cycles.length
  const list: Block = {
    key: 'cycles',
    grow: { length: shown, min: CYCLES_MIN },
    make: (columns, limit) => {
      const note = shown === 0 ? 'none' : `${input.count}${String(shown) === input.count ? '' : ` · ${shown} shown`} · largest first`
      const section = (body: Row[]): Section => ({ key: 'cycles', title: 'Cycles', note: noteOf(note), body })
      if (shown === 0) return section([{ key: 'cycles-none', segments: [{ text: '   No dependency cycles.', dim: true }] }])
      return section(cycleListRows(input, columns, hues, selected, windowOf(shown, limit, selected)))
    },
  }
  const marked = input.cycles[Math.min(Math.max(0, selected), shown - 1)]
  if (marked === undefined) return { left: [list] }
  const index = input.cycles.indexOf(marked)
  const members: Block = { key: 'cycle-members', make: columns => memberSection(marked, index, columns, hues) }
  return { left: [list], right: [members], order: [list] }
}

/**
 * The cycles in `window` as rows: a legend for the colours, then each
 * cycle's line and its chain. The chain opens with `↻` and runs member to
 * member, each hop in its own boundary's colour, so the eye sees where the
 * loop crosses from one boundary into another; it wraps across the width
 * and closes with `→ ↻`, back to where it began.
 */
function cycleListRows(input: CyclesInput, columns: number, hues: Hues, selected: number, window: { start: number; end: number }): Row[] {
  const rows: Row[] = []
  const visible = input.cycles.slice(window.start, window.end)
  // A legend for the members' colours, in the order they first appear.
  const seen = new Map<string, Segment[]>()
  for (const node of visible.flatMap(c => c.nodes)) {
    if (node.boundary === null || seen.has(node.boundary) || boundaryColour(node.boundary, hues) === undefined) continue
    seen.set(node.boundary, [{ text: `■ ${boundaryLabel(node.boundary, hues)}`, color: boundaryColour(node.boundary, hues) }])
  }
  if (seen.size > 1) rows.push(...wrapGroups('cycles-legend', [...seen.values()], columns, 2, MARK))
  visible.forEach((cycle, n) => {
    const i = window.start + n
    // The boundary most members share is named once, in its colour.
    const home = homeBoundary(cycle)
    const head: Segment[] = [
      { text: i === selected ? '›' : ' ', color: ACCENT, bold: true },
      { text: '  ' },
      button(`row:${i}`, `cycle ${i + 1}`),
      { text: ` · ${plural(cycle.size, 'member', 'members')}`, dim: true },
    ]
    if (home !== null) head.push({ text: ' · ', dim: true }, { text: boundaryLabel(home, hues), ...boundaryStyle(home, hues) })
    if (n > 0 || seen.size > 1) rows.push(blank(`gap-cycle-${i}`))
    rows.push(i === selected ? { key: `cycle-${i}`, segments: tinted(clip(head, columns), SELECTED_BG), tint: SELECTED_BG } : { key: `cycle-${i}`, segments: clip(head, columns) })
    rows.push(...wrapGroups(`chain-${i}`, chainGroups(cycle, Math.max(1, columns - MARK - 4), hues), columns, 1, MARK))
  })
  return [...rows, ...moreRows('cycles-window', window, input.cycles.length, columns)]
}

/** A cycle's chain as groups that wrap whole: `↻`, then each member (in its boundary's colour) with the arrow after it, then the loop's close. */
export function chainGroups(cycle: CycleLine, room: number, hues: Hues = NO_HUES): Segment[][] {
  const groups: Segment[][] = [[{ text: '↻', color: ACCENT }]]
  cycle.nodes.forEach((node, j) => {
    const last = j === cycle.nodes.length - 1
    groups.push([{ text: fit(node.name, room), ...boundaryStyle(node.boundary, hues) }, ...(last && cycle.more === 0 ? [] : [{ text: ' →', dim: true }])])
  })
  groups.push(cycle.more > 0 ? [{ text: `… +${cycle.more} more`, dim: true }] : [{ text: '→ ↻', dim: true }])
  return groups
}

/** The widest a member's boundary label is drawn beside it. */
const MEMBER_BOUNDARY_MAX = 20

/** The marked cycle spelled out: one member a row, its boundary beside it, the last closing the loop. */
function memberSection(cycle: CycleLine, index: number, columns: number, hues: Hues): Section {
  const home = homeBoundary(cycle)
  // No numbers and no bar: the names take what they need, the boundary what is left.
  const boundary = Math.min(MEMBER_BOUNDARY_MAX, Math.max(0, ...cycle.nodes.map(n => cells(boundaryLabel(n.boundary, hues)))))
  const name = Math.max(1, Math.min(Math.max(1, ...cycle.nodes.map(n => cells(n.name))), columns - MARK - (boundary > 0 ? boundary + 1 : 0)))
  const spec = { name, boundary: columns - MARK - name - 1 >= Math.min(boundary, 6) && boundary > 0 ? Math.min(boundary, columns - MARK - name - 1) : 0, bar: 0, numbers: [] }
  const body: Row[] = cycle.nodes.map((node, j) =>
    tableRow(`member-${j}`, { name: node.name, boundary: node.boundary, values: [], max: 0, mark: { text: j === 0 ? '┌' : '│', color: FAINT }, repeat: node.boundary === home && j > 0 }, spec, hues),
  )
  body.push({ key: 'member-close', segments: [{ text: ' ' }, { text: cycle.more > 0 ? '┆' : '└', color: FAINT }, { text: cycle.more > 0 ? ` … +${cycle.more} more` : ` ↻ back to ${fit(cycle.nodes[0]?.name ?? '', Math.max(1, columns - 12))}`, dim: true }] })
  return { key: 'cycle-members', title: `Cycle ${index + 1}`, note: noteOf(plural(cycle.size, 'member', 'members')), body }
}

/** The fewest counterparts each side of the detail lists, however short the pane. */
const SIDE_MIN = 5

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
  const label: Segment[] = [{ text: c.kind, dim: true }, ...(c.boundary === null ? [] : [{ text: ' · ', dim: true }, { text: boundaryLabel(c.boundary, hues), ...boundaryStyle(c.boundary, hues) }])]
  const head: Block = {
    key: 'detail',
    make: columns => {
      const body: Row[] = []
      if (c.place !== null) body.push({ key: 'detail-place', segments: [linked(fitStart(c.place, columns), c.loc, { dim: true })] })
      if (c.canonical !== c.name) body.push(dimRow('detail-canonical', c.canonical, columns))
      if (body.length === 0) body.push(dimRow('detail-place', 'declared nowhere the graph knows', columns))
      return { key: 'detail', title: c.name, note: label, body }
    },
  }
  const used: Block = { key: 'used', grow: { length: c.usedBy.items.length, min: SIDE_MIN }, make: (columns, limit) => sideSection('used', c.usedBy, 0, columns, limit, tier, hues, selected) }
  const uses: Block = {
    key: 'uses',
    grow: { length: c.uses.items.length, min: SIDE_MIN },
    make: (columns, limit) => sideSection('uses', c.uses, c.usedBy.items.length, columns, limit, tier, hues, selected),
  }
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
  return { top: [head], left: [used], right: [uses], bottom: [...notes, ...diff] }
}
