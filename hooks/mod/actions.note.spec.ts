import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Inspected, KnossosView, NoteState } from '../../types'
import { handlersOf } from './actions'
import { request, requestDetail } from './loaders'
import type { Port } from './port'
import { fieldKey, mod, PANE, reset } from './state'

// The loaders read the graph through their own timers and the wrapper: here only whether the detail is asked for again matters.
vi.mock('./loaders', async importOriginal => ({
    ...(await importOriginal<typeof import('./loaders')>()),
    request: vi.fn(async () => undefined),
    requestDetail: vi.fn(async () => undefined),
}))

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }
const GREETER: Inspected = { name: 'App\\Greeter', label: 'Greeter' }
const VIEW: KnossosView = { inspect: GREETER, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: true, filter: '', filtering: false, sort: 'in' }
const DETAIL = {
    detail: {
        component: {
            name: 'App\\Model\\Greeter',
            annotations: [
                { kind: 'owner', value: 'team-a' },
                { kind: 'note', value: 'keep it small' },
            ],
        },
    },
}

/**
 * A port over plain values: cells in a map, a clock whose timers run only
 * when the test says, focus calls recorded, and a wrapper that records every
 * argv and answers with the next queued stdout ('' once the queue is empty).
 */
function fakePort(initial: Record<string, unknown> = {}) {
    const values = new Map<string, unknown>(Object.entries({ view: VIEW, detail: null, note: null, dashboard: { project_root: ROOT }, feedback: null, live: null, ...initial }))
    const timers: (() => void)[] = []
    const runs: string[][] = []
    const answers: string[] = []
    const focused: unknown[] = []
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
        clock: { now: async () => 1_000, after: (_ms: number, run: () => void) => (timers.push(run), { cancel: () => undefined }) },
        ui: { focus: async (args: unknown) => (focused.push(args), {}), log: () => undefined, invalidate: () => undefined },
        session: { root: async () => '/session/root' },
        plugin: { root: '/plugin' },
        process: {
            run: async (argv: string[]) => {
                runs.push(argv)
                return { exitCode: 0, stdout: answers.shift() ?? '', stderr: '' }
            },
        },
        state,
    } as unknown as Port
    const handlers = handlersOf(io)
    /** Lets the handlers' fire-and-forget chains finish. */
    const settle = async (): Promise<void> => {
        for (let i = 0; i < 5; i++) await new Promise(resolve => setTimeout(resolve, 0))
    }
    /** Runs every pending timer once, then lets what they started finish. */
    const runTimers = async (): Promise<void> => {
        for (const run of timers.splice(0)) run()
        await settle()
    }
    const press = async (id: string): Promise<void> => {
        handlers.press(id)
        await settle()
    }
    const type = async (field: string, text: string): Promise<void> => {
        handlers.type(field, text)
        await settle()
    }
    const submit = async (field: string, text: string): Promise<void> => {
        handlers.submit(field, text)
        await settle()
    }
    const note = () => values.get('note') as NoteState | null
    return { io, values, timers, runs, answers, focused, press, type, submit, runTimers, note }
}

const editing = (over: Partial<NoteState> = {}): NoteState => ({ component: 'App\\Greeter', phase: 'editing', value: '', previous: null, reason: null, ...over })
const confirming = (over: Partial<NoteState> = {}): NoteState => ({ component: 'App\\Greeter', phase: 'confirming', value: 'be kind', previous: null, reason: null, ...over })
const answer = (body: Record<string, unknown>): string => JSON.stringify(body)

beforeEach(() => {
    reset(options)
    vi.mocked(requestDetail).mockClear()
    vi.mocked(request).mockClear()
})

afterEach(() => {
    mod.disabled = false
})

describe('opening a note', () => {
    it.each([
        ['nothing is inspected', null],
        ['a file is inspected', { name: 'src/Greeter.php', label: 'src/Greeter.php', file: true }],
    ])('does nothing when %s', async (_, inspect) => {
        const port = fakePort({ view: { ...VIEW, inspect }, detail: DETAIL })
        await port.press('note')
        expect(port.note()).toBeNull()
        await port.runTimers()
        expect(port.focused).toEqual([])
    })

    it("holds the stored detail's component name and its existing note, and asks for the field's focus", async () => {
        const port = fakePort({ detail: DETAIL })
        await port.press('note')
        expect(port.note()).toEqual({ component: 'App\\Model\\Greeter', phase: 'editing', value: 'keep it small', previous: null, reason: null })
        await port.runTimers()
        expect(port.focused).toEqual([{ requestId: PANE, key: fieldKey('note') }])
    })

    it('falls back to the inspected name and an empty value without a stored detail', async () => {
        const port = fakePort()
        await port.press('note')
        expect(port.note()).toEqual(editing())
    })

    it('starts empty when the stored component carries no note among its annotations', async () => {
        const port = fakePort({ detail: { detail: { component: { name: 'App\\Greeter', annotations: [{ kind: 'owner', value: 'team-a' }] } } } })
        await port.press('note')
        expect(port.note()?.value).toBe('')
    })
})

