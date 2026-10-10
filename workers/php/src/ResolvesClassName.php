<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node\Name;

/**
 * Shared resolution of a class name written in code.
 *
 * The enclosing class is passed in rather than read from the using class, so
 * the trait carries no property contract: FactCollector forwards its current
 * class and ReceiverTypeInference the one it was handed.
 */
trait ResolvesClassName
{
    /**
     * Fully-qualified name, honouring the parser's resolved name attribute when
     * present, with `self`, `static` and `parent` read against the enclosing class.
     *
     * @param array{name: string, parent: ?string}|null $class
     */
    private function resolvedClassNameIn(Name $name, ?array $class): string
    {
        return match (strtolower($name->toString())) {
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
}
