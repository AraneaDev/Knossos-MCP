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
            !node.moduleSpecifier.text.startsWith("@nestjs/") ||
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
        if (ts.isMethodDeclaration(node)) {
            this.controllerRoutes(node, id, canonical);
            if (this.isFrameworkHandler(node))
                result.roles.push("nestjs.framework_handler");
        }
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

    /**
     * A method NestJS calls on its own schedule or event: one decorated by
     * the scheduler, the event emitter, microservices, queues or websockets,
     * or the `validate` Passport calls on a strategy.
     */
    isFrameworkHandler(node) {
        if (
            FRAMEWORK_METHOD_DECORATORS.some((name) =>
                this.decorator(node, name),
            )
        )
            return true;
        if (!ts.isIdentifier(node.name)) return false;
        if (this.nestCallsContract(node.parent, node.name.text)) return true;
        return (
            node.name.text === "validate" &&
            this.extendsPassportStrategy(node.parent)
        );
    }

    /**
     * Whether Nest instantiates a class and so calls its contract methods:
     * it carries a Nest class decorator, such as `@Injectable()` on a guard,
     * interceptor or pipe, or `@Catch()` on a filter.
     */
    managedByNest(node) {
        return (
            node !== undefined &&
            (ts.isClassDeclaration(node) || ts.isClassExpression(node)) &&
            NEST_CLASS_DECORATORS.some((name) => this.decorator(node, name))
        );
    }

    /**
     * Whether Nest calls `method` on a class: a lifecycle hook on any class
     * it manages, and a role's own method only on a class in that role.
     */
    nestCallsContract(node, method) {
        if (!this.managedByNest(node)) return false;
        if (NEST_LIFECYCLE_METHODS.includes(method)) return true;
        const role = NEST_ROLES.find((each) => each.methods.includes(method));
        if (role === undefined) return false;
        return (
            (role.decorator !== undefined &&
                this.decorator(node, role.decorator) !== null) ||
            (role.suffix !== undefined &&
                (node.name?.text ?? "").endsWith(role.suffix)) ||
            this.implementedInterfaces(node).some((name) =>
                role.interfaces.includes(name),
            ) ||
            (role.base !== undefined && this.extendsCallTo(node, role.base))
        );
    }

    /** The exported names of the interfaces a class `implements`. */
    implementedInterfaces(node) {
        return (node.heritageClauses ?? [])
            .filter(
                (clause) => clause.token === ts.SyntaxKind.ImplementsKeyword,
            )
            .flatMap((clause) => clause.types)
            .map((type) => type.expression)
            .filter((expression) => ts.isIdentifier(expression))
            .map(
                (expression) =>
                    this.imports.get(expression.text) ?? expression.text,
            );
    }

    /** Whether a class extends a call to the imported `exportedName`. */
    extendsCallTo(node, exportedName) {
        return (node.heritageClauses ?? []).some(
            (clause) =>
                clause.token === ts.SyntaxKind.ExtendsKeyword &&
                clause.types.some(
                    (type) =>
                        ts.isCallExpression(type.expression) &&
                        ts.isIdentifier(type.expression.expression) &&
                        this.imports.get(type.expression.expression.text) ===
                            exportedName,
                ),
        );
    }

    /** Whether a class extends `PassportStrategy(Strategy)` from @nestjs/passport. */
    extendsPassportStrategy(node) {
        return (
            node !== undefined &&
            (ts.isClassDeclaration(node) || ts.isClassExpression(node)) &&
            this.extendsCallTo(node, "PassportStrategy")
        );
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

// Hooks Nest calls on every class it manages.
const NEST_LIFECYCLE_METHODS = [
    "onModuleInit",
    "onModuleDestroy",
    "onApplicationBootstrap",
    "beforeApplicationShutdown",
    "onApplicationShutdown",
];

// The methods Nest calls on a class in one role, and how a class is known to
// be in it: the interface it implements, its conventional name suffix, its
// class decorator, or the mixin it extends (`AuthGuard('jwt')`).
const NEST_ROLES = [
    {
        methods: ["canActivate"],
        interfaces: ["CanActivate"],
        suffix: "Guard",
        base: "AuthGuard",
    },
    {
        methods: ["intercept"],
        interfaces: ["NestInterceptor"],
        suffix: "Interceptor",
    },
    { methods: ["transform"], interfaces: ["PipeTransform"], suffix: "Pipe" },
    {
        methods: ["catch"],
        interfaces: ["ExceptionFilter"],
        decorator: "Catch",
    },
    { methods: ["use"], interfaces: ["NestMiddleware"], suffix: "Middleware" },
    {
        methods: ["handleConnection", "handleDisconnect", "afterInit"],
        interfaces: [
            "OnGatewayConnection",
            "OnGatewayDisconnect",
            "OnGatewayInit",
        ],
        decorator: "WebSocketGateway",
    },
];

// Class decorators that hand a class to Nest to instantiate.
const NEST_CLASS_DECORATORS = [
    "Injectable",
    "Catch",
    "Controller",
    "Module",
    "WebSocketGateway",
];

// Method decorators whose method the framework itself invokes.
const FRAMEWORK_METHOD_DECORATORS = [
    "Cron",
    "Interval",
    "Timeout",
    "OnEvent",
    "EventPattern",
    "MessagePattern",
    "Process",
    "OnQueueActive",
    "OnQueueCompleted",
    "OnQueueFailed",
    "OnWorkerEvent",
    "SubscribeMessage",
];

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
