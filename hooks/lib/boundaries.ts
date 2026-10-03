/**
 * The pane's Boundaries tab: a heat map of how much each boundary depends on
 * each other one, then each boundary's components and its dependencies in
 * and out.
 *
 * Pure, like the rest of the layout. The heat map is drawn as rows of glyphs
 * every surface can show: a shade from `░` to `█` per cell in the row's
 * boundary colour, `·` for none, red where a declared policy forbids the
 * pair. Its rows carry `raster: 'heat'` and each cell its background, so the
 * terminal can draw the same grid as one `Raster` of coloured cells instead.
 *
 * Axes are abbreviated to one letter each (A, B, C, ...); the row labels and
 * the table below the map spell each letter out, so they are the legend.
 */
import type { Dashboard } from '../../types'
import { boundaryColour, boundaryLabel, STATUS_COLOURS } from './palette'
import { blank, boundaryStyle, cells, dimRow, fit, grouped, numberWidth, padEnd, sectionRow, spaces, tableHead, tableRow, tableSpec, wrapGroups } from './rows'
import type { Row, Segment } from './rows'

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
/** The widest a row label grows (letter, space, name): past it the cells get the room. */
const LABEL_MAX = 20
/** The narrowest a row label shrinks to before the cells narrow instead. */
const LABEL_MIN = 6
/** Background tints of a cell on the terminal's grid, by shade: the boundary colour at this strength over a dark base. */
const TINTS = [0.3, 0.5, 0.75, 1] as const
const TINT_BASE = 0x26
const INDENT = 3
/** The corner above the row labels: rows depend on columns. */
const CORNER = 'from→to'
/** A crossed forbidden pair's background on the terminal's grid. */
const CROSSED = '#c0392b'

/** The axis letter of the boundary at `index`: A to Z, then a1, b1, ... (the axes hold twelve). */
export const axisCode = (index: number): string =>
  index < 26 ? String.fromCharCode(65 + index) : `${String.fromCharCode(97 + (index % 26))}${Math.floor(index / 26)}`

