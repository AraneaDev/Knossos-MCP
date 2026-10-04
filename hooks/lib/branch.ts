/**
 * The Branch tab: what the checked-out branch did to the architecture, the
 * graph now against the snapshot taken where it left its default branch
 * (`knossos branch-diff`).
 *
 * Pure. The answer in, cards out: first where the comparison stands (the
 * branch, its merge base, and how near to it the snapshot compared with
 * is, said plainly when none near it is kept), then what is new since:
 * dependencies crossing from one boundary into another, cycles, hubs more
 * depended on, dead code, and policy violations. Every listed component is
 * a row the marker walks and `o` opens as its detail.
 */
import type { BranchDiff, BranchItem, BranchState } from '../../types'
import { stamp } from './changes'
import { noteOf, windowOf, moreRows } from './cards'
import type { Arrangement, Block, Section } from './cards'
import { ACCENT, NO_HUES, SELECTED_BG, STATUS_COLOURS } from './palette'
import type { Hues } from './palette'
import { button, cells, chip, clip, dimRow, displayName, fit, grouped, placeOf, plural, segmentsWidth, shortName, spaces, tinted, wrapWords } from './rows'
import type { Loc, Row, Segment } from './rows'
import { issueGrid, locIn } from './views'
import type { Openable } from './views'

/** One component the Branch tab lists: what it shows, what it opens, where it is. */
export type BranchLine = { name: string; canonical: string; boundary: string | null; place: string; loc: Loc | null }

/** The Branch tab's view of the comparison. */
export type BranchInput = {
  /** Where the comparison stands, as sentences: the branch and its merge base, the snapshot compared with, or why there is none. */
  said: string[]
  /** Said in the warning tone: the comparison is partial, or none could be made. */
  warn: boolean
  loading: boolean
  crossing: { count: number; items: { source: BranchLine; target: BranchLine }[] } | null
  cycles: { count: number; items: { size: number; members: BranchLine[] }[] } | null
  hubs: { count: number; items: { component: BranchLine; before: number; after: number }[] } | null
  dead: { count: number; items: BranchLine[] } | null
  /** Null when the project declares no policies, or the comparison holds no check. */
  violations: { count: number; items: { policy: string; source: BranchLine; target: string }[]; truncated: boolean } | null
}

/** A git commit as the tab names it: its first seven characters. */
const short = (rev: string): string => rev.slice(0, 7)

/** A component of the answer as the tab lists it; `root` places it on disk. */
function lineOf(item: BranchItem, root: string | null): BranchLine {
  return { name: displayName({ name: item.name, canonical_name: item.canonical_name, kind: item.kind }), canonical: item.canonical_name, boundary: item.boundary, place: placeOf(item.path, item.line), loc: locIn(root, item.path, item.line) }
}

/** Where the comparison stands, in a sentence or two, and whether that is a warning. */
function standing(answer: BranchDiff): { said: string[]; warn: boolean } {
  const target = answer.default_branch ?? 'the default branch'
  const mb = answer.merge_base
  const left = mb === null || mb === undefined ? '' : ` It left ${target} at ${short(mb.rev)} (${stamp(mb.at * 1000)}), ${plural(answer.ahead ?? 0, 'commit', 'commits')} ago.`
  const branch = answer.branch === null || answer.branch === undefined ? 'This detached checkout' : `${answer.branch}`
  if (answer.status === 'on-default') return { said: [`${branch} is ${target} itself: there is no branch to compare.`], warn: false }
  if (answer.status === 'no-git') return { said: ['No git repository, or git did not answer: there is no branch to compare.'], warn: true }
  if (answer.status === 'no-default') return { said: ['No default branch (origin/HEAD, main or master) to compare with.'], warn: true }
  if (answer.status !== 'ok' && answer.status !== 'no-snapshot') return { said: ['knossos did not compare the branch.'], warn: true }
  const base = answer.base ?? null
  if (base === null) {
    return { said: [`${branch} against ${target}.${left}`, `No snapshot near the merge base is retained, so there is nothing to compare with. A scan of ${target} (with snapshots kept) gives this tab its base.`], warn: true }
  }
  const at = `${short(base.rev)}, ${base.at.replace('T', ' ').replace(/:\d\dZ$/, ' UTC')}`
  if (base.match === 'exact') return { said: [`${branch} against ${target}.${left}`, `Compared with the snapshot taken at the merge base (${at}).`], warn: false }
  if (base.match === 'before') return { said: [`${branch} against ${target}.${left}`, `Compared with the nearest snapshot before the merge base (${at}, ${plural(base.commits, 'commit', 'commits')} earlier): what ${target} changed in between counts as the branch's.`], warn: false }
  return {
    said: [
      `${branch} against ${target}.${left}`,
      `No snapshot near the merge base is retained. Compared with the oldest one after it (${at}), which already holds ${grouped(base.commits)} of the branch's ${grouped(answer.ahead ?? base.commits)} commits: what those changed is not shown.`,
    ],
    warn: true,
  }
}

