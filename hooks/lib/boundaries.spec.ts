import { describe, expect, it } from 'vitest'
import type { BoundaryMatrix, Dashboard } from '../../types'
import { axisCode, boundariesArrangement, boundariesInput, boundariesList, couplingSection, couplingView, heatRows, heatSpec, markedCell, nextTarget, shade, SHADES } from './boundaries'
import { arrange } from './cards'
import { ACCENT, boundaryColour, NO_HUES } from './palette'
import { HEAT_KEYS } from './raster'
import { findRow, plainText, rawText } from './__tests__/plain-text'
import { boundaryRows } from './__tests__/tabs'
import { rowWidth } from './rows'
import type { Row } from './rows'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const

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
const row = findRow

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
    expect(heatSpec(input, 40)).toEqual({ label: 19, cell: 3, rows: 1 })
    expect(heatSpec(input, 120)).toEqual({ label: 19, cell: 3, rows: 1 })
    const ten = boundariesInput(dash(matrix({ boundaries: [...input.boundaries.map(b => b.name), ...Array.from({ length: 6 }, (_, i) => `b${i}`)], members: [], cells: [], forbidden: [] })))!
    expect(heatSpec(ten, 60)).toEqual({ label: 19, cell: 3, rows: 1 })
    // Too narrow for whole labels: they give way before the cells drop below 3.
    expect(heatSpec(ten, 40)).toEqual({ label: 6, cell: 3, rows: 1 })
    const twelve = boundariesInput(dash(matrix({ boundaries: Array.from({ length: 12 }, (_, i) => `b${i}`), members: [], cells: [], forbidden: [] })))!
    expect(heatSpec(twelve, 40)).toEqual({ label: 7, cell: 2, rows: 1 })
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
    const cell = row(heatRows(boundariesInput(dash(crossed))!, 60), 'heat-0')!.segments.filter(s => s.text.startsWith('█'))[1]!
    expect(cell.color).toBe('error')
    expect(cell.cell).toEqual({ glyph: '▇', fg: 'error' })
    expect(textOf(boundaryRows(boundariesInput(dash(crossed)), 60))).toContain('forbidden, crossed')
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

describe('the marked boundary', () => {
  it('is walked by the marker, opens nothing, and spells out what it depends on and what depends on it', () => {
    const input = boundariesInput(dash())!
    const list = boundariesList(input)
    expect(list.map(b => b.name)).toEqual(input.boundaries.map(b => b.label))
    expect(list.every(b => b.inert === true && b.copy === b.canonical && b.ask?.includes(b.canonical) === true)).toBe(true)
    const at = (selected: number) => boundaryRows(input, 60, NO_HUES, selected)
    expect(plainText(row(at(1), 'bounds-1')!).startsWith('›')).toBe(true)
    expect(row(at(1), 'bounds-1')!.segments.find(s => s.press)?.press?.id).toBe('row:1')
    const focus = (selected: number) => at(selected).filter(r => r.key.startsWith('focus-')).map(plainText).join('\n')
    for (const [i, b] of input.boundaries.entries()) {
      const text = focus(i)
      expect(text, b.label).toContain(b.label)
      const out = input.cells[i]!.some((n, to) => to !== i && n > 0)
      expect(text, b.label).toMatch(out ? /depends on +\S+ [\d,]+/ : /depends on +nothing outside itself/)
    }
  })
  it('names the boundaries a policy forbids it to use, and a crossed one in the error colour', () => {
    const input = boundariesInput(dash())!
    const from = input.forbidden.findIndex(r => r.some(Boolean))
    expect(from).toBeGreaterThanOrEqual(0)
    const rows = boundaryRows(input, 60, NO_HUES, from)
    expect(rows.filter(r => r.key.startsWith('focus-forbidden')).map(plainText).join(' ')).toMatch(/may not use +× \S+/)
    const crossed = input.forbidden[from]!.findIndex((f, to) => f && (input.cells[from]![to] ?? 0) > 0)
    if (crossed >= 0) {
      const counts = rows.filter(r => r.key.startsWith('focus-out')).flatMap(r => r.segments).filter(s => s.color === 'error')
      expect(counts.length).toBeGreaterThan(0)
    }
  })
  it('draws each section as a card whose edges span the pane, the map beside the table when wide', () => {
    const rows = boundaryRows(boundariesInput(dash()), 100)
    expect(rowWidth(row(rows, 'bounds-head')!)).toBe(100)
    expect(rowWidth(row(rows, 'bounds-list-head')!)).toBe(100)
    const wide = boundaryRows(boundariesInput(dash()), 140)
    expect(wide.find(r => r.key === 'bounds-head|bounds-list-head')).toBeDefined()
    for (const r of wide) expect(rowWidth(r)).toBeLessThanOrEqual(140)
  })
})

