/**
 * Diagrams drawn with box-drawing glyphs: node boxes with rounded corners,
 * connectors that turn and join, arrowheads, and labels set into an edge.
 * Pure: a layout function takes the nodes and the width (and, where it has
 * one, the room in rows) and returns rows of styled segments, none wider
 * than the width.
 *
 * Everything is drawn on a {@link Canvas} of cells first. A connector is
 * never drawn as a glyph: each cell it passes keeps the directions it opens
 * to (up, down, left, right), and the glyph is read off them when the canvas
 * becomes rows. So two connectors that meet join (`┤`, `┼`, `╰`) without
 * either knowing of the other, and a box's border is a connector too: a line
 * leaving a box's side turns its `│` into `├`. A label written over a cell
 * something else holds, or a line through a label, counts as an overlap;
 * {@link joinErrors} lists every connector end that leads nowhere. The tests
 * hold every diagram to no overlap and no loose end at every width.
 *
 * Three diagrams are laid out here: a dependency cycle as a serpentine of
 * boxes closed by a return edge ({@link cycleDiagram}), two boundaries and
 * the dependencies between them ({@link pairDiagram}, {@link pairColumns}),
 * and a component's neighbourhood: what uses it fanning in from the left and
 * what it uses fanning out to the right, or stacked above and below it when
 * narrow ({@link neighbourhood}).
 *
 * Colours are theme keys: connectors in `subtle`, labels on them in
 * `inactive`, a highlighted path in the accent, a forbidden one in `error`.
 */
import { ACCENT, FAINT, HEADING, SELECTED_BG, STATUS_COLOURS } from './palette'
import { cells, fit, padEnd, spaces } from './rows'
import type { Press, Row, Segment } from './rows'

/** The narrowest a diagram is drawn: below it, a view says the same as text. */
export const DIAGRAM_MIN = 50

/** How a cell is drawn: a segment's style without its text. */
export type Style = Omit<Segment, 'text'>

const UP = 1
const DOWN = 2
const LEFT = 4
const RIGHT = 8

/** The glyph for each set of directions a connector cell opens to. */
const GLYPH: Record<number, string> = {
  [UP]: '│',
  [DOWN]: '│',
  [UP | DOWN]: '│',
  [LEFT]: '─',
  [RIGHT]: '─',
  [LEFT | RIGHT]: '─',
  [DOWN | RIGHT]: '╭',
  [DOWN | LEFT]: '╮',
  [UP | RIGHT]: '╰',
  [UP | LEFT]: '╯',
  [UP | DOWN | RIGHT]: '├',
  [UP | DOWN | LEFT]: '┤',
  [LEFT | RIGHT | DOWN]: '┬',
  [LEFT | RIGHT | UP]: '┴',
  [UP | DOWN | LEFT | RIGHT]: '┼',
}

/** The arrowheads, by the way they point, and the direction each is entered from. */
export const ARROWS = { right: '►', left: '◄', up: '▲', down: '▼' } as const
type Way = keyof typeof ARROWS
const ENTERED: Record<Way, number> = { right: LEFT, left: RIGHT, up: DOWN, down: UP }

/** The mark set across an edge where it crosses from one boundary into another. */
export const CROSSING = { across: '╫', along: '╪' } as const

/**
 * What a cell holds: nothing, part of a connector (its directions in
 * `mask`), an arrowhead, a crossing mark on a connector, a label set into a
 * connector (`edge`), or text (a box's label, a note).
 */
type Kind = 'blank' | 'line' | 'arrow' | 'mark' | 'edge' | 'text'
type CellAt = { ch: string; kind: Kind; mask: number; style: Style; way?: Way }

/** A grid of cells a diagram is drawn on, and how many times one thing was drawn over another. */
export type Canvas = { width: number; height: number; grid: CellAt[][]; overlaps: number }

const blankCell = (): CellAt => ({ ch: ' ', kind: 'blank', mask: 0, style: {} })

export function canvas(width: number, height: number): Canvas {
  return { width, height, grid: Array.from({ length: Math.max(0, height) }, () => Array.from({ length: Math.max(0, width) }, blankCell)), overlaps: 0 }
}

const at = (c: Canvas, x: number, y: number): CellAt | undefined => c.grid[y]?.[x]

