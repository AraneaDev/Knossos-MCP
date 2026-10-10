import { describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { BASELINES_KEY } from '../lib/baseline'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { endSession } from './session'
import { mod, reset } from './state'

const ROOT = '/work/app'
const REV = 'abcdef1234567'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true }
const okDashboard = {
    status: 'ok',
    path: ROOT,
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    cycles: { count: 1, truncated: false, truncation_reasons: [], largest: [{ members: ['b', 'a'] }] },
} as unknown as Dashboard

/** The session cells as a session that ran for a while leaves them. */
const lived = (): Record<string, unknown> => ({
    dashboard: null,
    view: { inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in' },
    live: { phase: 'live' },
    sessionStart: 'old-snap',
    startCycles: { count: 9, keys: [], complete: true },
    sessionBegan: 123,
    sessionEdits: ['src/a.ts'],
    sessionScans: [{ at: 1 }],
    sessionRev: { status: 'ok', rev: REV },
    fileDiff: { path: 'src/a.ts' },
    diffFold: { path: 'src/a.ts' },
    untestedOnly: true,
    sessionLedger: { entries: [] },
    changes: { changed: ['x'] },
    gitHead: null,
    churn: null,
})

type Setup = {
    /** What `io.session.id` answers. */
    sessionId?: string | null
    /** Makes the live cell's update reject, so ending the watcher fails. */
    liveFails?: boolean
}

/**
 * A port over plain values: cells held in a map, a clock whose timers run
 * only when the test says, a store that records each write, and a wrapper
 * that answers `session-head` with a commit and records every run.
 */
function fakePort(initial: Record<string, unknown> = lived(), setup: Setup = {}) {
    const values = new Map<string, unknown>(Object.entries(initial))
    const timers: { ms: number; run: () => void; cancelled: boolean }[] = []
    const runs: string[][] = []
    const stored: { key: string; value: unknown }[] = []
    let sessionId: string | null = setup.sessionId ?? null
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    if (key === 'live' && setup.liveFails === true) throw new Error('live cell gone')
                    values.set(key, change(values.get(key)))
                    return values.get(key)
                },
            }),
        },
    )
    const io = {
        clock: {
            now: async () => 50,
            after: (ms: number, run: () => void) => {
                const timer = { ms, run, cancelled: false }
                timers.push(timer)
                return { cancel: () => void (timer.cancelled = true) }
            },
        },
        ui: { log: () => undefined, invalidate: () => undefined },
        session: { root: async () => ROOT, id: async () => sessionId },
        plugin: { root: '/plugin' },
        store: {
            get: async () => undefined,
            set: async (key: string, value: unknown) => void stored.push({ key, value }),
        },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                const stdout = argv[2] === 'session-head' ? JSON.stringify({ status: 'ok', rev: REV, branch: 'main' }) : ''
                return { exitCode: 0, stdout, stderr: '' }
            },
        },
        state,
    } as unknown as Port
    /** Runs each pending timer once and lets what it started settle. */
    const runTimers = async (): Promise<void> => {
        for (const timer of timers.filter(t => !t.cancelled)) {
            timer.cancelled = true
            timer.run()
        }
        for (let i = 0; i < 5; i++) await new Promise(resolve => setTimeout(resolve, 0))
    }
    return {
        io,
        values,
        timers,
        runs,
        stored,
        runTimers,
        setSession: (id: string | null) => void (sessionId = id),
    }
}

/** The mod as a session that ran for a while leaves it: notes said, a watcher running, a baseline settled. */
function midSession(): void {
    reset(options)
    mod.snapshot = 'snap-now'
    mod.watchFailures = 3
    mod.watchRetryAt = 999
    mod.commitNoted = new Set(['c1'])
    mod.toasted = new Set(['t1'])
    mod.noted = new Set(['n1'])
    mod.ruled = new Set(['r1'])
    mod.testsNamed = new Set(['tn1'])
    mod.violationsSeen = new Set(['v1'])
    mod.truncationSaid = true
    mod.baselineOf = 'sess-old'
    mod.headAsked = true
    mod.endedSession = null
    mod.watcher = { return: async () => undefined } as never
    mod.watcherPid = 4242
}

