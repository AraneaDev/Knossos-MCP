/**
 * The drawing primitives every view of the pane shares: styled segments,
 * rows, cutting text to a width, bars, and the table that fits itself to the
 * columns it has. Pure; nothing here reads state.
 *
 * Widths are counted in code points. Every glyph the pane draws (blocks,
 * bullets, arrows, the ellipsis) is one cell wide, and names come from source
 * identifiers, so a code point is a cell.
 *
 * As the width shrinks a table gives way in a fixed order: its bars shorten
 * to a minimum, then names are cut with an ellipsis, then the boundary column
 * goes, then the bars go. The numbers always stay.
 */
import { ACCENT, boundaryColour, boundaryLabel, STATUS_COLOURS } from './palette'

/** A pressable segment: drawn as a plain Button, `hotkey: label` when it has a hotkey. */
export type Press = { id: string; label: string; hotkey?: string }
/** A one-line text field: drawn as an Input keyed `id`, holding `value`. */
export type Field = { id: string; value: string; placeholder: string }
/**
 * How a segment draws as cells of a terminal `Raster` instead of as text:
 * each of its cells shows `glyph` on the background `bg` (`#rrggbb`).
 */
export type Cell = { glyph: string; bg: string }
export type Segment = { text: string; color?: string; dim?: boolean; bold?: boolean; press?: Press; field?: Field; cell?: Cell }
/**
 * One line of the pane. Consecutive rows with the same `raster` key form a
 * grid the terminal may draw as one `Raster`; every surface can draw their
 * segments as text instead.
 */
export type Row = { key: string; segments: Segment[]; raster?: string }

export const BAR_MIN = 4
/** The longest bar drawn: a wider column leaves the rest of it empty. */
export const BAR_MAX = 40
export const NAME_MIN = 8
const NAME_MAX = 32
const BOUNDARY_MAX = 12
/** The selection marker, the hotspot mark and a space. */
export const MARK = 3

export const cells = (text: string): number => [...text].length
export const rowWidth = (row: Row): number => row.segments.reduce((n, s) => n + cells(s.text), 0)
/** The row as the terminal shows it, colours aside. */
export const plainText = (row: Row): string => row.segments.map(s => s.text).join('')
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

const EIGHTHS = ['', '▏', '▎', '▍', '▌', '▋', '▊', '▉']

/**
 * A horizontal bar for `value` out of `max` in at most `width` cells, in
 * eighth blocks. Anything above zero shows at least a sliver.
 */
export function bar(value: number, max: number, width: number): string {
  if (width <= 0 || max <= 0 || value <= 0) return ''
  const eighths = Math.max(1, Math.round((Math.min(value, max) / max) * width * 8))
  return '█'.repeat(Math.floor(eighths / 8)) + EIGHTHS[eighths % 8]
}

/** A pressable segment; its text is what the terminal draws for it. */
export function button(id: string, label: string, hotkey?: string, style: Omit<Segment, 'text' | 'press'> = {}): Segment {
  const text = hotkey === undefined ? label : `${hotkey}: ${label}`
  return { ...style, text, press: hotkey === undefined ? { id, label } : { id, label, hotkey } }
}

/** The colour a boundary's things are drawn in, or dim for none. */
export const boundaryStyle = (boundary: string | null): Pick<Segment, 'color' | 'dim'> => {
  const colour = boundaryColour(boundary)
  return colour ? { color: colour } : { dim: true }
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

/** A section header: its title in bold capitals, a note against the right edge when it fits. */
export function sectionRow(key: string, title: string, note: string, columns: number): Row {
  const head: Segment = { text: fit(title.toUpperCase(), columns), bold: true }
  return spread(key, [head], note === '' ? [] : [{ text: note, dim: true }], columns)
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
    if (!withBar) return avail >= Math.min(NAME_MIN, nameNeed) ? { name: avail, boundary, bar: 0, numbers } : null
    const barWidth = Math.max(BAR_MIN, avail - 1 - nameNeed)
    const name = avail - 1 - barWidth
    return name >= Math.min(NAME_MIN, nameNeed) ? { name, boundary, bar: barWidth, numbers } : null
  }
  return (
    attempt(true, true) ??
    attempt(false, true) ??
    attempt(false, false) ?? { name: Math.max(1, columns - fixed), boundary: 0, bar: 0, numbers }
  )
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
}

/** One table row: marker, name (pressable when it has an id), boundary, bar and numbers. */
export function tableRow(key: string, line: TableLine, spec: TableSpec): Row {
  const style = boundaryStyle(line.boundary)
  const name = line.cutStart ? fitStart(line.name, spec.name) : fit(line.name, spec.name)
  const segments: Segment[] = [
    { text: line.selected ? '›' : ' ', color: ACCENT, bold: true },
    { text: line.hotspotOnly ? '◆' : ' ', color: STATUS_COLOURS.warn },
    { text: ' ' },
    line.press === undefined ? { text: name } : button(line.press, name),
    { text: spaces(spec.name - cells(name)) },
  ]
  if (spec.boundary > 0) {
    const label = fit(boundaryLabel(line.boundary), spec.boundary)
    segments.push({ text: ' ' }, { text: padEnd(label, spec.boundary), ...style })
  }
  if (spec.bar > 0) {
    const glyphs = bar(line.barValue ?? line.values[0] ?? 0, line.max, Math.min(spec.bar, BAR_MAX))
    segments.push({ text: ' ' }, { text: glyphs, ...style }, { text: spaces(spec.bar - cells(glyphs)) })
  }
  spec.numbers.forEach((w, i) => segments.push({ text: ` ${padStart(String(line.values[i] ?? 0), w)}`, ...(i > 0 ? { dim: true } : {}) }))
  return { key, segments: segments.filter(s => s.text !== '') }
}

export const numberWidth = (title: string, values: number[]) => Math.max(cells(title), ...values.map(v => cells(String(v))))
