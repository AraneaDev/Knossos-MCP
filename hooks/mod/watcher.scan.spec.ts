import { describe, expect, it } from 'vitest'
import type { TurnBrief } from '../../types'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { mod, reset } from './state'
import { scanSafely } from './watcher'

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true }

/** The watcher's poll, its debounce and the margin settleWatcher waits after the last edit (ms). */
const QUIET_MS = 1000 + 300 + 250

/** An `ok` turn brief over `a.ts`, with nothing to say to the model unless `over` adds it. */
const brief = (over: Partial<TurnBrief> = {}): TurnBrief => ({
    status: 'ok',
    project_root: ROOT,
    project_id: 'p1',
    snapshot_id: 's1',
    scanned_at: 1,
    scan_ms: 5,
    reason: null,
    roots_file: null,
    refused_root: null,
    path: ROOT,
    changed_files: ['a.ts'],
    added_files: [],
    deleted_files: [],
    impact: {},
    tests: [],
    policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
    ...over,
})

type Setup = {
    /** The wrapper's stdout by subcommand. */
    answers?: Record<string, string>
    /** Initial cell values over the blank ones. */
    initial?: Record<string, unknown>
    /** Runs while the wrapper answers a subcommand, before it returns. */
    during?: (sub: string) => void
    /** Whether the n-th (0-based) update of the job cell rejects. */
    jobFails?: (n: number) => boolean
    /** Whether the clock's `now` rejects. */
    clockFails?: boolean
    /** What `session.append` answers. */
    append?: { deny?: string }
}

/**
 * A port over plain values: the cells the scan reads and writes, a clock
 * whose timers run only when the test says, a wrapper that answers each
 * subcommand from `answers`, and a log of every call in the order it came.
 */
function fakePort(setup: Setup = {}) {
    const answers: Record<string, string> = { ...setup.answers }
    const blank = {
        brief: null,
        dashboard: null,
        changes: NO_CHANGES,
        sessionRoot: null,
        live: LIVE_OFF,
        sessionLedger: null,
        sessionStart: null,
        sessionBegan: null,
        sessionEdits: [],
        sessionScans: [],
        untestedOnly: false,
        flash: null,
        refresh: { fetchedAt: null, failed: false },
        job: { phase: 'idle', lastAttemptAt: null },
        ...setup.initial,
    }
    const values = new Map<string, unknown>(Object.entries(blank))
    const timers: { at: number; run: () => void; cancelled: boolean }[] = []
    const calls: string[] = []
    const runs: string[][] = []
    const logs: { text: string; to?: string }[] = []
    const appended: unknown[] = []
    let jobUpdates = 0
    let now = 0
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    if (key === 'job' && setup.jobFails?.(jobUpdates++)) throw new Error('job cell refused')
                    values.set(key, change(values.get(key)))
                    return values.get(key)
                },
            }),
        },
    )
    const io = {
        clock: {
            now: async () => {
                if (setup.clockFails) throw new Error('clock gone')
                return now
            },
            after: (ms: number, run: () => void) => {
                const timer = { at: now + ms, run, cancelled: false }
                timers.push(timer)
                return { cancel: () => void (timer.cancelled = true) }
            },
            every: () => ({ cancel: () => undefined }),
        },
        ui: {
            log: (text: string, opts?: { to?: string }) => void logs.push({ text, to: opts?.to }),
            invalidate: () => undefined,
            toast: () => undefined,
        },
        session: {
            root: async () => ROOT,
            id: async () => 'sess-1',
            append: async (row: unknown) => {
                appended.push(row)
                return setup.append ?? {}
            },
        },
        fs: { stat: async (path: string) => ({ realPath: path }) },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                calls.push(`run:${argv[2]}`)
                runs.push(argv)
                setup.during?.(argv[2] ?? '')
                return { exitCode: 0, stdout: answers[argv[2] ?? ''] ?? '', stderr: '' }
            },
            spawn: () => {
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
    /** Moves the clock to `to` in 100 ms steps, the poll settleWatcher sleeps between its looks. */
    const stepTo = async (to: number) => {
        while (now < to) await advance(Math.min(100, to - now))
    }
    const runsOf = (sub: string) => calls.filter(c => c === `run:${sub}`).length
    const argsOf = (sub: string) => runs.filter(r => r[2] === sub).map(r => r.slice(4))
    return { io, values, calls, logs, appended, advance, stepTo, runsOf, argsOf }
}

