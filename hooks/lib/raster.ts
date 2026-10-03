/**
 * Rows of styled segments as the cells of a terminal `Raster`: each cell a
 * code point, a foreground and a background, packed as the engine reads them
 * (little-endian u32 triplets, base64).
 *
 * Pure. A segment with a `cell` draws its own glyph on that background; any
 * other draws its text in its colour on the terminal's default background.
 * Status colour names map to fixed values, dim text to grey; bold has no
 * cell form and is dropped.
 */
import type { Row } from './rows'

/** The engine's "terminal default" colour: bit 24 alone. */
export const DEFAULT_COLOUR = 0x01000000
const DIM = 0x808080
const NAMED: Record<string, number> = { red: 0xe0524c, green: 0x3fb950, yellow: 0xd29922 }
const BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

export type RasterGrid = { columns: number; rows: number; cells: string }

/** A colour as the Raster takes it: `#rrggbb` or a status name, else the default. */
export function colourValue(colour: string | undefined): number {
  if (colour === undefined) return DEFAULT_COLOUR
  if (/^#[0-9a-f]{6}$/i.test(colour)) return parseInt(colour.slice(1), 16)
  return NAMED[colour] ?? DEFAULT_COLOUR
}

/** A printable, one-cell code point from the Basic Multilingual Plane; anything else draws as `?`. */
function printable(char: string): number {
  const code = char.codePointAt(0) ?? 0x3f
  return code < 0x20 || (code >= 0x7f && code < 0xa0) || code > 0xffff ? 0x3f : code
}

/** Standard padded base64 of `bytes`. */
export function base64(bytes: Uint8Array): string {
  let out = ''
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i]!
    const b = bytes[i + 1]
    const c = bytes[i + 2]
    const n = (a << 16) | ((b ?? 0) << 8) | (c ?? 0)
    out += BASE64[(n >> 18) & 63]! + BASE64[(n >> 12) & 63]!
    out += b === undefined ? '=' : BASE64[(n >> 6) & 63]!
    out += c === undefined ? '=' : BASE64[n & 63]!
  }
  return out
}

/** The rows as one grid `columns` wide, each row padded with default cells. */
export function rasterOf(rows: Row[], columns: number): RasterGrid {
  const width = Math.max(1, columns)
  const words = new Uint32Array(width * Math.max(1, rows.length) * 3)
  for (let i = 0; i < words.length; i += 3) words.set([0x20, DEFAULT_COLOUR, DEFAULT_COLOUR], i)
  rows.forEach((row, y) => {
    let x = 0
    for (const s of row.segments) {
      const fg = s.dim ? DIM : colourValue(s.color)
      const bg = s.cell === undefined ? DEFAULT_COLOUR : colourValue(s.cell.bg)
      for (const char of s.text) {
        if (x >= width) break
        words.set([printable(s.cell?.glyph ?? char), fg, bg], (y * width + x) * 3)
        x++
      }
    }
  })
  // Little-endian whatever the machine: the bytes are laid out by hand.
  const bytes = new Uint8Array(words.length * 4)
  words.forEach((w, i) => {
    bytes[i * 4] = w & 0xff
    bytes[i * 4 + 1] = (w >>> 8) & 0xff
    bytes[i * 4 + 2] = (w >>> 16) & 0xff
    bytes[i * 4 + 3] = (w >>> 24) & 0xff
  })
  return { columns: width, rows: Math.max(1, rows.length), cells: base64(bytes) }
}
