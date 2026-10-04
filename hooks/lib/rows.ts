/**
 * The drawing primitives every view of the pane shares: styled segments,
 * rows, cutting text to a width, bars, and the table that fits itself to the
 * columns it has. Pure; nothing here reads state.
 *
 * Widths are counted in code points. Every glyph the pane draws (blocks,
 * bullets, arrows, the ellipsis) is one cell wide, and names come from source
 * identifiers, so a code point is a cell.
 *
 * Bars are thin rules (`━`, in half cells) on a faint dotted track, so a
 * column of them reads as a set of gauges rather than a block of colour.
 *
 * A table is packed to the left: names take what the longest needs (up to a
 * cap), the kind (where the table has one) and the boundary follow them,
 * then the bar, the numbers and, where the table has one, the file each row
 * is declared in. The bar takes the width left after the names and numbers,
 * within a minimum and a maximum share of the table and a cap in cells that
 * depend on the pane's {@link Tier}; width past that stays at the right
 * edge, never between a name and its boundary. As the width shrinks it gives
 * way in a fixed order: the kind goes, the file column goes, the bars
 * shorten to their minimum, then names are cut with an ellipsis, then the
 * boundary column goes, then the bars go. The numbers always stay.
 */
import { ACCENT, boundaryColour, boundaryLabel, FAINT, FLASH_BG, HEADING, NO_HUES, SECONDARY, SELECTED_BG } from './palette'
import type { Hues } from './palette'

/** A pressable segment: drawn as a plain Button, `hotkey: label` when it has a hotkey. */
export type Press = { id: string; label: string; hotkey?: string }
/**
 * A place in a file: an absolute path and, when known, a line. A segment
 * that carries one is drawn as a link the person can click to open it.
 */
export type Loc = { path: string; line: number | null }
/** A one-line text field: drawn as an Input keyed `id`, holding `value`. */
export type Field = { id: string; value: string; placeholder: string }
/**
 * How a segment draws as cells of a terminal `Raster` instead of as text:
 * each of its cells shows `glyph` in `fg` (else the segment's colour) on
 * `bg` (else the terminal's own); colours are theme keys the Raster
 * resolves, or `#rrggbb`.
 */
export type Cell = { glyph: string; fg?: string; bg?: string }
/**
 * A card that shows while the pointer rests on the row it belongs to (where
 * the surface has a pointer): its rows, and how wide it is. It never takes a
 * row of the pane: it is drawn over the rows below its own.
 */
export type Preview = { key: string; rows: Row[]; width: number }

/**
 * A run of text in one style. `color` is a Claude Code theme key (see
 * `palette.ts`); `dim` marks secondary text, drawn in the theme's `inactive`;
 * `bg` a theme key laid under it. `hidden` marks a pressable with no text
 * that is drawn out of sight: it exists only so its `press.hotkey` keeps
 * working. `preview` hangs a hover card off the segment's row.
 */
export type Segment = { text: string; color?: string; dim?: boolean; bold?: boolean; bg?: string; press?: Press; field?: Field; cell?: Cell; link?: Loc; hidden?: true; preview?: Preview }
/**
 * One line of the pane. Consecutive rows with the same `raster` key form a
 * grid the terminal may draw as one `Raster`; every surface can draw their
 * segments as text instead.
 */
export type Row = {
  key: string
  segments: Segment[]
  raster?: string
  /** In a row of the wide grid, the index of the first segment of the right card's row: everything before it is the left card's and the gap. */
  split?: number
  /** Drawn as the engine's diff element instead of its segments: unified-diff hunks, and the file they are of (its language). */
  code?: { source: string; path: string }
  /** The background the whole row is tinted with (the marked row): a card's frame carries it to its inner edge. */
  tint?: string
}

export const BAR_MIN = 4

/**
 * The pane's three layouts, by the columns its body has: `narrow` (below 80)
 * one column with light cards, `medium` (80 to 130) one column of framed
 * cards whose tables add columns, `wide` (above 130) a two-column grid.
 */
