import { annotated, exportedArrow, typedAs } from "./bindings";

export function callIt() {
  return exportedArrow() + typedAs() + annotated(1);
}
