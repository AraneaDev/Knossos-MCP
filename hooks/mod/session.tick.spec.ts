import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { bandModel } from '../lib/band'
import { NO_CHANGES, paneStatus } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import { declaredOf, huesOf } from '../lib/palette'
import { peekKey } from './loaders'
import type { Port } from './port'
import { startUp } from './session'
import { mod, PANE, reset } from './state'

const ROOT = '/work/app'
const hub = (i: number) => ({
    name: `C${i}`,
    canonical_name: `App\\C${i}`,
    kind: 'class',
    boundary: 'core',
    in_degree: 90 - i,
    out_degree: 1,
    cross_boundary_degree: 0,
    dependent_files: 40 - i,
    path: `src/C${i}.php`,
    line: 1,
    top_dependents: [],
})
const dashboard: Dashboard = {
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
    hubs: [hub(0), hub(1)],
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
const view = (): KnossosView => ({
    inspect: null,
    isBandHidden: false,
    tab: 'hubs',
    selected: 0,
    showKeys: false,
    filter: '',
    filtering: false,
    sort: 'in',
})
// The watcher is off: the tick's ensureWatcher has nothing to start, so no spawn interferes.
const options = {
    fanInThreshold: 20,
    enforcePolicies: true,
    agentNotes: true,
    watch: false,
    watchPollMs: 1000,
    notifications: true,
}

/**
 * A port over plain values for startUp and the age tick it starts: the cells
 * the tick reads and writes, a clock the test moves by hand whose ticker
 * callback is kept for the test to fire, timers recorded but never run on
 * their own, a pane list and a session id the test sets, a wrapper that
 * answers `dashboard` and `session-head`, and a record of every invalidate.
 */
function tickPort() {
    const blank = {
        brief: null,
        dashboard: null,
        view: view(),
        detail: null,
        refresh: { fetchedAt: null, failed: false },
        rescan: { phase: 'idle', reason: null },
        allow: { phase: 'idle', root: null, reason: null },
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
        startCycles: null,
        fileDiff: null,
        diffFold: null,
        untestedOnly: false,
        gitHead: null,
        couplings: null,
        feedback: null,
        search: { query: '', for: null, phase: 'idle', answer: null },
        peek: null,
        branch: null,
        flash: null,
        churn: null,
        rings: null,
        route: null,
        note: null,
        job: { phase: 'idle' },
    }
    const values = new Map<string, unknown>(Object.entries(blank))
    const failing = new Set<string>()
    const timers: { ms: number; run: () => void }[] = []
    const ticks: (() => void)[] = []
    const invalidated: string[] = []
    const calls: string[] = []
    const env = { now: 0, sessionId: 'sess-1', panes: [] as { id: string }[] }
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => {
                    if (failing.has(key)) throw new Error(`${key} unreadable`)
                    return values.get(key)
                },
                update: async (change: (v: unknown) => unknown) => {
                    values.set(key, change(values.get(key)))
                    return values.get(key)
                },
            }),
        },
    )
    const io = {
        clock: {
            now: async () => env.now,
            after: (ms: number, run: () => void) => {
                timers.push({ ms, run })
                return { cancel: () => undefined }
            },
            every: (_ms: number, run: () => void) => {
                ticks.push(run)
                return { cancel: () => undefined }
            },
        },
        ui: {
            log: () => undefined,
            invalidate: (what: string) => void invalidated.push(what),
            toast: () => undefined,
            panes: async () => env.panes,
            open: async () => undefined,
        },
        session: {
            root: async () => ROOT,
            id: async () => env.sessionId,
        },
        fs: { stat: async (path: string) => ({ realPath: path }) },
        store: {
            get: async () => undefined,
            set: async (key: string) => void calls.push(`store.set:${key}`),
        },
        config: { list: async () => [] },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                calls.push(`run:${argv[2]}`)
                const stdout = argv[2] === 'dashboard' ? JSON.stringify(dashboard) : argv[2] === 'session-head' ? JSON.stringify({ status: 'ok', rev: 'abcdef1234567', branch: 'main' }) : ''
                return { exitCode: 0, stdout, stderr: '' }
            },
            spawn: () => {
                throw new Error('no watcher in this test')
            },
        },
        state,
    } as unknown as Port
    /** Fires the age ticker once, as the clock would, and lets the tick it started settle. */
    const tick = async () => {
        expect(ticks).toHaveLength(1)
        ticks[0]!()
        for (let i = 0; i < 3; i++) await new Promise(resolve => setTimeout(resolve, 0))
    }
    const runsOf = (sub: string) => calls.filter(c => c === `run:${sub}`).length
    const savesOf = () => calls.filter(c => c.startsWith('store.set:')).length
    return { io, values, failing, timers, invalidated, env, tick, runsOf, savesOf }
}