/** The more telling of two connector styles where they meet: an alert over the accent over plain. */
function stronger(a: Style, b: Style): Style {
  const rank = (s: Style) => (s.color === STATUS_COLOURS.alert ? 3 : s.color === ACCENT ? 2 : s.color === undefined ? 0 : 1)
  return rank(b) > rank(a) ? b : a
}

/** Opens a connector cell toward `mask`; anything but a connector or blank there is an overlap (a cell off the canvas too). */
function open(c: Canvas, x: number, y: number, mask: number, style: Style): void {
  const cell = at(c, x, y)
  if (cell === undefined) {
    c.overlaps++
    return
  }
  if (cell.kind === 'blank') {
    c.grid[y]![x] = { ch: '', kind: 'line', mask, style }
    return
  }
  if (cell.kind === 'line') {
    cell.mask |= mask
    cell.style = stronger(cell.style, style)
    return
  }
  // A crossing mark or an edge label keeps its glyph: the line runs through it.
  if ((cell.kind === 'mark' || cell.kind === 'edge') && (mask & (LEFT | RIGHT | UP | DOWN)) !== 0) {
    cell.mask |= mask
    return
  }
  c.overlaps++
}

/** Puts `cell` at a blank cell; anything already there is an overlap. */
function put(c: Canvas, x: number, y: number, cell: CellAt): void {
  const here = at(c, x, y)
  if (here === undefined || here.kind !== 'blank') {
    c.overlaps++
    return
  }
  c.grid[y]![x] = cell
}

/**
 * A connector through `points` (each turn a point), each leg straight along
 * a row or a column. With `arrow`, its last point is an arrowhead pointing the
 * way the last leg runs.
 */
export function line(c: Canvas, points: [number, number][], style: Style = { color: FAINT }, arrow = false): void {
  for (let i = 0; i + 1 < points.length; i++) {
    const [x1, y1] = points[i]!
    const [x2, y2] = points[i + 1]!
    const last = i + 2 === points.length
    if (y1 === y2) {
      const step = x2 > x1 ? 1 : -1
      const forward = step > 0 ? RIGHT : LEFT
      const back = step > 0 ? LEFT : RIGHT
      for (let x = x1; x !== x2; x += step) {
        open(c, x, y1, forward, style)
        if (!(arrow && last && x + step === x2)) open(c, x + step, y1, back, style)
      }
      if (arrow && last) arrowAt(c, x2, y2, step > 0 ? 'right' : 'left', style)
    } else {
      const step = y2 > y1 ? 1 : -1
      const forward = step > 0 ? DOWN : UP
      const back = step > 0 ? UP : DOWN
      for (let y = y1; y !== y2; y += step) {
        open(c, x1, y, forward, style)
        if (!(arrow && last && y + step === y2)) open(c, x1, y + step, back, style)
      }
      if (arrow && last) arrowAt(c, x2, y2, step > 0 ? 'down' : 'up', style)
    }
  }
}

function arrowAt(c: Canvas, x: number, y: number, way: Way, style: Style): void {
  put(c, x, y, { ch: ARROWS[way], kind: 'arrow', mask: ENTERED[way], style, way })
}

/** A crossing mark on a connector cell not yet drawn: `across` a horizontal one, `along` a vertical one. */
export function mark(c: Canvas, x: number, y: number, horizontal: boolean): void {
  put(c, x, y, { ch: horizontal ? CROSSING.across : CROSSING.along, kind: 'mark', mask: 0, style: { color: STATUS_COLOURS.warn } })
}

/** A label set into a horizontal connector from `x`, a space either side, before the line is drawn through it. */
export function edgeLabel(c: Canvas, x: number, y: number, text: string, style: Style = { dim: true }): void {
  if (text === '') return
  ;[...` ${text} `].forEach((ch, i) => put(c, x + i, y, { ch, kind: 'edge', mask: 0, style }))
}

/** Text at `x`, `y`, cell by cell, one style per segment. */
export function write(c: Canvas, x: number, y: number, segments: Segment[]): void {
  let i = 0
  for (const s of segments) {
    const { text, ...style } = s
    for (const ch of text) put(c, x + i++, y, { ch, kind: 'text', mask: 0, style })
  }
}

/** A node as a box draws it: its label (styled), what pressing it does, and how it stands out. */
export type DiagramNode = {
  key: string
  label: string
  /** The node's colour (its boundary's): its frame's, and its label's when the label is not pressable. */
  color?: string
  dim?: boolean
  press?: Press
  /** The marked node: its frame in the accent, its inside tinted, its label bold. */
  selected?: boolean
  /** The node the diagram is about: its frame and label at full contrast. */
  centre?: boolean
}

