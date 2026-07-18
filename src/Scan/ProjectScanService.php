<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Query\ResultEnvelope;
use Knossos\Reconciliation\{FullScanRequest, GraphReconciler};
use Knossos\Store\SqliteGraphRepository;
use PDO;

final class ProjectScanService implements ProjectScanner
{
    private readonly ScanPlanner $planner;
    private readonly LanguageWorkerPool $workerPool;
    private readonly LanguageScanRunner $languageRunner;
    private readonly ScanAnalysisPipeline $analysisPipeline;
    private readonly ScanResultFactory $resultFactory;

    /** @param list<string> $allowedRoots */
    public function __construct(
        private PDO $pdo,
        string $installationRoot,
        array $allowedRoots,
    ) {
        $this->planner = new ScanPlanner($pdo, $allowedRoots);
        $this->workerPool = new LanguageWorkerPool();
        $this->languageRunner = new LanguageScanRunner(
            LanguageDescriptor::defaults($installationRoot),
            $this->workerPool,
            new ContributionCacheService(),
        );
        $this->analysisPipeline = new ScanAnalysisPipeline();
        $this->resultFactory = new ScanResultFactory();
    }

    public function __destruct()
    {
        $this->workerPool->shutdown();
    }

    /** @param list<array<string, mixed>>|null $explicitBoundaries */
    public function scan(
        string $root,
        ?string $name = null,
        ?int $maxFiles = null,
        ?int $maxFileBytes = null,
        ?array $explicitBoundaries = null,
        ?string $mode = null,
        ?CancellationToken $cancellation = null,
        ?int $snapshotRetention = null,
        ?int $workerTimeoutMs = null,
    ): ResultEnvelope {
        $startedAt = hrtime(true);
        $cancellation ??= new CancellationToken();
        $cancellation->throwIfCancelled();
        $preparation = $this->planner->prepare(
            $root,
            $maxFiles,
            $maxFileBytes,
            $explicitBoundaries,
            $mode,
            $snapshotRetention,
            $workerTimeoutMs,
        );
        $stageMilliseconds = [
            'configuration' => $preparation->configurationMilliseconds,
            'discovery' => $preparation->discoveryMilliseconds,
        ];
        $planningStarted = hrtime(true);
        $cancellation->throwIfCancelled();
        $projectId = \Knossos\Store\StableId::project('root:' . $preparation->discovery->rootRealpath);
        $lease = (new ProjectWriterLock($this->pdo))->acquire($projectId);
        $plan = $this->planner->finalize($preparation);
        $this->workerPool->prepare($preparation->executionPolicy);
        $stageMilliseconds['planning'] = $preparation->planningMilliseconds + self::elapsedMilliseconds($planningStarted);

        $language = $this->languageRunner->run($plan, $cancellation);
        $stageMilliseconds += $language->stageMilliseconds;
        $analysisStarted = hrtime(true);
        $analysis = $this->analysisPipeline->analyze($plan, $language->contributions);
        $stageMilliseconds['analysis'] = self::elapsedMilliseconds($analysisStarted);
        $cancellation->throwIfCancelled();

        $reconciliationStarted = hrtime(true);
        $result = (new GraphReconciler(new SqliteGraphRepository($this->pdo)))->reconcile(new FullScanRequest(
            'root:' . $preparation->discovery->rootRealpath,
            $name ?? basename($preparation->discovery->rootRealpath),
            $preparation->discovery,
            $language->manifests,
            $language->contributions,
            [
                'input_hash' => $preparation->discovery->inputHash,
                'configuration_hash' => $preparation->discovery->configurationHash,
                'snapshot_retention' => $preparation->snapshotRetention,
            ],
            $analysis->classifications,
            $analysis->boundaries,
            $plan->effectiveMode,
            $language->cacheEntries,
        ));
        $stageMilliseconds['reconciliation'] = self::elapsedMilliseconds($reconciliationStarted);
        $envelope = $this->resultFactory->create($plan, $language, $result, $startedAt, $stageMilliseconds);
        $lease->release();
        return $envelope;
    }

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