export type Tier = 'narrow' | 'medium' | 'wide'
/** The first width of the medium tier, and the last. */
export const MEDIUM_MIN = 80
export const MEDIUM_MAX = 130
export const tierOf = (columns: number): Tier => (columns < MEDIUM_MIN ? 'narrow' : columns <= MEDIUM_MAX ? 'medium' : 'wide')

/**
 * The share of a table's width its bars take at least and at most, per tier,
 * and the most cells a bar takes however wide the table: a bar is a gauge
 * beside a name, never the widest thing on its row. Width a bar may not take
 * goes to more columns (a wide table adds the kind and the dependent files)
 * or stays at the right edge.
 */
export const BAR_SHARE: Record<Tier, { min: number; max: number; cap: number }> = {
  narrow: { min: 0.08, max: 0.25, cap: 16 },
  medium: { min: 0.1, max: 0.25, cap: 24 },
  wide: { min: 0.1, max: 0.25, cap: 30 },
}
/** How many cells a bar may take in a table `columns` wide at `tier`. */
export function barRange(columns: number, tier: Tier): { min: number; max: number } {
  const share = BAR_SHARE[tier]
  const max = Math.max(BAR_MIN, Math.min(share.cap, Math.floor(columns * share.max)))
  return { min: Math.min(max, Math.max(BAR_MIN, Math.floor(columns * share.min))), max }
}
/** The glyph a bar's empty part (its track) is drawn with, faint. */
export const TRACK = '·'
export const NAME_MIN = 8
const NAME_MAX = 32
const BOUNDARY_MAX = 12
/** The widest a file column grows. */
const PLACE_MAX = 36
/** The widest a kind column grows. */
const KIND_MAX = 12
/** The selection marker, the hotspot mark and a space. */
export const MARK = 3

export const cells = (text: string): number => [...text].length
export const rowWidth = (row: Row): number => row.segments.reduce((n, s) => n + cells(s.text), 0)
export const segmentsWidth = (segments: Segment[]): number => segments.reduce((n, s) => n + cells(s.text), 0)

/** `text` cut to `width` cells, the last one an ellipsis when anything was cut. */
export function fit(text: string, width: number): string {
  if (width <= 0) return ''
  const chars = [...text]
  return chars.length <= width ? text : `${chars.slice(0, width - 1).join('')}…`
}

/** `text` cut to `width` cells from the front, the first one an ellipsis: a path keeps its file name. */
export function fitStart(text: string, width: number): string {
  if (width <= 0) return ''
  const chars = [...text]
  return chars.length <= width ? text : `…${chars.slice(chars.length - width + 1).join('')}`
}

/**
 * A path fitted to `width` cells as its directory and its file name, for
 * drawing in two tones. A path that does not fit is cut in the middle of
 * its directory, never in the file name: whole folders first (the first
 * one, `…`, then as many of the last as fit: `src/…/Query/`; then `…` and
 * the folder the file is in; then the first folder and `…`), then the
 * directory's own middle; a file name wider than the whole width alone is
 * cut at its end.
 */
export function pathParts(path: string, width: number): { dir: string; base: string } {
  const cut = path.lastIndexOf('/') + 1
  const dir = path.slice(0, cut)
  const base = path.slice(cut)
  if (width <= 0) return { dir: '', base: '' }
  if (cells(path) <= width) return { dir, base }
  const room = width - cells(base)
  if (room < 2) return { dir: '', base: fit(base, width) }
  return { dir: middleCut(dir, room), base }
}

/** A path fitted to `width` cells as one text, cut as {@link pathParts} cuts it: where one tone is all there is. */
export const pathText = (path: string, width: number): string => {
  const { dir, base } = pathParts(path, width)
  return dir + base
}

