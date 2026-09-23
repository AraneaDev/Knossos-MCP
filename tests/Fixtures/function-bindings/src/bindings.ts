import type { Handler } from "./handler";

class Widget {}

function memo<T>(value: T): T {
  return value;
}

export const exportedArrow = () => helper();
const helper = function () {
  return new Widget();
};
let reassignable = () => 1;
var legacy = function () {
  return 2;
};
export const typedAs = (() => 3) as () => number;
export const typedSatisfies = (() => 4) satisfies () => number;
export const make = (): Handler => ({
  run() {
    return 5;
  },
});
export const api = {
  run() {
    return 6;
  },
};
export function outer() {
  const inner = () => 7;
  return inner();
}
export const wrapped = memo(() => 8);
const unusedArrow = () => 9;
export const pairFn = () => 10,
  pairValue = 11;
const { length } = "abc";
declare const ambientFn: () => void;
export const mapped = [1].map(helper);
export const annotated: (n: number) => number = (n) => n;
export const load = () => import("./handler");
using disposer = (() => {}) as any;
export type parsed = string;
export const parsed = () => "";
export type area = number;
export function area(): number {
  return 1;
}
export interface Gadget {
  size?: number;
}
export class Gadget {}
export default () => 12;
export { reassignable, legacy, length, ambientFn };
