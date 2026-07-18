<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;

final readonly class LaravelContainerFactCollector
{
    public function __construct(
        private LaravelFactStore $facts,
        private LaravelTraversalContext $context,
    ) {}

    public function enterNode(Node $node): void
    {
        if (!$node instanceof Expr\MethodCall || !$node->name instanceof Identifier
            || !in_array(strtolower($node->name->toString()), ['bind', 'singleton', 'scoped'], true)) {
            return;
        }
        $source = $this->context->currentSource();
        $contract = LaravelFactStore::classArgument($node->args[0]->value ?? null);
        $implementation = LaravelFactStore::classArgument($node->args[1]->value ?? null);
        if ($source !== null && $contract !== null && $implementation !== null) {
            $this->facts->addEdge('binds', $source, LaravelFactStore::classReference($implementation), $node, ['contract' => $contract]);
        }
    }
}
