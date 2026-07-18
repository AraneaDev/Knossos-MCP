<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final readonly class LaravelProviderMapFactCollector
{
    public function __construct(
        private LaravelFactStore $facts,
        private LaravelTraversalContext $context,
    ) {}

    public function enterNode(Node $node): void
    {
        if (!$node instanceof Stmt\Property) {
            return;
        }
        $source = $this->context->currentClass();
        if ($source === null) {
            return;
        }
        foreach ($node->props as $property) {
            $name = strtolower($property->name->toString());
            if (!in_array($name, ['listen', 'policies'], true) || !$property->default instanceof Expr\Array_) {
                continue;
            }
            foreach ($property->default->items as $item) {
                $key = LaravelFactStore::classArgument($item?->key);
                if ($key === null) {
                    continue;
                }
                foreach (LaravelFactStore::classArguments($item?->value) as $mapped) {
                    $this->facts->addEdge(
                        $name === 'listen' ? 'listens_to' : 'handles',
                        $source,
                        LaravelFactStore::classReference($key),
                        $item,
                        [$name === 'listen' ? 'listener' : 'policy' => $mapped],
                    );
                }
            }
        }
    }
}
