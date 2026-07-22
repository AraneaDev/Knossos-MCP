<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-plan')]
final class ScanPlanTest extends TestCase
{
    public function testConstructorAssignsAllFieldsViaNamedArgs(): void
    {
        $prep = $this->makePreparation();
        $cache = ['src/foo.php' => ['entry-foo'], 'src/bar.php' => ['entry-bar']];

        $plan = new ScanPlan(
            preparation: $prep,
            projectId: 'proj-xyz',
            effectiveMode: 'full',
            cacheByScannerPath: $cache,
            deletedFiles: 7,
        );

        assertSame($prep, $plan->preparation);
        assertSame('proj-xyz', $plan->projectId);
        assertSame('full', $plan->effectiveMode);
        assertSame($cache, $plan->cacheByScannerPath);
        assertSame(7, $plan->deletedFiles);
    }

    public function testConstructorAssignsAllFieldsViaPositionalArgs(): void
    {
        $prep = $this->makePreparation();
        $cache = ['only.php' => ['entry']];

        $plan = new ScanPlan($prep, 'proj-positional', 'incremental', $cache, 1);

        assertSame($prep, $plan->preparation);
        assertSame('proj-positional', $plan->projectId);
        assertSame('incremental', $plan->effectiveMode);
        assertSame($cache, $plan->cacheByScannerPath);
        assertSame(1, $plan->deletedFiles);
    }

    public function testReadonlyFieldsCannotBeReassigned(): void
    {
        $prep = $this->makePreparation();
        $plan = new ScanPlan($prep, 'proj-lock', 'auto', [], 0);

        // Reassigning a readonly typed property triggers a PHP Error.
        $error = captureThrows(static function () use ($plan): void {
            $plan->effectiveMode = 'hacked';
        }, \Error::class);

        // The Error is the readonly-reassignment error.
        assertContains('readonly', $error->getMessage());
    }

    public function testEmptyCacheByScannerPathAndZeroDeletedFilesAreAccepted(): void
    {
        $prep = $this->makePreparation();
        $plan = new ScanPlan($prep, 'proj-empty', 'full', [], 0);

        assertSame([], $plan->cacheByScannerPath);
        assertSame(0, $plan->deletedFiles);
    }

    public function testLargeDeletedFileCountPropagates(): void
    {
        $prep = $this->makePreparation();
        $plan = new ScanPlan($prep, 'proj-large', 'incremental', [], 42);

        assertSame(42, $plan->deletedFiles);
        assertSame('incremental', $plan->effectiveMode);
    }

    private function makePreparation(): ScanPreparation
    {
        $root = dirname(__DIR__, 2) . '/Fixtures/mixed';
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);

        return new ScanPreparation(
            configuration: new ProjectConfiguration(
                path: dirname(__DIR__, 2) . '/tests/Fixtures/mixed/knossos.json',
                maxFiles: 1000,
                maxFileBytes: 1048576,
                workerTimeoutMs: 5000,
                snapshotRetention: 5,
            ),
            discovery: $discovery,
            maxFiles: 1000,
            maxFileBytes: 1048576,
            explicitBoundaries: [],
            requestedMode: 'auto',
            snapshotRetention: 5,
            executionPolicy: new WorkerExecutionPolicy(5000),
            laravel: false,
            symfony: false,
            configurationHashes: [],
            configurationMilliseconds: 1.5,
            discoveryMilliseconds: 2.5,
            planningMilliseconds: 3.5,
        );
    }
}
