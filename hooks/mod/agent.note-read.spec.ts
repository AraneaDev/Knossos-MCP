import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { noteRead } from './agent'
import type { Port } from './port'
import { mod, reset } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 5, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

/** One policed, declared boundary: `core` holds everything under src/core/ and may not depend on `ui`. */
const POLICY = {
    rules: [{ id: 'core-alone', from: 'core', deny: ['ui'], allow: [], edge_kinds: [] }],
    boundaries: { core: { path_prefixes: ['src/core/'], listed: false } },
    files: {},
    files_truncated: false,
}

const HUB_NOTE = 'knossos: src/core/hub.ts (core) has 7 dependent files. Policy: core may not depend on ui.'
const OTHER_NOTE = 'knossos: src/core/other.ts is in core. Policy: core may not depend on ui.'

function dashboard(over: Record<string, unknown> = {}): Dashboard {
    return {
        status: 'ok',
        path: ROOT,
        project_root: ROOT,
        project_id: 'p1',
        snapshot_id: 's1',
        fan_in: [
            { path: 'src/core/hub.ts', dependent_files: 7, boundaries: ['core'], boundary: 'core' },
            { path: 'src/leaf.ts', dependent_files: 2, boundaries: [], boundary: null },
            { path: 'src/a.ts', dependent_files: 6, boundaries: [], boundary: null },
            { path: 'src/b.ts', dependent_files: 6, boundaries: [], boundary: null },
            { path: 'src/c.ts', dependent_files: 6, boundaries: [], boundary: null },
            { path: 'src/d.ts', dependent_files: 6, boundaries: [], boundary: null },
        ],
        policy: POLICY,
        boundaries: { declared: ['core'], items: [] },
        ...over,
    } as unknown as Dashboard
}

/** A port whose dashboard cell holds `d` and whose file system reports every path as its own real path. */
function fakePort(d: Dashboard | null = dashboard()): Port {
    return {
        session: { root: async () => ROOT },
        fs: { stat: async (path: string) => ({ realPath: path }) },
        state: { dashboard: { read: async () => d } },
    } as unknown as Port
}

beforeEach(() => {
    reset(options)
})

describe('noteRead: when there is nothing to say', () => {
    it('says nothing when notes are off, even for a hub', async () => {
        reset({ ...options, agentNotes: false })
        expect(await noteRead(fakePort(), `${ROOT}/src/core/hub.ts`, 'main')).toBeNull()
        expect(mod.noted.size).toBe(0)
        expect(mod.turnNotes.size).toBe(0)
    })

    it('says nothing without a dashboard', async () => {
        expect(await noteRead(fakePort(null), `${ROOT}/src/core/hub.ts`, 'main')).toBeNull()
        expect(mod.noted.size).toBe(0)
    })

    it('says nothing when the dashboard is not ok', async () => {
        expect(await noteRead(fakePort(dashboard({ status: 'error' })), `${ROOT}/src/core/hub.ts`, 'main')).toBeNull()
        expect(mod.noted.size).toBe(0)
    })

    it('says nothing when the dashboard has no project root', async () => {
        expect(await noteRead(fakePort(dashboard({ project_root: null })), `${ROOT}/src/core/hub.ts`, 'main')).toBeNull()
        expect(mod.noted.size).toBe(0)
    })

    it('says nothing for a file outside the project root', async () => {
        expect(await noteRead(fakePort(), '/elsewhere/src/core/hub.ts', 'main')).toBeNull()
        expect(mod.noted.size).toBe(0)
        expect(mod.turnNotes.size).toBe(0)
    })

    it('says nothing for a quiet file and does not mark it noted or spend a slot', async () => {
        expect(await noteRead(fakePort(), `${ROOT}/src/leaf.ts`, 'main')).toBeNull()
        expect(mod.noted.size).toBe(0)
        expect(mod.ruled.size).toBe(0)
        expect(mod.turnNotes.get('main')).toBeUndefined()
    })
})

