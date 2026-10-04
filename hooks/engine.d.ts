// A stand-in for the module the Claude Code engine provides at run time, so a
// static scan of this repository resolves register.tsx's imports instead of
// reporting them as missing. The engine supplies the real declarations when it
// loads the mod; nothing here is shipped with the plugin.
/* eslint-disable @typescript-eslint/no-explicit-any -- the engine owns these shapes; a scan only needs the names to resolve */
declare module 'claude-code' {
  export type Elements = any
  export type EngineInterface = any
  export type Register = any
  export type RenderNode = any
  export type RenderSurface = any
  export type Timer = any
  export function atom<T>(initial: T, options?: any): any
  export function read(...args: any[]): any
  export function update(...args: any[]): any
}
