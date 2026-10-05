/**
 * The path explorer: how one component reaches another (`knossos
 * path-between`), drawn as a chain of boxes down the pane, each hop's
 * arrow labelled with its kind and the file and line it is written at.
 *
 * Pure. A head card says which two were picked and how the search went (in
 * the warning colour when only the other way had a route); every route
 * found is listed under it, each a press that draws it instead; the chosen
 * route is the diagram below that list. Every box is a row the marker walks and `o`
 * opens as its detail.
 */
import type { PathBetween, RouteState } from '../../types'
import { noteOf } from './cards'
import type { Arrangement, Block } from './cards'
import { box, boxWidth, canvas, DIAGRAM_MIN, LABEL_MAX, line, toRows, write } from './diagram'
import type { Canvas } from './diagram'
import { ACCENT, boundaryColour, boundaryLabel, FAINT, NO_HUES, SECONDARY, SELECTED_BG, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { button, cells, chip, displayName, fit, placeOf, plural, segmentsWidth, spaces, tinted, wrapWords } from './rows'
import type { Loc, Row, Segment } from './rows'
import { locIn } from './views'
import type { Openable } from './views'

/** One component on a route. */
export type RouteNode = { name: string; canonical: string; kind: string; boundary: string | null }
/** One hop of a route: its kind and where it is written. */
export type RouteHop = { kind: string; place: string; loc: Loc | null }

/** The route view's input. */
export type RouteInput = {
  from: string
  to: string
  loading: boolean
  /** What to say about the search, a sentence each; the last in the warning tone when `warn`. */
  said: string[]
  warn: boolean
  routes: { nodes: RouteNode[]; hops: RouteHop[] }[]
  /** The route drawn, an index into `routes`. */
  index: number
  truncated: boolean
}

/** The route view of the stored answer, for the ends picked (shown by their labels); `root` places each hop's file. */
export function routeInput(state: RouteState | null, from: { name: string; label: string }, to: { name: string; label: string }, index: number, root: string | null): RouteInput {
  const base = { from: from.label, to: to.label, routes: [], index: 0, truncated: false }
  if (state === null || state.from !== from.name || state.to !== to.name || (state.phase === 'loading' && state.answer === null)) return { ...base, loading: true, said: [`Looking for a route from ${from.label} to ${to.label}…`], warn: false }
  const answer: PathBetween | null = state.answer
  if (answer === null) return { ...base, loading: false, said: ['knossos did not answer; the route is looked for again with the next snapshot.'], warn: true }
  if (answer.status === 'not-found' || answer.status === 'ambiguous') {
    const end = answer.unresolved === 'to' ? to.label : from.label
    return { ...base, loading: false, said: [answer.status === 'not-found' ? `knossos knows no component called ${end}.` : `More than one component goes by ${end}; pick it from the finder by its full name.`], warn: true }
  }
  if (answer.status !== 'ok') return { ...base, loading: false, said: ['knossos did not look for a route.'], warn: true }
  const routes = answer.routes.map(r => ({
    nodes: r.nodes.map(n => ({ name: displayName(n), canonical: n.canonical_name, kind: n.kind, boundary: n.boundary })),
    hops: r.hops.map(h => ({ kind: h.kind, place: placeOf(h.path, h.line), loc: locIn(root, h.path, h.line) })),
  }))
  const shown = Math.min(Math.max(0, index), Math.max(0, routes.length - 1))
  const depth = `within ${plural(6, 'hop', 'hops')}`
  if (routes.length === 0) return { ...base, loading: false, said: [`No route from ${from.label} to ${to.label}, either way, ${depth}.`], warn: true }
  const found = `${plural(routes.length, 'route', 'routes')}${answer.truncated ? ' (the search stopped at its bounds)' : ''}, the strongest first.`
  const said = answer.reversed ? [`No route from ${from.label} to ${to.label} ${depth}; ${to.label} reaches ${from.label}: ${found}`] : [`${found}`]
  return { ...base, loading: state.phase === 'loading', said, warn: answer.reversed, routes, index: shown, truncated: answer.truncated }
}

/** The components of the route drawn: what the marker walks, first to last. */
export const routeList = (input: RouteInput): Openable[] => (input.routes[input.index]?.nodes ?? []).map(n => ({ name: n.name, canonical: n.canonical }))

/** Rows a box and the hop under it take: the box, the line, the arrowhead. */
const STEP = 5

/**
 * One route as boxes down the width, each its component's name in a frame
 * of its boundary's colour, the hop from it to the next an arrow labelled
 * with the kind and the place. The marked box is the accent. Narrower than
 * {@link DIAGRAM_MIN}, the same as lines of text.
 */
export function routeRows(route: { nodes: RouteNode[]; hops: RouteHop[] }, columns: number, selected: number, hues: Hues = NO_HUES): Row[] {
  return routeDiagram(route, columns, selected, hues).rows
}

/** {@link routeRows} with the canvas it was drawn on (null when drawn as text), for the checks every diagram keeps. */
export function routeDiagram(route: { nodes: RouteNode[]; hops: RouteHop[] }, columns: number, selected: number, hues: Hues = NO_HUES): { rows: Row[]; canvas: Canvas | null } {
  if (columns < DIAGRAM_MIN) {
    const rows = route.nodes.flatMap((n, i): Row[] => {
      const own = chip(n.boundary, hues)
      const named: Row = { key: `route-node-${i}`, segments: [{ text: i === selected ? '›' : ' ', color: ACCENT, bold: true }, { text: ' ' }, button(`row:${i}`, fit(n.name, Math.max(4, columns - 3 - segmentsWidth(own) - 1))), ...(own.length > 0 ? [{ text: ' ' }, ...own] : [])] }
      const hop = route.hops[i]
      return hop === undefined ? [named] : [named, { key: `route-hop-${i}`, segments: [{ text: '   ↓ ', color: FAINT }, { text: fit(`${hop.kind}${hop.place === '' ? '' : ` · ${hop.place}`}`, Math.max(1, columns - 5)), dim: true }] }]
    })
    return { rows, canvas: null }
  }
  const label = Math.min(LABEL_MAX, Math.max(...route.nodes.map(n => cells(n.name))))
  const width = Math.min(columns, boxWidth(label))
  const centre = Math.floor(width / 2)
  const c = canvas(columns, route.nodes.length * STEP - 2)
  route.nodes.forEach((n, i) => {
    const y = i * STEP
    const colour = boundaryColour(n.boundary, hues)
    box(c, 0, y, width, { key: `route-${i}`, label: n.name, ...(colour === undefined ? {} : { color: colour }), press: { id: `row:${i}`, label: n.name }, selected: i === selected })
    // The boundary beside the box, in its colour's swatch, where the width leaves room.
    const own = n.boundary === null ? '' : boundaryLabel(n.boundary, hues)
    const beside = columns - width - 2
    if (own !== '' && beside >= 6) write(c, width + 2, y + 1, [{ text: '■', ...(colour === undefined ? { dim: true } : { color: colour }) }, { text: ` ${fit(own, beside - 2)}`, dim: true }])
    const hop = route.hops[i]
    if (hop === undefined) return
    line(c, [[centre, y + 2], [centre, y + 4]], { color: FAINT }, true)
    const room = columns - centre - 3
    if (room > 4) {
      const kind = fit(hop.kind, room)
      const place = hop.place === '' || cells(kind) + 3 >= room ? '' : ` · ${fit(hop.place, room - cells(kind) - 3)}`
      write(c, centre + 2, y + 3, [{ text: kind, color: SECONDARY }, ...(place === '' ? [] : [{ text: place, dim: true }])])
    }
  })
  return { rows: toRows(c, 'route'), canvas: c }
}

/**
 * The path explorer's cards: the ends and how the search went, every
 * route found (each a press that draws it), and the route drawn.
 */
export function routeArrangement(input: RouteInput, selected: number, hues: Hues = NO_HUES): Arrangement {
  const head: Block = {
    key: 'route',
    make: columns => ({
      key: 'route',
      title: `${input.from} → ${input.to}`,
      ...(input.loading ? { note: noteOf('looking…') } : {}),
      body: input.said.flatMap((text, i) =>
        wrapWords(text, Math.max(1, columns - 3)).map((part, j): Row => ({ key: `route-said-${i}-${j}`, segments: [{ text: input.warn && j === 0 ? '▲  ' : '   ', color: STATUS_COLOURS.warn }, { text: part, ...(input.warn ? { color: STATUS_COLOURS.warn } : { dim: true }) }] })),
      ),
    }),
  }
  const route = input.routes[input.index]
  if (route === undefined) return { left: [head] }
  const drawn: Block = {
    key: 'route-drawn',
    make: columns => ({ key: 'route-drawn', title: input.routes.length > 1 ? `Route ${input.index + 1}` : 'Route', subtitle: plural(route.hops.length, 'hop', 'hops'), glyph: '⇢', body: routeRows(route, columns, selected, hues) }),
  }
  const others: Block = {
    key: 'route-others',
    make: columns =>
      input.routes.length < 2
        ? null
        : {
            key: 'route-others',
            title: 'Every route found',
            body: input.routes.map((r, i): Row => {
              const on = i === input.index
              // The routes all start alike: each is told from the last component they share, so the list reads by where they part.
              const from = Math.max(0, shared(input.routes) - 1)
              const chain = `${from > 0 ? '… → ' : ''}${r.nodes.slice(from).map(n => n.name).join(' → ')}`
              const lead: Segment[] = [{ text: on ? '›' : ' ', color: ACCENT, bold: true }, { text: `${i + 1} `, dim: true }]
              const hops = ` · ${plural(r.hops.length, 'hop', 'hops')}`
              const text = fit(chain, Math.max(4, columns - segmentsWidth(lead) - cells(hops)))
              const segments: Segment[] = [...lead, button(`route-pick:${i}`, text), { text: hops, dim: true }, { text: spaces(columns - segmentsWidth(lead) - cells(text) - cells(hops)) }]
              return on ? { key: `route-other-${i}`, segments: tinted(segments, SELECTED_BG), tint: SELECTED_BG } : { key: `route-other-${i}`, segments }
            }),
          },
  }
  // The list of routes first: the route drawn is the card that takes the pane's height.
  return { top: [head], left: [others, drawn] }
}

/** How many components, from the first, every route shares. */
function shared(routes: { nodes: RouteNode[] }[]): number {
  const first = routes[0]?.nodes ?? []
  let n = 0
  while (n < first.length && routes.every(r => r.nodes[n]?.canonical === first[n]!.canonical)) n++
  return routes.length > 1 ? n : 0
}
