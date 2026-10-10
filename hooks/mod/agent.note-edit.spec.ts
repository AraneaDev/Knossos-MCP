import { beforeEach, describe, expect, it } from 'vitest'
import type { Dashboard } from '../../types'
import { editNote } from '../lib/notes'
import { noteEdit } from './agent'
import type { Port } from './port'
import { mod, reset } from './state'

const ROOT = '/work/app'
const SESSION = '/work/app'
const options = { fanInThreshold: 5, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

const hub = { path: 'src/hub.ts', dependent_files: 7, boundaries: ['core', 'ui'] }
const quiet = { path: 'src/leaf.ts', dependent_files: 4, boundaries: ['core'] }
const edge = { path: 'src/edge.ts', dependent_files: 5, boundaries: ['core'] }

function dashboard(root: string | null): Dashboard {
    return { status: 'ok', path: ROOT, project_root: root, project_id: 'p1', snapshot_id: 's1', fan_in: [hub, quiet, edge] } as unknown as Dashboard
}

/**
 * A port over plain values: cells held in a map, and a file system whose
 * `stat` follows the links in `links` (a path prefix to its real target)
 * and otherwise reports every path as its own real path.
 */
function fakePort(initial: { dashboard?: Dashboard | null; sessionEdits?: string[] } = {}, links: Record<string, string> = {}) {
    const values = new Map<string, unknown>(Object.entries({ dashboard: null, sessionEdits: [], ...initial }))
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
        session: { root: async () => SESSION },
        fs: {
            stat: async (path: string) => {
                for (const [link, target] of Object.entries(links)) {
                    if (path === link || path.startsWith(`${link}/`)) return { realPath: target + path.slice(link.length) }
                }
                return { realPath: path }
            },
        },
        state,
    } as unknown as Port
    return { io, values }
}

beforeEach(() => {
    reset(options)
})

describe('noteEdit without a dashboard root', () => {
    it('records an edit under the session root by its absolute path and says nothing', async () => {
        const { io, values } = fakePort()
        const note = await noteEdit(io, `${SESSION}/src/a.ts`, 'base-1')
        expect(note).toBeNull()
        expect(mod.dirty).toBe(true)
        expect([...mod.edited]).toEqual([`${SESSION}/src/a.ts`])
        expect(mod.turnBase).toBe('base-1')
        expect(values.get('sessionEdits')).toEqual([`${SESSION}/src/a.ts`])
    })

    it('also treats a dashboard whose project root is null as having no root', async () => {
        const { io, values } = fakePort({ dashboard: dashboard(null) })
        expect(await noteEdit(io, `${SESSION}/src/hub.ts`, null)).toBeNull()
        expect([...mod.edited]).toEqual([`${SESSION}/src/hub.ts`])
        expect(values.get('sessionEdits')).toEqual([`${SESSION}/src/hub.ts`])
    })

    it('ignores an edit outside the session root', async () => {
        const { io, values } = fakePort()
        const note = await noteEdit(io, '/elsewhere/b.ts', 'base-1')
        expect(note).toBeNull()
        expect(mod.dirty).toBe(false)
        expect(mod.edited.size).toBe(0)
        expect(mod.turnBase).toBeNull()
        expect(values.get('sessionEdits')).toEqual([])
    })

    it('ignores a sibling directory that only shares the session root as a prefix', async () => {
        const { io } = fakePort()
        expect(await noteEdit(io, `${SESSION}-other/c.ts`, null)).toBeNull()
        expect(mod.edited.size).toBe(0)
        expect(mod.dirty).toBe(false)
    })
})

