import { beforeEach, describe, expect, it } from 'vitest'
import type { RescanState } from '../../types'
import { noActivity } from '../lib/activity'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { mod, reset } from './state'
import { requestRescan } from './watcher'

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

/** When the scan begins and when it lands, on the mod's clock. */
const STARTED = 10_000
const ENDED = 12_000

/**
 * A port with what a rescan touches: the session state as plain values, a
 * clock whose timers run only when the test advances it, and a wrapper that
 * records every subcommand it runs. The scan's answer is either a string
 * (moving the clock to {@link ENDED} as it lands) or a promise the test
 * resolves; the dashboard reload answers silence.
 */
function rescanPort(scan: string | Promise<string> = JSON.stringify({ status: 'ok', snapshot_id: 's2' }), failRescan?: (next: RescanState) => boolean) {
    const values = new Map<string, unknown>(
        Object.entries({ rescan: { phase: 'idle', reason: null }, refresh: { fetchedAt: null, failed: false }, dashboard: null, sessionScans: [], live: LIVE_OFF, sessionStart: null, flash: null }),
    )
    const runs: string[] = []
    const timers: { at: number; run: () => void; done: boolean }[] = []
    let now = STARTED
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => values.get(key),
                update: async (change: (v: unknown) => unknown) => {
                    const next = change(values.get(key))
                    if (key === 'rescan' && failRescan?.(next as RescanState)) throw new Error('state refused')
                    values.set(key, next)
                    return next
                },
            }),
        },
    )
    const io = {
        clock: {
            now: async () => now,
            after: (ms: number, run: () => void) => {
                const timer = { at: now + ms, run, done: false }
                timers.push(timer)
                return { cancel: () => void (timer.done = true) }
            },
        },
        ui: { log: () => undefined, invalidate: () => undefined, toast: () => undefined },
        session: { root: async () => ROOT },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                const sub = argv[2]!
                runs.push(sub)
                if (sub === 'scan') {
                    const stdout = await scan
                    now = ENDED
                    return { exitCode: 0, stdout, stderr: '' }
                }
                return { exitCode: 0, stdout: '', stderr: '' }
            },
        },
        state,
    } as unknown as Port
    const flush = async () => {
        for (let i = 0; i < 20; i++) await new Promise(resolve => setTimeout(resolve, 0))
    }
    /** Runs every timer due within `ms`, then lets what they started settle. */
    const advance = async (ms = 0) => {
        for (let due = timers.find(t => !t.done && t.at <= now + ms); due !== undefined; due = timers.find(t => !t.done && t.at <= now + ms)) {
            due.done = true
            due.run()
        }
        await flush()
    }
    return { io, values, runs, advance, flush }
}

const answer = (body: Record<string, unknown>) => JSON.stringify(body)

beforeEach(() => reset(options))

