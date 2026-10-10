import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard, SessionLedger } from '../../types'
import { FOLLOWED_SCAN_MS } from '../lib/activity'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { mod, reset, stopWatcher } from './state'
import { ensureWatcher } from './watcher'

const ROOT = '/work/app'
const SCRIPT = '/plugin/hooks/scripts/knossos-run.sh'
const dashboard = (over: Partial<Dashboard> = {}): Dashboard => ({ status: 'ok', path: ROOT, project_root: ROOT, snapshot_id: 's0', ...over }) as Dashboard
const ledger = (since: string, files: SessionLedger['files'] = {}): SessionLedger => ({ status: 'ok', since, complete: true, files, files_truncated: false, tests: [], tests_truncated: false })
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true }

/** The watcher's lookback (poll 1000 + debounce 300 + grace 1500), as `attributeScan` widens a scan's window by. */
const LOOKBACK_MS = 1_000 + 300 + 1_500

type Piece = { stream: string; text: string }

/**
 * A watcher child's output the test writes piece by piece: `push` hands the
 * loop one piece, `end` ends the stream, `fail` makes the read throw.
 */
function channel() {
    const queue: ({ piece: Piece } | { end: true } | { error: unknown })[] = []
    let wake: (() => void) | null = null
    const put = (item: (typeof queue)[number]) => {
        queue.push(item)
        wake?.()
        wake = null
    }
    async function* read(): AsyncGenerator<Piece, unknown> {
        for (;;) {
            while (queue.length === 0) await new Promise<void>(resolve => (wake = resolve))
            const item = queue.shift()!
            if ('end' in item) return undefined
            if ('error' in item) throw item.error
            yield item.piece
        }
    }
    return {
        stream: read(),
        push: (text: string, stream = 'stdout') => put({ piece: { stream, text } }),
        event: (e: object) => put({ piece: { stream: 'stdout', text: `${JSON.stringify(e)}\n` } }),
        end: () => put({ end: true }),
        fail: (error: unknown) => put({ error }),
    }
}

/**
 * A port over plain values: the session state as cells, a clock whose timers
 * run only when the test advances it, a wrapper answering each subcommand
 * from `answers` (a string, or a function run when it is asked), and a spawn
 * that hands out the next of `streams` (or throws when given an error).
 */
function fakePort(initial: Record<string, unknown> = {}, answers: Record<string, string | (() => string)> = {}) {
    const blank = {
        dashboard: dashboard(),
        live: LIVE_OFF,
        sessionLedger: null,
        sessionStart: null,
        sessionScans: [],
        untestedOnly: false,
        flash: null,
        refresh: { fetchedAt: null, failed: false },
        changes: NO_CHANGES,
    }
    const values = new Map<string, unknown>(Object.entries({ ...blank, ...initial }))
    const timers: { at: number; run: () => void; cancelled: boolean }[] = []
    const runs: string[][] = []
    const spawned: string[][] = []
    const streams: (ReturnType<typeof channel> | Error)[] = []
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
        },
        ui: { log: () => undefined, invalidate: () => undefined },
        session: { root: async () => ROOT },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                const answer = answers[argv[2]!]
                return { exitCode: 0, stdout: typeof answer === 'function' ? answer() : (answer ?? ''), stderr: '' }
            },
            spawn: ({ argv }: { argv: string[] }) => {
                spawned.push(argv)
                const next = streams.shift()
                if (next === undefined || next instanceof Error) throw next ?? new Error('no stream')
                return next.stream
            },
        },
        state,
    } as unknown as Port
    /** Runs every timer due within `ms`, then lets what they started settle. */
    const advance = async (ms = 0) => {
        now += ms
        for (let due = timers.find(t => !t.cancelled && t.at <= now); due !== undefined; due = timers.find(t => !t.cancelled && t.at <= now)) {
            due.cancelled = true
            due.run()
            await settle()
        }
        await settle()
    }
    /** Starts a watcher on `ch` and lets it come to its first read. */
    const start = async (ch = channel()) => {
        streams.push(ch)
        await ensureWatcher(io)
        await advance(0)
        return ch
    }
    return {
        io,
        values,
        runs,
        spawned,
        streams,
        advance,
        start,
        setNow: (ms: number) => void (now = ms),
        now: () => now,
        pending: () => timers.filter(t => !t.cancelled).length,
        ran: (sub: string) => runs.filter(r => r[2] === sub),
    }
}

