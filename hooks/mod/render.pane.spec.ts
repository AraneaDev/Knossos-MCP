import { beforeEach, describe, expect, it } from 'vitest'
import type { BoundaryMatrix, Dashboard, KnossosView, TurnBrief } from '../../types'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import type { Port } from './port'
import { currentInput, drawPane } from './render'
import type { Handlers } from './render'
import { mod, reset } from './state'

const ROOT = '/work/app'
const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true }

/** The surface's elements by name: the JSX stub turns each into `{ type: '<Name>', props, key }`, which serializes as the engine would weigh it. */
const elements = { Box: 'Box', Text: 'Text', Button: 'Button', Markdown: 'Markdown', Input: 'Input', Code: 'Code', Raster: 'Raster' }
/** Mobile has no text field. */
const mobileElements = Object.fromEntries(Object.entries(elements).filter(([name]) => name !== 'Input'))

const hubs = (n: number) =>
    Array.from({ length: n }, (_, i) => ({
        name: `Component${i}`,
        canonical_name: `App\\Component${i}`,
        kind: 'class',
        boundary: 'core',
        in_degree: n - i,
        out_degree: 1,
        cross_boundary_degree: 0,
        dependent_files: 5,
        top_dependents: ['src/a.php', 'src/b.php'],
        path: `src/C${i}.php`,
        line: 3,
    }))

const dashboard = (over: Partial<Dashboard> = {}): Dashboard =>
    ({
        status: 'ok',
        path: ROOT,
        project_root: ROOT,
        project_id: 'p1',
        snapshot_id: 's1',
        freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
        hubs: hubs(40),
        hubs_truncated: false,
        hubs_truncation_reasons: [],
        hotspots: [],
        dead_code_candidates: 0,
        dead_code_truncated: false,
        cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] },
        trend: [],
        fan_in: [],
        fan_in_truncated: false,
        ...over,
    }) as Dashboard

const view = (over: Partial<KnossosView> = {}): KnossosView => ({
    inspect: null,
    isBandHidden: false,
    tab: 'hubs',
    selected: 0,
    showKeys: false,
    filter: '',
    filtering: false,
    sort: 'in',
    ...over,
})

/** A port over plain cells (what drawing reads) and a log of what it wrote to the debug log. */
function fakePort(cells: Record<string, unknown> = {}) {
    const values: Record<string, unknown> = {
        brief: null,
        dashboard: dashboard(),
        view: view(),
        detail: null,
        refresh: { fetchedAt: 0, failed: false },
        rescan: { phase: 'idle', reason: null },
        allow: null,
        theme: 'dark',
        changes: NO_CHANGES,
        sessionRoot: null,
        live: LIVE_OFF,
        sessionLedger: null,
        sessionStart: null,
        sessionBegan: null,
        sessionEdits: [],
        sessionScans: [],
        sessionRev: null,
        fileDiff: null,
        diffFold: null,
        untestedOnly: false,
        gitHead: null,
        couplings: null,
        feedback: null,
        search: { query: '', for: null, phase: 'idle', answer: null },
        peek: null,
        branch: null,
        flash: null,
        churn: null,
        rings: null,
        route: null,
        note: null,
        job: null,
        ...cells,
    }
    const reads: string[] = []
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => {
                    reads.push(key)
                    return values[key]
                },
                update: async (change: (v: unknown) => unknown) => (values[key] = change(values[key])),
            }),
        },
    )
    const logs: { text: string; options: unknown }[] = []
    const io = {
        state,
        clock: { now: async () => 0 },
        ui: { log: (text: string, opts?: unknown) => void logs.push({ text, options: opts }) },
    } as unknown as Port
    return { io, logs, reads }
}

const act: Handlers = { press: () => undefined, type: () => undefined, submit: () => undefined, link: () => undefined }

type Node = { type: string; props: Record<string, unknown> & { children?: unknown }; key: string | null }

/** Every element in the tree, depth first. */
function nodesOf(tree: unknown): Node[] {
    const out: Node[] = []
    const walk = (n: unknown) => {
        if (Array.isArray(n)) return n.forEach(walk)
        if (n === null || typeof n !== 'object' || !('type' in n)) return
        out.push(n as Node)
        walk((n as Node).props.children)
    }
    walk(tree)
    return out
}

