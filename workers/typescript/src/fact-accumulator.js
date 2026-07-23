/**
 * Owns deterministic node and edge de-duplication for a single source file.
 */
export class FactAccumulator {
    constructor(sourceFile, relative, evidence) {
        this.sourceFile = sourceFile;
        this.relative = relative;
        this.evidence = evidence;
        this.nodesById = new Map();
        this.edgesByKey = new Map();
    }

    get nodes() {
        return [...this.nodesById.values()];
    }

    get edges() {
        return [...this.edgesByKey.values()];
    }

    addNode(
        id,
        kind,
        canonicalName,
        displayName,
        node,
        attributes = {},
        origin = "ast",
    ) {
        if (this.nodesById.has(id)) return;
        this.nodesById.set(id, {
            local_id: id,
            kind,
            canonical_name: canonicalName,
            display_name: displayName,
            origin,
            confidence: "certain",
            evidence: this.evidence(this.sourceFile, this.relative, node),
            attributes,
        });
    }

    addEdge(kind, source, target, node, attributes = {}, origin = "ast") {
        const location = this.evidence(this.sourceFile, this.relative, node);
        const key = `${kind}\0${source}\0${target}`;
        const existing = this.edgesByKey.get(key);
        if (existing) {
            if (kind === "imports" && "type_only" in attributes) {
                existing.attributes.type_only_variants = [
                    ...new Set([
                        existing.attributes.type_only,
                        ...(existing.attributes.type_only_variants ?? []),
                        attributes.type_only,
                    ]),
                ].sort();
            }
            // Merge any attributes the first occurrence lacked so a later
            // contribution (e.g. a NestJS module field or a dynamic import
            // marker) is not silently dropped on the duplicate edge key.
            for (const [attribute, value] of Object.entries(attributes)) {
                if (!(attribute in existing.attributes)) {
                    existing.attributes[attribute] = value;
                }
            }
            return;
        }
        this.edgesByKey.set(key, {
            kind,
            source,
            target,
            origin,
            confidence: "certain",
            evidence: location,
            attributes,
        });
    }
}
