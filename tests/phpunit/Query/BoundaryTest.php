<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Boundary\BoundaryInference;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class BoundaryTest extends KnossosTestCase
{
    #[Group('boundary')]
    public function testBoundariesInferAndConfigureMembershipWithPaginatedSearch(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-boundary-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate boundary database.');
        }
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        try {
            $pdo = SqliteConnection::open($path);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan(
                $root,
                'Mixed Boundaries',
                explicitBoundaries: [
                    ['name' => 'Backend', 'path_prefix' => 'src'],
                    ['name' => 'Frontend', 'path_prefix' => 'frontend'],
                ],
            );
            $query = new ArchitectureQueryService($pdo);
            $explicit = $query->listBoundaries($scan->projectId, 'explicit');
            assertSame(2, count($explicit->data['boundaries']));
            assertSame(['Backend', 'Frontend'], array_column($explicit->data['boundaries'], 'name'));
            assertSame('explicit', $explicit->data['boundaries'][0]['source']);
            assertSame(false, str_starts_with($explicit->evidence[0]['path'], '/'));
            $inferred = $query->listBoundaries($scan->projectId, 'inferred');
            assertSame(true, count($inferred->data['boundaries']) >= 4);
            assertArrayContains('namespace:Fixture', array_column($inferred->data['boundaries'], 'name'));

            $page = $query->listBoundaries($scan->projectId, null, 1);
            assertSame(true, $page->truncated);
            assertSame(1, $page->data['pagination']['next_offset']);
            assertSame('result_limit', $page->data['pagination']['truncation_reason']);
            $backendId = $explicit->data['boundaries'][0]['id'];
            $found = $query->searchArchitecture(
                $scan->projectId,
                'CheckoutService',
                kinds: ['class'],
                roles: ['application.service'],
                boundaryIds: [$backendId],
                confidences: ['certain'],
            );
            assertSame(1, count($found->data['results']));
            assertSame('Fixture\\CheckoutService', $found->data['results'][0]['canonical_name']);
            assertSame('Backend', $found->data['results'][0]['boundaries'][0]['name']);
            assertSame('src/CheckoutService.php', $found->evidence[0]['path']);

            $roleSearch = $query->searchArchitecture($scan->projectId, 'application.service');
            assertSame(1, count($roleSearch->data['results']));
            // Multi-word queries match components whose name contains every term.
            $multiWord = $query->searchArchitecture($scan->projectId, 'checkout service');
            assertArrayContains('Fixture\\CheckoutService', array_column($multiWord->data['results'], 'canonical_name'));
            assertSame('Fixture\\CheckoutService', $multiWord->data['results'][0]['canonical_name']);
            $pagedSearch = $query->searchArchitecture($scan->projectId, 'Checkout', limit: 1);
            assertSame(1, count($pagedSearch->data['results']));
            assertSame(true, $pagedSearch->truncated);
            assertSame(1, $pagedSearch->data['pagination']['next_offset']);
            assertThrows(fn() => $query->searchArchitecture($scan->projectId, 'x', confidences: ['unknown']), InvalidArgumentException::class);
            assertThrows(fn() => $query->listBoundaries($scan->projectId, 'generated'), InvalidArgumentException::class);
        } finally {
            unset($pdo);
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
        assertThrows(
            fn() => (new BoundaryInference())->infer([], [], [['name' => 'Escape', 'path_prefix' => '../outside']]),
            InvalidArgumentException::class,
        );
    }
}