/**
 * The frame's style for a node: the accent when marked, the text colour for
 * the centre, else the node's own colour (its boundary's) or the faint ink.
 * The frame carries the colour because a pressable label cannot: the
 * engine draws a Button's label in its own colour.
 */
const frameOf = (node: DiagramNode): Style => (node.selected ? { color: ACCENT } : node.centre ? { color: HEADING } : { color: node.color ?? FAINT })

/** How wide a box is for a label `width` cells wide: a border and a space either side. */
export const boxWidth = (labelWidth: number): number => labelWidth + 4

/** A box three rows tall at `x`, `y`, `width` wide, its label cut to fit, a press on the label when the node has one. */
export function box(c: Canvas, x: number, y: number, width: number, node: DiagramNode): void {
  const frame = frameOf(node)
  line(c, [[x, y], [x + width - 1, y], [x + width - 1, y + 2], [x, y + 2], [x, y]], frame)
  const room = Math.max(1, width - 4)
  const text = fit(node.label, room)
  const ground: Style = node.selected ? { bg: SELECTED_BG } : {}
  const label: Segment = {
    text,
    ...ground,
    ...(node.color !== undefined && node.press === undefined ? { color: node.color } : node.dim ? { dim: true } : node.centre ? { color: HEADING } : {}),
    ...(node.selected || node.centre ? { bold: true } : {}),
    ...(node.press === undefined ? {} : { press: node.press }),
  }
  write(c, x + 1, y + 1, [{ text: ' ', ...ground }, label, { text: spaces(width - 3 - cells(text)), ...ground }])
}

/**
 * The canvas as rows: runs of cells in one style become one segment (a
 * pressable label stays one segment of its own), trailing blank cells are
 * dropped.
 */
export function toRows(c: Canvas, key: string): Row[] {
  return c.grid.map((cellsOf, y) => {
    let end = cellsOf.length
    while (end > 0 && cellsOf[end - 1]!.kind === 'blank') end--
    const segments: Segment[] = []
    for (let x = 0; x < end; x++) {
      const cell = cellsOf[x]!
      const ch = cell.kind === 'line' ? (GLYPH[cell.mask] ?? ' ') : cell.ch
      const prev = segments[segments.length - 1]
      if (prev !== undefined && sameStyle(prev, cell.style) && cell.style.press === undefined && prev.press === undefined) prev.text += ch
      else if (prev !== undefined && cell.style.press !== undefined && prev.press?.id === cell.style.press.id) prev.text += ch
      else segments.push({ ...cell.style, text: ch })
    }
    return { key: `${key}-${y}`, segments: segments.length === 0 ? [{ text: ' ' }] : segments }
  })
}

const sameStyle = (s: Style, t: Style): boolean => s.color === t.color && s.dim === t.dim && s.bold === t.bold && s.bg === t.bg

/**
 * Every connector end on `c` that leads nowhere, as `x,y`: a side a
 * connector opens to whose neighbour does not open back (or is not an
 * arrowhead entered from there, or a label or mark set into the line), and
 * every arrowhead not entered by a connector or not pointing at a box.
 */
export function joinErrors(c: Canvas): string[] {
  const errors: string[] = []
  const opensTo = (x: number, y: number, dir: number): boolean => {
    const cell = at(c, x, y)
    if (cell === undefined) return false
    if (cell.kind === 'line' || cell.kind === 'mark' || cell.kind === 'edge') return (cell.mask & dir) !== 0 || ((cell.kind === 'edge' || cell.kind === 'mark') && (dir === LEFT || dir === RIGHT || dir === UP || dir === DOWN) && throughs(cell, dir))
    if (cell.kind === 'arrow') return cell.mask === dir
    return false
  }
  const step: Record<number, [number, number, number]> = { [UP]: [0, -1, DOWN], [DOWN]: [0, 1, UP], [LEFT]: [-1, 0, RIGHT], [RIGHT]: [1, 0, LEFT] }
  c.grid.forEach((row, y) =>
    row.forEach((cell, x) => {
      if (cell.kind === 'line' || cell.kind === 'mark' || cell.kind === 'edge') {
        for (const dir of [UP, DOWN, LEFT, RIGHT]) {
          if ((cell.mask & dir) === 0) continue
          const [dx, dy, back] = step[dir]!
          if (!opensTo(x + dx, y + dy, back)) errors.push(`${x},${y}`)
        }
      }
      if (cell.kind === 'arrow' && cell.way !== undefined) {
        const [dx, dy, back] = step[cell.mask]!
        if (!opensTo(x + dx, y + dy, back)) errors.push(`${x},${y}`)
        // The head points at a box's border: a connector cell running across its way.
        const [px, py] = { right: [1, 0], left: [-1, 0], up: [0, -1], down: [0, 1] }[cell.way] as [number, number]
        const target = at(c, x + px, y + py)
        if (target === undefined || target.kind !== 'line') errors.push(`${x},${y}`)
      }
    }),
  )
  return errors
}

