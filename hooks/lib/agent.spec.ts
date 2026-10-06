import { describe, expect, it } from 'vitest'
import { commitNote, committedSince, madeCommit, stillReported } from './agent'

describe('a commit', () => {
  it('is told by the line git prints for a commit, never by the command text', () => {
    for (const yes of ['[main abc1234] feat: route\n 1 file changed, 2 insertions(+)', 'hint\n[main (root-commit) 0a1b2c3d] first\n', '[detached HEAD 1234567] wip', '[feat/claude-code-mod ce1ad2d] fix(query): x'])
      expect(madeCommit(yes), yes).toBe(true)
    // What a grep, an echo, a gh call or a dry run print: `git commit` in the text, no commit made.
    for (const no of [
      'src/a.sh:3: git commit -m "x"',
      'git commit -m x',
      'https://github.com/o/r/pull/7',
      'On branch main\nChanges to be committed:\n\tmodified:   a.php',
      'nothing to commit, working tree clean',
      '[main] not a sha',
      'error: pathspec [main abc1234] x did not match',
    ])
      expect(madeCommit(no), no).toBe(false)
    // A subject may start with spaces.
    expect(madeCommit('[main abc1234]   indented')).toBe(true)
  })

  it("is told by the project's HEAD moving to a commit its reflog names, never by what the command printed", () => {
    const A = 'a'.repeat(40)
    const B = 'b'.repeat(40)
    const C = 'c'.repeat(40)
    const log = (...entries: [string, string][]) => entries.map(([sha, subject]) => `${sha}\x1f${subject}\n`).join('')
    const line = '[main abc1234] x'
    // HEAD did not move: nothing was committed here, whatever was printed; no repository, no commit.
    expect(committedSince(A, A, '', line)).toBe(false)
    expect(committedSince(null, null, '', line)).toBe(false)
    expect(committedSince(A, null, '', line)).toBe(false)
    // HEAD moved to a commit.
    for (const subject of ['commit: x', 'commit (amend): x', 'commit (initial): x', 'commit (merge): x', "merge topic: Merge made by the 'ort' strategy.", 'cherry-pick: x', 'revert: Revert "x"'])
      expect(committedSince(A, B, log([B, subject], [A, 'commit: before']), ''), subject).toBe(true)
    // HEAD moved without one.
    for (const subject of ['checkout: moving from a to b', 'reset: moving to HEAD~1', 'merge origin/main: Fast-forward', 'pull: Fast-forward', 'rebase (finish): returning to refs/heads/x'])
      expect(committedSince(A, B, log([B, subject], [A, 'commit: before']), line), subject).toBe(false)
    // A commit, then a checkout: an entry newer than the HEAD before it is a commit.
    expect(committedSince(A, C, log([C, 'checkout: moving from topic to main'], [B, 'commit: x'], [A, 'checkout: moving from main to topic']), '')).toBe(true)
    // An older commit, before the HEAD the command began at, is not this command's.
    expect(committedSince(A, C, log([C, 'checkout: moving from main to old'], [A, 'commit: earlier']), '')).toBe(false)
    // The first commit on a branch that had none.
    expect(committedSince('', A, log([A, 'commit (initial): x']), '')).toBe(true)
    // No reflog: git's own line decides.
    expect(committedSince(A, B, '', line)).toBe(true)
    expect(committedSince(A, B, '', "Switched to branch 'main'")).toBe(false)
  })

  it('carries only the violations the policy check still reports, unless the check cannot say', () => {
    const policy = (items: { source: string; target: string }[], over: { truncated?: boolean; total?: number; status?: string } = {}) => ({ status: 'evaluated', truncated: false, total: items.length, items, ...over })
    const at = { 'A → B': 's2', 'C → D': 's2' }
    const d = (p: ReturnType<typeof policy> | undefined) => ({ snapshot_id: 's2', trend: [{ snapshot_id: 's1' }, { snapshot_id: 's2' }], ...(p === undefined ? {} : { policy: p }) })
    expect(stillReported(['A → B', 'C → D'], at, d(policy([{ source: 'A', target: 'B' }])))).toEqual(['A → B'])
    expect(stillReported(['A → B', 'C → D'], at, d(policy([], { truncated: true })))).toEqual(['A → B', 'C → D'])
    expect(stillReported(['A → B'], at, d(policy([], { total: 3 })))).toEqual(['A → B'])
    expect(stillReported(['A → B'], at, d(policy([], { status: 'skipped' })))).toEqual(['A → B'])
    expect(stillReported(['A → B'], at, d(undefined))).toEqual(['A → B'])
  })

  it('drops a violation only by a policy check at least as new as the brief that recorded it', () => {
    const empty = { status: 'evaluated', truncated: false, total: 0, items: [] }
    // A dashboard newer than the brief: its trend passes through the brief's snapshot, and the violation is fixed.
    expect(stillReported(['A → B'], { 'A → B': 's2' }, { snapshot_id: 's3', trend: [{ snapshot_id: 's2' }, { snapshot_id: 's3' }], policy: empty })).toEqual([])
    // A dashboard still at an earlier snapshot (its reload failed, or has not landed) never saw it.
    expect(stillReported(['A → B'], { 'A → B': 's2' }, { snapshot_id: 's1', trend: [{ snapshot_id: 's0' }, { snapshot_id: 's1' }], policy: empty })).toEqual(['A → B'])
    // Recorded without a snapshot: nothing says the check is newer.
    expect(stillReported(['A → B'], {}, { snapshot_id: 's3', trend: [{ snapshot_id: 's3' }], policy: empty })).toEqual(['A → B'])
    expect(stillReported(['A → B'], undefined, { snapshot_id: 's3', trend: [], policy: empty })).toEqual(['A → B'])
  })

  it('gets a note of what it carries, or none when it carries nothing the graph knows of', () => {
    expect(commitNote([], [], { count: 0, chains: [] })).toBeNull()
    expect(commitNote(['A → B'], ['src/Kernel.php', 'src/Config.php'], { count: 1, chains: ['A → B → A'] })).toBe(
      'knossos: this session\'s changes carry 1 boundary-policy violation this session introduced (A → B); 2 changed files no test reaches (src/Kernel.php, src/Config.php); 1 dependency cycle new since the session began (A → B → A). Check them before you push.',
    )
    expect(commitNote([], Array.from({ length: 8 }, (_, i) => `f${i}`), { count: 0, chains: [] })).toBe("knossos: this session's changes carry 8 changed files no test reaches (f0, f1, f2, f3, f4, and 3 more). Check them before you push.")
  })
})
