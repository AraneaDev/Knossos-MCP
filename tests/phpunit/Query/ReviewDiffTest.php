<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ReviewDiffTest extends KnossosTestCase
{
    #[Group('query')]
    public function testComposesChangePolicyAndCycleSections(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $policies = [['id' => 'no-billing', 'from_boundary' => $backend, 'deny_targets' => [$billing]]];
        $result = $queries->reviewDiff($ids['project'], files: ['src/Checkout.php'], policies: $policies);

        assertSame('evaluated', $result->data['change']['status']);
        assertSame(['src/Checkout.php'], $result->data['change']['changed_files']);
        assertSame('evaluated', $result->data['policy_check']['status']);
        assertSame(1, $result->data['policy_check']['total_violations']);
        // The Checkout→Invoice call violates the policy AND touches the change.
        assertSame(1, count($result->data['policy_check']['violations_touching_change']));
        assertSame('evaluated', $result->data['cycles_touching_change']['status']);
        assertSame([], $result->data['cycles_touching_change']['cycles']);
        // No retained non-active snapshot and no budgets in this fixture:
        assertSame('not_evaluated', $result->data['quality_gate']['status']);
        // dependencyCycles' caveat warning propagates into the envelope:
        assertContains('Cycles are derived', implode(' ', $result->warnings));
        // Policy-check evidence (from the touching violation) is unioned into the envelope evidence:
        $hasPolicyEvidence = false;
        foreach ($result->evidence as $row) {
            if (array_key_exists('policy_id', $row)) {
                $hasPolicyEvidence = true;
                break;
            }
        }
        assertSame(true, $hasPolicyEvidence);
    }

    #[Group('query')]
    public function testMissingConfigYieldsNotEvaluatedSectionsNotErrors(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        // root_realpath (/workspace/fixture-shop) does not exist on disk:
        // config loading fails soft, sections degrade, envelope still returns.
        $result = (new ArchitectureQueryService($pdo))->reviewDiff($ids['project'], files: ['src/Checkout.php']);
        assertSame('not_evaluated', $result->data['policy_check']['status']);
        assertSame('not_evaluated', $result->data['quality_gate']['status']);
        assertSame('evaluated', $result->data['change']['status']);
    }

    #[Group('query')]
    public function testFilesWithBaseRefIsRejected(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        assertThrows(
            fn() => (new ArchitectureQueryService($pdo))->reviewDiff($ids['project'], baseRef: 'main', files: ['src/Checkout.php']),
            InvalidArgumentException::class,
        );
    }

    #[Group('query')]
    public function testDispatchThroughToolService(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('review_diff', ['project_id' => $ids['project'], 'files' => ['src/Checkout.php']]);
        assertSame(true, array_key_exists('change', $result->data));
        assertSame(true, array_key_exists('policy_check', $result->data));
        assertSame(true, array_key_exists('quality_gate', $result->data));
        assertSame(true, array_key_exists('cycles_touching_change', $result->data));
    }
}
