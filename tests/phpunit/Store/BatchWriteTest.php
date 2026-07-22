<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class BatchWriteTest extends KnossosTestCase
{
    #[Group('store')]
    public function testBatchSavesMatchSingleRowSaves(): void
    {
        $build = function (bool $batched): string {
            [$pdo, $repository, $ids] = $this->storeFixture();
            $nodes = [];
            $edges = [];
            for ($i = 0; $i < 150; $i++) { // > 2 chunks of 60
                $canonical = sprintf('App\\Gen\\Node%03d', $i);
                $id = StableId::symbol($ids['project'], 'php', 'class', $canonical);
                $nodes[] = [
                    'id' => $id, 'language' => 'php', 'kind' => 'class', 'canonical_name' => $canonical,
                    'display_name' => 'Node' . $i, 'file_id' => $ids['file'], 'start_line' => $i + 1, 'end_line' => $i + 2,
                    'origin' => 'ast', 'confidence' => 'certain', 'attributes' => ['n' => $i], 'owner_key' => 'php:file:src/Checkout.php',
                ];
                $edges[] = [
                    'id' => StableId::edge($ids['project'], 'calls', $ids['checkout'], $id, 'gen:' . $i),
                    'kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $id, 'file_id' => $ids['file'],
                    'start_line' => $i + 1, 'end_line' => $i + 1, 'origin' => 'ast', 'confidence' => 'certain',
                    'attributes' => [], 'owner_key' => 'php:file:src/Checkout.php',
                ];
            }
            if ($batched) {
                $repository->saveNodes($nodes, $ids['project'], $ids['scan']);
                $repository->saveEdges($edges, $ids['project'], $ids['scan']);
                // Upsert path: run again with changed attributes, must overwrite.
                $nodes[0]['attributes'] = ['n' => 'updated'];
                $repository->saveNodes([$nodes[0]], $ids['project'], $ids['scan']);
            } else {
                foreach ($nodes as $n) {
                    $repository->saveNode($n['id'], $ids['project'], $n['language'], $n['kind'], $n['canonical_name'], $n['display_name'], null, $n['file_id'], $n['start_line'], $n['end_line'], $n['origin'], $n['confidence'], $n['attributes'], $n['owner_key'], $ids['scan']);
                }
                foreach ($edges as $e) {
                    $repository->saveEdge($e['id'], $ids['project'], $e['kind'], $e['source_id'], $e['target_id'], $e['file_id'], $e['start_line'], $e['end_line'], $e['origin'], $e['confidence'], $e['attributes'], $e['owner_key'], $ids['scan']);
                }
                $repository->saveNode($nodes[0]['id'], $ids['project'], 'php', 'class', $nodes[0]['canonical_name'], $nodes[0]['display_name'], null, $ids['file'], $nodes[0]['start_line'], $nodes[0]['end_line'], 'ast', 'certain', ['n' => 'updated'], $nodes[0]['owner_key'], $ids['scan']);
            }
            return $this->graphSignature($pdo);
        };
        assertSame($build(false), $build(true));
    }
}
