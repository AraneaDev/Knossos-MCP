/**
 * The pane's Boundaries tab: a heat map of how much each boundary depends on
 * each other one, then each boundary's components and its dependencies in
 * and out.
 *
 * Pure, like the rest of the layout. The heat map is one hue: the accent, in
 * four steps from few dependencies to many, never a colour per boundary, so
 * the eye reads quantity and nothing else. Every surface can draw it as
 * glyphs (a shade from `░` to `█` in the accent, a faint `·` for none); its
 * rows carry `raster: 'heat'` and each cell a background step, so the
 * terminal draws the same grid as one `Raster` of solid cells instead. The
 * one other colour is `error`: a pair a declared policy forbids.
 *
 * Axes are abbreviated to one letter each (A, B, C, ...); the row labels and
 * the table below the map spell each letter out, so they are the legend.
 *
 * The map grows with the room it has: row labels are written out in full
 * once they fit, and when the pane has width and rows to spare each cell
 * scales up as a square, two glyphs wide for every row tall.
 */
import type { Dashboard } from '../../types'
import { ACCENT, boundaryLabel, FAINT, huesOf, NO_HUES, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { HEAT_KEYS } from './raster'
import { boundaryStyle, cells, dimRow, fit, grouped, numberWidth, padEnd, spaces, tableHead, tableRow, tableSpec, wrapGroups } from './rows'
import type { Row, Segment, TableSpec, Tier } from './rows'
import { moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import type { Openable } from './views'

export type BoundaryLine = {
  /** The axis letter. */
  code: string
  name: string
  label: string
  members: number
  /** Dependencies from other boundaries into this one, and from this one out to others. */
  in: number
  out: number
}

export type BoundariesInput = {
  boundaries: BoundaryLine[]
  /** `cells[from][to]`, edges from a component of one boundary to a component of another. */
  cells: number[][]
  /** `forbidden[from][to]`: a declared policy forbids the pair. */
  forbidden: boolean[][]
  edges: string
  /** More boundaries label components than the axes hold. */
  more: boolean
}

/** Shades for a cell's share of the busiest cell, fewest first. */
export const SHADES = ['░', '▒', '▓', '█'] as const
/** The widest a row label grows (letter, space, name) while it is cut: past it the cells get the room. */
const LABEL_MAX = 20
/** The widest a row label is written out in full, when the map has the room. */
const LABEL_FULL = 40
/** The most rows one heat cell is tall: past it the map only stretches. */
export const HEAT_SCALE_MAX = 3
/** The narrowest a row label shrinks to before the cells narrow instead. */
const LABEL_MIN = 6
const INDENT = 3
/** The corner above the row labels: rows depend on columns. */
const CORNER = 'from→to'

/** The axis letter of the boundary at `index`: A to Z, then a1, b1, ... (the axes hold twelve). */
export const axisCode = (index: number): string =>
  index < 26 ? String.fromCharCode(65 + index) : `${String.fromCharCode(97 + (index % 26))}${Math.floor(index / 26)}`

/** The Boundaries tab's view model, or null when the knossos that answered sends no matrix. */
export function boundariesInput(d: Dashboard): BoundariesInput | null {
  const m = d.boundary_matrix
  if (m === undefined) return null
  const hues = huesOf(d)
  const n = m.boundaries.length
  const forbidden = m.boundaries.map(() => m.boundaries.map(() => false))
  for (const [from, to] of m.forbidden) {
    if (forbidden[from] !== undefined && to < n) forbidden[from][to] = true
  }
  const at = (from: number, to: number) => m.cells[from]?.[to] ?? 0
  return {
    boundaries: m.boundaries.map((name, i) => ({
      code: axisCode(i),
      name,
      label: boundaryLabel(name, hues),
      members: m.members[i] ?? 0,
      in: m.boundaries.reduce((sum, _, from) => sum + (from === i ? 0 : at(from, i)), 0),
      out: m.boundaries.reduce((sum, _, to) => sum + (to === i ? 0 : at(i, to)), 0),
    })),
    cells: m.boundaries.map((_, from) => m.boundaries.map((__, to) => at(from, to))),
    forbidden,
    edges: `${grouped(m.edges)}${m.truncated ? '+' : ''}`,
    more: m.boundaries_truncated,
  }
}

/**
 * A cell's shade, 1 (fewest) to 4 (most), on a log scale of the busiest
 * cell, since a boundary's dependencies on itself outnumber the rest by
 * orders of magnitude; 0 for none.
 */
export function shade(value: number, max: number): number {
  if (value <= 0 || max <= 0) return 0
  return Math.min(SHADES.length, Math.max(1, Math.ceil((Math.log1p(value) / Math.log1p(max)) * SHADES.length)))
}

/**
 * How wide the row labels and each cell are at `columns`, and how many rows
 * each cell is tall. A cell is two glyphs and a one-cell gap, about square on
 * a terminal; at `scale` 2 or 3, while whole labels still fit, it is that
 * many rows tall and twice that many glyphs wide, so it stays square. Labels
 * are written out in full when they fit beside cells of one row; else they
 * stop at {@link LABEL_MAX}, then give way to {@link LABEL_MIN} and the cells
 * narrow to 2, then 1 (no gap at width 1).
 */
export function heatSpec(input: BoundariesInput, columns: number, scale = 1): { label: number; cell: number; rows: number } {
  const n = Math.max(1, input.boundaries.length)
  const full = Math.min(LABEL_FULL, Math.max(cells(CORNER), ...input.boundaries.map(b => cells(`${b.code} ${b.label}`))))
  const need = Math.min(LABEL_MAX, full)
  const room = (cell: number) => columns - INDENT - 1 - n * cell
  for (let rows = Math.min(HEAT_SCALE_MAX, Math.max(1, scale)); rows >= 1; rows--) {
    if (room(rows * 2 + 1) >= full) return { label: full, cell: rows * 2 + 1, rows }
  }
  // Two cells and a gap: about square on a terminal's grid.
  if (room(3) >= need) return { label: need, cell: 3, rows: 1 }
  for (const cell of [3, 2, 1]) {
    if (room(cell) >= Math.min(LABEL_MIN, need)) return { label: Math.min(need, room(cell)), cell, rows: 1 }
  }
  return { label: Math.max(1, room(1)), cell: 1, rows: 1 }
}

/** `mark` in the middle of `width` cells. */
const centred = (mark: string, width: number): string => padEnd(spaces(Math.floor((width - cells(mark)) / 2)) + mark, width)

/** How a heat cell fills its grid cell on the terminal: seven eighths high, so stacked cells keep a hairline apart. */
const TILE = '▇'


/**
 * One row of one heat cell: its glyphs and the gap after it. On the
 * terminal's grid the cell is a solid tile in its step of the accent (or
 * `error` for a forbidden pair that is crossed); a cell several rows tall is
 * filled as background in every row but its first, so its rows join into
 * one square with no seam. An empty cell's mark sits on its middle row.
 */
function heatCell(value: number, max: number, forbidden: boolean, width: number, row = 0, rows = 1): Segment[] {
  const glyphs = width > 1 ? width - 1 : 1
  const gap: Segment[] = width > 1 ? [{ text: ' ' }] : []
  const level = shade(value, max)
  if (level === 0) {
    if (row !== Math.floor((rows - 1) / 2)) return [{ text: spaces(glyphs) }, ...gap]
    const mark = forbidden ? { text: centred('×', glyphs), color: STATUS_COLOURS.alert } : { text: centred('·', glyphs), color: FAINT }
    return [mark, ...gap]
  }
  const glyph = SHADES[level - 1]!.repeat(glyphs)
  const colour = forbidden ? STATUS_COLOURS.alert : HEAT_KEYS[level - 1]!
  // The tile's top row is seven eighths high from the bottom: a hairline above it parts it from the cell over it; the rows under it are filled.
  const cell = row === 0 ? { glyph: TILE, fg: colour } : { glyph: ' ', bg: colour }
  // A forbidden pair that is crossed is a violation: the error colour, however many.
  if (forbidden) return [{ text: glyph, color: STATUS_COLOURS.alert, bold: true, cell }, ...gap]
  return [{ text: glyph, color: ACCENT, cell }, ...gap]
}

/**
 * The heat map: a header of axis letters, then each boundary's cells, each a
 * `raster: 'heat'` row (`scale` rows per boundary when the room allows; its
 * label on the middle one). A boundary's letter carries its colour, so the
 * map ties to the other tabs; its name and the cells stay out of it.
 */
export function heatRows(input: BoundariesInput, columns: number, hues: Hues = NO_HUES, scale = 1): Row[] {
  const spec = heatSpec(input, columns, scale)
  const max = Math.max(0, ...input.cells.flat())
  const head: Segment[] = [{ text: spaces(INDENT) + padEnd(fit(CORNER, spec.label), spec.label) + ' ', dim: true }]
  input.boundaries.forEach(b => head.push({ text: centred(b.code, spec.cell > 1 ? spec.cell - 1 : 1) + (spec.cell > 1 ? ' ' : ''), ...boundaryStyle(b.name, hues), bold: true }))
  const rows: Row[] = [{ key: 'heat-head', raster: 'heat', segments: head.filter(s => s.text !== '') }]
  const middle = Math.floor((spec.rows - 1) / 2)
  input.boundaries.forEach((b, from) => {
    for (let r = 0; r < spec.rows; r++) {
      const named = r === middle
      const segments: Segment[] = named
        ? [{ text: spaces(INDENT) }, { text: b.code, ...boundaryStyle(b.name, hues), bold: true }, { text: padEnd(fit(` ${b.label}`, spec.label - cells(b.code)), spec.label - cells(b.code)) }, { text: ' ' }]
        : [{ text: spaces(INDENT + spec.label + 1) }]
      input.boundaries.forEach((_, to) => {
        segments.push(...heatCell(input.cells[from]?.[to] ?? 0, max, input.forbidden[from]?.[to] ?? false, spec.cell, r, spec.rows))
      })
      // The named row keeps the boundary's key at every scale; the rows around it are numbered.
      rows.push({ key: named ? `heat-${from}` : `heat-${from}-${r}`, raster: 'heat', segments: segments.filter(s => s.text !== '') })
    }
  })
  return rows
}

/**
 * The key under the map: the four steps, none, then the forbidden marks. Part
 * of the grid, so on the terminal its swatches are the same solid cells.
 */
function legendRows(input: BoundariesInput, columns: number): Row[] {
  const crossed = input.forbidden.some((row, from) => row.some((f, to) => f && (input.cells[from]?.[to] ?? 0) > 0))
  const steps: Segment[] = SHADES.map((s, i) => ({ text: s.repeat(2), color: ACCENT, cell: { glyph: TILE, fg: HEAT_KEYS[i]! } }))
  const groups: Segment[][] = [
    [{ text: 'fewer ', dim: true }, ...steps, { text: ' more deps', dim: true }],
    [{ text: '·', color: FAINT }, { text: ' none', dim: true }],
    [{ text: '×', color: STATUS_COLOURS.alert }, { text: ' forbidden', dim: true }],
  ]
  if (crossed) groups.push([{ text: SHADES[3].repeat(2), color: STATUS_COLOURS.alert, cell: { glyph: TILE, fg: STATUS_COLOURS.alert } }, { text: ' forbidden, crossed', dim: true }])
  return wrapGroups('heat-legend', groups, columns, 3, INDENT).map(row => ({ ...row, raster: 'heat' }))
}

/** How the per-boundary table fits `columns`, and the names it lists. */
function perBoundarySpec(input: BoundariesInput, columns: number, tier: Tier): { spec: TableSpec; names: string[] } {
  const widths = PER_TITLES.map((t, i) => numberWidth(t, input.boundaries.map(b => perValues(b)[i] ?? 0)))
  const names = input.boundaries.map(b => `${b.code} ${b.label}`)
  return { spec: tableSpec(columns, names, [], widths, undefined, { tier }), names }
}

const PER_TITLES = ['comps', 'in', 'out']
const perValues = (b: BoundaryLine) => [b.members, b.in, b.out]

/** Each boundary on its own row, pressable: its letter and name, a bar of its components, then components, in and out; `limit` of them around the marked one. */
function perBoundaryRows(input: BoundariesInput, selected: number, columns: number, limit: number, tier: Tier, hues: Hues): Row[] {
  const { spec, names } = perBoundarySpec(input, columns, tier)
  const max = Math.max(0, ...input.boundaries.map(b => b.members))
  const window = windowOf(input.boundaries.length, limit, selected)
  return [
    tableHead('bounds-cols', spec, { name: 'boundary', boundary: '', numbers: PER_TITLES }),
    ...input.boundaries
      .slice(window.start, window.end)
      .map((b, n) =>
        tableRow(`bounds-${window.start + n}`, { name: names[window.start + n]!, boundary: b.name, values: perValues(b), max, selected: window.start + n === selected, press: `row:${window.start + n}` }, spec, hues),
      ),
    ...moreRows('bounds-window', window, input.boundaries.length, columns),
  ]
}

/**
 * The boundaries the marker walks. There is nothing to open: marking one
 * spells out what it depends on and what depends on it under the table. `c`
 * copies its name, and "Ask Claude" asks what its dependencies are for.
 */
export function boundariesList(input: BoundariesInput | null): Openable[] {
  return (input?.boundaries ?? []).map(b => ({
    name: b.label,
    canonical: b.name,
    inert: true,
    copy: b.name,
    ask: `Using the Knossos graph, what does the boundary ${b.name} depend on, what depends on it, and which of those dependencies would a cleaner design remove?`,
  }))
}

/** The other boundaries `index` reaches (`out`) or is reached from (`in`), most dependencies first. */
function linksOf(input: BoundariesInput, index: number, way: 'in' | 'out'): { line: BoundaryLine; count: number; forbidden: boolean }[] {
  return input.boundaries
    .map((line, other) => {
      const [from, to] = way === 'out' ? [index, other] : [other, index]
      return { line, count: input.cells[from]?.[to] ?? 0, forbidden: input.forbidden[from]?.[to] ?? false }
    })
    .filter((link, other) => other !== index && link.count > 0)
    .sort((a, b) => b.count - a.count || a.line.label.localeCompare(b.line.label))
}

/**
 * The marked boundary spelled out: what it depends on and what depends on
 * it, each other boundary in its colour with its count, and the boundaries a
 * policy forbids it to depend on. A crossed forbidden pair is in `error`.
 */
function focusSection(input: BoundariesInput, index: number, columns: number, hues: Hues): Section | null {
  const b = input.boundaries[index]
  if (b === undefined) return null
  const label = (text: string): Segment[] => [{ text: padEnd(text, FOCUS_LABEL), dim: true }]
  const linked = (way: 'in' | 'out'): Segment[][] => {
    const links = linksOf(input, index, way)
    if (links.length === 0) return [[{ text: 'nothing outside itself', dim: true }]]
    return links.map(l => [
      { text: l.line.label, ...boundaryStyle(l.line.name, hues) },
      { text: ` ${grouped(l.count)}`, ...(l.forbidden ? { color: STATUS_COLOURS.alert } : { dim: true }) },
    ])
  }
  const forbidden = input.boundaries.filter((_, to) => to !== index && (input.forbidden[index]?.[to] ?? false))
  const line = (key: string, title: string, groups: Segment[][]): Row[] => wrapGroups(key, [label(title), ...groups], columns, 2, INDENT)
  return {
    key: 'focus',
    title: `${b.code} ${b.label}`,
    note: [{ text: `${grouped(b.members)} ${b.members === 1 ? 'component' : 'components'}`, ...boundaryStyle(b.name, hues) }],
    body: [
      ...line('focus-out', 'depends on', linked('out')),
      ...line('focus-in', 'used by', linked('in')),
      ...(forbidden.length === 0 ? [] : line('focus-forbidden', 'may not use', forbidden.map(f => [{ text: '× ', color: STATUS_COLOURS.alert }, { text: f.label, dim: true }]))),
    ],
  }
}

/** The width of the label before what the marked boundary depends on. */
const FOCUS_LABEL = 'may not use'.length

/** The fewest boundaries the per-boundary table lists, however short the pane. */
const PER_MIN = 5

/** The heat map as a card: the map (each cell `scale` rows tall when it fits), and its key when `legend`; null without a map to draw. */
export function heatSection(input: BoundariesInput | null, columns: number, hues: Hues = NO_HUES, legend = true, scale = 1): Section | null {
  if (input === null || input.boundaries.length === 0) return null
  const note = [`${input.boundaries.length}${input.more ? '+' : ''} boundaries`, `${input.edges} deps`].join(' · ')
  return { key: legend ? 'bounds' : 'map', title: legend ? 'Boundaries' : 'Boundary map', note: noteOf(note), body: [...heatRows(input, columns, hues, scale), ...(legend ? legendRows(input, columns) : [])] }
}

/**
 * The heat map as a block that grows late (unless `grows` is false: the
 * Overview's small map stays small): with rows to spare once the lists have
 * theirs, each cell scales up a step (two rows, then three).
 */
export function heatBlock(input: BoundariesInput | null, hues: Hues, legend: boolean, grows = true): Block {
  const key = legend ? 'bounds' : 'map'
  if (!grows) return { key, make: columns => heatSection(input, columns, hues, legend) }
  return { key, grow: { length: HEAT_SCALE_MAX - 1, min: 0, late: true }, make: (columns, limit) => heatSection(input, columns, hues, legend, 1 + limit) }
}

/**
 * The Boundaries tab: the heat map with its key, the per-boundary table and
 * the marked boundary spelled out. Wide, the map stands left and the table
 * with the marked boundary right.
 */
export function boundariesArrangement(input: BoundariesInput | null, tier: Tier, hues: Hues = NO_HUES, selected = 0): Arrangement {
  const said = (text: string): Block => ({ key: 'bounds', make: columns => ({ key: 'bounds', title: 'Boundaries', body: [dimRow('bounds-none', `   ${text}`, columns)] }) })
  if (input === null) return { left: [said('This knossos sends no boundary map; update it.')] }
  if (input.boundaries.length === 0) return { left: [said('no boundary labels a component')] }
  const marked = Math.min(Math.max(0, selected), input.boundaries.length - 1)
  const heat = heatBlock(input, hues, true)
  const per: Block = {
    key: 'bounds-list',
    grow: { length: input.boundaries.length, min: PER_MIN },
    make: (columns, limit) => ({ key: 'bounds-list', title: 'Per boundary', note: noteOf('deps across'), body: perBoundaryRows(input, marked, columns, limit, tier, hues) }),
  }
  const focus: Block = { key: 'focus', make: columns => focusSection(input, marked, columns, hues) }
  return { left: [heat], right: [per, focus] }
}
