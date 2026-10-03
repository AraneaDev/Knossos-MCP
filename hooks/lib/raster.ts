/**
 * Rows of styled segments as the cells of a terminal `Raster`: each cell a
 * code point, a foreground and a background, packed as the engine reads them
 * (little-endian u32 triplets, base64).
 *
 * Pure. A segment with a `cell` draws its own glyph on that background; any
 * other draws its text in its colour on the terminal's default background.
 * Bold has no cell form and is dropped.
 *
 * A Raster takes raw colours only, where `Text` takes theme keys. So the few
 * theme keys the pane draws a grid with are resolved here, from the values
 * Claude Code's dark and light themes give them; a daltonized or ANSI theme
 * reads as its dark or light one. The heat map's four steps are the accent
 * laid over the theme's background at rising strength.
 */
import type { Row } from './rows'

/** The engine's "terminal default" colour: bit 24 alone. */
export const DEFAULT_COLOUR = 0x01000000
const BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

export type RasterGrid = { columns: number; rows: number; cells: string }

/** How strongly each heat step lays the accent over the background, fewest first. */
export const HEAT_STRENGTH = [0.22, 0.42, 0.66, 1] as const
/** The heat map's background keys, fewest dependencies first. */
export const HEAT_KEYS = ['heat-1', 'heat-2', 'heat-3', 'heat-4'] as const

type Palette = Record<string, number>

/** The keys both themes share: the eight subagent colours are the same in each. */
const CATEGORICAL: Palette = {
  blue_FOR_SUBAGENTS_ONLY: 0x6a9bcc,
  purple_FOR_SUBAGENTS_ONLY: 0x827dbd,
  cyan_FOR_SUBAGENTS_ONLY: 0x0891b2,
  orange_FOR_SUBAGENTS_ONLY: 0xd97757,
  pink_FOR_SUBAGENTS_ONLY: 0xc46686,
  green_FOR_SUBAGENTS_ONLY: 0x16a34a,
  yellow_FOR_SUBAGENTS_ONLY: 0xca8a04,
  red_FOR_SUBAGENTS_ONLY: 0xdc2626,
}

/** `over` laid on `base` at `strength`, channel by channel. */
export function blend(base: number, over: number, strength: number): number {
  const channel = (shift: number) => {
    const b = (base >> shift) & 0xff
    const o = (over >> shift) & 0xff
    return Math.round(b + (o - b) * strength) << shift
  }
  return channel(16) | channel(8) | channel(0)
}

/** A theme's grid colours: its own keys plus the heat steps over `background`. */
function palette(keys: Palette, background: number): Palette {
  const heat = Object.fromEntries(HEAT_KEYS.map((key, i) => [key, blend(background, keys.suggestion!, HEAT_STRENGTH[i]!)]))
  return { ...CATEGORICAL, ...keys, ...heat }
}

/** Claude Code's dark and light theme values for the keys a grid draws with. */
export const RASTER_THEMES: Record<'dark' | 'light', Palette> = {
  dark: palette(
    { text: 0xffffff, inactive: 0x999999, subtle: 0x505050, suggestion: 0xb1b9f9, success: 0x4eba65, warning: 0xffc107, error: 0xff6b80 },
    0x1e1e1e,
  ),
  light: palette(
    { text: 0x000000, inactive: 0x666666, subtle: 0xafafaf, suggestion: 0x5769f7, success: 0x2c7a39, warning: 0x966c1e, error: 0xab2b3f },
    0xffffff,
  ),
}

/** The grid colours for a theme by its name (`dark`, `light-daltonized`, ...); dark when unknown. */
export function rasterTheme(name: string | null | undefined): Palette {
  return typeof name === 'string' && name.startsWith('light') ? RASTER_THEMES.light : RASTER_THEMES.dark
}

/** A colour as the Raster takes it: a theme key of `theme`, or `#rrggbb`; else the default. */
export function colourValue(colour: string | undefined, theme: Palette = RASTER_THEMES.dark): number {
  if (colour === undefined) return DEFAULT_COLOUR
  if (/^#[0-9a-f]{6}$/i.test(colour)) return parseInt(colour.slice(1), 16)
  return theme[colour] ?? DEFAULT_COLOUR
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

/** The rows as one grid `columns` wide in `theme`'s colours, each row padded with default cells. */
export function rasterOf(rows: Row[], columns: number, theme: Palette = RASTER_THEMES.dark): RasterGrid {
  const width = Math.max(1, columns)
  const words = new Uint32Array(width * Math.max(1, rows.length) * 3)
  for (let i = 0; i < words.length; i += 3) words.set([0x20, DEFAULT_COLOUR, DEFAULT_COLOUR], i)
  rows.forEach((row, y) => {
    let x = 0
    for (const s of row.segments) {
      const fg = colourValue(s.cell?.fg ?? s.color ?? (s.dim ? 'inactive' : undefined), theme)
      const bg = colourValue(s.cell?.bg, theme)
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
