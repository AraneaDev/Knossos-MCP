import { describe, expect, it } from 'vitest'
import { activeBetween, begin, counts, finish, lookbackMs, noActivity, scanWindow, WAITING_TOOLS } from './activity'

describe("the session's activity", () => {
  it('counts every tool that does work, in any loop, and none that only waits', () => {
    for (const tool of ['Bash', 'Edit', 'Write', 'NotebookEdit', 'Read', 'mcp__knossos__scan_project']) expect(counts(tool)).toBe(true)
    for (const tool of WAITING_TOOLS) expect(counts(tool)).toBe(false)
  })

  it('is active while a call runs and over the span it ran, and idle around it', () => {
    const a = noActivity()
    begin(a, 'c1', 1_000)
    // Running: any window reaching past its start.
    expect(activeBetween(a, 0, 999)).toBe(false)
    expect(activeBetween(a, 0, 1_000)).toBe(true)
    expect(activeBetween(a, 50_000, 60_000)).toBe(true)
    finish(a, 'c1', 4_000)
    expect(a.running.size).toBe(0)
    expect(activeBetween(a, 4_000, 6_000)).toBe(true)
    expect(activeBetween(a, 2_000, 3_000)).toBe(true)
    expect(activeBetween(a, 4_001, 9_000)).toBe(false)
    expect(activeBetween(a, 0, 999)).toBe(false)
  })

  it('ends only a call it knows, and lets go of spans past their time and count', () => {
    const a = noActivity()
    finish(a, 'never', 10)
    expect(a.spans).toEqual([])
    for (let i = 0; i < 600; i++) {
      begin(a, `c${i}`, i)
      finish(a, `c${i}`, i + 1)
    }
    expect(a.spans).toHaveLength(500)
    expect(a.spans[0]!.start).toBe(100)
    begin(a, 'late', 11 * 60_000)
    finish(a, 'late', 11 * 60_000 + 5)
    expect(a.spans).toEqual([{ start: 11 * 60_000, end: 11 * 60_000 + 5 }])
  })

  it("looks back over the watcher's poll, its debounce and a grace", () => {
    expect(lookbackMs(1_000, 300)).toBe(2_800)
    expect(lookbackMs(250, 300)).toBe(2_050)
  })

  it('reaches back to the start of the scan before when that one ended inside the window', () => {
    // Idle before: the window is the lookback before the scan began, up to when it landed.
    expect(scanWindow(10_000, 12_000, 2_800, null)).toEqual({ from: 7_200, to: 12_000 })
    expect(scanWindow(10_000, 12_000, 2_800, { start: 1_000, end: 3_000 })).toEqual({ from: 7_200, to: 12_000 })
    // The scan before ran until 8 s: a change made while it ran (from 4 s) is noticed only now.
    expect(scanWindow(10_000, 12_000, 2_800, { start: 4_000, end: 8_000 })).toEqual({ from: 4_000, to: 12_000 })
  })
})
