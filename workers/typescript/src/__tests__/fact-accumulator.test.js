import { describe, it, expect } from "vitest";
import { FactAccumulator } from "../fact-accumulator.js";

// The accumulator calls `evidence(sourceFile, relative, node)` for every node
// and edge; a stub is enough since we only assert de-duplication and merging.
const stubEvidence = () => ({ path: "f.ts", start_line: 1, end_line: 1 });
const make = () => new FactAccumulator({}, "f.ts", stubEvidence);

describe("FactAccumulator nodes", () => {
    it("keeps the first node per id and ignores later duplicates", () => {
        const acc = make();
        acc.addNode("id1", "class", "Foo", "Foo", {});
        acc.addNode("id1", "class", "FooAgain", "FooAgain", {});
        expect(acc.nodes).toHaveLength(1);
        expect(acc.nodes[0]).toMatchObject({
            local_id: "id1",
            kind: "class",
            canonical_name: "Foo",
            origin: "ast",
            confidence: "certain",
        });
    });

    it("preserves a passed origin (e.g. framework_convention)", () => {
        const acc = make();
        acc.addNode("id2", "route", "R", "R", {}, {}, "framework_convention");
        expect(acc.nodes[0].origin).toBe("framework_convention");
    });
});

describe("FactAccumulator edges", () => {
    it("de-duplicates edges by (kind, source, target)", () => {
        const acc = make();
        acc.addEdge("calls", "a", "b", {});
        acc.addEdge("calls", "a", "b", {});
        expect(acc.edges).toHaveLength(1);
    });

    it("keeps edges that differ only by kind or endpoint", () => {
        const acc = make();
        acc.addEdge("calls", "a", "b", {});
        acc.addEdge("references", "a", "b", {});
        acc.addEdge("calls", "a", "c", {});
        expect(acc.edges).toHaveLength(3);
    });

    it("merges type_only variants for duplicate import edges", () => {
        const acc = make();
        acc.addEdge("imports", "m", "t", {}, { type_only: true });
        acc.addEdge("imports", "m", "t", {}, { type_only: false });
        expect(acc.edges).toHaveLength(1);
        expect(acc.edges[0].attributes.type_only_variants).toEqual([
            false,
            true,
        ]);
    });

    it("merges type_only variants for duplicate re_export edges, either parse order", () => {
        // `export type {A} from './x'` and `export {b} from './x'` are two
        // re-export statements between the same module pair, and merge into
        // one `re_exports` edge exactly as two `import` statements do. Both
        // parse orders are checked: first-writer-wins on `type_only` would
        // otherwise erase a real dependency (and any cycle it closes) whenever
        // the type-only statement happened to come first.
        const typeFirst = make();
        typeFirst.addEdge("re_exports", "m", "t", {}, { type_only: true });
        typeFirst.addEdge("re_exports", "m", "t", {}, { type_only: false });
        expect(typeFirst.edges).toHaveLength(1);
        expect(typeFirst.edges[0].attributes.type_only_variants).toEqual([
            false,
            true,
        ]);

        const valueFirst = make();
        valueFirst.addEdge("re_exports", "m", "t", {}, { type_only: false });
        valueFirst.addEdge("re_exports", "m", "t", {}, { type_only: true });
        expect(valueFirst.edges).toHaveLength(1);
        expect(valueFirst.edges[0].attributes.type_only_variants).toEqual([
            false,
            true,
        ]);
    });

    it("treats a merge partner with no type_only marker at all as a value import", () => {
        // A dynamic `import()` and a `require()` are runtime by definition but
        // used to carry no `type_only` key at all. Merging one with a
        // type-only static import of the same module must not erase the
        // dependency just because the runtime side never mentioned the
        // attribute, in EITHER parse order.
        const typeFirst = make();
        typeFirst.addEdge("imports", "m", "t", {}, { type_only: true });
        typeFirst.addEdge("imports", "m", "t", {}, { dynamic: true });
        expect(typeFirst.edges).toHaveLength(1);
        expect(typeFirst.edges[0].attributes.type_only_variants).toEqual([
            false,
            true,
        ]);

        const dynamicFirst = make();
        dynamicFirst.addEdge("imports", "m", "t", {}, { dynamic: true });
        dynamicFirst.addEdge("imports", "m", "t", {}, { type_only: true });
        expect(dynamicFirst.edges).toHaveLength(1);
        expect(dynamicFirst.edges[0].attributes.type_only_variants).toEqual([
            false,
            true,
        ]);
    });

    it("merges attributes a later duplicate adds without dropping them", () => {
        const acc = make();
        acc.addEdge("imports", "m", "t", {}, {});
        acc.addEdge(
            "imports",
            "m",
            "t",
            {},
            { nestjs_module_field: "imports" },
        );
        acc.addEdge("imports", "m", "t", {}, { dynamic: true });
        expect(acc.edges).toHaveLength(1);
        expect(acc.edges[0].attributes.nestjs_module_field).toBe("imports");
        expect(acc.edges[0].attributes.dynamic).toBe(true);
    });

    it("does not overwrite an attribute the first edge already set", () => {
        const acc = make();
        acc.addEdge("imports", "m", "t", {}, { dynamic: false });
        acc.addEdge("imports", "m", "t", {}, { dynamic: true });
        expect(acc.edges[0].attributes.dynamic).toBe(false);
    });
});