/** A stand-in for the running watcher's stream: only `return()` is ever called on it. */
const runningWatcher = () => ({ return: async () => ({ done: true, value: undefined }) }) as unknown as AsyncGenerator<unknown, unknown>

/** Resolves true once `p` has settled. */
const settled = (p: Promise<unknown>) => {
    let done = false
    void p.then(() => (done = true))
    return () => done
}

describe('scanSafely: what it asks the wrapper', () => {
    it('passes each drained file and the turn base, then empties both', async () => {
        reset(options)
        mod.edited = new Set(['a.ts', 'b.ts'])
        mod.turnBase = 's0'
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) } })
        await scanSafely(port.io)
        const [args] = port.argsOf('turn-brief')
        expect(args).toContain('--files=a.ts')
        expect(args).toContain('--files=b.ts')
        expect(args).toContain('--since=s0')
        expect([...mod.edited]).toEqual([])
        expect(mod.turnBase).toBeNull()
    })

    it('passes no --since without a turn base', async () => {
        reset(options)
        mod.edited = new Set(['a.ts'])
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) } })
        await scanSafely(port.io)
        expect(port.argsOf('turn-brief')[0]?.some(a => a.startsWith('--since='))).toBe(false)
    })

    it('adds --no-policies only when policies are not enforced', async () => {
        reset({ ...options, enforcePolicies: false })
        const off = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) } })
        await scanSafely(off.io)
        expect(off.argsOf('turn-brief')[0]).toContain('--no-policies')

        reset(options)
        const on = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) } })
        await scanSafely(on.io)
        expect(on.argsOf('turn-brief')[0]).not.toContain('--no-policies')
    })

    it('without a watcher passes no --reuse-scan and reads no ledger, even when the header says live', async () => {
        reset(options)
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) }, initial: { live: { phase: 'live' }, sessionStart: 's0' } })
        await scanSafely(port.io)
        // Lets a ledger read the brief might have fired off reach the wrapper before looking.
        await port.advance(0)
        expect(port.argsOf('turn-brief')[0]).not.toContain('--reuse-scan')
        expect(port.runsOf('session-changes')).toBe(0)
    })

    it('with a watcher whose header is off passes no --reuse-scan', async () => {
        reset(options)
        mod.watcher = runningWatcher()
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) } })
        await scanSafely(port.io)
        expect(port.argsOf('turn-brief')[0]).not.toContain('--reuse-scan')
    })

    it('with a live watcher waits a poll, the debounce and a margin after the last edit, then passes --reuse-scan and asks for the ledger', async () => {
        reset(options)
        mod.watcher = runningWatcher()
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) }, initial: { live: { phase: 'live' }, sessionStart: 's0' } })
        const done = settled(scanSafely(port.io))
        // Lets the run reach its first look at the clock before time moves.
        await port.advance(0)
        await port.stepTo(QUIET_MS - 50)
        expect(port.runsOf('turn-brief')).toBe(0)
        await port.stepTo(QUIET_MS + 50)
        expect(port.runsOf('turn-brief')).toBe(1)
        expect(port.argsOf('turn-brief')[0]).toContain('--reuse-scan')
        expect(done()).toBe(true)
        expect(port.argsOf('session-changes')).toEqual([['--since=s0']])
    })

    it('measures the quiet from the last edit, not from the start', async () => {
        reset(options)
        mod.watcher = runningWatcher()
        mod.lastEditAt = 1000
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) }, initial: { live: { phase: 'live' } } })
        void scanSafely(port.io)
        await port.advance(0)
        await port.stepTo(1000 + QUIET_MS - 50)
        expect(port.runsOf('turn-brief')).toBe(0)
        await port.stepTo(1000 + QUIET_MS + 50)
        expect(port.runsOf('turn-brief')).toBe(1)
    })

    it('waits while the watcher holds changes it has not taken in', async () => {
        reset(options)
        mod.watcher = runningWatcher()
        mod.watchPending = true
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) }, initial: { live: { phase: 'live' } } })
        void scanSafely(port.io)
        await port.advance(0)
        await port.stepTo(QUIET_MS + 500)
        expect(port.runsOf('turn-brief')).toBe(0)
        mod.watchPending = false
        await port.stepTo(QUIET_MS + 700)
        expect(port.runsOf('turn-brief')).toBe(1)
    })

    it('runs the brief anyway once 20 s pass with the watcher still scanning', async () => {
        reset(options)
        mod.watcher = runningWatcher()
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) }, initial: { live: { phase: 'scanning' } } })
        const done = settled(scanSafely(port.io))
        // Lets the run reach its first look at the clock before time moves.
        await port.advance(0)
        await port.stepTo(19_900)
        expect(port.runsOf('turn-brief')).toBe(0)
        await port.stepTo(20_000)
        expect(port.runsOf('turn-brief')).toBe(1)
        expect(port.argsOf('turn-brief')[0]).toContain('--reuse-scan')
        expect(done()).toBe(true)
    })
})

