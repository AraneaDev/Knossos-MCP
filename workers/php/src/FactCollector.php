<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the language-level facts for one PHP file.
 *
 * Declarations, inheritance, signature types, calls, and instantiations, with light
 * variable-type tracking so `$x = new Foo; $x->bar()` resolves to Foo rather than
 * being dropped. Anything not provable from syntax is recorded at lower confidence
 * or not at all.
 */
final class FactCollector extends NodeVisitorAbstract
{
    use ResolvesDeclarationName;

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $edges = [];

    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];

    /** @var list<array{id: string, name: string, parent: ?string, properties: array<string, string>}> */
    private array $classes = [];

    /** @var list<array{id: string, variables: array<string, array{type: ?string, confidence: string, returned_by?: string}>}> */
    private array $callables = [];

    /**
     * Declared return types of the methods this file declares, keyed `Class::method`.
     *
     * Collected up front because a method is routinely called above its own
     * declaration, and a single-pass visitor would not have read the signature
     * yet when it reaches the call.
     *
     * @var array<string, string>
     */
    private array $returnTypes = [];

    public function __construct(private readonly string $relativePath) {}

    /**
     * Index the file's method return types before collecting facts from it.
     *
     * A helper reached only through `$x = $this->make(); $x->use()` otherwise
     * has no inbound edge at all and reads as dead code, which is how this
     * repository's own `server_info` and `diagnose_runtime` entry points came
     * to report the environment methods they call on every request as unused.
     *
     * @param list<Node> $nodes
     */
    public function beforeTraverse(array $nodes): ?array
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
                    $this->returnTypes[$className . '::' . $method->name->toString()] = $method->returnType->toString();
                }
            }
        }

        return null;
    }
    /** Collect whatever facts this node declares as the traversal enters it. */

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->enterClassLike($node);
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->enterMethod($node);
        } elseif ($node instanceof Stmt\Function_) {
            $this->enterFunction($node);
        } elseif ($node instanceof Stmt\TraitUse) {
            $this->traitUse($node);
        } elseif ($node instanceof Stmt\Property) {
            $this->property($node);
        } elseif ($node instanceof Expr\Assign) {
            $this->assignment($node);
        } elseif ($node instanceof Expr\New_) {
            $this->newExpression($node);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->staticCall($node);
        } elseif ($node instanceof Expr\ClassConstFetch) {
            $this->classReference($node->class, $node);
        } elseif ($node instanceof Expr\StaticPropertyFetch) {
            $this->classReference($node->class, $node);
        } elseif ($node instanceof Expr\Instanceof_) {
            $this->classReference($node->class, $node);
        } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $this->methodCall($node);
        } elseif ($node instanceof Expr\FuncCall) {
            $this->functionCall($node);
        }

        return null;
    }
    /** Unwind scope on the way out, keeping enclosing-class attribution correct. */

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            array_pop($this->callables);
        } elseif ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }

        return null;
    }

    /**
     * The node facts collected from this file.
     *
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * The edge facts collected from this file.
     *
     * @return list<array<string, mixed>>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    /**
     * What could not be analysed, reported rather than thrown.
     *
     * @return list<array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** Emit the class/interface/trait/enum node and its inheritance edges. */
    private function enterClassLike(Stmt\ClassLike $node): void
    {
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $name = $this->declarationName($node, $this->relativePath);
        $id = self::reference($kind, $name);
        $parent = $node instanceof Stmt\Class_ && $node->extends instanceof Name
            ? $this->name($node->extends)
            : null;
        $interfaces = [];
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            $interfaces = array_map(fn(Name $interface): string => $this->name($interface), $node->implements);
        } elseif ($node instanceof Stmt\Interface_) {
            $interfaces = array_map(fn(Name $interface): string => $this->name($interface), $node->extends);
        }

        $this->addNode($id, $kind, $name, $node->name?->toString() ?? '{anonymous}', $node, [
            'abstract' => $node instanceof Stmt\Class_ && $node->isAbstract(),
            'final' => $node instanceof Stmt\Class_ && $node->isFinal(),
            'readonly' => $node instanceof Stmt\Class_ && $node->isReadonly(),
            'extends' => $parent,
            'implements' => $interfaces,
            'php_attributes' => $this->attributeNames($node->attrGroups),
        ]);

        if ($node instanceof Stmt\Class_ && $node->extends instanceof Name) {
            $this->addEdge('extends', $id, self::reference('class', $parent), $node->extends);
        }
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->addEdge('implements', $id, self::reference('interface', $this->name($interface)), $interface);
            }
        }
        if ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->addEdge('extends', $id, self::reference('interface', $this->name($interface)), $interface);
            }
        }

        $this->classes[] = ['id' => $id, 'name' => $name, 'parent' => $parent, 'properties' => []];
    }

    /** Emit a method node, its edge to the declaring class, and its signature types. */
    private function enterMethod(Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }

        $name = $class['name'] . '::' . $node->name->toString();
        $id = self::reference('method', $name);
        $this->addNode($id, 'method', $name, $node->name->toString(), $node, [
            'visibility' => $node->isPublic() ? 'public' : ($node->isProtected() ? 'protected' : 'private'),
            'static' => $node->isStatic(),
            'abstract' => $node->isAbstract(),
            'php_attributes' => $this->attributeNames($node->attrGroups),
        ]);
        $this->addEdge('contains', $class['id'], $id, $node);
        $this->callables[] = ['id' => $id, 'variables' => []];

        $constructor = strtolower($node->name->toString()) === '__construct';
        $this->parametersAndReturn($node->params, $node->returnType, $constructor ? $class['id'] : $id, $constructor);
    }

    /**
     * Resolved attribute class names, so attribute-driven wiring is visible statically.
     *
     * @param list<Node\AttributeGroup> $groups @return list<string>
     */
    private function attributeNames(array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $names[] = $this->name($attribute->name);
            }
        }
        return array_values(array_unique($names));
    }

    /** Emit a free function node and its signature types. */
    private function enterFunction(Stmt\Function_ $node): void
    {
        $name = $this->declarationName($node, $this->relativePath);
        $id = self::reference('function', $name);
        $this->addNode($id, 'function', $name, $node->name->toString(), $node);
        $this->callables[] = ['id' => $id, 'variables' => []];
        $this->parametersAndReturn($node->params, $node->returnType, $id, false);
    }

    /**
     * Emit edges for parameter and return types, which is most of what couples one class to another.
     *
     * @param list<Node\Param> $params
     */
    private function parametersAndReturn(array $params, ?Node $returnType, string $source, bool $constructor): void
    {
        foreach ($params as $param) {
            $types = $this->typeNames($param->type);
            foreach ($types as $type) {
                $this->addEdge($constructor ? 'injects' : 'references', $source, self::reference('class', $type), $param);
            }
            if ($types !== [] && is_string($param->var->name)) {
                $this->setVariableType($param->var->name, $types[0]);
                if ($constructor && $param->flags !== 0) {
                    $this->setPropertyType($param->var->name, $types[0]);
                }
            }
        }

        foreach ($this->typeNames($returnType) as $type) {
            $this->addEdge('returns', $this->currentCallableId() ?? $source, self::reference('class', $type), $returnType);
        }
    }

    /** Emit `uses` edges; a trait's members otherwise appear to belong to nothing. */
    private function traitUse(Stmt\TraitUse $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        foreach ($node->traits as $trait) {
            $this->addEdge('uses_trait', $class['id'], self::reference('trait', $this->name($trait)), $trait);
        }
    }

    /** Emit a property node and an edge for its declared type. */
    private function property(Stmt\Property $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        $types = $this->typeNames($node->type);
        foreach ($node->props as $property) {
            $name = $class['name'] . '::$' . $property->name->toString();
            $id = self::reference('property', $name);
            $this->addNode($id, 'property', $name, '$' . $property->name->toString(), $property);
            $this->addEdge('contains', $class['id'], $id, $property);
            foreach ($types as $type) {
                $this->addEdge('references', $id, self::reference('class', $type), $node->type ?? $node);
            }
            if ($types !== []) {
                $this->setPropertyType($property->name->toString(), $types[0]);
            }
        }
    }

    /** Track `$x = new Foo` so later `$x->method()` calls can be attributed to Foo. */
    private function assignment(Expr\Assign $node): void
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name)) {
            return;
        }
        if ($node->expr instanceof Expr\New_ && $node->expr->class instanceof Name) {
            // Inferred from local construction flow — only ever probable.
            $this->setVariableType($node->var->name, $this->resolvedClassName($node->expr->class), 'probable');

            return;
        }
        $returned = $this->returnedType($node->expr);
        if ($returned !== null) {
            // The type is declared, but the binding to this variable is local
            // flow like the `new` case above, so it stays probable.
            $this->setVariableType($node->var->name, $returned, 'probable');

            return;
        }
        $optional = $this->optionalType($node->expr);
        if ($optional !== null) {
            // `$x = $flag ? new Y() : null` is how PHP spells an optional
            // collaborator; losing the type there loses every call through it.
            $this->setVariableType($node->var->name, $optional, 'probable');

            return;
        }
        $callee = $this->calleeReference($node->expr);
        if ($callee !== null) {
            // The receiver is whatever that call returns, and the declaration
            // that would say what is in another file. Record the call so a
            // member access on this variable can name it; the reconciler, which
            // sees every file, finishes the resolution.
            $this->setVariableReturnSource($node->var->name, $callee);

            return;
        }
        // Reassignment to any untracked value invalidates the inferred type so a
        // stale `$x = new A; …; $x = something(); $x->m()` no longer resolves to A.
        $this->clearVariableType($node->var->name);
    }

    /**
     * The declaring reference of a call whose receiver is statically known, if any.
     *
     * `Foo::make()` names its declaration outright; `$this->make()` names it once
     * the enclosing class is known. Anything else could dispatch anywhere.
     */
    private function calleeReference(Expr $expression): ?string
    {
        if ($expression instanceof Expr\MethodCall
            && $expression->var instanceof Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier) {
            $class = $this->currentClass()['name'] ?? null;

            return $class === null ? null : $class . '::' . $expression->name->toString();
        }
        if ($expression instanceof Expr\StaticCall
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier) {
            return $this->resolvedClassName($expression->class) . '::' . $expression->name->toString();
        }
        if ($expression instanceof Expr\MethodCall && $expression->name instanceof Identifier) {
            // A call on a collaborator whose type is declared — an injected
            // property or a typed parameter. The collaborator's own declaration
            // is usually in another file, which is exactly the case this exists
            // for.
            $receiver = $this->declaredReceiverType($expression->var);

            return $receiver === null ? null : $receiver . '::' . $expression->name->toString();
        }

        return null;
    }

    /** The declared type of a receiver expression, when one is tracked. */
    private function declaredReceiverType(Expr $receiver): ?string
    {
        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return $receiver->name === 'this'
                ? ($this->currentClass()['name'] ?? null)
                : $this->variableType($receiver->name);
        }
        if (($receiver instanceof Expr\PropertyFetch || $receiver instanceof Expr\NullsafePropertyFetch)
            && $receiver->var instanceof Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier) {
            return $this->propertyType($receiver->name->toString());
        }

        return null;
    }

    /**
     * The single class a ternary can yield, ignoring a null branch.
     *
     * Returns null when the branches disagree or when either names something
     * this file cannot resolve: a receiver that might be one of two types is
     * not a receiver this can attribute a call to.
     */
    private function optionalType(Expr $expression): ?string
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
                ? $this->resolvedClassName($branch->class)
                : $this->returnedType($branch);
            if ($type === null) {
                return null;
            }
            $types[$type] = true;
        }

        return count($types) === 1 ? array_key_first($types) : null;
    }

    /**
     * The class a call to one of this file's own methods is declared to return, if any.
     *
     * Only calls whose receiver is statically known are considered: `$this->m()`
     * and a static call naming a class. A call on any other receiver could be
     * dispatched anywhere, and guessing there would trade a missing edge for a
     * wrong one.
     */
    private function returnedType(Expr $expression): ?string
    {
        if ($expression instanceof Expr\MethodCall
            && $expression->var instanceof Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Identifier) {
            $class = $this->currentClass()['name'] ?? null;

            return $class === null ? null : ($this->returnTypes[$class . '::' . $expression->name->toString()] ?? null);
        }
        if ($expression instanceof Expr\StaticCall
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier) {
            return $this->returnTypes[$this->resolvedClassName($expression->class) . '::' . $expression->name->toString()] ?? null;
        }

        return null;
    }

    /** Emit an `instantiates` edge for a `new` whose class is statically known. */
    private function newExpression(Expr\New_ $node): void
    {
        $source = $this->currentSource();
        if ($source !== null && $node->class instanceof Name) {
            $this->addEdge('constructs', $source, self::reference('class', $this->resolvedClassName($node->class)), $node);
        }
    }

    /** Emit a `calls` edge for a static call, resolving `self`/`static`/`parent` against the current class. */
    private function staticCall(Expr\StaticCall $node): void
    {
        $source = $this->currentSource();
        if ($source === null || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }
        $class = $this->resolvedClassName($node->class);
        $this->addEdge('calls', $source, self::reference('method', $class . '::' . $node->name->toString()), $node);
        $this->classReference($node->class, $node);
    }

    /**
     * Record that the enclosing symbol names a class in an expression position:
     * `Foo::bar()`, `Foo::CONST`, `Foo::class`, `Foo::$prop`, `x instanceof Foo`.
     *
     * The call and constant edges point at the *member*, so without this a class
     * or enum reached only through its static members has no inbound edge of its
     * own and reads as unreferenced — the same gap parameter and property types
     * already close by edging `references` to the declaring type.
     *
     * A class naming itself is skipped: `self::`, `static::`, and an explicit
     * mention of the enclosing class are internal traffic, not usage, and
     * counting them would make every class with one internal static call look
     * reachable. `parent::` resolves to a different class and is kept.
     */
    private function classReference(Node $class, Node $evidence): void
    {
        $source = $this->currentSource();
        if ($source === null || !$class instanceof Name) {
            return;
        }
        $resolved = $this->resolvedClassName($class);
        if ($resolved === ($this->currentClass()['name'] ?? null)) {
            return;
        }
        $this->addEdge('references', $source, self::reference('class', $resolved), $evidence);
    }

    /** Emit a `calls` edge, using the tracked variable type to name the receiver where possible. */
    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $node): void
    {
        $source = $this->currentSource();
        if ($source === null || !$node->name instanceof Identifier) {
            return;
        }

        $class = null;
        // Declared param/property types stay certain; a type inferred from a
        // local `$x = new Y` assignment is only ever probable (the variable may
        // be conditional or reassigned before the call).
        $confidence = 'certain';
        if ($node->var instanceof Expr\New_) {
            // `(new Y())->m()` names its receiver inline, so — unlike a variable
            // assigned earlier — there is nothing that could reassign it before
            // the call. An anonymous class has no name and is skipped.
            $class = $node->var->class instanceof Name
                ? $this->resolvedClassName($node->var->class)
                : null;
        } elseif ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
            if ($node->var->name === 'this') {
                $class = $this->currentClass()['name'] ?? null;
            } else {
                $class = $this->variableType($node->var->name);
                $confidence = $this->variableConfidence($node->var->name);
            }
        } elseif (
            ($node->var instanceof Expr\PropertyFetch || $node->var instanceof Expr\NullsafePropertyFetch)
            && $node->var->var instanceof Expr\Variable
            && $node->var->var->name === 'this'
            && $node->var->name instanceof Identifier
        ) {
            $class = $this->propertyType($node->var->name->toString());
        } elseif ($node->var instanceof Expr\MethodCall || $node->var instanceof Expr\StaticCall) {
            // `$this->make()->use()`: the receiver is the inner call itself, so
            // its declared return type names it with nothing in between that
            // could have reassigned it.
            $class = $this->returnedType($node->var);
        }

        if ($class !== null) {
            $this->addEdge('calls', $source, self::reference('method', $class . '::' . $node->name->toString()), $node, $confidence);

            return;
        }
        $returnedBy = $this->receiverReturnSource($node->var);
        if ($returnedBy !== null) {
            // Named indirectly: the member, and the call whose result it is on.
            // Resolved by the reconciler once every file's return types are known,
            // and dropped there if they do not name a member that exists.
            $this->addEdge(
                'calls',
                $source,
                self::reference('method_of_return', $returnedBy . '::' . $node->name->toString()),
                $node,
                'probable',
            );
        }
    }

    /** The call a receiver's value came from, whether held in a variable or used inline. */
    private function receiverReturnSource(Expr $receiver): ?string
    {
        if ($receiver instanceof Expr\Variable && is_string($receiver->name) && $receiver->name !== 'this') {
            return $this->variableReturnSource($receiver->name);
        }

        return $receiver instanceof Expr\MethodCall || $receiver instanceof Expr\StaticCall
            ? $this->calleeReference($receiver)
            : null;
    }

    /** Emit a `calls` edge to a free function. */
    private function functionCall(Expr\FuncCall $node): void
    {
        $source = $this->currentSource();
        if ($source !== null && $node->name instanceof Name) {
            $this->addEdge('calls', $source, self::reference('function', $this->name($node->name)), $node);
        }
    }

    /**
     * Flatten a type declaration to class names, walking union, intersection, and nullable forms.
     *
     * @return list<string>
     */
    private function typeNames(?Node $type): array
    {
        if ($type instanceof Name) {
            return [$this->resolvedClassName($type)];
        }
        if ($type instanceof Node\NullableType) {
            return $this->typeNames($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $types = [];
            foreach ($type->types as $inner) {
                $types = array_merge($types, $this->typeNames($inner));
            }
            return array_values(array_unique($types));
        }

        return [];
    }

    /** Fully-qualified name, honouring the parser's resolved name attribute when present. */
    private function resolvedClassName(Name $name): string
    {
        $value = strtolower($name->toString());
        $class = $this->currentClass();
        return match ($value) {
            'self', 'static' => $class['name'] ?? $name->toString(),
            'parent' => $class['parent'] ?? $name->toString(),
            default => $this->name($name),
        };
    }


    /** The name as written, for evidence where the resolved form would obscure the source. */
    private function name(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }
        return $name->toString();
    }

    /**
     * Record a node fact with its evidence location.
     *
     * @param array<string, mixed> $attributes
     */
    private function addNode(
        string $localId,
        string $kind,
        string $canonicalName,
        string $displayName,
        Node $evidence,
        array $attributes = [],
    ): void {
        $this->nodes[] = [
            'local_id' => $localId,
            'kind' => $kind,
            'canonical_name' => $canonicalName,
            'display_name' => $displayName,
            'origin' => 'ast',
            'confidence' => 'certain',
            'evidence' => $this->evidence($evidence),
            'attributes' => (object) $attributes,
        ];
    }

    /** Record an edge fact, defaulting to `certain` because most edges here are proven by syntax. */
    private function addEdge(string $kind, string $source, string $target, Node $evidence, string $confidence = 'certain'): void
    {
        $this->edges[] = [
            'kind' => $kind,
            'source' => $source,
            'target' => $target,
            'origin' => 'ast',
            'confidence' => $confidence,
            'evidence' => $this->evidence($evidence),
            'attributes' => (object) [],
        ];
    }

    /**
     * The file and line span a fact points back to, which is what makes it checkable.
     *
     * @return array{path: string, start_line: int, end_line: int}
     */
    private function evidence(Node $node): array
    {
        $start = max(1, $node->getStartLine());
        return [
            'path' => $this->relativePath,
            'start_line' => $start,
            'end_line' => max($start, $node->getEndLine()),
        ];
    }

    /**
     * The innermost enclosing class-like declaration, or null at file scope.
     *
     * @return array{id: string, name: string, parent: ?string, properties: array<string, string>}|null
     */
    private function currentClass(): ?array
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    /** The innermost enclosing method or function id, used as an edge source. */
    private function currentCallableId(): ?string
    {
        return $this->callables === [] ? null : $this->callables[array_key_last($this->callables)]['id'];
    }

    /** The node a fact should be attributed to: the callable if inside one, else the class. */
    private function currentSource(): ?string
    {
        return $this->currentCallableId() ?? ($this->currentClass()['id'] ?? null);
    }

    /** Remember a variable's inferred class so later calls on it can be resolved. */
    private function setVariableType(string $variable, string $type, string $confidence = 'certain'): void
    {
        if ($this->callables !== []) {
            $this->callables[array_key_last($this->callables)]['variables'][$variable] = [
                'type' => $type,
                'confidence' => $confidence,
            ];
        }
    }

    /**
     * Remember that a variable holds whatever a named call returned.
     *
     * Kept instead of a type because the declaration that names the type is in
     * another file; the reference this records is enough for the reconciler,
     * which sees them all, to finish the resolution.
     */
    private function setVariableReturnSource(string $variable, string $callee): void
    {
        if ($this->callables !== []) {
            $this->callables[array_key_last($this->callables)]['variables'][$variable] = [
                'type' => null,
                'confidence' => 'probable',
                'returned_by' => $callee,
            ];
        }
    }

    /** The call a variable's value came from, when its type was not resolvable here. */
    private function variableReturnSource(string $variable): ?string
    {
        return $this->callables === []
            ? null
            : ($this->callables[array_key_last($this->callables)]['variables'][$variable]['returned_by'] ?? null);
    }

    /** Forget a variable's type when it is reassigned to something unknown. */
    private function clearVariableType(string $variable): void
    {
        if ($this->callables !== []) {
            unset($this->callables[array_key_last($this->callables)]['variables'][$variable]);
        }
    }

    /** The tracked class for a variable, or null when it was never inferred. */
    private function variableType(string $variable): ?string
    {
        return $this->callables === []
            ? null
            : ($this->callables[array_key_last($this->callables)]['variables'][$variable]['type'] ?? null);
    }
    /** How far a tracked variable's type is inferred, so a guess is never recorded as proven. */

    private function variableConfidence(string $variable): string
    {
        return $this->callables === []
            ? 'certain'
            : ($this->callables[array_key_last($this->callables)]['variables'][$variable]['confidence'] ?? 'certain');
    }
    /** Remember a property's declared type for resolving calls on `$this->x`. */

    private function setPropertyType(string $property, string $type): void
    {
        if ($this->classes !== []) {
            $this->classes[array_key_last($this->classes)]['properties'][$property] = $type;
        }
    }
    /** The tracked type for a property, or null when it was never declared. */

    private function propertyType(string $property): ?string
    {
        return $this->classes === []
            ? null
            : ($this->classes[array_key_last($this->classes)]['properties'][$property] ?? null);
    }
    /** Emit a reference edge to a named class, the weakest form of coupling recorded. */

    private static function reference(string $kind, string $canonicalName): string
    {
        return 'php:' . $kind . ':' . ltrim($canonicalName, '\\');
    }
}
