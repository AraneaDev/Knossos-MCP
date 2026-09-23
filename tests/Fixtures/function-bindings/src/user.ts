import {
  annotated,
  area,
  exportedArrow,
  Gadget,
  parsed,
  typedAs,
} from "./bindings";

export function callIt() {
  new Gadget();
  return exportedArrow() + typedAs() + annotated(1) + parsed() + area();
}