/** Whether a label or mark set into a line passes the line on toward `dir`: it does where the line was drawn through it. */
const throughs = (cell: CellAt, dir: number): boolean => (cell.mask & dir) !== 0

// ---------------------------------------------------------------- the cycle

/** The left margin the return edge runs down: its corner, a line, the arrowhead. */
const CHANNEL = 3
/** The fewest and most cells between two boxes on a row of the serpentine. */
const GAP_MIN = 5
const GAP_MAX = 10
/** Rows between two rows of boxes: the line, then the arrowhead. */
const BETWEEN = 2
/** The widest a node's label grows. */
export const LABEL_MAX = 34

/** One hop of the cycle, from node `i` to the next: whether it crosses a boundary, and whether it is on the marked node. */
export type Hop = { crosses: boolean }

/** The cycle laid out: its rows, and how many boxes stand on a row. */
export type CycleLayout = { rows: Row[]; perRow: number; canvas: Canvas }

/**
 * A cycle as a serpentine: its nodes in boxes of one width, as many to a
 * row as fit, the first row left to right, the next right to left under it,
 * and so on, an arrow from each node to the next (down at the end of a row).
 * A return edge leaves the last node's bottom, runs along a lane under the
 * last row and up the left margin, and enters the first node from the left:
 * the loop closes where it began. A hop that crosses a boundary carries a
 * mark across it; the hops into and out of the marked node are in the accent.
 */
export function cycleDiagram(nodes: DiagramNode[], hops: Hop[], width: number, key = 'cycle'): CycleLayout {
  const n = nodes.length
  const labelWidth = Math.max(1, Math.min(LABEL_MAX, Math.max(...nodes.map(node => cells(node.label))), width - CHANNEL - 4))
  const w = boxWidth(labelWidth)
  const fits = Math.max(1, Math.floor((width - CHANNEL + GAP_MIN) / (w + GAP_MIN)))
  // Rows as even as they can be: seven over three to a row is 3, 3, 1 rather than 3, 2, 2 — the serpentine reads by its rows.
  const rowCount = Math.ceil(n / fits)
  const perRow = Math.max(1, Math.ceil(n / rowCount))
  const gap = perRow > 1 ? Math.max(GAP_MIN, Math.min(GAP_MAX, Math.floor((width - CHANNEL - perRow * w) / (perRow - 1)))) : GAP_MIN
  const place = (i: number): { x: number; y: number } => {
    const r = Math.floor(i / perRow)
    const j = i % perRow
    const col = r % 2 === 0 ? j : perRow - 1 - j
    return { x: CHANNEL + col * (w + gap), y: r * (3 + BETWEEN) }
  }
  const last = place(n - 1)
  const c = canvas(width, last.y + 4)
  const style = (i: number): Style => (nodes[i]?.selected || nodes[(i + 1) % n]?.selected ? { color: ACCENT } : { color: FAINT })
  nodes.forEach((node, i) => box(c, place(i).x, place(i).y, w, node))
  for (let i = 0; i + 1 < n; i++) {
    const a = place(i)
    const b = place(i + 1)
    if (a.y === b.y) {
      const right = b.x > a.x
      const from = right ? a.x + w - 1 : a.x
      const to = right ? b.x - 1 : b.x + w
      if (hops[i]?.crosses) mark(c, Math.floor((from + to) / 2), a.y + 1, true)
      line(c, [[from, a.y + 1], [to, a.y + 1]], style(i), true)
    } else {
      const x = a.x + Math.floor(w / 2)
      if (hops[i]?.crosses) mark(c, x, a.y + 3, false)
      line(c, [[x, a.y + 2], [x, b.y - 1]], style(i), true)
    }
  }
  // The return edge, under the last row and up the margin; a label on the lane where there is room.
  const lane = last.y + 3
  const down = last.x + Math.floor(w / 2)
  const back = style(n - 1)
  const said = ' back to the start '
  if (hops[n - 1]?.crosses) mark(c, Math.max(1, down - 2), lane, true)
  if (down - 2 >= cells(said) + 4) edgeLabel(c, Math.floor((down - cells(said)) / 2), lane, said.trim(), { dim: true })
  line(c, [[down, last.y + 2], [down, lane], [0, lane], [0, 1], [CHANNEL - 1, 1]], back, true)
  return { rows: toRows(c, key), perRow, canvas: c }
}

