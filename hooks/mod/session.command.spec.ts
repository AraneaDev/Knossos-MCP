import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView } from '../../types'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { runCommand } from './session'
import { mod, PANE, reset } from './state'

const ROOT = '/work/app'
const USAGE = 'Usage: /knossos to toggle the architecture pane; /knossos inspect <component> to open it on one component.'
const OPENED = 'Knossos pane opened.'
const CLOSED = 'Knossos pane closed.'
const dashboard = (snapshot: string): Dashboard =>
    ({
        status: 'ok',
        path: ROOT,
        project_root: ROOT,
        project_id: 'p1',
        snapshot_id: snapshot,
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
    }) as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'churn', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

/**
 * A port over plain values: the cells the command reads and writes, a clock
 * whose timers run only when the test says, a pane list the test sets, every
 * open and close recorded, and a wrapper that answers each subcommand from
 * `answers` and records every run.
 */
function fakePort(initial: Record<string, unknown>, opts: { open?: boolean; closeFails?: boolean; answers?: Record<string, string> } = {}) {
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
        startCycles: null,
    }
    const values = new Map<string, unknown>(Object.entries({ ...blank, ...initial }))
    const timers: { at: number; ms: number; run: () => void; cancelled: boolean }[] = []
    const runs: string[][] = []
    const opened: unknown[] = []
    const closed: unknown[] = []
    let panes: { id: string }[] = opts.open === true ? [{ id: PANE }] : []
    const now = 0
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
                const timer = { at: now + ms, ms, run, cancelled: false }
                timers.push(timer)
                return { cancel: () => void (timer.cancelled = true) }
            },
        },
        ui: {
            log: () => undefined,
            invalidate: () => undefined,
            toast: () => undefined,
            panes: async () => panes,
            open: async (args: { id: string }) => {
                opened.push(args)
                panes = [{ id: args.id }]
                return {}
            },
            close: async (args: unknown) => {
                closed.push(args)
                if (opts.closeFails === true) throw new Error('pane refused to close')
                panes = []
                return {}
            },
        },
        // No session named: a first snapshot is not saved as a baseline for a later process.
        session: { root: async () => ROOT, id: async () => null },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                return { exitCode: 0, stdout: opts.answers?.[argv[2]!] ?? '', stderr: '' }
            },
        },
        state,
    } as unknown as Port
    /** Runs every timer due now, and what they schedule in turn, then lets the reads they started settle. */
    const advance = async () => {
        for (let due = timers.find(t => !t.cancelled && t.at <= now); due !== undefined; due = timers.find(t => !t.cancelled && t.at <= now)) {
            due.cancelled = true
            due.run()
            await new Promise(resolve => setTimeout(resolve, 0))
        }
        await new Promise(resolve => setTimeout(resolve, 0))
    }
    const dashboardRuns = () => runs.filter(argv => argv[2] === 'dashboard').length
    return { io, values, runs, opened, closed, timers, advance, dashboardRuns }
}

beforeEach(() => reset(options))

describe('/knossos with no arguments', () => {
    it.each(['', '   ', '\t\n'])('opens a closed pane on the tab rather than a component (args %j), then refreshes the dashboard on a timer', async args => {
        const port = fakePort({ dashboard: dashboard('s1'), view: view({ inspect: { name: 'App\\Greeter', label: 'Greeter' } }) }, { answers: { dashboard: JSON.stringify(dashboard('s2')) } })
        expect(await runCommand(port.io, args)).toBe(OPENED)
        expect(port.opened).toEqual([{ id: PANE, title: 'Knossos' }])
        expect(port.closed).toEqual([])
        expect((port.values.get('view') as KnossosView).inspect).toBeNull()
        // The refresh waits for a timer due at once, never inside the command.
        expect(port.dashboardRuns()).toBe(0)
        expect(port.timers.filter(t => !t.cancelled).map(t => t.ms)).toEqual([0])
        await port.advance()
        expect(port.dashboardRuns()).toBe(1)
        expect((port.values.get('dashboard') as Dashboard).snapshot_id).toBe('s2')
    })

    it('closes an open pane and forgets what it drew and that it was wide', async () => {
        const port = fakePort({ dashboard: dashboard('s1') }, { open: true })
        mod.paneText = 'as of 3s ago'
        mod.paneWide = true
        expect(await runCommand(port.io, '')).toBe(CLOSED)
        expect(port.closed).toEqual([{ id: PANE }])
        expect(port.opened).toEqual([])
        expect(mod.paneText).toBeNull()
        expect(mod.paneWide).toBe(false)
        // Closing asks for nothing.
        await port.advance()
        expect(port.runs).toEqual([])
    })

    it('still says the pane closed, and forgets it, when the close is refused', async () => {
        const port = fakePort({ dashboard: dashboard('s1') }, { open: true, closeFails: true })
        mod.paneText = 'as of 3s ago'
        mod.paneWide = true
        expect(await runCommand(port.io, '  ')).toBe(CLOSED)
        expect(port.closed).toEqual([{ id: PANE }])
        expect(mod.paneText).toBeNull()
        expect(mod.paneWide).toBe(false)
    })

    it('toggles: a second call closes what the first opened', async () => {
        const port = fakePort({ dashboard: dashboard('s1') })
        expect(await runCommand(port.io, '')).toBe(OPENED)
        expect(await runCommand(port.io, '')).toBe(CLOSED)
        expect(port.opened).toHaveLength(1)
        expect(port.closed).toEqual([{ id: PANE }])
    })
})

