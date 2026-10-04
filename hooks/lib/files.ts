/**
 * Files on the pane: one file's detail (the files that depend on it and what
 * it declares), which a touched, drifted or dependent file opens, and the
 * files drifted since the snapshot, listed under the header on `d`.
 *
 * Pure, like the rest of the layout: envelopes in, rows of styled segments
 * out. A file row is walkable like a component's: `o` opens its detail, `e`
 * the file itself in the editor.
 */
import type { Dashboard, DetailState, Drifted, FileDetail, Inspected } from '../../types'
import { rankIn, reachRows, statusMark } from './changes'
import { diffBlock } from './diff'
import { moreRows, noteOf, windowOf } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { fileDetailLines } from './envelopes'
import { boundaryLabel, NO_HUES } from './palette'
import type { Hues } from './palette'
import {
  baseName,
  blank,
  boundaryStyle,
  cells,
  dimRow,
  displayName,
  fitStart,
  linked,
  numberWidth,
  plural,
  tableRow,
  tableSpec,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment, TableSpec, Tier } from './rows'
import { hoodBlock, locIn } from './views'
import type { DetailInput, FileView, HoodSide, Openable } from './views'

/** Paths longer than this are cut from the front in a table, as on Changes. */
const PATH_MAX = 56

/** The files drifted since the snapshot: how many in all, and the first few by path with how each drifted. */
export type DriftInput = { count: number; truncated: boolean; items: (Drifted & { loc: Loc | null })[] }

/**
 * The detail's view of the file on show, from the stored lookup: loading,
 * what to say instead (not in the graph, nothing answered), or the file.
 * `root` places the files on disk; `hues` orders the dependents' boundaries
 * as the project's colours do.
 */
export function fileDetailInput(shown: Inspected, state: DetailState | null, root: string | null = null, hues: Hues = NO_HUES): DetailInput {
  const base = { label: shown.label, loading: false, messages: null, component: null, file: null }
  if (state === null || state.name !== shown.name || state.file !== true || state.phase === 'loading') return { ...base, loading: true }
  const answer: FileDetail | null = state.fileDetail ?? null
  if (answer === null) return { ...base, messages: [`No details for ${shown.label}: knossos said nothing.`] }
  const f = answer.file
  if (answer.status !== 'ok' || f === null) return { ...base, messages: fileDetailLines(answer, shown.label) }
  const rank = rankIn(hues)
  return {
    ...base,
    file: {
      path: f.path,
      language: f.language,
      lines: f.lines,
      boundary: f.boundary,
      loc: locIn(root, f.path),
      dependents: {
        count: f.dependents.count,
        truncated: f.dependents.truncated,
        boundaries: [...f.dependents.boundaries].sort((a, b) => rank(a) - rank(b) || a.localeCompare(b)),
        items: f.dependents.items.map(d => ({ ...d, loc: locIn(root, d.path) })),
      },
      uses: f.uses === undefined ? null : { count: f.uses.count, truncated: f.uses.truncated, items: f.uses.items.map(u => ({ ...u, loc: locIn(root, u.path) })) },
      components: {
        count: f.components.count,
        truncated: f.components.truncated,
        items: f.components.items.map(c => ({ name: displayName(c), canonical: c.canonical_name, kind: c.kind, boundary: c.boundary, usedBy: c.used_by, loc: locIn(root, f.path, c.line) })),
      },
    },
  }
}

/** What the file's detail walks: the files that depend on it, the files it depends on (each opens as its own detail), then its components. */
export function fileDetailList(file: FileView): Openable[] {
  return [
    ...file.dependents.items.map((d): Openable => ({ name: d.path, canonical: d.path, loc: d.loc, file: true })),
    ...(file.uses?.items ?? []).map((u): Openable => ({ name: u.path, canonical: u.path, loc: u.loc, file: true })),
    ...file.components.items.map((c): Openable => ({ name: c.name, canonical: c.canonical, loc: c.loc })),
  ]
}

/** A table of one number per row, its bar dropped when every row has the same number: such bars compare nothing. */
function countedSpec(columns: number, names: string[], boundaries: string[], values: number[], tier: Tier): TableSpec {
  const spec = tableSpec(columns, names, boundaries, [numberWidth('', values)], PATH_MAX, { tier, compact: false })
  return values.length > 1 && values.every(v => v === values[0]) ? { ...spec, bar: 0 } : spec
}