// ---------------------------------------------------------------- two boundaries

/** The fewest cells the edge between two boundary boxes takes besides its label. */
const PAIR_EDGE = 6

/**
 * Two boundaries and the dependencies running from one to the other: a box
 * for each, an arrow between them carrying `label` (how many). Forbidden by a
 * policy, the arrow and its label are in `error`, marked `✕`.
 */
export function pairDiagram(from: DiagramNode, to: DiagramNode, label: string, width: number, forbidden = false, key = 'pair'): { rows: Row[]; canvas: Canvas } {
  const said = forbidden ? `✕ ${label}` : label
  const edge = cells(said) + 2 + PAIR_EDGE
  const room = Math.max(2, Math.floor((width - edge - 1) / 2) - 4)
  const lw = boxWidth(Math.min(room, LABEL_MAX, cells(from.label)))
  const rw = boxWidth(Math.min(room, LABEL_MAX, cells(to.label)))
  const span = Math.max(edge, Math.min(edge + 12, width - lw - rw - 1))
  const c = canvas(width, 3)
  const style: Style = forbidden ? { color: STATUS_COLOURS.alert } : { color: FAINT }
  box(c, 0, 0, lw, from)
  box(c, lw + span, 0, rw, to)
  const fits = cells(said) + 2 <= span - 3
  if (fits) edgeLabel(c, lw + Math.floor((span - cells(said) - 2) / 2), 1, said, forbidden ? { color: STATUS_COLOURS.alert, bold: true } : { color: HEADING })
  line(c, [[lw - 1, 1], [lw + span - 1, 1]], style, true)
  return { rows: toRows(c, key), canvas: c }
}

/** One coupling of two components: its source, its target, how many dependencies run that way. */
export type Coupling = { source: string; target: string; edges: string }

/**
 * Couplings as small pairs in columns: every source padded to the widest,
 * an arrow, every target, the counts right-aligned at the end. Names are cut
 * only as far as the width needs, the source and target sharing the cut.
 */
export function pairColumns(items: Coupling[], width: number, sourceStyle: Style, targetStyle: Style, indent = 3, key = 'pairs'): Row[] {
  const arrow = ' ──► '
  const count = Math.max(1, ...items.map(i => cells(i.edges)))
  const room = Math.max(4, width - indent - cells(arrow) - 2 - count)
  let sw = Math.max(1, ...items.map(i => cells(i.source)))
  let tw = Math.max(1, ...items.map(i => cells(i.target)))
  if (sw + tw > room) {
    const half = Math.floor(room / 2)
    if (sw <= half) tw = room - sw
    else if (tw <= half) sw = room - tw
    else [sw, tw] = [half, room - half]
  }
  return items.map((item, i) => {
    const target = fit(item.target, tw)
    return {
      key: `${key}-${i}`,
      segments: [
        { text: spaces(indent) },
        { text: padEnd(fit(item.source, sw), sw), ...sourceStyle },
        { text: arrow, color: FAINT },
        { text: target, ...targetStyle },
        { text: spaces(tw - cells(target) + 2 + count - cells(item.edges)) },
        { text: item.edges, color: HEADING },
      ],
    }
  })
}

// ---------------------------------------------------------------- a neighbourhood

/** A neighbour: its node, and the label on its edge (how many dependencies). */
export type Neighbour = { node: DiagramNode; edge: string }

/** The neighbourhood laid out: its rows, and whether it stands stacked (narrow) or side by side. */
export type NeighbourhoodLayout = { rows: Row[]; stacked: boolean; canvas: Canvas }

