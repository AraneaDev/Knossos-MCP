import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Dashboard, JobState, KnossosView, TurnBrief } from '../../types'
import { cells } from '../lib/rows'
import type { Port } from './port'
import { drawBand } from './render'
import type { BandHandlers } from './render'
import { mod, reset } from './state'

const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: true, watchPollMs: 1000, notifications: true }

/** The surface's elements by name: the JSX stub turns each into `{ type: '<Name>', props, key }`. */
const elements = { Box: 'Box', Text: 'Text', Button: 'Button', Markdown: 'Markdown', Input: 'Input', Code: 'Code', Raster: 'Raster' }

/** The mod clock, in milliseconds: 12 seconds after the brief's scan. */
const NOW = 1_012_000

const brief = (over: Partial<TurnBrief> = {}): TurnBrief =>
    ({
        status: 'ok',
        project_root: '/r',
        project_id: 'p',
        snapshot_id: 's',
        scanned_at: 1_000,
        scan_ms: 5,
        reason: null,
        roots_file: null,
        refused_root: null,
        path: '/r',
        changed_files: ['a.php'],
        added_files: [],
        deleted_files: [],
        impact: { 'a.php': { path: 'a.php', dependent_files: 37, boundaries: ['core', 'http'] } },
        tests: [{ path: 't/A.php', distance: 1 }],
        policy: { status: 'evaluated', total: 0, violations: [], truncated: false },
        ...over,
    }) as TurnBrief

const idle: JobState = { phase: 'idle', lastAttemptAt: 1_000_000 } as JobState

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

/** A dashboard that declares `core` as its only boundary. */
const declaring = (): Dashboard => ({ status: 'ok', project_root: '/r', boundaries: { declared: ['core'], items: [] } }) as unknown as Dashboard

/** A port over plain cells, recording which ones were read. */
function fakePort(cells: Record<string, unknown> = {}) {
    const values: Record<string, unknown> = { view: view(), dashboard: null, brief: brief(), job: idle, ...cells }
    const reads: string[] = []
    const state = new Proxy(
        {},
        {
            get: (_, key: string) => ({
                read: async () => {
                    reads.push(key)
                    return values[key]
                },
            }),
        },
    )
    const io = { state, clock: { now: async () => NOW } } as unknown as Port
    return { io, reads }
}

type Node = { type: string; props: Record<string, unknown> & { children?: unknown }; key: string | null }

const children = (n: Node): Node[] =>
    (Array.isArray(n.props.children) ? (n.props.children as unknown[]).flat(Infinity) : [n.props.children]).filter((c): c is Node => c !== null && typeof c === 'object' && 'type' in (c as object))

/** The band's parts: its text box, the Text inside, the string it shows, and its buttons by label. */
function partsOf(tree: unknown) {
    const band = tree as Node
    const [textBox, ...rest] = children(band)
    const text = children(textBox!)[0]!
    const shown = (text.props.children as unknown[]).join('')
    const buttons = new Map(rest.filter(n => n.type === 'Button').map(n => [n.props.label as string, n]))
    return { band, textBox: textBox!, text, shown, buttons }
}

function handlers() {
    return { details: vi.fn(), copy: vi.fn(), hide: vi.fn() } satisfies BandHandlers
}

beforeEach(() => {
    reset(options)
})

describe('drawBand: nothing drawn', () => {
    it('draws nothing while the mod is off, clears the band text and never resolves the elements', async () => {
        const { io } = fakePort()
        mod.disabled = true
        mod.bandText = 'stale'
        const resolve = vi.fn(() => elements as never)
        expect(await drawBand(io, resolve, { hasSurvey: false, bodyColumns: 80 }, handlers())).toBeNull()
        expect(mod.bandText).toBeNull()
        expect(resolve).not.toHaveBeenCalled()
    })

    it('gives way to a survey', async () => {
        const { io } = fakePort()
        mod.bandText = 'stale'
        const resolve = vi.fn(() => elements as never)
        expect(await drawBand(io, resolve, { hasSurvey: true, bodyColumns: 80 }, handlers())).toBeNull()
        expect(mod.bandText).toBeNull()
        expect(resolve).not.toHaveBeenCalled()
    })

    it('stays hidden while the view hides the band', async () => {
        const { io, reads } = fakePort({ view: view({ isBandHidden: true }) })
        mod.bandText = 'stale'
        const resolve = vi.fn(() => elements as never)
        expect(await drawBand(io, resolve, { hasSurvey: false, bodyColumns: 80 }, handlers())).toBeNull()
        expect(mod.bandText).toBeNull()
        expect(resolve).not.toHaveBeenCalled()
        // Hidden is decided before the brief is even looked at.
        expect(reads).not.toContain('brief')
    })

    it('draws nothing when there is no brief and no scan to report', async () => {
        const { io } = fakePort({ brief: null })
        mod.bandText = 'stale'
        const resolve = vi.fn(() => elements as never)
        expect(await drawBand(io, resolve, { hasSurvey: false, bodyColumns: 80 }, handlers())).toBeNull()
        expect(mod.bandText).toBeNull()
        expect(resolve).not.toHaveBeenCalled()
    })
})

