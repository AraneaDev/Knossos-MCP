import { describe, expect, it } from 'vitest'
import type { BoundaryMatrix, Dashboard } from '../../types'
import { axisCode, boundariesInput, boundaryRows, heatRows, heatSpec, shade, SHADES } from './boundaries'
import { ACCENT, boundaryColour } from './palette'
import { HEAT_KEYS } from './raster'
import { plainText, rowWidth } from './rows'
import type { Row } from './rows'

const WIDTHS = [40, 60, 90, 120] as const

const matrix = (over: Partial<BoundaryMatrix> = {}): BoundaryMatrix => ({
  boundaries: ['tests', 'core', 'typescript-worker', 'module:hooks (+typescript:hooks/tsconfig.json)'],
  members: [4933, 1633, 403, 175],
  boundaries_truncated: false,
  cells: [
    [12617, 10779, 0, 0],
    [0, 3513, 0, 0],
    [0, 0, 765, 0],
    [0, 0, 0, 438],
  ],
  forbidden: [
    [1, 0],
    [1, 2],
  ],
  edges: 28112,
  truncated: false,
  truncation_reasons: [],
  ...over,
})

const dash = (m: BoundaryMatrix | null = matrix()): Dashboard => ({
  status: 'ok',
  path: '/work/p',
  project_root: '/work/p',
  project_id: 'p1',
  snapshot_id: 's1',
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 0,
  dead_code_truncated: false,
  cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
  trend: [],
  fan_in: [],
  fan_in_truncated: false,
  ...(m === null ? {} : { boundary_matrix: m }),
})

const textOf = (rows: Row[]) => rows.map(plainText).join('\n')
const row = (rows: Row[], key: string) => rows.find(r => r.key === key)

describe('boundariesInput', () => {
  it('letters the axes, labels each boundary and sums its dependencies across', () => {
    const input = boundariesInput(dash())!
    expect(input.boundaries.map(b => `${b.code} ${b.label}`)).toEqual(['A tests', 'B core', 'C typescript-worker', 'D hooks'])
    // In and out leave a boundary's dependencies on itself out.
    expect(input.boundaries.map(b => [b.members, b.in, b.out])).toEqual([
      [4933, 0, 10779],
      [1633, 10779, 0],
      [403, 0, 0],
      [175, 0, 0],
    ])
    expect(input.forbidden[1]).toEqual([true, false, true, false])
    expect(input.forbidden[0]).toEqual([false, false, false, false])
    expect(input.edges).toBe('28,112')
  })
  it('marks a count that stopped at a limit as a floor, and more boundaries than the axes hold', () => {
    const input = boundariesInput(dash(matrix({ truncated: true, boundaries_truncated: true })))!
    expect(input.edges).toBe('28,112+')
    expect(input.more).toBe(true)
  })
  it('is null for a knossos that sends no matrix', () => {
    expect(boundariesInput(dash(null))).toBeNull()
  })
  it('names axes past Z by a lower-case letter and a round', () => {
    expect([0, 25, 26, 27].map(axisCode)).toEqual(['A', 'Z', 'a1', 'b1'])
  })
})

describe('shade', () => {
  it('shades on a log scale of the busiest cell, none for zero', () => {
    expect(shade(0, 100)).toBe(0)
    expect(shade(100, 100)).toBe(4)
    expect(shade(1, 12617)).toBe(1)
    expect(shade(11, 12617)).toBe(2)
    expect(shade(438, 12617)).toBe(3)
    expect(shade(5, 0)).toBe(0)
  })
})

