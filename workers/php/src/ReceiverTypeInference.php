<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Infers what a local variable holds from the code that binds it, so a later
 * `$x->m()` can name the class it calls into.
 *
 * Reads assignments, `??=` and loop headers, and records the result in the
 * variable scope: a class when this file proves one, the call a value came
 * from when only another file's declaration could say, and nothing when the
 * value is untracked. The class enclosing the code is passed to each entry
 * point, since `$this`, `self` and `parent` are read against it.
 *
 * The return types it reads are this file's own, indexed before the traversal
 * because a method is routinely called above its own declaration.
 */
final class ReceiverTypeInference
{
    use ResolvesClassName;

    /**
     * Declared return types of the methods this file declares, keyed
     * `Class::method` in lower case, since PHP reads both names so.
     *
     * Collected up front because a method is routinely called above its own
     * declaration, and a single-pass visitor would not have read the signature
     * yet when it reaches the call.
     *
     * @var array<string, string>
     */
    private array $returnTypes = [];

    /**
     * What the closure a method returns yields when it is called, keyed as
     * {@see self::$returnTypes} is, for a method whose docblock declares
     * `@return \Closure(): X`. The native type says only `\Closure`.
     *
     * @var array<string, string>
     */
    private array $closureReturns = [];

    public function __construct(
        private readonly VariableTypeScope $variables,
        private readonly DocblockTypes $docblocks,
    ) {}

    /**
     * Index what the closures returned by one namespace block's methods yield,
     * as their docblocks declare it, read through that block's imports.
     *
     * @param array<\PhpParser\Node> $statements
     */
    public function readClosureReturns(int $scope, string $namespace, array $statements): void
    {
        foreach ((new NodeFinder())->findInstanceOf($statements, Stmt\ClassLike::class) as $class) {
            $className = $class->namespacedName?->toString();
            foreach ($className === null ? [] : $class->getMethods() as $method) {
                $yielded = DocblockTypes::documentedClosureResult($method->getDocComment()?->getText() ?? '');
                $resolved = $yielded === null ? null : $this->docblocks->documentedClass($yielded, $scope, $namespace, $className);
                if ($resolved !== null) {
                    $this->closureReturns[strtolower($className . '::' . $method->name->toString())] = $resolved;
                }
            }
        }
    }

    /**
     * Index the declared return types of the methods the file declares.
     *
     * @param array<\PhpParser\Node> $nodes
     */
    public function readReturnTypes(array $nodes): void
    {
        foreach ((new NodeFinder())->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
            $className = $class->namespacedName?->toString();
            if ($className === null) {
                // An anonymous class has no name to key its members by.
                continue;
            }
            foreach ($class->getMethods() as $method) {
                // Only a single named type is usable: a union or an intersection
                // does not name one receiver, and a nullable one is dereferenced
                // at the caller's risk rather than ours.
                if ($method->returnType instanceof Name) {
                    // `self` and `static` name the declaring class itself.
                    $returned = $method->returnType->toString();
                    $this->returnTypes[strtolower($className . '::' . $method->name->toString())] = in_array(strtolower($returned), ['self', 'static'], true) ? $className : $returned;
                }
            }
        }
    }

