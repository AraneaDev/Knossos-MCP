declare const require: (id: string) => unknown;

export function createBackend(): { read(): string } {
    // Loaded lazily, so the module is only required when a backend is made.
    const { LocalStorageBackend } = require('./local') as typeof import('./local');
    return new LocalStorageBackend();
}

export function loadServer(): unknown {
    // A module the tsconfig leaves out of the program is still on disk.
    return require('./lazy/server');
}

export function missing(): unknown {
    return require('./absent');
}
