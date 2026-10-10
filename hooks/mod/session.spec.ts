import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { BASELINES_KEY } from '../lib/baseline'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { startUp } from './session'
import { mod, PANE, reset } from './state'

const ROOT = '/work/app'
const REAL_ROOT = '/real/app'
const REV = 'abcdef1234567'
const dashboard: Dashboard = {
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
} as Dashboard
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
const options = {
    fanInThreshold: 20,
    enforcePolicies: true,
    agentNotes: true,
    watch: true,
    watchPollMs: 1000,
    notifications: true,
}

type Setup = {
    /** The wrapper's stdout by subcommand. */
    answers?: Record<string, string>
    /** What `/config` lists; an Error makes the listing reject. */
    config?: { key: string; value: unknown }[] | Error
    /** The session's root; an Error makes it reject. */
    root?: string | Error
    /** The baselines the plugin's store holds. */
    stored?: unknown
}

/**
 * A port over plain values: the cells startUp reads and writes, a clock
 * whose timers run only when the test says, a wrapper that answers each
 * subcommand from `answers`, and a log of every call in the order it came.
 */
function fakePort(setup: Setup = {}) {
    const answers = {
        dashboard: JSON.stringify(dashboard),
        'session-head': JSON.stringify({
            status: 'ok',
            rev: REV,
            branch: 'main',
        }),
        ...setup.answers,
    }
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
        job: null,
    }
    const values = new Map<string, unknown>(Object.entries(blank))
    const timers: { at: number; run: () => void; cancelled: boolean }[] = []
    const tickers: { ms: number; cancelled: boolean }[] = []
    const calls: string[] = []
    const opened: unknown[] = []
    let now = 0
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    values.set(key, change(values.get(key)))
                    return values.get(key)
                },
            }),
        },
    )
    const io = {
        clock: {
            now: async () => now,
            after: (ms: number, run: () => void) => {
                const timer = { at: now + ms, run, cancelled: false }
                timers.push(timer)
                return { cancel: () => void (timer.cancelled = true) }
            },
            every: (ms: number) => {
                const ticker = { ms, cancelled: false }
                tickers.push(ticker)
                return { cancel: () => void (ticker.cancelled = true) }
            },
        },
        ui: {
            log: () => undefined,
            invalidate: () => undefined,
            toast: () => undefined,
            panes: async () => [],
            open: async (pane: unknown) => {
                opened.push(pane)
            },
        },
        session: {
            root: async () => {
                if (setup.root instanceof Error) throw setup.root
                return setup.root ?? ROOT
            },
            id: async () => 'sess-1',
        },
        fs: {
            stat: async (path: string) => ({
                realPath: path === ROOT ? REAL_ROOT : path,
            }),
        },
        store: {
            get: async (key: string) => {
                calls.push(`store.get:${key}`)
                return setup.stored
            },
            set: async (key: string) => void calls.push(`store.set:${key}`),
        },
        config: {
            list: async () => {
                calls.push('config.list')
                if (setup.config instanceof Error) throw setup.config
                return setup.config ?? []
            },
        },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                calls.push(`run:${argv[2]}`)
                return {
                    exitCode: 0,
                    stdout: answers[argv[2] as keyof typeof answers] ?? '',
                    stderr: '',
                }
            },
            spawn: () => {
                calls.push('spawn')
                throw new Error('no watcher in this test')
            },
        },
        state,
    } as unknown as Port
    /** Runs every timer due within `ms`, and what they schedule in turn, then lets the reads they started settle. */
    const advance = async (ms = 0) => {
        now += ms
        for (let due = timers.find(t => !t.cancelled && t.at <= now); due !== undefined; due = timers.find(t => !t.cancelled && t.at <= now)) {
            due.cancelled = true
            due.run()
            await new Promise(resolve => setTimeout(resolve, 0))
        }
        await new Promise(resolve => setTimeout(resolve, 0))
    }
    const runsOf = (sub: string) => calls.filter(c => c === `run:${sub}`).length
    return { io, values, calls, tickers, opened, advance, runsOf }
}

