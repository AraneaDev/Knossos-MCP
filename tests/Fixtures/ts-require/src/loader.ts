declare const require: (id: string) => unknown;

let pending: Promise<typeof import('./lazy-service')> | undefined;

// The promise is kept, awaited later, and a named export taken from it.
export async function respond(): Promise<string> {
    pending ??= import('./lazy-service');
    const { getSpiderResponse: fn } = await pending;
    return fn();
}

// An inline type hides the module; the member read still names its export.
export function route(): () => string {
    const service = require('./lazy-service') as { handleSse: () => string };
    return service.handleSse;
}

let onMessage: (() => string) | undefined;

// Assigned to a variable declared elsewhere, as a handler is wired up.
export function wire(): void {
    const service = require('./lazy-service') as { handleMessage: () => string };
    onMessage = service.handleMessage;
}

// The module is outside the program (a tsconfig `exclude`), so only its path
// and the member's name say what is read.
export function excluded(): () => string {
    const server = require('./lazy/server') as { handle: () => string };
    return server.handle;
}
