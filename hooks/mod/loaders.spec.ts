import { describe, expect, it } from 'vitest'
import type { ChurnState, Dashboard, DetailState, Inspected, KnossosView } from '../../types'
import { NO_CHANGES } from '../lib/layout'
import { LIVE_OFF } from '../lib/live'
import { LOADERS, request } from './loaders'
import type { Port } from './port'
import { mod, reset } from './state'

const ROOT = '/work/app'
const dashboard = (snapshot: string): Dashboard =>
  ({ status: 'ok', path: ROOT, project_root: ROOT, project_id: 'p1', snapshot_id: snapshot, freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 }, hubs: [], hubs_truncated: false, hubs_truncation_reasons: [], hotspots: [], dead_code_candidates: 0, dead_code_truncated: false, cycles: { count: 0, truncated: false, truncation_reasons: [], largest: [] }, trend: [], fan_in: [], fan_in_truncated: false }) as Dashboard
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'churn', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })
const churnOk = JSON.stringify({ status: 'ok', files: [] })

/**
 * A port over plain values: the cells the loaders read and write, a clock
 * whose timers run only when the test says, and a wrapper that answers each
 * subcommand from `answers` and records every run.
 */
function fakePort(initial: Record<string, unknown>, answers: Record<string, string> = {}) {
  const blank = { brief: null, dashboard: null, view: view(), detail: null, refresh: { fetchedAt: null, failed: false }, rescan: { phase: 'idle', reason: null }, allow: { phase: 'idle', root: null, reason: null }, theme: 'dark', changes: NO_CHANGES, sessionRoot: null, live: LIVE_OFF, sessionLedger: null, sessionStart: null, sessionBegan: null, sessionEdits: [], sessionScans: [], sessionRev: null, fileDiff: null, diffFold: null, gitHead: null, couplings: null, feedback: null, search: { query: '', for: null, phase: 'idle', answer: null }, peek: null, branch: null, flash: null, churn: null, rings: null, route: null, note: null }
  const values = new Map<string, unknown>(Object.entries({ ...blank, ...initial }))
  const timers: { at: number; run: () => void; cancelled: boolean }[] = []
  const runs: string[][] = []
  let now = 0
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
      now: async () => now,
      after: (ms: number, run: () => void) => {
        const timer = { at: now + ms, run, cancelled: false }
        timers.push(timer)
        return { cancel: () => void (timer.cancelled = true) }
      },
    },
    ui: { log: () => undefined, invalidate: () => undefined },
    session: { root: async () => ROOT },
    plugin: { root: '/plugin' },
    process: {
      run: async (argv: string[]) => {
        runs.push(argv)
        return { exitCode: 0, stdout: answers[argv[2]!] ?? '', stderr: '' }
      },
    },
    state,
  } as unknown as Port
  /** Runs every timer due within `ms`, and what they schedule in turn, then lets the reads they started settle. */
  const advance = async (ms = 0) => {
    now += ms
    for (let due = timers.find(t => !t.cancelled && t.at <= now); due !== undefined; due = timers.find(t => !t.cancelled && t.at <= now)) {
      due.cancelled = true
      due.run()
      await new Promise(resolve => setTimeout(resolve, 0))
    }
    await new Promise(resolve => setTimeout(resolve, 0))
  }
  return { io, values, runs, advance, pending: () => timers.filter(t => !t.cancelled).length }
}

const options = { fanInThreshold: 20, enforcePolicies: true, agentNotes: true, watch: false, watchPollMs: 1000, notifications: true }

