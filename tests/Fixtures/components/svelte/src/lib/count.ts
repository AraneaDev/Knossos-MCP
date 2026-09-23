import { writable } from './store';
export const count = writable(0);
export function increment(): void {}
