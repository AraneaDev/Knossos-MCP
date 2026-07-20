<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class PolicyTest extends KnossosTestCase
{
    #[Group('policy')]
    public function testDeclaredArchitecturePoliciesReportDeterministicEvidenceBackedViolations(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $worker = StableId::symbol($ids['project'], 'php', 'class', 'App\\Worker');
        $repository->saveNode(
            $worker,
            $ids['project'],
            'class',
            'App\\Worker',
            'Worker',
            null,
            $ids['file'],
            40,
            50,
            'ast',
            'certain',
            [],
            'php:file:src/Worker.php',
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'calls', $ids['checkout'], $worker, 'worker'),
            $ids['project'],
            'calls',
            $ids['checkout'],
            $worker,
            $ids['file'],
            15,
            15,
            'ast',
            'probable',
            [],
            'php:file:src/Checkout.php',
            $ids['scan'],
        );
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $duplicateBackend = StableId::boundary($ids['project'], 'Backend', 'inferred');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundary($duplicateBackend, $ids['project'], 'Backend', ['namespace_prefix' => 'App'], 'inferred', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $policies = [
            ['id' => 'backend-allow', 'from_boundary' => $backend, 'allow_targets' => [$backend], 'edge_kinds' => ['calls']],
            ['id' => 'backend-deny-billing', 'from_boundary' => $backend, 'deny_targets' => [$billing], 'edge_kinds' => ['calls']],
            ['id' => 'backend-no-unassigned', 'from_boundary' => $backend, 'deny_targets' => ['@unassigned'], 'edge_kinds' => ['calls']],
        ];
        $query = new ArchitectureQueryService($pdo);
        $result = $query->checkArchitecture($ids['project'], $policies);
        assertSame(4, count($result->data['violations']));
        $policyCounts = array_count_values(array_column($result->data['violations'], 'policy_id'));
        ksort($policyCounts);
        assertSame(['backend-allow' => 2, 'backend-deny-billing' => 1, 'backend-no-unassigned' => 1], $policyCounts);
        $denyViolation = array_values(array_filter($result->data['violations'], fn(array $item): bool => $item['policy_id'] === 'backend-deny-billing'))[0];
        $unassignedViolation = array_values(array_filter($result->data['violations'], fn(array $item): bool => $item['policy_id'] === 'backend-no-unassigned'))[0];
        assertSame('denied_target', $denyViolation['reasons'][0]['type']);
        assertSame([], $unassignedViolation['target_boundaries']);
        assertSame(4, count($result->evidence));
        assertContains('static graph findings', $result->warnings[0]);

        $certain = $query->checkArchitecture($ids['project'], $policies, minConfidence: 'certain');
        assertSame(2, count($certain->data['violations']));
        $limited = $query->checkArchitecture($ids['project'], $policies, limit: 1);
        assertSame(true, $limited->truncated);
        assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);
        $edgeLimited = $query->checkArchitecture($ids['project'], $policies, maxEdges: 1);
        assertSame(true, $edgeLimited->truncated);
        assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
        $filtered = $query->checkArchitecture($ids['project'], [[
            'id' => 'imports-only', 'from_boundary' => $backend, 'deny_targets' => [$billing], 'edge_kinds' => ['imports'],
        ]]);
        assertSame([], $filtered->data['violations']);
        assertThrows(fn() => $query->checkArchitecture($ids['project'], [[
            'id' => 'ambiguous', 'from_boundary' => 'Backend', 'deny_targets' => [$billing],
        ]]), InvalidArgumentException::class);
        assertThrows(fn() => $query->checkArchitecture($ids['project'], [[
            'id' => 'unknown', 'from_boundary' => 'Missing', 'deny_targets' => [$billing],
        ]]), InvalidArgumentException::class);
        assertThrows(fn() => $query->checkArchitecture($ids['project'], [[
            'id' => 'empty', 'from_boundary' => $backend,
        ]]), InvalidArgumentException::class);

        $time = 0;
        $timedQuery = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });
        $timed = $timedQuery->checkArchitecture($ids['project'], $policies, timeoutMs: 1);
        assertSame(true, $timed->truncated);
        assertSame(['time_limit'], $timed->data['bounds']['truncation_reasons']);
    }
}