/** All the words the tree shows: its text, its button labels and its links. */
function textOf(tree: unknown): string {
    const parts: string[] = []
    const walk = (n: unknown) => {
        if (typeof n === 'string') return void parts.push(n)
        if (Array.isArray(n)) return n.forEach(walk)
        if (n === null || typeof n !== 'object' || !('props' in n)) return
        const p = (n as Node).props
        if (typeof p.label === 'string') parts.push(p.label)
        if (typeof p.text === 'string') parts.push(p.text)
        walk(p.children)
    }
    walk(tree)
    return parts.join('\n')
}

const children = (n: Node): Node[] =>
    (Array.isArray(n.props.children) ? (n.props.children as unknown[]).flat(Infinity) : [n.props.children]).filter((c): c is Node => c !== null && typeof c === 'object' && 'type' in (c as object))
const isCard = (n: Node) => n.type === 'Box' && n.props.position === 'absolute' && n.props.display === 'none'
const weight = (tree: unknown) => JSON.stringify(tree).length

beforeEach(() => {
    reset(options)
})

describe('drawPane: off and without a graph', () => {
    it('draws an empty keyed Box when the mod is off, and clears what the pane last drew', async () => {
        const { io, reads } = fakePort()
        mod.disabled = true
        mod.paneText = 'stale'
        mod.emptyShown = true
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree).toMatchObject({ type: 'Box', key: 'off' })
        expect(nodesOf(tree)).toHaveLength(1)
        expect(mod.paneText).toBeNull()
        expect(mod.emptyShown).toBe(false)
        expect(reads).toEqual([])
    })

    it('says the graph is loading when no dashboard came yet, and marks the empty state shown', async () => {
        const { io } = fakePort({ dashboard: null, refresh: { fetchedAt: null, failed: false } })
        mod.paneText = 'stale'
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree.key).toBe('empty')
        expect(mod.emptyShown).toBe(true)
        expect(mod.paneText).toBeNull()
        expect(textOf(tree)).toContain('Reading the graph')
        expect(textOf(tree)).not.toContain('allow root')
    })

    it('offers to ask for a scan when the project was never scanned', async () => {
        const { io } = fakePort({ dashboard: { status: 'unscanned' } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree.key).toBe('empty')
        expect(textOf(tree)).toContain('No architecture graph yet')
        expect(textOf(tree)).toContain('ask Claude to scan it')
    })

    it('adds the allow-root offer when the last brief was refused its root', async () => {
        const brief = { status: 'not-allowed', refused_root: '/elsewhere', path: '/elsewhere/x', roots_file: null } as unknown as TurnBrief
        const { io } = fakePort({ dashboard: null, brief, refresh: { fetchedAt: null, failed: false } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(mod.emptyShown).toBe(true)
        const text = textOf(tree)
        expect(text).toContain('Reading the graph')
        expect(text).toContain('/elsewhere is not an allowed root')
        expect(nodesOf(tree).some(n => n.type === 'Button' && n.props.label === 'allow root')).toBe(true)
    })

    it('treats a dashboard that answered with an error as an unreadable graph', async () => {
        const { io } = fakePort({ dashboard: { status: 'error' } })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 80 }, act)) as unknown as Node
        expect(tree.key).toBe('empty')
        expect(mod.emptyShown).toBe(true)
        expect(textOf(tree)).toContain('Could not read the graph')
    })
})

