/**
 * The Overview's charts: what the project is made of, how its dependencies
 * concentrate, how its health moved over the retained snapshots, which
 * boundaries lean on which, and what this session changed. The lists live on
 * Hubs; the Overview only measures.
 *
 * Pure: the dashboard in, cards (see `cards.ts`) out, every row fitted to the
 * width it is given. Each card says one thing in one form:
 *
 * - composition: a 100%-stacked bar per dimension (boundaries, languages,
 *   kinds), the largest few named under it with their shares, the rest as
 *   "other". Boundaries in their own colours (a boundary is drawn in the same
 *   colour everywhere on the pane); languages and kinds in one neutral ink,
 *   told apart by texture, so no colour means two things;
 * - dependency concentration: a histogram of how many components have how
 *   many dependents, one bar per bucket in the accent (one hue: it is a
 *   magnitude), counts right-aligned, the top bucket marked as the hubs;
 *   pressing a bucket opens Hubs narrowed to it;
 * - health over time: one row per figure, each its own small chart on a
 *   shared time axis (never two scales in one chart), the newest value
 *   right-aligned; only with five snapshots or more;
 * - cross-boundary flows: the strongest dependencies from one boundary to
 *   another as bars, a forbidden one in the error colour with a `×` and the
 *   word, so the colour is never the only sign; pressing one opens that
 *   cell on Boundaries;
 * - this session: its scans, what it touched, and the way to Changes.
 *
 * Text is always in a text tone; colour is on the marks (bars, swatches).
 */