/** A path as two segments fitted to `width`: its directory dim, its file name in `style` (a link to `loc` when given). */
export function pathSegments(path: string, width: number, loc: Loc | null = null, style: Omit<Segment, 'text' | 'link'> = {}): Segment[] {
  const { dir, base } = pathParts(path, width)
  return [{ text: dir, dim: true }, linked(base, loc, style)].filter(s => s.text !== '')
}

/** A directory (ending in `/`) in at most `room` cells, cut in its middle: by whole folders while they fit, else by characters. */
function middleCut(dir: string, room: number): string {
  const folders = dir.split('/').filter(f => f !== '')
  // The first folder, `…`, then the last `kept` folders, as many as fit.
  for (let kept = folders.length - 2; kept >= 1; kept--) {
    const text = `${folders[0]}/…/${folders.slice(folders.length - kept).map(f => `${f}/`).join('')}`
    if (cells(text) <= room) return text
  }
  // The folder the file is in says more than the first one.
  const last = folders.at(-1)
  if (last !== undefined && folders.length > 1 && cells(`…/${last}/`) <= room) return `…/${last}/`
  if (folders.length > 1 && cells(`${folders[0]}/…/`) <= room) return `${folders[0]}/…/`
  if (room < 4) return '…/'
  const chars = [...dir]
  const left = Math.ceil((room - 1) / 2)
  const right = room - 1 - left
  return `${chars.slice(0, left).join('')}…${right > 0 ? chars.slice(chars.length - right).join('') : ''}`
}