/** Lets the loop's reads and the state writes they start settle. */
const settle = async () => {
    for (let i = 0; i < 10; i++) await new Promise(resolve => setTimeout(resolve, 0))
}

beforeEach(() => reset(options))

describe('ensureWatcher guards', () => {
    const guards: [string, () => void, Record<string, unknown>][] = [
        ['the watcher is switched off', () => void (mod.watchOn = false), {}],
        ['the mod is disabled', () => void (mod.disabled = true), {}],
        ['a watcher runs', () => void (mod.watcher = channel().stream), {}],
        ['a watcher is starting', () => void (mod.watchStarting = true), {}],
        ['the root was refused', () => void (mod.watchRefused = true), {}],
        ['the pause before a restart is not over', () => void (mod.watchRetryAt = 5), {}],
        ['there is no dashboard', () => undefined, { dashboard: null }],
        ['the dashboard is not ok', () => undefined, { dashboard: { status: 'unscanned' } }],
        ['the dashboard names no project root', () => undefined, { dashboard: dashboard({ project_root: null } as never) }],
    ]

    it.each(guards)('schedules nothing when %s', async (_, arrange, initial) => {
        const port = fakePort(initial)
        arrange()
        const gen = mod.watchGen
        const starting = mod.watchStarting
        await ensureWatcher(port.io)
        expect(port.pending()).toBe(0)
        expect(mod.watchGen).toBe(gen)
        expect(mod.watchStarting).toBe(starting)
        await port.advance(0)
        expect(port.spawned).toEqual([])
    })
})

describe('starting the watcher', () => {
    it('marks it starting and bumps the generation at once, and spawns the watch on the next tick', async () => {
        const port = fakePort()
        port.streams.push(channel())
        const gen = mod.watchGen
        await ensureWatcher(port.io)
        expect(mod.watchStarting).toBe(true)
        expect(mod.watchGen).toBe(gen + 1)
        expect(port.spawned).toEqual([])
        await port.advance(0)
        expect(port.spawned).toEqual([['sh', SCRIPT, 'watch', ROOT, '--poll-ms=1000']])
        expect(mod.watchStarting).toBe(false)
        expect(mod.watcher).not.toBeNull()
        expect(port.values.get('live')).toEqual({ phase: 'starting' })
    })

    it('a second call while one is starting schedules no second start', async () => {
        const port = fakePort()
        port.streams.push(channel())
        await ensureWatcher(port.io)
        await ensureWatcher(port.io)
        expect(port.pending()).toBe(1)
    })

    it('refuses the root for good when the spawn throws', async () => {
        const port = fakePort()
        port.streams.push(new Error('spawn failed'))
        await ensureWatcher(port.io)
        await port.advance(0)
        expect(port.spawned).toHaveLength(1)
        expect(mod.watchRefused).toBe(true)
        expect(mod.watcher).toBeNull()
        // Not asked again.
        await ensureWatcher(port.io)
        expect(port.pending()).toBe(0)
    })
})

