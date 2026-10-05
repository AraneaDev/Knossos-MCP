import { boundariesArrangement } from '../boundaries'
import type { BoundariesInput } from '../boundaries'
import { arrange, cardInner, cardRows, fitBlocks } from '../cards'
import { changesArrangement } from '../changes'
import type { ChangesInput } from '../changes'
import { diffSection } from '../diff'
import type { DiffView } from '../diff'
import { driftSection, fileDetailArrangement } from '../files'
import type { DriftInput } from '../files'
import { NO_HUES } from '../palette'
import type { Hues } from '../palette'
import { tierOf } from '../rows'
import type { Row } from '../rows'
import { detailArrangement, issuesArrangement } from '../views'
import type { DetailInput, IssuesInput } from '../views'

/**
 * Each tab's and card's rows at a width, laid out as the pane lays them out
 * for that width's tier, on a pane tall enough to list everything: what the
 * specs read when they ask what one tab draws.
 */
const TALL = 1_000

export const changesRows = (input: ChangesInput, selected: number, columns: number, hues: Hues = NO_HUES): Row[] =>
  arrange(changesArrangement(input, selected, tierOf(columns), hues), columns, TALL)

export const boundaryRows = (input: BoundariesInput | null, columns: number, hues: Hues = NO_HUES, selected = 0): Row[] =>
  arrange(boundariesArrangement(input, tierOf(columns), hues, selected), columns, TALL)

export const issueRows = (issues: IssuesInput, selected: number, columns: number, hues: Hues = NO_HUES): Row[] =>
  arrange(issuesArrangement(issues, selected, tierOf(columns), hues), columns, TALL)

export const detailRows = (detail: DetailInput, columns: number, hues: Hues = NO_HUES, selected = -1): Row[] =>
  arrange(detailArrangement(detail, tierOf(columns), hues, selected), columns, TALL)

export const fileDetailRows = (detail: DetailInput, columns: number, hues: Hues = NO_HUES, selected = -1): Row[] =>
  arrange(fileDetailArrangement(detail, tierOf(columns), hues, selected), columns, TALL)

export const driftRows = (drift: DriftInput, columns: number, hues: Hues = NO_HUES, selected = -1): Row[] =>
  fitBlocks([{ key: 'drift', grow: { length: drift.items.length, min: TALL }, make: (inner, limit) => driftSection(drift, inner, limit, hues, selected) }], columns, tierOf(columns), 0)

export const diffRows = (view: DiffView, columns: number): Row[] => cardRows(diffSection(view, cardInner(columns, tierOf(columns))), columns, tierOf(columns))
