<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class LaravelFactCollector extends NodeVisitorAbstract
{
    private readonly LaravelFactStore $facts;
    private readonly LaravelTraversalContext $context;
    private readonly LaravelRouteFactCollector $routes;
    private readonly LaravelContainerFactCollector $container;
    private readonly LaravelDispatchFactCollector $dispatch;
    private readonly LaravelProviderMapFactCollector $providerMaps;

    public function __construct(string $relativePath)
    {
        $this->facts = new LaravelFactStore($relativePath);
        $this->context = new LaravelTraversalContext();
        $this->routes = new LaravelRouteFactCollector($this->facts);
        $this->container = new LaravelContainerFactCollector($this->facts, $this->context);
        $this->dispatch = new LaravelDispatchFactCollector($this->facts, $this->context);
        $this->providerMaps = new LaravelProviderMapFactCollector($this->facts, $this->context);
    }

    public function enterNode(Node $node): ?int
    {
        $this->context->enterNode($node);
        $this->routes->enterNode($node);
        $this->container->enterNode($node);
        $this->dispatch->enterNode($node);
        $this->providerMaps->enterNode($node);

        return null;
    }

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