    /**
     * Track `$x = new Foo` so later `$x->method()` calls can be attributed to Foo.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    public function assignment(Expr\Assign $node, ?array $class): void
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name)) {
            return;
        }
        $prefix = self::namespacePrefix($node->expr);
        if ($prefix === null) {
            $this->variables->clearPrefix($node->var->name);
        } else {
            $this->variables->setPrefix($node->var->name, $prefix);
        }
        if ($node->expr instanceof Expr\New_ && $node->expr->class instanceof Name) {
            // Inferred from local construction flow — only ever probable.
            $this->variables->set($node->var->name, $this->resolvedClassNameIn($node->expr->class, $class), 'probable');

            return;
        }
        $invoked = $this->invokedType($node->expr);
        if ($invoked !== null) {
            // `$result = $run()` where `$run` holds a closure whose result type is declared.
            $this->variables->set($node->var->name, $invoked, 'probable');

            return;
        }
        $yields = $this->closureResult($node->expr, $class);
        if ($yields !== null) {
            $this->variables->set($node->var->name, 'Closure', 'probable');
            $this->variables->setInvokes($node->var->name, $yields);

            return;
        }
        $returned = $this->returnedType($node->expr, $class);
        if ($returned !== null) {
            // The type is declared, but the binding to this variable is local
            // flow like the `new` case above, so it stays probable.
            $this->variables->set($node->var->name, $returned, 'probable');

            return;
        }
        $optional = $this->optionalType($node->expr, $class);
        if ($optional !== null) {
            // `$x = $flag ? new Y() : null` is how PHP spells an optional
            // collaborator; losing the type there loses every call through it.
            $this->variables->set($node->var->name, $optional, 'probable');

            return;
        }
        if ($this->propertyAssignment($node->var->name, $node->expr, $class)) {
            return;
        }
        $callee = $this->calleeReference($node->expr, $class);
        if ($callee !== null) {
            // The receiver is whatever that call returns, and the declaration
            // that would say what is in another file. Record the call so a
            // member access on this variable can name it; the reconciler, which
            // sees every file, finishes the resolution.
            $this->variables->setReturnSource($node->var->name, $callee);

            return;
        }
        // Reassignment to any untracked value invalidates the inferred type so a
        // stale `$x = new A; …; $x = something(); $x->m()` no longer resolves to A.
        $this->variables->clear($node->var->name);
    }

    /**
     * Track `$x ??= new Foo()`, the way PHP builds a collaborator on first use.
     *
     * The variable holds what it held unless that was null, so the
     * construction types it only where nothing else did, and a type it
     * already had survives only when the construction agrees with it.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    public function coalescingAssignment(Expr\AssignOp\Coalesce $node, ?array $class): void
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name)) {
            return;
        }
        $assigned = $node->expr instanceof Expr\New_ && $node->expr->class instanceof Name
            ? $this->resolvedClassNameIn($node->expr->class, $class)
            : $this->returnedType($node->expr, $class);
        $held = $this->variables->type($node->var->name);
        if ($assigned !== null && ($held === null || $held === $assigned) && $this->variables->returnSource($node->var->name) === null
            && $this->variables->propertySource($node->var->name) === null) {
            $this->variables->set($node->var->name, $assigned, 'probable');

            return;
        }
        if ($held !== $assigned) {
            $this->variables->clear($node->var->name);
        }
    }

    /**
     * Type the value variable of `foreach (Enum::cases() as $case)`.
     *
     * `cases()` returns the enum's own cases, so each value is an instance of
     * the enum; nothing else in the loop header says so.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    public function foreachLoop(Stmt\Foreach_ $node, ?array $class): void
    {
        // The loop rebinds its variables, whatever they held before.
        foreach ([$node->keyVar, $node->valueVar] as $bound) {
            if ($bound instanceof Expr\Variable && is_string($bound->name)) {
                $this->variables->clearPrefix($bound->name);
            }
        }
        if (!$node->valueVar instanceof Expr\Variable || !is_string($node->valueVar->name)) {
            return;
        }
        if ($node->expr instanceof Expr\StaticCall
            && $node->expr->class instanceof Name
            && $node->expr->name instanceof Identifier
            && strtolower($node->expr->name->toString()) === 'cases') {
            $this->variables->set($node->valueVar->name, $this->resolvedClassNameIn($node->expr->class, $class), 'probable');

            return;
        }
        $element = $node->expr instanceof Expr\Variable && is_string($node->expr->name)
            ? $this->variables->element($node->expr->name)
            : null;
        if ($element !== null) {
            // A parameter whose docblock names its element type.
            $this->variables->set($node->valueVar->name, $element, 'probable');

            return;
        }
        // Any other loop rebinds the variable to something untracked.
        $this->variables->clear($node->valueVar->name);
    }

    /**
     * The declaring reference of a call whose receiver is statically known, if any.
     *
     * `Foo::make()` names its declaration outright; `$this->make()` names it once
     * the enclosing class is known. Anything else could dispatch anywhere.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    public function calleeReference(Expr $expression, ?array $class): ?string
    {
        if ($expression instanceof Expr\MethodCall
            && $expression->var instanceof Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier) {
            $className = $class['name'] ?? null;

            return $className === null ? null : $className . '::' . $expression->name->toString();
        }
        if ($expression instanceof Expr\StaticCall
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier) {
            return $this->resolvedClassNameIn($expression->class, $class) . '::' . $expression->name->toString();
        }
        if ($expression instanceof Expr\MethodCall
            && $expression->var instanceof Expr\New_
            && $expression->var->class instanceof Name
            && $expression->name instanceof Identifier) {
            // `(new Kernel())->server()`: the receiver is named inline.
            return $this->resolvedClassNameIn($expression->var->class, $class) . '::' . $expression->name->toString();
        }
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Identifier) {
            // A call on a collaborator whose type is declared — an injected
            // property or a typed parameter. The collaborator's own declaration
            // is usually in another file, which is exactly the case this exists
            // for.
            $receiver = $this->declaredReceiverType($expression->var, $class);

            return $receiver === null ? null : $receiver . '::' . $expression->name->toString();
        }

        return null;
    }

    /**
     * The class a call to one of this file's own methods is declared to return, if any.
     *
     * Only calls whose receiver is statically known are considered: `$this->m()`
     * and a static call naming a class. A call on any other receiver could be
     * dispatched anywhere, and guessing there would trade a missing edge for a
     * wrong one.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    public function returnedType(Expr $expression, ?array $class): ?string
    {
        $key = $this->calledMethodKey($expression, $class);

        return $key === null ? null : ($this->returnTypes[$key] ?? null);
    }

    /**
     * A receiver read through properties of a typed root, as the path the
     * reconciler resolves: `Type::$a::$b` for `$x->a->b`, where `$x` is
     * `$this`, a variable of a known type, or a variable that holds what such
     * a path held. Null when the root's type is unknown or a step is not a
     * plain property name.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    public function propertyPath(Expr $receiver, ?array $class): ?string
    {
        if ($receiver instanceof Expr\Variable && is_string($receiver->name) && $receiver->name !== 'this') {
            // `$request = $graph->request; $request->m()`: the variable stands
            // for the path it was read from.
            return $this->variables->propertySource($receiver->name);
        }
        if ((!$receiver instanceof Expr\PropertyFetch && !$receiver instanceof Expr\NullsafePropertyFetch)
            || !$receiver->name instanceof Identifier) {
            return null;
        }
        $property = '$' . $receiver->name->toString();
        $root = $receiver->var;
        if ($root instanceof Expr\Variable && is_string($root->name)) {
            $type = $root->name === 'this' ? ($class['name'] ?? null) : $this->variables->type($root->name);
            if ($type !== null) {
                return $type . '::' . $property;
            }
        }
        $inner = $this->propertyPath($root, $class);

        return $inner === null ? null : $inner . '::' . $property;
    }

    /**
     * The namespace a concatenation builds a class name in, when it starts with
     * one written out: `'App\\Cards\\' . $name` is `App\\Cards`. A separator
     * written after a runtime part (`'App\\Cards\\' . $segment . '\\' . $name`)
     * puts the class in a namespace below that one, marked `\\**`.
     */
    public static function namespacePrefix(Expr $expression): ?string
    {
        $parts = [];
        $flatten = static function (Expr $part) use (&$flatten, &$parts): void {
            if ($part instanceof Expr\BinaryOp\Concat) {
                $flatten($part->left);
                $flatten($part->right);

                return;
            }
            $parts[] = $part;
        };
        $flatten($expression);
        // The literal parts before the first runtime one are the known prefix.
        $known = '';
        while (($head = $parts[0] ?? null) instanceof Scalar\String_) {
            $known .= $head->value;
            array_shift($parts);
        }
        if (preg_match('/^\\\\?((?:[A-Za-z_][A-Za-z0-9_]*\\\\)+)$/', $known, $match) !== 1) {
            return null;
        }
        $nested = false;
        foreach ($parts as $part) {
            $nested = $nested || ($part instanceof Scalar\String_ && str_contains($part->value, '\\'));
        }

        return rtrim($match[1], '\\') . ($nested ? '\\**' : '');
    }