describe('noteEdit with a dashboard root', () => {
    it('changes nothing for an edit outside the project root', async () => {
        const { io, values } = fakePort({ dashboard: dashboard(ROOT), sessionEdits: ['src/old.ts'] })
        const note = await noteEdit(io, '/other/repo/src/hub.ts', 'base-1')
        expect(note).toBeNull()
        expect(mod.dirty).toBe(false)
        expect(mod.edited.size).toBe(0)
        expect(mod.turnBase).toBeNull()
        expect(values.get('sessionEdits')).toEqual(['src/old.ts'])
    })

    it('records a file the fan-in list does not name by its relative path, with no note', async () => {
        const { io, values } = fakePort({ dashboard: dashboard(ROOT) })
        const note = await noteEdit(io, `${ROOT}/src/unlisted.ts`, 'base-1')
        expect(note).toBeNull()
        expect(mod.dirty).toBe(true)
        expect([...mod.edited]).toEqual(['src/unlisted.ts'])
        expect(values.get('sessionEdits')).toEqual(['src/unlisted.ts'])
    })

    it('says nothing when the file has fewer dependents than the threshold, but still records it', async () => {
        const { io, values } = fakePort({ dashboard: dashboard(ROOT) })
        expect(await noteEdit(io, `${ROOT}/src/leaf.ts`, null)).toBeNull()
        expect([...mod.edited]).toEqual(['src/leaf.ts'])
        expect(values.get('sessionEdits')).toEqual(['src/leaf.ts'])
    })

    it('returns the edit note when the dependents reach the threshold exactly', async () => {
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        const note = await noteEdit(io, `${ROOT}/src/edge.ts`, null)
        expect(note).toEqual({ text: editNote(edge), path: 'src/edge.ts' })
    })

    it('returns the edit note when the dependents exceed the threshold', async () => {
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        const note = await noteEdit(io, `${ROOT}/src/hub.ts`, null)
        expect(note).toEqual({ text: editNote(hub), path: 'src/hub.ts' })
        expect(mod.dirty).toBe(true)
    })

    it('follows the configured threshold: a higher one silences the same file', async () => {
        reset({ ...options, fanInThreshold: 8 })
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        expect(await noteEdit(io, `${ROOT}/src/hub.ts`, null)).toBeNull()
        expect([...mod.edited]).toEqual(['src/hub.ts'])
    })

    it('resolves a linked path to its real path before relativising it', async () => {
        const { io, values } = fakePort({ dashboard: dashboard(ROOT) }, { '/home/me/link': ROOT })
        const note = await noteEdit(io, '/home/me/link/src/hub.ts', null)
        expect(note).toEqual({ text: editNote(hub), path: 'src/hub.ts' })
        expect([...mod.edited]).toEqual(['src/hub.ts'])
        expect(values.get('sessionEdits')).toEqual(['src/hub.ts'])
    })

    it('treats a link that points out of the project as outside it', async () => {
        const { io, values } = fakePort({ dashboard: dashboard(ROOT) }, { [`${ROOT}/vendored`]: '/opt/shared' })
        expect(await noteEdit(io, `${ROOT}/vendored/lib.ts`, null)).toBeNull()
        expect(mod.edited.size).toBe(0)
        expect(values.get('sessionEdits')).toEqual([])
    })

    it('keeps a repeated edit of the same file once in the session edits', async () => {
        const { io, values } = fakePort({ dashboard: dashboard(ROOT) })
        await noteEdit(io, `${ROOT}/src/leaf.ts`, null)
        await noteEdit(io, `${ROOT}/src/leaf.ts`, null)
        await noteEdit(io, `${ROOT}/src/unlisted.ts`, null)
        expect(values.get('sessionEdits')).toEqual(['src/leaf.ts', 'src/unlisted.ts'])
        expect([...mod.edited]).toEqual(['src/leaf.ts', 'src/unlisted.ts'])
    })
})

describe('noteEdit and the turn base', () => {
    it('sets the turn base from the first edit of the turn', async () => {
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        await noteEdit(io, `${ROOT}/src/leaf.ts`, 'snap-before')
        expect(mod.turnBase).toBe('snap-before')
    })

    it('never overwrites a turn base already set', async () => {
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        mod.turnBase = 'snap-first'
        await noteEdit(io, `${ROOT}/src/leaf.ts`, 'snap-later')
        expect(mod.turnBase).toBe('snap-first')
    })

    it('keeps the first base across later edits in the turn', async () => {
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        await noteEdit(io, `${ROOT}/src/leaf.ts`, 'snap-1')
        await noteEdit(io, `${ROOT}/src/hub.ts`, 'snap-2')
        expect(mod.turnBase).toBe('snap-1')
    })

    it('leaves an unset base null when the edit brings no base, so a later edit can still set it', async () => {
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        await noteEdit(io, `${ROOT}/src/leaf.ts`, null)
        expect(mod.turnBase).toBeNull()
        await noteEdit(io, `${ROOT}/src/leaf.ts`, 'snap-2')
        expect(mod.turnBase).toBe('snap-2')
    })

    it('does not set the base for an edit outside the project', async () => {
        const { io } = fakePort({ dashboard: dashboard(ROOT) })
        await noteEdit(io, '/other/x.ts', 'snap-1')
        expect(mod.turnBase).toBeNull()
    })
})
