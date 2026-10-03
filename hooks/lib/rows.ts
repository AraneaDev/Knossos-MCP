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
 * cap), the boundary follows them, then the bar (up to {@link BAR_MAX}) and
 * the numbers. Width a table does not need stays at the right edge, never
 * between a name and its boundary. As the width shrinks it gives way in a
 * fixed order: its bars shorten to a minimum, then names are cut with an
 * ellipsis, then the boundary column goes, then the bars go. The numbers
 * always stay.
 */
import { ACCENT, boundaryColour, boundaryLabel, FAINT, HEADING, NO_HUES, SECONDARY } from './palette'
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
 * A run of text in one style. `color` is a Claude Code theme key (see
 * `palette.ts`); `dim` marks secondary text, drawn in the theme's `inactive`.
 */
/**
 * A run of text in one style. `hidden` marks a pressable with no text that is
 * drawn out of sight: it exists only so its `press.hotkey` keeps working.
 */
export type Segment = { text: string; color?: string; dim?: boolean; bold?: boolean; press?: Press; field?: Field; cell?: Cell; link?: Loc; hidden?: true }
/**
 * One line of the pane. Consecutive rows with the same `raster` key form a
 * grid the terminal may draw as one `Raster`; every surface can draw their
 * segments as text instead.
 */
export type Row = {
  key: string
  segments: Segment[]
  raster?: string
  /** Drawn as the engine's diff element instead of its segments: unified-diff hunks, and the file they are of (its language). */
  code?: { source: string; path: string }
}

export const BAR_MIN = 4
/** The widest a bar column grows: past it the names take the room, so bars, boundary and numbers stay together on the right. */
export const BAR_MAX = 24
/** The glyph a bar's empty part (its track) is drawn with, faint. */
export const TRACK = '·'
export const NAME_MIN = 8
const NAME_MAX = 32
const BOUNDARY_MAX = 12
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

