<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class AnnotationsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAnnotatePreviewsUpsertsListsAndRemoves(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $preview = $queries->annotateComponent($ids['project'], 'App\\Checkout', 'note', 'core flow');
        assertSame(false, $preview->data['executed']);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());

        $written = $queries->annotateComponent($ids['project'], 'App\\Checkout', 'note', 'core flow', execute: true);
        assertSame(true, $written->data['executed']);
        assertSame('upsert', $written->data['action']);
        assertSame('App\\Checkout', $written->data['component']);

        // Upsert: same key, new value.
        $queries->annotateComponent($ids['project'], 'App\\Checkout', 'note', 'CORE flow', execute: true);
        $list = $queries->listAnnotations($ids['project']);
        assertSame(1, count($list->data['annotations']));
        assertSame('CORE flow', $list->data['annotations'][0]['value']);

        $removed = $queries->annotateComponent($ids['project'], 'App\\Checkout', 'note', remove: true, execute: true);
        assertSame('remove', $removed->data['action']);
        assertSame([], $queries->listAnnotations($ids['project'])->data['annotations']);

        assertThrows(fn() => $queries->annotateComponent($ids['project'], 'App\\Checkout', 'bogus_kind', execute: true), InvalidArgumentException::class);
        // Ambiguous prefix: both fixture classes match 'App\'.
        assertThrows(fn() => $queries->annotateComponent($ids['project'], 'App\\', 'note', execute: true), InvalidArgumentException::class);
        // Unknown symbol: allowed, but warned.
        $unknown = $queries->annotateComponent($ids['project'], 'App\\Future', 'note', 'coming soon', execute: true);
        assertSame(true, str_contains(implode(' ', $unknown->warnings), 'not found'));
    }

    #[Group('query')]
    public function testAnnotationsSurviveRescans(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $queries = new ArchitectureQueryService($pdo);
            // Confirmed against tests/Fixtures/mixed/src/CheckoutService.php,
            // which declares `namespace Fixture;`.
            $queries->annotateComponent($projectId, 'Fixture\\CheckoutService', 'confirmed_dead', 'checked by hand', execute: true);
            // Full rescan clears and rebuilds the graph; the annotation must survive.
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full');
            $list = $queries->listAnnotations($projectId);
            assertSame(1, count($list->data['annotations']));
            assertSame('confirmed_dead', $list->data['annotations'][0]['kind']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
