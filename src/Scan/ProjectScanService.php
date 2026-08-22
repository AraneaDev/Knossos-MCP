<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Query\ResultEnvelope;
use Knossos\Reconciliation\{FullScanRequest, GraphReconciler, ReconciliationResult};
use Knossos\Store\SqliteGraphRepository;
use PDO;

/**
 * Runs a scan end to end: plan, discover, analyse, reconcile, report.
 *
 * Holds the worker pool for the scan's duration and shuts it down on the way out,
 * including when a scan fails or is cancelled. Nothing here loads or executes the
 * analysed project — the pipeline parses, and that is what makes scanning an
 * untrusted repository safe.
 */
final class ProjectScanService implements ProjectScanner
{
    private readonly ScanPlanner $planner;
    private readonly LanguageWorkerPool $workerPool;
    private readonly LanguageScanRunner $languageRunner;
    private readonly ScanAnalysisPipeline $analysisPipeline;
    private readonly ScanResultFactory $resultFactory;

    /** @param \Knossos\Discovery\AllowedRoots|list<string> $allowedRoots */
    public function __construct(
        private PDO $pdo,
        string $installationRoot,
        \Knossos\Discovery\AllowedRoots|array $allowedRoots,
    ) {
        $this->planner = new ScanPlanner($pdo, $allowedRoots);
        $this->workerPool = new LanguageWorkerPool();
        $this->languageRunner = new LanguageScanRunner(
            LanguageDescriptor::installed($installationRoot),
            $this->workerPool,
            new ContributionCacheService(),
        );
        $this->analysisPipeline = new ScanAnalysisPipeline();
        $this->resultFactory = new ScanResultFactory();
    }
    /** Shut the worker pool down, including when a scan failed or was cancelled. */

    public function __destruct()
    {
        $this->workerPool->shutdown();
    }