describe('noteRead: a file worth a note', () => {
    it('returns the note for a hub and records the file and the boundary it ruled for this loop', async () => {
        expect(await noteRead(fakePort(), `${ROOT}/src/core/hub.ts`, 'main')).toBe(HUB_NOTE)
        expect([...mod.noted]).toEqual(['main\u0000src/core/hub.ts'])
        expect([...mod.ruled]).toEqual(['main\u0000core'])
        expect(mod.turnNotes.get('main')).toBe(1)
    })

    it('says nothing when the same file is read again in the same loop', async () => {
        const io = fakePort()
        expect(await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')).toBe(HUB_NOTE)
        expect(await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')).toBeNull()
        expect(mod.turnNotes.get('main')).toBe(1)
    })

    it('says it again for the same file read in another loop', async () => {
        const io = fakePort()
        expect(await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')).toBe(HUB_NOTE)
        expect(await noteRead(io, `${ROOT}/src/core/hub.ts`, 'agent-1')).toBe(HUB_NOTE)
        expect(mod.noted).toEqual(new Set(['main\u0000src/core/hub.ts', 'agent-1\u0000src/core/hub.ts']))
        expect(mod.ruled).toEqual(new Set(['main\u0000core', 'agent-1\u0000core']))
    })
})

describe('noteRead: rules already stated', () => {
    it('leaves out the rules this loop already stated, so a non-hub in that boundary is quiet and stays unmarked', async () => {
        const io = fakePort()
        await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')
        expect(await noteRead(io, `${ROOT}/src/core/other.ts`, 'main')).toBeNull()
        expect(mod.noted.has('main\u0000src/core/other.ts')).toBe(false)
    })

    it('does not treat boundaries ruled in another loop as stated for this one', async () => {
        const io = fakePort()
        await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')
        expect(await noteRead(io, `${ROOT}/src/core/other.ts`, 'agent-1')).toBe(OTHER_NOTE)
        expect(mod.ruled.has('agent-1\u0000core')).toBe(true)
    })

    it('drops the policy from a hub note once this loop stated it', async () => {
        const io = fakePort()
        expect(await noteRead(io, `${ROOT}/src/core/other.ts`, 'main')).toBe(OTHER_NOTE)
        expect(await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')).toBe('knossos: src/core/hub.ts (core) has 7 dependent files.')
    })
})

describe('noteRead: the turn note slots', () => {
    it('says nothing once the turn has spent its slots, and leaves the file to a later Read', async () => {
        const io = fakePort()
        expect(await noteRead(io, `${ROOT}/src/a.ts`, 'main')).toBe('knossos: src/a.ts has 6 dependent files.')
        expect(await noteRead(io, `${ROOT}/src/b.ts`, 'main')).toBe('knossos: src/b.ts has 6 dependent files.')
        expect(await noteRead(io, `${ROOT}/src/c.ts`, 'main')).toBe('knossos: src/c.ts has 6 dependent files.')
        expect(await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')).toBeNull()
        expect(mod.noted.has('main\u0000src/core/hub.ts')).toBe(false)
        expect(mod.ruled.has('main\u0000core')).toBe(false)

        // A new turn frees the slots; the file held back is said then, rules included.
        mod.turnNotes = new Map()
        expect(await noteRead(io, `${ROOT}/src/core/hub.ts`, 'main')).toBe(HUB_NOTE)
    })

    it('counts slots per loop: one loop spending its slots leaves another its own', async () => {
        const io = fakePort()
        for (const f of ['a', 'b', 'c']) await noteRead(io, `${ROOT}/src/${f}.ts`, 'main')
        expect(await noteRead(io, `${ROOT}/src/d.ts`, 'main')).toBeNull()
        expect(await noteRead(io, `${ROOT}/src/d.ts`, 'agent-1')).toBe('knossos: src/d.ts has 6 dependent files.')
    })
})
