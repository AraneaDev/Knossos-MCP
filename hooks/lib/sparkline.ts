const LEVELS = '▁▂▃▄▅▆▇█'

/** Eight-level block glyphs, scaled to the series' own range. */
export function sparkline(values: number[]): string {
  if (values.length === 0) return ''
  const min = Math.min(...values), max = Math.max(...values)
  if (max === min) return LEVELS[3].repeat(values.length)
  return values.map(v => LEVELS[Math.round(((v - min) / (max - min)) * 7)]).join('')
}