    /**
     * Run a scan end to end and return its result envelope.
     *
     * @param list<array<string, mixed>>|null $explicitBoundaries
     */
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
        $effectiveMode = 'full';
        try {
            $plan = $this->planner->finalize($preparation);
            $effectiveMode = $plan->effectiveMode;
            $this->workerPool->prepare($preparation->executionPolicy);
            $stageMilliseconds['planning'] = $preparation->planningMilliseconds + self::elapsedMilliseconds($planningStarted);

            $language = $this->languageRunner->run($plan, $cancellation);
            $stageMilliseconds += $language->stageMilliseconds;
            $analysisStarted = hrtime(true);
            $analysis = $this->analysisPipeline->analyze($plan, $language->contributions);
            $stageMilliseconds['analysis'] = self::elapsedMilliseconds($analysisStarted);
            $cancellation->throwIfCancelled();

            $reconciliationStarted = hrtime(true);
            $projectConfig = $this->projectConfig($preparation);
            $fastPath = $this->noChangeFastPath($plan, $language, $preparation, $projectConfig, $name);
            if ($fastPath !== null) {
                $stageMilliseconds['reconciliation'] = self::elapsedMilliseconds($reconciliationStarted);
                return $this->resultFactory->create($plan, $language, $fastPath, $startedAt, $stageMilliseconds, 'no_change');
            }
            // Refresh the lease immediately before the exclusive reconcile write. A
            // legitimately long scan can outlive the lease window; if another scanner
            // expired-and-stole it in the meantime, renew() reports zero matched rows
            // and we must not reconcile — two "exclusive" writers would corrupt the graph.
            if (!$lease->renew()) {
                throw new ScanBusyException(sprintf('The writer lease for project %s was lost during the scan; another writer took over.', $projectId));
            }
            $result = (new GraphReconciler(new SqliteGraphRepository($this->pdo)))->reconcile(new FullScanRequest(
                'root:' . $preparation->discovery->rootRealpath,
                $name ?? basename($preparation->discovery->rootRealpath),
                $preparation->discovery,
                $language->manifests,
                $language->contributions,
                $projectConfig,
                $analysis->classifications,
                $analysis->boundaries,
                $plan->effectiveMode,
                $language->cacheEntries,
                $language->workerDiagnostics,
            ));
            foreach ($result->phaseMilliseconds as $phase => $milliseconds) {
                $stageMilliseconds['reconciliation.' . $phase] = $milliseconds;
            }
            $stageMilliseconds['reconciliation'] = self::elapsedMilliseconds($reconciliationStarted);

            return $this->resultFactory->create($plan, $language, $result, $startedAt, $stageMilliseconds);
        } catch (\Throwable $error) {
            // Persist the terminal attempt so it is observable and reapable by
            // stale-scan cleanup. Best-effort: never let bookkeeping mask the
            // original failure, and never record for a project that reconcile
            // never created (recordFailedScan no-ops when the project is absent).
            $status = $error instanceof ScanCancelledException ? 'cancelled' : 'failed';
            try {
                (new SqliteGraphRepository($this->pdo))->recordFailedScan(
                    \Knossos\Store\StableId::scan($projectId, bin2hex(random_bytes(16))),
                    $projectId,
                    $effectiveMode,
                    $status,
                );
            } catch (\Throwable) {
                // Ignore: the original failure below is what matters.
            }
            throw $error;
        } finally {
            if ($lease->release() === 0) {
                error_log(sprintf('Knossos: writer lease for project %s released zero rows (already expired or stolen).', $projectId));
            }
        }
    }
    /** Milliseconds since a hrtime() mark, for the stage timings. */

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    /**
     * The project's stored configuration, needed to honour snapshot retention on completion.
     *
     * @return array<string, mixed>
     */
    private function projectConfig(ScanPreparation $preparation): array
    {
        return [
            'input_hash' => $preparation->discovery->inputHash,
            'configuration_hash' => $preparation->discovery->configurationHash,
            'snapshot_retention' => $preparation->snapshotRetention,
            'dead_code_suppressions' => $preparation->configuration->deadCodeSuppressions,
        ];
    }

    /**
     * When an incremental scan discovered zero added/changed/deleted files and
     * neither the scanner set nor the persisted configuration moved, the
     * stored graph is already the correct result: skip teardown/rebuild and
     * snapshot archiving entirely. No graph row is rewritten and no scan row is
     * created; only the stored file mtimes and the active scan's finished_at are
     * refreshed, so the staleness probe agrees with reality.
     *
     * @param array<string, mixed> $projectConfig
     */
    private function noChangeFastPath(ScanPlan $plan, LanguageScanResult $language, ScanPreparation $preparation, array $projectConfig, ?string $name): ?ReconciliationResult
    {
        // A degraded language contributes nothing to added/changed, so those tallies
        // cannot see it, and the scanner-set hash only differs when the failed
        // language was in the previously active scan. Without this guard the first
        // scan to discover a language whose worker is missing would take the fast
        // path and never reconcile, so the error diagnostic this scan produced would
        // never reach the graph. A degraded scan is by definition not a no-change one.
        if ($plan->effectiveMode !== 'incremental' || $language->added !== 0 || $language->changed !== 0 || $plan->deletedFiles !== 0 || $language->workerDiagnostics !== []) {
            return null;
        }
        // Explicit boundary overrides and rename requests arrive as call arguments,
        // not via knossos.json, so they never move configuration_hash and are absent
        // from $projectConfig. The freshly computed analysis already incorporates them,
        // so a fast-path return here would silently discard them and serve stale state.
        if ($preparation->explicitBoundaries !== []) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT p.name, p.config_json, p.active_scan_id, s.scanner_set_hash FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id',
        );
        $statement->execute(['id' => $plan->projectId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if ($name !== null && $name !== (string) $row['name']) {
            return null;
        }
        $stored = json_decode((string) $row['config_json'], true);
        if (!is_array($stored)) {
            return null;
        }
        ksort($stored, SORT_STRING);
        ksort($projectConfig, SORT_STRING);
        if ($stored !== $projectConfig) {
            return null;
        }
        if ($row['scanner_set_hash'] !== GraphReconciler::scannerSetHash($language->manifests)) {
            return null;
        }
        $this->recordVerifiedGraph($plan->projectId, (string) $row['active_scan_id'], $preparation->discovery->files);
        return $this->currentGraphCounts($plan->projectId, (string) $row['active_scan_id']);
    }

    /**
     * Record that this scan re-verified the stored graph without rebuilding it:
     * refresh stored mtimes for files whose content was unchanged, and restamp
     * the active scan's completion.
     *
     * Both halves exist for StalenessProbe, which compares stored file mtimes
     * against the tree AND the directories holding tracked files against the
     * active scan's finished_at. Refreshing mtimes alone left that second
     * comparison anchored to a timestamp the fast path never advanced, so any
     * directory-mtime bump discovery cannot see -- a `__pycache__` appearing, a
     * build artefact, an editor swap file written and removed -- marked the
     * project stale permanently: every rescan took this path, changed nothing
     * the probe reads, and left the same verdict behind.
     *
     * One transaction, so a crash cannot leave refreshed mtimes measured against
     * a completion that was never restated, or the reverse.
     *
     * @param list<\Knossos\Discovery\DiscoveredFile> $files
     */
    private function recordVerifiedGraph(string $projectId, string $activeScanId, array $files): void
    {
        $repository = new SqliteGraphRepository($this->pdo);
        $repository->transaction(function () use ($repository, $projectId, $activeScanId, $files): void {
            // Positional params: the mtime value is used twice (SET and guard).
            $update = $this->pdo->prepare(
                'UPDATE files SET mtime = ? WHERE project_id = ? AND relative_path = ? AND mtime <> ?',
            );
            foreach ($files as $file) {
                $update->execute([$file->mtime, $projectId, $file->relativePath, $file->mtime]);
            }
            $repository->refreshScanCompletion($projectId, $activeScanId);
        });
    }
    /** Node and edge counts before reconciliation, for the scan report's delta. */

    private function currentGraphCounts(string $projectId, string $activeScanId): ReconciliationResult
    {
        $count = function (string $sql) use ($projectId): int {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['project' => $projectId]);
            return (int) $statement->fetchColumn();
        };
        return new ReconciliationResult(
            $projectId,
            $activeScanId,
            $count('SELECT COUNT(*) FROM files WHERE project_id = :project'),
            $count('SELECT COUNT(*) FROM nodes WHERE project_id = :project'),
            $count('SELECT COUNT(*) FROM edges WHERE project_id = :project'),
            $count('SELECT COUNT(*) FROM diagnostics WHERE project_id = :project'),
            $count("SELECT COUNT(*) FROM nodes WHERE project_id = :project AND kind LIKE 'external!_%' ESCAPE '!'"),
        );
    }
}