describe('heat map', () => {
  it('fits whole labels first with square cells, wider panes only leaving room', () => {
    const input = boundariesInput(dash())!
    expect(heatSpec(input, 40)).toEqual({ label: 19, cell: 3 })
    expect(heatSpec(input, 120)).toEqual({ label: 19, cell: 3 })
    const ten = boundariesInput(dash(matrix({ boundaries: [...input.boundaries.map(b => b.name), ...Array.from({ length: 6 }, (_, i) => `b${i}`)], members: [], cells: [], forbidden: [] })))!
    expect(heatSpec(ten, 60)).toEqual({ label: 19, cell: 3 })
    // Too narrow for whole labels: they give way before the cells drop below 3.
    expect(heatSpec(ten, 40)).toEqual({ label: 6, cell: 3 })
    const twelve = boundariesInput(dash(matrix({ boundaries: Array.from({ length: 12 }, (_, i) => `b${i}`), members: [], cells: [], forbidden: [] })))!
    expect(heatSpec(twelve, 40)).toEqual({ label: 7, cell: 2 })
  })
  it('draws every cell in the one accent, none as a faint dot and a forbidden pair as a cross in the error colour', () => {
    const rows = heatRows(boundariesInput(dash())!, 60)
    expect(plainText(rows[0]!)).toMatch(/^ {3}from→to +A +B +C +D *$/)
    expect(plainText(row(rows, 'heat-0')!)).toMatch(/^ {3}A tests +█+ █+ ·  +·  +$/)
    const core = row(rows, 'heat-1')!
    expect(plainText(core)).toMatch(/^ {3}B core +× +█+ × +· +$/)
    expect(core.segments.filter(s => s.text.includes('×')).every(s => s.color === 'error')).toBe(true)
    expect(core.segments.find(s => s.text.includes('·'))?.color).toBe('subtle')
    const tests = row(rows, 'heat-0')!.segments.find(s => s.text.startsWith('█'))!
    // One hue, whatever the boundary: the accent, its busiest step as a tile on the terminal grid.
    expect(tests.color).toBe(ACCENT)
    expect(tests.cell).toEqual({ glyph: '▇', fg: HEAT_KEYS[3] })
    // Only the axis letter carries the boundary's colour.
    expect(row(rows, 'heat-0')!.segments.find(s => s.text === 'A')?.color).toBe(boundaryColour('tests'))
    expect(row(rows, 'heat-0')!.segments.find(s => s.text.includes('tests'))?.color).toBeUndefined()
    expect(rows.every(r => r.raster === 'heat')).toBe(true)
  })
  it('draws a crossed forbidden pair in the error colour, as an error tile on the terminal grid', () => {
    const crossed = matrix({ forbidden: [[0, 1]] })
    const rows = boundaryRows(boundariesInput(dash(crossed)), 60)
    const cell = row(rows, 'heat-0')!.segments.filter(s => s.text.startsWith('█'))[1]!
    expect(cell.color).toBe('error')
    expect(cell.cell).toEqual({ glyph: '▇', fg: 'error' })
    expect(textOf(rows)).toContain('forbidden, crossed')
  })
  it('keeps every row of the tab within the width', () => {
    for (const columns of WIDTHS) {
      for (const m of [matrix(), matrix({ boundaries: Array.from({ length: 12 }, (_, i) => `boundary-number-${i}`), members: Array(12).fill(1), cells: [], forbidden: [] })]) {
        const rows = boundaryRows(boundariesInput(dash(m)), columns)
        for (const r of rows) expect(rowWidth(r), `${r.key} at ${columns}`).toBeLessThanOrEqual(columns)
      }
    }
  })
})

describe('boundaryRows', () => {
  it('lists each boundary with its letter, components, in and out under the map, with a legend', () => {
    const rows = boundaryRows(boundariesInput(dash()), 60)
    expect(plainText(row(rows, 'bounds-head')!)).toMatch(/^Boundaries +4 · 28,112 deps$/)
    expect(textOf(rows)).toContain(`fewer ${SHADES.map(s => s.repeat(2)).join('')} more deps`)
    // The legend is part of the grid, so the terminal draws its swatches as the same tiles.
    expect(rows.filter(r => r.key.startsWith('heat-legend')).every(r => r.raster === 'heat')).toBe(true)
    expect(plainText(row(rows, 'bounds-cols')!)).toMatch(/boundary +comps +in +out$/)
    expect(plainText(row(rows, 'bounds-1')!)).toMatch(/^ {3}B core +[━╸]+·* +1633 +10779 +0$/)
    expect(plainText(row(rows, 'bounds-3')!)).toMatch(/^ {3}D hooks /)
  })
  it('says so when no boundary labels a component, and when knossos sends no map', () => {
    expect(textOf(boundaryRows(boundariesInput(dash(matrix({ boundaries: [], members: [], cells: [], forbidden: [], edges: 0 }))), 60))).toContain(
      'no boundary labels a component',
    )
    expect(textOf(boundaryRows(null, 60))).toContain('sends no boundary map')
  })
})
