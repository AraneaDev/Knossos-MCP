// The shared global, as the framework's client types declare it: every
// member open, props an untyped record.
interface AstroGlobal {
  props: Record<string, any>;
  [member: string]: any;
}
declare const Astro: Readonly<AstroGlobal>;
