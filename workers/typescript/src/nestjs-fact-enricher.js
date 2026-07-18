import ts from "typescript";
import {
    addFrameworkRoute,
    propertyNameText,
    reference,
} from "./typescript-fact-utils.js";

/** Adds NestJS decorator and module-metadata facts during the shared traversal. */
export class NestJsFactEnricher {
    constructor(context) {
        this.context = context;
        this.imports = new Map();
    }

    importDeclaration(node) {
        if (
            !ts.isStringLiteral(node.moduleSpecifier) ||
            node.moduleSpecifier.text !== "@nestjs/common" ||
            !node.importClause?.namedBindings ||
            !ts.isNamedImports(node.importClause.namedBindings)
        )
            return;
        for (const element of node.importClause.namedBindings.elements) {
            this.imports.set(
                element.name.text,
                element.propertyName?.text ?? element.name.text,
            );
        }
    }

    declaration(node, id, canonical) {
        const result = { roles: [], controllerPrefix: null };
        if (ts.isClassDeclaration(node) || ts.isClassExpression(node)) {
            const controller = this.decorator(node, "Controller");
            if (controller) {
                result.roles.push("nestjs.controller");
                result.controllerPrefix =
                    literalDecoratorArgument(controller) ?? "";
            }
            if (this.decorator(node, "Injectable"))
                result.roles.push("nestjs.provider");
            const moduleDecorator = this.decorator(node, "Module");
            if (moduleDecorator) {
                result.roles.push("nestjs.module");
                this.moduleRelations(moduleDecorator, id);
            }
        }
        if (ts.isMethodDeclaration(node))
            this.controllerRoutes(node, id, canonical);
        return result;
    }

    moduleRelations(decorator, id) {
        const metadata = decorator.arguments[0];
        if (!metadata || !ts.isObjectLiteralExpression(metadata)) return;
        const relations = {
            imports: "depends_on",
            controllers: "contains",
            providers: "contains",
            exports: "exports",
        };
        for (const property of metadata.properties) {
            if (!ts.isPropertyAssignment(property)) continue;
            const name = propertyNameText(property.name);
            if (
                !(name in relations) ||
                !ts.isArrayLiteralExpression(property.initializer)
            )
                continue;
            for (const element of property.initializer.elements) {
                const target = this.context.symbolReference(
                    this.context.checker.getSymbolAtLocation(element),
                    "class",
                );
                if (target !== null)
                    this.context.addEdge(
                        relations[name],
                        id,
                        target,
                        element,
                        { nestjs_module_field: name },
                        "framework_convention",
                    );
            }
        }
    }

    controllerRoutes(node, id, canonical) {
        const parent = this.context.container.at(-1);
        if (
            parent?.nestControllerPrefix === null ||
            parent?.nestControllerPrefix === undefined
        )
            return;
        for (const method of [
            "Get",
            "Post",
            "Put",
            "Patch",
            "Delete",
            "Head",
            "Options",
            "All",
        ]) {
            const decorator = this.decorator(node, method);
            if (!decorator) continue;
            const routePath = joinRoutePath(
                parent.nestControllerPrefix,
                literalDecoratorArgument(decorator) ?? "",
            );
            const httpMethod = method === "All" ? "ALL" : method.toUpperCase();
            const routeCanonical = `${httpMethod} ${routePath} => ${canonical}`;
            const routeId = reference("route", routeCanonical);
            addFrameworkRoute(this.context, {
                id: routeId,
                canonical: routeCanonical,
                displayName: `${httpMethod} ${routePath}`,
                node,
                framework: "nestjs",
                httpMethod,
                path: routePath,
                target: id,
            });
        }
    }

    decorator(node, exportedName) {
        for (const decorator of decoratorsOf(node)) {
            const expression = decorator.expression;
            const target = ts.isCallExpression(expression)
                ? expression.expression
                : expression;
            if (
                ts.isIdentifier(target) &&
                this.imports.get(target.text) === exportedName
            )
                return ts.isCallExpression(expression)
                    ? expression
                    : { arguments: [] };
        }
        return null;
    }
}

function decoratorsOf(node) {
    return ts.canHaveDecorators(node) ? (ts.getDecorators(node) ?? []) : [];
}

function literalDecoratorArgument(call) {
    const value = call.arguments?.[0];
    return value &&
        (ts.isStringLiteral(value) || ts.isNoSubstitutionTemplateLiteral(value))
        ? value.text
        : null;
}

function joinRoutePath(prefix, suffix) {
    const segments = [prefix, suffix]
        .flatMap((value) => String(value).split("/"))
        .filter(Boolean);
    return `/${segments.join("/")}`.replaceAll("//", "/");
}
