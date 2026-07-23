<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Stmt;

final class LaravelTraversalContext
{
    /** @var list<string> */
    private array $classes = [];
    /** @var list<string> */
    private array $callables = [];

    public function __construct(private readonly string $relativePath = '') {}

    public function enterNode(Node $node): void
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->classes[] = LaravelFactStore::classReference($this->declarationName($node));
        } elseif ($node instanceof Stmt\ClassMethod) {
            $class = $this->currentClass();
            if ($class !== null) {
                $this->callables[] = 'php:method:' . substr($class, strlen('php:class:')) . '::' . $node->name->toString();
            }
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Stmt\ClassMethod && $this->callables !== []) {
            array_pop($this->callables);
        } elseif ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }
    }

    public function currentClass(): ?string
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    public function currentSource(): ?string
    {
        return $this->callables === [] ? $this->currentClass() : $this->callables[array_key_last($this->callables)];
    }

    private function declarationName(Stmt\ClassLike $node): string
    {
        if (isset($node->namespacedName)) {
            return $node->namespacedName->toString();
        }
        // Match the core FactCollector's path:line scheme so a Laravel-context
        // anonymous class id resolves to the same node instead of dangling.
        if ($node instanceof Stmt\Class_ && $node->name === null) {
            return sprintf('{anonymous}@%s:%d', $this->relativePath, max(1, $node->getStartLine()));
        }

        return $node->name?->toString() ?? '{anonymous}';
    }
}
