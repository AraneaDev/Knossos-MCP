import { Visitor } from 'host-visitors'

/** Fulfils members of a dependency's base class and of a built-in interface. */
export class Walker extends Visitor implements Iterator<number> {
  enter(node: object): void {
    void node
  }

  override leave(node: object): void {
    void node
  }

  next(): IteratorResult<number> {
    return { done: true, value: undefined }
  }

  helper(): number {
    return 1
  }
}

/** A static member shares an instance member's name and fulfils nothing. */
export class Factory extends Visitor {
  static enter(): number {
    return 1
  }
}

interface Hooks {
  resolve(specifier: string): string
}

declare function register(hooks: Hooks): void

/** A literal handed to a typed parameter fulfils that type's members. */
register({
  resolve(specifier) {
    return specifier
  },
})