/** The tab's view of the stored comparison; `root` places each component on disk. */
export function branchInput(state: BranchState | null, root: string | null): BranchInput {
  const none = { crossing: null, cycles: null, hubs: null, dead: null, violations: null }
  // A comparison for a newer graph on its way keeps the last one on show, saying so.
  const loading = state === null || state.phase === 'loading'
  const answer = state?.answer ?? null
  if (answer === null && loading) return { said: ['Comparing the branch with its merge base…'], warn: false, loading: true, ...none }
  if (answer === null) return { said: ['knossos did not answer; the tab asks again when the graph moves.'], warn: true, loading: false, ...none }
  const { said, warn } = standing(answer)
  const c = answer.comparison ?? null
  if (c === null) return { said, warn, loading, ...none }
  const line = (item: BranchItem) => lineOf(item, root)
  return {
    said,
    warn,
    loading,
    crossing: { count: c.crossing.count, items: c.crossing.items.map(i => ({ source: line(i.source), target: line(i.target) })) },
    cycles: { count: c.cycles.count, items: c.cycles.items.map(i => ({ size: i.size, members: i.members.map(line) })) },
    hubs: { count: c.hubs.count, items: c.hubs.items.map(i => ({ component: line(i.component), before: i.before, after: i.after })) },
    dead: { count: c.dead_code.count, items: c.dead_code.items.map(line) },
    violations:
      c.violations === null
        ? null
        : { count: c.violations.count, truncated: c.violations.truncated, items: c.violations.items.map(v => ({ policy: v.policy_id, source: { name: shortName(v.source), canonical: v.source, boundary: null, place: '', loc: null }, target: shortName(v.target) })) },
  }
}

/** How many new things the comparison found: what the tab's badge counts. */
export function branchCount(input: BranchInput): number {
  return (input.crossing?.count ?? 0) + (input.cycles?.count ?? 0) + (input.hubs?.count ?? 0) + (input.dead?.count ?? 0) + (input.violations?.count ?? 0)
}

/** The components the tab walks, card by card: crossing sources, each cycle's first member, grown hubs, new dead code, violation sources. */
export function branchList(input: BranchInput): Openable[] {
  const open = (l: BranchLine): Openable => ({ name: l.name, canonical: l.canonical, loc: l.loc })
  return [
    ...(input.crossing?.items ?? []).map(i => open(i.source)),
    ...(input.cycles?.items ?? []).flatMap(i => (i.members[0] === undefined ? [] : [open(i.members[0])])),
    ...(input.hubs?.items ?? []).map(i => open(i.component)),
    ...(input.dead?.items ?? []).map(open),
    ...(input.violations?.items ?? []).map(i => open(i.source)),
  ]
}

/** The fewest rows each list shows, however short the pane. */
const LIST_MIN = 3

/** One listed row: the marker, a mark, then `content` fitted to what is left, tinted when marked. */
function itemRow(key: string, marked: boolean, mark: Segment, content: Segment[], columns: number): Row {
  const kept = clip([{ text: marked ? '›' : ' ', color: ACCENT, bold: true }, mark, { text: ' ' }, ...content], columns)
  if (!marked) return { key, segments: kept }
  const used = segmentsWidth(kept)
  return { key, segments: tinted([...kept, ...(used < columns ? [{ text: spaces(columns - used) }] : [])], SELECTED_BG), tint: SELECTED_BG }
}

/** A list card: its rows `limit` long around the marker (`offset` is its first row's place in the walk), its count, one line when empty. */
function listBlock<T>(key: string, title: string, list: { count: number; items: T[] } | null, offset: number, selected: number, row: (item: T, i: number, columns: number) => Row, extra: Segment[] = []): Block {
  return {
    key,
    grow: { length: list?.items.length ?? 0, min: LIST_MIN },
    make: (columns, limit) => {
      if (list === null) return null
      const local = selected - offset
      const window = windowOf(list.items.length, limit, local >= 0 && local < list.items.length ? local : -1)
      const body = list.items.slice(window.start, window.end).map((item, n) => row(item, window.start + n, columns))
      body.push(...moreRows(`${key}-window`, window, list.items.length, columns))
      if (list.count > list.items.length) body.push(dimRow(`${key}-more`, `   +${grouped(list.count - list.items.length)} not listed`, columns))
      const note: Segment[] = list.count === 0 ? [{ text: '✓ 0', color: STATUS_COLOURS.ok }] : [{ text: `▲ ${grouped(list.count)}`, color: STATUS_COLOURS.warn }, ...extra]
      const section: Section = { key, title, note, body, empty: 'none' }
      return section
    },
  }
}

/** A component's name as a pressable cut to `width`, then its boundary as a chip when it fits. */
function named(line: BranchLine, press: string, width: number, hues: Hues): Segment[] {
  const own = chip(line.boundary, hues)
  const room = Math.max(1, width - (own.length > 0 ? segmentsWidth(own) + 1 : 0))
  const name = fit(line.name, room)
  return [button(press, name), ...(own.length > 0 && cells(name) + 1 + segmentsWidth(own) <= width ? [{ text: ' ' }, ...own] : [])]
}

