import { Buffer } from 'node:buffer'
import { describe, expect, it } from 'vitest'
import { base64, blend, colourValue, DEFAULT_COLOUR, HEAT_KEYS, RASTER_THEMES, rasterOf, rasterTheme } from './raster'

/** The grid's cells back as [codePoint, fg, bg] triplets. */
const decode = (cells: string): number[][] => {
  const bytes = Buffer.from(cells, 'base64')
  const out: number[][] = []
  for (let i = 0; i < bytes.length; i += 12) out.push([bytes.readUInt32LE(i), bytes.readUInt32LE(i + 4), bytes.readUInt32LE(i + 8)])
  return out
}

describe('base64', () => {
  it('encodes as the standard padded alphabet does', () => {
    for (const text of ['', 'a', 'ab', 'abc', 'abcd', 'ÿþý']) {
      const bytes = Uint8Array.from([...text].map(c => c.charCodeAt(0)))
      expect(base64(bytes)).toBe(Buffer.from(bytes).toString('base64'))
    }
  })
})

describe('colourValue', () => {
  it('reads #rrggbb, resolves theme keys in the theme and leaves anything else to the terminal', () => {
    expect(colourValue('#7aa2f7')).toBe(0x7aa2f7)
    expect(colourValue('error')).toBe(0xff6b80)
    expect(colourValue('error', RASTER_THEMES.light)).toBe(0xab2b3f)
    expect(colourValue('blue_FOR_SUBAGENTS_ONLY')).toBe(0x6a9bcc)
    expect(colourValue(undefined)).toBe(DEFAULT_COLOUR)
    expect(colourValue('chartreuse')).toBe(DEFAULT_COLOUR)
  })
})

describe('rasterTheme', () => {
  it('reads a light theme as light and every other one, or none, as dark', () => {
    expect(rasterTheme('light')).toBe(RASTER_THEMES.light)
    expect(rasterTheme('light-daltonized')).toBe(RASTER_THEMES.light)
    expect(rasterTheme('dark-ansi')).toBe(RASTER_THEMES.dark)
    expect(rasterTheme(undefined)).toBe(RASTER_THEMES.dark)
  })
  it('steps the heat map from the background to the accent, one hue', () => {
    for (const theme of Object.values(RASTER_THEMES)) {
      const steps = HEAT_KEYS.map(k => theme[k]!)
      expect(steps[3]).toBe(theme.suggestion)
      expect(new Set(steps).size).toBe(4)
    }
    // On a dark theme each step is brighter than the last; on a light one darker.
    const sum = (c: number) => ((c >> 16) & 0xff) + ((c >> 8) & 0xff) + (c & 0xff)
    const dark = HEAT_KEYS.map(k => sum(RASTER_THEMES.dark[k]!))
    const light = HEAT_KEYS.map(k => sum(RASTER_THEMES.light[k]!))
    expect(dark).toEqual([...dark].sort((a, b) => a - b))
    expect(light).toEqual([...light].sort((a, b) => b - a))
  })
  it('blends channel by channel', () => {
    expect(blend(0x000000, 0xffffff, 0.5)).toBe(0x808080)
    expect(blend(0x102030, 0x405060, 0)).toBe(0x102030)
    expect(blend(0x102030, 0x405060, 1)).toBe(0x405060)
  })
})

describe('rasterOf', () => {
  it('packs every cell as code point, foreground and background, rows padded to the width', () => {
    const grid = rasterOf(
      [
        { key: 'a', segments: [{ text: 'A', color: '#7aa2f7' }, { text: '██', color: '#7aa2f7', cell: { glyph: ' ', bg: '#3c4c6e' } }] },
        { key: 'b', segments: [{ text: '·', dim: true }, { text: 'x', color: 'error' }, { text: '▒', color: 'suggestion', cell: { glyph: '▇', fg: 'heat-2' } }] },
      ],
      4,
      RASTER_THEMES.light,
    )
    expect(grid.columns).toBe(4)
    expect(grid.rows).toBe(2)
    const cells = decode(grid.cells)
    expect(cells).toHaveLength(8)
    expect(cells[0]).toEqual([0x41, 0x7aa2f7, DEFAULT_COLOUR])
    // A cell draws its own glyph on its background.
    expect(cells[1]).toEqual([0x20, 0x7aa2f7, 0x3c4c6e])
    expect(cells[2]).toEqual([0x20, 0x7aa2f7, 0x3c4c6e])
    expect(cells[3]).toEqual([0x20, DEFAULT_COLOUR, DEFAULT_COLOUR])
    // Theme keys resolve in the theme given: dim is `inactive`; a cell's own foreground wins.
    expect(cells[4]).toEqual([0xb7, 0x666666, DEFAULT_COLOUR])
    expect(cells[5]).toEqual([0x78, 0xab2b3f, DEFAULT_COLOUR])
    expect(cells[6]).toEqual([0x2587, RASTER_THEMES.light['heat-2'], DEFAULT_COLOUR])
  })
  it('cuts a row at the width and draws a character it cannot place as ?', () => {
    const cells = decode(rasterOf([{ key: 'a', segments: [{ text: 'ab\u0007😀cd' }] }], 4).cells)
    expect(cells.map(c => String.fromCodePoint(c[0]!)).join('')).toBe('ab??')
  })
})