describe('the watcher stream', () => {
    it('a ready event takes the header out of off, resets the failures, and reads the ledger since the session began', async () => {
        const port = fakePort({ sessionStart: 's0' })
        mod.watchFailures = 2
        const ch = await port.start()
        ch.event({ event: 'ready' })
        await settle()
        expect(port.values.get('live')).toEqual({ phase: 'live' })
        expect(mod.watchFailures).toBe(0)
        expect(port.ran('session-changes')).toEqual([['sh', SCRIPT, 'session-changes', ROOT, '--since=s0']])
    })

    it('reads an event split over two pieces once, and ignores stderr', async () => {
        const port = fakePort({ sessionStart: 's0' })
        const ch = await port.start()
        ch.push('{"event":"ready","pid":4321}\n', 'stderr')
        await settle()
        expect(port.values.get('live')).toEqual({ phase: 'starting' })
        expect(mod.watcherPid).toBeNull()
        ch.push('{"event":"rea')
        await settle()
        expect(port.values.get('live')).toEqual({ phase: 'starting' })
        ch.push('dy","pid":42}\n')
        await settle()
        expect(port.values.get('live')).toEqual({ phase: 'live' })
        expect(mod.watcherPid).toBe(42)
        expect(port.ran('session-changes')).toHaveLength(1)
    })

    it('a changes event marks changes pending until a scan_completed lands them', async () => {
        const port = fakePort()
        const ch = await port.start()
        ch.event({ event: 'ready' })
        ch.event({ event: 'changes' })
        await settle()
        expect(mod.watchPending).toBe(true)
        ch.event({ event: 'scan_completed' })
        await settle()
        expect(mod.watchPending).toBe(false)
    })

    it.each(['absorbed', 'stopped'])('a %s event clears pending changes', async event => {
        const port = fakePort()
        const ch = await port.start()
        ch.event({ event: 'changes' })
        await settle()
        expect(mod.watchPending).toBe(true)
        ch.event({ event })
        await settle()
        expect(mod.watchPending).toBe(false)
    })

    it('records only a whole pid above 1', async () => {
        const port = fakePort()
        const ch = await port.start()
        for (const pid of [1, 12.5, '1234', 0, -7]) ch.event({ event: 'ready', pid })
        await settle()
        expect(mod.watcherPid).toBeNull()
        ch.event({ event: 'ready', pid: 1234 })
        await settle()
        expect(mod.watcherPid).toBe(1234)
    })

    it('a new snapshot updates the known one and reloads the dashboard; the same one again does nothing', async () => {
        const port = fakePort()
        mod.snapshot = 's0'
        const ch = await port.start()
        ch.event({ event: 'snapshot', snapshot_id: 's1' })
        await settle()
        expect(mod.snapshot).toBe('s1')
        expect(port.ran('dashboard')).toHaveLength(1)
        ch.event({ event: 'snapshot', snapshot_id: 's1' })
        await settle()
        expect(port.ran('dashboard')).toHaveLength(1)
    })

    it("keeps a scan as the session's when its tools ran while it was under way", async () => {
        const port = fakePort()
        port.setNow(100_000)
        const ch = await port.start()
        ch.event({ event: 'scan_started' })
        await settle()
        mod.activity.spans.push({ start: 100_200, end: 100_400 })
        port.setNow(101_000)
        ch.event({ event: 'scan_completed', snapshot_id: 's1' })
        await settle()
        expect(port.values.get('sessionScans')).toEqual(['s1'])
        expect(mod.lastScan).toEqual({ start: 100_000, end: 101_000 })
    })

    it('does not keep a scan nothing of the session ran during', async () => {
        const port = fakePort()
        port.setNow(100_000)
        const ch = await port.start()
        ch.event({ event: 'scan_started' })
        await settle()
        port.setNow(101_000)
        ch.event({ event: 'scan_completed', snapshot_id: 's1' })
        await settle()
        expect(port.values.get('sessionScans')).toEqual([])
        // Remembered as the last scan either way.
        expect(mod.lastScan).toEqual({ start: 100_000, end: 101_000 })
    })

    it.each(['absorbed', 'snapshot'])('an unseen %s scan is dated a poll and a followed scan back', async event => {
        const port = fakePort()
        const now = 200_000
        port.setNow(now)
        const ch = await port.start()
        const start = now - 1_000 - FOLLOWED_SCAN_MS
        // Activity that ended exactly where the window opens counts.
        mod.activity.spans.push({ start: start - LOOKBACK_MS - 500, end: start - LOOKBACK_MS })
        ch.event({ event, snapshot_id: 's1' })
        await settle()
        expect(port.values.get('sessionScans')).toEqual(['s1'])
        expect(mod.lastScan).toEqual({ start, end: now })
    })

    it('an unseen absorbed scan is not kept for activity just outside that window', async () => {
        const port = fakePort()
        const now = 200_000
        port.setNow(now)
        const ch = await port.start()
        const start = now - 1_000 - FOLLOWED_SCAN_MS
        mod.activity.spans.push({ start: start - LOOKBACK_MS - 500, end: start - LOOKBACK_MS - 1 })
        ch.event({ event: 'absorbed', snapshot_id: 's1' })
        await settle()
        expect(port.values.get('sessionScans')).toEqual([])
    })
})

