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

    #[Group('query')]
    public function testFalsePositiveAnnotationRemovesDeadCodeCandidate(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $orphan = \Knossos\Store\StableId::symbol($ids['project'], 'php', 'class', 'App\\Orphan');
        $repository->saveNode($orphan, $ids['project'], 'php', 'class', 'App\\Orphan', 'Orphan', null, $ids['file'], 50, 60, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $before = $queries->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $before['dead_code_candidates']);
        assertSame(true, in_array('App\\Orphan', $names, true));

        $queries->annotateComponent($ids['project'], 'App\\Orphan', 'false_positive', 'constructed via DI config', execute: true);
        $after = $queries->architectureHealth($ids['project'])->data;
        $namesAfter = array_map(static fn(array $c): string => $c['component']['canonical_name'], $after['dead_code_candidates']);
        assertSame(false, in_array('App\\Orphan', $namesAfter, true));
        assertSame(1, $after['bounds']['annotated_false_positives']);
    }

    #[Group('query')]
    public function testFalsePositiveTakesPrecedenceOverConfirmedDeadOnSameComponent(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $orphan = \Knossos\Store\StableId::symbol($ids['project'], 'php', 'class', 'App\\Orphan');
        $repository->saveNode($orphan, $ids['project'], 'php', 'class', 'App\\Orphan', 'Orphan', null, $ids['file'], 50, 60, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        // Both annotations land on the same canonical name; false_positive
        // must win regardless of write order.
        $queries->annotateComponent($ids['project'], 'App\\Orphan', 'confirmed_dead', 'delete next sprint', execute: true);
        $queries->annotateComponent($ids['project'], 'App\\Orphan', 'false_positive', 'constructed via DI config', execute: true);

        $health = $queries->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $health['dead_code_candidates']);
        assertSame(false, in_array('App\\Orphan', $names, true));
        assertSame(1, $health['bounds']['annotated_false_positives']);
    }

    #[Group('query')]
    public function testConfirmedDeadAttachesInlineAndInspectShowsAnnotations(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $orphan = \Knossos\Store\StableId::symbol($ids['project'], 'php', 'class', 'App\\Orphan');
        $repository->saveNode($orphan, $ids['project'], 'php', 'class', 'App\\Orphan', 'Orphan', null, $ids['file'], 50, 60, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);
        $queries->annotateComponent($ids['project'], 'App\\Orphan', 'confirmed_dead', 'delete next sprint', execute: true);

        $health = $queries->architectureHealth($ids['project'])->data;
        $candidate = null;
        foreach ($health['dead_code_candidates'] as $entry) {
            if ($entry['component']['canonical_name'] === 'App\\Orphan') {
                $candidate = $entry;
            }
        }
        assertSame('confirmed_dead', $candidate['annotation']['kind']);
        assertSame('delete next sprint', $candidate['annotation']['value']);

        $inspect = $queries->inspectComponent($ids['project'], 'App\\Orphan')->data;
        assertSame('confirmed_dead', $inspect['component']['annotations'][0]['kind']);
    }
}