export const spaces = (n: number): string => ' '.repeat(Math.max(0, n))
export const padEnd = (text: string, width: number): string => text + spaces(width - cells(text))
export const padStart = (text: string, width: number): string => spaces(width - cells(text)) + text
export const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`
/** A count with thousands separated: 7,878. */
export const grouped = (n: number): string => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
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

/** The colour a boundary's things are drawn in, or dim for none. */
export const boundaryStyle = (boundary: string | null, hues: Hues = NO_HUES): Pick<Segment, 'color' | 'dim'> => {
  const colour = boundaryColour(boundary, hues)
  return colour ? { color: colour } : { dim: true }
}

/** How a segment's `Text` is styled: its colour, else `inactive` when dim; bold. */
export function textStyle(s: Segment): { color?: string; bold?: boolean } {
  const color = s.color ?? (s.dim ? SECONDARY : undefined)
  return { ...(color === undefined ? {} : { color }), ...(s.bold ? { bold: true } : {}) }
}

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

/** Whole parts joined by ` · `, as many as fit, dropping from the end. */
export function joinFitting(parts: string[], columns: number): string {
  for (let n = parts.length; n > 0; n--) {
    const text = parts.slice(0, n).join(' · ')
    if (cells(text) <= columns) return text
  }
  return fit(parts[0] ?? '', columns)
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

/**
 * A section header: its title in bold, then its `subtitle` dim beside it (what
 * the list is sorted by), and a note against the right edge when it fits.
 */
export function sectionRow(key: string, title: string, note: string, columns: number, subtitle = ''): Row {
  const head: Segment[] = [{ text: fit(title, columns), bold: true, color: HEADING }]
  // The subtitle gives way to the note: the note says something the rows do not (`partial`, `all in core`).
  const noteRoom = note === '' ? 0 : cells(note) + 2
  if (subtitle !== '' && cells(title) + 3 + cells(subtitle) + noteRoom <= columns) head.push({ text: ` · ${subtitle}`, dim: true })
  return spread(key, head, note === '' ? [] : [{ text: note, dim: true }], columns)
}

export const blank = (key: string): Row => ({ key, segments: [{ text: ' ' }] })
export const dimRow = (key: string, text: string, columns: number): Row => ({ key, segments: [{ text: fit(text, columns), dim: true }] })

/** How a table of names, boundaries, a bar and numbers fits `columns`. */
export type TableSpec = { name: number; boundary: number; bar: number; numbers: number[] }

/**
 * Fits a table to `columns` (see the module docblock for the order things
 * give way in). `numbers` holds each number column's widest cell, title
 * included; the first number column is the one the bar draws. `nameMax`
 * caps how wide names grow before the bar takes the rest: paths want more.
 */
export function tableSpec(columns: number, names: string[], boundaries: string[], numbers: number[], nameMax = NAME_MAX): TableSpec {
  const fixed = MARK + numbers.reduce((n, w) => n + 1 + w, 0)
  const nameNeed = Math.min(nameMax, Math.max(1, ...names.map(cells)))
  const boundaryNeed = Math.min(BOUNDARY_MAX, Math.max(0, ...boundaries.map(cells)))
  const attempt = (withBoundary: boolean, withBar: boolean): TableSpec | null => {
    const boundary = withBoundary && boundaryNeed > 0 ? boundaryNeed : 0
    const avail = columns - fixed - (boundary > 0 ? boundary + 1 : 0)
    if (!withBar) return avail >= Math.min(NAME_MIN, nameNeed) ? { name: Math.min(avail, nameNeed), boundary, bar: 0, numbers } : null
    const barWidth = Math.min(BAR_MAX, Math.max(BAR_MIN, avail - 1 - nameNeed))
    const name = Math.min(nameNeed, avail - 1 - barWidth)
    return name >= Math.min(NAME_MIN, nameNeed) ? { name, boundary, bar: barWidth, numbers } : null
  }
  return (
    attempt(true, true) ??
    attempt(false, true) ??
    attempt(false, false) ?? { name: Math.max(1, columns - fixed), boundary: 0, bar: 0, numbers }
  )
}

/** How many columns a table laid out by `spec` takes. */
export const specWidth = (spec: TableSpec): number =>
  MARK + spec.name + (spec.boundary > 0 ? spec.boundary + 1 : 0) + (spec.bar > 0 ? spec.bar + 1 : 0) + spec.numbers.reduce((n, w) => n + 1 + w, 0)

/**
 * The width a section's header spreads over: its table's, so a note such as
 * `in` stands over the numbers, but never less than the header needs, nor
 * more than `columns`.
 */
export function sectionWidth(tableWidth: number, title: string, note: string, columns: number): number {
  return Math.min(columns, Math.max(tableWidth, cells(title) + (note === '' ? 0 : cells(note) + 2)))
}

/** A column-title row for a table, dim. */
export function tableHead(key: string, spec: TableSpec, titles: { name: string; boundary: string; numbers: string[] }): Row {
  let text = spaces(MARK) + padEnd(fit(titles.name, spec.name), spec.name)
  // A title that would be cut says nothing: the column's colours say what it is.
  if (spec.boundary > 0) text += ` ${padEnd(cells(titles.boundary) <= spec.boundary ? titles.boundary : '', spec.boundary)}`
  if (spec.bar > 0) text += ` ${spaces(spec.bar)}`
  spec.numbers.forEach((w, i) => (text += ` ${padStart(titles.numbers[i] ?? '', w)}`))
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
  /** Cut the name from the front (a path), not the end. */
  cutStart?: boolean
  /** Where the name's file is: the name is drawn as a link to it. */
  link?: Loc | null
  /** The one-cell mark before the name, when not the hotspot mark. */
  mark?: Segment
  /** The number column the list is sorted by: the others are drawn dim beside it. */
  sorted?: number
  /** The same boundary as the row above: its label is drawn dim, so the column reads by where it changes. */
  repeat?: boolean
}

/** One table row: marker, name (pressable when it has an id), boundary, bar and numbers. */
export function tableRow(key: string, line: TableLine, spec: TableSpec, hues: Hues = NO_HUES): Row {
  const style = boundaryStyle(line.boundary, hues)
  const name = line.cutStart ? fitStart(line.name, spec.name) : fit(line.name, spec.name)
  const segments: Segment[] = [
    { text: line.selected ? '›' : ' ', color: ACCENT, bold: true },
    line.mark ?? { text: line.hotspotOnly ? '◆' : ' ', dim: true },
    { text: ' ' },
    line.press === undefined ? linked(name, line.link ?? null) : button(line.press, name),
    { text: spaces(spec.name - cells(name)) },
  ]
  if (spec.boundary > 0) {
    const label = fit(boundaryLabel(line.boundary, hues), spec.boundary)
    segments.push({ text: ' ' }, { text: padEnd(label, spec.boundary), ...(line.repeat === true ? { dim: true } : style) })
  }
  if (spec.bar > 0) {
    const glyphs = bar(line.barValue ?? line.values[0] ?? 0, line.max, spec.bar)
    segments.push({ text: ' ' }, { text: glyphs, ...style }, { text: TRACK.repeat(spec.bar - cells(glyphs)), color: FAINT })
  }
  spec.numbers.forEach((w, i) => {
    const text = ` ${padStart(grouped(line.values[i] ?? 0), w)}`
    segments.push(line.sorted === undefined || line.sorted === i ? { text, color: HEADING } : { text, dim: true })
  })
  return { key, segments: segments.filter(s => s.text !== '') }
}

export const numberWidth = (title: string, values: number[]) => Math.max(cells(title), ...values.map(v => cells(grouped(v))))
