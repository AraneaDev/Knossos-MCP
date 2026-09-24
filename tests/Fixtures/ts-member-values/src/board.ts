export interface PlayerBoard {
    tick(now: number): void;
    size(): number;
    idle(): void;
}

export function createPlayerBoard(): PlayerBoard {
    return {
        tick(now: number): void {
            void now;
        },
        size(): number {
            return 1;
        },
        idle(): void {},
    };
}