describe('startUp', () => {
    it('stores the theme and the placed root, loads the dashboard, starts one age ticker and the watcher, and opens the pane without a second load', async () => {
        reset(options)
        const port = fakePort({
            config: [
                // A string row under another key, first in line: only the 'theme' key counts.
                { key: 'verbose', value: 'solarized' },
                { key: 'theme', value: 'light' },
            ],
        })
        await startUp(port.io, true)
        expect(port.values.get('theme')).toBe('light')
        // The root as the session gave it, with its links followed.
        expect(port.values.get('sessionRoot')).toBe(REAL_ROOT)
        expect(port.values.get('dashboard')).toEqual(dashboard)
        expect(port.tickers).toEqual([{ ms: 1000, cancelled: false }])
        expect(mod.ticker).not.toBeNull()
        // The watcher is on its way: its start waits for a timer.
        expect(mod.watchStarting).toBe(true)
        expect(port.opened).toEqual([{ id: PANE, title: 'Knossos' }])
        await port.advance()
        // The dashboard was just stored: the opened pane asks for no second load.
        expect(port.runsOf('dashboard')).toBe(1)
    })

    it('keeps the dark default when the config cannot be read, and does not throw', async () => {
        reset(options)
        const port = fakePort({ config: new Error('config unreadable') })
        await expect(startUp(port.io, false)).resolves.toBeUndefined()
        expect(port.values.get('theme')).toBe('dark')
        expect(port.values.get('dashboard')).toEqual(dashboard)
    })

    it('keeps the default theme when the theme row holds no string', async () => {
        reset(options)
        const port = fakePort({ config: [{ key: 'theme', value: 1 }] })
        await startUp(port.io, false)
        expect(port.values.get('theme')).toBe('dark')
    })

    it('stores a null session root when the session cannot say its root', async () => {
        reset(options)
        const port = fakePort({ root: new Error('no root') })
        // A stale root from before must not survive.
        port.values.set('sessionRoot', '/stale')
        await startUp(port.io, false)
        expect(port.values.get('sessionRoot')).toBeNull()
    })

    it('reads the git head once: the baseline already asked it', async () => {
        reset(options)
        const port = fakePort()
        await startUp(port.io, false)
        expect(mod.gitAsked).toBe(true)
        expect(port.runsOf('session-head')).toBe(1)
        expect(port.values.get('gitHead')).toEqual({
            rev: REV,
            branch: 'main',
        })
    })

    it('reads the git head itself when the baseline came back from the store without asking it', async () => {
        reset(options)
        const port = fakePort({
            stored: {
                'sess-1': {
                    rev: { status: 'ok', rev: REV },
                    snapshot: 's0',
                    startedAt: 5,
                },
            },
        })
        await startUp(port.io, false)
        expect(port.runsOf('session-head')).toBe(1)
        expect(port.values.get('gitHead')).toEqual({
            rev: REV,
            branch: 'main',
        })
    })

    it('does not read the git head again once it was asked', async () => {
        reset(options)
        mod.gitAsked = true
        const port = fakePort({
            stored: {
                'sess-1': {
                    rev: { status: 'ok', rev: REV },
                    snapshot: 's0',
                    startedAt: 5,
                },
            },
        })
        await startUp(port.io, false)
        expect(port.runsOf('session-head')).toBe(0)
        expect(port.values.get('gitHead')).toBeNull()
    })

    it('stops when the dashboard load turns the mod off: no ticker, no watcher, no pane', async () => {
        reset(options)
        const port = fakePort({
            answers: { dashboard: JSON.stringify({ status: 'no-binary' }) },
        })
        await startUp(port.io, true)
        expect(mod.disabled).toBe(true)
        expect(port.tickers).toEqual([])
        expect(mod.ticker).toBeNull()
        expect(mod.watchStarting).toBe(false)
        expect(port.opened).toEqual([])
        await port.advance()
        expect(port.calls).not.toContain('spawn')
        expect(port.runsOf('dashboard')).toBe(1)
    })

    it('leaves the pane closed when it is not to open on start', async () => {
        reset(options)
        const port = fakePort()
        await startUp(port.io, false)
        expect(port.opened).toEqual([])
        expect(port.tickers).toHaveLength(1)
    })

    it('opens the pane and loads the dashboard again on a timer when the first load stored nothing', async () => {
        reset(options)
        const port = fakePort({ answers: { dashboard: '' } })
        await startUp(port.io, true)
        expect(port.values.get('dashboard')).toBeNull()
        expect(port.opened).toEqual([{ id: PANE, title: 'Knossos' }])
        // Not inside start-up: the second load waits for the timer.
        expect(port.runsOf('dashboard')).toBe(1)
        await port.advance()
        expect(port.runsOf('dashboard')).toBe(2)
        // A silent load marks the refresh failed; the mod stays on and its ticker runs.
        expect(mod.disabled).toBe(false)
        expect(port.tickers).toHaveLength(1)
    })

    it('starts the age ticker once across two start-ups', async () => {
        reset(options)
        const port = fakePort()
        await startUp(port.io, false)
        const first = mod.ticker
        await startUp(port.io, false)
        expect(port.tickers).toHaveLength(1)
        expect(mod.ticker).toBe(first)
    })

    it('settles the baseline before it reads the git head or the dashboard', async () => {
        reset(options)
        const port = fakePort({
            stored: {
                'sess-1': {
                    rev: { status: 'ok', rev: REV },
                    snapshot: 's0',
                    startedAt: 5,
                },
            },
        })
        await startUp(port.io, false)
        const at = (call: string) => port.calls.indexOf(call)
        expect(at(`store.get:${BASELINES_KEY}`)).toBeGreaterThanOrEqual(0)
        expect(at(`store.get:${BASELINES_KEY}`)).toBeLessThan(at('run:session-head'))
        expect(at('run:session-head')).toBeLessThan(at('run:dashboard'))
        // The kept baseline was in place before the dashboard read: its first snapshot stays the session's start.
        expect(port.values.get('sessionRev')).toEqual({
            status: 'ok',
            rev: REV,
        })
        expect(port.values.get('sessionStart')).toBe('s0')
    })
})
