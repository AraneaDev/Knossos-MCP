/**
 * Owns deterministic node and edge de-duplication for a single source file.
 *
 * A component's virtual source can end in declarations its framework implies
 * (SvelteKit's `$props`, a generic component's type parameters, Astro's
 * global), typed like the component's own code but written nowhere in it.
 * `own.end` is the offset where the file's own text ends: a node declared past
 * it is no fact of the file, an edge to one is dropped, an edge from one is
 * the module's (`own.moduleId`), and every evidence line stays within the
 * file's own lines.
 */
export class FactAccumulator {
    constructor(sourceFile, relative, evidence, own = {}) {
        this.sourceFile = sourceFile;
        this.relative = relative;
        this.evidence = evidence;
        this.nodesById = new Map();
        this.edgesByKey = new Map();
        this.ownEnd = own.end ?? Infinity;
        this.moduleId = own.moduleId;
        this.lastLine =
            own.end === undefined
                ? Infinity
                : sourceFile.getLineAndCharacterOfPosition(own.end).line + 1;
        // The ids of the declarations past the file's own text.
        this.appended = new Set();
    }

    get nodes() {
        return [...this.nodesById.values()];
    }

    get edges() {
        return [...this.edgesByKey.values()].filter(
            (edge) => !this.appended.has(edge.target),
        );
    }

    /** Where a syntax node stands, never past the file's own last line. */
    located(node) {
        const location = this.evidence(this.sourceFile, this.relative, node);
        if (this.lastLine === Infinity) return location;
        return {
            ...location,
            start_line: Math.min(location.start_line, this.lastLine),
            end_line: Math.min(location.end_line, this.lastLine),
        };
    }

    /**
     * Add a node unless one has its id; false when it is a declaration past
     * the file's own text, which is no fact.
     */
    addNode(
        id,
        kind,
        canonicalName,
        displayName,
        node,
        attributes = {},
        origin = "ast",
    ) {
        if (this.nodesById.has(id)) return true;
        // The module itself starts past its own text when that text is blank.
        if (
            this.ownEnd !== Infinity &&
            node !== this.sourceFile &&
            node.getStart(this.sourceFile) >= this.ownEnd
        ) {
            this.appended.add(id);
            return false;
        }
        this.nodesById.set(id, {
            local_id: id,
            kind,
            canonical_name: canonicalName,
            display_name: displayName,
            origin,
            confidence: "certain",
            evidence: this.located(node),
            attributes,
        });
        return true;
    }

    addEdge(kind, source, target, node, attributes = {}, origin = "ast") {
        const location = this.located(node);
        if (this.appended.has(source)) source = this.moduleId;
        const key = `${kind}\0${source}\0${target}`;
        const existing = this.edgesByKey.get(key);
        if (existing) {
            // Both an `import` and a `re_export` statement can be type-only, and
            // either kind can merge two occurrences into one edge (two
            // `export ... from` statements between the same module pair, just
            // like two `import` statements do). Restricting the merge to
            // `imports` let a type-only re-export merge with a value one and
            // have first-writer-wins decide `type_only` on the merged edge,
            // silently erasing a real dependency — and any cycle it closed —
            // depending on parse order alone.
            //
            // Checking either side's attributes for the key (rather than
            // requiring both) matters because not every emitter that can merge
            // into an `imports` edge marks `type_only` at all: a dynamic
            // `import()` and a `require()` are runtime by definition, and a
            // module can carry one of those alongside a type-only static
            // import of the same specifier. Treating a missing marker as
            // `false` — a value import — rather than skipping it means a
            // future emitter that forgets to mark `type_only` still cannot
            // erase the dependency; it can only fail to prove the OTHER side
            // erased.
            if (
                (kind === "imports" || kind === "re_exports") &&
                ("type_only" in attributes ||
                    "type_only" in existing.attributes)
            ) {
                existing.attributes.type_only_variants = [
                    ...new Set([
                        existing.attributes.type_only ?? false,
                        ...(existing.attributes.type_only_variants ?? []),
                        attributes.type_only ?? false,
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