import type { Dashboard } from '../../types'
import type { Block, Section } from './cards'
import { noteOf } from './cards'
import { timelineRow } from './changes'
import type { ChangesInput, LookAt } from './changes'
import { ACCENT, boundaryColour, boundaryLabel, FAINT, HEADING, SECONDARY, SELECTED_BG, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { BAR_FULL, bar, button, cells, chip, chipWidth, clip, fit, grouped, padEnd, padStart, plural, spaces, SWATCH, tinted, TRACK, wrapGroups } from './rows'
import type { Row, Segment } from './rows'
import type { Openable } from './views'

/** One part of a whole: its label, how many, and how its share of the bar is drawn (a glyph in a colour). */
export type Part = { label: string; value: number; glyph: string; colour: string }

/** One dimension of the project as parts of a whole: what it counts (`unit`), the whole, and its largest parts then "other". */
export type Composition = { key: string; title: string; unit: string; total: number; parts: Part[] }

/** A bucket of the in-degree histogram: the in-degrees it spans (`to` null for no upper end) and how many components. */
export type Bucket = { from: number; to: number | null; components: number }

/** A dependency from one boundary to another: their names, axis indexes, how many edges, and whether a policy forbids it. */
export type Flow = { from: string; to: string; fromIndex: number; toIndex: number; edges: number; forbidden: boolean }

/** A figure over the retained snapshots, oldest first. */
export type HealthSeries = { label: string; values: number[] }

/** What the Overview's charts draw, read from the dashboard once. */
export type OverviewData = {
  composition: Composition[]
  buckets: Bucket[]
  /** The walk the histogram counts stopped early: the buckets are floors. */
  bucketsPartial: boolean
  health: HealthSeries[]
  flows: Flow[]
  /** Every dependency between labelled components the matrix counted. */
  flowEdges: number
  flowsPartial: boolean
}

export const NO_OVERVIEW: OverviewData = { composition: [], buckets: [], bucketsPartial: false, health: [], flows: [], flowEdges: 0, flowsPartial: false }

/** The most parts a stacked bar names before the rest become "other": boundaries keep their colours, the others three textures. */
const BOUNDARY_PARTS = 5
const OTHER_PARTS = 3
/** The textures that tell a neutral bar's parts apart, largest first; "other" is the faintest. */
const TEXTURES = ['█', '▓', '▒']
const OTHER_TEXTURE = '░'
/** The neutral ink of a textured bar. */
const NEUTRAL = SECONDARY
/** A health chart needs this many snapshots. */
export const HEALTH_MIN_POINTS = 5
/** The most cells one snapshot takes on the health axis: a few snapshots still span the chart, as steps. */
const POINT_MAX = 8
/** The levels a health row is drawn with, lowest first. */
const LEVELS = '▁▂▃▄▅▆▇█'
/** The width a card's row label takes: the longest label of its kind. */
const COMPOSITION_LABEL = 'boundaries'.length
const HEALTH_LABEL = 'unreferenced'.length
/** The cells between two columns of a legend. */
const LEGEND_GAP = 3
/** Below this many columns a stacked bar's label stands above it rather than beside it. */
const BESIDE_MIN = 56

const LANGUAGES: Record<string, string> = { php: 'PHP', javascript: 'JS', typescript: 'TS', python: 'Python', rust: 'Rust', go: 'Go', ruby: 'Ruby', java: 'Java' }

const isCount = (n: unknown): n is number => typeof n === 'number' && Number.isFinite(n) && n >= 0

/**
 * Parts from counts: the largest `keep` as they are, the rest folded into
 * "other" (with anything the whole holds that no listed count does).
 */
function parts(counts: { label: string; value: number; colour?: string }[], total: number, keep: number, textured: boolean): Part[] {
  const sorted = [...counts].filter(c => c.value > 0).sort((a, b) => b.value - a.value || a.label.localeCompare(b.label))
  const kept = sorted.slice(0, keep).map((c, i): Part => ({ label: c.label, value: c.value, glyph: textured ? (TEXTURES[i] ?? OTHER_TEXTURE) : BAR_FULL, colour: textured ? NEUTRAL : (c.colour ?? FAINT) }))
  const rest = total - kept.reduce((n, p) => n + p.value, 0)
  return rest > 0 ? [...kept, { label: 'other', value: rest, glyph: OTHER_TEXTURE, colour: FAINT }] : kept
}

/** The Overview's chart data from the dashboard; whatever an older knossos does not send is left empty. */
export function overviewData(d: Dashboard, hues: Hues): OverviewData {
  const composition: Composition[] = []
  const m = d.boundary_matrix
  if (m !== undefined && Array.isArray(m.boundaries) && Array.isArray(m.members) && m.boundaries.length > 0) {
    const counts = m.boundaries.map((name, i) => ({ label: boundaryLabel(name, hues), value: isCount(m.members[i]) ? m.members[i]! : 0, colour: boundaryColour(name, hues) ?? FAINT }))
    const listed = counts.reduce((n, c) => n + c.value, 0)
    const total = isCount(m.labelled) ? Math.max(m.labelled, listed) : listed
    composition.push({ key: 'boundaries', title: 'boundaries', unit: 'components', total, parts: parts(counts, total, BOUNDARY_PARTS, false) })
  }
  const summary = d.summary
  if (summary !== undefined && Array.isArray(summary.languages) && summary.files > 0) {
    const counts = summary.languages.filter(l => isCount(l.files)).map(l => ({ label: LANGUAGES[l.language] ?? l.language, value: l.files }))
    composition.push({ key: 'languages', title: 'languages', unit: 'files', total: summary.files, parts: parts(counts, summary.files, OTHER_PARTS, true) })
  }
  if (summary !== undefined && Array.isArray(summary.kinds) && summary.components > 0) {
    const counts = summary.kinds.filter(k => isCount(k.count)).map(k => ({ label: k.kind.replace(/_/g, ' '), value: k.count }))
    composition.push({ key: 'kinds', title: 'kinds', unit: 'components', total: summary.components, parts: parts(counts, summary.components, OTHER_PARTS, true) })
  }
  const raw = d.in_degree?.buckets
  const buckets = Array.isArray(raw) && raw.every(b => isCount(b.from) && (b.to === null || isCount(b.to)) && isCount(b.components)) ? raw.map(b => ({ from: b.from, to: b.to, components: b.components })) : []
  const points = d.trend
  const series = (label: string, pick: (p: Dashboard['trend'][number]) => unknown): HealthSeries[] => {
    const values = points.map(pick)
    return values.every(isCount) ? [{ label, values: values as number[] }] : []
  }
  const health = points.length < HEALTH_MIN_POINTS ? [] : [...series('cycles', p => p.cycles), ...series('unreferenced', p => p.dead_code), ...series('max degree', p => p.max_degree), ...series('diagnostics', p => p.diagnostics)]
  const flows: Flow[] =
    m !== undefined && Array.isArray(m.flows)
      ? m.flows.flatMap(f => {
          const from = m.boundaries[f.from]
          const to = m.boundaries[f.to]
          return from !== undefined && to !== undefined && isCount(f.edges) ? [{ from, to, fromIndex: f.from, toIndex: f.to, edges: f.edges, forbidden: f.forbidden === true }] : []
        })
      : []
  return { composition, buckets, bucketsPartial: d.in_degree?.truncated === true, health, flows, flowEdges: m?.edges ?? 0, flowsPartial: m?.truncated === true }
}

/** A bucket as its row names it: `0`, `1–5`, `101+`. */
export const bucketLabel = (b: Bucket): string => (b.to === null ? `${b.from}+` : b.from === b.to ? `${b.from}` : `${b.from}–${b.to}`)

/** The session row the Overview walks first, when the session changed anything: it opens Changes. */
const sessionRow = (changes: ChangesInput): Openable[] =>
  changes.files.length === 0
    ? []
    : [
        {
          name: 'Changes',
          canonical: 'Changes',
          jump: { tab: 'changes' },
          copy: `${plural(changes.files.length, 'file', 'files')} changed this session`,
          ask: 'Using the Knossos graph, what did the changes in this session reach, and which tests should I run?',
        },
      ]

/**
 * The rows the Overview's marker walks, in the order a narrow pane draws
 * them: the way to this session's changes, the in-degree buckets (each opens
 * Hubs narrowed to it), then the flows (each opens its cell on Boundaries).
 */
export function overviewList(data: OverviewData, changes: ChangesInput): Openable[] {
  const buckets = data.buckets.map(
    (b): Openable => ({
      name: `in-degree ${bucketLabel(b)}`,
      canonical: `in-degree ${bucketLabel(b)}`,
      jump: { tab: 'hubs', degree: { from: b.from, to: b.to } },
      ask: `Using the Knossos graph, which components have an in-degree of ${bucketLabel(b)}, and is any of them doing too much?`,
    }),
  )
  const flows = data.flows.map(
    (f): Openable => ({
      name: `${f.from} → ${f.to}`,
      canonical: `${f.from} → ${f.to}`,
      jump: { tab: 'boundaries', selected: f.fromIndex, target: f.to },
      ask: `Using the Knossos graph, why does ${f.from} depend on ${f.to} (${grouped(f.edges)} dependencies), and should any of it move?`,
    }),
  )
  return [...sessionRow(changes), ...buckets, ...flows]
}

/** The marker and tint of a walkable row: `›` and the tint when marked. */
function walked(key: string, marked: boolean, segments: Segment[], columns: number): Row {
  const kept = clip([{ text: marked ? '›' : ' ', color: ACCENT, bold: true }, ...segments], columns)
  if (!marked) return { key, segments: kept }
  const used = kept.reduce((n, s) => n + cells(s.text), 0)
  return { key, segments: tinted([...kept, ...(used < columns ? [{ text: spaces(columns - used) }] : [])], SELECTED_BG), tint: SELECTED_BG }
}

/**
 * Cells of a bar `width` wide shared out by value, largest remainder first:
 * every part above zero gets at least one cell while there are cells to give.
 */
export function shares(values: number[], width: number): number[] {
  const total = values.reduce((a, b) => a + b, 0)
  if (total <= 0 || width <= 0) return values.map(() => 0)
  const exact = values.map(v => (v / total) * width)
  const out = exact.map(Math.floor)
  let left = width - out.reduce((a, b) => a + b, 0)
  const order = exact.map((e, i) => ({ i, r: e - Math.floor(e) })).sort((a, b) => b.r - a.r || a.i - b.i)
  for (const { i } of order) {
    if (left <= 0) break
    out[i]!++
    left--
  }
  // A part too small for a cell borrows one from the largest, while that keeps one of its own.
  values.forEach((v, i) => {
    if (v <= 0 || out[i]! > 0) return
    const largest = out.indexOf(Math.max(...out))
    if (out[largest]! > 1) {
      out[largest]!--
      out[i] = 1
    }
  })
  return out
}

/** A share as a whole percent, `<1%` for a sliver. */
export function percent(value: number, total: number): string {
  if (total <= 0) return '0%'
  const p = (value / total) * 100
  return p > 0 && p < 0.5 ? '<1%' : `${Math.round(p)}%`
}

/**
 * A stacked bar's cells: each part in its glyph and colour. Parts drawn in
 * full blocks end in a half block, so two colours side by side keep a
 * sliver of ground between them.
 */
function stackedBar(c: Composition, width: number): Segment[] {
  const cellsOf = shares(
    c.parts.map(p => p.value),
    width,
  )
  return c.parts.flatMap((p, i): Segment[] => {
    const n = cellsOf[i]!
    if (n === 0) return []
    const last = i === c.parts.length - 1 || cellsOf.slice(i + 1).every(k => k === 0)
    const text = p.glyph === BAR_FULL || p.glyph === '█' ? (last ? '█'.repeat(n) : `${'█'.repeat(n - 1)}▌`) : p.glyph.repeat(n)
    return [{ text, color: p.colour }]
  })
}

/**
 * The legend under a stacked bar: each part's swatch (its glyph and colour),
 * its label and its share, in columns of one width, so the parts read down
 * as well as across; a part whose label would not fit its column is cut.
 */
function legendRows(c: Composition, columns: number, indent: number): Row[] {
  const room = Math.max(1, columns - indent)
  const shares = c.parts.map(p => percent(p.value, c.total))
  const shareWidth = Math.max(...shares.map(cells))
  const labelWidth = Math.max(...c.parts.map(p => cells(p.label)))
  const per = Math.max(1, Math.min(c.parts.length, Math.floor((room + LEGEND_GAP) / (2 + labelWidth + 1 + shareWidth + LEGEND_GAP))))
  // One column per part while they fit, each as wide as the longest part needs; a label is cut only when one column is all there is.
  const label = Math.max(1, Math.min(labelWidth, room - 2 - 1 - shareWidth))
  const rows: Row[] = []
  for (let start = 0; start < c.parts.length; start += per) {
    const segments: Segment[] = [{ text: spaces(indent) }]
    c.parts.slice(start, start + per).forEach((p, j) => {
      const i = start + j
      if (j > 0) segments.push({ text: spaces(LEGEND_GAP) })
      segments.push({ text: p.glyph === BAR_FULL ? SWATCH : p.glyph, color: p.colour }, { text: ` ${padEnd(fit(p.label, label), label)} `, dim: true }, { text: padStart(shares[i]!, shareWidth), color: HEADING })
    })
    rows.push({ key: `comp-${c.key}-legend${rows.length === 0 ? '' : `-${rows.length}`}`, segments: segments.filter(s => s.text !== '') })
  }
  return rows
}

/**
 * The composition card: per dimension its name, the stacked bar and the
 * whole (right-aligned), then the legend under the bar. Narrow, the name
 * and the whole stand above a bar as wide as the card.
 */
export function compositionBlock(data: OverviewData): Block | null {
  if (data.composition.length === 0) return null
  return {
    key: 'composition',
    make: (columns): Section => {
      const totals = Math.max(...data.composition.map(c => cells(grouped(c.total))))
      const beside = columns >= BESIDE_MIN
      const body: Row[] = []
      data.composition.forEach((c, n) => {
        if (n > 0) body.push({ key: `comp-${c.key}-gap`, segments: [{ text: ' ' }] })
        const total: Segment[] = [{ text: padStart(grouped(c.total), totals), color: HEADING }, { text: ` ${c.unit}`, dim: true }]
        const unitWidth = Math.max(...data.composition.map(x => cells(` ${x.unit}`)))
        if (beside) {
          const width = Math.max(4, columns - COMPOSITION_LABEL - 1 - 1 - totals - unitWidth)
          const drawn = stackedBar(c, width)
          const used = drawn.reduce((k, s) => k + cells(s.text), 0)
          body.push({ key: `comp-${c.key}`, segments: [{ text: padEnd(c.title, COMPOSITION_LABEL), dim: true }, { text: ' ' }, ...drawn, { text: spaces(width - used + 1) }, ...total] })
          body.push(...legendRows(c, columns, COMPOSITION_LABEL + 1))
        } else {
          const head = [{ text: c.title, dim: true }, { text: spaces(Math.max(1, columns - cells(c.title) - totals - cells(` ${c.unit}`))) }, ...total]
          body.push({ key: `comp-${c.key}-head`, segments: clip(head, columns) })
          body.push({ key: `comp-${c.key}`, segments: stackedBar(c, columns) })
          body.push(...legendRows(c, columns, 0))
        }
      })
      return { key: 'composition', title: 'Composition', body }
    },
  }
}

/**
 * The dependency concentration card: one row per in-degree bucket, its
 * label (a press that opens Hubs narrowed to it), a bar in the accent on a
 * faint track, and how many components, right-aligned; the top bucket is
 * marked `◆` and named the hubs. `offset` is the first bucket's place in the
 * Overview's walk.
 */
export function concentrationBlock(data: OverviewData, selected: number, offset: number): Block | null {
  const buckets = data.buckets
  if (buckets.length === 0) return null
  return {
    key: 'concentration',
    make: (columns): Section => {
      const labels = buckets.map(bucketLabel)
      const labelWidth = Math.max(...labels.map(cells))
      const counts = buckets.map(b => grouped(b.components))
      const countWidth = Math.max(...counts.map(cells), cells('components') - 1)
      const flag = ' hubs'
      const width = Math.max(4, columns - 3 - labelWidth - 1 - 1 - countWidth - cells(flag))
      const max = Math.max(0, ...buckets.map(b => b.components))
      const total = buckets.reduce((n, b) => n + b.components, 0)
      const top = buckets.length - 1
      const head: Row = { key: 'degree-head', segments: [{ text: `   ${padEnd('in-degree', labelWidth + 1 + width)}`, dim: true }, { text: padStart('components', countWidth + 1), dim: true }] }
      const body = buckets.map((b, i) => {
        const glyphs = bar(b.components, max, width)
        const segments: Segment[] = [
          i === top ? { text: '◆', color: ACCENT } : { text: ' ' },
          { text: ' ' },
          button(`row:${offset + i}`, labels[i]!),
          { text: spaces(labelWidth - cells(labels[i]!) + 1) },
          { text: glyphs, color: ACCENT },
          { text: TRACK.repeat(width - cells(glyphs)), color: FAINT },
          { text: ` ${padStart(counts[i]!, countWidth)}`, color: HEADING },
          ...(i === top ? [{ text: flag, dim: true }] : []),
        ]
        return walked(`degree-${i}`, offset + i === selected, segments, columns)
      })
      const note = `${grouped(total)}${data.bucketsPartial ? '+' : ''} components`
      // What the buckets count, so their sum is not read as the whole project.
      body.push({ key: 'degree-said', segments: [{ text: fit('   tests and external code left out', columns), dim: true }] })
      return { key: 'concentration', title: 'Dependency concentration', note: noteOf(note), body: [{ ...head, segments: clip(head.segments, columns) }, ...body] }
    },
  }
}

/** A series as level glyphs, each point `per` cells wide; a flat series lies on the lowest level. */
function levels(values: number[], per: number): string {
  const min = Math.min(...values)
  const max = Math.max(...values)
  return values.map(v => (max === min ? LEVELS[0]! : LEVELS[Math.round(((v - min) / (max - min)) * 7)]!).repeat(per)).join('')
}

/**
 * The health card: one row per figure, each its own chart scaled to its own
 * range (a row never shares a scale with another), every row on the same
 * time axis: the same snapshots, column for column, oldest left. The newest
 * value stands right-aligned after it, and its range dim when there is room.
 * A flat figure lies on its lowest level in the faint ink. Null with fewer
 * than {@link HEALTH_MIN_POINTS} snapshots.
 */
export function healthBlock(data: OverviewData): Block | null {
  const series = data.health
  if (series.length === 0 || (series[0]?.values.length ?? 0) < HEALTH_MIN_POINTS) return null
  return {
    key: 'health',
    make: (columns): Section | null => {
      const last = series.map(s => grouped(s.values[s.values.length - 1]!))
      const lastWidth = Math.max(...last.map(cells))
      // A figure that never moved says so instead of a range of one value.
      const ranges = series.map(s => (Math.min(...s.values) === Math.max(...s.values) ? 'no change' : `${grouped(Math.min(...s.values))}–${grouped(Math.max(...s.values))}`))
      const rangeWidth = Math.max(...ranges.map(cells)) + 2
      const plotMax = columns - HEALTH_LABEL - 1 - 1 - lastWidth
      if (plotMax < HEALTH_MIN_POINTS) return null
      const withRange = plotMax - rangeWidth >= HEALTH_MIN_POINTS * 2
      const plot = withRange ? plotMax - rangeWidth : plotMax
      const points = Math.min(series[0]!.values.length, plot)
      const per = Math.max(1, Math.min(POINT_MAX, Math.floor(plot / points)))
      const drawnWidth = points * per
      const body: Row[] = series.map((s, i) => {
        const values = s.values.slice(-points)
        const flat = Math.min(...values) === Math.max(...values)
        return {
          key: `health-${i}`,
          segments: [
            { text: padEnd(s.label, HEALTH_LABEL), dim: true },
            { text: ' ' },
            { text: levels(values, per), color: flat ? FAINT : ACCENT },
            { text: ' ' },
            { text: padStart(last[i]!, lastWidth), color: HEADING },
            ...(withRange ? [{ text: `  ${padEnd(ranges[i]!, rangeWidth - 2)}`, dim: true }] : []),
          ],
        }
      })
      // The shared time axis: where the oldest and the newest snapshot stand.
      const lead = spaces(HEALTH_LABEL + 1)
      const ends = drawnWidth >= 'oldest'.length + 'newest'.length + 3 ? `oldest${'─'.repeat(drawnWidth - 12)}newest` : '─'.repeat(drawnWidth)
      body.push({ key: 'health-axis', segments: [{ text: lead }, { text: ends.slice(0, 6), dim: true }, { text: ends.slice(6, ends.length - 6), color: FAINT }, { text: ends.slice(ends.length - 6), dim: true }].filter(s => s.text !== '') })
      const said = plural(points, 'snapshot', 'snapshots')
      return { key: 'health', title: 'Health over time', note: noteOf(said), body }
    },
  }
}

/**
 * The cross-boundary flows card: the strongest dependencies from one
 * boundary to another, each `■ from → ■ to`, a bar in the accent and the
 * count right-aligned; one a policy forbids has its bar in the error colour
 * and says `× forbidden`. Each row opens its cell on Boundaries. Empty, the
 * card is one line.
 */
export function flowsBlock(data: OverviewData, hues: Hues, selected: number, offset: number): Block {
  const flows = data.flows
  return {
    key: 'flows',
    make: (columns): Section => {
      const section = (body: Row[], note: string): Section => ({ key: 'flows', title: 'Cross-boundary flows', note: noteOf(note), body, ...(body.length === 0 ? { empty: 'none' } : {}) })
      if (flows.length === 0) return section([], '')
      const counts = flows.map(f => grouped(f.edges))
      const countWidth = Math.max(...counts.map(cells))
      const forbidden = flows.some(f => f.forbidden) ? ' × forbidden'.length : 0
      const nameCap = Math.max(6, Math.floor((columns - 3 - 3 - countWidth - forbidden - 8) / 2))
      const fromWidth = Math.min(nameCap, Math.max(...flows.map(f => chipWidth(f.from, hues))))
      const toWidth = Math.min(nameCap, Math.max(...flows.map(f => chipWidth(f.to, hues))))
      const width = columns - 2 - fromWidth - 3 - toWidth - 1 - 1 - countWidth - forbidden
      const withBar = width >= 4
      const max = Math.max(0, ...flows.map(f => f.edges))
      const body = flows.map((f, i) => {
        // The source's name is the press: a chip's label cannot carry a colour, so its swatch carries it.
        const name = fit(boundaryLabel(f.from, hues), fromWidth - 2)
        const from: Segment[] = [{ text: '■', color: boundaryColour(f.from, hues) ?? FAINT }, { text: ' ' }, button(`row:${offset + i}`, name)]
        const to = chip(f.to, hues, toWidth, {})
        const pad = (s: Segment[], w: number) => [...s, { text: spaces(w - s.reduce((n, x) => n + cells(x.text), 0)) }]
        const glyphs = withBar ? bar(f.edges, max, width) : ''
        const segments: Segment[] = [
          { text: ' ' },
          ...pad(from, fromWidth),
          { text: ' → ', dim: true },
          ...pad(to, toWidth),
          ...(withBar ? [{ text: ' ' }, { text: glyphs, color: f.forbidden ? STATUS_COLOURS.alert : ACCENT }, { text: TRACK.repeat(width - cells(glyphs)), color: FAINT }] : []),
          { text: ` ${padStart(counts[i]!, countWidth)}`, color: HEADING },
          ...(f.forbidden ? [{ text: ' × forbidden', color: STATUS_COLOURS.alert }] : forbidden > 0 ? [{ text: spaces(forbidden) }] : []),
        ]
        return walked(`flow-${i}`, offset + i === selected, segments, columns)
      })
      const note = `${flows.length} of ${grouped(data.flowEdges)}${data.flowsPartial ? '+' : ''} deps`
      return section(body, note)
    },
  }
}

/**
 * This session, in brief: its scans as a timeline, what it touched (files,
 * their dependents, the tests that reach them, or a warning that none do),
 * and the way to Changes, which the marker walks first; `t` copies the test
 * command. Before anything changed, one line.
 */
export function sessionBlock(changes: ChangesInput, look: LookAt | null, selected: number): Block {
  return {
    key: 'session',
    make: (columns): Section => {
      const section = (body: Row[]): Section => ({ key: 'session', title: 'This session', body, ...(body.length === 0 ? { empty: 'nothing changed yet' } : {}) })
      if (changes.files.length === 0) return section([])
      const body: Row[] = []
      const timeline = timelineRow(changes, columns)
      if (timeline !== null) body.push(timeline)
      const dependents = changes.files.reduce((n, f) => n + f.dependents, 0)
      const plus = changes.truncated ? '+' : ''
      const tests = look?.tests ?? changes.tests.length
      // Each figure a group, so a narrow card wraps between them rather than cutting one.
      const said: Segment[][] = [
        [{ text: `${grouped(changes.files.length)}${plus}`, color: HEADING }, { text: ` ${changes.files.length === 1 ? 'file' : 'files'} ·`, dim: true }],
        [{ text: grouped(dependents), color: HEADING }, { text: ` ${dependents === 1 ? 'dependent' : 'dependents'} ·`, dim: true }],
        tests === 0 ? [{ text: '▲ no test reaches them', color: STATUS_COLOURS.warn }] : [{ text: grouped(tests), color: HEADING }, { text: ` ${tests === 1 ? 'test reaches' : 'tests reach'} them`, dim: true }],
      ]
      body.push(...wrapGroups('session-said', said, columns, 1, 3))
      const open: Segment[] = [{ text: '  ' }, button('row:0', 'Changes'), { text: '  every file, its dependents and its tests', dim: true }]
      const copy: Segment[] = look?.command ? [button('tests', 'copy test command', 't')] : []
      const room = columns - 1 - open.reduce((n, s) => n + cells(s.text), 0) - copy.reduce((n, s) => n + cells(s.text), 0)
      body.push(walked('session-open', selected === 0, room >= 2 ? [...open, { text: spaces(room) }, ...copy] : open, columns))
      if (room < 2 && copy.length > 0) body.push({ key: 'session-tests', segments: [{ text: '   ' }, ...copy] })
      return section(body)
    },
  }
}

