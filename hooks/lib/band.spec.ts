import { describe, expect, it } from 'vitest'
import { bandModel, formatAge, freshnessLine } from './band'
import type { Dashboard, TurnBrief } from './envelopes'

const ok = (over: Partial<TurnBrief> = {}): TurnBrief => ({
  status: 'ok', project_root: '/r', project_id: 'p', snapshot_id: 's', scanned_at: 1_000, scan_ms: 5, reason: null,
  roots_file: null, refused_root: null, path: '/r',
  changed_files: ['a.php'], added_files: [], deleted_files: [],
  impact: { 'a.php': { path: 'a.php', dependent_files: 37, boundaries: ['Core', 'Http'] } },
  tests: [{ path: 't/A.php', distance: 1 }],
  policy: { status: 'evaluated', total: 0, violations: [], truncated: false }, ...over,
})
const idle = { phase: 'idle' as const, lastAttemptAt: 1_000_000 }

describe('formatAge', () => {
  it('seconds', () => expect(formatAge(12_000)).toBe('12s'))
  it('minutes', () => expect(formatAge(14 * 60_000)).toBe('14m'))
  it('hours', () => expect(formatAge(3 * 3_600_000)).toBe('3h'))
})

describe('bandModel', () => {
  it('summarises a clean turn with its age', () => {
    expect(bandModel(ok(), idle, 1_000_000 + 12_000)).toEqual({
      tone: 'normal',
      text: 'knossos · 1 file → 37 dependents · Core, Http · 1 test · as of 12s ago',
      showDetails: true,
    })
  })
  it('is red with violations', () => {
    const m = bandModel(ok({ policy: { status: 'evaluated', total: 2, violations: [] } }), idle, 1_012_000)
    expect(m?.tone).toBe('alert')
    expect(m?.text).toContain('2 policy violations')
  })
  it('says scanning and keeps the old figures', () => {
    const m = bandModel(ok(), { phase: 'scanning', lastAttemptAt: 1_000_000 }, 1_005_000)
    expect(m?.text).toBe('knossos · scanning… · last: 1 file → 37 dependents · Core, Http · 1 test · as of 5s ago')
  })
  it('says the scan failed and how old the figures are', () => {
    const m = bandModel(ok(), { phase: 'failed', lastAttemptAt: 1_000_000 + 14 * 60_000 }, 1_000_000 + 14 * 60_000)
    expect(m?.tone).toBe('warn')
    expect(m?.text).toContain('scan failed, figures from 14m ago')
  })
  it('lists deletions', () => {
    const m = bandModel(ok({ changed_files: [], impact: {}, deleted_files: ['gone.php'] }), idle, 1_000_000)
    expect(m?.text).toContain('1 deleted')
  })
  it('offers the allow-root command when not allowed', () => {
    const m = bandModel(ok({ status: 'not-allowed', path: '/r x' }), idle, 1_000_000)
    expect(m?.text).toBe("knossos · not an allowed root: knossos allow-root '/r x' --execute")
  })
  it('names the roots file the brief read and the root it refused', () => {
    const m = bandModel(
      ok({ status: 'not-allowed', path: '/r/src', roots_file: "/data/it's/roots.json", refused_root: '/r' }),
      idle,
      1_000_000,
    )
    expect(m?.text).toBe(
      "knossos · not an allowed root: KNOSSOS_ROOTS_FILE='/data/it'\\''s/roots.json' knossos allow-root '/r' --execute",
    )
  })
  it('says the first scan failed', () =>
    expect(bandModel(null, { phase: 'failed', lastAttemptAt: 1 }, 2)).toEqual({ tone: 'warn', text: 'knossos · scan failed', showDetails: false }))
  it('shows a scan-failed brief with its reason', () =>
    expect(bandModel(ok({ status: 'scan-failed', reason: 'boom' }), idle, 1_000_000)).toEqual({ tone: 'warn', text: 'knossos · scan failed: boom', showDetails: false }))
  it('shows a scan-failed brief without a reason', () =>
    expect(bandModel(ok({ status: 'scan-failed', reason: null }), idle, 1_000_000)?.text).toBe('knossos · scan failed'))
  it('omits the age when the scan time is unknown', () =>
    expect(bandModel(ok({ scanned_at: null }), idle, 1_000_000)?.text).toBe('knossos · 1 file → 37 dependents · Core, Http · 1 test'))
  it('draws nothing before the first brief', () => expect(bandModel(null, idle, 0)).toBeNull())
  it('draws nothing after a turn that changed nothing', () =>
    expect(bandModel(ok({ changed_files: [], impact: {}, tests: [] }), idle, 1_000_000)).toBeNull())
})

describe('freshnessLine', () => {
  const dash = (age: number | null): Dashboard =>
    ({ status: 'ok', freshness: { state: 'fresh', age_seconds: age, drift_files: 2 } }) as Dashboard
  it('shows the snapshot age and keeps counting after the refresh', () => {
    expect(freshnessLine(dash(30), { fetchedAt: 1_000_000, failed: false }, 1_000_000)).toBe(
      'snapshot fresh, 30s old · 2 files drifted',
    )
    expect(freshnessLine(dash(30), { fetchedAt: 1_000_000, failed: false }, 1_000_000 + 60_000)).toBe(
      'snapshot fresh, 2m old · 2 files drifted',
    )
  })
  it('says when the refresh failed and how old the figures are', () =>
    expect(freshnessLine(dash(30), { fetchedAt: 1_000_000, failed: true }, 1_000_000 + 15_000)).toBe(
      'refresh failed, figures from 45s ago · snapshot fresh · 2 files drifted',
    ))
  it('leaves the age out when the snapshot has none', () =>
    expect(freshnessLine(dash(null), { fetchedAt: null, failed: false }, 5)).toBe('snapshot fresh · 2 files drifted'))
})
