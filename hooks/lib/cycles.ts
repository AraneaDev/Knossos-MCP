/**
 * The Cycles tab: the marked cycle drawn as a diagram (see `diagram.ts`),
 * and every cycle listed under it.
 *
 * The marker walks members, not cycles: the list it walks holds each
 * cycle's members in order, then one entry standing for the members a long
 * cycle folds away. The cycle on show is the one the marker is in; a press
 * on a cycle in the list puts the marker on its first member, `j` past a
 * cycle's last member goes on to the next cycle's first.
 *
 * A long cycle on a narrow pane folds its middle into one `… N more …` box:
 * the marker can rest on it, and `o` (or a press) unfolds the cycle. How much
 * shows depends on the width, which only the layout knows; so `j`, `k` and
 * that `o` are buttons whose ids the layout writes (`next:N`, `prev:N`, `unfold:C:N`),
 * naming the very row each moves to; the fold's own box presses as
 * `fold:C:N`, a cycle's line in the list as `mark:N` (each id is drawn once).
 *
 * Below {@link DIAGRAM_MIN} columns the diagram gives way to the chain as
 * text (`↻ a → b → c → ↻`) and a member a row.
 */
import { moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { CROSSING, cycleDiagram, DIAGRAM_MIN } from './diagram'
export { DIAGRAM_MIN } from './diagram'
import type { DiagramNode, Hop } from './diagram'
import type { Dashboard } from '../../types'
import { countLabel } from './envelopes'
import { ACCENT, boundaryColour, boundaryLabel, FAINT, NO_HUES, SELECTED_BG, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { blank, boundaryStyle, button, cells, clip, displayName, fit, MARK, plural, tableRow, tinted, wrapGroups } from './rows'
import type { Row, Segment, Tier } from './rows'
import type { Openable } from './views'

export type CycleNode = { name: string; canonical: string; boundary: string | null }
export type CycleLine = { size: number; nodes: CycleNode[]; more: number }
/** The cycles, largest first, and the ones the person unfolded (by index). */
export type CyclesInput = { count: string; cycles: CycleLine[]; unfolded: number[] }


/** The most boxes a cycle shows before its middle folds, per tier. */
export const FOLD_AT: Record<Tier, number> = { narrow: 6, medium: 10, wide: 16 }

/** The Cycles tab's view of a dashboard: the largest first, each member with its boundary. */
export function cyclesInput(d: Pick<Dashboard, 'cycles'>, unfolded: number[] = []): CyclesInput {
  return {
    count: countLabel(d.cycles.count, d.cycles.truncated),
    cycles: d.cycles.largest.map(c => {
      const nodes = c.nodes?.map(n => ({ name: displayName(n), canonical: n.canonical_name, boundary: n.boundary })) ?? c.members.map(name => ({ name, canonical: name, boundary: null }))
      return { size: c.size, nodes, more: Math.max(0, c.size - nodes.length) }
    }),
    unfolded,
  }
}

/** Where each cycle's entries start in the list the marker walks: its members, then its fold. */
export function cycleOffsets(input: CyclesInput): number[] {
  const offsets: number[] = []
  let at = 0
  for (const cycle of input.cycles) {
    offsets.push(at)
    at += cycle.nodes.length + 1
  }
  return offsets
}

/** A cycle's chain as one line, for a copy and a question: `a → b → c → a`. */
function chainOf(cycle: CycleLine): string {
  const names = cycle.nodes.map(n => n.canonical)
  return `${names.join(' → ')}${cycle.more > 0 ? ` → … (+${cycle.more} more)` : names.length > 0 ? ` → ${names[0]}` : ''}`
}

const askOf = (cycle: CycleLine): string => `Using the Knossos graph, how could I break this dependency cycle: ${chainOf(cycle)}? Name the edge to cut and what would have to move.`

/**
 * What the marker walks on the Cycles tab: each cycle's members (each opens
 * as its own detail; "Ask Claude" asks how to break the cycle it is in),
 * then the entry its folded middle stands for, which opens nothing.
 */
export function cyclesList(input: CyclesInput): Openable[] {
  return input.cycles.flatMap((cycle, i) => [
    ...cycle.nodes.map((n): Openable => ({ name: n.name, canonical: n.canonical, ask: askOf(cycle) })),
    { name: `cycle ${i + 1}`, canonical: cycle.nodes[0]?.canonical ?? '', inert: true, copy: chainOf(cycle), ask: askOf(cycle) },
  ])
}

/** The cycle the marker is in, and the marked entry's place in it (the fold is past its last member). */
export function markedCycle(input: CyclesInput, selected: number): { index: number; local: number } | null {
  if (input.cycles.length === 0) return null
  const offsets = cycleOffsets(input)
  let index = 0
  for (let i = 0; i < offsets.length; i++) if (selected >= offsets[i]!) index = i
  return { index, local: Math.max(0, selected - offsets[index]!) }
}

/** One box of a cycle as drawn: a member by its index, the folded middle (how many, the first of them), or the members knossos did not list. */
export type Shown = { kind: 'node'; j: number } | { kind: 'fold'; hidden: number; first: number } | { kind: 'unlisted'; count: number }

/**
 * The boxes a cycle shows in a card `inner` wide: every member, unless the
 * diagram is drawn and the cycle has more than {@link FOLD_AT} allows and
 * was not unfolded: then its first ones, the fold, and its last. A marker on
 * a member the fold would hide keeps the cycle whole.
 */
export function shownOf(cycle: CycleLine, index: number, input: CyclesInput, inner: number, tier: Tier, local = -1): Shown[] {
  const all: Shown[] = cycle.nodes.map((_, j) => ({ kind: 'node', j }))
  const unlisted: Shown[] = cycle.more > 0 ? [{ kind: 'unlisted', count: cycle.more }] : []
  const cap = FOLD_AT[tier]
  const n = cycle.nodes.length
  if (inner < DIAGRAM_MIN || n <= cap || input.unfolded.includes(index)) return [...all, ...unlisted]
  const head = cap - 2
  if (local >= head && local < n - 1) return [...all, ...unlisted]
  return [...all.slice(0, head), { kind: 'fold', hidden: n - 1 - head, first: head }, all[n - 1]!, ...unlisted]
}

/** The list entry each walkable box stands for, in the order the eye reads them, cycle after cycle. */
export function cycleWalk(input: CyclesInput, inner: number, tier: Tier, selected: number): number[] {
  const offsets = cycleOffsets(input)
  const marked = markedCycle(input, selected)
  return input.cycles.flatMap((cycle, i) =>
    shownOf(cycle, i, input, inner, tier, marked?.index === i ? marked.local : -1).flatMap(s => (s.kind === 'node' ? [offsets[i]! + s.j] : s.kind === 'fold' ? [offsets[i]! + cycle.nodes.length] : [])),
  )
}

/** Where `j` and `k` move the marker: the next and the previous box in {@link cycleWalk}, kept inside it. */
export function cycleSteps(input: CyclesInput, inner: number, tier: Tier, selected: number): { next: number; prev: number } | null {
  const walk = cycleWalk(input, inner, tier, selected)
  if (walk.length === 0) return null
  const at = walk.indexOf(selected)
  const here = at < 0 ? 0 : at
  return { next: walk[Math.min(walk.length - 1, here + 1)]!, prev: walk[Math.max(0, here - 1)]! }
}

/** The press that unfolds the marked cycle, when the marker rests on its fold: `unfold:<cycle>:<first hidden entry>`. */
export function unfoldPress(input: CyclesInput, inner: number, tier: Tier, selected: number): string | null {
  const marked = markedCycle(input, selected)
  if (marked === null) return null
  const cycle = input.cycles[marked.index]!
  const fold = shownOf(cycle, marked.index, input, inner, tier, marked.local).find(s => s.kind === 'fold')
  if (fold === undefined || fold.kind !== 'fold' || marked.local !== cycle.nodes.length) return null
  return `unfold:${marked.index}:${cycleOffsets(input)[marked.index]! + fold.first}`
}

/** The boundary most of a cycle's members are in (the first such, on a tie), or null when none has one. */
export function homeBoundary(cycle: CycleLine): string | null {
  const counts = new Map<string, number>()
  for (const node of cycle.nodes) if (node.boundary !== null) counts.set(node.boundary, (counts.get(node.boundary) ?? 0) + 1)
  let home: string | null = null
  for (const [name, n] of counts) if (home === null || n > counts.get(home)!) home = name
  return home
}

/** The boundaries a cycle's members are in, in the order they first appear. */
const boundariesOf = (cycle: CycleLine): string[] => [...new Set(cycle.nodes.flatMap(n => (n.boundary === null ? [] : [n.boundary])))]

/** The fewest cycles the list shows, however short the pane. */
const CYCLES_MIN = 2

/**
 * The Cycles tab: the marked cycle drawn (or, narrow, spelled out), then
 * every cycle as a line naming its size and the boundary most of its
 * members are in; the marked one tinted. One column at every width: a
 * diagram wants the room.
 */
export function cyclesArrangement(input: CyclesInput, tier: Tier, hues: Hues = NO_HUES, selected = -1): Arrangement {
  const shown = input.cycles.length
  const marked = selected < 0 ? null : markedCycle(input, selected)
  const offsets = cycleOffsets(input)
  const list: Block = {
    key: 'cycles',
    grow: { length: shown, min: CYCLES_MIN },
    make: (columns, limit) => {
      const note = shown === 0 ? 'none' : `${input.count}${String(shown) === input.count ? '' : ` · ${shown} shown`} · largest first`
      const section = (body: Row[]): Section => ({ key: 'cycles', title: shown > 1 ? 'All cycles' : 'Cycles', note: noteOf(note), body })
      if (shown === 0) return section([{ key: 'cycles-none', segments: [{ text: '   No dependency cycles.', dim: true }] }])
      const window = windowOf(shown, limit, marked?.index ?? -1)
      const rows = input.cycles.slice(window.start, window.end).map((cycle, n) => cycleLine(cycle, window.start + n, offsets[window.start + n]!, marked?.index === window.start + n, columns, hues))
      return section([...rows, ...moreRows('cycles-window', window, shown, columns)])
    },
  }
  if (marked === null || input.cycles[marked.index] === undefined) return { left: [list] }
  const index = marked.index
  const drawn: Block = { key: 'cycle-diagram', make: columns => cycleSection(input, index, offsets[index]!, marked.local, columns, tier, hues) }
  return { left: [drawn, list] }
}

/** A cycle's line in the list: the marker, its name (a press that marks its first member), its size and its boundary. */
function cycleLine(cycle: CycleLine, i: number, offset: number, on: boolean, columns: number, hues: Hues): Row {
  const home = homeBoundary(cycle)
  const head: Segment[] = [{ text: on ? '›' : ' ', color: ACCENT, bold: true }, { text: '  ' }, button(`mark:${offset}`, `cycle ${i + 1}`), { text: ` · ${plural(cycle.size, 'member', 'members')}`, dim: true }]
  if (home !== null) head.push({ text: ' · ', dim: true }, { text: boundaryLabel(home, hues), ...boundaryStyle(home, hues) })
  const others = boundariesOf(cycle).filter(b => b !== home)
  if (others.length > 0) head.push({ text: ` +${others.length} ${others.length === 1 ? 'boundary' : 'boundaries'}`, dim: true })
  return on ? { key: `cycle-${i}`, segments: tinted(clip(head, columns), SELECTED_BG), tint: SELECTED_BG } : { key: `cycle-${i}`, segments: clip(head, columns) }
}

/**
 * The marked cycle as a card: a legend for its boundaries' colours and the
 * crossing mark (when it spans boundaries), then the diagram, or, below
 * {@link DIAGRAM_MIN}, the chain and a member a row.
 */
function cycleSection(input: CyclesInput, index: number, offset: number, local: number, columns: number, tier: Tier, hues: Hues): Section {
  const cycle = input.cycles[index]!
  const spans = boundariesOf(cycle)
  const coloured = spans.length > 1
  const crossings = cycle.nodes.filter((n, j) => crosses(n, cycle.nodes[(j + 1) % cycle.nodes.length])).length
  const note = noteOf([plural(cycle.size, 'member', 'members'), ...(crossings > 0 ? [`crosses ${plural(spans.length, 'boundary', 'boundaries')}`] : [])].join(' · '))
  const legend: Row[] = []
  if (coloured) {
    const groups: Segment[][] = spans.filter(b => boundaryColour(b, hues) !== undefined).map(b => [{ text: `■ ${boundaryLabel(b, hues)}`, color: boundaryColour(b, hues) }])
    if (crossings > 0) groups.push([{ text: CROSSING.across, color: STATUS_COLOURS.warn }, { text: ' crosses a boundary', dim: true }])
    legend.push(...wrapGroups('cycle-legend', groups, columns, 2), blank('cycle-legend-gap'))
  }
  const section = (body: Row[]): Section => ({ key: 'cycle-diagram', title: `Cycle ${index + 1}`, note, body: [...legend, ...body] })
  if (columns < DIAGRAM_MIN) return section(chainRows(cycle, offset, local, columns, hues, coloured))
  const shown = shownOf(cycle, index, input, columns, tier, local)
  const nodes: DiagramNode[] = shown.map((s): DiagramNode => {
    if (s.kind === 'fold') return { key: 'fold', label: `… ${s.hidden} more …`, dim: true, press: { id: `fold:${index}:${offset + s.first}`, label: `… ${s.hidden} more …` }, selected: local === cycle.nodes.length }
    if (s.kind === 'unlisted') return { key: 'unlisted', label: `+${s.count} not listed`, dim: true }
    const node = cycle.nodes[s.j]!
    const colour = coloured ? boundaryColour(node.boundary, hues) : undefined
    return { key: `m${s.j}`, label: node.name, ...(colour === undefined ? {} : { color: colour }), press: { id: `row:${offset + s.j}`, label: node.name }, selected: s.j === local }
  })
  const hops: Hop[] = shown.map((s, k) => {
    const next = shown[(k + 1) % shown.length]!
    return { crosses: s.kind === 'node' && next.kind === 'node' && crosses(cycle.nodes[s.j]!, cycle.nodes[next.j]) }
  })
  return section(cycleDiagram(nodes, hops, columns, `diagram-${index}`).rows)
}

/** Whether a hop from `a` to `b` leaves one boundary for another. */
const crosses = (a: CycleNode, b: CycleNode | undefined): boolean => b !== undefined && a.boundary !== null && b.boundary !== null && a.boundary !== b.boundary

/** The chain wrapped to the width, then a member a row (the marked one tinted, each a press), closing with the way back. */
function chainRows(cycle: CycleLine, offset: number, local: number, columns: number, hues: Hues, coloured: boolean): Row[] {
  const chain = wrapGroups('chain', chainGroups(cycle, Math.max(1, columns - 4), hues, coloured), columns, 1)
  const name = Math.max(1, Math.min(Math.max(1, ...cycle.nodes.map(n => cells(n.name))), columns - MARK))
  const spec = { name, boundary: 0, bar: 0, numbers: [] }
  const members = cycle.nodes.map((node, j) =>
    tableRow(`member-${j}`, { name: node.name, boundary: node.boundary, values: [], max: 0, press: `row:${offset + j}`, selected: j === local, mark: { text: j === 0 ? '┌' : '│', color: FAINT } }, spec, coloured ? hues : NO_HUES),
  )
  const close: Row = { key: 'member-close', segments: [{ text: ' ' }, { text: cycle.more > 0 ? '┆' : '└', color: FAINT }, { text: cycle.more > 0 ? ` … +${cycle.more} more` : ` ↻ back to ${fit(cycle.nodes[0]?.name ?? '', Math.max(1, columns - 12))}`, dim: true }] }
  return [...chain, blank('chain-gap'), ...members, close]
}

/** A cycle's chain as groups that wrap whole: `↻`, then each member (in its boundary's colour) with the arrow after it, then the loop's close. */
export function chainGroups(cycle: CycleLine, room: number, hues: Hues = NO_HUES, coloured = true): Segment[][] {
  const groups: Segment[][] = [[{ text: '↻', color: ACCENT }]]
  cycle.nodes.forEach((node, j) => {
    const last = j === cycle.nodes.length - 1
    groups.push([{ text: fit(node.name, room), ...(coloured ? boundaryStyle(node.boundary, hues) : {}) }, ...(last && cycle.more === 0 ? [] : [{ text: ' →', dim: true }])])
  })
  groups.push(cycle.more > 0 ? [{ text: `… +${cycle.more} more`, dim: true }] : [{ text: '→ ↻', dim: true }])
  return groups
}
