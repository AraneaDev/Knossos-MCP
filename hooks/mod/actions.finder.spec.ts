import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView, SearchState } from '../../types'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import { handlersOf, PRESSES } from './actions'
import type { Port } from './port'
import { mod, reset } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

const dashboard = {
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
    hubs: [],
    hubs_truncated: false,
    hubs_truncation_reasons: [],
    hotspots: [],
    dead_code_candidates: 0,
    dead_code_truncated: false,
    cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
    trend: [],
    fan_in: [],
    fan_in_truncated: false,
} as unknown as Dashboard

const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })

const GREETER = { type: 'component', name: 'Greeter', canonical_name: 'App\\Greeter', kind: 'class', path: 'src/Greeter.php', line: 3, boundary: 'core' }
/** The wrapper's stdout for a search that found `results`. */
const found = (results: unknown[]) => JSON.stringify({ status: 'ok', results })
const EMPTY_SEARCH: SearchState = { query: '', for: null, phase: 'idle', answer: null }

/**
 * A port over plain cells (all the pane reads), a clock whose timers run only
 * when the test says, and a wrapper that answers each run through `answer`
 * and records its argv.
 */
function fakePort(cells: Record<string, unknown> = {}, answer: (argv: string[]) => Promise<string> = async () => '') {
    const values: Record<string, unknown> = {
        brief: null,
        dashboard,
        view: view(),
        detail: null,
        refresh: { fetchedAt: 0, failed: false },
        rescan: { phase: 'idle', reason: null },
        allow: null,
        theme: 'dark',
        changes: NO_CHANGES,
        sessionRoot: null,
        live: LIVE_OFF,
        sessionLedger: null,
        sessionStart: null,
        sessionBegan: null,
        sessionEdits: [],
        sessionScans: [],
        sessionRev: null,
        fileDiff: null,
        diffFold: null,
        untestedOnly: false,
        gitHead: null,
        couplings: null,
        feedback: null,
        search: EMPTY_SEARCH,
        peek: null,
        branch: null,
        flash: null,
        churn: null,
        rings: null,
        route: null,
        note: null,
        job: null,
        ...cells,
    }
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values[key],
                update: async (change: (v: unknown) => unknown) => (values[key] = change(values[key])),
            }),
        },
    )
    const timers: { ms: number; run: () => void; cancelled: boolean }[] = []
    const runs: string[][] = []
    const io = {
        state,
        clock: {
            now: async () => 0,
            after: (ms: number, run: () => void) => {
                const timer = { ms, run, cancelled: false }
                timers.push(timer)
                return { cancel: () => void (timer.cancelled = true) }
            },
        },
        ui: { log: () => undefined, invalidate: () => undefined, focus: async () => ({}), toast: () => undefined },
        session: { root: async () => ROOT, id: async () => 'sess-1' },
        plugin: { root: '/plugin' },
        fs: { stat: async () => Promise.reject(new Error('none')) },
        store: { get: async () => undefined, set: async () => undefined },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                return { exitCode: 0, stdout: await answer(argv), stderr: '' }
            },
        },
    } as unknown as Port
    const pending = () => timers.filter(t => !t.cancelled)
    /** The finder's search timers (its debounce), still pending. */
    const searchTimers = () => pending().filter(t => t.ms === 150)
    /** Runs the pending debounced search, once, and lets what it started settle. */
    const runSearch = async () => {
        for (const timer of searchTimers()) {
            timer.cancelled = true
            timer.run()
        }
        await settle()
    }
    /** The wrapper's graph-search runs so far. */
    const searches = () => runs.filter(argv => argv[2] === 'graph-search')
    return { io, values, timers, searchTimers, runSearch, searches, runs }
}

/** Lets fire-and-forget handlers and the search they start run to the end. */
async function settle(): Promise<void> {
    for (let i = 0; i < 20; i++) await new Promise(resolve => setTimeout(resolve, 0))
}

const press = (io: Port, id: string) => PRESSES.get(id)!(io, '', undefined, id)

beforeEach(() => {
    reset(options)
})

