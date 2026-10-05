import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync, symlinkSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it } from 'vitest'

const SCRIPT = join(dirname(fileURLToPath(import.meta.url)), 'pane-preview.mjs')
const CAPTURES = join(dirname(fileURLToPath(import.meta.url)), '..', 'docs', 'images', 'claude-code')
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

  it('refuses an output in the real captures, through a symlink to them or to a dir above them, and writes nothing there', () => {
    scratch = mkdtempSync(join(tmpdir(), 'knossos-stale-preview-'))
    const before = readdirSync(CAPTURES).sort()
    const data = join(scratch, 'data')
    mkdirSync(data)
    symlinkSync(CAPTURES, join(scratch, 'shots'))
    symlinkSync(dirname(CAPTURES), join(scratch, 'images'))
    for (const out of [join(scratch, 'shots'), join(scratch, 'shots', 'new', 'deeper'), join(scratch, 'images', 'claude-code'), CAPTURES]) {
      const run = spawnSync(process.execPath, [SCRIPT, `--data-dir=${data}`, `--out=${out}`], { encoding: 'utf8' })
      expect(run.status, out).toBe(2)
      expect(run.stderr).toContain('refusing to write')
    }
    expect(readdirSync(CAPTURES).sort()).toEqual(before)
  })

  it('refuses a dangling symlink into the captures (a dir not made yet), a relative one, and a loop, and writes nothing there', () => {
    scratch = mkdtempSync(join(tmpdir(), 'knossos-stale-preview-'))
    const before = readdirSync(CAPTURES).sort()
    const data = join(scratch, 'data')
    mkdirSync(data)
    symlinkSync(join(CAPTURES, 'newdir'), join(scratch, 'dangling'))
    symlinkSync(relative(scratch, join(CAPTURES, 'other', 'deeper')), join(scratch, 'relative'))
    symlinkSync(join(scratch, 'dangling'), join(scratch, 'chained'))
    symlinkSync(join(scratch, 'loop-b'), join(scratch, 'loop-a'))
    symlinkSync(join(scratch, 'loop-a'), join(scratch, 'loop-b'))
    for (const out of [join(scratch, 'dangling'), join(scratch, 'dangling', 'x'), join(scratch, 'relative'), join(scratch, 'chained'), join(scratch, 'loop-a')]) {
      const run = spawnSync(process.execPath, [SCRIPT, `--data-dir=${data}`, `--out=${out}`], { encoding: 'utf8' })
      expect(run.status, out).toBe(2)
      expect(run.stderr, out).toContain('refusing to write')
    }
    expect(readdirSync(CAPTURES).sort()).toEqual(before)
    expect(existsSync(join(CAPTURES, 'newdir'))).toBe(false)
  })

  it('refuses `..` after a symlink (cap/../claude-code/x) once the OS has resolved it, and removes the dirs it made there', () => {
    scratch = mkdtempSync(join(tmpdir(), 'knossos-stale-preview-'))
    const before = readdirSync(CAPTURES).sort()
    const data = join(scratch, 'data')
    mkdirSync(data)
    symlinkSync(CAPTURES, join(scratch, 'cap'))
    for (const out of [`${scratch}/cap/../claude-code/x`, `${scratch}/cap/../claude-code/x/y/z`, `${scratch}/cap/../claude-code`]) {
      const run = spawnSync(process.execPath, [SCRIPT, `--data-dir=${data}`, `--out=${out}`], { encoding: 'utf8' })
      expect(run.status, out).toBe(2)
      expect(run.stderr, out).toContain('refusing to write')
      expect(readdirSync(CAPTURES).sort()).toEqual(before)
    }
  })
})
