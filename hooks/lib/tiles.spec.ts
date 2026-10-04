import { describe, expect, it } from 'vitest'
import { cells, rowWidth } from './rows'
import { moves, tileRows, tileSlots, tilesBlock } from './tiles'
import type { Stat } from './tiles'
import { chartRows, trendBlock } from './trend'
import { plainText, rawText } from './__tests__/plain-text'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const

const STATS: Stat[] = [
  { key: 'components', label: 'components', value: '9,026', inSummary: true },
  { key: 'boundaries', label: 'boundaries', value: '7', inSummary: true },
  { key: 'cycles', label: 'cycles', value: '2', tone: 'warn', trend: [0, 1, 1, 2, 4, 3, 3, 2] },
  { key: 'degree', label: 'max degree', value: '165', trend: [120, 130, 140, 150, 160, 165] },
  { key: 'dead', label: 'dead code', value: '0' },
  { key: 'drifted', label: 'drifted', value: '10', tone: 'accent', press: 'drifted', inSummary: true },
  { key: 'policy', label: 'policy', value: '3', tone: 'alert' },
  { key: 'diagnostics', label: 'diagnostics', value: '0' },
]

describe('stat tiles', () => {
  it('never draw a row wider than the pane, at any width', () => {
    for (const columns of WIDTHS) {
      const tier = columns < 80 ? 'narrow' : columns <= 130 ? 'medium' : 'wide'
      for (const r of tileRows(STATS, columns, tier)) expect(rowWidth(r), `${columns} ${r.key}: ${rawText(r)}`).toBeLessThanOrEqual(columns)
    }
  })

  it('collapse to a line of every figure when narrow, the header saying none of them', () => {
    const rows = tileRows(STATS, 60, 'narrow')
    expect(rows.every(r => r.key.startsWith('tiles-line'))).toBe(true)
    const text = rows.map(rawText).join('\n')
    expect(text).toContain('9,026 components')
    expect(text).toContain('drifted')
    expect(rawText(rows[0]!)).toMatch(/^9,026 components {3}7 boundaries {3}2 cycles ▁▃▃▅█▆▆▅/)
    // The deviating figures keep their colour on the line.
    expect(rows.flatMap(r => r.segments).find(s => s.text === '3')).toMatchObject({ color: 'error', bold: true })
  })

  it('sit on one line, a rule between each, when every tile fits at its own width', () => {
    const rows = tileRows(STATS, 100, 'medium')
    expect(rows.map(r => r.key)).toEqual(['tiles-top', 'tiles-0-value', 'tiles-0-label', 'tiles-end'])
    for (const r of rows) expect(rowWidth(r)).toBe(100)
    expect(plainText(rows[2]!)).toMatch(/^components +│ boundaries +│ cycles +│ max degree +│ dead code +│ drifted +│ policy +│ diagnostics$/)
  })

  it('wrap onto as few lines as hold them, the same number on each, their rules in columns', () => {
    const { per, widths } = tileSlots(STATS, 76)
    expect(per).toBe(4)
    expect(new Set(widths).size).toBeLessThanOrEqual(2)
    const rows = tileRows(STATS, 80, 'medium')
    expect(rows.map(r => r.key)).toEqual(['tiles-top', 'tiles-0-value', 'tiles-0-label', 'tiles-gap-1', 'tiles-1-value', 'tiles-1-label', 'tiles-end'])
    const rules = (key: string) => [...rawText(rows.find(r => r.key === key)!)].flatMap((ch, i) => (ch === '│' ? [i] : []))
    expect(rules('tiles-0-value')).toEqual(rules('tiles-1-label'))
  })

  it('colour a figure only when it deviates, and spread the band across the wide pane', () => {
    const rows = tileRows(STATS, 200, 'wide')
    const figures = rows.find(r => r.key === 'tiles-0-value')!.segments
    expect(figures.find(s => s.text === '9,026')).toMatchObject({ color: 'text', bold: true })
    expect(figures.find(s => s.text === '2')).toMatchObject({ color: 'warning' })
    expect(figures.find(s => s.text === '10')).toMatchObject({ color: 'suggestion' })
    expect(figures.find(s => s.text === '3')).toMatchObject({ color: 'error' })
    expect(figures.filter(s => s.text === '0').every(s => s.color === 'text')).toBe(true)
    // Room enough: each moving series draws its sparkline in its tile, dim.
    expect(figures.find(s => /^[▁-█]+$/.test(s.text))).toMatchObject({ dim: true })
    // The drifted label is the press that lists the drifted files.
    expect(rows.find(r => r.key === 'tiles-0-label')!.segments.find(s => s.press)?.press).toEqual({ id: 'drifted', label: 'drifted' })
  })

  it('draw nothing without figures, and frame themselves as a bare section', () => {
    expect(tileRows([], 100, 'medium')).toEqual([])
    expect(tilesBlock([], 'medium').make(96, 0)).toBeNull()
    const section = tilesBlock(STATS, 'medium').make(96, 0)!
    expect(section.bare).toBe(true)
    expect(rowWidth(section.body[0]!)).toBe(100)
  })

  it('read a series as moving only with five points that differ', () => {
    expect(moves([1, 2, 3, 4])).toBe(false)
    expect(moves([2, 2, 2, 2, 2])).toBe(false)
    expect(moves([2, 2, 2, 2, 3])).toBe(true)
    expect(moves(undefined)).toBe(false)
  })
})

describe('trend chart', () => {
  const series = { label: 'cycles', values: [0, 1, 1, 2, 4, 3, 3, 2, 2, 2] }

  it('draws a column per point, as tall as asked, its range on the axis', () => {
    const rows = chartRows(series, 60, 4, 'c')!
    expect(rows).toHaveLength(5)
    expect(rows.slice(0, 4).every(r => r.raster === 'trend')).toBe(true)
    // The axis stands right of a gutter as wide as the label under it.
    expect(rawText(rows[0]!)).toMatch(/^ {3} {5}4 ┤/)
    expect(rawText(rows[3]!)).toMatch(/^ {3} {5}0 ┤/)
    // The highest point reaches the top row whole; the lowest keeps an eighth on the bottom row.
    expect(rawText(rows[0]!)).toContain('█')
    const plot = rawText(rows[3]!).indexOf('┤') + 1
    expect(rawText(rows[3]!).slice(plot, plot + 1)).toBe('▁')
    expect(plainText(rows[4]!).trim()).toBe('cycles')
    for (const r of rows) expect(rowWidth(r)).toBeLessThanOrEqual(60)
  })

  it('keeps every row within the width, and draws nothing with no room for five points', () => {
    for (const columns of WIDTHS) for (const r of chartRows(series, columns, 6, 'c') ?? []) expect(cells(rawText(r))).toBeLessThanOrEqual(columns)
    expect(chartRows(series, 14, 4, 'c')).toBeNull()
    expect(chartRows({ label: 'flat', values: [3, 3, 3, 3, 3, 3] }, 60, 4, 'c')).toBeNull()
  })

  it('grows late: nothing without spare rows, a row taller per row given', () => {
    const block = trendBlock([series, { label: 'flat', values: [1, 1, 1, 1, 1] }])!
    expect(block.grow).toMatchObject({ min: 0, late: true })
    expect(block.make(60, 0)).toBeNull()
    const small = block.make(60, 1)!
    const large = block.make(60, 3)!
    expect(large.body.length - small.body.length).toBe(2)
    // The flat series draws no chart.
    expect(small.body.some(r => r.key.startsWith('trend-1'))).toBe(false)
    expect(trendBlock([{ label: 'flat', values: [1, 1, 1, 1, 1] }])).toBeNull()
  })
})
