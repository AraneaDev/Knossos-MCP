<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node\Stmt;

/**
 * Shared canonical-name resolution for class-like and function declarations.
 *
 * The relative path is passed in rather than read from the using class so the
 * trait carries no property contract: FactCollector and LaravelTraversalContext
 * each own their own $relativePath and forward it here.
 */
trait ResolvesDeclarationName
{
    private function declarationName(Stmt\ClassLike|Stmt\Function_ $node, string $relativePath): string
    {
        if (isset($node->namespacedName)) {
            return $node->namespacedName->toString();
        }
        // Anonymous classes have no name; resolve them to a path:line scheme so
        // the same declaration yields the same node id across collectors instead
        // of dangling.
        if ($node instanceof Stmt\Class_ && $node->name === null) {
            return sprintf('{anonymous}@%s:%d', $relativePath, max(1, $node->getStartLine()));
        }

        return $node->name?->toString() ?? '{anonymous}';
    }
}