/**
 * The Branch tab's cards: where the comparison stands across the pane, then
 * the five lists on a grid of equal rows when wide (empty ones as short rows
 * first), one column narrower.
 */
export function branchArrangement(input: BranchInput, selected: number, hues: Hues = NO_HUES): Arrangement {
  const head: Block = {
    key: 'branch',
    make: columns => ({
      key: 'branch',
      title: 'Against the merge base',
      ...(input.loading ? { note: noteOf('comparing…') } : {}),
      body: input.said.flatMap((text, i) =>
        wrapWords(text, Math.max(1, columns - 3)).map((line, j): Row => ({ key: `branch-said-${i}-${j}`, segments: [{ text: `${i === input.said.length - 1 && input.warn && j === 0 ? '▲' : ' '}  `, color: STATUS_COLOURS.warn }, { text: line, ...(input.warn && i === input.said.length - 1 ? { color: STATUS_COLOURS.warn } : { dim: true }) }] })),
      ),
    }),
  }
  if (input.crossing === null) return { left: [head] }
  const offsets = [0, input.crossing.items.length]
  offsets.push(offsets[1]! + (input.cycles?.items.length ?? 0))
  offsets.push(offsets[2]! + (input.hubs?.items.length ?? 0))
  offsets.push(offsets[3]! + (input.dead?.items.length ?? 0))
  const crossing = listBlock('crossing', 'New cross-boundary dependencies', input.crossing, offsets[0]!, selected, (i, n, columns) => {
    const half = Math.max(8, Math.floor((columns - 7) / 2))
    return itemRow(`crossing-${n}`, offsets[0]! + n === selected, { text: '⇄', dim: true }, [...named(i.source, `row:${offsets[0]! + n}`, half, hues), { text: ' → ', dim: true }, ...chip(i.target.boundary, hues), { text: ` ${fit(i.target.name, Math.max(1, columns - half - 8 - cells(i.target.boundary ?? '')))}` }], columns)
  })
  const cycles = listBlock('branch-cycles', 'New cycles', input.cycles, offsets[1]!, selected, (c, n, columns) => {
    const first = c.members[0]
    const rest = c.members.slice(1).map(m => m.name).join(' → ')
    const head: Segment[] = first === undefined ? [] : [button(`row:${offsets[1]! + n}`, fit(first.name, Math.max(1, Math.floor(columns / 2))))]
    return itemRow(`branch-cycle-${n}`, offsets[1]! + n === selected, { text: '↻', color: ACCENT }, [{ text: `${c.size} `, dim: true }, ...head, ...(rest === '' ? [] : [{ text: ` → ${rest}${c.size > c.members.length ? ' → …' : ''}`, dim: true }])], columns)
  })
  const hubs = listBlock('grown', 'Hubs that grew', input.hubs, offsets[2]!, selected, (h, n, columns) => {
    const figures = `${grouped(h.before)} → ${grouped(h.after)}`
    const delta = `+${grouped(h.after - h.before)}`
    const room = Math.max(8, columns - 3 - cells(figures) - cells(delta) - 3)
    const name = named(h.component, `row:${offsets[2]! + n}`, room, hues)
    return itemRow(`grown-${n}`, offsets[2]! + n === selected, { text: '◎', dim: true }, [...name, { text: spaces(room - segmentsWidth(name) + 1) }, { text: figures, dim: true }, { text: ' ' }, { text: delta, color: STATUS_COLOURS.warn }], columns)
  })
  const dead = listBlock('branch-dead', 'New dead code', input.dead, offsets[3]!, selected, (d, n, columns) => {
    const place = d.place === '' ? [] : [{ text: '  ' }, { text: fit(d.place, Math.max(4, Math.floor(columns / 3))), dim: true, ...(d.loc === null ? {} : { link: d.loc }) }]
    return itemRow(`branch-dead-${n}`, offsets[3]! + n === selected, { text: ' ' }, [...named(d, `row:${offsets[3]! + n}`, Math.max(8, columns - 3 - segmentsWidth(place)), hues), ...place], columns)
  })
  const violations = listBlock(
    'branch-policy',
    'New policy violations',
    input.violations,
    offsets[4]!,
    selected,
    (v, n, columns) => {
      const half = Math.max(8, Math.floor((columns - 7) / 2))
      return itemRow(`branch-policy-${n}`, offsets[4]! + n === selected, { text: '▲', color: STATUS_COLOURS.alert }, [button(`row:${offsets[4]! + n}`, fit(v.source.name, half)), { text: ' → ', dim: true }, { text: fit(v.target, Math.max(1, columns - half - 7)) }], columns)
    },
    input.violations?.truncated === true ? [{ text: ' · check cut short', dim: true }] : [],
  )
  const lists = [crossing, cycles, hubs, dead, ...(input.violations === null ? [] : [violations])]
  return { top: [head], left: lists, rows: issueGrid(lists), order: [head, ...lists] }
}