describe('opening and closing the finder', () => {
    it('keeps the marker it was opened from across a second `find`, and puts it back on close', async () => {
        const { io, values } = fakePort({ view: view({ selected: 5, filtering: true }) })
        await press(io, 'find')
        expect(mod.findFrom).toBe(5)
        expect(values.view).toMatchObject({ finding: true, filtering: false, selected: 0 })

        // The marker moves inside the finder; a second `find` must not take that as where it came from.
        values.view = { ...(values.view as KnossosView), selected: 2 }
        await press(io, 'find')
        expect(mod.findFrom).toBe(5)

        await press(io, 'find-close')
        expect(values.view).toMatchObject({ finding: false, picking: null, selected: 5 })
    })

    it('closing a finder that is not open changes nothing on the view, but drops a pending open', async () => {
        const before = view({ selected: 3, finding: false })
        const { io, values } = fakePort({ view: before })
        mod.findFrom = 7
        mod.openWhenFound = true
        await press(io, 'find-close')
        expect(values.view).toEqual(before)
        expect(mod.openWhenFound).toBe(false)
    })
})

describe('typing into the finder', () => {
    it('searches once for what was typed last, after the pause', async () => {
        const { io, values, searchTimers, runSearch, searches } = fakePort({ view: view({ finding: true, selected: 4 }) })
        const act = handlersOf(io)
        act.type('find', 'ab')
        await settle()
        act.type('find', 'abc')
        await settle()
        expect((values.search as SearchState).query).toBe('abc')
        expect((values.view as KnossosView).selected).toBe(0)
        // The first keystroke's timer was cancelled: one search waits.
        expect(searchTimers()).toHaveLength(1)
        expect(searches()).toEqual([])

        await runSearch()
        expect(searches()).toHaveLength(1)
        expect(searches()[0]).toContain('--query=abc')
    })

    it('schedules nothing while the mod is off, though the text is kept', async () => {
        const { io, values, timers } = fakePort()
        mod.disabled = true
        handlersOf(io).type('find', 'ab')
        await settle()
        expect((values.search as SearchState).query).toBe('ab')
        expect(timers).toEqual([])
    })

    it('answers a query of only spaces as nothing to search, without asking the wrapper', async () => {
        const { io, values, runSearch, runs } = fakePort({ search: { query: 'x', for: 'x', phase: 'idle', answer: { status: 'ok', results: [], truncated: false } } })
        handlersOf(io).type('find', '   ')
        await settle()
        await runSearch()
        expect(values.search).toEqual({ query: '   ', for: '', phase: 'idle', answer: null })
        expect(runs).toEqual([])
    })

    it('stores the answer with the trimmed query it answers', async () => {
        const { io, values, runSearch, searches } = fakePort({}, async () => found([GREETER]))
        handlersOf(io).type('find', '  greet ')
        await settle()
        await runSearch()
        expect(searches()[0]).toContain('--query=greet')
        const search = values.search as SearchState
        expect(search).toMatchObject({ query: '  greet ', for: 'greet', phase: 'idle' })
        expect(search.answer?.status).toBe('ok')
        expect(search.answer?.results.map(r => r.canonical_name)).toEqual(['App\\Greeter'])
    })

    it('turns the mod off when the wrapper finds no binary, and stores no answer', async () => {
        const { io, values, runSearch } = fakePort({}, async () => JSON.stringify({ status: 'no-binary', results: [] }))
        handlersOf(io).type('find', 'greet')
        await settle()
        await runSearch()
        expect(mod.disabled).toBe(true)
        const search = values.search as SearchState
        // It was searching when the mod went off; it never lands as an idle answer.
        expect(search.phase).toBe('searching')
        expect(search.for).toBeNull()
    })

    it.each([
        ['throws', async () => Promise.reject(new Error('spawn failed'))],
        ['answers nothing', async () => ''],
    ])('stores no answer when the wrapper %s', async (_, answer) => {
        const { io, values, runSearch, searches } = fakePort({}, answer)
        handlersOf(io).type('find', 'greet')
        await settle()
        await runSearch()
        expect(searches()).toHaveLength(1)
        expect(values.search).toEqual({ query: 'greet', for: 'greet', phase: 'idle', answer: null })
        expect(mod.disabled).toBe(false)
    })
})

