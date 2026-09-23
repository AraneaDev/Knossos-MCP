// What the framework's package declares for its runes, reduced to what the
// fixture uses. Reached only as the installed `svelte` package would be.
declare function $state<T>(initial: T): T;
declare function $derived<T>(expression: T): T;
declare function $props<T = any>(): T;
declare namespace $props {
  function id(): string;
}
