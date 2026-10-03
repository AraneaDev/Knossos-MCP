import { describe, expect, it } from 'vitest'
import { arrange, besideRows, cardInner, cardRows, DEFAULT_ROWS, fitBlocks, gridColumns, moreRows, paneHeight, topRow, windowOf } from './cards'
import type { Block, Section } from './cards'
import { rawText } from './__tests__/plain-text'
import { barRange, button, rowWidth, tableSpec, tierOf } from './rows'
import type { Row, Tier } from './rows'

const WIDTHS = [40, 60, 80, 100, 130, 140, 200] as const
const HEIGHTS = [16, 24, 40, 60, 120] as const

const line = (key: string, text: string, extra: Partial<Row> = {}): Row => ({ key, segments: [{ text }], ...extra })

/** A card listing `length` items, `limit` at a time around `selected`, as the pane's lists do. */
const listBlock = (key: string, length: number, min: number, selected = -1): Block => ({
  key,
  grow: { length, min },
  make: (columns, limit) => {
    const window = windowOf(length, limit, selected)
    const body = Array.from({ length: window.end - window.start }, (_, n) => line(`${key}-${window.start + n}`, `item ${window.start + n}`.padEnd(Math.min(columns, 30))))
    return { key, title: `List ${key}`, note: [{ text: `${length}`, dim: true }], body: [...body, ...moreRows(`${key}-more`, window, length, columns)] }
  },
})
const fixedBlock = (key: string, rows: number): Block => ({
  key,
  make: columns => ({ key, title: 'Fixed', body: Array.from({ length: rows }, (_, i) => line(`${key}-${i}`, 'x'.repeat(Math.min(columns, 12)))) }),
})
const items = (rows: Row[], key: string) => rows.filter(r => r.key.split('|').some(k => new RegExp(`^${key}-\\d+$`).test(k)))

describe('tiers', () => {
  it('switch at 80 and past 130', () => {
    expect([40, 79, 80, 100, 130, 131, 200].map(tierOf)).toEqual(['narrow', 'narrow', 'medium', 'medium', 'medium', 'wide', 'wide'])
  })
  it('frame medium and wide cards, and leave a narrow card its every column', () => {
    expect(cardInner(60, 'narrow')).toBe(60)
    expect(cardInner(100, 'medium')).toBe(96)
    expect(cardInner(140, 'wide')).toBe(136)
  })
})

describe('cards', () => {
  const section: Section = { key: 's', title: 'Most depended on', subtitle: 'sorted by in', note: [{ text: 'all in core · in', dim: true }], body: [line('s-0', 'StableId'), line('s-1', '›  ArchitectureQueryService')] }

  for (const columns of WIDTHS) {
    it(`exactly fill ${columns} columns with their edges, framed or not by the tier`, () => {
      const tier = tierOf(columns)
      const rows = cardRows(section, columns, tier)
      expect(rowWidth(rows[0]!)).toBe(columns)
      if (tier === 'narrow') {
        // A top rule with the title in it, nothing at the sides and no bottom edge.
        expect(rawText(rows[0]!)).toMatch(/^── Most depended/)
        expect(rawText(rows[1]!)).toBe('StableId')
        expect(rows).toHaveLength(3)
      } else {
        expect(rawText(rows[0]!)).toMatch(/^╭─ Most depended on .*─╮$/)
        for (const r of rows.slice(1, -1)) {
          expect(rowWidth(r), r.key).toBe(columns)
          expect(rawText(r)).toMatch(/^│ .* │$/)
        }
        expect(rawText(rows.at(-1)!)).toBe(`╰${'─'.repeat(columns - 2)}╯`)
      }
    })
  }

  it('set the note against the right corner, and give way: subtitle, then the title is cut, then the note', () => {
    expect(rawText(topRow(section, 100, 'medium'))).toMatch(/^╭─ Most depended on · sorted by in ─+ all in core · in ─╮$/)
    expect(rawText(topRow(section, 44, 'narrow'))).toMatch(/^── Most depended on ─+ all in core · in ──$/)
    expect(rawText(topRow(section, 40, 'narrow'))).toBe('── Most depended… ── all in core · in ──')
    expect(rawText(topRow(section, 34, 'narrow'))).toMatch(/^── Most depended on ─+$/)
    const tiny = topRow(section, 20, 'narrow')
    expect(rowWidth(tiny)).toBeLessThanOrEqual(20)
    expect(rawText(tiny)).toContain('Most')
  })

  it('cut a body row too wide for the frame, and leave a diff element unframed', () => {
    const rows = cardRows({ key: 'd', title: 'Diff', body: [line('d-0', 'y'.repeat(200)), { key: 'd-hunk', segments: [], code: { source: '@@ -1 +1 @@\n-a\n+b', path: 'a.ts' } }] }, 100, 'medium')
    expect(rowWidth(rows[1]!)).toBe(100)
    expect(rows[2]).toEqual({ key: 'd-hunk', segments: [], code: { source: '@@ -1 +1 @@\n-a\n+b', path: 'a.ts' } })
  })
})

