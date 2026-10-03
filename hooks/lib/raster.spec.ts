import { Buffer } from 'node:buffer'
import { describe, expect, it } from 'vitest'
import { base64, colourValue, DEFAULT_COLOUR, rasterOf } from './raster'

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
  it('reads #rrggbb, maps the status names and leaves anything else to the terminal', () => {
    expect(colourValue('#7aa2f7')).toBe(0x7aa2f7)
    expect(colourValue('red')).not.toBe(DEFAULT_COLOUR)
    expect(colourValue(undefined)).toBe(DEFAULT_COLOUR)
    expect(colourValue('chartreuse')).toBe(DEFAULT_COLOUR)
  })
})

describe('rasterOf', () => {
  it('packs every cell as code point, foreground and background, rows padded to the width', () => {
    const grid = rasterOf(
      [
        { key: 'a', segments: [{ text: 'A', color: '#7aa2f7' }, { text: '██', color: '#7aa2f7', cell: { glyph: ' ', bg: '#3c4c6e' } }] },
        { key: 'b', segments: [{ text: '·', dim: true }] },
      ],
      4,
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
    expect(cells[4]).toEqual([0xb7, 0x808080, DEFAULT_COLOUR])
  })
  it('cuts a row at the width and draws a character it cannot place as ?', () => {
    const cells = decode(rasterOf([{ key: 'a', segments: [{ text: 'ab\u0007😀cd' }] }], 4).cells)
    expect(cells.map(c => String.fromCodePoint(c[0]!)).join('')).toBe('ab??')
  })
})
