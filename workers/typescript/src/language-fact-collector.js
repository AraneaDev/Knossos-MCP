/**
 * The facts of one TypeScript or JavaScript source file.
 *
 * `TypeScriptLanguageFactCollector` is told about every node of the file
 * in order (`enter`, `handle`, `leave`) and turns declarations,
 * imports, calls and references into nodes and edges, resolved through the
 * program's checker. Framework conventions are added by the enrichers it
 * creates, each handed the collector as its context.
 */

import path from "node:path";
import ts from "typescript";
import {
    ambientAttributes,
    boundFunction,
    callableKind,
    canonicalForDeclaration,
    containerDeclaration,
    declarationDescriptor,
    declarationKind,
    evidence,
    externalPackageName,
    importIsTypeOnly,
    isContextualObjectLiteral,
    isDeclaration,
    isNamePosition,
    isObjectLiteralBinding,
    isTypeOnlyDeclaration,
    memberDeclaration,
    objectLiteralContract,
    referenceableDeclaration,
    unalias,
    valueReferencePosition,
} from "./declaration-kinds.js";
import { FactAccumulator } from "./fact-accumulator.js";
import {
    hasMainGuard,
    importMetaUrlModule,
    isDirnameExpression,
    sourcePathTarget,
    unwrapParentheses,
} from "./module-entry.js";
import { NestJsFactEnricher } from "./nestjs-fact-enricher.js";
import {
    allowedCompilerPath,
    belowNodeModules,
    normalize,
    realSourcePath,
    relativeInside,
} from "./project-paths.js";
import { startsWithShebang } from "./request-validation.js";
import { componentSources } from "./source-caches.js";
import {
    globBase,
    globContext,
    isClassicScript,
    isImportMetaGlob,
    isK6Script,
    isRequireContext,
    memberName,
    objectField,
    regularExpressionOf,
    relativeGlob,
    REQUIRE_SUFFIXES,
    runsOnLoad,
    storeMemberNames,
    VUE_HOOKS,
    vueOptionsObject,
} from "./syntax-predicates.js";
import { TypeScriptApplicationEnricher } from "./typescript-application-enricher.js";
import {
    callName,
    declarationModifiers,
    functionBindingOf,
    isFunctionBinding,
    reference,
    unwrapExpression,
} from "./typescript-fact-utils.js";

export class TypeScriptLanguageFactCollector {
    constructor(root, sourceFile, checker, project = {}) {
        this.root = root;
        // The program's compiler options.
        this.project = project;
        this.sourceFile = sourceFile;
        this.checker = checker;
        this.relative = relativeInside(root, sourceFile.fileName);
        this.container = [];
        this.moduleId = reference("module", this.relative);
        const ownEnd = componentSources.get(sourceFile)?.sourceLength;
        this.accumulator = new FactAccumulator(
            sourceFile,
            this.relative,
            evidence,
            ownEnd === undefined
                ? {}
                : { end: ownEnd, moduleId: this.moduleId },
        );
        this.application = new TypeScriptApplicationEnricher(this);
        this.nest = new NestJsFactEnricher(this);
        this.untypedCalls = new Set();
        // Each declaration's node id, by the syntax node it was read from.
        this.declaredIds = new Map();
    }

    get nodes() {
        return this.accumulator.nodes;
    }
    get edges() {
        return this.accumulator.edges;
    }

    initialize() {
        this.addNode(
            this.moduleId,
            "module",
            this.relative,
            path.basename(this.relative),
            this.sourceFile,
            {
                declaration_file: this.sourceFile.isDeclarationFile,
                executable:
                    startsWithShebang(this.sourceFile.text) ||
                    hasMainGuard(this.sourceFile, this.checker) ||
                    isClassicScript(this.sourceFile) ||
                    runsOnLoad(this.sourceFile),
            },
        );
    }

    /**
     * Records on the module node the member names called on a receiver the
     * checker could not type (`any`, an untyped parameter in JavaScript).
     * Such a call has no edge, and a method by one of these names may be what
     * it reaches, so the core reports that method as only possibly dead.
     */
    finish() {
        const component = componentSources.get(this.sourceFile);
        if (component !== undefined)
            this.unresolvedTemplateNames(component.templateRanges);
        if (component?.dialect === "astro") this.astroProps();
        if (component?.dialect === "vue")
            this.vueOptions(component.templateRanges);
        if (isK6Script(this.sourceFile)) this.k6Script();
        if (this.untypedCalls.size === 0) return;
        this.accumulator.nodesById.get(
            this.moduleId,
        ).attributes.unresolved_member_calls = [...this.untypedCalls].sort();
    }

    /**
     * Names a component's template uses that resolve to nothing, such as an
     * Options API method reached through the component instance. They join
     * the member names called on untyped receivers, so a method by one of
     * them is reported as only possibly dead.
     */
    unresolvedTemplateNames(ranges) {
        const inTemplate = (node) => {
            const start = node.getStart(this.sourceFile);
            return ranges.some(([from, to]) => start >= from && node.end <= to);
        };
        const visit = (node) => {
            if (
                ts.isIdentifier(node) &&
                inTemplate(node) &&
                !isNamePosition(node) &&
                this.checker.getSymbolAtLocation(node) === undefined
            )
                this.untypedCalls.add(node.text);
            ts.forEachChild(node, visit);
        };
        visit(this.sourceFile);
    }

