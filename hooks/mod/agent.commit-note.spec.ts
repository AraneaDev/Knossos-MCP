import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard, SessionChanges } from '../../types'
import { commitNoteFor } from './agent'
import type { Port } from './port'
import { mod, reset, takeNoteSlot } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 5, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

type Cycle = { size: number; members: string[] }

function dashboard(over: { cycles?: { count: number; largest: Cycle[] }; status?: string } = {}): Dashboard {
    return {
        status: over.status ?? 'ok',
        path: ROOT,
        project_root: ROOT,
        project_id: 'p1',
        snapshot_id: 's1',
        fan_in: [],
        trend: [],
        cycles: over.cycles ?? { count: 0, largest: [] },
    } as unknown as Dashboard
}

function changes(over: Partial<SessionChanges> = {}): SessionChanges {
    return { turns: 1, files: {}, tests: {}, violations: [], truncated: false, ...over }
}

const file = (status: 'changed' | 'added' | 'deleted', tests?: number) => ({ status, dependents: 0, boundaries: [], boundary: null, ...(tests === undefined ? {} : { tests }) })

/** A port over plain values: each state cell held in a map, with the keys that were read recorded. */
function fakePort(initial: { dashboard?: Dashboard | null; changes?: SessionChanges; startCycles?: unknown } = {}) {
    const values = new Map<string, unknown>(Object.entries({ dashboard: dashboard(), changes: changes(), sessionScans: [], startCycles: null, ...initial }))
    const reads: string[] = []
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => {
                    reads.push(key)
                    return values.get(key)
                },
                update: async (change: (v: unknown) => unknown) => {
                    values.set(key, change(values.get(key)))
                    return values.get(key)
                },
            }),
        },
    )
    const io = { session: { root: async () => ROOT }, state } as unknown as Port
    return { io, reads }
}

const untestedOnly = () => changes({ files: { 'src/a.ts': file('changed', 0) } })

beforeEach(() => {
    reset(options)
})

describe('commitNoteFor: when it says nothing', () => {
    it('returns null with notes off, without reading the session changes', async () => {
        reset({ ...options, agentNotes: false })
        const { io, reads } = fakePort({ changes: untestedOnly() })
        expect(await commitNoteFor(io, 'main')).toBeNull()
        expect(reads).not.toContain('changes')
        expect(mod.commitNoted.size).toBe(0)
    })

    it('returns null when there is no dashboard', async () => {
        const { io, reads } = fakePort({ dashboard: null, changes: untestedOnly() })
        expect(await commitNoteFor(io, 'main')).toBeNull()
        expect(reads).not.toContain('changes')
    })

    it('returns null when the dashboard is in error', async () => {
        const { io } = fakePort({ dashboard: dashboard({ status: 'error' }), changes: untestedOnly() })
        expect(await commitNoteFor(io, 'main')).toBeNull()
    })

    it('returns null and takes no slot when nothing is untested, violated or newly cyclic', async () => {
        const { io } = fakePort({ changes: changes({ files: { 'src/a.ts': file('changed', 2) } }) })
        expect(await commitNoteFor(io, 'main')).toBeNull()
        expect(mod.commitNoted.size).toBe(0)
        expect(mod.turnNotes.get('main')).toBeUndefined()
    })
})

describe('commitNoteFor: the untested files', () => {
    it("names the session's own untested file and leaves out deleted, tested and outside changes", async () => {
        const { io } = fakePort({
            changes: changes({
                files: {
                    'src/own.ts': file('changed', 0),
                    'src/gone.ts': file('deleted', 0),
                    'src/tested.ts': file('added', 3),
                    'src/theirs.ts': file('changed', 0),
                    'src/unknown.ts': file('changed'),
                },
                origins: { 'src/own.ts': 'session', 'src/gone.ts': 'session', 'src/tested.ts': 'session', 'src/theirs.ts': 'outside', 'src/unknown.ts': 'session' },
            }),
        })
        const note = await commitNoteFor(io, 'main')
        expect(note).toContain('1 changed file no test reaches (src/own.ts)')
        expect(note).not.toContain('gone.ts')
        expect(note).not.toContain('tested.ts')
        expect(note).not.toContain('theirs.ts')
        // A file whose test count is unknown is not called untested.
        expect(note).not.toContain('unknown.ts')
    })

    it('counts every file as its own when the changes carry no origins', async () => {
        const { io } = fakePort({ changes: changes({ files: { 'src/b.ts': file('added', 0) } }) })
        expect(await commitNoteFor(io, 'main')).toContain('(src/b.ts)')
    })

    it('names the untested paths sorted', async () => {
        const { io } = fakePort({ changes: changes({ files: { 'src/z.ts': file('changed', 0), 'lib/m.ts': file('changed', 0), 'src/a.ts': file('added', 0) } }) })
        expect(await commitNoteFor(io, 'main')).toContain('3 changed files no test reaches (lib/m.ts, src/a.ts, src/z.ts)')
    })
})

