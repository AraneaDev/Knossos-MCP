<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Records the array-shaped registries on service providers: `$listen` and `$policies`.
 *
 * The mapping from event to listener, or model to policy, lives only in that array
 * literal; nothing in the code references the listener, so these edges are the only
 * static evidence the wiring exists.
 */
final readonly class LaravelProviderMapFactCollector
{
    public function __construct(
        private LaravelFactStore $facts,
        private LaravelTraversalContext $context,
    ) {}

    /** Read `$listen`/`$policies` defaults and emit one edge per `::class => ::class` pair. */
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
