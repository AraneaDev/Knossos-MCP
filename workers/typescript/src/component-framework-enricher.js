/**
 * Facts a component framework or runner implies without any code saying so.
 *
 * Names a component's template uses that resolve to nothing, the `Props`
 * type Astro reads by name, the hooks, watchers, methods and computed
 * properties Vue reaches by name, and the functions k6 calls in a load-test
 * script. Applied once the fact collector has walked the whole file.
 */

import ts from "typescript";
import { isNamePosition } from "./declaration-kinds.js";
import {
    memberName,
    objectField,
    VUE_HOOKS,
    vueOptionsObject,
} from "./syntax-predicates.js";
import {
    declarationModifiers,
    isFunctionBinding,
    reference,
    unwrapExpression,
} from "./typescript-fact-utils.js";

/** Adds the facts a component framework or k6 implies, once the file is walked. */
export class ComponentFrameworkEnricher {
    constructor(context) {
        this.context = context;
    }

    /**
     * Names a component's template uses that resolve to nothing, such as an
     * Options API method reached through the component instance. They join
     * the member names called on untyped receivers, so a method by one of
     * them is reported as only possibly dead.
     */
    unresolvedTemplateNames(ranges) {
        const inTemplate = (node) => {
            const start = node.getStart(this.context.sourceFile);
            return ranges.some(([from, to]) => start >= from && node.end <= to);
        };
        const visit = (node) => {
            if (
                ts.isIdentifier(node) &&
                inTemplate(node) &&
                !isNamePosition(node) &&
                this.context.checker.getSymbolAtLocation(node) === undefined
            )
                this.context.untypedCalls.add(node.text);
            ts.forEachChild(node, visit);
        };
        visit(this.context.sourceFile);
    }

    /**
     * Astro types a component's `Astro.props` from the `Props` its frontmatter
     * declares, by that name, so the component references it even when no
     * line of its source does.
     */
    astroProps() {
        const props = this.context.sourceFile.statements.find(
            (statement) =>
                (ts.isInterfaceDeclaration(statement) ||
                    ts.isTypeAliasDeclaration(statement)) &&
                statement.name.text === "Props",
        );
        if (props === undefined) return;
        const kind = ts.isInterfaceDeclaration(props)
            ? "interface"
            : "type_alias";
        this.context.addEdge(
            "references",
            this.context.moduleId,
            reference(kind, `${this.context.relative}#Props`),
            props,
        );
    }

    /**
     * A Vue Options API component: Vue calls its lifecycle hooks and watchers
     * itself, and its methods and computed properties are reached by name,
     * through `this` or from the template, which no static edge records. A
     * method or computed property this component names gets a reference from
     * its module; one it never names stays reportable.
     */
    vueOptions(templateRanges) {
        const options = vueOptionsObject(this.context.sourceFile);
        if (options === undefined) return;
        const used = this.vueUsedNames(templateRanges);
        const groups = new Map();
        for (const property of options.properties) {
            const name = memberName(property);
            if (VUE_HOOKS.has(name)) this.markRuntimeInvoked(property);
            if (
                ts.isPropertyAssignment(property) &&
                ts.isObjectLiteralExpression(property.initializer)
            )
                groups.set(name, property.initializer.properties);
        }
        // `props: { items: { default() {}, validator(v) {} } }`.
        for (const prop of groups.get("props") ?? []) {
            if (
                !ts.isPropertyAssignment(prop) ||
                !ts.isObjectLiteralExpression(prop.initializer)
            )
                continue;
            for (const factory of prop.initializer.properties) {
                if (["default", "validator"].includes(memberName(factory)))
                    this.markRuntimeInvoked(factory);
            }
        }
        for (const watcher of groups.get("watch") ?? [])
            this.vueWatcher(watcher, used);
        for (const member of [
            ...(groups.get("methods") ?? []),
            ...(groups.get("computed") ?? []),
        ]) {
            const id = this.context.declaredIds.get(member);
            if (id !== undefined && used.has(memberName(member)))
                this.context.addEdge(
                    "references",
                    this.context.moduleId,
                    id,
                    member,
                );
        }
    }

