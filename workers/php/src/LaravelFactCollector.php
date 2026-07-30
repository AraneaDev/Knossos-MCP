<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects Laravel-specific facts a plain AST pass would miss.
 *
 * Routes, container bindings, dispatches, and provider registries — all of which
 * connect components through framework indirection rather than direct references,
 * so without them the graph shows handlers nothing appears to call.
 */
final class LaravelFactCollector extends NodeVisitorAbstract
{
    private readonly LaravelFactStore $facts;
    private readonly LaravelTraversalContext $context;
    private readonly LaravelRouteFactCollector $routes;
    private readonly LaravelContainerFactCollector $container;
    private readonly LaravelDispatchFactCollector $dispatch;
    private readonly LaravelProviderMapFactCollector $providerMaps;

    /** @param string $relativePath the file being visited, carried onto every emitted fact */
    public function __construct(string $relativePath)
    {
        $this->facts = new LaravelFactStore($relativePath);
        $this->context = new LaravelTraversalContext($relativePath);
        $this->routes = new LaravelRouteFactCollector($this->facts);
        $this->container = new LaravelContainerFactCollector($this->facts, $this->context);
        $this->dispatch = new LaravelDispatchFactCollector($this->facts, $this->context);
        $this->providerMaps = new LaravelProviderMapFactCollector($this->facts, $this->context);
    }

    /** Fan the node out to the scope tracker and each specialised collector. */
    public function enterNode(Node $node): ?int
    {
        $this->context->enterNode($node);
        $this->routes->enterNode($node);
        $this->container->enterNode($node);
        $this->dispatch->enterNode($node);
        $this->providerMaps->enterNode($node);

        return null;
    }

    /** Unwind the scope tracker so enclosing-class attribution stays correct. */
    public function leaveNode(Node $node): ?int
    {
        $this->routes->leaveNode($node);
        $this->context->leaveNode($node);

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return $this->facts->nodes();
    }

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return $this->facts->edges();
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->facts->diagnostics();
    }
}
