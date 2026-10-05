import { describe, expect, it } from 'vitest'
import { relativise } from './paths'

describe('relativise', () => {
  it('strips the project root', () => expect(relativise('/repo', '/repo/src/a.php')).toBe('src/a.php'))
  it('tolerates a trailing slash on the root', () => expect(relativise('/repo/', '/repo/a.php')).toBe('a.php'))
  it('rejects a sibling with a shared prefix', () => expect(relativise('/repo', '/repo2/a.php')).toBeNull())
  it('rejects a path outside the root', () => expect(relativise('/repo', '/etc/passwd')).toBeNull())
  it('keeps spaces and unicode', () => expect(relativise('/repo', '/repo/a b/ü.php')).toBe('a b/ü.php'))
  it('normalises dot segments', () => expect(relativise('/repo', '/repo/src/../a.php')).toBe('a.php'))
})
