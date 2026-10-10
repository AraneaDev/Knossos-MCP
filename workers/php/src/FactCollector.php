<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
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
    use ResolvesClassName;
    use ResolvesDeclarationName;

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $edges = [];

    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];

    /** @var list<array{id: string, name: string, parent: ?string, interfaces: list<string>, properties: array<string, string>}> */
    private array $classes = [];

    /** @var list<array{id: string}> */
    private array $callables = [];

    /**
     * Member names called on a receiver nothing types, keyed by the calling node's id.
     *
     * @var array<string, array<string, true>>
     */
    private array $untypedCalls = [];

    /** The namespace block the traversal is in: its node id, 0 for a file without one. */
    private int $namespaceScope = 0;

    /** The name of that namespace, empty outside one, which a docblock's unqualified class name is read in. */
    private string $namespaceName = '';

    /** Whether this file's module node has been declared; see {@see self::fileModuleId()}. */
    private bool $moduleDeclared = false;

    /** Class names read out of docblocks, through the file's imports. */
    private readonly DocblockTypes $docblocks;

    /** What each local variable in scope holds, as far as the code shows. */
    private readonly VariableTypeScope $variables;

    /** Infers those variables' classes from the code that binds them. */
    private readonly ReceiverTypeInference $receivers;

    public function __construct(private readonly string $relativePath)
    {
        $this->docblocks = new DocblockTypes();
        $this->variables = new VariableTypeScope();
        $this->receivers = new ReceiverTypeInference($this->variables, $this->docblocks);
    }

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
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        $scopes = [];
        $namespaces = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $scopes[spl_object_id($node)] = $node->stmts;
                $namespaces[spl_object_id($node)] = $node->name?->toString() ?? '';
            }
        }
        foreach ($scopes === [] ? [0 => $nodes] : $scopes as $scope => $statements) {
            $this->docblocks->readImports($scope, $statements);
            $this->receivers->readClosureReturns($scope, $namespaces[$scope] ?? '', $statements);
        }
        $this->receivers->readReturnTypes($nodes);

        return null;
    }

    /** Collect whatever facts this node declares as the traversal enters it. */
    #[\Override]
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\FunctionLike) {
            $this->variables->enter($node);
        }
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->closureSignature($node);
        }
        if ($node instanceof Stmt\Namespace_) {
            $this->namespaceScope = spl_object_id($node);
            $this->namespaceName = $node->name?->toString() ?? '';
        }
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
            $this->receivers->assignment($node, $this->currentClass());
        } elseif ($node instanceof Expr\AssignOp\Coalesce) {
            $this->receivers->coalescingAssignment($node, $this->currentClass());
        } elseif ($node instanceof Stmt\Foreach_) {
            $this->receivers->foreachLoop($node, $this->currentClass());
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
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Property || $node instanceof Stmt\ClassMethod) {
            $this->annotationReferences($node);
        }

        return null;
    }

    /**
     * Classes a Doctrine-style annotation names in a string.
     *
     * `@Gedmo\SlugHandler(class="App\Slug\Handler")` and
     * `@ORM\Entity(repositoryClass="App\Repository\X")` hand a class to a
     * library by name, and nothing else refers to it. Only fully qualified
     * names are taken: a short one would need the file's imports to resolve.
     */
    private function annotationReferences(Node $node): void
    {
        $comment = $node->getDocComment();
        if ($comment === null) {
            return;
        }
        preg_match_all(
            '/\b(?:class|repositoryClass|targetEntity|entityClass|handler)\s*=\s*"\\\\{0,2}([A-Za-z_][A-Za-z0-9_]*(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+)"/',
            $comment->getText(),
            $matches,
        );
        $classNames = array_map(static fn(string $className): string => str_replace('\\\\', '\\', $className), $matches[1]);
        // `@AdminEmail` and `@Assert\NotBlank(...)`: an annotation is a class,
        // named through the imports. A tag nothing imports (`@param`) is not.
        preg_match_all('/(?:^|[\s*])@(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/m', $comment->getText(), $tags);
        foreach ($tags[1] as $tag) {
            if (str_starts_with($tag, '\\')) {
                $classNames[] = substr($tag, 1);
                continue;
            }
            $imported = $this->docblocks->imported($this->namespaceScope, $tag);
            if ($imported !== null) {
                $classNames[] = $imported;
            }
        }
        foreach (array_unique($classNames) as $className) {
            $this->addEdge('references', $this->currentSource(), self::reference('class', $className), $node);
        }
    }

    /** Unwind scope on the way out, keeping enclosing-class attribution correct. */
    #[\Override]
    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\FunctionLike) {
            $this->variables->leave();
        }
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
        $nodes = $this->nodes;
        foreach ($nodes as $index => $node) {
            $names = $this->untypedCalls[$node['local_id']] ?? null;
            if ($names === null) {
                continue;
            }
            $names = array_keys($names);
            sort($names);
            $attributes = (array) $node['attributes'];
            $attributes['unresolved_member_calls'] = $names;
            $nodes[$index]['attributes'] = (object) $attributes;
        }

        return $nodes;
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
            if (strcasecmp($parent, 'Symfony\\Component\\Validator\\Constraint') === 0 && $node->getMethod('validatedBy') === null) {
                // Validated by `static::class . 'Validator'` unless its own
                // `validatedBy()` says otherwise; kept only when that class
                // exists.
                $this->addEdge('references', $id, self::reference('class', $name . 'Validator'), $node, attributes: ['speculative' => true]);
            }
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

        $this->classes[] = ['id' => $id, 'name' => $name, 'parent' => $parent, 'interfaces' => $interfaces, 'properties' => []];
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
        $attributes = $this->attributeNames($node->attrGroups);
        $this->addNode($id, 'method', $name, $node->name->toString(), $node, [
            'visibility' => $node->isPublic() ? 'public' : ($node->isProtected() ? 'protected' : 'private'),
            'static' => $node->isStatic(),
            'abstract' => $node->isAbstract(),
            'php_attributes' => $attributes,
            ...(self::isVirtualProperty($node, $attributes) ? ['runtime_invoked' => true] : []),
            ...(self::overridesSupertypeMember($node->name->toString(), $attributes, $class) ? ['overrides' => true] : []),
        ]);
        $this->addEdge('contains', $class['id'], $id, $node);
        $this->callables[] = ['id' => $id];

        $constructor = strtolower($node->name->toString()) === '__construct';
        $this->parametersAndReturn($node->params, $node->returnType, $constructor ? $class['id'] : $id, $constructor);
        $this->documentedElementTypes($node);
    }

    /**
     * Whether JMS Serializer reads this method as a property: `@VirtualProperty`
     * in its docblock or `#[VirtualProperty]`. The serializer calls it by
     * reflection, and no code names it.
     *
     * @param list<string> $attributes
     */
    private static function isVirtualProperty(Stmt\ClassMethod $node, array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if (str_ends_with('\\' . $attribute, '\\VirtualProperty')) {
                return true;
            }
        }
        $comment = $node->getDocComment()?->getText() ?? '';

        return preg_match('/(?:^|[\s*])@(?:[A-Za-z_\\\\]+\\\\)?VirtualProperty\b/m', $comment) === 1;
    }

    /**
     * Whether a method fulfils a member of a type its class extends or
     * implements, by the source's word or by the language's.
     *
     * `#[\Override]` is the source saying so, and PHP checks it. A built-in
     * supertype (`JsonSerializable`, `Countable`, `IteratorAggregate`) is
     * described by the worker's own runtime, so its members are known without
     * loading anything. A dependency's supertype is neither, and is left to
     * the attribute: guessing from its name would hide a method that only
     * happens to share one.
     *
     * @param list<string> $attributes resolved attribute names
     * @param array{parent: ?string, interfaces: list<string>} $class
     */
    private static function overridesSupertypeMember(string $method, array $attributes, array $class): bool
    {
        if (in_array('Override', $attributes, true)) {
            return true;
        }
        foreach ([$class['parent'], ...$class['interfaces']] as $supertype) {
            if ($supertype === null || (!class_exists($supertype, false) && !interface_exists($supertype, false))) {
                continue;
            }
            $reflection = new \ReflectionClass($supertype);
            if ($reflection->isInternal() && $reflection->hasMethod($method)) {
                return true;
            }
        }

        return false;
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
        $this->callables[] = ['id' => $id];
        $this->parametersAndReturn($node->params, $node->returnType, $id, false);
        $this->documentedElementTypes($node);
    }

    /**
     * Remember the element type a parameter's docblock gives an array or
     * iterable parameter (`@param list<Fact> $facts`), so a loop over it types
     * its value. The native type says only `array`, and a loop over a list of
     * collaborators is how most of them are reached.
     */
    private function documentedElementTypes(Stmt\ClassMethod|Stmt\Function_ $node): void
    {
        $parameters = [];
        foreach ($node->params as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $parameters[$param->var->name] = true;
            }
        }
        $class = $this->currentClass()['name'] ?? null;
        foreach (DocblockTypes::documentedParameters($node->getDocComment()?->getText() ?? '') as $name => $type) {
            $element = isset($parameters[$name]) ? DocblockTypes::elementType($type) : null;
            $resolved = $element === null ? null : $this->docblocks->documentedClass($element, $this->namespaceScope, $this->namespaceName, $class);
            if ($resolved !== null) {
                $this->variables->setElement($name, $resolved);
            }
        }
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
            if ($constructor && $param->flags !== 0 && is_string($param->var->name)) {
                $this->promotedProperty($param, $param->var->name, $types);
            }
            if ($types !== [] && is_string($param->var->name)) {
                $this->variables->set($param->var->name, $types[0]);
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

    /**
     * Emit a promoted constructor parameter as the property it declares.
     *
     * A promoted parameter is a property like any other, and another file's
     * `$context->options->flag()` is resolved through its declared type, which
     * the reconciler reads from the property's `references` edge.
     *
     * @param list<string> $types
     */
    private function promotedProperty(Node\Param $param, string $name, array $types): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        $canonical = $class['name'] . '::$' . $name;
        $id = self::reference('property', $canonical);
        $this->addNode($id, 'property', $canonical, '$' . $name, $param, ['promoted' => true]);
        $this->addEdge('contains', $class['id'], $id, $param);
        foreach ($types as $type) {
            $this->addEdge('references', $id, self::reference('class', $type), $param->type ?? $param);
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

    /** Emit an `instantiates` edge for a `new` whose class is statically known. */
    private function newExpression(Expr\New_ $node): void
    {
        if ($node->class instanceof Name) {
            $this->addEdge('constructs', $this->currentSource(), self::reference('class', $this->resolvedClassName($node->class)), $node);

            return;
        }
        // `new ('App\\Cards\\' . $name)` or `new $card` after `$card = 'App\\Cards\\' . $name`:
        // any class in that namespace may be the one built.
        $prefix = match (true) {
            $node->class instanceof Expr\Variable && is_string($node->class->name) => $this->variables->prefix($node->class->name),
            $node->class instanceof Expr => ReceiverTypeInference::namespacePrefix($node->class),
            default => null,
        };
        if ($prefix !== null) {
            $this->addEdge('references', $this->currentSource(), 'php:class_prefix:' . $prefix, $node, 'probable');
        }
    }

    /**
     * Bind a closure's or arrow function's parameters by their declared
     * types, and record the classes its signature names as references of
     * the code that writes it. An untyped parameter shadows a captured
     * variable of the same name.
     */
    private function closureSignature(Expr\Closure|Expr\ArrowFunction $node): void
    {
        $source = $this->currentSource();
        foreach ($node->params as $param) {
            $types = $this->typeNames($param->type);
            foreach ($types as $type) {
                $this->addEdge('references', $source, self::reference('class', $type), $param);
            }
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                if ($types === []) {
                    $this->variables->clear($param->var->name);
                } else {
                    $this->variables->set($param->var->name, $types[0]);
                }
            }
        }
        foreach ($this->typeNames($node->returnType) as $type) {
            $this->addEdge('references', $source, self::reference('class', $type), $node->returnType ?? $node);
        }
    }

    /** Emit a `calls` edge for a static call, resolving `self`/`static`/`parent` against the current class. */
    private function staticCall(Expr\StaticCall $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }
        $source = $this->currentSource();
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
     * mention of the enclosing class, in any case, are internal traffic, not usage, and
     * counting them would make every class with one internal static call look
     * reachable. `parent::` resolves to a different class and is kept.
     */
    private function classReference(Node $class, Node $evidence): void
    {
        if (!$class instanceof Name) {
            return;
        }
        $source = $this->currentSource();
        $resolved = $this->resolvedClassName($class);
        if (strcasecmp($resolved, $this->currentClass()['name'] ?? '') === 0) {
            return;
        }
        $this->addEdge('references', $source, self::reference('class', $resolved), $evidence);
    }

    /** Emit a `calls` edge, using the tracked variable type to name the receiver where possible. */
    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }
        $source = $this->currentSource();

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
                $class = $this->variables->type($node->var->name);
                $confidence = $this->variables->confidence($node->var->name);
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
            $class = $this->receivers->returnedType($node->var, $this->currentClass());
        }

        if ($class !== null) {
            $this->addEdge('calls', $source, self::reference('method', $class . '::' . $node->name->toString()), $node, $confidence);

            return;
        }
        $path = $this->propertyPath($node->var);
        if ($path !== null) {
            // A property of a typed receiver (`$context->options->flag()`),
            // declared in whatever file declares that type, often as a
            // promoted constructor parameter. Resolved by the reconciler from
            // every file's declared property types, and dropped there if a
            // step names no typed property.
            $this->addEdge(
                'calls',
                $source,
                self::reference('method_of_property', $path . '::' . $node->name->toString()),
                $node,
                'probable',
            );

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

            return;
        }
        // Nothing types the receiver. A method by this name may be what the
        // call reaches, so the caller records it for dead-code confidence.
        $this->untypedCalls[$source][$node->name->toString()] = true;
    }

    /**
     * A receiver read through properties of a typed root, as the path the
     * reconciler resolves: `Type::$a::$b` for `$x->a->b`, where `$x` is
     * `$this` or a variable of a known type. Null when the root's type is
     * unknown or a step is not a plain property name.
     */
    private function propertyPath(Expr $receiver): ?string
    {
        if ((!$receiver instanceof Expr\PropertyFetch && !$receiver instanceof Expr\NullsafePropertyFetch)
            || !$receiver->name instanceof Identifier) {
            return null;
        }
        $property = '$' . $receiver->name->toString();
        $root = $receiver->var;
        if ($root instanceof Expr\Variable && is_string($root->name)) {
            $type = $root->name === 'this' ? ($this->currentClass()['name'] ?? null) : $this->variables->type($root->name);

            return $type === null ? null : $type . '::' . $property;
        }
        $inner = $this->propertyPath($root);

        return $inner === null ? null : $inner . '::' . $property;
    }

    /** The call a receiver's value came from, whether held in a variable or used inline. */
    private function receiverReturnSource(Expr $receiver): ?string
    {
        if ($receiver instanceof Expr\Variable && is_string($receiver->name) && $receiver->name !== 'this') {
            return $this->variables->returnSource($receiver->name);
        }

        return $receiver instanceof Expr\MethodCall || $receiver instanceof Expr\StaticCall
            ? $this->receivers->calleeReference($receiver, $this->currentClass())
            : null;
    }

    /** Emit a `calls` edge to a free function. */
    private function functionCall(Expr\FuncCall $node): void
    {
        if ($node->name instanceof Name) {
            $this->addEdge('calls', $this->currentSource(), $this->functionReference($node->name), $node);
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
        return $this->resolvedClassNameIn($name, $this->currentClass());
    }


    /**
     * The reference a function call names, deferring the ambiguous case.
     *
     * PHP resolves an unqualified call inside a namespace to that namespace's
     * function when one exists and to the global function otherwise — a choice
     * that depends on declarations in other files, which a per-file scanner
     * cannot see. Naming it as written resolved every such call to the global
     * candidate, so a namespaced free function called from its own namespace
     * gained a phantom global twin and carried no inbound edge itself.
     *
     * A `use function` import and a leading backslash are already unambiguous:
     * the parser marks both fully qualified, and they are named outright.
     */
    private function functionReference(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return self::reference('function', $resolved->toString());
        }
        $namespaced = $name->getAttribute('namespacedName');
        if (!$name instanceof Name\FullyQualified
            && $namespaced instanceof Name
            && $namespaced->toString() !== $name->toString()) {
            return self::reference('namespaced_function', $namespaced->toString());
        }

        return self::reference('function', $name->toString());
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

    /**
     * Record an edge fact with its evidence location, defaulting to `certain`
     * because most edges here are proven by syntax.
     *
     * @param array<string, mixed> $attributes
     */
    private function addEdge(string $kind, string $source, string $target, Node $evidence, string $confidence = 'certain', array $attributes = []): void
    {
        $this->edges[] = [
            'kind' => $kind,
            'source' => $source,
            'target' => $target,
            'origin' => 'ast',
            'confidence' => $confidence,
            'evidence' => $this->evidence($evidence),
            'attributes' => (object) $attributes,
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
     * @return array{id: string, name: string, parent: ?string, interfaces: list<string>, properties: array<string, string>}|null
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

    /**
     * The node a fact should be attributed to: the callable, else the class, else the file itself.
     *
     * PHP has no module scope, so a procedural script — an entry point, a route
     * file, a tools script — makes its calls from file scope, where neither a
     * callable nor a class encloses them. Attributing those to nothing dropped
     * them outright, leaving anything reached only from a script body with no
     * inbound edge and reading as dead code.
     */
    private function currentSource(): string
    {
        return $this->currentCallableId() ?? ($this->currentClass()['id'] ?? $this->fileModuleId());
    }

    /**
     * The file's own module node, declared the first time file-scope code needs it.
     *
     * Declared lazily so a file that only declares types — which is most of
     * them — contributes no module, keeping the graph free of a node per file
     * that nothing could ever reference.
     */
    private function fileModuleId(): string
    {
        $id = self::reference('module', $this->relativePath);
        if (!$this->moduleDeclared) {
            $this->moduleDeclared = true;
            $this->nodes[] = [
                'local_id' => $id,
                'kind' => 'module',
                'canonical_name' => $this->relativePath,
                'display_name' => basename($this->relativePath),
                'origin' => 'ast',
                'confidence' => 'certain',
                'evidence' => ['path' => $this->relativePath, 'start_line' => 1, 'end_line' => 1],
                // A PHP module node exists only because the file has a body
                // that runs, which by definition is entered from outside the
                // graph — a shell, a web server, a CI step.
                'attributes' => (object) ['executable' => true],
            ];
        }

        return $id;
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

    /** The local id of a declaration: `php:<kind>:<name>`, without a leading backslash. */
    private static function reference(string $kind, string $canonicalName): string
    {
        return 'php:' . $kind . ':' . ltrim($canonicalName, '\\');
    }
}
