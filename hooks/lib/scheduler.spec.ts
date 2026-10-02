import { describe, expect, it } from 'vitest'
import { SingleFlight } from './scheduler'

describe('SingleFlight', () => {
  it('never runs two at once and reruns once for any number of requests during a run', async () => {
    let running = 0, maxRunning = 0, runs = 0
    let release!: () => void
    const flight = new SingleFlight(async () => {
      running++; runs++; maxRunning = Math.max(maxRunning, running)
      if (runs === 1) await new Promise<void>(r => (release = r))
      running--
    })
    const first = flight.request()
    flight.request(); flight.request(); flight.request()
    release()
    await first
    await flight.request()
    expect(maxRunning).toBe(1)
    expect(runs).toBe(3) // first, one coalesced rerun, the final request
  })
  it('recovers after a run throws', async () => {
    let calls = 0
    const flight = new SingleFlight(async () => { calls++; if (calls === 1) throw new Error('x') })
    await flight.request().catch(() => undefined)
    await flight.request()
    expect(calls).toBe(2)
    expect(flight.isRunning).toBe(false)
  })
})