describe('commitNoteFor: new cycles', () => {
    const start = { count: 0, keys: [], complete: true, exact: true }

    it('shows the first four members of a longer cycle and an ellipsis', async () => {
        const long = { size: 5, members: ['a', 'b', 'c', 'd', 'e'] }
        const { io } = fakePort({ startCycles: start, dashboard: dashboard({ cycles: { count: 1, largest: [long] } }) })
        expect(await commitNoteFor(io, 'main')).toContain('1 dependency cycle new since the session began (a → b → c → d → …)')
    })

    it('shows a cycle of four members whole, with no ellipsis', async () => {
        const four = { size: 4, members: ['a', 'b', 'c', 'd'] }
        const { io } = fakePort({ startCycles: start, dashboard: dashboard({ cycles: { count: 1, largest: [four] } }) })
        const note = await commitNoteFor(io, 'main')
        expect(note).toContain('(a → b → c → d)')
        expect(note).not.toContain('…')
    })

    it('says nothing of a cycle the session began with', async () => {
        const old = { size: 2, members: ['b', 'a'] }
        const { io } = fakePort({ startCycles: { ...start, count: 1, keys: ['a\u0000b'] }, dashboard: dashboard({ cycles: { count: 1, largest: [old] } }) })
        expect(await commitNoteFor(io, 'main')).toBeNull()
    })
})

describe('commitNoteFor: violations', () => {
    const violated = () => changes({ violations: ['src/ui/x.ts → src/core/y.ts'] })

    it('names the violations the session introduced when policies are enforced', async () => {
        const { io } = fakePort({ changes: violated() })
        expect(await commitNoteFor(io, 'main')).toContain('1 boundary-policy violation this session introduced (src/ui/x.ts → src/core/y.ts)')
    })

    it('leaves the violations out when policies are not enforced', async () => {
        reset({ ...options, enforcePolicies: false })
        const { io } = fakePort({ changes: violated() })
        expect(await commitNoteFor(io, 'main')).toBeNull()
    })

    it('still names untested files when policies are not enforced', async () => {
        reset({ ...options, enforcePolicies: false })
        const { io } = fakePort({ changes: changes({ violations: ['a → b'], files: { 'src/a.ts': file('changed', 0) } }) })
        const note = await commitNoteFor(io, 'main')
        expect(note).toContain('(src/a.ts)')
        expect(note).not.toContain('boundary-policy')
    })
})

describe('commitNoteFor: once per loop and note, within the turn budget', () => {
    it('records the note it returns, keyed by loop', async () => {
        const { io } = fakePort({ changes: untestedOnly() })
        const note = await commitNoteFor(io, 'main')
        expect(note).not.toBeNull()
        expect([...mod.commitNoted]).toEqual([`main\u0000${note}`])
        expect(mod.turnNotes.get('main')).toBe(1)
    })

    it('says the same note only once in the same loop', async () => {
        const { io } = fakePort({ changes: untestedOnly() })
        expect(await commitNoteFor(io, 'main')).not.toBeNull()
        expect(await commitNoteFor(io, 'main')).toBeNull()
        expect(mod.commitNoted.size).toBe(1)
        expect(mod.turnNotes.get('main')).toBe(1)
    })

    it('says the same note again in a different loop', async () => {
        const { io } = fakePort({ changes: untestedOnly() })
        const first = await commitNoteFor(io, 'main')
        const second = await commitNoteFor(io, 'agent-7')
        expect(second).toBe(first)
        expect(mod.commitNoted.size).toBe(2)
    })

    it('returns null and records nothing when the turn has no note slot left', async () => {
        const { io } = fakePort({ changes: untestedOnly() })
        while (takeNoteSlot('main'));
        expect(await commitNoteFor(io, 'main')).toBeNull()
        expect(mod.commitNoted.size).toBe(0)
        // Another loop has its own budget.
        expect(await commitNoteFor(io, 'other')).not.toBeNull()
    })
})