describe('the keyed loader', () => {
  it('reads what the cell wants on a timer, keeps the last answer on show meanwhile, and stores the new one', async () => {
    reset(options)
    const before: ChurnState = { head: 'a', phase: 'done', answer: { status: 'ok', files: [] } as never }
    const port = fakePort({ dashboard: dashboard('s1'), gitHead: { rev: 'b', branch: 'main' }, churn: before }, { churn: churnOk })
    await request(port.io, 'churn')
    // Not inside the request: the read waits for the timer.
    expect(port.runs).toEqual([])
    expect(port.values.get('churn')).toEqual({ head: 'b', phase: 'loading', answer: before.answer })
    await port.advance()
    expect(port.runs).toEqual([['sh', '/plugin/hooks/scripts/knossos-run.sh', 'churn', ROOT]])
    expect(port.values.get('churn')).toEqual({ head: 'b', phase: 'done', answer: { status: 'ok', files: [] } })
  })

  it('starts nothing while the cell holds or reads what is wanted, nor where there is nothing to read', async () => {
    reset(options)
    const port = fakePort({ dashboard: dashboard('s1'), gitHead: { rev: 'b', branch: 'main' } }, { churn: churnOk })
    await request(port.io, 'churn')
    await request(port.io, 'churn')
    expect(port.pending()).toBe(1)
    await port.advance()
    await request(port.io, 'churn')
    expect(port.pending()).toBe(0)
    expect(port.runs).toHaveLength(1)
    // Another tab: no churn to read.
    port.values.set('view', view({ tab: 'hubs' }))
    port.values.set('gitHead', { rev: 'c', branch: 'main' })
    await request(port.io, 'churn')
    expect(port.pending()).toBe(0)
  })

  it('drops an answer for what the cell no longer wants', async () => {
    reset(options)
    const port = fakePort({ dashboard: dashboard('s1'), gitHead: { rev: 'b', branch: 'main' } }, { churn: churnOk })
    await request(port.io, 'churn')
    // A new commit while the read waits: the answer on its way is for the old one.
    port.values.set('churn', { head: 'c', phase: 'loading', answer: null })
    await port.advance()
    expect(port.values.get('churn')).toEqual({ head: 'c', phase: 'loading', answer: null })
  })

  it('turns the mod off when the wrapper finds no binary, and then reads nothing', async () => {
    reset(options)
    const port = fakePort({ dashboard: dashboard('s1'), gitHead: { rev: 'b', branch: 'main' } }, { churn: '{"status":"no-binary"}' })
    await request(port.io, 'churn')
    await port.advance()
    expect(mod.disabled).toBe(true)
    port.values.set('gitHead', { rev: 'c', branch: 'main' })
    await request(port.io, 'churn')
    expect(port.pending()).toBe(0)
  })

  it('a component looked up again while its lookup runs starts no second one, and its answer still lands after the detail moved away and back', async () => {
    reset(options)
    const a: Inspected = { name: 'App\\A', label: 'A' }
    const b: Inspected = { name: 'App\\B', label: 'B' }
    const port = fakePort({ dashboard: dashboard('s1') }, { 'component-detail': JSON.stringify({ status: 'not-found' }) })
    await request(port.io, 'detail', a)
    await request(port.io, 'detail', b)
    await request(port.io, 'detail', a)
    // B's lookup and A's; A's second request found A's in flight and put its loading state back.
    expect(port.pending()).toBe(2)
    expect((port.values.get('detail') as DetailState).name).toBe('App\\A')
    await port.advance()
    expect(port.runs.map(r => r[4])).toEqual(['App\\A', 'App\\B'])
    expect(port.values.get('detail')).toMatchObject({ name: 'App\\A', phase: 'done' })
    expect(mod.fetching.size).toBe(0)
  })

  it('looks the marked row up beside the tab only once the marker rests, and a row with nothing to show shows none', async () => {
    reset(options)
    mod.paneWide = true
    const hub = (i: number) => ({ name: `C${i}`, canonical_name: `App\\C${i}`, kind: 'class', boundary: 'core', in_degree: 90 - i, out_degree: 1, cross_boundary_degree: 0, dependent_files: 40 - i, path: `src/C${i}.php`, line: 1, top_dependents: [] })
    const d = { ...dashboard('s1'), hubs: [hub(0), hub(1), hub(2)] } as Dashboard
    const port = fakePort({ dashboard: d, view: view({ tab: 'hubs' }) }, { 'component-detail': JSON.stringify({ status: 'not-found' }) })
    for (const selected of [0, 1, 2]) {
      port.values.set('view', view({ tab: 'hubs', selected }))
      await request(port.io, 'peek')
    }
    // One timer, for the row the marker came to rest on.
    expect(port.pending()).toBe(1)
    await port.advance(50)
    expect(port.runs).toEqual([])
    await port.advance(500)
    expect(port.runs.map(r => r[4])).toEqual(['App\\C2'])
    expect(port.values.get('peek')).toMatchObject({ shown: { name: 'App\\C2' }, detail: { phase: 'done' } })
    // Past the list's end there is no row to show: the detail beside the tab goes, and the row is remembered as empty.
    port.values.set('dashboard', { ...d, hubs: [] })
    await request(port.io, 'peek')
    expect(port.values.get('peek')).toBeNull()
    expect(mod.peekNone).not.toBeNull()
  })
})