    /**
     * A Vue watcher, which Vue calls: `n(value) {}`, `total: 'recount'` (a
     * method named by string), or `deep: { handler() {} }` /
     * `deep: { handler: 'recount' }`.
     */
    vueWatcher(watcher, used) {
        this.markRuntimeInvoked(watcher);
        if (!ts.isPropertyAssignment(watcher)) return;
        const value = watcher.initializer;
        if (ts.isStringLiteralLike(value)) used.add(value.text);
        if (!ts.isObjectLiteralExpression(value)) return;
        for (const option of value.properties) {
            if (memberName(option) !== "handler") continue;
            this.markRuntimeInvoked(option);
            if (
                ts.isPropertyAssignment(option) &&
                ts.isStringLiteralLike(option.initializer)
            )
                used.add(option.initializer.text);
        }
    }

    /** Names a Vue component uses: `this.name` in its script, any name in its template. */
    vueUsedNames(templateRanges) {
        const used = new Set();
        const visit = (node) => {
            if (
                ts.isPropertyAccessExpression(node) &&
                node.expression.kind === ts.SyntaxKind.ThisKeyword
            )
                used.add(node.name.text);
            else if (
                ts.isIdentifier(node) &&
                templateRanges.some(
                    ([from, to]) =>
                        node.getStart(this.context.sourceFile) >= from &&
                        node.end <= to,
                )
            )
                used.add(node.text);
            ts.forEachChild(node, visit);
        };
        visit(this.context.sourceFile);
        return used;
    }

    /**
     * A k6 load-test script: `k6 run script.js` runs the module, and k6 calls
     * its default export, `setup`, `teardown` and `handleSummary`, and every
     * function a scenario names as its `exec`. Nothing imports any of them.
     */
    k6Script() {
        this.context.accumulator.nodesById.get(
            this.context.moduleId,
        ).attributes.executable = true;
        const invoked = new Set(["setup", "teardown", "handleSummary"]);
        for (const statement of this.context.sourceFile.statements) {
            if (!ts.isVariableStatement(statement)) continue;
            for (const declaration of statement.declarationList.declarations) {
                if (
                    !ts.isIdentifier(declaration.name) ||
                    declaration.name.text !== "options"
                )
                    continue;
                const scenarios = objectField(
                    unwrapExpression(declaration.initializer),
                    "scenarios",
                );
                for (const scenario of scenarios?.properties ?? []) {
                    const exec = ts.isPropertyAssignment(scenario)
                        ? objectField(scenario.initializer, "exec")
                        : undefined;
                    if (exec !== undefined && ts.isStringLiteral(exec))
                        invoked.add(exec.text);
                }
            }
        }
        for (const statement of this.context.sourceFile.statements) {
            const modifiers = declarationModifiers(statement);
            const isDefault = modifiers.some(
                (modifier) => modifier.kind === ts.SyntaxKind.DefaultKeyword,
            );
            if (ts.isFunctionDeclaration(statement)) {
                if (
                    isDefault ||
                    (statement.name !== undefined &&
                        invoked.has(statement.name.text))
                )
                    this.markRuntimeInvoked(statement);
            } else if (ts.isVariableStatement(statement)) {
                for (const declaration of statement.declarationList
                    .declarations)
                    if (
                        isFunctionBinding(declaration) &&
                        invoked.has(declaration.name.text)
                    )
                        this.markRuntimeInvoked(declaration);
            } else if (
                ts.isExportAssignment(statement) &&
                ts.isIdentifier(statement.expression)
            ) {
                // `export default run`: the declaration it names.
                const symbol = this.context.checker.getSymbolAtLocation(
                    statement.expression,
                );
                for (const declaration of symbol?.declarations ?? [])
                    this.markRuntimeInvoked(declaration);
            }
        }
    }

    /** Mark a declared member as called by its framework rather than by code. */
    markRuntimeInvoked(member) {
        const id = this.context.declaredIds.get(member);
        const fact =
            id === undefined
                ? undefined
                : this.context.accumulator.nodesById.get(id);
        if (fact !== undefined)
            fact.attributes = { ...fact.attributes, runtime_invoked: true };
    }
}