describe('drawPane: a graph', () => {
    it('keys the pane, keeps the header status text, and pins no bar when everything fits', async () => {
        const { io } = fakePort()
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 100 }, act)) as unknown as Node
        expect(tree).toMatchObject({ type: 'Box', key: 'pane' })
        const status = (await currentInput(io, true))!.status.text
        expect(status.length).toBeGreaterThan(0)
        expect(mod.paneText).toBe(status)
        expect(mod.emptyShown).toBe(false)
        expect(nodesOf(tree).some(n => n.key === 'bar')).toBe(false)
        expect(textOf(tree)).toContain('Component0')
    })

    it('keys the detail when a component is inspected', async () => {
        const { io } = fakePort({ view: view({ inspect: { name: 'App\\Component0', label: 'Component0' } }) })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 100 }, act)) as unknown as Node
        expect(tree.key).toBe('detail')
    })

    it('draws a pane with no columns as one column wide, without throwing', async () => {
        for (const bodyColumns of [0, -12]) {
            const { io } = fakePort()
            const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns }, act)) as unknown as Node
            const rows = nodesOf(tree).filter(n => n.type === 'Box' && n.props.flexDirection === 'row')
            expect(rows.length, `${bodyColumns}`).toBeGreaterThan(0)
            expect(new Set(rows.map(r => r.props.width)), `${bodyColumns}`).toEqual(new Set([1]))
        }
    })

    it('records whether the pane is wide enough for the detail beside the tab', async () => {
        const { io } = fakePort()
        await drawPane(io, elements as never, 'terminal', { bodyColumns: 160 }, act)
        expect(mod.paneWide).toBe(true)
        await drawPane(io, elements as never, 'terminal', { bodyColumns: 60 }, act)
        expect(mod.paneWide).toBe(false)
        // Set before the empty state too: the lookup it needs runs from the next press.
        await drawPane(fakePort({ dashboard: null }).io, elements as never, 'terminal', { bodyColumns: 200 }, act)
        expect(mod.paneWide).toBe(true)
    })

    it('pins the footer bar over a window shorter than the body, never above the top, however far back it is scrolled', async () => {
        const { io } = fakePort({ dashboard: dashboard({ hubs: hubs(120) }) })
        for (const offset of [-5, 0, 7]) {
            const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 100, scroll: { offset, bodyRows: 10 } }, act)) as unknown as Node
            expect(tree.key).toBe('pane')
            const bar = children(tree).find(n => n.key === 'bar')
            expect(bar, `${offset}`).toBeDefined()
            expect(bar!.props).toMatchObject({ position: 'absolute', left: 0, width: 100 })
            expect(bar!.props.top as number, `${offset}`).toBeGreaterThanOrEqual(0)
            expect(children(bar!).length).toBeGreaterThan(0)
        }
        // A negative offset is drawn as the top: the same tree as offset 0.
        const negative = await drawPane(io, elements as never, 'terminal', { bodyColumns: 100, scroll: { offset: -5, bodyRows: 10 } }, act)
        const top = await drawPane(io, elements as never, 'terminal', { bodyColumns: 100, scroll: { offset: 0, bodyRows: 10 } }, act)
        expect(JSON.stringify(negative)).toBe(JSON.stringify(top))
    })
})

describe('drawPane: surfaces', () => {
    it('hangs no hover cards and no links on mobile', async () => {
        const { io } = fakePort()
        const tree = await drawPane(io, mobileElements as never, 'mobile', { bodyColumns: 100 }, act)
        const nodes = nodesOf(tree)
        expect(nodes.filter(isCard)).toEqual([])
        expect(nodes.filter(n => n.type === 'Markdown')).toEqual([])
        expect(nodes.filter(n => n.type === 'Input')).toEqual([])
        expect(textOf(tree)).toContain('Component0')
    })

    it('hangs at most eight hover cards after the rows where the surface has a pointer, and links the places', async () => {
        const { io } = fakePort()
        const tree = (await drawPane(io, elements as never, 'desktop', { bodyColumns: 100 }, act)) as unknown as Node
        const top = children(tree)
        const cards = top.filter(isCard)
        expect(cards.length).toBeGreaterThan(0)
        expect(cards.length).toBeLessThanOrEqual(8)
        // The cards come after every row.
        const firstCard = top.findIndex(isCard)
        expect(top.slice(firstCard).every(isCard)).toBe(true)
        for (const card of cards) expect((card.props.hover as { scope: string }).scope).toMatch(/^knossos:/)
        expect(nodesOf(tree).some(n => n.type === 'Markdown')).toBe(true)
    })

    it('draws the Boundaries heat map on the terminal as one Raster instead of a Box per row', async () => {
        const boundary_matrix: BoundaryMatrix = {
            boundaries: ['tests', 'core', 'worker'],
            members: [10, 20, 5],
            boundaries_truncated: false,
            cells: [
                [3, 9, 0],
                [0, 4, 1],
                [0, 0, 2],
            ],
            forbidden: [[1, 0]],
            edges: 19,
            truncated: false,
            truncation_reasons: [],
        }
        const { io } = fakePort({ dashboard: dashboard({ boundary_matrix }), view: view({ tab: 'boundaries' }) })
        const terminal = nodesOf(await drawPane(io, elements as never, 'terminal', { bodyColumns: 100 }, act))
        const rasters = terminal.filter(n => n.type === 'Raster')
        expect(rasters.map(r => r.key)).toEqual(['raster-heat'])
        expect(terminal.some(n => n.key !== null && n.key.startsWith('heat-'))).toBe(false)
        // Elsewhere the same rows are text.
        const desktop = nodesOf(await drawPane(io, elements as never, 'desktop', { bodyColumns: 100 }, act))
        expect(desktop.filter(n => n.type === 'Raster')).toEqual([])
        expect(desktop.some(n => n.key === 'heat-head')).toBe(true)
    })
})