describe('Enter in the finder', () => {
    it('closes the finder on an empty field, the marker back where it stood', async () => {
        const { io, values, timers } = fakePort({ view: view({ finding: true, selected: 0 }) })
        mod.findFrom = 6
        handlersOf(io).submit('find', '  ')
        await settle()
        expect(values.view).toMatchObject({ finding: false, picking: null, selected: 6 })
        expect(timers).toEqual([])
    })

    it('opens the marked match at once when the answer for that text is already in', async () => {
        const answer = { status: 'ok', results: [GREETER], truncated: false }
        const { io, values, searchTimers, runs } = fakePort({ view: view({ finding: true, selected: 0 }), search: { query: 'greet', for: 'greet', phase: 'idle', answer } })
        mod.findFrom = 4
        handlersOf(io).submit('find', 'greet')
        await settle()
        expect(values.view).toMatchObject({ finding: false, inspect: { name: 'App\\Greeter', label: 'Greeter' }, opened: 4 })
        expect(searchTimers()).toEqual([])
        expect(runs.filter(argv => argv[2] === 'graph-search')).toEqual([])
        expect(mod.openWhenFound).toBe(false)
    })

    it('keeps the submitted text as the query when it differs from what was stored', async () => {
        const { io, values } = fakePort({ view: view({ finding: true }), search: { query: 'gre', for: null, phase: 'idle', answer: null } })
        handlersOf(io).submit('find', 'greet')
        await settle()
        expect((values.search as SearchState).query).toBe('greet')
    })

    it('pressed before the answer, opens the first match when it lands', async () => {
        const { io, values, searchTimers, runSearch, searches } = fakePort({ view: view({ finding: true }) }, async () => found([GREETER]))
        mod.findFrom = 2
        handlersOf(io).submit('find', 'greet')
        await settle()
        expect(mod.openWhenFound).toBe(true)
        expect(searchTimers()).toHaveLength(1)
        expect((values.view as KnossosView).inspect).toBeNull()

        await runSearch()
        expect(searches()[0]).toContain('--query=greet')
        expect(mod.openWhenFound).toBe(false)
        expect(values.view).toMatchObject({ finding: false, inspect: { name: 'App\\Greeter', label: 'Greeter' }, opened: 2 })
    })

    it('pressed while the same text is searched again, waits for that answer rather than opening the last one', async () => {
        const stale = { status: 'ok', results: [{ ...GREETER, name: 'OldGreeter', canonical_name: 'App\\OldGreeter' }], truncated: false }
        const { io, values, runSearch } = fakePort({ view: view({ finding: true, selected: 0 }), search: { query: 'greet', for: 'greet', phase: 'searching', answer: stale } }, async () => found([GREETER]))
        handlersOf(io).submit('find', 'greet')
        await settle()
        expect((values.view as KnossosView).inspect).toBeNull()
        expect(mod.openWhenFound).toBe(true)

        await runSearch()
        expect(values.view).toMatchObject({ finding: false, inspect: { name: 'App\\Greeter', label: 'Greeter' } })
    })

    it('opens nothing when the field changed while the answer was on its way', async () => {
        let land: (stdout: string) => void = () => undefined
        const { io, values, runSearch } = fakePort({ view: view({ finding: true }) }, () => new Promise<string>(resolve => (land = resolve)))
        const act = handlersOf(io)
        act.submit('find', 'greet')
        await settle()
        await runSearch()
        // The wrapper is still answering for "greet" when the person types on.
        act.type('find', 'other')
        await settle()
        land(found([GREETER]))
        await settle()
        expect(values.search).toMatchObject({ query: 'other', for: 'greet', phase: 'idle' })
        expect(values.view).toMatchObject({ finding: true, inspect: null })
        // Not this answer's to clear: the flag waits for the answer to what the field says now.
        expect(mod.openWhenFound).toBe(true)
    })

    it('opens nothing and drops the pending open when the answer has no matches', async () => {
        const { io, values, runSearch } = fakePort({ view: view({ finding: true }) }, async () => found([]))
        handlersOf(io).submit('find', 'zz')
        await settle()
        await runSearch()
        expect(mod.openWhenFound).toBe(false)
        expect(values.view).toMatchObject({ finding: true, inspect: null })
        expect(values.search).toMatchObject({ query: 'zz', for: 'zz', phase: 'idle' })
    })
})