describe('typing a note', () => {
    it('keeps what is typed while editing', async () => {
        const port = fakePort({ note: editing({ value: 'a' }) })
        await port.type('note', 'ab')
        expect(port.note()).toEqual(editing({ value: 'ab' }))
    })

    it('leaves no note, or one past editing, as it is', async () => {
        const none = fakePort()
        await none.type('note', 'x')
        expect(none.note()).toBeNull()

        for (const phase of ['previewing', 'confirming', 'saving', 'failed'] as const) {
            const held = editing({ phase, value: 'kept' })
            const port = fakePort({ note: held })
            await port.type('note', 'changed')
            expect(port.note(), phase).toEqual(held)
        }
    })
})

describe('submitting a note', () => {
    it('drops the note when only blanks were typed, and asks nothing', async () => {
        const port = fakePort({ note: editing({ value: 'old' }) })
        await port.submit('note', '   ')
        expect(port.note()).toBeNull()
        await port.runTimers()
        expect(port.runs).toEqual([])
    })

    it('does nothing outside editing', async () => {
        const held = confirming()
        const port = fakePort({ note: held })
        await port.submit('note', 'other')
        expect(port.note()).toEqual(held)
        expect(port.timers).toEqual([])
    })

    it('previews the trimmed note on a timer, in the dashboard project root, without --execute', async () => {
        const port = fakePort({ note: editing() })
        await port.submit('note', '  be kind  ')
        expect(port.note()).toEqual(editing({ phase: 'previewing', value: 'be kind' }))
        // Not inside the keystroke: the preview waits for its timer.
        expect(port.runs).toEqual([])
        await port.runTimers()
        expect(port.runs).toHaveLength(1)
        const argv = port.runs[0]!
        expect(argv.slice(2)).toEqual(['annotate', ROOT, '--component=App\\Greeter', '--value=be kind'])
        expect(argv).not.toContain('--execute')
    })

    it("previews in the session's root when no dashboard names one", async () => {
        const port = fakePort({ note: editing(), dashboard: null })
        await port.submit('note', 'x')
        await port.runTimers()
        expect(port.runs[0]!.slice(2, 4)).toEqual(['annotate', '/session/root'])
    })
})

describe("the note's preview", () => {
    /** A port whose note was submitted as `value` and whose preview answers `stdout`, the preview run. */
    async function previewed(stdout: string, value = 'be kind') {
        const port = fakePort({ note: editing() })
        port.answers.push(stdout)
        await port.submit('note', value)
        await port.runTimers()
        return port
    }

    it('asks for a yes, holding what it would replace', async () => {
        const port = await previewed(answer({ status: 'ok', component: 'App\\Greeter', executed: false, previous: 'old' }))
        expect(port.note()).toEqual(confirming({ previous: 'old' }))
    })

    it('asks for a yes with nothing replaced when the component had no note', async () => {
        const port = await previewed(answer({ status: 'ok', component: 'App\\Greeter', executed: false }))
        expect(port.note()).toEqual(confirming({ previous: null }))
    })

    it('fails with the reason knossos refused it for', async () => {
        const port = await previewed(answer({ status: 'refused', reason: 'too long' }))
        expect(port.note()).toEqual(editing({ phase: 'failed', value: 'be kind', reason: 'too long' }))
    })

    it("fails with 'it refused' when the refusal gives no reason", async () => {
        const port = await previewed(answer({ status: 'refused' }))
        expect(port.note()?.phase).toBe('failed')
        expect(port.note()?.reason).toBe('it refused')
    })

    it.each([
        ['garbage', 'not json at all'],
        ['silence', ''],
        ['an error', answer({ status: 'error' })],
        ['an ok that already wrote', answer({ status: 'ok', component: 'App\\Greeter', executed: true })],
    ])("fails with 'it said nothing' on %s", async (_, stdout) => {
        const port = await previewed(stdout)
        expect(port.note()?.phase).toBe('failed')
        expect(port.note()?.reason).toBe('it said nothing')
    })

    it('leaves a note edited after the preview was asked alone', async () => {
        const port = fakePort({ note: editing() })
        port.answers.push(answer({ status: 'ok', component: 'App\\Greeter', executed: false, previous: 'old' }))
        await port.submit('note', 'first')
        // The person drops it and starts over before the preview answers.
        await port.press('note-no')
        await port.press('note')
        await port.type('note', 'second')
        await port.runTimers()
        expect(port.note()).toEqual(editing({ value: 'second' }))
    })

    it('leaves a dropped note dropped', async () => {
        const port = fakePort({ note: editing() })
        port.answers.push(answer({ status: 'ok', component: 'App\\Greeter', executed: false }))
        await port.submit('note', 'first')
        await port.press('note-no')
        await port.runTimers()
        expect(port.note()).toBeNull()
    })

    it('turns the mod off when the wrapper finds no binary', async () => {
        const port = await previewed(answer({ status: 'no-binary' }))
        expect(mod.disabled).toBe(true)
        // What becomes of the note then is not part of the contract: only the mod turning off is.
        expect(port.note()?.phase).not.toBe('confirming')
    })
})

