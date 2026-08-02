<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SnapshotPayload;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Archiving a snapshot used to fetch every row of the graph into PHP arrays and
 * json_encode the lot, so peak memory was several times the payload: this
 * repository's 30 MB snapshot needed 145 MB to write. That is the same shape of
 * defect as materialising a query result — the size of the project decides
 * whether the process survives, and nothing warns before it does not.
 */
#[Group('storage')]
final class SnapshotArchiveMemoryTest extends KnossosTestCase
{
    public function testArchivingHoldsFarLessThanTheSnapshotItWrites(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // Wide attributes so the payload is large without needing a huge row
        // count; the point is bytes held at once, not rows.
        $filler = str_repeat('abcdefghij', 200);
        $nodes = [];
        for ($i = 0; $i < 4000; $i++) {
            $name = sprintf('App\\Generated%d', $i);
            $nodes[] = [
                'id' => StableId::symbol($ids['project'], 'php', 'class', $name),
                'language' => 'php', 'kind' => 'class', 'canonical_name' => $name, 'display_name' => 'Generated',
                'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 2, 'origin' => 'ast',
                'confidence' => 'certain', 'attributes' => ['filler' => $filler],
                'owner_key' => 'php:file:src/Checkout.php',
            ];
        }
        $repository->bulkTransaction(static function ($repository) use ($nodes, $ids): void {
            $repository->saveNodes($nodes, $ids['project'], $ids['scan']);
        });
        $repository->completeScan($ids['project'], $ids['scan']);
        unset($nodes, $filler);
        gc_collect_cycles();

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $peak = memory_get_peak_usage() - $before;

        $stored = (string) $pdo->query('SELECT payload_json FROM scan_snapshots')->fetchColumn();
        $payload = SnapshotPayload::decode($stored);
        $facts = json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['facts'];
        assertSame(4002, count($facts['nodes']));
        assertSame(strlen($payload), (int) $pdo->query('SELECT byte_size FROM scan_snapshots')->fetchColumn());
        // The uncompressed payload is roughly 9 MB here. Holding it whole, plus
        // the row arrays it was built from, is what this guards against.
        $this->assertLessThan(strlen($payload) / 2, $peak, sprintf('Archiving held %d bytes to write %d.', $peak, strlen($payload)));
    }
}