describe('the end of the stream', () => {
    it('a refused event ends the loop for good: refused, off, no restart', async () => {
        const port = fakePort()
        const ch = await port.start()
        ch.event({ event: 'refused' })
        await settle()
        expect(mod.watchRefused).toBe(true)
        expect(mod.watcher).toBeNull()
        expect(port.values.get('live')).toEqual(LIVE_OFF)
        expect(mod.watchFailures).toBe(0)
        expect(mod.watchRetryAt).toBe(0)
        await ensureWatcher(port.io)
        expect(port.pending()).toBe(0)
    })

    it('a stopped event ends the loop without waiting for the stream to end', async () => {
        const port = fakePort()
        const ch = await port.start()
        ch.event({ event: 'ready', pid: 99 })
        ch.event({ event: 'stopped' })
        await settle()
        expect(mod.watcher).toBeNull()
        expect(mod.watcherPid).toBeNull()
        expect(port.values.get('live')).toEqual(LIVE_OFF)
        expect(mod.watchFailures).toBe(1)
    })

    it('a no-binary answer turns the mod off', async () => {
        const port = fakePort()
        const ch = await port.start()
        ch.event({ status: 'no-binary' })
        await settle()
        expect(mod.disabled).toBe(true)
        expect(mod.watcher).toBeNull()
        expect(port.values.get('live')).toEqual(LIVE_OFF)
    })

    it('a stream that ends without a word refuses the root', async () => {
        const port = fakePort()
        const ch = await port.start()
        ch.end()
        await settle()
        expect(mod.watchRefused).toBe(true)
        expect(mod.watchFailures).toBe(0)
        expect(mod.watcher).toBeNull()
        expect(port.values.get('live')).toEqual(LIVE_OFF)
    })

    it('a stream that ends after coming up is retried after a growing pause, and never after the third failure', async () => {
        const port = fakePort()
        port.setNow(1_000)
        const ch = await port.start()
        ch.event({ event: 'ready', pid: 1234 })
        await settle()
        expect(mod.watcherPid).toBe(1234)
        ch.end()
        await settle()
        expect(mod.watchFailures).toBe(1)
        expect(mod.watchRetryAt).toBe(31_000)
        expect(mod.watcher).toBeNull()
        expect(mod.watcherPid).toBeNull()
        expect(port.values.get('live')).toEqual(LIVE_OFF)
        expect(mod.watchRefused).toBe(false)

        // Not before the pause is over.
        port.setNow(30_999)
        await ensureWatcher(port.io)
        expect(port.pending()).toBe(0)

        // Later runs come up without `ready`, so the count goes on.
        for (const [failures, pause] of [
            [2, 60_000],
            [3, 90_000],
        ] as const) {
            port.setNow(mod.watchRetryAt)
            const next = await port.start()
            next.event({ event: 'leading' })
            next.end()
            await settle()
            expect(mod.watchFailures).toBe(failures)
            expect(mod.watchRetryAt).toBe(port.now() + pause)
        }
        port.setNow(mod.watchRetryAt)
        const last = await port.start()
        last.event({ event: 'leading' })
        last.end()
        await settle()
        expect(mod.watchFailures).toBe(4)
        expect(mod.watchRetryAt).toBe(Number.POSITIVE_INFINITY)
        expect(port.spawned).toHaveLength(4)
    })

    it('a read that throws is handled as an end', async () => {
        const port = fakePort()
        port.setNow(5_000)
        const ch = await port.start()
        ch.event({ event: 'ready', pid: 77 })
        await settle()
        ch.fail(new Error('torn down'))
        await settle()
        expect(mod.watcher).toBeNull()
        expect(mod.watcherPid).toBeNull()
        expect(mod.watchFailures).toBe(1)
        expect(mod.watchRetryAt).toBe(35_000)
        expect(port.values.get('live')).toEqual(LIVE_OFF)
    })

    it('stopped from outside, it counts no failure and says nothing is live', async () => {
        const port = fakePort()
        const ch = await port.start()
        ch.event({ event: 'leading' })
        await settle()
        mod.watchFailures = 2
        expect(port.values.get('live')).toEqual({ phase: 'live' })
        stopWatcher()
        ch.event({ event: 'changes' })
        await settle()
        expect(port.values.get('live')).toEqual(LIVE_OFF)
        expect(mod.watchFailures).toBe(2)
        expect(mod.watchRetryAt).toBe(0)
        expect(mod.watchRefused).toBe(false)
        // The piece that arrived after the stop was not acted on.
        expect(mod.watchPending).toBe(false)
    })

    it('stopped from outside while a newer watcher is starting, it leaves the header alone', async () => {
        const port = fakePort()
        const ch = await port.start()
        ch.event({ event: 'leading' })
        await settle()
        stopWatcher()
        mod.watchStarting = true
        ch.event({ event: 'changes' })
        await settle()
        expect(port.values.get('live')).toEqual({ phase: 'live' })
        expect(mod.watchFailures).toBe(0)
    })
})