/** The Boundaries tab's view model, or null when the knossos that answered sends no matrix. */
export function boundariesInput(d: Dashboard): BoundariesInput | null {
  const m = d.boundary_matrix
  if (m === undefined) return null
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
      label: boundaryLabel(name),
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

/** `#rrggbb` at `strength` over the dark base, as `#rrggbb`. */
export function tint(colour: string, strength: number): string {
  const rgb = parseInt(colour.slice(1), 16)
  const channel = (shift: number) => {
    const c = (rgb >> shift) & 0xff
    return Math.round(TINT_BASE + (c - TINT_BASE) * strength)
  }
  return `#${[16, 8, 0].map(s => channel(s).toString(16).padStart(2, '0')).join('')}`
}

/**
 * How wide the row labels and each cell are at `columns`. Labels come first:
 * whole labels with the widest cells that leave room for them, down to
 * cells of 3; past that the labels give way to {@link LABEL_MIN} and the
 * cells narrow to 2, then 1. A cell is its shade glyphs and a one-cell gap
 * (no gap at width 1).
 */
export function heatSpec(input: BoundariesInput, columns: number): { label: number; cell: number } {
  const n = Math.max(1, input.boundaries.length)
  const need = Math.min(LABEL_MAX, Math.max(cells(CORNER), ...input.boundaries.map(b => cells(`${b.code} ${b.label}`))))
  const room = (cell: number) => columns - INDENT - 1 - n * cell
  for (const cell of [6, 5, 4, 3]) {
    if (room(cell) >= need) return { label: need, cell }
  }
  for (const cell of [3, 2, 1]) {
    if (room(cell) >= Math.min(LABEL_MIN, need)) return { label: Math.min(need, room(cell)), cell }
  }
  return { label: Math.max(1, room(1)), cell: 1 }
}

/** One heat cell: its glyphs (the grid's own shade on the terminal is its background) and the gap after it. */
function heatCell(value: number, max: number, forbidden: boolean, colour: string | undefined, width: number): Segment[] {
  const glyphs = width > 1 ? width - 1 : 1
  const gap: Segment[] = width > 1 ? [{ text: ' ' }] : []
  const level = shade(value, max)
  if (level === 0) {
    const mark = forbidden ? { text: padEnd('×', glyphs), color: STATUS_COLOURS.alert, dim: true } : { text: padEnd('·', glyphs), dim: true }
    return [mark, ...gap]
  }
  const glyph = SHADES[level - 1]!.repeat(glyphs)
  // A forbidden pair that is crossed is a violation: red whatever the boundary.
  if (forbidden) return [{ text: glyph, color: STATUS_COLOURS.alert, bold: true, cell: { glyph: ' ', bg: CROSSED } }, ...gap]
  if (colour === undefined) return [{ text: glyph, dim: true }, ...gap]
  return [{ text: glyph, color: colour, cell: { glyph: ' ', bg: tint(colour, TINTS[level - 1]!) } }, ...gap]
}

/** The heat map: a header of axis letters, then one row per boundary, each a `raster: 'heat'` row. */
export function heatRows(input: BoundariesInput, columns: number): Row[] {
  const spec = heatSpec(input, columns)
  const max = Math.max(0, ...input.cells.flat())
  const head: Segment[] = [{ text: spaces(INDENT) + padEnd(fit(CORNER, spec.label), spec.label) + ' ', dim: true }]
  input.boundaries.forEach(b => head.push({ text: padEnd(b.code, spec.cell), ...boundaryStyle(b.name), bold: true }))
  const rows: Row[] = [{ key: 'heat-head', raster: 'heat', segments: head.filter(s => s.text !== '') }]
  input.boundaries.forEach((b, from) => {
    const style = boundaryStyle(b.name)
    const segments: Segment[] = [
      { text: spaces(INDENT) },
      { text: b.code, ...style, bold: true },
      { text: padEnd(fit(` ${b.label}`, spec.label - cells(b.code)), spec.label - cells(b.code)), ...style },
      { text: ' ' },
    ]
    input.boundaries.forEach((_, to) => {
      segments.push(...heatCell(input.cells[from]?.[to] ?? 0, max, input.forbidden[from]?.[to] ?? false, boundaryColour(b.name), spec.cell))
    })
    rows.push({ key: `heat-${from}`, raster: 'heat', segments: segments.filter(s => s.text !== '') })
  })
  return rows
}

/** The key under the map: the shades, then the forbidden marks. */
function legendRows(input: BoundariesInput, columns: number): Row[] {
  const crossed = input.forbidden.some((row, from) => row.some((f, to) => f && (input.cells[from]?.[to] ?? 0) > 0))
  const groups: Segment[][] = [
    [{ text: `${SHADES.join('')} fewer → more deps`, dim: true }],
    [{ text: '·', dim: true }, { text: ' none', dim: true }],
    [{ text: '×', color: STATUS_COLOURS.alert }, { text: ' forbidden', dim: true }],
  ]
  if (crossed) groups.push([{ text: SHADES[3], color: STATUS_COLOURS.alert, bold: true }, { text: ' forbidden, crossed', dim: true }])
  return wrapGroups('heat-legend', groups, columns, 3, INDENT)
}

/** Each boundary on its own row: its letter and name, a bar of its components, then components, in and out. */
function perBoundaryRows(input: BoundariesInput, columns: number): Row[] {
  const titles = ['comps', 'in', 'out']
  const values = (b: BoundaryLine) => [b.members, b.in, b.out]
  const widths = titles.map((t, i) => numberWidth(t, input.boundaries.map(b => values(b)[i] ?? 0)))
  const names = input.boundaries.map(b => `${b.code} ${b.label}`)
  const spec = tableSpec(columns, names, [], widths)
  const max = Math.max(0, ...input.boundaries.map(b => b.members))
  return [
    tableHead('bounds-cols', spec, { name: 'boundary', boundary: '', numbers: titles }),
    ...input.boundaries.map((b, i) => tableRow(`bounds-${i}`, { name: names[i]!, boundary: b.name, values: values(b), max }, spec)),
  ]
}

/** The whole Boundaries tab at `columns`. */
export function boundaryRows(input: BoundariesInput | null, columns: number): Row[] {
  const rows: Row[] = [blank('gap-bounds')]
  if (input === null) {
    return [...rows, sectionRow('bounds-head', 'Boundaries', '', columns), dimRow('bounds-old', '   This knossos sends no boundary map; update it.', columns)]
  }
  const note = [`${input.boundaries.length}${input.more ? '+' : ''}`, `${input.edges} deps`].join(' · ')
  rows.push(sectionRow('bounds-head', 'Boundaries', input.boundaries.length === 0 ? '' : note, columns))
  if (input.boundaries.length === 0) return [...rows, dimRow('bounds-none', '   no boundary labels a component', columns)]
  return [
    ...rows,
    ...heatRows(input, columns),
    ...legendRows(input, columns),
    blank('gap-per-boundary'),
    sectionRow('bounds-list', 'Per boundary', 'deps across', columns),
    ...perBoundaryRows(input, columns),
  ]
}
