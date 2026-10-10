import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { KnossosView } from '../../types'
import type { Openable } from '../lib/layout'
import { PRESSES, pressOf } from './actions'
import { request, requestDetail, showComponent } from './loaders'
import type { Port } from './port'
import { currentInput, currentList } from './render'
import { mod } from './state'

vi.mock('./render', async importOriginal => ({ ...(await importOriginal<typeof import('./render')>()), currentList: vi.fn(), currentInput: vi.fn() }))
vi.mock('./loaders', async importOriginal => ({
    ...(await importOriginal<typeof import('./loaders')>()),
    showComponent: vi.fn(async () => {}),
    request: vi.fn(async () => {}),
    requestDetail: vi.fn(async () => {}),
}))

const VIEW: KnossosView = { inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' }

/** A port holding only the view, in a cell that reads and updates in memory. */
function port(view: Partial<KnossosView> = {}) {
    let value: KnossosView = { ...VIEW, ...view }
    const io = {
        state: {
            view: {
                read: async () => value,
                update: async (change: (v: KnossosView) => KnossosView) => void (value = change(value)),
            },
        },
    } as unknown as Port
    return { io, view: () => value }
}

const press = (io: Port, id: string) => {
    const found = pressOf(id)!
    return PRESSES.get(found.key)!(io, found.rest, undefined, id)
}

const item = (name: string, extra: Partial<Openable> = {}): Openable => ({ name, canonical: `App\\${name}`, loc: null, ...extra }) as Openable

/** The list the marker moves in, as the pane would draw it. */
const list = (items: Openable[]) => vi.mocked(currentList).mockResolvedValue(items)

beforeEach(() => {
    vi.mocked(showComponent).mockClear()
    vi.mocked(request).mockClear()
    vi.mocked(requestDetail).mockClear()
    vi.mocked(currentList).mockReset()
    vi.mocked(currentInput).mockReset()
    mod.findFrom = 0
    mod.openWhenFound = false
})

describe('moving the marker', () => {
    it('stops at the last row going down and at the first going up, and forgets the marked cell', async () => {
        list([item('A'), item('B'), item('C')])
        const { io, view } = port({ selected: 2, target: 'App\\X' } as Partial<KnossosView>)
        await press(io, 'down')
        expect(view().selected).toBe(2)
        expect(view().target).toBeUndefined()

        await press(io, 'up')
        expect(view().selected).toBe(1)
        await press(io, 'up')
        await press(io, 'up')
        expect(view().selected).toBe(0)
    })

    it('moves one row down from the middle', async () => {
        list([item('A'), item('B'), item('C')])
        const { io, view } = port({ selected: 1 })
        await press(io, 'down')
        expect(view().selected).toBe(2)
    })

    it('keeps the marker on 0 in an empty list, whichever way it is moved', async () => {
        list([])
        const { io, view } = port({ selected: 0 })
        await press(io, 'down')
        expect(view().selected).toBe(0)
        await press(io, 'up')
        expect(view().selected).toBe(0)
    })

    it('pulls a marker left past the end of a shorter list back onto its last row', async () => {
        list([item('A'), item('B')])
        const { io, view } = port({ selected: 7 })
        await press(io, 'up')
        expect(view().selected).toBe(1)
    })
})

describe('opening a row', () => {
    it('marks the pressed row and opens a plain component as the detail, with no file or changed flag', async () => {
        list([item('A'), item('B'), item('Greeter')])
        const { io, view } = port()
        await press(io, 'row:2')
        expect(view().selected).toBe(2)
        expect(showComponent).toHaveBeenCalledTimes(1)
        const shown = vi.mocked(showComponent).mock.calls[0]![1]
        expect(shown).toEqual({ name: 'App\\Greeter', label: 'Greeter' })
        expect(shown).not.toHaveProperty('file')
        expect(shown).not.toHaveProperty('changed')
    })

    it('passes the file and changed flags of a changed file', async () => {
        list([item('src/Router.php', { canonical: 'src/Router.php', file: true, changed: true } as Partial<Openable>)])
        const { io } = port()
        await press(io, 'row:0')
        expect(showComponent).toHaveBeenCalledWith(io, { name: 'src/Router.php', label: 'src/Router.php', file: true, changed: true })
    })

    it('leaves out a flag set to false', async () => {
        list([item('src/a.ts', { canonical: 'src/a.ts', file: false, changed: false } as Partial<Openable>)])
        const { io } = port()
        await press(io, 'row:0')
        expect(vi.mocked(showComponent).mock.calls[0]![1]).toEqual({ name: 'src/a.ts', label: 'src/a.ts' })
    })

    it('switches to the tab a chart bar jumps to, and opens nothing', async () => {
        list([item('bar', { jump: { tab: 'boundaries', selected: 3, degree: 2, target: 'App\\Core' } } as Partial<Openable>)])
        const { io, view } = port({ filtering: true, drift: true } as Partial<KnossosView>)
        await press(io, 'row:0')
        expect(view()).toMatchObject({ tab: 'boundaries', selected: 3, degree: 2, target: 'App\\Core', filtering: false, drift: false })
        expect(showComponent).not.toHaveBeenCalled()
    })

    it('starts a jump on the first row with no degree when the bar names neither', async () => {
        list([item('Hubs'), item('bar', { jump: { tab: 'changes' } } as Partial<Openable>)])
        const { io, view } = port({ selected: 0, filtering: true, drift: true, degree: 4 } as Partial<KnossosView>)
        await press(io, 'row:1')
        expect(view()).toMatchObject({ tab: 'changes', selected: 0, degree: null, filtering: false, drift: false })
        expect(view().target).toBeUndefined()
        expect(showComponent).not.toHaveBeenCalled()
    })

    it('marks an inert row and opens nothing', async () => {
        list([item('A'), item('Boundary', { inert: true } as Partial<Openable>)])
        const { io, view } = port()
        await press(io, 'row:1')
        expect(view().selected).toBe(1)
        expect(showComponent).not.toHaveBeenCalled()
    })

    it.each(['row:99', 'row:abc'])('does nothing at all for %s, which names no row', async id => {
        list([item('A'), item('B')])
        const { io, view } = port({ selected: 1, filtering: true })
        const before = view()
        await press(io, id)
        expect(view()).toBe(before)
        expect(showComponent).not.toHaveBeenCalled()
    })

    it.each(['open', 'diff-open'])('%s opens the row the marker stands on', async id => {
        list([item('A'), item('B'), item('C')])
        const { io, view } = port({ selected: 1 })
        await press(io, id)
        expect(view().selected).toBe(1)
        expect(showComponent).toHaveBeenCalledWith(io, { name: 'App\\B', label: 'B' })
    })

    it('on Boundaries, clears the marked cell before marking the pressed boundary', async () => {
        list([item('Core', { inert: true } as Partial<Openable>), item('Web', { inert: true } as Partial<Openable>)])
        const { io, view } = port({ tab: 'boundaries', selected: 0, target: 'App\\Old' } as Partial<KnossosView>)
        await press(io, 'row:1')
        expect(view().target).toBeUndefined()
        expect(view().selected).toBe(1)
    })

    it('keeps the marked cell on any other tab', async () => {
        list([item('A', { inert: true } as Partial<Openable>)])
        const { io, view } = port({ tab: 'hubs', target: 'App\\Old' } as Partial<KnossosView>)
        await press(io, 'row:0')
        expect(view().target).toBe('App\\Old')
    })
})

describe('opening a match from the finder', () => {
    it('closes the finder, puts the tab marker back where it stood, and opens the match', async () => {
        list([item('Match0'), item('Match1')])
        mod.findFrom = 5
        mod.openWhenFound = true
        const { io, view } = port({ finding: true, picking: null, selected: 1 } as Partial<KnossosView>)
        await press(io, 'row:1')
        expect(view().finding).toBe(false)
        expect(view().selected).toBe(5)
        expect(mod.openWhenFound).toBe(false)
        expect(showComponent).toHaveBeenCalledWith(io, { name: 'App\\Match1', label: 'Match1' })
    })

    it('passes the file flag of a matched file, but never the changed flag', async () => {
        list([item('src/a.ts', { canonical: 'src/a.ts', file: true, changed: true } as Partial<Openable>)])
        const { io } = port({ finding: true } as Partial<KnossosView>)
        await press(io, 'open')
        expect(vi.mocked(showComponent).mock.calls[0]![1]).toEqual({ name: 'src/a.ts', label: 'src/a.ts', file: true })
    })

    it('opens a jump-carrying match as a component instead of jumping', async () => {
        list([item('bar', { jump: { tab: 'changes' } } as Partial<Openable>)])
        const { io, view } = port({ finding: true, tab: 'hubs' } as Partial<KnossosView>)
        await press(io, 'row:0')
        expect(view().tab).toBe('hubs')
        expect(showComponent).toHaveBeenCalledTimes(1)
    })

    it('while picking a route end, draws the route to the match instead of opening it', async () => {
        const from = { name: 'App\\Start', label: 'Start' }
        const inspect = { name: 'App\\Start', label: 'Start' }
        list([item('A'), item('End')])
        mod.openWhenFound = true
        const { io, view } = port({ finding: true, picking: from, inspect, selected: 1 } as Partial<KnossosView>)
        await press(io, 'row:1')
        expect(view().route).toEqual({ from, to: { name: 'App\\End', label: 'End' }, index: 0, back: inspect })
        expect(view().picking).toBeNull()
        expect(view().finding).toBe(false)
        expect(view().selected).toBe(0)
        expect(mod.openWhenFound).toBe(false)
        expect(request).toHaveBeenCalledWith(io, 'route')
        expect(showComponent).not.toHaveBeenCalled()
    })
})

describe('opening what the detail lists', () => {
    it('rel:N opens the related component as the detail, and an index past the list opens nothing', async () => {
        list([item('UsedBy'), item('Uses', { file: true } as Partial<Openable>)])
        const { io, view } = port({ selected: 0 })
        await press(io, 'rel:1')
        // Related rows open as components: no file flag is passed.
        expect(showComponent).toHaveBeenCalledWith(io, { name: 'App\\Uses', label: 'Uses' })
        // The marker is not moved by opening a related row.
        expect(view().selected).toBe(0)

        vi.mocked(showComponent).mockClear()
        await press(io, 'rel:9')
        await press(io, 'rel:x')
        expect(showComponent).not.toHaveBeenCalled()
    })

    it.each([null, undefined])('peek:N does nothing when nothing is peeked (%s)', async peek => {
        vi.mocked(currentInput).mockResolvedValue({ peek } as never)
        const { io } = port()
        await press(io, 'peek:0')
        expect(showComponent).not.toHaveBeenCalled()
    })

    it('peek:N does nothing when there is no input at all', async () => {
        vi.mocked(currentInput).mockResolvedValue(null)
        const { io } = port()
        await press(io, 'peek:0')
        expect(showComponent).not.toHaveBeenCalled()
    })

    it('peek:N opens the peeked item at N, a file with its flag and a component without', async () => {
        // A file's peek lists who depends on it, then what it uses, then its components.
        const peek = {
            file: {
                dependents: { items: [{ path: 'src/b.ts', loc: null }] },
                uses: { items: [] },
                components: { items: [{ name: 'Widget', canonical: 'App\\Widget', loc: null }] },
            },
        }
        vi.mocked(currentInput).mockResolvedValue({ peek } as never)
        const { io } = port()
        await press(io, 'peek:0')
        expect(showComponent).toHaveBeenLastCalledWith(io, { name: 'src/b.ts', label: 'src/b.ts', file: true })
        await press(io, 'peek:1')
        expect(showComponent).toHaveBeenLastCalledWith(io, { name: 'App\\Widget', label: 'Widget' })
        expect(currentInput).toHaveBeenCalledWith(io, true)

        vi.mocked(showComponent).mockClear()
        await press(io, 'peek:2')
        expect(showComponent).not.toHaveBeenCalled()
    })
})