describe('scanSafely: an ok brief', () => {
    it('stores the brief, counts the turn, marks the job idle at now, adopts the snapshot and refreshes the dashboard', async () => {
        reset(options)
        const parsed = brief({ snapshot_id: 's7' })
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(parsed) } })
        await port.advance(500)
        await scanSafely(port.io)
        expect(port.values.get('brief')).toEqual(parsed)
        expect((port.values.get('changes') as typeof NO_CHANGES).turns).toBe(1)
        expect(Object.keys((port.values.get('changes') as typeof NO_CHANGES).files)).toEqual(['a.ts'])
        expect(port.values.get('job')).toEqual({ phase: 'idle', lastAttemptAt: 500 })
        expect(mod.snapshot).toBe('s7')
        expect(port.runsOf('dashboard')).toBe(1)
        // The dashboard is reloaded after the brief, not before it.
        expect(port.calls.indexOf('run:dashboard')).toBeGreaterThan(port.calls.indexOf('run:turn-brief'))
    })

    it('keeps the snapshot it knew when the brief names none', async () => {
        reset(options)
        mod.snapshot = 'prev'
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief({ snapshot_id: null })) } })
        await scanSafely(port.io)
        expect(mod.snapshot).toBe('prev')
    })

    it("keeps the scan as the session's only when the brief scanned itself and names its snapshot", async () => {
        reset(options)
        const scanned = fakePort({ answers: { 'turn-brief': JSON.stringify(brief({ scanned: true, snapshot_id: 's9' })) } })
        await scanSafely(scanned.io)
        expect(scanned.values.get('sessionScans')).toEqual(['s9'])

        for (const over of [{ scanned: false, snapshot_id: 's9' }, { snapshot_id: 's9' }, { scanned: true, snapshot_id: null }]) {
            reset(options)
            const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief(over)) } })
            await scanSafely(port.io)
            expect(port.values.get('sessionScans')).toEqual([])
        }
    })

    it('does not put the drained files back', async () => {
        reset(options)
        mod.edited = new Set(['a.ts'])
        mod.turnBase = 's0'
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) } })
        await scanSafely(port.io)
        expect(mod.edited.size).toBe(0)
        expect(mod.turnBase).toBeNull()
    })

    it('turns the untested-only list back to every file once no shown file is untested', async () => {
        reset(options)
        const tested = brief({ impact: { 'a.ts': { path: 'a.ts', dependent_files: 1, boundaries: [], tests: 2 } } })
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(tested) }, initial: { untestedOnly: true } })
        await scanSafely(port.io)
        expect(port.values.get('untestedOnly')).toBe(false)
    })

    it('leaves the untested-only list narrowed while an untested file remains', async () => {
        reset(options)
        const untested = brief({ impact: { 'a.ts': { path: 'a.ts', dependent_files: 1, boundaries: [], tests: 0 } } })
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(untested) }, initial: { untestedOnly: true } })
        await scanSafely(port.io)
        expect(port.values.get('untestedOnly')).toBe(true)
    })

    it('tells the model of a fresh violation the turn introduced', async () => {
        reset(options)
        const violation = { policy_id: 'core-no-http', source: 'App\\Core\\Kernel', target: 'App\\Http\\Router', source_boundaries: [], target_boundaries: [] }
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief({ policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false } })) } })
        await scanSafely(port.io)
        expect(port.appended).toHaveLength(1)
        const row = port.appended[0] as { message: { type: string; content: { type: string; text: string }[] } }
        expect(row.message.type).toBe('user')
        const text = row.message.content[0]?.text ?? ''
        expect(text).toContain('core-no-http')
        expect(text).toContain('App\\Core\\Kernel')
        expect(text).toContain('App\\Http\\Router')
    })

    it('says nothing to the model when the turn has nothing new', async () => {
        reset(options)
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) } })
        await scanSafely(port.io)
        expect(port.appended).toEqual([])
    })

    it('logs a refused note at debug level and still finishes the job', async () => {
        reset(options)
        const violation = { policy_id: 'core-no-http', source: 'A', target: 'B', source_boundaries: [], target_boundaries: [] }
        const port = fakePort({
            answers: { 'turn-brief': JSON.stringify(brief({ policy: { status: 'evaluated', total: 1, violations: [violation], truncated: false } })) },
            append: { deny: 'a plugin above refused it' },
        })
        await expect(scanSafely(port.io)).resolves.toBeUndefined()
        const debug = port.logs.filter(l => l.to === 'debug')
        expect(debug).toHaveLength(1)
        expect(debug[0]?.text).toContain('a plugin above refused it')
        expect(port.runsOf('dashboard')).toBe(1)
    })
})

