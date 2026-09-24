import { createPlayerBoard, type PlayerBoard } from './board';

// A wrapper hands the board's own methods on as its fields.
export function wrap(): { tick: PlayerBoard['tick']; size: () => number } {
    const board: PlayerBoard = createPlayerBoard();
    return { tick: board.tick, size: () => board.size() };
}