describe("the note's yes", () => {
    it('does nothing outside the question: no timer, no wrapper run', async () => {
        for (const held of [null, editing({ value: 'x' }), editing({ phase: 'previewing', value: 'x' }), editing({ phase: 'failed', value: 'x', reason: 'r' })]) {
            const port = fakePort({ note: held })
            await port.press('note-yes')
            expect(port.note()).toEqual(held)
            expect(port.timers).toEqual([])
            expect(port.runs).toEqual([])
        }
    })

    it('records the note with --execute on a timer, saving meanwhile', async () => {
        const port = fakePort({ note: confirming() })
        await port.press('note-yes')
        expect(port.note()).toEqual(confirming({ phase: 'saving' }))
        expect(port.runs).toEqual([])
        await port.runTimers()
        expect(port.runs[0]!.slice(2)).toEqual(['annotate', ROOT, '--component=App\\Greeter', '--value=be kind', '--execute'])
    })

    it('on success clears the note, says so by the short name, and reads the detail again', async () => {
        const port = fakePort({ note: confirming(), detail: DETAIL })
        port.answers.push(answer({ status: 'ok', component: 'App\\Greeter', executed: true }))
        await port.press('note-yes')
        await port.runTimers()
        expect(port.note()).toBeNull()
        expect(port.values.get('feedback')).toMatchObject({ text: '✓ noted on Greeter', tone: 'ok' })
        expect(port.values.get('detail')).toBeNull()
        expect(requestDetail).toHaveBeenCalledTimes(1)
        expect(vi.mocked(requestDetail).mock.calls[0]![1]).toEqual(GREETER)
    })

    it('on success with nothing inspected any more, keeps the stored detail and asks for none', async () => {
        const port = fakePort({ note: confirming(), detail: DETAIL, view: { ...VIEW, inspect: null } })
        port.answers.push(answer({ status: 'ok', component: 'App\\Greeter', executed: true }))
        await port.press('note-yes')
        await port.runTimers()
        expect(port.note()).toBeNull()
        expect(port.values.get('feedback')).toMatchObject({ text: '✓ noted on Greeter' })
        expect(port.values.get('detail')).toEqual(DETAIL)
        expect(requestDetail).not.toHaveBeenCalled()
    })

    it.each([
        ['a refusal with its reason', answer({ status: 'refused', reason: 'locked' }), 'locked'],
        ['a refusal without one', answer({ status: 'refused' }), 'it refused'],
        ['an ok that wrote nothing', answer({ status: 'ok', component: 'App\\Greeter', executed: false }), 'it said nothing'],
        ['silence', '', 'it said nothing'],
    ])('fails on %s, saying nothing and reading no detail', async (_, stdout, reason) => {
        const port = fakePort({ note: confirming() })
        port.answers.push(stdout)
        await port.press('note-yes')
        await port.runTimers()
        expect(port.note()).toEqual(confirming({ phase: 'failed', reason }))
        expect(port.values.get('feedback')).toBeNull()
        expect(requestDetail).not.toHaveBeenCalled()
    })

    it("leaves another component's note alone when the record fails", async () => {
        const port = fakePort({ note: confirming() })
        port.answers.push(answer({ status: 'refused', reason: 'locked' }))
        await port.press('note-yes')
        // Meanwhile the person opened a note on another component.
        const other = editing({ component: 'App\\Other', value: 'mine' })
        port.values.set('note', other)
        await port.runTimers()
        expect(port.note()).toEqual(other)
    })

    it('turns the mod off when the wrapper finds no binary', async () => {
        const port = fakePort({ note: confirming() })
        port.answers.push(answer({ status: 'no-binary' }))
        await port.press('note-yes')
        await port.runTimers()
        expect(mod.disabled).toBe(true)
        // Nothing was recorded, so there is no success to report nor a detail to read again.
        expect(port.note()).not.toBeNull()
        expect(port.values.get('feedback')).toBeNull()
        expect(requestDetail).not.toHaveBeenCalled()
    })
})

describe("the note's no", () => {
    it.each([editing({ value: 'x' }), confirming(), editing({ phase: 'failed', value: 'x', reason: 'r' })])('clears a note in %o', async held => {
        const port = fakePort({ note: held })
        await port.press('note-no')
        expect(port.note()).toBeNull()
        expect(port.runs).toEqual([])
    })
})