describe('requestRescan', () => {
    it('runs nothing until the clock advances, then one scan, the dashboard after it, and ends idle', async () => {
        const port = rescanPort()
        requestRescan(port.io)
        await port.flush()
        expect(port.runs).toEqual([])
        expect(port.values.get('rescan')).toEqual({ phase: 'idle', reason: null })
        await port.advance(0)
        expect(port.runs).toEqual(['scan', 'dashboard'])
        expect(port.values.get('rescan')).toEqual({ phase: 'idle', reason: null })
    })

    it('coalesces two presses before the timer fires into one scan', async () => {
        const port = rescanPort()
        requestRescan(port.io)
        requestRescan(port.io)
        await port.advance(0)
        expect(port.runs.filter(r => r === 'scan')).toHaveLength(1)
    })

    it('adds nothing for a press while the scan runs, and shows scanning meanwhile', async () => {
        let land!: (stdout: string) => void
        const port = rescanPort(new Promise<string>(resolve => (land = resolve)))
        requestRescan(port.io)
        await port.advance(0)
        expect(port.runs).toEqual(['scan'])
        expect(port.values.get('rescan')).toEqual({ phase: 'scanning', reason: null })
        requestRescan(port.io)
        await port.advance(0)
        expect(port.runs).toEqual(['scan'])
        land(answer({ status: 'ok', snapshot_id: 's2' }))
        await port.advance(0)
        expect(port.runs).toEqual(['scan', 'dashboard'])
        expect(port.values.get('rescan')).toEqual({ phase: 'idle', reason: null })
    })

    it('runs a second scan for a press after the first completed', async () => {
        const port = rescanPort()
        requestRescan(port.io)
        await port.advance(0)
        requestRescan(port.io)
        await port.advance(0)
        expect(port.runs).toEqual(['scan', 'dashboard', 'scan', 'dashboard'])
    })

    it("keeps an ok scan as the session's own when its tools ran inside the scan's window", async () => {
        mod.activity = { running: new Map(), spans: [{ start: STARTED + 500, end: STARTED + 900 }] }
        const port = rescanPort()
        requestRescan(port.io)
        await port.advance(0)
        expect(port.values.get('sessionScans')).toEqual(['s2'])
        expect(mod.lastScan).toEqual({ start: STARTED, end: ENDED })
    })

    it('keeps an ok scan out of the session when nothing of it ran, and still remembers it as the last scan', async () => {
        mod.activity = noActivity()
        const port = rescanPort()
        requestRescan(port.io)
        await port.advance(0)
        expect(port.values.get('sessionScans')).toEqual([])
        expect(mod.lastScan).toEqual({ start: STARTED, end: ENDED })
        expect(port.values.get('rescan')).toEqual({ phase: 'idle', reason: null })
    })

    it.each([null, 7])('attributes nothing for an ok with snapshot_id %s, but still reloads and goes idle', async snapshot => {
        mod.activity = { running: new Map([['c1', STARTED]]), spans: [] }
        const port = rescanPort(answer({ status: 'ok', snapshot_id: snapshot }))
        requestRescan(port.io)
        await port.advance(0)
        expect(port.values.get('sessionScans')).toEqual([])
        expect(mod.lastScan).toBeNull()
        expect(port.runs).toEqual(['scan', 'dashboard'])
        expect(port.values.get('rescan')).toEqual({ phase: 'idle', reason: null })
    })

    it.each([
        ['silence', '', 'knossos said nothing', null],
        ['not-allowed with a refused root', answer({ status: 'not-allowed', refused_root: '/x' }), 'not an allowed root', '/x'],
        ['not-allowed without a refused root', answer({ status: 'not-allowed' }), 'not an allowed root', null],
        ['missing', answer({ status: 'missing', refused_root: '/x' }), 'the project is gone', null],
        ['unscanned', answer({ status: 'unscanned' }), 'never scanned', null],
        ['scan-failed with a reason', answer({ status: 'scan-failed', reason: 'parser crashed' }), 'parser crashed', null],
        ['scan-failed without a reason', answer({ status: 'scan-failed' }), 'the scan failed', null],
        ['error', answer({ status: 'error' }), 'knossos could not run it', null],
    ])('fails on %s with its reason and reloads nothing', async (_, stdout, reason, refusedRoot) => {
        const port = rescanPort(stdout)
        requestRescan(port.io)
        await port.advance(0)
        expect(port.values.get('rescan')).toEqual({ phase: 'failed', reason, refusedRoot })
        expect(port.runs).toEqual(['scan'])
        expect(mod.lastScan).toBeNull()
    })

    it('goes idle and turns the mod off on no-binary, without a dashboard run', async () => {
        const port = rescanPort(answer({ status: 'no-binary' }))
        requestRescan(port.io)
        await port.advance(0)
        expect(port.values.get('rescan')).toEqual({ phase: 'idle', reason: null })
        expect(mod.disabled).toBe(true)
        expect(port.runs).toEqual(['scan'])
    })

    it('does nothing while the mod is off', async () => {
        mod.disabled = true
        const port = rescanPort()
        port.values.set('rescan', { phase: 'failed', reason: 'earlier', refusedRoot: null })
        requestRescan(port.io)
        await port.advance(0)
        expect(port.runs).toEqual([])
        expect(port.values.get('rescan')).toEqual({ phase: 'failed', reason: 'earlier', refusedRoot: null })
    })

    it('ends failed without a reason when marking it scanning throws', async () => {
        const port = rescanPort(undefined, next => next.phase === 'scanning')
        requestRescan(port.io)
        await port.advance(0)
        expect(port.values.get('rescan')).toEqual({ phase: 'failed', reason: null })
        expect(port.runs).toEqual([])
    })

    it('swallows a failure update that rejects too, and takes the next press', async () => {
        const tried: string[] = []
        const port = rescanPort(undefined, next => {
            tried.push(next.phase)
            return true
        })
        requestRescan(port.io)
        await port.advance(0)
        expect(tried).toEqual(['scanning', 'failed'])
        expect(port.values.get('rescan')).toEqual({ phase: 'idle', reason: null })
        // The flight settled rather than rejecting: a new press runs again instead of being coalesced away.
        requestRescan(port.io)
        await port.advance(0)
        expect(tried).toEqual(['scanning', 'failed', 'scanning', 'failed'])
        expect(port.runs).toEqual([])
    })
})