describe('scanSafely: a brief that did not land', () => {
    it('on silence marks the job failed, keeps the old brief and puts the files and the base back', async () => {
        reset(options)
        mod.edited = new Set(['a.ts', 'b.ts'])
        mod.turnBase = 's0'
        const old = brief({ snapshot_id: 'old' })
        const port = fakePort({ initial: { brief: old } })
        await port.advance(300)
        await scanSafely(port.io)
        expect(port.values.get('job')).toEqual({ phase: 'failed', lastAttemptAt: 300 })
        expect(port.values.get('brief')).toBe(old)
        expect([...mod.edited].sort()).toEqual(['a.ts', 'b.ts'])
        expect(mod.turnBase).toBe('s0')
        expect(port.runsOf('dashboard')).toBe(0)
    })

    it('keeps a newer turn base set while the brief ran', async () => {
        reset(options)
        mod.edited = new Set(['a.ts'])
        mod.turnBase = 's0'
        const port = fakePort({
            during: sub => {
                if (sub !== 'turn-brief') return
                mod.turnBase = 's5'
                mod.edited.add('c.ts')
            },
        })
        await scanSafely(port.io)
        expect(mod.turnBase).toBe('s5')
        expect([...mod.edited].sort()).toEqual(['a.ts', 'c.ts'])
    })

    it('on an error envelope marks the job failed and keeps the old brief', async () => {
        reset(options)
        const old = brief({ snapshot_id: 'old' })
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify({ status: 'error', reason: 'boom' }) }, initial: { brief: old } })
        await scanSafely(port.io)
        expect((port.values.get('job') as { phase: string }).phase).toBe('failed')
        expect(port.values.get('brief')).toBe(old)
    })

    it('stores the first scan-failed brief, marking the job failed', async () => {
        reset(options)
        const failed = { status: 'scan-failed', reason: 'parse error' }
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(failed) } })
        await scanSafely(port.io)
        expect(port.values.get('brief')).toMatchObject(failed)
        expect((port.values.get('job') as { phase: string }).phase).toBe('failed')
    })

    it('keeps the last ok brief over a later scan-failed one, marking the job failed', async () => {
        reset(options)
        const ok = brief()
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify({ status: 'scan-failed', reason: 'parse error' }) }, initial: { brief: ok } })
        await scanSafely(port.io)
        expect(port.values.get('brief')).toBe(ok)
        expect((port.values.get('job') as { phase: string }).phase).toBe('failed')
    })

    it('on no-binary marks the job idle, turns the mod off and loads no dashboard', async () => {
        reset(options)
        mod.edited = new Set(['a.ts'])
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify({ status: 'no-binary' }) } })
        await port.advance(200)
        await scanSafely(port.io)
        expect(port.values.get('job')).toEqual({ phase: 'idle', lastAttemptAt: 200 })
        expect(mod.disabled).toBe(true)
        expect(port.logs.some(l => l.text.includes('no knossos binary found'))).toBe(true)
        expect(port.runsOf('dashboard')).toBe(0)
        expect([...mod.edited]).toEqual(['a.ts'])
    })

    it.each(['unscanned', 'not-allowed'])('on %s stores the brief, idles the job, counts no turn, loads no dashboard and re-queues the files', async status => {
        reset(options)
        mod.edited = new Set(['a.ts'])
        mod.turnBase = 's0'
        const answer = { status, reason: 'not here', refused_root: status === 'not-allowed' ? ROOT : null }
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(answer) } })
        await port.advance(400)
        await scanSafely(port.io)
        expect(port.values.get('brief')).toMatchObject(answer)
        expect(port.values.get('changes')).toEqual(NO_CHANGES)
        expect(port.values.get('job')).toEqual({ phase: 'idle', lastAttemptAt: 400 })
        expect(port.runsOf('dashboard')).toBe(0)
        expect([...mod.edited]).toEqual(['a.ts'])
        expect(mod.turnBase).toBe('s0')
    })
})

