import { describe, expect, it } from 'vitest'
import { rowWidth } from './rows'
import { deltaSegment, moves, tileRows, tileSlots, tilesBlock } from './tiles'
import type { Stat } from './tiles'
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
    // Room enough: each moving series draws its sparkline in its tile, in the accent (the data's one hue).
    expect(figures.find(s => /^[▁-█]+$/.test(s.text))).toMatchObject({ color: 'suggestion' })
    // The drifted label is the press that lists the drifted files.
    expect(rows.find(r => r.key === 'tiles-0-label')!.segments.find(s => s.press)?.press).toEqual({ id: 'drifted', label: 'drifted' })
  })

  it('say how each figure moved since the previous snapshot: a status in its colours, anything else dim', () => {
    const moved: Stat[] = [
      { key: 'components', label: 'components', value: '9,186', delta: 12 },
      { key: 'cycles', label: 'cycles', value: '2', tone: 'warn', delta: 1, worse: 'up' },
      { key: 'dead', label: 'dead code', value: '3', delta: -2, worse: 'up' },
      { key: 'degree', label: 'max degree', value: '165', delta: 0 },
      { key: 'drifted', label: 'drifted', value: '0' },
    ]
    expect(deltaSegment(moved[0]!)).toEqual({ text: '▲12', dim: true })
    expect(deltaSegment(moved[1]!)).toEqual({ text: '▲1', color: 'warning' })
    expect(deltaSegment(moved[2]!)).toEqual({ text: '▼2', color: 'success' })
    expect(deltaSegment(moved[3]!)).toEqual({ text: '±0', dim: true })
    expect(deltaSegment(moved[4]!)).toBeNull()
    expect(deltaSegment({ delta: 12_345 })?.text).toBe('▲12,345')
    for (const columns of WIDTHS) {
      const tier = columns < 80 ? 'narrow' : columns <= 130 ? 'medium' : 'wide'
      const rows = tileRows(moved, columns, tier)
      for (const r of rows) expect(rowWidth(r), `${columns} ${r.key}`).toBeLessThanOrEqual(columns)
      expect(rows.map(rawText).join('\n'), `${columns}`).toMatch(/9,186 (components )?▲12/)
    }
    // The delta follows the figure, a space apart, on the tile's first line.
    expect(rawText(tileRows(moved, 140, 'wide').find(r => r.key === 'tiles-0-value')!)).toMatch(/^│ 9,186 ▲12 +│ 2 ▲1 +│ 3 ▼2 +│ 165 ±0 +│ 0 +│$/)
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