describe('the session ledger', () => {
    it('stores an ok answer', async () => {
        const answer = ledger('s0', { 'src/a.ts': { status: 'modified', dependents: 1, boundaries: [], boundary: null } } as never)
        const port = fakePort({ sessionStart: 's0' }, { 'session-changes': JSON.stringify(answer) })
        const ch = await port.start()
        ch.event({ event: 'ready' })
        await settle()
        expect(port.values.get('sessionLedger')).toEqual(answer)
    })

    it('turns the mod off on no-binary', async () => {
        const port = fakePort({ sessionStart: 's0' }, { 'session-changes': '{"status":"no-binary"}' })
        const ch = await port.start()
        ch.event({ event: 'ready' })
        await settle()
        expect(mod.disabled).toBe(true)
        expect(port.values.get('sessionLedger')).toBeNull()
    })

    it('drops an answer when the session began again during the read', async () => {
        let port: ReturnType<typeof fakePort> | null = null
        port = fakePort(
            { sessionStart: 's0' },
            {
                'session-changes': () => {
                    port!.values.set('sessionStart', 's9')
                    return JSON.stringify(ledger('s0'))
                },
            },
        )
        const ch = await port.start()
        ch.event({ event: 'ready' })
        await settle()
        expect(port.ran('session-changes')).toHaveLength(1)
        expect(port.values.get('sessionLedger')).toBeNull()
    })

    it('asks nothing without a session start', async () => {
        const port = fakePort({ sessionStart: null }, { 'session-changes': JSON.stringify(ledger('s0')) })
        const ch = await port.start()
        ch.event({ event: 'ready' })
        await settle()
        expect(port.ran('session-changes')).toEqual([])
    })

    it('keeps the previous ledger when the answer is not ok', async () => {
        const previous = ledger('s0')
        const port = fakePort({ sessionStart: 's0', sessionLedger: previous }, { 'session-changes': '{"status":"error"}' })
        const ch = await port.start()
        ch.event({ event: 'ready' })
        await settle()
        expect(port.ran('session-changes')).toHaveLength(1)
        expect(port.values.get('sessionLedger')).toBe(previous)
    })

    it.each(['leading', 'following'])('a %s event reads the ledger too', async event => {
        const port = fakePort({ sessionStart: 's0' })
        const ch = await port.start()
        ch.event({ event })
        await settle()
        expect(port.ran('session-changes')).toHaveLength(1)
    })
})
