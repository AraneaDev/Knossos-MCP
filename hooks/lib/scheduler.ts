/**
 * Runs one job at a time. Requests during a run collapse into one rerun,
 * so a burst of dirty turns scans twice at most, never in parallel.
 */
export class SingleFlight {
  private current: Promise<void> | null = null
  private again = false

  constructor(private readonly run: () => Promise<void>) {}

  get isRunning(): boolean {
    return this.current !== null
  }

  request(): Promise<void> {
    if (this.current !== null) {
      this.again = true
      return this.current
    }
    this.current = this.loop().finally(() => {
      this.current = null
    })
    return this.current
  }

  private async loop(): Promise<void> {
    do {
      this.again = false
      await this.run()
    } while (this.again)
  }
}
