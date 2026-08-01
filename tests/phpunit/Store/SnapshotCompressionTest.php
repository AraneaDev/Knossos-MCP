<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\SnapshotPayload;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('storage')]
final class SnapshotCompressionTest extends KnossosTestCase
{
    public function testAnArchivedSnapshotIsStoredCompressed(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);

        $stored = (string) $pdo->query('SELECT payload_json FROM scan_snapshots')->fetchColumn();
        $this->assertStringStartsWith('gzip64:', $stored);
        // byte_size stays the size of the facts, not of their storage: it
        // answers "how big is this snapshot", which a reader compares across
        // scans and which compression must not silently redefine.
        assertSame(strlen(SnapshotPayload::decode($stored)), (int) $pdo->query('SELECT byte_size FROM scan_snapshots')->fetchColumn());
    }

    public function testACompressedSnapshotStillAnswersASnapshotDiff(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        $repository->completeScan($ids['project'], $next);

        $diff = (new ArchitectureQueryService($pdo))->snapshotDiff($ids['project'], $ids['scan']);

        assertSame($ids['scan'], $diff->data['from']['scan_id']);
        // The graph did not change between the two scans, which is only
        // knowable by reading the archived facts back out of the snapshot.
        assertSame(0, $diff->data['changes']['components']['counts']['added']);
        assertSame(0, $diff->data['changes']['components']['counts']['removed']);
    }
}
