import { describe, expect, it } from 'vitest'
import type { Dashboard, KnossosView, SessionLedger } from '../../types'
import { FLASH_BG, FLASH_MS, flashKeys, ledgerFlashKeys, litAt } from './flash'
import { paneInput } from './layout'
import { paneRows } from './__tests__/pane'
import { findRow } from './__tests__/plain-text'
import { rowWidth } from './rows'

const hub = (name: string, inDegree: number) => ({ name, canonical_name: `App\\${name}`, kind: 'class', boundary: 'core', in_degree: inDegree, out_degree: 1, cross_boundary_degree: 0, dependent_files: inDegree })
const dash = (snapshot: string, over: Partial<Dashboard> = {}): Dashboard => ({
  status: 'ok',
  path: '/work/app',
  project_root: '/work/app',
  project_id: 'p1',
  snapshot_id: snapshot,
  freshness: { state: 'fresh', age_seconds: 1, drift_files: 0 },
  hubs: [hub('Router', 40), hub('Kernel', 30)],
  hubs_truncated: false,
  hubs_truncation_reasons: [],
  hotspots: [],
  dead_code_candidates: 3,
  dead_code_truncated: false,
  cycles: { count: 1, truncated: false, truncation_reasons: [], largest: [] },
  trend: [{ snapshot_id: snapshot, cycles: 1, max_degree: 40 }],
  fan_in: [{ path: 'src/Router.php', dependent_files: 40, boundaries: ['core'], boundary: 'core' }],
  fan_in_truncated: false,
  ...over,
})
const view = (over: Partial<KnossosView> = {}): KnossosView => ({ inspect: null, isBandHidden: false, tab: 'hubs', selected: 0, showKeys: false, filter: '', filtering: false, sort: 'in', ...over })

describe('rows the latest scan changed', () => {
  const before = dash('s1')
  const after = dash('s2', { hubs: [hub('Router', 40), hub('Kernel', 31), hub('Cache', 25)], fan_in: [{ path: 'src/Router.php', dependent_files: 41, boundaries: ['core'], boundary: 'core' }], cycles: { count: 2, truncated: false, truncation_reasons: [], largest: [] } })

  it('are the components, files and tiles whose figures moved, or that are new', () => {
    expect(flashKeys(before, after).sort()).toEqual(['file:src/Router.php', 'hub:App\\Cache', 'hub:App\\Kernel', 'tile:cycles'])
    // The same snapshot, the first load, or a dashboard with no figures lights nothing.
    expect(flashKeys(before, { ...after, snapshot_id: 's1' })).toEqual([])
    expect(flashKeys(null, after)).toEqual([])
    expect(flashKeys({ ...before, status: 'error' }, after)).toEqual([])
  })

  it('are lit until the flash is over, then not', () => {
    const flash = { keys: flashKeys(before, after), until: 1_000 + FLASH_MS }
    expect([...litAt(flash, 1_000)]).toHaveLength(4)
    expect(litAt(flash, 1_000 + FLASH_MS).size).toBe(0)
    expect(litAt(null, 0).size).toBe(0)
  })

  it('draw on the flash ground, the marked row keeping its own, at every width', () => {
    const flash = { keys: flashKeys(before, after), until: 5_000 }
    for (const columns of [40, 60, 80, 100, 130, 140, 200]) {
      for (const height of [24, 40, 60]) {
        const rows = paneRows(paneInput(after, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view(), 1_000, true, null, null, undefined, null, undefined, { flash }), columns, height)
        for (const r of rows) expect(rowWidth(r), `${columns}x${height} ${r.key}`).toBeLessThanOrEqual(columns)
        // Router is marked: it keeps the marked ground; Kernel and Cache moved.
        // Side by side on a wide grid a row is two cards' rows: the lit one's own cells carry the ground.
        const ground = (key: string) => findRow(rows, key)?.segments.some(s => s.bg === FLASH_BG && s.text.includes(key === 'hub-1' ? 'Kernel' : key === 'hub-2' ? 'Cache' : 'Router'))
        expect(ground('hub-0'), `${columns}`).toBe(false)
        expect(ground('hub-1'), `${columns}`).toBe(true)
        expect(ground('hub-2'), `${columns}`).toBe(true)
      }
    }
    const later = paneRows(paneInput(after, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view(), 6_000, true, null, null, undefined, null, undefined, { flash }), 100)
    expect(findRow(later, 'hub-1')?.segments.some(s => s.bg === FLASH_BG)).toBe(false)
    // The cycles tile is lit on the Overview.
    const tiles = paneRows(paneInput(after, null, { fetchedAt: 0, failed: false }, { phase: 'idle', reason: null }, view({ tab: 'overview' }), 1_000, true, null, null, undefined, null, undefined, { flash }), 140)
    expect(tiles.flatMap(r => r.segments).find(s => s.text === '2' && s.bold === true)?.bg).toBe(FLASH_BG)
  })

  it('in the session changes are the files a newer read holds anew or a newer scan took in', () => {
    const ledger = (files: SessionLedger['files']): SessionLedger => ({ status: 'ok', since: 's0', complete: true, files, files_truncated: false, tests: [], tests_truncated: false })
    const one = ledger({ 'a.php': { status: 'changed', dependents: 2, boundaries: [], boundary: null, scans: ['s1'] }, 'b.php': { status: 'added', dependents: 0, boundaries: [], boundary: null, scans: ['s1'] } })
    const two = ledger({ 'a.php': { status: 'changed', dependents: 2, boundaries: [], boundary: null, scans: ['s1', 's2'] }, 'b.php': { status: 'added', dependents: 0, boundaries: [], boundary: null, scans: ['s1'] }, 'c.php': { status: 'changed', dependents: 1, boundaries: [], boundary: null, scans: ['s2'] } })
    expect(ledgerFlashKeys(one, two)).toEqual(['change:a.php', 'change:c.php'])
    expect(ledgerFlashKeys(null, two)).toEqual([])
    expect(ledgerFlashKeys({ ...one, since: 'other' }, two)).toEqual([])
  })
})
