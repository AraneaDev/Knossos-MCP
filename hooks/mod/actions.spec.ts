import { describe, expect, it } from 'vitest'
import type { DiffFold, KnossosView } from '../../types'
import { TABS } from '../lib/layout'
import { PRESSES, pressOf } from './actions'
import type { Port } from './port'
import { fieldKey, PANE } from './state'

describe('the press table', () => {
  it('runs an outright id as itself, and a prefixed one by its prefix with what follows the colon', () => {
    expect(pressOf('find')).toEqual({ key: 'find', rest: '' })
    expect(pressOf('route')).toEqual({ key: 'route', rest: '' })
    expect(pressOf('route-pick:2')).toEqual({ key: 'route-pick:', rest: '2' })
    expect(pressOf('row:12')).toEqual({ key: 'row:', rest: '12' })
    expect(pressOf('tab:hubs')).toEqual({ key: 'tab:', rest: 'hubs' })
    // Only the first colon divides: a fold's id carries the cycle and the row.
    expect(pressOf('unfold:3:7')).toEqual({ key: 'unfold:', rest: '3:7' })
  })

  it('runs a twin as the id it stands in for', () => {
    expect(pressOf('tabkey:churn')).toEqual({ key: 'tab:', rest: 'churn' })
    expect(pressOf('drifted')).toEqual({ key: 'drift', rest: '' })
    expect(pressOf('prev:4')).toEqual({ key: 'next:', rest: '4' })
    expect(pressOf('mark:0')).toEqual({ key: 'next:', rest: '0' })
    expect(pressOf('fold:1:2')).toEqual({ key: 'unfold:', rest: '1:2' })
  })

  it('does nothing for an id it does not know, a prefix without its colon, or an outright id given a colon', () => {
    for (const id of ['', 'row', 'tab', 'find:x', 'down:1', 'nope', 'constructor', 'toString', '__proto__', 'hasOwnProperty:1']) expect(pressOf(id), id).toBeNull()
  })

  it('no outright id holds a colon and every prefix ends in one, so the two kinds never meet', () => {
    for (const key of PRESSES.keys()) expect(key.indexOf(':') === -1 || key.indexOf(':') === key.length - 1, key).toBe(true)
  })

  it('every tab the pane draws has a press, by its id and by its hotkey twin', () => {
    for (const tab of TABS) {
      expect(pressOf(`tab:${tab.id}`)?.rest).toBe(tab.id)
      expect(pressOf(`tabkey:${tab.id}`)?.key).toBe('tab:')
    }
  })
})

describe('opening a field', () => {
  const VIEW: KnossosView = { inspect: { name: 'App\\Greeter', label: 'Greeter' }, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: true, filter: '', filtering: false, sort: 'in' }

  /** A port holding the view, a component's detail and the note, its timers run by hand and its focus calls recorded. */
  function port() {
    const timers: (() => void)[] = []
    const focused: unknown[] = []
    const cell = <T>(value: T) => ({ read: async () => value, update: async (change: (v: T) => T) => void (value = change(value)) })
    const io = {
      clock: { after: (_ms: number, run: () => void) => void timers.push(run) },
      ui: { focus: async (args: unknown) => (focused.push(args), {}) },
      state: { view: cell(VIEW), detail: cell(null), note: cell(null) },
    } as unknown as Port
    return { io, timers, focused }
  }

  it.each(['filter', 'find', 'note'])('%s asks for the focus by the field key, which is never the id its Button is pressed by', async id => {
    const { io, timers, focused } = port()
    await PRESSES.get(id)!(io, '', undefined, id)
    // Not inside the press: the call waits for the drawing that brings the field.
    expect(focused).toEqual([])
    for (const run of timers) run()
    await Promise.resolve()
    expect(focused).toEqual([{ requestId: PANE, key: fieldKey(id) }])
    expect(fieldKey(id)).not.toBe(id)
    expect(pressOf(fieldKey(id))).toBeNull()
  })
})