describe('endSession', () => {
    it.each(['prompt_input_exit', 'logout', 'other'])('after a final end (%s) never retries the watcher and resets nothing else', async reason => {
        midSession()
        const port = fakePort()
        const before = new Map(port.values)
        await endSession(port.io, reason, 'sess-old')
        // The watcher ended: signalled and said to be off.
        expect(mod.watcher).toBeNull()
        expect(port.runs).toContainEqual(['kill', '-TERM', '4242'])
        expect(port.values.get('live')).toEqual(LIVE_OFF)
        expect(mod.watchRetryAt).toBe(Number.POSITIVE_INFINITY)
        expect(mod.watchFailures).toBe(3)
        expect([...mod.noted]).toEqual(['n1'])
        expect([...mod.ruled]).toEqual(['r1'])
        expect([...mod.testsNamed]).toEqual(['tn1'])
        expect([...mod.violationsSeen]).toEqual(['v1'])
        expect([...mod.commitNoted]).toEqual(['c1'])
        expect([...mod.toasted]).toEqual(['t1'])
        expect(mod.truncationSaid).toBe(true)
        expect(mod.endedSession).toBeNull()
        expect(mod.baselineOf).toBe('sess-old')
        expect(mod.headAsked).toBe(true)
        for (const [key, value] of before) if (key !== 'live') expect(port.values.get(key)).toEqual(value)
        expect(port.timers).toEqual([])
    })

    it.each(['clear', 'resume'])('after a %s resets the watch, the notes and the session cells for the next session', async reason => {
        midSession()
        const port = fakePort()
        await endSession(port.io, reason, 'sess-old')
        expect(mod.watcher).toBeNull()
        expect(port.values.get('live')).toEqual(LIVE_OFF)
        expect(mod.watchFailures).toBe(0)
        expect(mod.watchRetryAt).toBe(0)
        expect(port.values.get('sessionStart')).toBe('snap-now')
        expect(mod.commitNoted.size).toBe(0)
        expect(mod.toasted.size).toBe(0)
        expect(mod.noted.size).toBe(0)
        expect(mod.ruled.size).toBe(0)
        expect(mod.testsNamed.size).toBe(0)
        expect(mod.violationsSeen.size).toBe(0)
        expect(mod.truncationSaid).toBe(false)
        expect(port.values.get('sessionBegan')).toBeNull()
        expect(port.values.get('sessionEdits')).toEqual([])
        expect(port.values.get('sessionScans')).toEqual([])
        expect(port.values.get('sessionRev')).toBeNull()
        expect(port.values.get('fileDiff')).toBeNull()
        expect(port.values.get('diffFold')).toBeNull()
        expect(port.values.get('untestedOnly')).toBe(false)
        expect(port.values.get('sessionLedger')).toBeNull()
        expect(port.values.get('changes')).toBe(NO_CHANGES)
    })

    it("takes the next session's start cycles from an ok dashboard", async () => {
        midSession()
        const port = fakePort({ ...lived(), dashboard: okDashboard })
        await endSession(port.io, 'clear', 'sess-old')
        expect(port.values.get('startCycles')).toEqual({ count: 1, keys: ['a\u0000b'], complete: true, exact: true })
    })

    it.each([
        ['no dashboard', null],
        ['a dashboard that is not ok', { status: 'error' }],
    ])('records no start cycles when there is %s', async (_, dashboard) => {
        midSession()
        const port = fakePort({ ...lived(), dashboard })
        await endSession(port.io, 'clear', 'sess-old')
        expect(port.values.get('startCycles')).toBeNull()
    })

    it('marks the session ended and forgets its baseline, settling the next one on a 0ms timer', async () => {
        midSession()
        const port = fakePort()
        await endSession(port.io, 'clear', 'sess-old')
        expect(mod.endedSession).toBe('sess-old')
        expect(mod.baselineOf).toBeNull()
        expect(mod.headAsked).toBe(false)
        expect(port.timers.map(t => t.ms)).toEqual([0])
        // Nothing settles inside the hook.
        expect(port.stored).toEqual([])
        expect(port.runs.some(argv => argv[2] === 'session-head')).toBe(false)
    })

    it('saves no baseline for the ended session while the process still names it', async () => {
        midSession()
        const port = fakePort(lived(), { sessionId: 'sess-old' })
        await endSession(port.io, 'clear', 'sess-old')
        await port.runTimers()
        // The timer did settle: it asked where the checkout stands, but kept nothing for the ended id.
        expect(port.runs.some(argv => argv[2] === 'session-head')).toBe(true)
        expect(mod.headAsked).toBe(true)
        expect(port.stored).toEqual([])
        expect(mod.baselineOf).toBeNull()
    })

    it('records the baseline under the new session id once the process names it', async () => {
        midSession()
        const port = fakePort(lived(), { sessionId: 'sess-new' })
        await endSession(port.io, 'resume', 'sess-old')
        await port.runTimers()
        expect(mod.baselineOf).toBe('sess-new')
        expect(port.stored).toHaveLength(1)
        expect(port.stored[0]!.key).toBe(BASELINES_KEY)
        const saved = port.stored[0]!.value as Record<string, unknown>
        expect(Object.keys(saved)).toEqual(['sess-new'])
        expect(saved['sess-new']).toEqual({ rev: { status: 'ok', rev: REV }, snapshot: 'snap-now', startedAt: 50 })
    })

    it('records the new session once the ended one is no longer named', async () => {
        midSession()
        const port = fakePort(lived(), { sessionId: 'sess-old' })
        await endSession(port.io, 'clear', 'sess-old')
        await port.runTimers()
        expect(port.stored).toEqual([])
        port.setSession('sess-new')
        await endSession(port.io, 'clear', 'sess-old')
        await port.runTimers()
        expect(mod.baselineOf).toBe('sess-new')
        expect(port.stored.map(s => Object.keys(s.value as object))).toEqual([['sess-new']])
    })

    it.each(['clear', 'logout'])('does not reject when ending the watcher fails (%s)', async reason => {
        midSession()
        const port = fakePort(lived(), { liveFails: true })
        await expect(endSession(port.io, reason, 'sess-old')).resolves.toBeUndefined()
        expect(mod.watcher).toBeNull()
        if (reason === 'clear') {
            expect(mod.watchRetryAt).toBe(0)
            expect(mod.endedSession).toBe('sess-old')
        } else {
            expect(mod.watchRetryAt).toBe(Number.POSITIVE_INFINITY)
        }
    })
})
