import { beforeEach, describe, expect, it } from 'vitest'
import type { Port } from './port'
import { registerCommand } from './session'
import { mod, reset } from './state'

/**
 * A port with only what registering touches: the engine's command register,
 * answered by `answers` in turn (a value to throw, or undefined to accept),
 * and the log. Every register and log call is recorded, and so is any reach
 * for a tool register, which the mod must never make.
 */
function port(answers: unknown[] = []) {
    const registered: unknown[] = []
    const logged: unknown[][] = []
    const toolCalls: unknown[] = []
    const io = {
        command: {
            register: async (args: unknown) => {
                registered.push(args)
                const answer = answers.shift()
                if (answer !== undefined) throw answer
                return {}
            },
        },
        ui: { log: (...args: unknown[]) => void logged.push(args) },
        tool: { register: async (args: unknown) => void toolCalls.push(args) },
        tools: { register: async (args: unknown) => void toolCalls.push(args) },
    } as unknown as Port
    return { io, registered, logged, toolCalls }
}

describe('registering /knossos', () => {
    beforeEach(() => reset({}))

    it('registers the command by its name once, and says so', async () => {
        const { io, registered, logged } = port()
        expect(await registerCommand(io)).toBe(true)
        expect(mod.commandRegistered).toBe(true)
        expect(registered).toHaveLength(1)
        expect(registered[0]).toMatchObject({ name: 'knossos', description: expect.stringContaining('/knossos inspect') })
        expect(logged).toEqual([])
    })

    it('does not ask the engine again once registered', async () => {
        mod.commandRegistered = true
        const { io, registered } = port()
        expect(await registerCommand(io)).toBe(true)
        expect(registered).toEqual([])
    })

    it('a second call after a success does not register twice', async () => {
        const { io, registered } = port()
        await registerCommand(io)
        expect(await registerCommand(io)).toBe(true)
        expect(registered).toHaveLength(1)
    })

    it('answers false on a refusal and leaves one debug line naming the error', async () => {
        const { io, logged } = port([new Error('no session')])
        expect(await registerCommand(io)).toBe(false)
        expect(mod.commandRegistered).toBe(false)
        expect(logged).toHaveLength(1)
        expect(logged[0]![0]).toContain('no session')
        expect(logged[0]![1]).toEqual({ to: 'debug' })
    })

    it('names a refusal that is not an Error by its string form', async () => {
        const { io, logged } = port(['engine busy'])
        expect(await registerCommand(io)).toBe(false)
        expect(logged).toHaveLength(1)
        expect(logged[0]![0]).toContain('engine busy')
    })

    it('logs only the first of several refusals', async () => {
        const { io, logged, registered } = port([new Error('no session'), new Error('still none')])
        expect(await registerCommand(io)).toBe(false)
        expect(await registerCommand(io)).toBe(false)
        expect(registered).toHaveLength(2)
        expect(logged).toHaveLength(1)
        expect(logged[0]![0]).toContain('no session')
        expect(mod.registerFailureLogged).toBe(true)
    })

    it('registers on a later call after a refusal', async () => {
        const { io, registered } = port([new Error('no session')])
        expect(await registerCommand(io)).toBe(false)
        expect(await registerCommand(io)).toBe(true)
        expect(mod.commandRegistered).toBe(true)
        expect(registered).toHaveLength(2)
    })

    it('registers no tool for the model, whether the engine accepts or refuses', async () => {
        const accepted = port()
        await registerCommand(accepted.io)
        expect(accepted.toolCalls).toEqual([])
        reset({})
        const refused = port([new Error('no session')])
        await registerCommand(refused.io)
        expect(refused.toolCalls).toEqual([])
    })
})
