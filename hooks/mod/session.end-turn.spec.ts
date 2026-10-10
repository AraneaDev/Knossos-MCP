import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Dashboard } from '../../types'
import type { Port } from './port'
import { endTurn } from './session'
import { mod, reset } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }
const okDashboard = { status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: 's1' } as unknown as Dashboard

/**
 * A port over plain values: cells held in a map, a clock whose timers run
 * only when the test says, a wrapper that answers nothing and records the
 * subcommand of every run, and a command registry that records each call.
 */
function fakePort(initial: Record<string, unknown> = {}) {
    const values = new Map<string, unknown>(Object.entries({ dashboard: null, gitHead: null, view: null, churn: null, ...initial }))
    const timers: { ms: number; run: () => void; cancelled: boolean }[] = []
    const subs: string[] = []
    const registered: unknown[] = []
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
            now: async () => 0,
            after: (ms: number, run: () => void) => {
                const timer = { ms, run, cancelled: false }
                timers.push(timer)
                return { cancel: () => void (timer.cancelled = true) }
            },
        },
        ui: { log: () => undefined, invalidate: () => undefined },
        session: { root: async () => ROOT },
        plugin: { root: '/plugin' },
        command: {
            register: async (spec: unknown) => {
                registered.push(spec)
            },
        },
        process: {
            run: async (argv: string[]) => {
                subs.push(argv[2]!)
                return { exitCode: 0, stdout: '', stderr: '' }
            },
        },
        state,
    } as unknown as Port
    /** Runs each pending timer once (not what they schedule in turn) and returns the wrapper subcommands they ran. */
    const runTimers = async (): Promise<string[]> => {
        const from = subs.length
        for (const timer of timers.filter(t => !t.cancelled)) {
            timer.cancelled = true
            timer.run()
        }
        await new Promise(resolve => setTimeout(resolve, 0))
        await new Promise(resolve => setTimeout(resolve, 0))
        return subs.slice(from)
    }
    return { io, values, timers, registered, runTimers, pending: () => timers.filter(t => !t.cancelled) }
}

/** The state after a quiet main-loop turn: registered, nothing dirty or edited. */
function quiet(): void {
    reset(options)
    mod.commandRegistered = true
}

afterEach(() => {
    vi.restoreAllMocks()
})

describe('endTurn', () => {
    it("ends a subagent's turn at its own notes: the main loop's commands and timers are left alone", async () => {
        quiet()
        mod.commandRegistered = false
        mod.dirty = true
        mod.turnNotes.set('sub-1', 2)
        mod.turnNotes.set('', 5)
        mod.ranCommands = ['npm test']
        const port = fakePort()
        await endTurn(port.io, 'sub-1')
        expect(mod.turnNotes.has('sub-1')).toBe(false)
        expect(mod.turnNotes.get('')).toBe(5)
        expect(mod.ranCommands).toEqual(['npm test'])
        expect(mod.turnRan).toEqual([])
        expect(mod.dirty).toBe(true)
        expect(port.registered).toEqual([])
        expect(port.timers).toEqual([])
    })

    it("clears the main loop's notes and hands the turn's commands to turnRan", async () => {
        quiet()
        mod.turnNotes.set('', 3)
        mod.turnNotes.set('sub-1', 1)
        mod.ranCommands = ['npm test', 'git status']
        const port = fakePort({ dashboard: okDashboard })
        await endTurn(port.io, undefined)
        expect(mod.turnNotes.has('')).toBe(false)
        expect(mod.turnNotes.get('sub-1')).toBe(1)
        expect(mod.turnRan).toEqual(['npm test', 'git status'])
        expect(mod.ranCommands).toEqual([])
    })

    it('asks for /knossos again while it is refused and no timed retry is pending', async () => {
        quiet()
        mod.commandRegistered = false
        const port = fakePort({ dashboard: okDashboard })
        await endTurn(port.io, undefined)
        expect(port.registered).toEqual([expect.objectContaining({ name: 'knossos' })])
        expect(mod.commandRegistered).toBe(true)
    })

    it('leaves the registration to a pending timed retry', async () => {
        quiet()
        mod.commandRegistered = false
        mod.registerTimer = { cancel: () => undefined } as never
        const port = fakePort({ dashboard: okDashboard })
        await endTurn(port.io, undefined)
        expect(port.registered).toEqual([])
        expect(mod.commandRegistered).toBe(false)
    })

    it('neither registers, reads the head, refreshes nor scans once the mod is off', async () => {
        quiet()
        mod.commandRegistered = false
        mod.disabled = true
        mod.dirty = true
        mod.edited.add('src/a.ts')
        const port = fakePort()
        await endTurn(port.io, undefined)
        expect(port.registered).toEqual([])
        expect(port.timers).toEqual([])
        expect(mod.dirty).toBe(true)
        expect(mod.flight).toBeNull()
    })

    it("reads the checkout's head again on a 0ms timer, and refreshes nothing while a graph is on show", async () => {
        quiet()
        const port = fakePort({ dashboard: okDashboard })
        await endTurn(port.io, undefined)
        expect(port.pending().map(t => t.ms)).toEqual([0])
        // Not inside the hook: the read waits for the timer.
        const ran = await port.runTimers()
        expect(ran).toContain('session-head')
        expect(ran).not.toContain('dashboard')
    })

    it.each([
        ['no dashboard', null],
        ['a dashboard that is not ok', { status: 'error' }],
    ])('refreshes the dashboard after the turn when there is %s', async (_, dashboard) => {
        quiet()
        const port = fakePort({ dashboard })
        await endTurn(port.io, undefined)
        expect(port.pending().map(t => t.ms)).toEqual([0, 0])
        const ran = await port.runTimers()
        expect(ran).toContain('session-head')
        expect(ran).toContain('dashboard')
    })

    it('drops the turn start when nothing is dirty or edited', async () => {
        quiet()
        mod.turnBase = 'abc123'
        await endTurn(fakePort({ dashboard: okDashboard }).io, undefined)
        expect(mod.turnBase).toBeNull()
    })

    it('keeps the turn start while edits wait for a scan', async () => {
        quiet()
        mod.turnBase = 'abc123'
        mod.edited.add('src/a.ts')
        await endTurn(fakePort({ dashboard: okDashboard }).io, undefined)
        expect(mod.turnBase).toBe('abc123')
    })

    it('requests the scan of a dirty turn only once its 0ms timer runs', async () => {
        quiet()
        mod.dirty = true
        mod.turnBase = 'abc123'
        const port = fakePort({ dashboard: okDashboard })
        await endTurn(port.io, undefined)
        expect(mod.dirty).toBe(false)
        expect(mod.turnBase).toBe('abc123')
        expect(mod.flight).not.toBeNull()
        const request = vi.spyOn(mod.flight!, 'request').mockResolvedValue(undefined)
        // The git-head read and the scan, both deferred: nothing scans inside the hook.
        expect(port.pending().map(t => t.ms)).toEqual([0, 0])
        expect(request).not.toHaveBeenCalled()
        await port.runTimers()
        expect(request).toHaveBeenCalledTimes(1)
    })

    it('shares one scan flight across dirty turns', async () => {
        quiet()
        const port = fakePort({ dashboard: okDashboard })
        mod.dirty = true
        await endTurn(port.io, undefined)
        const first = mod.flight
        const request = vi.spyOn(first!, 'request').mockResolvedValue(undefined)
        mod.dirty = true
        await endTurn(port.io, undefined)
        expect(mod.flight).toBe(first)
        await port.runTimers()
        expect(request).toHaveBeenCalledTimes(2)
    })
})