describe('drawPane: the tree budget', () => {
    it('drops the hover cards and most links before it gives up rows', async () => {
        const { io } = fakePort({ dashboard: dashboard({ hubs: hubs(300) }) })
        const full = await drawPane(io, elements as never, 'desktop', { bodyColumns: 120, scroll: { offset: 0, bodyRows: 40 } }, act)
        expect(nodesOf(full).filter(isCard).length).toBeGreaterThan(0)
        const fullLinks = nodesOf(full).filter(n => n.type === 'Markdown').length
        const tall = await drawPane(io, elements as never, 'desktop', { bodyColumns: 120, scroll: { offset: 0, bodyRows: 100 } }, act)
        expect(weight(tall)).toBeLessThanOrEqual(70_000)
        expect(nodesOf(tall).filter(isCard)).toEqual([])
        // Only the marked row keeps its links: fewer than a pane a third as tall drew.
        expect(nodesOf(tall).filter(n => n.type === 'Markdown').length).toBeLessThan(fullLinks)
        expect(textOf(tall)).not.toContain('Too large to draw here')
    })

    it('gives the lists fewer rows when even the lean tree is past the budget', async () => {
        const { io, logs } = fakePort({ dashboard: dashboard({ hubs: hubs(300) }) })
        // The same window at a width that fits the budget: the row count a shrink must undercut.
        const fits = await drawPane(io, elements as never, 'desktop', { bodyColumns: 120, scroll: { offset: 0, bodyRows: 40 } }, act)
        const fullRows = new Set(textOf(fits).match(/Component\d+/g)).size
        const tree = await drawPane(io, elements as never, 'desktop', { bodyColumns: 2000, scroll: { offset: 0, bodyRows: 40 } }, act)
        expect(weight(tree)).toBeLessThanOrEqual(70_000)
        const shown = new Set(textOf(tree).match(/Component\d+/g))
        expect(shown.size).toBeGreaterThan(0)
        expect(shown.size).toBeLessThan(fullRows)
        expect(textOf(tree)).not.toContain('Too large to draw here')
        expect(logs).toEqual([])
    })

    it('draws one line and logs once to debug when even the fewest rows are too heavy', async () => {
        const { io, logs } = fakePort({ dashboard: dashboard({ hubs: hubs(300) }) })
        const tree = (await drawPane(io, elements as never, 'terminal', { bodyColumns: 5000, scroll: { offset: 0, bodyRows: 40 } }, act)) as unknown as Node
        expect(tree.key).toBe('pane')
        expect(weight(tree)).toBeLessThanOrEqual(70_000)
        expect(children(tree).map(r => r.key)).toEqual(['too-large'])
        expect(textOf(tree)).toContain('Too large to draw here')
        expect(logs).toHaveLength(1)
        expect(logs[0]!.options).toEqual({ to: 'debug' })
        expect(logs[0]!.text).toContain('too large to draw')
        expect(mod.tooLargeLogged).toBe(true)
        // The header status is still what the age tick compares against.
        expect(mod.paneText).toBe((await currentInput(io, true))!.status.text)

        await drawPane(io, elements as never, 'terminal', { bodyColumns: 5000, scroll: { offset: 0, bodyRows: 40 } }, act)
        expect(logs).toHaveLength(1)
    })

    it('never hands the engine a tree past its 100,000 serialized characters', async () => {
        const many = dashboard({ hubs: hubs(400) })
        const cases: [string, Record<string, unknown>, number, number][] = [
            ['desktop', { dashboard: many }, 120, 200],
            ['terminal', { dashboard: many }, 1000, 40],
            ['mobile', { dashboard: many }, 3000, 60],
            ['desktop', { dashboard: many, view: view({ filtering: true, filter: 'x'.repeat(5000) }) }, 120, 40],
            ['terminal', { dashboard: many, view: view({ inspect: { name: 'App\\Component1', label: 'Component1' } }) }, 4000, 300],
            ['terminal', { dashboard: null }, 100_000, 40],
        ]
        for (const [surface, cells, bodyColumns, bodyRows] of cases) {
            const { io } = fakePort(cells)
            const ui = surface === 'mobile' ? mobileElements : elements
            const tree = await drawPane(io, ui as never, surface, { bodyColumns, scroll: { offset: 0, bodyRows } }, act)
            expect(weight(tree), `${surface} ${bodyColumns}x${bodyRows}`).toBeLessThanOrEqual(100_000)
        }
    })
})