describe('the wide grid', () => {
  it('splits the width into two columns two apart', () => {
    for (const columns of [131, 140, 200]) {
      const [l, r] = gridColumns(columns)
      expect(l + r + 2).toBe(columns)
      expect(Math.abs(l - r)).toBeLessThanOrEqual(1)
    }
  })
  it('puts every right card in the right half, the shorter column padded, no row wider than the pane', () => {
    for (const columns of [140, 200]) {
      const rows = arrange({ left: [fixedBlock('a', 2)], right: [fixedBlock('b', 6)] }, columns, 40)
      const [lw] = gridColumns(columns)
      for (const r of rows) expect(rowWidth(r)).toBeLessThanOrEqual(columns)
      const right = rows.find(r => r.key.endsWith('|b-5'))!
      expect(right.key).toMatch(/^pad-l-\d+\|b-5$/)
      expect(rawText(right).indexOf('│')).toBe(lw + 2)
      expect(rowWidth(right)).toBe(columns)
    }
  })
  it('stacks the same cards in one column below the wide tier, in their own order when given', () => {
    const rows = arrange({ top: [fixedBlock('t', 1)], left: [fixedBlock('a', 1)], right: [fixedBlock('b', 1)], order: [fixedBlock('b', 1), fixedBlock('a', 1)] }, 100, 40)
    expect(rows.some(r => r.key.includes('|'))).toBe(false)
    expect(rows.findIndex(r => r.key === 'b-head')).toBeLessThan(rows.findIndex(r => r.key === 'a-head'))
    expect(rows.find(r => r.key === 't-head')).toBeUndefined()
  })
  it('draws a heat map as text where it shares rows with something pressable, as raster cells otherwise', () => {
    const heat = (key: string): Row => ({ key, raster: 'heat', segments: [{ text: '▓▓', cell: { glyph: '▇', fg: 'heat-3' } }] })
    const press = { key: 'p', segments: [button('row:0', 'open me')] }
    expect(besideRows([press], [heat('h-0'), heat('h-1')], 20).every(r => r.raster === undefined)).toBe(true)
    expect(besideRows([line('q', 'quiet')], [heat('h-0'), heat('h-1')], 20).every(r => r.raster === 'heat')).toBe(true)
  })
})