describe('the loaders', () => {
  const w = { from: 'core', to: 'tests', snapshot: 's1', root: ROOT }

  it('keep the answer on show while they read again where the pane draws it, and only there', () => {
    const answer = { status: 'ok' } as never
    expect(LOADERS.branch.loading({ snapshot: 's0', phase: 'done', answer }, { snapshot: 's1', root: ROOT })).toEqual({ snapshot: 's1', phase: 'loading', answer })
    expect(LOADERS.rings.loading({ name: 'A', snapshot: 's0', phase: 'done', answer }, { name: 'A', snapshot: 's1', root: ROOT }).answer).toBe(answer)
    expect(LOADERS.rings.loading({ name: 'A', snapshot: 's0', phase: 'done', answer }, { name: 'B', snapshot: 's1', root: ROOT }).answer).toBeNull()
    expect(LOADERS.couplings.loading({ ...w, phase: 'done', answer }, w).answer).toBeNull()
  })

  it('ask the wrapper for what they want, where the graph lives', async () => {
    const io = fakePort({}).io
    expect(await LOADERS.couplings.ask(io, w)).toEqual({ sub: 'boundary-couplings', args: ['--from=core', '--to=tests'], timeoutMs: 20_000, root: ROOT })
    expect(await LOADERS.diff.ask(io, { name: 'src/A.php', rev: 'r1', snapshot: 's1', root: ROOT })).toMatchObject({ sub: 'session-diff', args: ['--rev=r1', '--file=src/A.php'] })
    expect(await LOADERS.route.ask(io, w)).toMatchObject({ sub: 'path-between', args: ['--from=core', '--to=tests'] })
  })

  it('a file is looked up by its path under the project root, a component wherever the session is', async () => {
    const io = fakePort({ dashboard: dashboard('s1') }).io
    expect(await LOADERS.detail.ask(io, { shown: { name: 'src/A.php', label: 'A.php', file: true }, snapshot: 's1' })).toEqual({ sub: 'file-detail', args: ['src/A.php'], timeoutMs: 20_000, root: ROOT })
    expect(await LOADERS.detail.ask(io, { shown: { name: 'App\\A', label: 'A' }, snapshot: 's1' })).toEqual({ sub: 'component-detail', args: ['App\\A'], timeoutMs: 20_000, root: undefined })
  })

  it('store an answer only for what the cell still shows', () => {
    const parsed = { status: 'ok' } as never
    expect(LOADERS.route.landed({ ...w, phase: 'loading', answer: null }, w, parsed)).toEqual({ ...w, phase: 'done', answer: parsed })
    const moved = { ...w, to: 'hooks', phase: 'loading' as const, answer: null }
    expect(LOADERS.route.landed(moved, w, parsed)).toBe(moved)
    expect(LOADERS.route.landed(null, w, parsed)).toBeNull()
  })
})