describe('boundaryRows', () => {
  it('lists each boundary with its letter, components, in and out under the map, with a legend', () => {
    const rows = boundaryRows(boundariesInput(dash()), 60)
    expect(plainText(row(rows, 'bounds-head')!)).toMatch(/^Boundaries +4 boundaries · 28,112 deps$/)
    expect(textOf(rows)).toContain(`fewer ${SHADES.map(s => s.repeat(2)).join('')} more deps`)
    // The legend is part of the grid, so the terminal draws its swatches as the same tiles.
    expect(rows.filter(r => r.key.startsWith('heat-legend')).every(r => r.raster === 'heat')).toBe(true)
    expect(plainText(row(rows, 'bounds-cols')!)).toMatch(/boundary +comps +in +out$/)
    // A narrow column says its figures compactly; the wide one in full.
    expect(plainText(row(rows, 'bounds-1')!)).toMatch(/^ {3}B core +[━╸]+·* +1\.6k +10\.8k +0$/)
    expect(plainText(row(boundaryRows(boundariesInput(dash()), 100), 'bounds-1')!)).toMatch(/ 1,633 +10,779 +0$/)
    expect(plainText(row(rows, 'bounds-3')!)).toMatch(/^ {3}D hooks /)
  })
  it('says so when no boundary labels a component, and when knossos sends no map', () => {
    expect(textOf(boundaryRows(boundariesInput(dash(matrix({ boundaries: [], members: [], cells: [], forbidden: [], edges: 0 }))), 60))).toContain(
      'no boundary labels a component',
    )
    expect(textOf(boundaryRows(null, 60))).toContain('sends no boundary map')
  })
})

