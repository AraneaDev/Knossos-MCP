import { beforeEach, describe, expect, it } from 'vitest'
import type { Port } from './port'
import { retryRegister } from './session'
import { mod, reset } from './state'

/**
 * A port with only what a registration retry touches: a clock whose timers
 * run only when the test fires them, the engine's command register answering
 * from `answers` in turn (a value to throw, or undefined to accept), and a log.
 */
function retryPort(answers: unknown[] = []) {
    const timers: { ms: number; run: () => void; handle: { cancel: () => void } }[] = []
    const registered: unknown[] = []
    const io = {
        clock: {
            after: (ms: number, run: () => void) => {
                const handle = { cancel: () => undefined }
                timers.push({ ms, run, handle })
                return handle
            },
        },
        command: {
            register: async (args: unknown) => {
                registered.push(args)
                const answer = answers.shift()
                if (answer !== undefined) throw answer
                return {}
            },
        },
        ui: { log: () => undefined },
    } as unknown as Port
    return { io, timers, registered }
}

/** Lets the register call a fired timer started, and its follow-up, settle. */
const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise(resolve => setTimeout(resolve, 0))
}

const refusal = () => new Error('no session')

beforeEach(() => reset({}))

describe('retryRegister', () => {
    it('schedules attempt 0 after 500ms and keeps the timer', () => {
        const { io, timers, registered } = retryPort()
        retryRegister(io, 0)
        expect(timers.map(t => t.ms)).toEqual([500])
        expect(mod.registerTimer).toBe(timers[0]!.handle)
        // Nothing is registered until the timer fires.
        expect(registered).toEqual([])
    })

    it('registers when the timer fires and schedules nothing more on success', async () => {
        const { io, timers, registered } = retryPort()
        retryRegister(io, 0)
        timers[0]!.run()
        expect(mod.registerTimer).toBeNull()
        await settle()
        expect(registered).toHaveLength(1)
        expect(mod.commandRegistered).toBe(true)
        expect(timers).toHaveLength(1)
        expect(mod.registerTimer).toBeNull()
    })

    it('on a refusal schedules attempt 1 at 1000ms', async () => {
        const { io, timers, registered } = retryPort([refusal()])
        retryRegister(io, 0)
        timers[0]!.run()
        await settle()
        expect(registered).toHaveLength(1)
        expect(mod.commandRegistered).toBe(false)
        expect(timers.map(t => t.ms)).toEqual([500, 1_000])
        expect(mod.registerTimer).toBe(timers[1]!.handle)
    })

    it('follows the full delay sequence while every attempt is refused, then stops', async () => {
        const { io, timers, registered } = retryPort(Array.from({ length: 10 }, refusal))
        retryRegister(io, 0)
        for (let fired = 0; fired < timers.length; fired++) {
            timers[fired]!.run()
            await settle()
        }
        expect(timers.map(t => t.ms)).toEqual([500, 1_000, 2_000, 4_000, 8_000, 16_000, 30_000])
        expect(registered).toHaveLength(7)
        expect(mod.commandRegistered).toBe(false)
        expect(mod.registerTimer).toBeNull()
    })

    it('stops the chain at the first success partway through', async () => {
        const { io, timers, registered } = retryPort([refusal(), refusal()])
        retryRegister(io, 0)
        for (let fired = 0; fired < timers.length; fired++) {
            timers[fired]!.run()
            await settle()
        }
        expect(timers.map(t => t.ms)).toEqual([500, 1_000, 2_000])
        expect(registered).toHaveLength(3)
        expect(mod.commandRegistered).toBe(true)
    })

    it('starts at the delay of a later attempt', () => {
        const { io, timers } = retryPort()
        retryRegister(io, 6)
        expect(timers.map(t => t.ms)).toEqual([30_000])
    })

    it.each([7, 8, 100])('schedules nothing past the last delay (attempt %i)', attempt => {
        const { io, timers } = retryPort()
        retryRegister(io, attempt)
        expect(timers).toEqual([])
        expect(mod.registerTimer).toBeNull()
    })

    it('schedules nothing once registered', () => {
        mod.commandRegistered = true
        const { io, timers } = retryPort()
        retryRegister(io, 0)
        expect(timers).toEqual([])
        expect(mod.registerTimer).toBeNull()
    })

    it('schedules nothing while the mod is disabled', () => {
        mod.disabled = true
        const { io, timers } = retryPort()
        retryRegister(io, 0)
        expect(timers).toEqual([])
        expect(mod.registerTimer).toBeNull()
    })

    it('schedules nothing for a generation other than the current one', () => {
        const { io, timers } = retryPort()
        retryRegister(io, 0, mod.registerGen - 1)
        retryRegister(io, 0, mod.registerGen + 1)
        expect(timers).toEqual([])
        expect(mod.registerTimer).toBeNull()
    })

    it('schedules for the current generation passed explicitly', () => {
        const { io, timers } = retryPort()
        retryRegister(io, 0, mod.registerGen)
        expect(timers.map(t => t.ms)).toEqual([500])
    })

    it('does not register when the generation changed between scheduling and firing', async () => {
        const { io, timers, registered } = retryPort()
        retryRegister(io, 0)
        reset({})
        timers[0]!.run()
        await settle()
        expect(registered).toEqual([])
        expect(mod.commandRegistered).toBe(false)
        expect(timers).toHaveLength(1)
        expect(mod.registerTimer).toBeNull()
    })

    it('stops a refused chain whose generation changed before its next timer fired', async () => {
        const { io, timers, registered } = retryPort([refusal(), refusal()])
        retryRegister(io, 0)
        timers[0]!.run()
        await settle()
        expect(timers).toHaveLength(2)
        reset({})
        timers[1]!.run()
        await settle()
        expect(registered).toHaveLength(1)
        expect(timers).toHaveLength(2)
    })

    it('swallows a throwing clock and leaves no timer', () => {
        const { io } = retryPort()
        ;(io.clock as { after: unknown }).after = () => {
            throw new Error('clock gone')
        }
        mod.registerTimer = { cancel: () => undefined } as typeof mod.registerTimer
        expect(() => retryRegister(io, 0)).not.toThrow()
        expect(mod.registerTimer).toBeNull()
    })
})
