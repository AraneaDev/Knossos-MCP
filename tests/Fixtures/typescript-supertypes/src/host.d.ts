// A dependency's base class, declared the way an installed package's types are.
declare module 'host-visitors' {
  export abstract class Visitor {
    enter(node: object): void
    leave(node: object): void
  }
}