/** The `+N not listed` under a list that holds fewer than its count. */
const moreRow = (key: string, count: number, shown: number, columns: number): Row[] => (count > shown ? [dimRow(key, `   +${count - shown} not listed`, columns)] : [])

/** The fewest dependents and components the file detail lists, however short the pane. */
const LIST_MIN = 5

/**
 * One file's detail: a card naming it and its own boundary, its path (a
 * link to it) with its language and size; the files that depend on it with
 * how many relationships run from each and the boundaries a change here
 * reaches; then the components it declares, the most used first. Its change
 * since the session began, when it was opened as one, follows the
 * dependents (below both lists when wide, where they stand side by side).
 */
export function fileDetailArrangement(detail: DetailInput, tier: Tier, hues: Hues = NO_HUES, selected = -1): Arrangement {
  const f = detail.file ?? null
  const diff = detail.diff ? [diffBlock(detail.diff)] : []
  if (f === null) {
    const lines = detail.loading ? [`Reading what depends on ${detail.label}…`] : (detail.messages ?? [])
    const head: Block = {
      key: 'detail',
      make: columns => ({
        key: 'detail',
        title: fitStart(detail.label, Math.max(1, columns - 4)),
        body: lines.flatMap((line, i) => wrapWords(line, columns).map((part, j) => dimRow(`detail-line-${i}-${j}`, part, columns))),
      }),
    }
    // A deleted file is in no graph, yet its change is all there is to see.
    return { left: [head, ...diff] }
  }
  const own: Segment[] = f.boundary === null ? [] : [{ text: boundaryLabel(f.boundary, hues), ...boundaryStyle(f.boundary, hues) }]
  const facts = [f.language.toUpperCase(), ...(f.lines === null ? [] : [plural(f.lines, 'line', 'lines')])].filter(s => s !== '').join(' · ')
  const tail = facts === '' ? '' : ` · ${facts}`
  const head: Block = {
    key: 'detail',
    make: columns => ({
      key: 'detail',
      title: baseName(f.path),
      note: own,
      body: [{ key: 'detail-place', segments: [linked(fitStart(f.path, Math.max(1, columns - cells(tail))), f.loc, { dim: true }), { text: tail, dim: true }].filter(s => s.text !== '') }],
    }),
  }

  // Who depends on it (what a change here reaches) and what it depends on, drawn round it.
  const deps = f.dependents
  const uses = f.uses ?? { count: 0, truncated: false, items: [] }
  const table = (key: string, items: { path: string; edges: number; boundary: string | null }[], offset: number, columns: number, limit: number): Row[] => {
    const spec = countedSpec(columns, items.map(d => d.path), items.map(d => boundaryLabel(d.boundary, hues)), items.map(d => d.edges), tier)
    const max = Math.max(0, ...items.map(d => d.edges))
    const local = selected - offset
    const window = windowOf(items.length, limit, local >= 0 && local < items.length ? local : -1)
    return [
      ...items.slice(window.start, window.end).map((d, n) => {
        const i = offset + window.start + n
        return tableRow(`${key}-${window.start + n}`, { name: d.path, boundary: d.boundary, values: [d.edges], max, selected: i === selected, cutStart: true, press: `row:${i}` }, spec, hues)
      }),
      ...moreRows(`${key}-window`, window, items.length, columns),
    ]
  }
  const side = (title: string, count: number, items: { path: string; edges: number; boundary: string | null }[], offset: number): HoodSide => ({
    title,
    count,
    items: items.map((d, n) => ({ name: baseName(d.path), boundary: d.boundary, edges: d.edges, press: `row:${offset + n}` })),
  })
  const hood = hoodBlock({ name: baseName(f.path), boundary: f.boundary }, side('Depended on by', deps.count, deps.items, 0), side('Depends on', uses.count, uses.items, deps.items.length), selected, hues, (columns, limit) => [
    ...(deps.items.length === 0 ? [dimRow('deps-none', '   none: no other file depends on it', columns)] : table('dep', deps.items, 0, columns, limit)),
    ...moreRow('deps-more', deps.count, deps.items.length, columns),
    ...(f.uses === null ? [] : [blank('uses-gap'), dimRow('uses-title', `   Depends on · ${plural(uses.count, 'file', 'files')}`, columns), ...table('use', uses.items, deps.items.length, columns, limit), ...moreRow('uses-more', uses.count, uses.items.length, columns)]),
  ])
  // The boundaries a change here reaches, above the drawing.
  const reached: Block = {
    ...hood,
    make: (columns, limit) => {
      const section = hood.make(columns, limit)
      if (section === null || deps.boundaries.length === 0) return section
      return { ...section, body: [...reachRows('deps-reach', '', deps.boundaries, columns, hues), blank('deps-reach-gap'), ...section.body] }
    },
  }

  // What it declares, the most used first; they share the file's boundary, so no column for it.
  const comps = f.components
  const offset = deps.items.length + uses.items.length
  const compBlock: Block = {
    key: 'comps',
    grow: { length: comps.items.length, min: LIST_MIN },
    make: (columns, limit) => {
      const spec = countedSpec(columns, comps.items.map(c => c.name), [], comps.items.map(c => c.usedBy), tier)
      const rows: Row[] = []
      if (comps.items.length === 0) rows.push(dimRow('comps-none', '   none', columns))
      const max = Math.max(0, ...comps.items.map(c => c.usedBy))
      const window = windowOf(comps.items.length, limit, selected >= offset ? selected - offset : -1)
      comps.items.slice(window.start, window.end).forEach((c, n) => {
        const i = offset + window.start + n
        rows.push(tableRow(`comp-${window.start + n}`, { name: c.name, boundary: null, values: [c.usedBy], max, selected: i === selected, press: `row:${i}` }, spec, hues))
      })
      rows.push(...moreRows('comps-window', window, comps.items.length, columns), ...moreRow('comps-more', comps.count, comps.items.length, columns))
      return { key: 'comps', title: 'Declares', subtitle: plural(comps.count, 'component', 'components'), note: noteOf(comps.items.length > 0 ? 'used by' : ''), body: rows }
    },
  }
  return { top: [head], left: [reached, compBlock], bottom: diff, order: [head, reached, ...diff, compBlock] }
}