    /**
     * Astro types a component's `Astro.props` from the `Props` its frontmatter
     * declares, by that name, so the component references it even when no
     * line of its source does.
     */
    astroProps() {
        const props = this.sourceFile.statements.find(
            (statement) =>
                (ts.isInterfaceDeclaration(statement) ||
                    ts.isTypeAliasDeclaration(statement)) &&
                statement.name.text === "Props",
        );
        if (props === undefined) return;
        const kind = ts.isInterfaceDeclaration(props)
            ? "interface"
            : "type_alias";
        this.addEdge(
            "references",
            this.moduleId,
            reference(kind, `${this.relative}#Props`),
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
        const options = vueOptionsObject(this.sourceFile);
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
            const id = this.declaredIds.get(member);
            if (id !== undefined && used.has(memberName(member)))
                this.addEdge("references", this.moduleId, id, member);
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
                        node.getStart(this.sourceFile) >= from &&
                        node.end <= to,
                )
            )
                used.add(node.text);
            ts.forEachChild(node, visit);
        };
        visit(this.sourceFile);
        return used;
    }

    /** Mark a declared member as called by its framework rather than by code. */
    /**
     * A k6 load-test script: `k6 run script.js` runs the module, and k6 calls
     * its default export, `setup`, `teardown` and `handleSummary`, and every
     * function a scenario names as its `exec`. Nothing imports any of them.
     */
    k6Script() {
        this.accumulator.nodesById.get(this.moduleId).attributes.executable =
            true;
        const invoked = new Set(["setup", "teardown", "handleSummary"]);
        for (const statement of this.sourceFile.statements) {
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
        for (const statement of this.sourceFile.statements) {
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
                const symbol = this.checker.getSymbolAtLocation(
                    statement.expression,
                );
                for (const declaration of symbol?.declarations ?? [])
                    this.markRuntimeInvoked(declaration);
            }
        }
    }

    /**
     * Whether a method fulfils a member of a type its class extends or
     * implements, or of the type its object literal is handed to: the source
     * says so with `override`, or the checker finds a member of that name on
     * a heritage or contextual type. The type may be a dependency's
     * or a built-in one (`Iterator`), whose members are not in the graph, so
     * the dispatch through it leaves no edge to this method.
     */
    overridesSupertypeMember(node) {
        if (!ts.isMethodDeclaration(node)) return false;
        // An object literal's method fulfils the member of the type the
        // literal is handed to (`registerHooks({ resolve() {} })`), which
        // calls it unseen.
        if (ts.isObjectLiteralExpression(node.parent)) {
            const name = this.checker.getSymbolAtLocation(
                node.name,
            )?.escapedName;
            const contract = this.checker.getContextualType(node.parent);
            const member =
                name === undefined
                    ? undefined
                    : contract?.getProperty(
                          ts.unescapeLeadingUnderscores(name),
                      );
            // A contextual type inferred from the literal itself
            // (`define<T>(options: T)`) holds this very method: that names
            // no contract, so only a member declared elsewhere counts.
            return (member?.declarations ?? []).some(
                (declaration) => declaration !== node,
            );
        }
        if (
            !ts.isClassDeclaration(node.parent) &&
            !ts.isClassExpression(node.parent)
        )
            return false;
        const flags = ts.getCombinedModifierFlags(node);
        if (flags & ts.ModifierFlags.Override) return true;
        // A heritage type describes instances; a static method sharing an
        // instance member's name fulfils nothing of it.
        if (flags & ts.ModifierFlags.Static) return false;
        const name = this.checker.getSymbolAtLocation(node.name)?.escapedName;
        if (name === undefined) return false;
        return (node.parent.heritageClauses ?? []).some((clause) =>
            clause.types.some(
                (type) =>
                    this.checker
                        .getTypeAtLocation(type)
                        .getProperty(ts.unescapeLeadingUnderscores(name)) !==
                    undefined,
            ),
        );
    }

    markRuntimeInvoked(member) {
        const id = this.declaredIds.get(member);
        const fact =
            id === undefined ? undefined : this.accumulator.nodesById.get(id);
        if (fact !== undefined)
            fact.attributes = { ...fact.attributes, runtime_invoked: true };
    }

    enter(node) {
        return isDeclaration(node) ? this.declaration(node) : false;
    }

    handle(node) {
        if (ts.isImportDeclaration(node)) this.importDeclaration(node);
        if (ts.isExportDeclaration(node)) this.exportDeclaration(node);
        if (ts.isImportEqualsDeclaration(node)) this.importEquals(node);
        if (ts.isPropertyAssignment(node)) this.entryPointsProperty(node);
        if (ts.isVariableDeclaration(node)) {
            this.application.variable(node);
            this.dynamicImportBindings(node);
        }
        if (ts.isNewExpression(node)) this.newExpression(node);
        if (ts.isCallExpression(node)) this.callExpression(node);
        if (ts.isTypeReferenceNode(node)) this.typeReference(node);
        if (ts.isIdentifier(node)) this.valueReference(node);
        if (ts.isBindingElement(node) && ts.isObjectBindingPattern(node.parent))
            this.destructuredMember(node);
    }

    leave(pushed) {
        if (pushed) this.container.pop();
    }

    /** `extends` / `implements` edges and constructor `injects` edges of a class or interface. */
    heritageEdges(node, id) {
        if (
            !ts.isClassDeclaration(node) &&
            !ts.isClassExpression(node) &&
            !ts.isInterfaceDeclaration(node)
        )
            return;
        for (const clause of node.heritageClauses ?? []) {
            for (const type of clause.types) {
                const target = this.symbolReference(
                    this.checker.getSymbolAtLocation(type.expression),
                    "class",
                );
                if (target !== null) {
                    this.addEdge(
                        clause.token === ts.SyntaxKind.ImplementsKeyword
                            ? "implements"
                            : "extends",
                        id,
                        target,
                        type,
                    );
                }
            }
        }
        const constructor = node.members?.find((member) =>
            ts.isConstructorDeclaration(member),
        );
        for (const parameter of constructor?.parameters ?? []) {
            if (parameter.type) {
                const target = this.typeNodeReference(parameter.type);
                if (target !== null)
                    this.addEdge("injects", id, target, parameter);
            }
        }
    }

    /** The contract an object literal implements, from its context or its binding's annotation. */
    objectLiteralContracts(node, id) {
        if (isContextualObjectLiteral(node)) {
            const contextual = this.checker.getContextualType(node);
            const parts = contextual?.isUnion()
                ? contextual.types
                : contextual
                  ? [contextual]
                  : [];
            for (const part of parts) {
                const symbol = part.aliasSymbol ?? part.symbol;
                if (!symbol || symbol.getName().startsWith("__")) continue;
                const target = this.symbolReference(symbol, "class");
                if (target !== null && target !== id)
                    this.addEdge("implements", id, target, node);
            }
        }

        if (isObjectLiteralBinding(node)) {
            const contract = objectLiteralContract(node);
            const target =
                contract !== undefined && ts.isTypeReferenceNode(contract)
                    ? this.typeNodeReference(contract)
                    : null;
            if (target !== null && target !== id)
                this.addEdge("implements", id, target, contract);
        }
    }

    /** A `returns` edge per named type a function or method declares it returns. */
    returnEdges(node, id) {
        // A function binding declares its return type on the arrow or
        // function expression; a type on the binding itself is a function
        // type, not a return type.
        const returnType = isFunctionBinding(node)
            ? unwrapExpression(node.initializer).type
            : ts.isFunctionDeclaration(node) ||
                ts.isMethodDeclaration(node) ||
                ts.isMethodSignature(node)
              ? node.type
              : undefined;
        if (returnType === undefined) return;
        // `Clipboard | null` has no symbol of its own: each named member
        // of a union is a type the function may return.
        const returned = ts.isUnionTypeNode(returnType)
            ? returnType.types.filter((member) =>
                  ts.isTypeReferenceNode(member),
              )
            : [returnType];
        for (const typeNode of returned) {
            const target = this.typeNodeReference(typeNode);
            if (target !== null) this.addEdge("returns", id, target, typeNode);
        }
    }

    declaration(node) {
        const descriptor = declarationDescriptor(node, this.sourceFile);
        if (descriptor === null) return false;
        const parent = this.container.at(-1) ?? {
            id: this.moduleId,
            canonical: this.relative,
        };
        const canonical = memberDeclaration(node)
            ? `${parent.canonical}::${descriptor.name}`
            : `${this.relative}#${this.container.length > 0 ? `${this.container.map((item) => item.name).join(".")}.` : ""}${descriptor.name}`;
        const id = reference(descriptor.kind, canonical);
        this.declaredIds.set(node, id);
        // False for a declaration a component's framework implies, which is
        // no node, so it takes no roles or attributes either.
        const kept = this.addNode(
            id,
            descriptor.kind,
            canonical,
            descriptor.name,
            node,
            // Carried down from the module node onto every declaration it
            // holds. A `.d.ts` describes code rather than being it, so its
            // symbols are not their own reachability question: the graph should
            // ask whether the `.mjs` behind `color-debt.d.mts` is used, not
            // whether anyone imports the declaration of it.
            this.sourceFile.isDeclarationFile
                ? { ...descriptor.attributes, declaration_file: true }
                : ambientAttributes(node, descriptor.attributes),
        );
        this.addEdge("contains", parent.id, id, node);
        if (kept && this.overridesSupertypeMember(node)) {
            const fact = this.accumulator.nodesById.get(id);
            fact.attributes = { ...fact.attributes, overrides: true };
        }
        const nest = kept
            ? this.nest.declaration(node, id, canonical)
            : { roles: [], controllerPrefix: null };
        const applicationRoles = kept
            ? this.application.declaration(node, id, canonical, descriptor.name)
            : [];
        if (applicationRoles.length > 0) {
            const fact = this.accumulator.nodesById.get(id);
            fact.attributes = {
                ...fact.attributes,
                typescript_framework_roles: applicationRoles,
            };
        }
        if (nest.roles.length > 0) {
            const fact = this.accumulator.nodesById.get(id);
            fact.attributes = { ...fact.attributes, nestjs_roles: nest.roles };
        }

        this.heritageEdges(node, id);
        this.objectLiteralContracts(node, id);
        this.returnEdges(node, id);

        if (containerDeclaration(node)) {
            this.container.push({
                id,
                canonical,
                name: descriptor.name,
                nestControllerPrefix: nest.controllerPrefix,
            });
            return true;
        }
        return false;
    }

    importDeclaration(node) {
        if (!ts.isStringLiteral(node.moduleSpecifier)) return;
        this.nest.importDeclaration(node);
        const target = this.moduleTarget(
            node.moduleSpecifier.text,
            node.moduleSpecifier,
        );
        if (target === null) return;
        this.addEdge("imports", this.moduleId, target, node, {
            type_only: importIsTypeOnly(node.importClause),
        });
    }

    exportDeclaration(node) {
        if (!node.moduleSpecifier || !ts.isStringLiteral(node.moduleSpecifier))
            return;
        const target = this.moduleTarget(
            node.moduleSpecifier.text,
            node.moduleSpecifier,
        );
        if (target !== null)
            this.addEdge("re_exports", this.moduleId, target, node, {
                type_only: node.isTypeOnly,
                // What the re-export passes on under the source's own names;
                // absent for `export *`, which passes on everything.
                ...(node.exportClause && ts.isNamedExports(node.exportClause)
                    ? {
                          names: node.exportClause.elements.map(
                              (element) =>
                                  (element.propertyName ?? element.name).text,
                          ),
                      }
                    : {}),
            });
    }

    importEquals(node) {
        if (
            ts.isExternalModuleReference(node.moduleReference) &&
            ts.isStringLiteral(node.moduleReference.expression)
        ) {
            const target = this.moduleTarget(
                node.moduleReference.expression.text,
                node.moduleReference.expression,
            );
            if (target !== null)
                this.addEdge("imports", this.moduleId, target, node, {
                    type_only: node.isTypeOnly,
                });
        }
    }

    newExpression(node) {
        const url = importMetaUrlModule(node, this.sourceFile, this.root);
        if (url !== null)
            this.addEdge(
                "imports",
                this.currentSource() ?? this.moduleId,
                reference("module", url),
                node,
                {
                    dynamic: true,
                    url: true,
                    type_only: false,
                    speculative: true,
                },
            );
        const source = this.currentSource();
        const target =
            this.symbolReference(
                this.checker.getSymbolAtLocation(node.expression),
                "class",
                true,
            ) ?? this.constructedClass(node.expression);
        if (source !== null && target !== null)
            this.addEdge("constructs", source, target, node);
    }

    /**
     * The class a binding holds when `new` names the binding rather than the
     * class. A binding assigned a class (`const B: typeof A = Actual`, or a
     * class expression) holds that class, whatever its annotation says. A
     * binding with no initializer to read (`const { Backend } =
     * require('./local') as typeof import('./local')`) holds what its type
     * says: the constructor type's symbol is the class. An annotated binding
     * assigned anything else proves nothing.
     */
    constructedClass(expression) {
        const binding = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(expression),
        )?.valueDeclaration;
        if (
            binding !== undefined &&
            ts.isVariableDeclaration(binding) &&
            binding.initializer !== undefined
        ) {
            const value = unwrapExpression(binding.initializer);
            const assigned =
                value !== undefined &&
                (ts.isClassExpression(value) || ts.isIdentifier(value))
                    ? this.classSymbolReference(
                          ts.isClassExpression(value)
                              ? this.checker
                                    .getTypeAtLocation(value)
                                    .getSymbol()
                              : unalias(
                                    this.checker,
                                    this.checker.getSymbolAtLocation(value),
                                ),
                      )
                    : null;
            if (assigned !== null || binding.type !== undefined)
                return assigned;
        }
        return this.classSymbolReference(
            this.checker.getTypeAtLocation(expression).getSymbol(),
        );
    }

    /** The node a class symbol names, declared or a class expression, or null. */
    classSymbolReference(symbol) {
        return symbol?.declarations?.some(
            (item) => ts.isClassDeclaration(item) || ts.isClassExpression(item),
        )
            ? this.symbolReference(symbol, "class", true)
            : null;
    }

    callExpression(node) {
        if (
            node.expression.kind === ts.SyntaxKind.ImportKeyword &&
            node.arguments.length === 1 &&
            ts.isStringLiteral(node.arguments[0])
        ) {
            const target = this.moduleTarget(
                node.arguments[0].text,
                node.arguments[0],
            );
            if (target !== null)
                this.addEdge(
                    "imports",
                    this.currentSource() ?? this.moduleId,
                    target,
                    node,
                    { dynamic: true, type_only: false },
                );
            // Only when the import resolved to a module INSIDE the project.
            // `target` above is non-null for an external package too —
            // moduleTarget() falls back to minting a `package` node for
            // anything that isn't internal — so gating on it here would still
            // let dynamicDefaultImport resolve a real default export an npm
            // package happens to have, minting an external_function node and
            // a references edge for what is, from this project, just an
            // ordinary dependency.
            if (this.internalModuleTarget(node.arguments[0]) !== null)
                this.dynamicDefaultImport(node.arguments[0]);
            return;
        }
        if (
            ts.isIdentifier(node.expression) &&
            node.expression.text === "require" &&
            node.arguments.length === 1 &&
            ts.isStringLiteral(node.arguments[0])
        ) {
            const target = this.moduleTarget(
                node.arguments[0].text,
                node.arguments[0],
            );
            if (target !== null)
                this.addEdge(
                    "imports",
                    this.currentSource() ?? this.moduleId,
                    target,
                    node,
                    { commonjs: true, type_only: false },
                );
            return;
        }

        this.pathLiteralImports(node);
        for (const name of storeMemberNames(node)) this.untypedCalls.add(name);
        const signature = this.checker.getResolvedSignature(node);
        if (
            signature?.declaration === undefined &&
            ts.isPropertyAccessExpression(node.expression)
        ) {
            // `x.m.bind(...)` on an untyped `x` may reach any `m`; the name
            // `bind` says nothing about which.
            const bound = boundFunction(node.expression);
            this.untypedCalls.add(
                bound !== undefined && ts.isPropertyAccessExpression(bound)
                    ? bound.name.text
                    : node.expression.name.text,
            );
        }
        const source = this.currentSource();
        const target = this.callEdge(node, signature, source);

        const calledName = callName(node.expression);
        if (source !== null && calledName?.startsWith("use") && target !== null)
            this.addEdge(
                "uses_hook",
                source,
                target,
                node,
                { framework: "react" },
                "framework_convention",
            );
        this.application.call(node, source, calledName);
    }

    /**
     * The function binding a call names, or null. A binding typed by an
     * annotation or a cast is called through that type's signature, whose
     * declaration is the type rather than the arrow, so the callee's own
     * symbol is what names the node.
     */
    bindingCallee(node) {
        const callee = ts.isPropertyAccessExpression(node.expression)
            ? node.expression.name
            : node.expression;
        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(callee),
        );
        return symbol?.declarations?.some((declaration) =>
            isFunctionBinding(declaration),
        )
            ? this.symbolReference(symbol, "function", true)
            : null;
    }

    /**
     * The default export of a module loaded with `import('./x')`, which no
     * identifier in this file ever names.
     *
     * A static default import is reached at its USE site — `unalias()` resolves
     * the local binding back to the exported declaration when the name is read.
     * A dynamic import has no such site: `lazy(() => import('./pages/Admin'))`
     * hands the module object straight to React, and the component is rendered
     * from a variable holding the lazy wrapper. The module gets its `imports`
     * edge and the component inside it gets nothing, so every route in a
     * code-split application looked unreferenced — twenty of them on the project
     * this comes from, each one a page the router serves.
     *
     * Only `default` is resolved. It is what a dynamic import is overwhelmingly
     * used for, and a named export a caller destructures off the module object
     * is a narrower question this deliberately leaves alone: missing an edge
     * there costs a false candidate, while guessing at every export of every
     * dynamically imported module would quietly mark real dead code as live.
     */
    dynamicDefaultImport(specifier) {
        const moduleSymbol = this.checker.getSymbolAtLocation(specifier);
        if (!moduleSymbol) return;
        const exported = this.checker.tryGetMemberInModuleExports(
            "default",
            moduleSymbol,
        );
        const target = exported
            ? this.symbolReference(exported, "function", true)
            : null;
        const source = this.currentSource();
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, specifier);
    }

    /**
     * The exports `const { App, Panel: P } = await import('./tui')` takes.
     *
     * Destructuring names each export exactly, so, unlike a module object
     * handed around whole, it resolves without guessing. Without this, a
     * module loaded lazily this way had its `imports` edge while every export
     * it takes looked unreferenced. A rest element names no export.
     */
    dynamicImportBindings(node) {
        if (!ts.isObjectBindingPattern(node.name) || !node.initializer) return;
        let call = unwrapParentheses(node.initializer);
        if (ts.isAwaitExpression(call))
            call = unwrapParentheses(call.expression);
        if (
            !ts.isCallExpression(call) ||
            call.expression.kind !== ts.SyntaxKind.ImportKeyword ||
            call.arguments.length !== 1 ||
            !ts.isStringLiteral(call.arguments[0])
        )
            return;
        const specifier = call.arguments[0];
        if (this.internalModuleTarget(specifier) === null) return;
        const moduleSymbol = this.checker.getSymbolAtLocation(specifier);
        if (!moduleSymbol) return;
        const source = this.currentSource();
        for (const element of node.name.elements) {
            if (element.dotDotDotToken) continue;
            const name = element.propertyName ?? element.name;
            if (!ts.isIdentifier(name) && !ts.isStringLiteral(name)) continue;
            const exported = this.checker.tryGetMemberInModuleExports(
                name.text,
                moduleSymbol,
            );
            const target = exported
                ? this.symbolReference(exported, "function", true)
                : null;
            if (source !== null && target !== null && source !== target)
                this.addEdge("references", source, target, element);
        }
    }

    /**
     * A speculative import of a project module a source path names as a
     * literal: the only evidence, so the core keeps the edge only when the
     * graph holds that module.
     */
    speculativeImport(relative, node) {
        this.addEdge(
            "imports",
            this.currentSource() ?? this.moduleId,
            reference("module", relative),
            node,
            { dynamic: true, type_only: false, speculative: true },
        );
    }

    /**
     * `require.context('./modules', false, /\.js$/)`: webpack bundles every
     * file of the directory the pattern matches. Which files those are is the
     * whole graph's business, not this request's (a request holds only the
     * files that changed), so one unexpanded edge names the directory, the
     * recursion and the pattern, and the reconciler expands it against every
     * module the graph holds.
     */
    requireContextImports(node) {
        const [directoryArg, recursiveArg, patternArg] = node.arguments;
        const absolute = this.contextDirectory(directoryArg.text);
        const directory =
            absolute === null ? null : relativeInside(this.root, absolute);
        const pattern =
            patternArg === undefined
                ? { source: "^\\.\\/.*$", flags: "" }
                : regularExpressionOf(patternArg);
        if (directory === null || pattern === null) return;
        this.addEdge(
            "imports",
            this.currentSource() ?? this.moduleId,
            reference(
                "module_context",
                JSON.stringify({
                    directory: directory === "." ? "" : directory,
                    recursive:
                        recursiveArg === undefined ||
                        recursiveArg.kind === ts.SyntaxKind.TrueKeyword,
                    pattern: pattern.source,
                    flags: pattern.flags,
                }),
            ),
            node,
            { dynamic: true, type_only: false, context: true },
        );
    }

    /**
     * The program's file a relative `require('./local')` names.
     *
     * The compiler binds a `require` argument to its module only in a
     * JavaScript file; in TypeScript it is a string. Each candidate is tried
     * in the order Node loads it, so `require('./x')` beside both `x.js` and
     * `x.ts` means the `.js`.
     */
    requiredSourceFile(location) {
        const sourceFileAt = this.project.sourceFileAt;
        if (
            sourceFileAt === undefined ||
            !ts.isStringLiteralLike(location) ||
            !/^\.\.?(\/|$)/.test(location.text)
        )
            return undefined;
        const base = path.resolve(
            path.dirname(this.sourceFile.fileName),
            location.text,
        );
        for (const suffix of REQUIRE_SUFFIXES) {
            const fileName = normalize(base + suffix);
            const file = sourceFileAt(fileName);
            if (file !== undefined) return file;
            // A file the program leaves out (a tsconfig `exclude`, or `.js`
            // without `allowJs`) is still the module the call loads. Only its
            // existence is asked, as the compiler host asks it, so nothing is
            // read; the answer is attributed to this file like any probe.
            if (
                allowedCompilerPath(this.root, fileName) &&
                this.project.fileExists(fileName)
            )
                return { fileName };
        }
        return undefined;
    }

    /**
     * `import.meta.glob('./Pages/**\/*.vue')`: Vite bundles every file the
     * pattern matches. Each positive pattern becomes the same unexpanded edge
     * `require.context` makes, the directory before the first wildcard and
     * the rest as a pattern over the paths below it. A negated pattern only
     * narrows what the others match, so leaving it out keeps a match live
     * rather than inventing one.
     */
    globImports(node) {
        const first = node.arguments[0];
        const patterns = ts.isArrayLiteralExpression(first)
            ? first.elements
            : [first];
        const base = globBase(node.arguments[1]);
        for (const pattern of patterns) {
            if (!ts.isStringLiteralLike(pattern)) continue;
            // A relative pattern resolves from `base` when the call sets one.
            const text =
                base !== null && /^\.\.?\//.test(pattern.text)
                    ? relativeGlob(base, pattern.text)
                    : pattern.text;
            const glob = globContext(text);
            if (glob === null) continue;
            const absolute = glob.directory.startsWith("/")
                ? normalize(path.resolve(this.root, "." + glob.directory))
                : this.contextDirectory(glob.directory);
            const directory =
                absolute === null ? null : relativeInside(this.root, absolute);
            if (directory === null) continue;
            this.addEdge(
                "imports",
                this.currentSource() ?? this.moduleId,
                reference(
                    "module_context",
                    JSON.stringify({
                        directory: directory === "." ? "" : directory,
                        recursive: glob.recursive,
                        pattern: glob.pattern,
                        flags: "",
                    }),
                ),
                pattern,
                { dynamic: true, type_only: false, context: true },
            );
        }
    }

    /**
     * The directory a `require.context` names: relative to this file, or
     * through the program's `paths` (which carry a bundler's aliases).
     */
    contextDirectory(specifier) {
        const here = path.dirname(
            realSourcePath(normalize(this.sourceFile.fileName)),
        );
        if (specifier.startsWith("."))
            return normalize(path.resolve(here, specifier));
        const options = this.project.options ?? {};
        const base = options.pathsBasePath ?? options.baseUrl ?? this.root;
        // An exact key wins; otherwise the longest matching prefix, as the
        // compiler picks among `paths` patterns.
        let best = null;
        for (const [key, targets] of Object.entries(options.paths ?? {})) {
            const target = targets[0];
            if (target === undefined) continue;
            if (key === specifier) return normalize(path.resolve(base, target));
            const prefix = key.endsWith("*") ? key.slice(0, -1) : null;
            if (
                prefix !== null &&
                specifier.startsWith(prefix) &&
                (best === null || prefix.length > best.prefix.length)
            )
                best = { prefix, target };
        }
        return best === null
            ? null
            : normalize(
                  path.resolve(
                      base,
                      best.target.replace(
                          "*",
                          specifier.slice(best.prefix.length),
                      ),
                  ),
              );
    }

    /**
     * Source paths a call names by literal: `resolve(__dirname, 'x/y.tsx')`
     * (or `join`, or `import.meta.dirname`), relative to this file, or
     * `join(root, 'scripts', 'x.ts')` from a base it cannot know; and
     * `navigator.serviceWorker.register('/sw.js')`, a URL under the web root.
     */
    pathLiteralImports(node) {
        if (isRequireContext(node)) {
            this.requireContextImports(node);
            return;
        }
        if (isImportMetaGlob(node)) {
            this.globImports(node);
            return;
        }
        const callee = node.expression;
        const name = ts.isIdentifier(callee)
            ? callee.text
            : ts.isPropertyAccessExpression(callee)
              ? callee.name.text
              : null;
        const args = node.arguments;
        if (
            (name === "resolve" || name === "join") &&
            args.length >= 2 &&
            args.slice(1).every((arg) => ts.isStringLiteralLike(arg))
        ) {
            // A literal first segment is part of the path (`join('src',
            // 'util.ts')`); any other first argument is the unknown base.
            const joined = args
                .slice(ts.isStringLiteralLike(args[0]) ? 0 : 1)
                .map((arg) => arg.text)
                .join("/");
            // `__dirname` names this file's directory. Any other base
            // (`root`, `ROOT`, `process.cwd()`) is unknown here, so both usual
            // answers are offered: this file's directory and the project
            // root. Speculative, so a guess the graph does not hold is dropped.
            const bases = isDirnameExpression(args[0])
                ? [path.dirname(this.sourceFile.fileName)]
                : [path.dirname(this.sourceFile.fileName), this.root];
            for (const base of bases) {
                const relative = sourcePathTarget(this.root, base, joined);
                if (relative !== null) this.speculativeImport(relative, node);
            }
            return;
        }
        if (
            name === "register" &&
            ts.isPropertyAccessExpression(callee) &&
            ts.isPropertyAccessExpression(callee.expression) &&
            callee.expression.name.text === "serviceWorker" &&
            args.length >= 1 &&
            ts.isStringLiteralLike(args[0]) &&
            args[0].text.startsWith("/")
        ) {
            // Served from the web root, which is `public/` or `static/` in
            // most toolchains, or the project root itself.
            const url = args[0].text.slice(1).split(/[?#]/)[0];
            for (const base of ["public", "static", ""]) {
                const relative = sourcePathTarget(
                    this.root,
                    this.root,
                    base === "" ? url : `${base}/${url}`,
                );
                if (relative !== null) this.speculativeImport(relative, node);
            }
        }
    }

    /**
     * `build({ entryPoints: ['src/boot.ts'] })`: a bundler's entries, named
     * by path relative to where the build runs, which is this file's
     * directory for a build script beside its package.
     */
    entryPointsProperty(node) {
        const name =
            ts.isIdentifier(node.name) || ts.isStringLiteral(node.name)
                ? node.name.text
                : null;
        if (!["entryPoints", "entrypoints", "entry", "input"].includes(name))
            return;
        const values = ts.isArrayLiteralExpression(node.initializer)
            ? node.initializer.elements
            : ts.isObjectLiteralExpression(node.initializer)
              ? node.initializer.properties
                    .filter((member) => ts.isPropertyAssignment(member))
                    .map((member) => member.initializer)
              : [node.initializer];
        for (const value of values) {
            if (!ts.isStringLiteralLike(value)) continue;
            const relative = sourcePathTarget(
                this.root,
                path.dirname(this.sourceFile.fileName),
                value.text,
            );
            if (relative !== null) this.speculativeImport(relative, value);
        }
    }

    typeReference(node) {
        const source = this.currentSource();
        const target = this.typeNodeReference(node);
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
    }

    /**
     * A callable or type used as a VALUE rather than invoked — pushed into an
     * array or object literal, passed as a callback, assigned to a const,
     * exported through a registry.
     *
     * Without this, the only edges into a function were `calls` / `constructs`
     * (from call and new expressions) and `references` (from type positions), so
     * every dispatch-table entry looked unreferenced and dead-code analysis
     * reported it as a "probable" dead candidate — e.g. the members of a
     * `const VALIDATORS = [validateA, validateB]` array, which are demonstrably
     * live. The edge kind is `references`, matching the type-position case.
     *
     * Scope is deliberately narrow: only identifiers resolving to a callable or
     * type DECLARATION in this repository, and only in positions no other
     * handler already covers. Emitting an edge for every identifier (locals,
     * parameters, property reads) would multiply graph size and swamp the
     * in-degree signal that hub and hotspot ranking depends on.
     */
    valueReference(node) {
        if (this.enumMemberRead(node)) return;
        if (!valueReferencePosition(node)) return;
        const exported =
            ts.isPropertyAccessExpression(node.parent) &&
            node.parent.name === node
                ? this.requiredExport(node.parent)
                : null;
        if (exported !== null) {
            const source = this.currentSource();
            if (source !== null && source !== exported.target)
                this.addEdge(
                    "references",
                    source,
                    exported.target,
                    node,
                    exported.speculative ? { speculative: true } : {},
                );
            return;
        }

        // A shorthand `{ discover }` names the object's property; the value it
        // copies is the function, which only this lookup returns.
        const symbol = unalias(
            this.checker,
            ts.isShorthandPropertyAssignment(node.parent)
                ? this.checker.getShorthandAssignmentValueSymbol(node.parent)
                : this.checker.getSymbolAtLocation(node),
        );
        const declaration = symbol?.declarations?.find((item) =>
            referenceableDeclaration(item),
        );
        if (!declaration) return;

        const target = this.symbolReference(
            symbol,
            callableKind(declaration),
            true,
        );
        const source = this.currentSource();
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
    }

    /**
     * Emit the `calls` edge a call makes and return its target: the resolved
     * declaration, or a loaded module's export read behind an inline type,
     * which is speculative when the module is outside the program.
     */
    callEdge(node, signature, source) {
        const required =
            ts.isPropertyAccessExpression(node.expression) &&
            signature?.declaration === undefined
                ? this.requiredExport(node.expression)
                : null;
        const target =
            this.callTarget(node, signature) ?? required?.target ?? null;
        if (source !== null && target !== null)
            this.addEdge(
                "calls",
                source,
                target,
                node,
                required?.speculative ? { speculative: true } : {},
            );
        return target;
    }

    /**
     * The declaration a call reaches: a local binding's function, the
     * signature the checker resolved, or a loaded module's export read
     * behind an inline type.
     */
    callTarget(node, signature) {
        return (
            this.bindingCallee(node) ??
            this.symbolReference(
                signature?.declaration?.symbol,
                callableKind(signature?.declaration),
                true,
            )
        );
    }

    /**
     * `const { getSpiderResponse: fn } = await import('./service')`: a name
     * taken out of an object is the declaration the object's property is, so
     * a module's export destructured from a loaded module is referenced.
     */
    destructuredMember(element) {
        const key = element.propertyName ?? element.name;
        const name =
            ts.isIdentifier(key) || ts.isStringLiteral(key) ? key.text : null;
        if (name === null) return;
        const property = this.checker
            .getTypeAtLocation(element.parent)
            .getProperty(name);
        const symbol = unalias(this.checker, property);
        const declaration = symbol?.declarations?.find((item) =>
            referenceableDeclaration(item),
        );
        if (!declaration) return;
        const target = this.symbolReference(
            symbol,
            callableKind(declaration),
            true,
        );
        const source = this.currentSource();
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, element);
    }

    /**
     * The export a member read names when its object is a module loaded by
     * `require('./x')` behind an inline type (`as { handle: ... }`) that
     * hides the module's own: `service.handle` is the module's `handle`. For
     * a module the program leaves out, the reference is speculative.
     */
    requiredExport(access) {
        if (!ts.isIdentifier(access.expression)) return null;
        const binding = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(access.expression),
        )?.valueDeclaration;
        const call =
            binding !== undefined &&
            ts.isVariableDeclaration(binding) &&
            binding.initializer !== undefined
                ? unwrapExpression(binding.initializer)
                : undefined;
        if (
            call === undefined ||
            !ts.isCallExpression(call) ||
            !ts.isIdentifier(call.expression) ||
            call.expression.text !== "require" ||
            call.arguments.length !== 1 ||
            !ts.isStringLiteralLike(call.arguments[0])
        )
            return null;
        const file =
            unalias(
                this.checker,
                this.checker.getSymbolAtLocation(call.arguments[0]),
            )?.declarations?.find((item) => ts.isSourceFile(item)) ??
            this.requiredSourceFile(call.arguments[0]);
        if (file === undefined) return null;
        if (!ts.isSourceFile(file)) {
            // A file the program leaves out: its exports are unknown, so the
            // function the name would be is named, and kept only if it exists.
            const relative = relativeInside(this.root, file.fileName);
            return relative === null
                ? null
                : {
                      target: reference(
                          "function",
                          `${relative}#${access.name.text}`,
                      ),
                      speculative: true,
                  };
        }
        const symbol = unalias(
            this.checker,
            this.checker
                .getSymbolAtLocation(file)
                ?.exports?.get(access.name.text),
        );
        const declaration = symbol?.declarations?.find((item) =>
            referenceableDeclaration(item),
        );
        const target = declaration
            ? this.symbolReference(symbol, callableKind(declaration), true)
            : null;
        return target === null ? null : { target, speculative: false };
    }

    /**
     * `TokenType.And`: an enum read through its members, which are not nodes
     * of their own, so the read is a reference to the enum.
     */
    enumMemberRead(node) {
        const parent = node.parent;
        if (
            parent === undefined ||
            !ts.isPropertyAccessExpression(parent) ||
            parent.expression !== node
        )
            return false;
        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(node),
        );
        if (!symbol?.declarations?.some((item) => ts.isEnumDeclaration(item)))
            return false;
        const source = this.currentSource();
        const target = this.symbolReference(symbol, "enum", true);
        if (source !== null && target !== null && source !== target)
            this.addEdge("references", source, target, node);
        return true;
    }

    typeNodeReference(node) {
        const type = this.checker.getTypeFromTypeNode(node);
        return this.symbolReference(type.aliasSymbol ?? type.symbol, "class");
    }

    moduleTarget(specifier, location) {
        const internal = this.internalModuleTarget(location);
        if (internal !== null) return internal;
        // An import nothing resolved under a tsconfig `paths` key (bundler
        // aliases are folded into them) names the project's own code that is
        // missing, not a dependency: `@app/missing` under `@app/*`. One that
        // resolved into a dependency (`vue` aliased to a file of the `vue`
        // package) is that package.
        if (!this.resolvedImport(location) && this.underAlias(specifier))
            return null;

        const packageName = externalPackageName(specifier);
        if (packageName !== null) {
            const id = reference("package", packageName);
            this.addNode(id, "package", packageName, packageName, location, {
                external: true,
            });
            return id;
        }
        return null;
    }

    /** Whether the compiler resolved an import's specifier to any file. */
    resolvedImport(location) {
        if (this.checker.getSymbolAtLocation(location) !== undefined)
            return true;
        return (
            ts.isStringLiteralLike(location) &&
            this.project.resolved?.(location) === true
        );
    }

    /**
     * Whether a `paths` key covers a specifier, as the compiler matches them.
     *
     * `ts.tryParsePatterns` and `ts.matchPatternOrExact` are the compiler's
     * internal helpers, not its public API, and their shape changed in
     * TypeScript 5.6; the package-name tests import `@app/missing` under
     * `@app/*`, so a TypeScript release that changes them again fails there.
     */
    underAlias(specifier) {
        return this.aliasPatterns().some(
            (patterns) =>
                ts.matchPatternOrExact(patterns, specifier) !== undefined,
        );
    }

    /**
     * The program's `paths` keys as the compiler matches them, without the
     * catch-all `*`, which says nothing about a name. Wrapped in a list, as
     * a program without `paths` has none.
     */
    aliasPatterns() {
        if (this.parsedAliases === undefined) {
            const keys = Object.keys(this.project.options?.paths ?? {}).filter(
                (key) => key !== "*",
            );
            this.parsedAliases =
                keys.length === 0
                    ? []
                    : [
                          ts.tryParsePatterns(
                              Object.fromEntries(keys.map((key) => [key, []])),
                          ),
                      ];
        }
        return this.parsedAliases;
    }

    /**
     * The module id when the specifier resolves to a real file inside this
     * project, or null when it resolves outside it (an npm package's own
     * source, or a node_modules copy) or not at all.
     *
     * Split out of {@link moduleTarget} so a caller — {@link callExpression}'s
     * dynamic-import handling — can tell "resolved to a project module" apart
     * from "resolved to SOMETHING", which `moduleTarget`'s own return does
     * not: it falls back to minting an external `package` node for anything
     * that isn't internal, so a non-null `moduleTarget` result does not mean
     * an internal module.
     */
    internalModuleTarget(location) {
        const symbol = unalias(
            this.checker,
            this.checker.getSymbolAtLocation(location),
        );
        const declaration =
            symbol?.declarations?.find((item) => ts.isSourceFile(item)) ??
            this.requiredSourceFile(location);
        if (!declaration) return null;
        const relative = relativeInside(this.root, declaration.fileName);
        if (relative === null || belowNodeModules(relative)) return null;
        return reference("module", relative);
    }

    symbolReference(input, hint = "class", value = false) {
        const symbol = unalias(this.checker, input);
        const inProject = (item) =>
            relativeInside(this.root, item.getSourceFile().fileName) !== null;
        // A type and a value may share a name and so one symbol. Read as a
        // value (called, constructed, referenced), it is the value's
        // declaration, whichever of the two came first.
        const declaration =
            (value
                ? symbol?.declarations?.find(
                      (item) => inProject(item) && !isTypeOnlyDeclaration(item),
                  )
                : undefined) ?? symbol?.declarations?.find(inProject);
        if (!declaration) {
            const name = symbol?.getName();
            return name && !name.startsWith("__")
                ? reference(`external_${hint}`, name)
                : null;
        }
        const relative = relativeInside(
            this.root,
            declaration.getSourceFile().fileName,
        );
        if (relative === null || belowNodeModules(relative)) {
            const name = symbol?.getName();
            return name && !name.startsWith("__")
                ? reference(`external_${hint}`, name)
                : null;
        }
        // A call resolves to the arrow or function expression itself; the
        // node is the module-level binding it initialises.
        const binding = functionBindingOf(declaration);
        if (binding !== null)
            return reference(
                "function",
                canonicalForDeclaration(binding, relative),
            );
        // Declared in this project, but not as anything `declaration()` emits:
        // a type parameter, an inline `{ ... }` type, an arrow bound inside a
        // function. The name built for it would match no node, and the core
        // turns a dangling edge into an external component that is neither
        // external nor a component.
        if (!isDeclaration(declaration)) return null;
        const kind = declarationKind(declaration, hint);
        const canonical = canonicalForDeclaration(declaration, relative);
        return reference(kind, canonical);
    }

    currentSource() {
        return this.container.at(-1)?.id ?? this.moduleId;
    }

    /** Add a node; false when it is a declaration past the file's own text. */
    addNode(
        id,
        kind,
        canonicalName,
        displayName,
        node,
        attributes = {},
        origin = "ast",
    ) {
        return this.accumulator.addNode(
            id,
            kind,
            canonicalName,
            displayName,
            node,
            attributes,
            origin,
        );
    }

    addEdge(kind, source, target, node, attributes = {}, origin = "ast") {
        this.accumulator.addEdge(
            kind,
            source,
            target,
            node,
            attributes,
            origin,
        );
    }
}
