/** The path relative to the project root, or null when it lies outside it. */
export function relativise(root: string, path: string): string | null {
  const base = normalise(root).replace(/\/+$/, '')
  const full = normalise(path)
  return full.startsWith(base + '/') ? full.slice(base.length + 1) : null
}

function normalise(path: string): string {
  const out: string[] = []
  for (const part of path.split('/')) {
    if (part === '..') out.pop()
    else if (part !== '.' && part !== '') out.push(part)
  }
  return '/' + out.join('/')
}