/** The files drifted since the snapshot, from a dashboard that names them; null when it names none. */
export function driftInput(d: Pick<Dashboard, 'freshness' | 'project_root'>): DriftInput | null {
  const items = d.freshness.drifted ?? []
  if (items.length === 0) return null
  return {
    count: Math.max(d.freshness.drift_files, items.length),
    truncated: d.freshness.drifted_truncated === true,
    items: items.map(i => ({ ...i, loc: i.change === 'deleted' ? null : locIn(d.project_root, i.path) })),
  }
}

/** What the drifted list walks: each file opens as its detail, as the snapshot holds it. */
export const driftList = (drift: DriftInput): Openable[] => drift.items.map(i => ({ name: i.path, canonical: i.path, loc: i.loc, file: true }))

/**
 * The files drifted since the snapshot, under the header where their count
 * stands: each marked as Changes marks a file (`+` added, `−` deleted), with
 * its own boundary, `limit` of them around the marker, then how many more
 * there are.
 */
export function driftSection(drift: DriftInput, columns: number, limit: number, hues: Hues = NO_HUES, selected = -1): Section {
  const spec = { ...tableSpec(columns, drift.items.map(i => i.path), drift.items.map(i => boundaryLabel(i.boundary)), [], PATH_MAX), bar: 0 }
  const note = drift.truncated && drift.count <= drift.items.length ? `${drift.count}+` : String(drift.count)
  const window = windowOf(drift.items.length, limit, selected)
  const rows: Row[] = drift.items
    .slice(window.start, window.end)
    .map((i, n) =>
      tableRow(`drift-${window.start + n}`, { name: i.path, boundary: i.boundary, values: [], max: 0, selected: window.start + n === selected, mark: statusMark(i.change), cutStart: true, press: `row:${window.start + n}` }, spec, hues),
    )
  rows.push(...moreRows('drift-window', window, drift.items.length, columns), ...moreRow('drift-more', drift.count, drift.items.length, columns))
  return { key: 'drift', title: 'Drifted since the snapshot', note: noteOf(note), body: rows }
}