describe('the marked heat map cell', () => {
  const input = boundariesInput(dash(matrix({ cells: [[12617, 10779, 40, 3], [0, 3513, 0, 0], [0, 0, 765, 0], [0, 0, 0, 438]] })))!
  const answer = {
    status: 'ok',
    edges: 10779,
    truncated: false,
    couplings: [
      { source: { name: 'scan', canonical_name: 'Tests\\ScanTest::scan', kind: 'method' }, target: { name: 'StableId', canonical_name: 'Knossos\\Store\\StableId', kind: 'class' }, edges: 812 },
      { source: { name: 'Fixtures', canonical_name: 'Tests\\Support\\Fixtures', kind: 'class' }, target: { name: 'open', canonical_name: 'Knossos\\Store\\SqliteConnection::open', kind: 'method' }, edges: 97 },
    ],
  }

  it('runs from the marked boundary to what it depends on most, and l steps it round the rest', () => {
    expect(markedCell(input, 0)).toEqual({ from: 0, to: 1 })
    // With no target the cell is on the most: the next is the second most.
    expect(nextTarget(input, 0, null)).toBe('typescript-worker')
    expect(nextTarget(input, 0, 'nowhere')).toBe('typescript-worker')
    expect(nextTarget(input, 0, 'core')).toBe('typescript-worker')
    expect(nextTarget(input, 0, 'typescript-worker')).toBe('module:hooks (+typescript:hooks/tsconfig.json)')
    // Past the last it comes round again; a target it does not depend on falls back to the most.
    expect(nextTarget(input, 0, 'module:hooks (+typescript:hooks/tsconfig.json)')).toBe('core')
    expect(markedCell(input, 0, 'typescript-worker')).toEqual({ from: 0, to: 2 })
    expect(markedCell(input, 0, 'nowhere')).toEqual({ from: 0, to: 1 })
    // A boundary that depends on nothing outside itself has no cell to mark.
    expect(markedCell(input, 1)).toBeNull()
    expect(nextTarget(input, 1, null)).toBeNull()
    expect(markedCell(null, 0)).toBeNull()
  })

  it('stands out of the map in the text colour, its row label and column letter on the tint', () => {
    const rows = heatRows(input, 100, NO_HUES, 1, { from: 0, to: 2 })
    const cell = row(rows, 'heat-0')!.segments.find(s => s.color === 'text' && s.cell !== undefined)
    expect(cell?.cell).toEqual({ glyph: '▇', fg: 'text' })
    expect(row(rows, 'heat-head')!.segments.filter(s => s.bg === 'userMessageBackground').map(s => s.text.trim())).toEqual(['C'])
    expect(row(rows, 'heat-0')!.segments.filter(s => s.bg === 'userMessageBackground').map(s => s.text.trim())).toEqual(['A', 'tests'])
    expect(row(rows, 'heat-1')!.segments.some(s => s.bg !== undefined)).toBe(false)
  })

  it('is spelled out as A → B, its deps, and the pairs behind it, most first, at every width', () => {
    for (const columns of WIDTHS) {
      const section = couplingSection(input, { from: 0, to: 1 }, couplingView({ phase: 'done', answer }), columns)!
      expect(section.title).toBe('tests → core')
      expect(section.note!.map(n => n.text).join('')).toBe('10,779 deps')
      for (const r of section.body) expect(rowWidth(r), `${columns} ${r.key}`).toBeLessThanOrEqual(columns)
    }
    const section = couplingSection(input, { from: 0, to: 1 }, couplingView({ phase: 'done', answer }), 80)!
    // The two boundaries drawn, the dependencies on the arrow between them.
    expect(rawText(section.body[1]!)).toMatch(/^│ tests ├─+ 10,779 deps ─+►│ core │$/)
    // Under them the pairs behind it, in columns: the sources padded, an arrow, the targets, the counts.
    expect(plainText(section.body[4]!)).toMatch(/^ {3}ScanTest::scan +──► StableId +812$/)
    expect(plainText(section.body[5]!)).toMatch(/^ {3}Fixtures +──► SqliteConnection::open +97$/)
    expect(rawText(section.body[4]!).indexOf('──►')).toBe(rawText(section.body[5]!).indexOf('──►'))
    // A pair forbidden by a policy says so in the error colour.
    const forbidden = couplingSection(input, { from: 1, to: 0 }, null, 80)!
    expect(forbidden.note!.find(n => n.text === ' · forbidden')?.color).toBe('error')
    expect(forbidden.body[1]!.segments.find(s => s.text.includes('✕'))).toMatchObject({ color: 'error', bold: true })
    expect(forbidden.body[1]!.segments.filter(s => s.text.includes('─')).every(s => s.color === 'error')).toBe(true)
  })

  it('says it is reading, that knossos did not say, or that no pair is listed', () => {
    const said = (view: ReturnType<typeof couplingView>) => couplingSection(input, { from: 0, to: 1 }, view, 80)!.body.map(plainText).join('\n')
    expect(said(null)).toContain('reading the couplings…')
    expect(said(couplingView({ phase: 'loading', answer: null }))).toContain('reading the couplings…')
    expect(said(couplingView({ phase: 'done', answer: null }))).toContain('knossos did not say which components')
    expect(said(couplingView({ phase: 'done', answer: { ...answer, couplings: [] } }))).toContain('no component pair listed')
    expect(couplingView({ phase: 'done', answer: { ...answer, truncated: true } })?.truncated).toBe(true)
  })

  it('sits under the marked boundary on the Boundaries tab, beside the map when wide', () => {
    const view = couplingView({ phase: 'done', answer })
    for (const columns of WIDTHS) {
      const rows = arrange(boundariesArrangement(input, columns < 80 ? 'narrow' : columns <= 130 ? 'medium' : 'wide', NO_HUES, 0, null, view), columns, 1_000)
      const at = (key: string) => rows.findIndex(r => r.key.split('|').includes(key))
      expect(at('coupling-head'), `${columns}`).toBeGreaterThan(at('focus-head'))
      for (const r of rows) expect(rowWidth(r), `${columns} ${r.key}`).toBeLessThanOrEqual(columns)
    }
    // What the marked boundary depends on is pressable: a press moves the cell there.
    const rows = boundaryRows(input, 100)
    const presses = rows.flatMap(r => r.segments.flatMap(s => (s.press?.id.startsWith('cell:') ? [s.press.id] : [])))
    expect(presses).toEqual(['cell:1', 'cell:2', 'cell:3'])
  })
})