describe('opening the diff further', () => {
  const REV = '0123456789abcdef0123456789abcdef01234567'
  const SHOWN = { name: 'src/Router.php', label: 'src/Router.php', file: true, changed: true }
  /** As much as session-diff sends: 14 hunks of 70 lines, each line as long as a hunk keeps. */
  const FULL = `${Array.from({ length: 14 }, (_, h) => `@@ -${h * 100 + 1},0 +${h * 100 + 1},70 @@\n${Array.from({ length: 70 }, (_, i) => `+${`h${h} line ${i} `.padEnd(199, 'x')}`).join('\n')}`).join('\n')}\n`
  const answer = { status: 'ok', file: SHOWN.name, kind: 'changed', from: null, to: null, binary: false, diff: FULL, lines: 994, truncated: false }

  /** A port holding the detail of a changed file, its diff read for snapshot `s1`, and the fold given. */
  function port(fold: DiffFold | null) {
    const cell = <T>(value: T) => ({ read: async () => value, update: async (change: (v: T) => T) => void (value = change(value)) })
    const diffFold = cell(fold)
    const io = {
      state: {
        view: cell({ inspect: SHOWN, isBandHidden: false, tab: 'changes', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' } as KnossosView),
        fileDiff: cell({ name: SHOWN.name, rev: REV, snapshot: 's1', phase: 'done', diff: answer }),
        sessionRev: cell({ status: 'ok', rev: REV }),
        diffFold,
      },
    } as unknown as Port
    return { io, fold: () => diffFold.read() }
  }
  const press = (io: Port, id: string) => PRESSES.get(pressOf(id)!.key)!(io, pressOf(id)!.rest, undefined, id)

  it('has a press for a hunk\'s more lines, for the hunks past or before a page, and for the full diff, none keyed as a row is', () => {
    expect(pressOf('diff-more:3')).toEqual({ key: 'diff-more:', rest: '3' })
    expect(pressOf('diff-from:12')).toEqual({ key: 'diff-from:', rest: '12' })
    expect(pressOf('diff-open')).toEqual({ key: 'diff-open', rest: '' })
    // The rows they stand on are keyed with a dash, so a Button is never keyed as a row's Box.
    for (const row of ['diff-more-3', 'diff-hunks-more', 'diff-hunks-before', 'diff-full']) expect(pressOf(row), row).toBeNull()
  })

  it('opens the pressed hunk for the diff on show, keeping what was opened before', async () => {
    const { io, fold } = port(null)
    await press(io, 'diff-more:0')
    expect(await fold()).toEqual({ name: SHOWN.name, rev: REV, snapshot: 's1', open: [0], from: 0 })
    await press(io, 'diff-more:1')
    expect((await fold())?.open).toEqual([0, 1])
  })

  it('starts over when what it held was opened on another diff', async () => {
    const { io, fold } = port({ name: SHOWN.name, rev: REV, snapshot: 's0', open: [4, 5], from: 3 })
    await press(io, 'diff-more:0')
    expect(await fold()).toEqual({ name: SHOWN.name, rev: REV, snapshot: 's1', open: [0], from: 0 })
  })

  it('moves the page to a hunk that, opened, no longer fits after the ones before it', async () => {
    const { io, fold } = port(null)
    // Folded, the first three fill the page; the third opened outweighs what is left.
    await press(io, 'diff-more:2')
    expect(await fold()).toMatchObject({ open: [2], from: 2 })
  })

  it('shows the hunks from the one pressed, and does nothing without a diff on show', async () => {
    const { io, fold } = port(null)
    await press(io, 'diff-from:5')
    expect(await fold()).toEqual({ name: SHOWN.name, rev: REV, snapshot: 's1', open: [], from: 5 })
    await io.state.fileDiff.update(() => null)
    await press(io, 'diff-from:7')
    await press(io, 'diff-more:7')
    expect((await fold())?.from).toBe(5)
  })
})

describe('the files no test reaches', () => {
  it('has a press for `u` and its count, and one for every file again, none keyed as a row is', () => {
    expect(pressOf('untested')).toEqual({ key: 'untested', rest: '' })
    expect(pressOf('untested-row')).toEqual({ key: 'untested', rest: '' })
    expect(pressOf('untested-all')).toEqual({ key: 'untested-all', rest: '' })
    for (const row of ['changes-untested', 'changes-all', 'session-said']) expect(pressOf(row), row).toBeNull()
  })
})

describe('the line under a cut list', () => {
  it('moves the marker onto the item it names, and leaves a boundary to start on what it depends on most', async () => {
    expect(pressOf('more:12')).toEqual({ key: 'more:', rest: '12' })
    let v: KnossosView = { inspect: null, isBandHidden: false, tab: 'boundaries', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', target: 'App' }
    const io = { state: { view: { read: async () => v, update: async (change: (w: KnossosView) => KnossosView) => void (v = change(v)) } } } as unknown as Port
    await PRESSES.get('more:')!(io, '12', undefined, 'more:12')
    expect(v).toMatchObject({ selected: 12, target: undefined })
    await PRESSES.get('more:')!(io, 'x', undefined, 'more:x')
    expect(v.selected).toBe(12)
  })
})

describe('a figure that counts a set', () => {
  it('has a press for each tile and the Changes warnings, never one a row is keyed by', () => {
    for (const key of ['cycles', 'dead', 'policy', 'diagnostics', 'components', 'boundaries']) expect(pressOf(`stat:${key}`)).toEqual({ key: 'stat:', rest: key })
    expect(pressOf('untested-none')).toEqual({ key: 'untested', rest: '' })
  })

  it('opens the tab that lists it, the marker on its first item', async () => {
    let v: KnossosView = { inspect: null, isBandHidden: false, tab: 'overview', selected: 4, showKeys: false, filter: 'x', filtering: true, sort: 'in', degree: { from: 3, to: null } }
    const cell = <T>(value: T) => ({ read: async () => value, update: async (change: (w: T) => T) => void (value = change(value)) })
    // Two violations listed before the dead code on Issues.
    const io = {
      state: { view: { read: async () => v, update: async (change: (w: KnossosView) => KnossosView) => void (v = change(v)) }, dashboard: cell({ status: 'ok', policy: { status: 'evaluated', total: 2, items: [{}, {}] } }) },
    } as unknown as Port
    const go = async (key: string) => PRESSES.get('stat:')!(io, key, undefined, `stat:${key}`)
    await go('cycles')
    expect(v).toMatchObject({ tab: 'cycles', selected: 0, filtering: false, degree: null })
    await go('components')
    expect(v).toMatchObject({ tab: 'hubs', selected: 0 })
    await go('boundaries')
    expect(v).toMatchObject({ tab: 'boundaries', selected: 0 })
    await go('policy')
    expect(v).toMatchObject({ tab: 'issues', selected: 0 })
    await go('dead')
    expect(v).toMatchObject({ tab: 'issues', selected: 2 })
    await go('nonsense')
    expect(v.tab).toBe('issues')
  })
})