    /**
     * What `$run()` yields when `$run` holds a closure whose result type was
     * declared, by the method that returned it or by the closure itself.
     */
    private function invokedType(Expr $expression): ?string
    {
        if (!$expression instanceof Expr\FuncCall || !$expression->name instanceof Expr\Variable || !is_string($expression->name->name)) {
            return null;
        }

        return $this->variables->invokes($expression->name->name);
    }

    /**
     * What calling the closure an expression evaluates to yields, when that is
     * declared: a closure literal's own return type, or the docblock of a
     * method of this file that returns one.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    private function closureResult(Expr $expression, ?array $class): ?string
    {
        if (($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) && $expression->returnType instanceof Name) {
            return $this->resolvedClassNameIn($expression->returnType, $class);
        }
        $key = $this->calledMethodKey($expression, $class);

        return $key === null ? null : ($this->closureReturns[$key] ?? null);
    }

    /**
     * Track `$x = $typed->property`, the property read into a local first.
     *
     * A property this file types (`$this->parser`) types the variable; any
     * other is declared in whatever file declares the receiver's type, so the
     * variable keeps the path for the reconciler to finish. Either binding is
     * local flow, so it stays probable. False when the value is no such read.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    private function propertyAssignment(string $variable, Expr $expression, ?array $class): bool
    {
        if (!$expression instanceof Expr\PropertyFetch && !$expression instanceof Expr\NullsafePropertyFetch) {
            return false;
        }
        $declared = $this->declaredReceiverType($expression, $class);
        if ($declared !== null) {
            $this->variables->set($variable, $declared, 'probable');

            return true;
        }
        $path = $this->propertyPath($expression, $class);
        if ($path === null) {
            return false;
        }
        $this->variables->setPropertySource($variable, $path);

        return true;
    }

    /**
     * The declared type of a receiver expression, when one is tracked.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    private function declaredReceiverType(Expr $receiver, ?array $class): ?string
    {
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return $receiver->name === 'this'
                ? ($class['name'] ?? null)
                : $this->variables->type($receiver->name);
        }
        if (($receiver instanceof Expr\PropertyFetch || $receiver instanceof Expr\NullsafePropertyFetch)
            && $receiver->var instanceof Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier) {
            return $class['properties'][$receiver->name->toString()] ?? null;
        }

        return null;
    }

    /**
     * The single class a ternary can yield, ignoring a null branch.
     *
     * Returns null when the branches disagree or when either names something
     * this file cannot resolve: a receiver that might be one of two types is
     * not a receiver this can attribute a call to.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    private function optionalType(Expr $expression, ?array $class): ?string
    {
        if (!$expression instanceof Expr\Ternary) {
            return null;
        }
        $types = [];
        foreach ([$expression->if ?? $expression->cond, $expression->else] as $branch) {
            if ($branch instanceof Expr\ConstFetch && strtolower($branch->name->toString()) === 'null') {
                continue;
            }
            $type = $branch instanceof Expr\New_ && $branch->class instanceof Name
                ? $this->resolvedClassNameIn($branch->class, $class)
                : $this->returnedType($branch, $class);
            if ($type === null) {
                return null;
            }
            $types[$type] = true;
        }

        return count($types) === 1 ? array_key_first($types) : null;
    }

    /**
     * The `Class::method` key, lower-cased, of a call whose receiver is statically known: `$this->m()` or `Foo::m()`.
     *
     * @param array{name: string, parent: ?string, properties: array<string, string>}|null $class
     */
    private function calledMethodKey(Expr $expression, ?array $class): ?string
    {
        if ($expression instanceof Expr\MethodCall
            && $expression->var instanceof Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier) {
            $className = $class['name'] ?? null;

            return $className === null ? null : strtolower($className . '::' . $expression->name->toString());
        }
        if ($expression instanceof Expr\StaticCall
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier) {
            return strtolower($this->resolvedClassNameIn($expression->class, $class) . '::' . $expression->name->toString());
        }

        return null;
    }
}
