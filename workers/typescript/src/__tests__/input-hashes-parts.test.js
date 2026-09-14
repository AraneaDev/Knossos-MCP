import { describe, expect, it } from "vitest";

import {
    INPUT_HASHES_PART_BYTES,
    inputHashesParts,
} from "../input-hashes-parts.js";

const HASH = "a".repeat(64);

function bytesOf(part) {
    return Buffer.byteLength(JSON.stringify(part));
}

describe("inputHashesParts", () => {
    it("returns one empty part when nothing was read, so the result still carries the field", () => {
        expect(inputHashesParts({})).toEqual([{}]);
    });

    it("keeps a map that fits one frame whole", () => {
        const map = { "src/a.ts": HASH, "src/b.ts": null };

        expect(inputHashesParts(map)).toEqual([map]);
    });

    it("splits a map into parts that each fit the budget exactly as serialized, and lose nothing", () => {
        const map = {};
        for (let i = 0; i < 500; ++i) {
            map[`src/dir-${i}/é-file-${i}.ts`] = i % 7 === 0 ? null : HASH;
        }

        const parts = inputHashesParts(map, 4_000);

        expect(parts.length).toBeGreaterThan(10);
        for (const [index, part] of parts.entries()) {
            expect(bytesOf(part)).toBeLessThanOrEqual(4_000);
            // Each part is full: the next part's first entry would not fit.
            if (index + 1 < parts.length) {
                const [nextKey, nextValue] = Object.entries(
                    parts[index + 1],
                )[0];
                expect(
                    bytesOf({ ...part, [nextKey]: nextValue }),
                ).toBeGreaterThan(4_000);
            }
        }
        expect(Object.assign({}, ...parts)).toEqual(map);
        expect(parts.flatMap((part) => Object.keys(part))).toEqual(
            Object.keys(map),
        );
    });

    it("fills a part to exactly the budget and not one byte past it", () => {
        const map = { "src/a.ts": HASH, "src/b.ts": null };
        const exact = bytesOf(map);

        expect(inputHashesParts(map, exact)).toEqual([map]);
        expect(inputHashesParts(map, exact - 1)).toEqual([
            { "src/a.ts": HASH },
            { "src/b.ts": null },
        ]);
    });

    it("sends an entry larger than the budget alone rather than dropping it", () => {
        const long = `src/${"x".repeat(300)}.ts`;
        const map = { [long]: HASH, "src/a.ts": HASH, [`${long}x`]: null };

        expect(inputHashesParts(map, 200)).toEqual([
            { [long]: HASH },
            { "src/a.ts": HASH },
            { [`${long}x`]: null },
        ]);
    });

    it("sizes the default part well under the core's 1,000,000-byte line cap", () => {
        expect(INPUT_HASHES_PART_BYTES).toBeLessThanOrEqual(500_000);
    });
});
