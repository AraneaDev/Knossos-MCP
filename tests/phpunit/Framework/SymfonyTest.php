<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class SymfonyTest extends KnossosTestCase
{
    #[Group('symfony')]
    public function testSymfonyEnricherExtractsAttributesHandlersSubscribersAndServicesStatically(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/symfony';
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);
        $files = array_map(fn($file): string => $file->relativePath, $discovery->files);
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $files,
            'frameworks' => ['symfony'],
        ]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $diagnostics = array_merge(...array_map(fn(ScanContribution $item): array => $item->diagnostics, $contributions));

        $route = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'))[0];
        assertSame('GET|POST /shop/checkout => App\\CheckoutController::checkout', $route->canonicalName);
        assertSame('shop.checkout', $route->attributes['name']);
        $edgeTuples = array_map(fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $edges);
        assertArrayContains(['routes_to', 'php:route:' . $route->canonicalName, 'php:method:App\\CheckoutController::checkout'], $edgeTuples);
        assertArrayContains(['handles', 'php:command:app:reconcile', 'php:class:App\\ReconcileCommand'], $edgeTuples);
        assertArrayContains(['handles_message', 'php:class:App\\CheckoutHandler', 'php:class:App\\CheckoutRequested'], $edgeTuples);
        assertArrayContains(['listens_to', 'php:class:App\\RequestListener', 'php:class:Symfony\\Component\\HttpKernel\\Event\\RequestEvent'], $edgeTuples);
        assertArrayContains(['listens_to', 'php:class:App\\KernelSubscriber', 'php:event:Symfony\\Component\\HttpKernel\\KernelEvents::REQUEST'], $edgeTuples);
        assertArrayContains(['binds', 'php:service:app.checkout_gateway', 'php:class:App\\StripeGateway'], $edgeTuples);
        assertArrayContains(['injects', 'php:class:App\\CheckoutController', 'php:service:app.audit'], $edgeTuples);
        assertSame('SYMFONY_DYNAMIC_ROUTE_PATH', $diagnostics[0]->code);

        $path = tempnam(sys_get_temp_dir(), 'knossos-symfony-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate Symfony database.');
        }
        try {
            $pdo = SqliteConnection::open($path);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Symfony Fixture');
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.controller'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.command'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.message_handler'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.event_subscriber'")->fetchColumn());
        } finally {
            @unlink($path);
        }
    }
}