export const spaces = (n: number): string => ' '.repeat(Math.max(0, n))
export const padEnd = (text: string, width: number): string => text + spaces(width - cells(text))
export const padStart = (text: string, width: number): string => spaces(width - cells(text)) + text
export const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`
/** A count with thousands separated: 7,878. */
export const grouped = (n: number): string => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

/**
 * A count in at most five cells, for a narrow column: as it is below a
 * thousand, then in thousands or millions with one decimal while that is
 * under a hundred (`31.7k`, `1.2M`), whole past it (`317k`). A trailing `.0`
 * is dropped. Tiles and details keep the full {@link grouped} figure.
 */
export function compact(n: number): string {
  const sign = n < 0 ? '-' : ''
  const a = Math.abs(n)
  if (a < 1_000) return `${sign}${a}`
  const [scaled, unit] = a < 999_500 ? [a / 1_000, 'k'] : a < 999_500_000 ? [a / 1_000_000, 'M'] : [a / 1_000_000_000, 'G']
  const text = scaled < 99.95 ? (Math.round(scaled * 10) / 10).toFixed(1).replace(/\.0$/, '') : String(Math.round(scaled))
  return `${sign}${text}${unit}`
}
export const baseName = (path: string): string => path.slice(path.lastIndexOf('/') + 1)

/** A file and line as the pane prints a place: the file's own name, then the line. */
export function placeOf(path: string | null, line: number | null): string {
  if (path === null) return ''
  return line === null ? baseName(path) : `${baseName(path)}:${line}`
}

/** A project-relative path made absolute under `root`; an absolute path is kept. */
export function absolute(root: string, path: string): string {
  return path.startsWith('/') ? path : `${root.replace(/\/+$/, '')}/${path}`
}

/** `path:line` (or the path alone), as an editor's go-to and a copy take it. */
export const locText = (loc: Loc): string => (loc.line === null ? loc.path : `${loc.path}:${loc.line}`)

/** A path segment percent-encoded, with the characters `encodeURIComponent` keeps that a Markdown link target cannot: `( ) ! ' *`. */
const encodeSegment = (segment: string): string => encodeURIComponent(segment).replace(/[!'()*]/g, ch => `%${ch.charCodeAt(0).toString(16).toUpperCase()}`)

/**
 * The `file:` URL a link opens: the path percent-encoded, the line as a
 * `#L<n>` fragment, as Claude Code writes links to a line in its own replies.
 * Parentheses are encoded too, so an unbalanced `)` cannot end a Markdown
 * link's target early.
 */
export function fileHref(loc: Loc): string {
  const path = loc.path.split('/').map(encodeSegment).join('/')
  return `file://${path}${loc.line === null ? '' : `#L${loc.line}`}`
}

/** The place a `file:` URL from {@link fileHref} names; null for anything else. */
export function locOf(href: string): Loc | null {
  if (!href.startsWith('file://')) return null
  const hash = href.indexOf('#')
  const rest = hash < 0 ? href.slice(7) : href.slice(7, hash)
  const line = hash < 0 ? null : (/^#L(\d+)$/.exec(href.slice(hash))?.[1] ?? null)
  try {
    const path = decodeURIComponent(rest)
    return path.startsWith('/') ? { path, line: line === null ? null : Number(line) } : null
  } catch {
    return null
  }
}

/** Markdown text that draws `text` literally as a link to `loc`: every character markdown would read escaped. */
export function linkMarkdown(text: string, loc: Loc): string {
  return `[${text.replace(/[\\`*_{}[\]()<>#+\-.!|~]/g, ch => `\\${ch}`)}](${fileHref(loc)})`
}

/** A segment drawing `text` as a link to `loc`, or as plain text when there is no place. */
export function linked(text: string, loc: Loc | null, style: Omit<Segment, 'text' | 'link'> = {}): Segment {
  return loc === null || text === '' ? { ...style, text } : { ...style, text, link: loc }
}

/** A whole cell of a bar, and its left half. */
export const BAR_FULL = '━'
export const BAR_HALF = '╸'

/**
 * A horizontal bar for `value` out of `max` in at most `width` cells, in
 * half cells. Anything above zero shows at least a half.
 */
export function bar(value: number, max: number, width: number): string {
  if (width <= 0 || max <= 0 || value <= 0) return ''
  const halves = Math.max(1, Math.round((Math.min(value, max) / max) * width * 2))
  return BAR_FULL.repeat(Math.floor(halves / 2)) + (halves % 2 === 1 ? BAR_HALF : '')
}

/** A pressable segment; its text is what the terminal draws for it. */
export function button(id: string, label: string, hotkey?: string, style: Omit<Segment, 'text' | 'press'> = {}): Segment {
  const text = hotkey === undefined ? label : `${hotkey}: ${label}`
  return { ...style, text, press: hotkey === undefined ? { id, label } : { id, label, hotkey } }
}

/**
 * The label a pressable segment's Button is drawn with: the segment's own
 * text, less the `k: ` the engine draws before a hotkey's label itself. The
 * layout fits a segment's text to the cells it has (a box's name is cut with
 * an ellipsis), so the Button draws exactly what was laid out; `press.label`
 * may still hold the whole name, which would widen the row or wrap it.
 */
export function pressLabel(s: Segment): string {
  const hotkey = s.press?.hotkey
  const prefix = hotkey === undefined ? '' : `${hotkey}: `
  return prefix !== '' && s.text.startsWith(prefix) ? s.text.slice(prefix.length) : s.text
}

/** The colour a boundary's things are drawn in, or dim for none. */
export const boundaryStyle = (boundary: string | null, hues: Hues = NO_HUES): Pick<Segment, 'color' | 'dim'> => {
  const colour = boundaryColour(boundary, hues)
  return colour ? { color: colour } : { dim: true }
}

/** The swatch a boundary chip starts with: one cell in the boundary's colour. */
export const SWATCH = '■'

/**
 * A boundary as a chip: a swatch in its colour (the faint ink for a boundary
 * without one), then its label in a text tone, never in the colour itself.
 * `width` cuts the label so the chip takes at most that many cells; `tone`
 * is the label's (dim by default).
 */
export function chip(boundary: string | null, hues: Hues = NO_HUES, width = Number.POSITIVE_INFINITY, tone: Pick<Segment, 'color' | 'dim'> = { dim: true }): Segment[] {
  if (boundary === null) return []
  const colour = boundaryColour(boundary, hues)
  const label = boundaryLabel(boundary, hues)
  return [{ text: SWATCH, color: colour ?? FAINT }, { text: ` ${fit(label, Math.max(0, width - 2))}`, ...tone }]
}

/** How many cells {@link chip} takes for `boundary` uncut: its swatch, a space and its label. */
export const chipWidth = (boundary: string | null, hues: Hues = NO_HUES): number => (boundary === null ? 0 : 2 + cells(boundaryLabel(boundary, hues)))

/** How a segment's `Text` is styled: its colour, else `inactive` when dim; bold; the background laid under it. */
export function textStyle(s: Segment): { color?: string; bold?: boolean; backgroundColor?: string } {
  const color = s.color ?? (s.dim ? SECONDARY : undefined)
  return { ...(color === undefined ? {} : { color }), ...(s.bold ? { bold: true } : {}), ...(s.bg === undefined ? {} : { backgroundColor: s.bg }) }
}

/** `segments` with `bg` laid under each that has none of its own. */
export const tinted = (segments: Segment[], bg: string): Segment[] => segments.map(s => (s.bg === undefined ? { ...s, bg } : s))

/**
 * A component's name as the pane prints it: a method as `Class::method`
 * from its canonical name, anything else by its own short name. Display
 * only; lookups go by the canonical name.
 */
export function displayName(item: { name: string; canonical_name: string; kind: string }): string {
  if (item.kind !== 'method') return item.name
  const cut = item.canonical_name.lastIndexOf('::')
  if (cut < 0) return item.name
  const owner = item.canonical_name.slice(0, cut)
  // An anonymous class is named after where it is declared: keep the marker, not the path.
  const at = owner.indexOf('@')
  const scoped = at > 0 ? owner.slice(0, at) : owner
  return `${lastSegment(scoped)}::${item.canonical_name.slice(cut + 2)}`
}

/**
 * The last segment of a qualified name, whatever the language separates it
 * with: a namespace (`\\`), a path (`/`), a file's export (`#`), a module
 * (`.`) or a Rust path (`::`).
 */
function lastSegment(name: string): string {
  const cut = Math.max(name.lastIndexOf('\\'), name.lastIndexOf('/'), name.lastIndexOf('#'), name.lastIndexOf('.'))
  const rust = name.lastIndexOf('::')
  return rust > cut ? name.slice(rust + 2) : name.slice(cut + 1)
}

/**
 * A short name from a canonical one alone, as a policy violation names its
 * two ends: `Class::member` for a member, the last segment otherwise.
 */
export function shortName(canonical: string): string {
  const cut = canonical.lastIndexOf('::')
  if (cut >= 0) return displayName({ name: canonical.slice(cut + 2), canonical_name: canonical, kind: 'method' })
  return lastSegment(canonical)
}

/** Segments laid out as `left`, a gap, then `right` against the right edge; `right` goes first when both do not fit. */
export function spread(key: string, left: Segment[], right: Segment[], columns: number): Row {
  const gap = columns - segmentsWidth(left) - segmentsWidth(right)
  if (right.length > 0 && gap >= 1) return { key, segments: [...left, { text: spaces(gap) }, ...right] }
  return { key, segments: clip(left, columns) }
}

/**
 * Segments cut to `columns`: the last text segment that crosses the edge is
 * truncated with an ellipsis, and nothing after it is kept. A pressable
 * segment is never cut: it is dropped whole, since its label is its address.
 */
export function clip(segments: Segment[], columns: number): Segment[] {
  const out: Segment[] = []
  let used = 0
  for (const s of segments) {
    const w = cells(s.text)
    if (used + w <= columns) {
      out.push(s)
      used += w
      continue
    }
    if (s.press === undefined && s.field === undefined && columns - used > 0) out.push({ ...s, text: fit(s.text, columns - used) })
    break
  }
  return out
}

/** Words wrapped onto as many rows as they need, none wider than `columns`. */
export function wrapWords(text: string, columns: number): string[] {
  const lines: string[] = []
  let line = ''
  for (const word of text.split(/\s+/).filter(w => w !== '')) {
    const next = line === '' ? word : `${line} ${word}`
    if (cells(next) <= columns) {
      line = next
      continue
    }
    if (line !== '') lines.push(line)
    line = fit(word, columns)
  }
  if (line !== '') lines.push(line)
  return lines
}

/**
 * Groups of segments wrapped onto rows: each group stays whole on one row,
 * `gap` apart, and a group wider than a row is clipped to it.
 */
export function wrapGroups(key: string, groups: Segment[][], columns: number, gap = 2, indent = 0): Row[] {
  const rows: Row[] = []
  let line: Segment[] = []
  let used = 0
  const flush = () => {
    if (line.length > 0) rows.push({ key: rows.length === 0 ? key : `${key}-${rows.length}`, segments: line })
    line = []
    used = 0
  }
  for (const group of groups) {
    const w = segmentsWidth(group)
    const lead = line.length === 0 ? indent : gap
    if (line.length > 0 && used + lead + w > columns) flush()
    const pad = line.length === 0 ? indent : gap
    if (pad > 0) line.push({ text: spaces(pad) })
    line.push(...clip(group, columns - used - pad))
    used = segmentsWidth(line)
  }
  flush()
  return rows
}

export const blank = (key: string): Row => ({ key, segments: [{ text: ' ' }] })
export const dimRow = (key: string, text: string, columns: number): Row => ({ key, segments: [{ text: fit(text, columns), dim: true }] })

/** How a table of names, boundaries, a bar, numbers and (optionally) files fits `columns`; `compact` numbers (`31.7k`) on a narrow pane. */
export type TableSpec = { name: number; boundary: number; bar: number; numbers: number[]; place?: number; kind?: number; compact?: true }

/**
 * What a table may add beyond names and numbers: the pane's tier (its bar
 * shares), each row's file, each row's kind, and whether its numbers are
 * compact (narrow, unless the table is a detail's, whose figures stay whole).
 */
export type TableOptions = { tier?: Tier; places?: string[]; kinds?: string[]; compact?: boolean }

/**
 * Fits a table to `columns` (see the module docblock for the order things
 * give way in). `numbers` holds each number column's widest cell, title
 * included; the first number column is the one the bar draws. On a narrow
 * pane `nameMax` caps how wide names grow before the bar takes the rest
 * (paths want more); from the medium tier on names grow whole while the bar
 * keeps its minimum share.
 * With `places` the table ends in a file column while it fits beside a bar
 * at its minimum and names uncut; with `kinds` a kind column follows the
 * names while that fits too.
 */
export function tableSpec(columns: number, names: string[], boundaries: string[], numbers: number[], nameMax = NAME_MAX, options: TableOptions = {}): TableSpec {
  const range = barRange(columns, options.tier ?? 'narrow')
  const compacted = (options.compact ?? options.tier === 'narrow') ? { compact: true as const } : {}
  const longest = Math.max(1, ...names.map(cells))
  // From the medium tier on a whole name wins over a longer gauge; narrow, names stop at their cap so the bars still compare.
  const nameNeed = (options.tier ?? 'narrow') === 'narrow' ? Math.min(nameMax, longest) : longest
  // A boundary is a chip: its swatch and a space before its label.
  const boundaryLongest = Math.max(0, ...boundaries.map(cells))
  const boundaryNeed = boundaryLongest === 0 ? 0 : Math.min(BOUNDARY_MAX, boundaryLongest) + 2
  const placeNeed = Math.min(PLACE_MAX, Math.max(0, ...(options.places ?? []).map(cells)))
  const kindNeed = Math.min(KIND_MAX, Math.max(0, ...(options.kinds ?? []).map(cells)))
  // Beside a file or kind column a name may stop at the typical one's width: the few longest are cut rather than the column lost.
  const typical = Math.min(nameNeed, percentile(names.map(cells), 0.9))
  const attempt = (withBoundary: boolean, withBar: boolean, place: number, kind = 0): TableSpec | null => {
    const boundary = withBoundary && boundaryNeed > 0 ? boundaryNeed : 0
    const fixed = MARK + numbers.reduce((n, w) => n + 1 + w, 0) + (place > 0 ? place + 1 : 0) + (kind > 0 ? kind + 1 : 0)
    const avail = columns - fixed - (boundary > 0 ? boundary + 1 : 0)
    const placed = { ...(place > 0 ? { place } : {}), ...(kind > 0 ? { kind } : {}), ...compacted }
    if (!withBar) return avail >= Math.min(NAME_MIN, nameNeed) ? { name: Math.min(avail, nameNeed), boundary, bar: 0, numbers, ...placed } : null
    const barWidth = Math.min(range.max, Math.max(range.min, avail - 1 - nameNeed))
    // Room the bar may not take goes to a name past its cap: a whole name says more than a longer gauge.
    const name = Math.min(Math.max(nameNeed, Math.min(longest, avail - 1 - barWidth)), avail - 1 - barWidth)
    // A file or kind column only beside names whole but for the longest few: the name is what the row is about.
    if ((place > 0 || kind > 0) && name < typical) return null
    return name >= Math.min(NAME_MIN, nameNeed) ? { name, boundary, bar: barWidth, numbers, ...placed } : null
  }
  return (
    (placeNeed > 0 && kindNeed > 0 ? attempt(true, true, placeNeed, kindNeed) : null) ??
    (placeNeed > 0 ? attempt(true, true, placeNeed) : null) ??
    attempt(true, true, 0) ??
    attempt(false, true, 0) ??
    attempt(false, false, 0) ?? { name: Math.max(1, columns - MARK - numbers.reduce((n, w) => n + 1 + w, 0)), boundary: 0, bar: 0, numbers, ...compacted }
  )
}

/** The value `at` (0 to 1) of the way up `values` sorted; 1 for none. */
export function percentile(values: number[], at: number): number {
  if (values.length === 0) return 1
  const sorted = [...values].sort((a, b) => a - b)
  return sorted[Math.min(sorted.length - 1, Math.ceil(at * sorted.length) - 1)] ?? 1
}

/** A column-title row for a table, dim. */
export function tableHead(key: string, spec: TableSpec, titles: { name: string; boundary: string; numbers: string[]; place?: string; kind?: string }): Row {
  let text = spaces(MARK) + padEnd(fit(titles.name, spec.name), spec.name)
  if ((spec.kind ?? 0) > 0) text += ` ${padEnd(fit(titles.kind ?? 'kind', spec.kind ?? 0), spec.kind ?? 0)}`
  // A title that would be cut says nothing: the column's colours say what it is.
  if (spec.boundary > 0) text += ` ${padEnd(cells(titles.boundary) <= spec.boundary ? titles.boundary : '', spec.boundary)}`
  if (spec.bar > 0) text += ` ${spaces(spec.bar)}`
  spec.numbers.forEach((w, i) => (text += ` ${padStart(titles.numbers[i] ?? '', w)}`))
  if ((spec.place ?? 0) > 0) text += ` ${fit(titles.place ?? 'where', spec.place ?? 0)}`
  return { key, segments: [{ text, dim: true }] }
}

export type TableLine = {
  name: string
  boundary: string | null
  values: number[]
  max: number
  /** The value the bar draws, when not the first number. */
  barValue?: number
  selected?: boolean
  hotspotOnly?: boolean
  press?: string
  /** The name is a path: drawn in two tones and cut in its directory, never its file name (see {@link pathParts}). */
  path?: boolean
  /** Where the name's file is: the name is drawn as a link to it. */
  link?: Loc | null
  /** The one-cell mark before the name, when not the hotspot mark. */
  mark?: Segment
  /** The number column the list is sorted by: the others are drawn dim beside it. */
  sorted?: number
  /** What kind of thing the row is (class, method, ...), for a table with a kind column: drawn dim. */
  kind?: string
  /** The file (and line) the row is declared in, for a table with a file column: a link to it. */
  place?: string
  placeLoc?: Loc | null
  /** The card shown while the pointer rests on the row. */
  preview?: Preview
  /** Changed in the latest scan: lit on the flash ground for a moment (the marked row keeps its own). */
  lit?: boolean
}

/**
 * One table row: marker, name (pressable when it has an id), boundary as a
 * chip, bar and numbers. The boundary's colour is on its swatch and its
 * bar, never on its name. The marked row keeps its `›` (a surface without
 * backgrounds still shows which) and is tinted across.
 */
export function tableRow(key: string, line: TableLine, spec: TableSpec, hues: Hues = NO_HUES): Row {
  const style = boundaryStyle(line.boundary, hues)
  // A path in two tones, its directory dim and its file name in the text tone; only the file name is pressed or followed.
  const parts = line.path === true ? pathParts(line.name, spec.name) : { dir: '', base: fit(line.name, spec.name) }
  // The press keeps the whole name as its label; the Button draws only the cells laid out for it (`pressLabel`).
  const named = line.press === undefined ? linked(parts.base, line.link ?? null) : { ...button(line.press, parts.base), press: { id: line.press, label: line.name } }
  const segments: Segment[] = [
    { text: line.selected ? '›' : ' ', color: ACCENT, bold: true },
    line.mark ?? { text: line.hotspotOnly ? '◆' : ' ', dim: true },
    { text: ' ' },
    { text: parts.dir, dim: true },
    line.preview === undefined ? named : { ...named, preview: line.preview },
    { text: spaces(spec.name - cells(parts.dir) - cells(parts.base)) },
  ]
  if ((spec.kind ?? 0) > 0) segments.push({ text: ' ' }, { text: padEnd(fit(line.kind ?? '', spec.kind ?? 0), spec.kind ?? 0), dim: true })
  if (spec.boundary > 0) {
    // A chip: the boundary's colour on its swatch, its name in a text tone, the same on every row.
    const chipped = chip(line.boundary, hues, spec.boundary)
    segments.push({ text: ' ' }, ...chipped, { text: spaces(spec.boundary - segmentsWidth(chipped)) })
  }
  if (spec.bar > 0) {
    const glyphs = bar(line.barValue ?? line.values[0] ?? 0, line.max, spec.bar)
    segments.push({ text: ' ' }, { text: glyphs, ...style }, { text: TRACK.repeat(spec.bar - cells(glyphs)), color: FAINT })
  }
  spec.numbers.forEach((w, i) => {
    const text = ` ${padStart((spec.compact ? compact : grouped)(line.values[i] ?? 0), w)}`
    segments.push(line.sorted === undefined || line.sorted === i ? { text, color: HEADING } : { text, dim: true })
  })
  // Files are left-aligned and cut from the front: a column of them reads down the file names.
  if ((spec.place ?? 0) > 0 && (line.place ?? '') !== '') segments.push({ text: ' ' }, linked(fitStart(line.place ?? '', spec.place ?? 0), line.placeLoc ?? null, { dim: true }))
  const kept = segments.filter(s => s.text !== '')
  if (line.selected) return { key, segments: tinted(kept, SELECTED_BG), tint: SELECTED_BG }
  return line.lit === true ? { key, segments: tinted(kept, FLASH_BG), tint: FLASH_BG } : { key, segments: kept }
}

/** A number column's width: its title, or its widest figure as the table prints it (`compact` on a narrow pane). */
export const numberWidth = (title: string, values: number[], tier?: Tier, compacted = tier === 'narrow') =>
  Math.max(cells(title), ...values.map(v => cells(compacted ? compact(v) : grouped(v))))