/** The rows between two neighbours of one side. */
const NEIGHBOUR_GAP = 1
/** The widest a neighbour's label grows. */
const NEIGHBOUR_MAX = 40

/** What a side says when it has no one: drawn where its boxes would stand. */
export type Empty = { usedBy: string; uses: string }

/**
 * A component in the middle and its neighbours. Side by side (when the
 * width holds three boxes and two edges): what uses it in a column on the
 * left, each joined by a connector carrying its count to a bus that enters
 * the centre with an arrow; what it uses in a column on the right, the bus
 * from the centre branching to each with an arrow and its count. Stacked
 * (narrower): the same, the users above the centre and what it uses below
 * it, on one spine.
 */
export function neighbourhood(centre: DiagramNode, usedBy: Neighbour[], uses: Neighbour[], width: number, empty: Empty, key = 'hood'): NeighbourhoodLayout {
  const all = [...usedBy, ...uses]
  const edgeWidth = Math.max(1, ...all.map(nb => cells(nb.edge))) + 4
  const longest = Math.max(1, ...all.map(nb => cells(nb.node.label)), cells(empty.usedBy), cells(empty.uses))
  const centreWidth = boxWidth(Math.min(LABEL_MAX, cells(centre.label)))
  // Side by side: two columns of neighbours, two edges and buses, the centre.
  const sideRoom = Math.floor((width - centreWidth - 2 * (edgeWidth + 3) - 1) / 2)
  if (sideRoom >= boxWidth(Math.min(20, longest))) {
    const nw = Math.min(boxWidth(Math.min(NEIGHBOUR_MAX, longest)), sideRoom)
    return { ...sideBySide(centre, usedBy, uses, width, nw, centreWidth, edgeWidth, empty, key), stacked: false }
  }
  return { ...stacked(centre, usedBy, uses, width, edgeWidth, longest, empty, key), stacked: true }
}

/** The rows a column of `count` boxes takes. */
const columnHeight = (count: number): number => (count === 0 ? 3 : count * 3 + (count - 1) * NEIGHBOUR_GAP)

function sideBySide(centre: DiagramNode, usedBy: Neighbour[], uses: Neighbour[], width: number, nw: number, cw: number, ew: number, empty: Empty, key: string): { rows: Row[]; canvas: Canvas } {
  const height = Math.max(columnHeight(usedBy.length), columnHeight(uses.length), 3)
  // Spare width goes to the edges, evenly, so the centre stands in the middle.
  const spare = Math.max(0, width - 2 * nw - cw - 2 * (ew + 3))
  const ewL = ew + Math.min(10, Math.floor(spare / 2))
  const busL = nw + ewL
  const cx = busL + 3
  const busR = cx + cw + 1
  const rx = busR + ewL + 1
  const c = canvas(rx + nw, height)
  const top = (count: number) => Math.floor((height - columnHeight(count)) / 2)
  const cy = Math.floor((height - 3) / 2)
  const mid = cy + 1
  box(c, cx, cy, cw, { ...centre, centre: true })
  const styleOf = (nb: Neighbour): Style => (nb.node.selected ? { color: ACCENT } : { color: FAINT })
  const lit = (list: Neighbour[]): Style => (list.some(nb => nb.node.selected) ? { color: ACCENT } : { color: FAINT })
  if (usedBy.length === 0) write(c, Math.max(0, busL - 1 - cells(empty.usedBy)), mid, [{ text: empty.usedBy, dim: true }])
  else {
    const ys = usedBy.map((_, i) => top(usedBy.length) + i * (3 + NEIGHBOUR_GAP))
    usedBy.forEach((nb, i) => {
      const y = ys[i]!
      box(c, 0, y, nw, nb.node)
      edgeLabel(c, nw + Math.floor((ewL - cells(nb.edge) - 2) / 2), y + 1, nb.edge, nb.node.selected ? { color: ACCENT } : { dim: true })
      line(c, [[nw - 1, y + 1], [busL, y + 1]], styleOf(nb))
    })
    const span = [...ys.map(y => y + 1), mid]
    line(c, [[busL, Math.min(...span)], [busL, Math.max(...span)]], { color: FAINT })
    line(c, [[busL, mid], [cx - 1, mid]], lit(usedBy), true)
  }
  if (uses.length === 0) write(c, busR + 2, mid, [{ text: empty.uses, dim: true }])
  else {
    const ys = uses.map((_, i) => top(uses.length) + i * (3 + NEIGHBOUR_GAP))
    line(c, [[cx + cw - 1, mid], [busR, mid]], lit(uses))
    const span = [...ys.map(y => y + 1), mid]
    line(c, [[busR, Math.min(...span)], [busR, Math.max(...span)]], { color: FAINT })
    uses.forEach((nb, i) => {
      const y = ys[i]!
      box(c, rx, y, nw, nb.node)
      edgeLabel(c, busR + 1 + Math.floor((ewL - 1 - cells(nb.edge) - 2) / 2), y + 1, nb.edge, nb.node.selected ? { color: ACCENT } : { dim: true })
      line(c, [[busR, y + 1], [rx - 1, y + 1]], styleOf(nb), true)
    })
  }
  // The drawing stands in the middle of the width it has: the centre box is what the eye looks for.
  const indent = Math.max(0, Math.floor((width - c.width) / 2))
  return { rows: toRows(c, key).map(row => (indent === 0 ? row : { ...row, segments: [{ text: spaces(indent) }, ...row.segments] })), canvas: c }
}

