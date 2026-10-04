/**
 * The finder (`f`): every component and file of the project by the letters
 * of its name, in order, the closest first, over whatever tab is open.
 *
 * Pure. What was typed and what `graph-search` answered in, the finder's
 * card out: its field, then the matches as a table, each a row the marker
 * walks and Enter (or a click) opens: a component as its detail, a file as
 * the file's. The search itself runs on a timer after each keystroke, never
 * in a render; until the answer for what is typed lands, the card says it is
 * searching and keeps the last matches.
 */
import type { SearchState } from '../../types'
import { noteOf } from './cards'
import type { Block } from './cards'
import { boundaryLabel, NO_HUES } from './palette'
import type { Hues } from './palette'
import { dimRow, displayName, placeOf, tableRow, tableSpec, wrapWords } from './rows'
import type { Loc, Row, Tier } from './rows'
import { locIn } from './views'
import type { Openable } from './views'

/** One match as the finder lists it: a component, or (`file`) a file by its path. */
export type Found = { name: string; canonical: string; kind: string; file: boolean; boundary: string | null; place: string; loc: Loc | null }

/** The finder as the pane draws it: what is typed, whether its answer is still on the way, and the matches last read. */
export type FinderInput = {
  query: string
  searching: boolean
  results: Found[]
  truncated: boolean
  failed: boolean
  /** While it picks where a route ends (`p`): the component the route starts at, by its label. Only components are listed then. */
  routeFrom?: string
}

/** The fewest matches the finder lists, however short the pane. */
const FOUND_MIN = 5

/** The finder's view of the search state; `root` places each match on disk. */
export function finderInput(state: SearchState, root: string | null, routeFrom: string | null = null): FinderInput {
  const answer = state.answer
  // A route runs between components: while one is picked, files are not offered.
  const results = (answer?.status === 'ok' ? answer.results : []).filter(r => routeFrom === null || r.type !== 'file').map(
    (r): Found => ({
      name: r.type === 'file' ? r.name : displayName({ name: r.name, canonical_name: r.canonical_name, kind: r.kind }),
      canonical: r.canonical_name,
      kind: r.type === 'file' ? 'file' : r.kind,
      file: r.type === 'file',
      boundary: r.boundary,
      place: r.type === 'file' ? '' : placeOf(r.path, r.line),
      loc: locIn(root, r.path, r.line),
    }),
  )
  const typed = state.query.trim()
  return {
    query: state.query,
    searching: typed !== '' && (state.phase === 'searching' || state.for !== typed),
    results: typed === '' ? [] : results,
    truncated: answer?.status === 'ok' && answer.truncated,
    failed: typed !== '' && state.phase === 'idle' && state.for === typed && answer?.status !== 'ok',
    ...(routeFrom === null ? {} : { routeFrom }),
  }
}

/** The matches the marker walks: each opens as a component's or a file's detail. */
export function finderList(finder: FinderInput): Openable[] {
  return finder.results.map(f => ({ name: f.name, canonical: f.canonical, loc: f.loc, ...(f.file ? { file: true } : {}) }))
}

/** The finder's card: its field, then the matches as a table, `limit` of them around the marker. */
export function finderBlock(finder: FinderInput, selected: number, tier: Tier, hues: Hues = NO_HUES): Block {
  return {
    key: 'find',
    grow: { length: finder.results.length, min: FOUND_MIN },
    make: (columns, limit) => {
      const rows: Row[] = [{ key: 'find-row', segments: [{ text: '   find: ', dim: true }, { text: finder.query, field: { id: 'find', value: finder.query, placeholder: 'letters of a name, in order' } }] }]
      const said = (key: string, text: string) => wrapWords(text, Math.max(1, columns - 3)).forEach((line, i) => rows.push(dimRow(`${key}-${i}`, `   ${line}`, columns)))
      if (finder.routeFrom !== undefined && finder.query.trim() === '') said('find-route', `A route from ${finder.routeFrom}: type the letters of the component it should reach. Enter takes the first match; a click takes any.`)
      else if (finder.query.trim() === '') said('find-hint', 'Type letters of a component or file name, in order. Enter opens the first match; a click opens any.')
      else if (finder.failed) said('find-failed', 'knossos did not answer; type on to ask again.')
      else if (!finder.searching && finder.results.length === 0) said('find-none', `Nothing matches "${finder.query.trim()}".`)
      const list = finder.results
      if (list.length > 0) {
        // The marked match in the middle of what the card holds, wherever it is in the list.
        const n = Math.max(1, limit)
        const start = list.length <= n ? 0 : Math.max(0, Math.min(selected - Math.floor(n / 2), list.length - n))
        const shown = list.slice(start, start + n)
        const spec = tableSpec(columns, shown.map(f => f.name), shown.map(f => boundaryLabel(f.boundary, hues)), [], undefined, { tier, kinds: tier === 'narrow' ? [] : shown.map(f => f.kind), places: tier === 'narrow' ? [] : shown.map(f => f.place) })
        shown.forEach((f, j) => {
          const i = start + j
          rows.push(tableRow(`found-${i}`, { name: f.name, boundary: f.boundary, values: [], max: 0, kind: f.kind, place: f.place, placeLoc: f.loc, path: f.file, press: `row:${i}`, selected: i === selected }, { ...spec, bar: 0 }, hues))
        })
        const below = list.length - start - shown.length
        const parts = [...(start > 0 ? [`${start} above ↑`] : []), ...(below > 0 ? [`${below} more ↓`] : [])]
        if (parts.length > 0) rows.push(dimRow('found-more', `   ${parts.join(' · ')} · type more to narrow`, columns))
      }
      const note = finder.searching ? 'searching…' : finder.query.trim() === '' ? 'components and files' : `${list.length}${finder.truncated ? '+' : ''} found`
      return { key: 'find', title: finder.routeFrom === undefined ? 'Find' : `Route from ${finder.routeFrom} to…`, note: noteOf(note), body: rows }
    },
  }
}
