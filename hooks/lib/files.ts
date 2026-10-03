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
  fit,
  fitStart,
  linked,
  numberWidth,
  plural,
  sectionRow,
  sectionWidth,
  specWidth,
  spread,
  tableRow,
  tableSpec,
  wrapWords,
} from './rows'
import type { Loc, Row, Segment, TableSpec } from './rows'
import { locIn } from './views'
import type { DetailInput, FileView, Openable } from './views'

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
      components: {
        count: f.components.count,
        truncated: f.components.truncated,
        items: f.components.items.map(c => ({ name: displayName(c), canonical: c.canonical_name, kind: c.kind, boundary: c.boundary, usedBy: c.used_by, loc: locIn(root, f.path, c.line) })),
      },
    },
  }
}

/** What the file's detail walks: the files that depend on it (each opens as its own detail), then its components. */
export function fileDetailList(file: FileView): Openable[] {
  return [
    ...file.dependents.items.map((d): Openable => ({ name: d.path, canonical: d.path, loc: d.loc, file: true })),
    ...file.components.items.map((c): Openable => ({ name: c.name, canonical: c.canonical, loc: c.loc })),
  ]
}

/** A table of one number per row, its bar dropped when every row has the same number: such bars compare nothing. */
function countedSpec(columns: number, names: string[], boundaries: string[], values: number[]): TableSpec {
  const spec = tableSpec(columns, names, boundaries, [numberWidth('', values)], PATH_MAX)
  return values.length > 1 && values.every(v => v === values[0]) ? { ...spec, bar: 0 } : spec
}

/** The `+N more` under a list that holds fewer than its count. */
const moreRow = (key: string, count: number, shown: number, columns: number): Row[] => (count > shown ? [dimRow(key, `   +${count - shown} more`, columns)] : [])

/**
 * One file's detail: its name and own boundary, its path (a link to it) with
 * its language and size, then the files that depend on it with how many
 * relationships run from each and the boundaries a change here reaches, then
 * the components it declares, the most used first.
 */
export function fileDetailRows(detail: DetailInput, columns: number, hues: Hues = NO_HUES, selected = -1): Row[] {
  const f = detail.file ?? null
  if (f === null) {
    const lines = detail.loading ? [`Reading what depends on ${detail.label}…`] : (detail.messages ?? [])
    return [
      blank('gap-detail'),
      { key: 'detail-name', segments: [{ text: fitStart(detail.label, columns), bold: true, color: 'text' }] },
      ...lines.flatMap((line, i) => wrapWords(line, columns).map((part, j) => dimRow(`detail-line-${i}-${j}`, part, columns))),
    ]
  }
  const own: Segment[] = f.boundary === null ? [] : [{ text: boundaryLabel(f.boundary, hues), ...boundaryStyle(f.boundary, hues) }]
  const facts = [f.language.toUpperCase(), ...(f.lines === null ? [] : [plural(f.lines, 'line', 'lines')])].filter(s => s !== '').join(' · ')
  const tail = facts === '' ? '' : ` · ${facts}`
  const rows: Row[] = [
    blank('gap-detail'),
    spread('detail-name', [{ text: fit(baseName(f.path), columns), bold: true, color: 'text' }], own, columns),
    { key: 'detail-place', segments: [linked(fitStart(f.path, Math.max(1, columns - cells(tail))), f.loc, { dim: true }), { text: tail, dim: true }].filter(s => s.text !== '') },
    blank('gap-dependents'),
  ]

  // Who depends on it: what a change here reaches.
  const deps = f.dependents
  const depSpec = countedSpec(columns, deps.items.map(d => d.path), deps.items.map(d => boundaryLabel(d.boundary, hues)), deps.items.map(d => d.edges))
  const depTitle = `Depended on by ${plural(deps.count, 'file', 'files')}`
  const depNote = deps.items.length > 0 ? 'edges' : ''
  rows.push(sectionRow('deps-head', depTitle, depNote, sectionWidth(specWidth(depSpec), depTitle, depNote, columns)))
  if (deps.boundaries.length > 0) rows.push(...reachRows('deps-reach', '', deps.boundaries, columns, hues))
  if (deps.items.length === 0) rows.push(dimRow('deps-none', '   none: no other file depends on it', columns))
  const depMax = Math.max(0, ...deps.items.map(d => d.edges))
  deps.items.forEach((d, i) =>
    rows.push(tableRow(`dep-${i}`, { name: d.path, boundary: d.boundary, values: [d.edges], max: depMax, selected: i === selected, cutStart: true, press: `row:${i}` }, depSpec, hues)),
  )
  rows.push(...moreRow('deps-more', deps.count, deps.items.length, columns))

  // What it declares, the most used first; they share the file's boundary, so no column for it.
  const comps = f.components
  const offset = deps.items.length
  const compSpec = countedSpec(columns, comps.items.map(c => c.name), [], comps.items.map(c => c.usedBy))
  const compTitle = `Declares ${plural(comps.count, 'component', 'components')}`
  const compNote = comps.items.length > 0 ? 'used by' : ''
  rows.push(blank('gap-components'), sectionRow('comps-head', compTitle, compNote, sectionWidth(specWidth(compSpec), compTitle, compNote, columns)))
  if (comps.items.length === 0) rows.push(dimRow('comps-none', '   none', columns))
  const compMax = Math.max(0, ...comps.items.map(c => c.usedBy))
  comps.items.forEach((c, i) =>
    rows.push(tableRow(`comp-${i}`, { name: c.name, boundary: null, values: [c.usedBy], max: compMax, selected: offset + i === selected, press: `row:${offset + i}` }, compSpec, hues)),
  )
  rows.push(...moreRow('comps-more', comps.count, comps.items.length, columns))
  return rows
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
 * its own boundary, then how many more there are.
 */
export function driftRows(drift: DriftInput, columns: number, hues: Hues = NO_HUES, selected = -1): Row[] {
  const spec = { ...tableSpec(columns, drift.items.map(i => i.path), drift.items.map(i => boundaryLabel(i.boundary)), [], PATH_MAX), bar: 0 }
  const title = 'Drifted since the snapshot'
  const note = drift.truncated && drift.count <= drift.items.length ? `${drift.count}+` : String(drift.count)
  const rows: Row[] = [blank('gap-drift'), sectionRow('drift-head', title, note, sectionWidth(specWidth(spec), title, note, columns))]
  drift.items.forEach((i, n) =>
    rows.push(tableRow(`drift-${n}`, { name: i.path, boundary: i.boundary, values: [], max: 0, selected: n === selected, mark: statusMark(i.change), cutStart: true, press: `row:${n}` }, spec, hues)),
  )
  return [...rows, ...moreRow('drift-more', drift.count, drift.items.length, columns)]
}
