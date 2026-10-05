// The host supplies this module at run time; nothing installs it.
declare module 'host-engine' {
  export function atom(initial: number): number
}