describe('scanSafely: a run that throws', () => {
    it('resolves with the job failed at now when the scanning mark is refused, and re-queues the files', async () => {
        reset(options)
        mod.edited = new Set(['a.ts'])
        const port = fakePort({ answers: { 'turn-brief': JSON.stringify(brief()) }, jobFails: n => n === 0 })
        await port.advance(900)
        await expect(scanSafely(port.io)).resolves.toBeUndefined()
        expect(port.values.get('job')).toEqual({ phase: 'failed', lastAttemptAt: 900 })
        expect(port.runsOf('turn-brief')).toBe(0)
        expect([...mod.edited]).toEqual(['a.ts'])
    })

    it('keeps the previous attempt time when the clock rejects too', async () => {
        reset(options)
        const port = fakePort({ jobFails: n => n === 0, clockFails: true, initial: { job: { phase: 'idle', lastAttemptAt: 42 } } })
        await expect(scanSafely(port.io)).resolves.toBeUndefined()
        expect(port.values.get('job')).toEqual({ phase: 'failed', lastAttemptAt: 42 })
    })

    it('swallows a failed-mark update that rejects as well', async () => {
        reset(options)
        const before = { phase: 'idle', lastAttemptAt: 7 }
        const port = fakePort({ jobFails: () => true, initial: { job: before } })
        await expect(scanSafely(port.io)).resolves.toBeUndefined()
        expect(port.values.get('job')).toBe(before)
    })
})
