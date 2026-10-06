/**
 * Text from a scanned repository made safe to draw. Every C0 control except
 * tab and newline, DEL and every C1 control becomes U+FFFD: an ESC or a CSI
 * left in a path or a name reaches the terminal as an escape sequence, which
 * can move the cursor, repaint the screen or write the clipboard.
 */
// eslint-disable-next-line no-control-regex -- matching control characters is the point
const CONTROL = /[\u0000-\u0008\u000b-\u001f\u007f-\u009f]/g

export function printable(text: string): string {
  return text.replace(CONTROL, '�')
}

/** printable() applied to every string in parsed JSON, keys included. */
export function printableDeep(value: unknown): unknown {
  if (typeof value === 'string') return printable(value)
  if (Array.isArray(value)) return value.map(printableDeep)
  if (typeof value === 'object' && value !== null) {
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [printable(key), printableDeep(item)]))
  }
  return value
}