describe('lists sized to the height', () => {
  for (const columns of WIDTHS) {
    for (const height of HEIGHTS) {
      it(`fill ${height} rows at ${columns} columns without passing them, past their minimum`, () => {
        const blocks = [fixedBlock('f', 3), listBlock('a', 50, 3), listBlock('b', 8, 2)]
        const rows = arrange({ left: blocks.slice(0, 2), right: blocks.slice(2) }, columns, height)
        for (const r of rows) expect(rowWidth(r), `${r.key}: ${rawText(r)}`).toBeLessThanOrEqual(columns)
        const shown = items(rows, 'a').length
        expect(shown).toBeGreaterThanOrEqual(3)
        // Grown only while the whole fits: the minimum may pass a short pane, a grown list never does.
        if (shown > 3) expect(rows.length).toBeLessThanOrEqual(height)
        // A cut list says how many more there are.
        if (shown < 50) expect(rows.some(r => /\d+ more ↓/.test(rawText(r)))).toBe(true)
      })
    }
  }
  it('grow with the height, and a taller pane shows at least as many', () => {
    let last = 0
    for (const height of HEIGHTS) {
      const shown = items(fitBlocks([listBlock('a', 200, 3)], 80, 'medium', height), 'a').length
      expect(shown).toBeGreaterThanOrEqual(last)
      last = shown
    }
    expect(last).toBeGreaterThan(100)
  })
  it('share the rows between the lists in turn, each stopping at its length', () => {
    const rows = fitBlocks([listBlock('a', 4, 2), listBlock('b', 100, 2)], 60, 'narrow', 40)
    expect(items(rows, 'a')).toHaveLength(4)
    expect(items(rows, 'b').length).toBeGreaterThan(20)
    expect(rows.length).toBeLessThanOrEqual(40)
  })
  it('keep the marked row in sight, with how many are above and below', () => {
    const rows = fitBlocks([listBlock('a', 30, 3, 20)], 60, 'narrow', 10)
    const shown = items(rows, 'a').map(r => r.key)
    expect(shown).toContain('a-20')
    expect(rawText(rows.find(r => r.key === 'a-more')!)).toMatch(/^ {3}\d+ above ↑ · \d+ more ↓$/)
  })
  it('read the height from the pane, with a default where the surface gives none', () => {
    expect(paneHeight({ bodyRows: 33 })).toBe(33)
    expect(paneHeight({ bodyRows: 0 })).toBe(DEFAULT_ROWS)
    expect(paneHeight(undefined)).toBe(DEFAULT_ROWS)
  })
  it('window a list around the marker', () => {
    expect(windowOf(10, 20)).toEqual({ start: 0, end: 10 })
    expect(windowOf(10, 4)).toEqual({ start: 0, end: 4 })
    expect(windowOf(10, 4, 6)).toEqual({ start: 3, end: 7 })
    expect(windowOf(10, 4, 9)).toEqual({ start: 6, end: 10 })
  })
})

describe('bars', () => {
  const tiers: Tier[] = ['narrow', 'medium', 'wide']
  it('take a share of the table between a minimum and a maximum per tier, with no fixed cap', () => {
    for (const tier of tiers) {
      for (const columns of [40, 60, 100, 136, 196]) {
        const range = barRange(columns, tier)
        const spec = tableSpec(columns, ['Short'], ['core'], [3])
        expect(range.min).toBeLessThanOrEqual(range.max)
        const withTier = tableSpec(columns, ['Short'], ['core'], [3], undefined, { tier })
        expect(withTier.bar).toBeLessThanOrEqual(range.max)
        expect(withTier.bar).toBeGreaterThanOrEqual(range.min)
        expect(spec.bar).toBeGreaterThan(0)
      }
    }
    // Wider tiers draw longer gauges: past the old 24-cell cap.
    expect(tableSpec(196, ['Short'], ['core'], [3], undefined, { tier: 'wide' }).bar).toBeGreaterThan(24)
  })
  it('give way to a file column only while names stay whole, and drop it first', () => {
    const places = ['ArchitectureQueryService.php:22']
    expect(tableSpec(120, ['ArchitectureQueryService'], ['core'], [3, 3, 5], undefined, { tier: 'medium', places }).place).toBe(31)
    expect(tableSpec(60, ['ArchitectureQueryService'], ['core'], [3, 3, 5], undefined, { tier: 'medium', places }).place).toBeUndefined()
  })
})