describe('drawBand: a turn that changed the graph', () => {
    it('draws a band Box as wide as the body, showing the text it records', async () => {
        const { io } = fakePort()
        const resolve = vi.fn(() => elements as never)
        const tree = await drawBand(io, resolve, { hasSurvey: false, bodyColumns: 200 }, handlers())
        expect(resolve).toHaveBeenCalledTimes(1)
        const { band, textBox, shown } = partsOf(tree)
        expect(band).toMatchObject({ type: 'Box', key: 'band', props: { width: 200 } })
        expect(textBox).toMatchObject({ type: 'Box', key: 'band-text' })
        expect(mod.bandText).toBe('knossos · 1 file → 37 dependents · 1 test · as of 12s ago · reaching core, http')
        // Wide enough for all of it: the text is the recorded one, then a space before the buttons.
        expect(shown).toBe(`${mod.bandText} `)
        expect(textBox.props.width).toBe(cells(mod.bandText!) + 1)
    })

    it.each([
        ['alert', 'error', brief({ policy: { status: 'evaluated', total: 2, violations: [], truncated: false } }), idle],
        ['warn', 'warning', null, { phase: 'failed', lastAttemptAt: 1_000_000 } as JobState],
        ['normal', 'inactive', brief(), idle],
    ])('colours a %s band %s', async (_tone, color, b, job) => {
        const { io } = fakePort({ brief: b, job })
        const { text } = partsOf(await drawBand(io, () => elements as never, { hasSurvey: false, bodyColumns: 200 }, handlers()))
        expect(text.type).toBe('Text')
        expect(text.props.color).toBe(color)
    })

    it('offers details only when the model shows them, and its press opens them', async () => {
        const act = handlers()
        const withDetails = partsOf(await drawBand(fakePort().io, () => elements as never, { hasSurvey: false, bodyColumns: 200 }, act))
        expect([...withDetails.buttons.keys()]).toEqual(['details', 'hide'])
        ;(withDetails.buttons.get('details')!.props.onPress as () => void)()
        expect(act.details).toHaveBeenCalledTimes(1)
        expect(act.hide).not.toHaveBeenCalled()

        // A scan with no brief yet: the band says so, with nothing to open.
        const scanning = partsOf(
            await drawBand(fakePort({ brief: null, job: { phase: 'scanning', lastAttemptAt: 0 } }).io, () => elements as never, { hasSurvey: false, bodyColumns: 200 }, handlers()),
        )
        expect([...scanning.buttons.keys()]).toEqual(['hide'])
        expect(scanning.shown).toBe('knossos · scanning… ')
    })

    it('offers to copy the command a refused root needs, handing the press its surface', async () => {
        const act = handlers()
        const refused = brief({ status: 'not-allowed', refused_root: '/work/app', path: '/work/app' } as Partial<TurnBrief>)
        const { buttons, shown } = partsOf(await drawBand(fakePort({ brief: refused }).io, () => elements as never, { hasSurvey: false, bodyColumns: 200 }, act))
        expect([...buttons.keys()]).toEqual(['details', 'copy', 'hide'])
        expect(shown).toBe('knossos · not allowed: app ')
        ;(buttons.get('copy')!.props.onPress as (p: unknown) => void)({ surface: 'mobile' })
        expect(act.copy).toHaveBeenCalledWith("knossos allow-root '/work/app' --execute", 'mobile')
        expect(act.details).not.toHaveBeenCalled()
    })

    it('always offers hide, and its press hides the band', async () => {
        const act = handlers()
        const { buttons } = partsOf(await drawBand(fakePort().io, () => elements as never, { hasSurvey: false, bodyColumns: 200 }, act))
        ;(buttons.get('hide')!.props.onPress as () => void)()
        expect(act.hide).toHaveBeenCalledTimes(1)
        expect(act.details).not.toHaveBeenCalled()
        expect(act.copy).not.toHaveBeenCalled()
    })
})

describe('drawBand: a narrow body', () => {
    // Each button takes its label and five cells (`[ label ]` and a space); one cell more is kept free.
    it('cuts the text so it and the buttons fit the body', async () => {
        const columns = 40
        const { shown, textBox } = partsOf(await drawBand(fakePort().io, () => elements as never, { hasSurvey: false, bodyColumns: columns }, handlers()))
        const room = columns - ('details'.length + 5) - ('hide'.length + 5) - 1
        expect(shown.endsWith('… ')).toBe(true)
        expect(cells(shown.trimEnd())).toBe(room)
        expect(shown.startsWith('knossos · 1 file')).toBe(true)
        expect((textBox.props.width as number) + ('details'.length + 5) + ('hide'.length + 5)).toBeLessThanOrEqual(columns)
        // What the band records is the whole text, not the cut one.
        expect(mod.bandText).toBe('knossos · 1 file → 37 dependents · 1 test · as of 12s ago · reaching core, http')
    })

    it('keeps at least one cell of text when the buttons take the whole body', async () => {
        const { shown, textBox, band } = partsOf(await drawBand(fakePort().io, () => elements as never, { hasSurvey: false, bodyColumns: 5 }, handlers()))
        expect(band.props.width).toBe(5)
        expect(shown).toBe('… ')
        expect(textBox.props.width).toBe(2)
    })
})

describe('drawBand: the dashboard', () => {
    it('names only the declared boundaries when the dashboard declares some', async () => {
        const { io } = fakePort({ dashboard: declaring() })
        await drawBand(io, () => elements as never, { hasSurvey: false, bodyColumns: 200 }, handlers())
        expect(mod.bandText).toBe('knossos · 1 file → 37 dependents · 1 test · as of 12s ago · reaching core')
    })

    it('still draws without an ok dashboard, naming every boundary reached', async () => {
        const { io } = fakePort({ dashboard: { status: 'error', declared: ['core'] } })
        const tree = await drawBand(io, () => elements as never, { hasSurvey: false, bodyColumns: 200 }, handlers())
        expect(partsOf(tree).band.key).toBe('band')
        expect(mod.bandText).toBe('knossos · 1 file → 37 dependents · 1 test · as of 12s ago · reaching core, http')
    })
})
