<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final class FactCollector extends NodeVisitorAbstract
{
    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $edges = [];

    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];

    /** @var list<array{id: string, name: string, parent: ?string, properties: array<string, string>}> */
    private array $classes = [];

    /** @var list<array{id: string, variables: array<string, array{type: string, confidence: string}>}> */
    private array $callables = [];

    public function __construct(private readonly string $relativePath) {}

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
        } elseif ($node instanceof Expr\MethodCall) {
            $this->methodCall($node);
        } elseif ($node instanceof Expr\FuncCall) {
            $this->functionCall($node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            array_pop($this->callables);
        } elseif ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    private function enterClassLike(Stmt\ClassLike $node): void
    {
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $name = $this->declarationName($node);
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

    /** @param list<Node\AttributeGroup> $groups @return list<string> */
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

    private function enterFunction(Stmt\Function_ $node): void
    {
        $name = $this->declarationName($node);
        $id = self::reference('function', $name);
        $this->addNode($id, 'function', $name, $node->name->toString(), $node);
        $this->callables[] = ['id' => $id, 'variables' => []];
        $this->parametersAndReturn($node->params, $node->returnType, $id, false);
    }

    /** @param list<Node\Param> $params */
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
        // Reassignment to any untracked value invalidates the inferred type so a
        // stale `$x = new A; …; $x = something(); $x->m()` no longer resolves to A.
        $this->clearVariableType($node->var->name);
    }

    private function newExpression(Expr\New_ $node): void
    {
        $source = $this->currentSource();
        if ($source !== null && $node->class instanceof Name) {
            $this->addEdge('constructs', $source, self::reference('class', $this->resolvedClassName($node->class)), $node);
        }
    }

    private function staticCall(Expr\StaticCall $node): void
    {
        $source = $this->currentSource();
        if ($source === null || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }
        $class = $this->resolvedClassName($node->class);
        $this->addEdge('calls', $source, self::reference('method', $class . '::' . $node->name->toString()), $node);
    }

    private function methodCall(Expr\MethodCall $node): void
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
        if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
            if ($node->var->name === 'this') {
                $class = $this->currentClass()['name'] ?? null;
            } else {
                $class = $this->variableType($node->var->name);
                $confidence = $this->variableConfidence($node->var->name);
            }
        } elseif (
            $node->var instanceof Expr\PropertyFetch
            && $node->var->var instanceof Expr\Variable
            && $node->var->var->name === 'this'
            && $node->var->name instanceof Identifier
        ) {
            $class = $this->propertyType($node->var->name->toString());
        }

        if ($class !== null) {
            $this->addEdge('calls', $source, self::reference('method', $class . '::' . $node->name->toString()), $node, $confidence);
        }
    }

    private function functionCall(Expr\FuncCall $node): void
    {
        $source = $this->currentSource();
        if ($source !== null && $node->name instanceof Name) {
            $this->addEdge('calls', $source, self::reference('function', $this->name($node->name)), $node);
        }
    }

    /** @return list<string> */
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

    private function declarationName(Stmt\ClassLike|Stmt\Function_ $node): string
    {
        if (isset($node->namespacedName)) {
            return $node->namespacedName->toString();
        }
        if ($node instanceof Stmt\Class_ && $node->name === null) {
            return sprintf('{anonymous}@%s:%d', $this->relativePath, max(1, $node->getStartLine()));
        }

        return $node->name?->toString() ?? '{anonymous}';
    }

    private function name(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return $resolved->toString();
        }
        return $name->toString();
    }

    /** @param array<string, mixed> $attributes */
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

    /** @return array{path: string, start_line: int, end_line: int} */
    private function evidence(Node $node): array
    {
        $start = max(1, $node->getStartLine());
        return [
            'path' => $this->relativePath,
            'start_line' => $start,
            'end_line' => max($start, $node->getEndLine()),
        ];
    }

    /** @return array{id: string, name: string, parent: ?string, properties: array<string, string>}|null */
    private function currentClass(): ?array
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    private function currentCallableId(): ?string
    {
        return $this->callables === [] ? null : $this->callables[array_key_last($this->callables)]['id'];
    }

    private function currentSource(): ?string
    {
        return $this->currentCallableId() ?? ($this->currentClass()['id'] ?? null);
    }

    private function setVariableType(string $variable, string $type, string $confidence = 'certain'): void
    {
        if ($this->callables !== []) {
            $this->callables[array_key_last($this->callables)]['variables'][$variable] = [
                'type' => $type,
                'confidence' => $confidence,
            ];
        }
    }

    private function clearVariableType(string $variable): void
    {
        if ($this->callables !== []) {
            unset($this->callables[array_key_last($this->callables)]['variables'][$variable]);
        }
    }

    private function variableType(string $variable): ?string
    {
        return $this->callables === []
            ? null
            : ($this->callables[array_key_last($this->callables)]['variables'][$variable]['type'] ?? null);
    }

    private function variableConfidence(string $variable): string
    {
        return $this->callables === []
            ? 'certain'
            : ($this->callables[array_key_last($this->callables)]['variables'][$variable]['confidence'] ?? 'certain');
    }

    private function setPropertyType(string $property, string $type): void
    {
        if ($this->classes !== []) {
            $this->classes[array_key_last($this->classes)]['properties'][$property] = $type;
        }
    }

    private function propertyType(string $property): ?string
    {
        return $this->classes === []
            ? null
            : ($this->classes[array_key_last($this->classes)]['properties'][$property] ?? null);
    }

    private static function reference(string $kind, string $canonicalName): string
    {
        return 'php:' . $kind . ':' . ltrim($canonicalName, '\\');
    }
}
