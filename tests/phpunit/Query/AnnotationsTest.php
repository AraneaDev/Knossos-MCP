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

        $preview = $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'core flow');
        assertSame(false, $preview->data['executed']);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM annotations')->fetchColumn());

        $written = $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'core flow', execute: true);
        assertSame(true, $written->data['executed']);
        assertSame('upsert', $written->data['action']);
        assertSame('App\\Checkout', $written->data['component']);

        // Upsert: same key, new value.
        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', 'CORE flow', execute: true);
        $list = $queries->listAnnotations($ids['project']);
        assertSame(1, count($list->data['annotations']));
        assertSame('CORE flow', $list->data['annotations'][0]['value']);

        $removed = $queries->removeAnnotation($ids['project'], 'App\\Checkout', 'note', execute: true);
        assertSame('remove', $removed->data['action']);
        assertSame([], $queries->listAnnotations($ids['project'])->data['annotations']);

        assertThrows(fn() => $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'bogus_kind', execute: true), InvalidArgumentException::class);
        // A prefix both fixture classes share is not a match: annotations land
        // only on an exact one, so it previews as not found and names them.
        $prefix = $queries->upsertAnnotation($ids['project'], 'App\\', 'note');
        assertSame('App\\', $prefix->data['component']);
        assertSame(true, str_contains($prefix->warnings[0], 'Did you mean: App\\Checkout, App\\InvoiceService?'));
        // Unknown symbol: allowed, but warned.
        $unknown = $queries->upsertAnnotation($ids['project'], 'App\\Future', 'note', 'coming soon', execute: true);
        assertSame(true, str_contains(implode(' ', $unknown->warnings), 'not found'));
    }

    /** A note's limit is 2,000 characters: 2,000 CJK characters (6,000 bytes) fit, one more does not. */
    #[Group('query')]
    public function testANoteIsMeasuredInCharactersNotBytes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', str_repeat('界', 2000), execute: true);
        assertSame(str_repeat('界', 2000), $queries->listAnnotations($ids['project'])->data['annotations'][0]['value']);

        $refused = captureThrows(
            fn() => $queries->upsertAnnotation($ids['project'], 'App\\Checkout', 'note', str_repeat('界', 2001), execute: true),
            InvalidArgumentException::class,
        );
        assertSame('value must not exceed 2000 characters.', $refused->getMessage());
    }

    #[Group('query')]
    public function testAnnotationsSurviveRescans(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $queries = new ArchitectureQueryService($pdo);
            // Confirmed against tests/Fixtures/mixed/src/CheckoutService.php,
            // which declares `namespace Fixture;`.
            $queries->upsertAnnotation($projectId, 'Fixture\\CheckoutService', 'confirmed_dead', 'checked by hand', execute: true);
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

        $queries->upsertAnnotation($ids['project'], 'App\\Orphan', 'false_positive', 'constructed via DI config', execute: true);
        $after = $queries->architectureHealth($ids['project'])->data;
        $namesAfter = array_map(static fn(array $c): string => $c['component']['canonical_name'], $after['dead_code_candidates']);
        assertSame(false, in_array('App\\Orphan', $namesAfter, true));
        assertSame(1, $after['bounds']['annotated_false_positives']);
    }

    /**
     * A `false_positive` annotation skips that candidate, it does not end the scan.
     *
     * The guard sits inside the loop over every provisional dead-code
     * candidate, so turning its `continue` into a `break` stops at the first
     * annotated component and silently drops every candidate behind it. One
     * annotation on a busy project would truncate the whole report.
     *
     * The existing tests annotate a single component, where skipping and
     * stopping look the same. Three annotations separate them, and the
     * `annotated_false_positives` count is asserted rather than the surviving
     * names so the test does not depend on candidate ordering.
     */
    #[Group('query')]
    public function testAFalsePositiveAnnotationSkipsRatherThanEndingTheScan(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $orphans = ['App\\OrphanOne', 'App\\OrphanTwo', 'App\\OrphanThree'];
        foreach ($orphans as $index => $name) {
            $node = \Knossos\Store\StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($node, $ids['project'], 'php', 'class', $name, 'Orphan' . $index, null, $ids['file'], 50 + $index, 60 + $index, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);
        foreach ($orphans as $name) {
            $queries->upsertAnnotation($ids['project'], $name, 'false_positive', 'constructed via DI config', execute: true);
        }

        $data = $queries->architectureHealth($ids['project'])->data;

        assertSame(3, $data['bounds']['annotated_false_positives'], 'Every annotated candidate must be counted, not just the first.');
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        foreach ($orphans as $name) {
            assertSame(false, in_array($name, $names, true));
        }
    }

    /**
     * `intentional` is for a finding that is true and meant: a route parked
     * on purpose, a helper only tests use by design. It leaves the report as
     * a false positive does, so the real findings are not buried under it on
     * every scan, but it is counted apart: the graph was right.
     */
    #[Group('query')]
    public function testAnIntentionalAnnotationLeavesTheReportAndIsCountedApart(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        foreach (['App\\Parked', 'App\\Both'] as $index => $name) {
            $node = \Knossos\Store\StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($node, $ids['project'], 'php', 'class', $name, 'Orphan' . $index, null, $ids['file'], 50 + $index, 60 + $index, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $queries->upsertAnnotation($ids['project'], 'App\\Parked', 'intentional', 'route parked until the MVP', execute: true);
        // On one component a false positive wins: the graph was wrong there.
        $queries->upsertAnnotation($ids['project'], 'App\\Both', 'intentional', 'kept', execute: true);
        $queries->upsertAnnotation($ids['project'], 'App\\Both', 'false_positive', 'built by the container', execute: true);

        $data = $queries->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        assertSame([false, false], [in_array('App\\Parked', $names, true), in_array('App\\Both', $names, true)]);
        assertSame([1, 1], [$data['bounds']['annotated_intentional'], $data['bounds']['annotated_false_positives']]);
        assertSame(['App\\Both', 'App\\Parked'], array_column($queries->listAnnotations($ids['project'], kind: 'intentional')->data['annotations'], 'canonical_name'));
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
        $queries->upsertAnnotation($ids['project'], 'App\\Orphan', 'confirmed_dead', 'delete next sprint', execute: true);
        $queries->upsertAnnotation($ids['project'], 'App\\Orphan', 'false_positive', 'constructed via DI config', execute: true);

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
        $queries->upsertAnnotation($ids['project'], 'App\\Orphan', 'confirmed_dead', 'delete next sprint', execute: true);

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

    /** A prefix match turned `App\\Invoice` into `App\\InvoiceService` and hid the service from dead-code reports. */
    #[Group('query')]
    public function testAnAnnotationNeverLandsOnAPrefixMatch(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $result = $queries->upsertAnnotation($ids['project'], 'App\\Invoice', 'false_positive', execute: true);

        self::assertSame('App\\Invoice', $result->data['component']);
        self::assertStringContainsString('not found', implode(' ', $result->warnings));
        self::assertStringContainsString('Did you mean: App\\InvoiceService', implode(' ', $result->warnings));
        self::assertSame([], $queries->listAnnotations($ids['project'], 'App\\InvoiceService')->data['annotations']);
        self::assertCount(1, $queries->listAnnotations($ids['project'], 'App\\Invoice')->data['annotations']);
    }

    /** An exact display name is still an exact match. */
    #[Group('query')]
    public function testAnExactDisplayNameStillResolves(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->upsertAnnotation($ids['project'], 'Checkout', 'note', 'entry', execute: true);

        self::assertSame('App\\Checkout', $result->data['component']);
        self::assertSame([], $result->warnings);
    }
}
