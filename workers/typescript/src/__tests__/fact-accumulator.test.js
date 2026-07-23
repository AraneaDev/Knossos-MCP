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