describe('/knossos inspect', () => {
    it('shows the named component on a stored dashboard without loading it first, and refreshes it on a timer once open', async () => {
        const port = fakePort({ dashboard: dashboard('s1') }, { answers: { dashboard: JSON.stringify(dashboard('s2')) } })
        expect(await runCommand(port.io, 'inspect App\\Greeter')).toBe(OPENED)
        expect((port.values.get('view') as KnossosView).inspect).toEqual({ name: 'App\\Greeter', label: 'App\\Greeter' })
        expect(port.opened).toEqual([{ id: PANE, title: 'Knossos' }])
        // Nothing read inside the command: the dashboard was there to key the lookup to.
        expect(port.dashboardRuns()).toBe(0)
        await port.advance()
        // The stored figures may be old: the pane refreshes them once open.
        expect(port.dashboardRuns()).toBe(1)
        expect(port.runs.some(argv => argv[2] === 'component-detail' && argv.includes('App\\Greeter'))).toBe(true)
    })

    it('loads the dashboard first when none is stored, then shows the component, and does not refresh it again', async () => {
        const port = fakePort({}, { answers: { dashboard: JSON.stringify(dashboard('s1')) } })
        expect(await runCommand(port.io, 'inspect Foo')).toBe(OPENED)
        // Loaded inside the command, before the lookup is keyed to it.
        expect(port.dashboardRuns()).toBe(1)
        expect((port.values.get('dashboard') as Dashboard).snapshot_id).toBe('s1')
        expect((port.values.get('view') as KnossosView).inspect).toEqual({ name: 'Foo', label: 'Foo' })
        expect(port.opened).toEqual([{ id: PANE, title: 'Knossos' }])
        await port.advance()
        // Just loaded: the pane is opened with no second dashboard read.
        expect(port.dashboardRuns()).toBe(1)
    })

    it('still refreshes on a timer when no dashboard is stored and the load stores nothing', async () => {
        // Silence from the wrapper: nothing was loaded, so the open pane asks again.
        const port = fakePort({})
        expect(await runCommand(port.io, 'inspect Foo')).toBe(OPENED)
        expect(port.dashboardRuns()).toBe(1)
        await port.advance()
        expect(port.dashboardRuns()).toBe(2)
    })

    it('takes the whole rest as the name, trimmed, inner spaces kept', async () => {
        const port = fakePort({ dashboard: dashboard('s1') })
        expect(await runCommand(port.io, '  inspect   multi word name ')).toBe(OPENED)
        expect((port.values.get('view') as KnossosView).inspect).toEqual({ name: 'multi word name', label: 'multi word name' })
    })

    it('opens the pane on a component even while it is already open, never closing it', async () => {
        const port = fakePort({ dashboard: dashboard('s1') }, { open: true })
        expect(await runCommand(port.io, 'inspect Foo')).toBe(OPENED)
        expect(port.closed).toEqual([])
        expect(port.opened).toEqual([{ id: PANE, title: 'Knossos' }])
    })

    it.each(['inspect', 'inspect   ', '  inspect  '])('answers the usage to %j with no name, and opens nothing', async args => {
        const port = fakePort({ dashboard: dashboard('s1'), view: view({ inspect: { name: 'Kept', label: 'Kept' } }) })
        expect(await runCommand(port.io, args)).toBe(USAGE)
        expect(port.opened).toEqual([])
        expect(port.closed).toEqual([])
        expect((port.values.get('view') as KnossosView).inspect).toEqual({ name: 'Kept', label: 'Kept' })
        await port.advance()
        expect(port.runs).toEqual([])
    })
})

describe('/knossos with anything else', () => {
    it.each(['foo', 'foo bar', 'INSPECT Foo', 'inspector Foo', 'close'])('answers the usage to %j and touches no pane', async args => {
        const port = fakePort({ dashboard: dashboard('s1') }, { open: true })
        mod.paneText = 'as of 3s ago'
        expect(await runCommand(port.io, args)).toBe(USAGE)
        expect(port.opened).toEqual([])
        expect(port.closed).toEqual([])
        expect(mod.paneText).toBe('as of 3s ago')
        await port.advance()
        expect(port.runs).toEqual([])
    })
})