function stacked(centre: DiagramNode, usedBy: Neighbour[], uses: Neighbour[], width: number, ew: number, longest: number, empty: Empty, key: string): { rows: Row[]; canvas: Canvas } {
  // Two columns of boxes either side of one spine: the users left of it, what it uses right of it.
  const nw = Math.max(5, Math.min(boxWidth(Math.min(NEIGHBOUR_MAX, longest)), Math.floor((width - 2 * ew - 1) / 2)))
  const spine = nw + ew
  const rx = spine + ew
  const cw = Math.min(width, boxWidth(Math.min(LABEL_MAX, cells(centre.label))))
  const cx = Math.max(0, Math.min(width - cw, spine - Math.floor(cw / 2)))
  const upper = usedBy.length === 0 ? 1 : columnHeight(usedBy.length) + 1
  const cy = upper + 1
  const lower = uses.length === 0 ? 2 : 1 + columnHeight(uses.length)
  const height = cy + 3 + lower
  const c = canvas(Math.max(1, width), height)
  const spineAt = Math.min(Math.max(cx + 1, spine), cx + cw - 2)
  const styleOf = (nb: Neighbour): Style => (nb.node.selected ? { color: ACCENT } : { color: FAINT })
  box(c, cx, cy, cw, { ...centre, centre: true })
  if (usedBy.length === 0) write(c, Math.max(0, spineAt - Math.floor(cells(empty.usedBy) / 2)), 0, [{ text: fit(empty.usedBy, width), dim: true }])
  else {
    usedBy.forEach((nb, i) => {
      const y = i * (3 + NEIGHBOUR_GAP)
      box(c, 0, y, nw, nb.node)
      edgeLabel(c, nw + Math.floor((spineAt - nw - cells(nb.edge) - 2) / 2), y + 1, nb.edge, nb.node.selected ? { color: ACCENT } : { dim: true })
      line(c, [[nw - 1, y + 1], [spineAt, y + 1]], styleOf(nb))
    })
    line(c, [[spineAt, 1], [spineAt, cy - 1]], usedBy.some(nb => nb.node.selected) ? { color: ACCENT } : { color: FAINT }, true)
  }
  if (uses.length === 0) write(c, Math.max(0, spineAt - Math.floor(cells(empty.uses) / 2)), cy + 4, [{ text: fit(empty.uses, width), dim: true }])
  else {
    const ys = uses.map((_, i) => cy + 4 + i * (3 + NEIGHBOUR_GAP))
    line(c, [[spineAt, cy + 2], [spineAt, ys[ys.length - 1]! + 1]], uses.some(nb => nb.node.selected) ? { color: ACCENT } : { color: FAINT })
    const ux = Math.max(rx, spineAt + ew)
    const uw = Math.max(5, Math.min(nw, width - ux))
    uses.forEach((nb, i) => {
      const y = ys[i]!
      box(c, ux, y, uw, nb.node)
      edgeLabel(c, spineAt + 1 + Math.floor((ux - spineAt - 2 - cells(nb.edge) - 2) / 2), y + 1, nb.edge, nb.node.selected ? { color: ACCENT } : { dim: true })
      line(c, [[spineAt, y + 1], [ux - 1, y + 1]], styleOf(nb), true)
    })
  }
  return { rows: toRows(c, key), canvas: c }
}
