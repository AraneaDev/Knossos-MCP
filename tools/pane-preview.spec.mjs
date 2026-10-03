import { spawnSync } from 'node:child_process'
import { existsSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it } from 'vitest'

const SCRIPT = join(dirname(fileURLToPath(import.meta.url)), 'pane-preview.mjs')
let scratch = null

afterEach(() => {
  if (scratch !== null) rmSync(scratch, { recursive: true, force: true })
  scratch = null
})

describe('pane-preview', () => {
  it('refuses to run without --data-dir, even with KNOSSOS_DATA_DIR set: it never picks a database to write on its own', () => {
    scratch = mkdtempSync(join(tmpdir(), 'knossos-stale-preview-'))
    const out = join(scratch, 'out')
    const run = spawnSync(process.execPath, [SCRIPT, `--out=${out}`], {
      encoding: 'utf8',
      env: { ...process.env, KNOSSOS_DATA_DIR: join(scratch, 'data') },
    })
    expect(run.status).toBe(2)
    expect(run.stderr).toContain('--data-dir=<dir> is required')
    expect(existsSync(out)).toBe(false)
    expect(existsSync(join(scratch, 'data'))).toBe(false)
  })

  it('refuses the README screenshots without --data-dir too, and writes no image', () => {
    scratch = mkdtempSync(join(tmpdir(), 'knossos-stale-preview-'))
    const out = join(scratch, 'images')
    const run = spawnSync(process.execPath, [SCRIPT, '--readme', `--out=${out}`], { encoding: 'utf8' })
    expect(run.status).toBe(2)
    expect(run.stderr).toContain('--data-dir=<dir> is required')
    expect(existsSync(out)).toBe(false)
  })

  it('refuses the README screenshots without the snapshot and git revision its real changes are read from', () => {
    scratch = mkdtempSync(join(tmpdir(), 'knossos-stale-preview-'))
    const out = join(scratch, 'out')
    const run = spawnSync(process.execPath, [SCRIPT, '--readme', `--data-dir=${join(scratch, 'data')}`, `--out=${out}`], { encoding: 'utf8' })
    expect(run.status).toBe(2)
    expect(run.stderr).toContain('--since=<snapshot> and --session-rev=<git rev>')
    expect(existsSync(out)).toBe(false)
  })
})
