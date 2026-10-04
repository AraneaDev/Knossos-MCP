import { describe, expect, it } from 'vitest'
import type { FileContext } from '../../types'
import { commitNote, contextAnswer, isGitCommit, TEXT_MAX } from './agent'

const context = (over: Partial<NonNullable<FileContext['file']>> = {}): FileContext => ({
  status: 'ok',
  file: {
    path: 'src/Query/DashboardService.php',
    language: 'php',
    lines: 439,
    boundary: 'core',
    components: 19,
    dependents: { count: 3, boundaries: ['core', 'tests'], top: ['tests/DashboardServiceTest.php', 'src/Cli/BriefCommand.php', 'tests/CouplingsTest.php'] },
    tests: { items: [{ path: 'tests/DashboardServiceTest.php', distance: 1 }, { path: 'tests/BriefCommandTest.php', distance: 3 }], more: false },
    commits: [{ rev: 'edac8ad', at: 1_791_115_340, subject: 'feat(dashboard): rank files by size times dependents' }],
    ...over,
  },
})

describe('the knossos_context answer', () => {
  it('says the boundary, rules, dependents, tests, commits and this session, one line each', () => {
    const text = contextAnswer('src/Query/DashboardService.php', context(), ['core may not depend on workers'], false, 'changed')
    expect(text.split('\n')).toEqual([
      'src/Query/DashboardService.php: boundary core, PHP, 439 lines, 19 components.',
      'Dependents: 3 files in core, tests; closest: tests/DashboardServiceTest.php, src/Cli/BriefCommand.php, tests/CouplingsTest.php.',
      'Rules: core may not depend on workers.',
      'Tests that reach it: tests/DashboardServiceTest.php (1 hop), tests/BriefCommandTest.php (3 hops).',
      expect.stringMatching(/^Latest commits: edac8ad 2026-10-0\d feat\(dashboard\): rank files by size times dependents\.$/),
      'This session changed it.',
    ])
  })

  it('says what it does not know, and stays short whatever the lists hold', () => {
    const lonely = contextAnswer('a.php', context({ dependents: { count: 0, boundaries: [], top: [] }, tests: { items: [], more: false }, commits: [], boundary: null }), [], true, null)
    expect(lonely).toContain('Dependents: no other file depends on it.')
    expect(lonely).toContain('Rules: no declared rule binds it. Other rules may bind it; run check_architecture to be sure.')
    expect(lonely).toContain('Tests: no test reaches it.')
    expect(lonely).toContain('This session has not changed it.')
    expect(lonely).not.toContain('Latest commits')
    const many = contextAnswer('a.php', context({ dependents: { count: 900, boundaries: Array.from({ length: 9 }, (_, i) => `b${i}`), top: Array.from({ length: 5 }, (_, i) => `f${i}.php`) } }), [], false, null)
    expect(many).toContain('Dependents: 900 files in b0, b1, b2, b3, b4, and 4 more; closest: f0.php, f1.php, f2.php, f3.php, f4.php, and 895 more.')
    const huge = contextAnswer('a.php', context({ commits: Array.from({ length: 3 }, () => ({ rev: 'abc1234', at: 0, subject: 'x'.repeat(2_000) })) }), [], false, null)
    expect(huge.length).toBeLessThanOrEqual(TEXT_MAX)
    expect(huge).toMatch(/cut short\)$/)
    expect(contextAnswer('nope.md', { status: 'not-found', file: null }, [], false, null)).toContain('is not in the graph')
    expect(contextAnswer('a.php', null, [], false, null)).toContain('knossos did not answer')
  })
})

describe('a commit', () => {
  it('is told from other git commands, wherever it stands in a command line', () => {
    for (const yes of ['git commit -m x', 'git add . && git commit -m "x"', 'git -C /repo commit --amend', 'cd a; git --no-pager commit -qm x', '(git commit)']) expect(isGitCommit(yes), yes).toBe(true)
    for (const no of ['git status', 'git log --grep commit', 'git commit-tree abc', 'gitcommit', 'echo committed', 'git show HEAD']) expect(isGitCommit(no), no).toBe(false)
  })

  it('gets a note of what it carries, or none when it carries nothing the graph knows of', () => {
    expect(commitNote([], [], { count: 0, chains: [] })).toBeNull()
    expect(commitNote(['A → B'], ['src/Kernel.php', 'src/Config.php'], { count: 1, chains: ['A → B → A'] })).toBe(
      'knossos: this commit carries 1 boundary-policy violation this session introduced (A → B); 2 changed files no test reaches (src/Kernel.php, src/Config.php); 1 dependency cycle new since the session began (A → B → A). Check them before you push.',
    )
    expect(commitNote([], Array.from({ length: 8 }, (_, i) => `f${i}`), { count: 0, chains: [] })).toBe('knossos: this commit carries 8 changed files no test reaches (f0, f1, f2, f3, f4, and 3 more). Check them before you push.')
  })
})
