<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dead-code candidates are decided over the whole project. The hub ranking
 * reads a bounded window of nodes in canonical-name order; a candidate past
 * that window was never reported, and one inside it was classified from facts
 * the window happened to hold.
 */
#[Group('query')]
final class WholeProjectCandidatesTest extends KnossosTestCase
{
    public function testAnUntypedCallPastTheWindowStillDemotes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $method = StableId::symbol($project, 'ts', 'method', 'src/a.ts#Mode::label');
        $repository->saveNode($method, $project, 'ts', 'method', 'src/a.ts#Mode::label', 'label', null, $ids['file'], 3, 4, 'ast', 'certain', [], 'ts:file:src/a.ts', $ids['scan']);
        // Sorts after everything else, so a small window never reads it.
        $loop = StableId::symbol($project, 'ts', 'module', 'zzz/loop.js');
        $repository->saveNode($loop, $project, 'ts', 'module', 'zzz/loop.js', 'loop.js', null, $ids['file'], 1, 9, 'ast', 'certain', ['executable' => true, 'unresolved_member_calls' => ['label']], 'ts:file:zzz/loop.js', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($project, limit: 100, maxNodes: 3)->data;

        $confidence = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $confidence[$candidate['component']['canonical_name']] = $candidate['confidence'];
        }
        self::assertSame('possible', $confidence['src/a.ts#Mode::label'] ?? null);
    }
}
