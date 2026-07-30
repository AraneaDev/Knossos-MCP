<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * Tracks where the traversal currently is: the enclosing class and callable.
 *
 * The Laravel collectors need that enclosing scope to attribute a fact — a route
 * registered inside a method belongs to that method — and the parser visits nodes
 * without carrying it. Maintained as stacks so nested declarations (a closure in a
 * method, a class in a class) unwind in the right order.
 */
final class LaravelTraversalContext
{
    use ResolvesDeclarationName;

    /** @var list<string> */
    private array $classes = [];
    /** @var list<string> */
    private array $callables = [];

    /** @param string $relativePath the file being traversed, for evidence on emitted facts */
    public function __construct(private readonly string $relativePath = '') {}

    /** Push a declaration onto the scope stacks as the traversal enters it. */
    public function enterNode(Node $node): void
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->classes[] = LaravelFactStore::classReference($this->declarationName($node, $this->relativePath));
        } elseif ($node instanceof Stmt\ClassMethod) {
            $class = $this->currentClass();
            if ($class !== null) {
                $this->callables[] = 'php:method:' . substr($class, strlen('php:class:')) . '::' . $node->name->toString();
            }
        }
    }

    /** Pop the scope stacks on the way out, keeping them balanced with enterNode(). */
    public function leaveNode(Node $node): void
    {
        if ($node instanceof Stmt\ClassMethod && $this->callables !== []) {
            array_pop($this->callables);
        } elseif ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }
    }

    /** The innermost enclosing class, or null at file scope. */
    public function currentClass(): ?string
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    /** The innermost enclosing callable, which is what a fact is attributed to. */
    public function currentSource(): ?string
    {
        return $this->callables === [] ? $this->currentClass() : $this->callables[array_key_last($this->callables)];
    }
}
