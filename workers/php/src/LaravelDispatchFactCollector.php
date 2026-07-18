<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

final readonly class LaravelDispatchFactCollector
{
    public function __construct(
        private LaravelFactStore $facts,
        private LaravelTraversalContext $context,
    ) {}

    public function enterNode(Node $node): void
    {
        if ($node instanceof Expr\StaticCall) {
            $this->staticFrameworkCall($node);
        } elseif ($node instanceof Expr\FuncCall) {
            $this->functionDispatch($node);
        }
    }

    private function staticFrameworkCall(Expr\StaticCall $node): void
    {
        $source = $this->context->currentSource();
        if ($source === null || !$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }
        $method = strtolower($node->name->toString());
        if ($method === 'dispatch') {
            $event = LaravelFactStore::name($node->class);
            if (!str_ends_with($event, '\\Bus') && !str_ends_with($event, '\\Event')) {
                $this->facts->addEdge('dispatches', $source, LaravelFactStore::classReference($event), $node);
            }
        } elseif ($method === 'observe') {
            $observer = LaravelFactStore::classArgument($node->args[0]->value ?? null);
            if ($observer !== null) {
                $this->facts->addEdge('observes', $source, LaravelFactStore::classReference($observer), $node, ['model' => LaravelFactStore::name($node->class)]);
            }
        }
    }

    private function functionDispatch(Expr\FuncCall $node): void
    {
        $source = $this->context->currentSource();
        if ($source === null || !$node->name instanceof Name
            || !in_array(strtolower($node->name->toString()), ['event', 'dispatch'], true)) {
            return;
        }
        $argument = $node->args[0]->value ?? null;
        if ($argument instanceof Expr\New_ && $argument->class instanceof Name) {
            $this->facts->addEdge('dispatches', $source, LaravelFactStore::classReference(LaravelFactStore::name($argument->class)), $node);
        }
    }
}