/** startUp with the pane closed, then a clean slate: no timers, no invalidates, nothing drawn yet. */
async function started() {
    const port = tickPort()
    await startUp(port.io, false)
    port.timers.length = 0
    port.invalidated.length = 0
    port.values.set('peek', null)
    mod.bandText = null
    mod.paneText = null
    mod.paneWide = false
    mod.peekNone = null
    return port
}

beforeEach(() => reset(options))

describe('the age tick', () => {
    describe('settling the baseline of a session named late', () => {
        it('settles it once a new session is named after a /clear: its head is asked and the baseline saved under its id', async () => {
            const port = await started()
            expect(mod.baselineOf).toBe('sess-1')
            // A /clear: the baseline is dropped and a new session named later, whose head is not asked yet.
            mod.baselineOf = null
            mod.headAsked = false
            port.values.set('sessionRev', null)
            port.env.sessionId = 'sess-2'
            const heads = port.runsOf('session-head')
            const saves = port.savesOf()
            await port.tick()
            expect(port.runsOf('session-head')).toBe(heads + 1)
            expect(port.values.get('sessionRev')).toMatchObject({ rev: 'abcdef1234567' })
            expect(port.savesOf()).toBe(saves + 1)
            expect(mod.baselineOf).toBe('sess-2')
        })

        it('does not settle it again once it is settled', async () => {
            const port = await started()
            mod.headAsked = false
            const heads = port.runsOf('session-head')
            const saves = port.savesOf()
            await port.tick()
            expect(port.runsOf('session-head')).toBe(heads)
            expect(port.savesOf()).toBe(saves)
            expect(mod.baselineOf).toBe('sess-1')
        })

        it('does not settle it while the mod is off', async () => {
            const port = await started()
            mod.baselineOf = null
            mod.headAsked = false
            mod.disabled = true
            const heads = port.runsOf('session-head')
            const saves = port.savesOf()
            await port.tick()
            expect(port.runsOf('session-head')).toBe(heads)
            expect(port.savesOf()).toBe(saves)
            expect(mod.baselineOf).toBeNull()
        })

        it('does not settle it while no session is named yet', async () => {
            const port = await started()
            mod.baselineOf = null
            mod.headAsked = false
            port.env.sessionId = ''
            const heads = port.runsOf('session-head')
            const saves = port.savesOf()
            await port.tick()
            expect(port.runsOf('session-head')).toBe(heads)
            expect(port.savesOf()).toBe(saves)
            expect(mod.baselineOf).toBeNull()
        })
    })

    describe('fading the footer word and the lit rows', () => {
        it('clears a feedback whose time is up, at the moment itself', async () => {
            const port = await started()
            port.env.now = 5_000
            port.values.set('feedback', { text: 'copied', until: 5_000 })
            await port.tick()
            expect(port.values.get('feedback')).toBeNull()
        })

        it('keeps a feedback whose time is not up', async () => {
            const port = await started()
            port.env.now = 5_000
            const said = { text: 'copied', until: 5_001 }
            port.values.set('feedback', said)
            await port.tick()
            expect(port.values.get('feedback')).toBe(said)
        })

        it('leaves no feedback as none, and goes on with the rest of the tick', async () => {
            const port = await started()
            port.env.now = 5_000
            port.values.set('flash', { names: ['App\\C0'], until: 1 })
            await port.tick()
            expect(port.values.get('feedback')).toBeNull()
            // A null feedback must not stop the tick: the past-due flash after it is still cleared.
            expect(port.values.get('flash')).toBeNull()
        })

        it('clears a flash whose time is past and keeps one still due', async () => {
            const port = await started()
            port.env.now = 2_000
            port.values.set('flash', { names: ['App\\C0'], until: 1_999 })
            await port.tick()
            expect(port.values.get('flash')).toBeNull()
            const lit = { names: ['App\\C1'], until: 3_000 }
            port.values.set('flash', lit)
            await port.tick()
            expect(port.values.get('flash')).toBe(lit)
        })
    })

    describe('looking up the marked row beside a wide pane', () => {
        it('asks for the marked row when the pane is wide, nothing is loaded and the row is not known to be empty', async () => {
            const port = await started()
            mod.paneWide = true
            mod.peekNone = 'some other row'
            await port.tick()
            expect(port.values.get('peek')).toMatchObject({ shown: { name: 'App\\C0' }, detail: { phase: 'loading' } })
            expect(port.timers).toHaveLength(1)
        })

        it('does not ask for a row already found to have nothing to show', async () => {
            const port = await started()
            mod.paneWide = true
            mod.peekNone = peekKey(view(), 's1')
            await port.tick()
            expect(port.values.get('peek')).toBeNull()
            expect(port.timers).toEqual([])
            expect(mod.peekNone).toBe(peekKey(view(), 's1'))
        })

        it('does not ask again while a looked-up row is loaded', async () => {
            const port = await started()
            mod.paneWide = true
            const loaded = { shown: { name: 'App\\C1' }, detail: { snapshot_id: 's1', name: 'App\\C1', detail: null, phase: 'done' } }
            port.values.set('peek', loaded)
            await port.tick()
            expect(port.values.get('peek')).toBe(loaded)
            expect(port.timers).toEqual([])
        })

        it('does not ask while the pane is not wide', async () => {
            const port = await started()
            await port.tick()
            expect(port.values.get('peek')).toBeNull()
            expect(port.timers).toEqual([])
        })
    })

    describe('redrawing', () => {
        it('forgets a pane that is no longer open, and does not redraw for it', async () => {
            const port = await started()
            mod.paneText = 'fresh'
            mod.paneWide = true
            mod.peekNone = peekKey(view(), 's1')
            port.env.panes = [{ id: 'some-other-pane' }]
            await port.tick()
            expect(mod.paneText).toBeNull()
            expect(mod.paneWide).toBe(false)
            expect(port.invalidated).toEqual([])
        })

        it('does not redraw when neither the band nor the pane was drawn', async () => {
            const port = await started()
            port.values.set('job', { phase: 'scanning' })
            port.env.panes = [{ id: PANE }]
            await port.tick()
            expect(port.invalidated).toEqual([])
        })

        it('redraws once when the band text it would draw differs from what it drew', async () => {
            const port = await started()
            port.values.set('job', { phase: 'scanning' })
            mod.bandText = 'knossos · scan failed'
            await port.tick()
            expect(port.invalidated).toEqual(['ui.render'])
        })

        it('does not redraw when the band and the pane would draw what they drew', async () => {
            const port = await started()
            port.values.set('job', { phase: 'scanning' })
            port.env.panes = [{ id: PANE }]
            const d = port.values.get('dashboard') as Dashboard
            // What the band would draw from these same cells, taken from the band's own model rather than written out here.
            mod.bandText = bandModel(null, { phase: 'scanning' } as never, port.env.now, declaredOf(d), huesOf(d))!.text
            mod.paneText = paneStatus(d, port.values.get('refresh') as never, port.values.get('rescan') as never, port.env.now, LIVE_OFF).text
            await port.tick()
            expect(port.invalidated).toEqual([])
        })

        it('redraws the open pane once its age has moved on with the clock', async () => {
            const port = await started()
            port.env.panes = [{ id: PANE }]
            const d = port.values.get('dashboard') as Dashboard
            const statusAt = (now: number) => paneStatus(d, port.values.get('refresh') as never, port.values.get('rescan') as never, now, LIVE_OFF).text
            mod.paneText = statusAt(port.env.now)
            await port.tick()
            expect(port.invalidated).toEqual([])
            port.env.now += 120_000
            // The age the pane would show now is not the one it drew.
            expect(statusAt(port.env.now)).not.toBe(mod.paneText)
            await port.tick()
            expect(port.invalidated).toEqual(['ui.render'])
        })

        it('redraws the open pane when the dashboard no longer has figures to put an age on', async () => {
            const port = await started()
            port.env.panes = [{ id: PANE }]
            port.values.set('dashboard', { status: 'silent' })
            mod.paneText = 'fresh · 1s'
            await port.tick()
            expect(port.invalidated).toEqual(['ui.render'])
        })
    })

    it('swallows a tick that fails part-way: nothing surfaces, and what comes after the failure is not done', async () => {
        const port = await started()
        port.env.now = 5_000
        const lit = { names: ['App\\C0'], until: 1 }
        port.values.set('flash', lit)
        port.failing.add('feedback')
        mod.bandText = 'knossos · scan failed'
        // An unhandled rejection would fail the run; the ticker's own catch keeps it in.
        await port.tick()
        expect(port.values.get('flash')).toBe(lit)
        expect(port.invalidated).toEqual([])
    })
})
