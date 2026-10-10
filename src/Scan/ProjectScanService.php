<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Git\DirtyPathResolver;
use Knossos\Git\GitHeadResolver;
use Knossos\Query\ResultEnvelope;
use Knossos\Reconciliation\{FullScanRequest, GraphReconciler, ReconciliationResult};
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\SqliteGraphRepository;
use PDO;

/**
 * Runs a scan end to end: plan, discover, analyse, verify the snapshot the
 * workers read, reconcile, report.
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
    private readonly ScanSnapshotValidator $snapshotValidator;
    private readonly ScanResultFactory $resultFactory;

    /**
     * @param \Knossos\Discovery\AllowedRoots|list<string> $allowedRoots
     * @param ?GitHeadResolver $gitHead injected only so a test can drive the
     *        commit capture without a subprocess; production builds its own.
     * @param ?DirtyPathResolver $dirtyPaths injected for the same reason.
     */
    public function __construct(
        private PDO $pdo,
        string $installationRoot,
        \Knossos\Discovery\AllowedRoots|array $allowedRoots,
        ?GitHeadResolver $gitHead = null,
        ?DirtyPathResolver $dirtyPaths = null,
    ) {
        $this->planner = new ScanPlanner($pdo, $allowedRoots, $gitHead, $dirtyPaths);
        $this->workerPool = new LanguageWorkerPool();
        $this->languageRunner = new LanguageScanRunner(
            LanguageDescriptor::installed($installationRoot),
            $this->workerPool,
            new ContributionCacheService(),
            installationRoot: $installationRoot,
            pdo: $pdo,
        );
        $this->analysisPipeline = new ScanAnalysisPipeline();
        $this->snapshotValidator = new ScanSnapshotValidator();
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
     * A caller that already holds the project's write lease (a scanner that
     * reads the graph before the scan and must know nobody wrote in between)
     * passes it as `$lease`: the scan writes under it and leaves releasing it
     * to the caller. A lease for another project is not used.
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
        ?int $workerMemoryMb = null,
        ?ProjectWriterLease $lease = null,
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
            $workerMemoryMb,
        );
        $stageMilliseconds = [
            'configuration' => $preparation->configurationMilliseconds,
            'discovery' => $preparation->discoveryMilliseconds,
        ];
        $planningStarted = hrtime(true);
        $cancellation->throwIfCancelled();
        $projectId = \Knossos\Store\StableId::project('root:' . $preparation->discovery->rootRealpath);
        $held = $lease !== null && $lease->projectId() === $projectId;
        if ($lease === null || !$held) {
            $lease = (new ProjectWriterLock($this->pdo))->acquire($projectId);
        }
        $effectiveMode = 'full';
        // One repository for the whole scan, built per scan rather than held by
        // the service: a service lives across scans (a watcher, the MCP server),
        // and the repository caches every statement it prepares, including
        // pruning deletes whose SQL varies with the batch, so a held one would
        // keep them all for the life of the process.
        $repository = new SqliteGraphRepository($this->pdo);
        try {
            $plan = $this->planner->finalize($preparation);
            $effectiveMode = $plan->effectiveMode;
            $this->workerPool->prepare($preparation->executionPolicy);
            $stageMilliseconds['planning'] = $preparation->planningMilliseconds + self::elapsedMilliseconds($planningStarted);

            $language = $this->languageRunner->run($plan, $cancellation);
            $stageMilliseconds += $language->stageMilliseconds;
            // Cancellation wins over fidelity reporting: an abandoned scan
            // must surface ScanCancelledException, not a worker degradation.
            $cancellation->throwIfCancelled();
            $this->refuseDegradedRescan($plan, $language, $projectId);
            // The workers read every file themselves, so their facts descend
            // from bytes this process never hashed. Discovery's own files are
            // checked before analysis; undiscovered worker inputs are checked at
            // the final write boundary below, after reconciliation preparation.
            //
            $validationStarted = hrtime(true);
            $this->snapshotValidator->validateDiscovery($preparation->discovery);
            $stageMilliseconds['snapshot_validation'] = self::elapsedMilliseconds($validationStarted);
            $analysisStarted = hrtime(true);
            $analysis = $this->analysisPipeline->analyze($plan, $language->contributions);
            $stageMilliseconds['analysis'] = self::elapsedMilliseconds($analysisStarted);
            $cancellation->throwIfCancelled();

            // What this scan's requests read, and then what every kept entry
            // read, reused ones included: a reused file sent no request, so a
            // dependency of it edited since planning shows up only here.
            $verifyUndiscovered = function () use ($preparation, $language, $plan): void {
                $verifier = new UndiscoveredInputVerifier();
                $root = $preparation->discovery->rootRealpath;
                $verifier->verify($root, $language->undiscoveredInputs, $preparation->maxFileBytes);
                $verifier->verify($root, self::entryReads($language, $plan, $preparation->discovery->hashedPaths()), $preparation->maxFileBytes);
            };

            $reconciliationStarted = hrtime(true);
            $projectConfig = $this->projectConfig($preparation);
            $fastPath = $this->noChangeFastPath($plan, $language, $preparation, $projectConfig, $name, $verifyUndiscovered, $repository);
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
            $result = (new GraphReconciler($repository))->reconcile(
                self::fullScanRequest($preparation, $plan, $language, $analysis, $projectConfig, $name),
                $verifyUndiscovered,
            );
            foreach ($result->phaseMilliseconds as $phase => $milliseconds) {
                $stageMilliseconds['reconciliation.' . $phase] = $milliseconds;
            }
            $stageMilliseconds['reconciliation'] = self::elapsedMilliseconds($reconciliationStarted);
            // What rebuilding this graph cost, end to end, recorded here
            // because this is the only place that knows it: reconciliation
            // completes the scan row but sees neither discovery nor analysis.
            // RefreshPolicy reads it to decide whether repeating the work fits
            // inside a query the caller is already waiting on.
            $repository->recordScanDuration(
                $result->projectId,
                $result->scanId,
                (int) round(self::elapsedMilliseconds($startedAt)),
            );

            return $this->resultFactory->create($plan, $language, $result, $startedAt, $stageMilliseconds);
        } catch (\Throwable $error) {
            self::recordFailedScan($repository, $projectId, $effectiveMode, $error);
            throw $error;
        } finally {
            if (!$held && $lease->release() === 0) {
                error_log(sprintf('Knossos: writer lease for project %s released zero rows (already expired or stolen).', $projectId));
            }
        }
    }
    /**
     * Refuse a rescan that would reconcile away a failed language's facts.
     *
     * A rescan must never do so, whatever its mode: doing so prunes the last
     * good facts for a worker that failed and can replace a healthy graph with
     * an empty one. That holds for a requested full rescan as much as an
     * incremental one, so such a scan fails closed and the caller can repair
     * the worker and retry without data loss. A full rescan whose failed
     * languages hold no facts in the graph has nothing to lose, and degrades
     * per language as a first scan does; refusing it would leave no way to
     * update the other languages until the broken worker is repaired.
     */
    private function refuseDegradedRescan(ScanPlan $plan, LanguageScanResult $language, string $projectId): void
    {
        if (!$plan->hadActiveScan || $language->workerDiagnostics === []) {
            return;
        }
        $owners = array_values(array_unique(array_map(
            static fn(array $diagnostic): string => (string) ($diagnostic['owner'] ?? 'unknown'),
            $language->workerDiagnostics,
        )));
        if ($plan->effectiveMode !== 'full' || $this->graphHoldsFactsOf($projectId, $owners)) {
            $failed = array_map(
                static fn(array $diagnostic): string => ($diagnostic['owner'] ?? 'unknown') . ': ' . ($diagnostic['code'] ?? 'WORKER_FAILED'),
                $language->workerDiagnostics,
            );
            throw new WorkerException(
                'WORKER_DEGRADED_INCREMENTAL',
                'Scan aborted to preserve the last good graph. Failed workers: ' . implode(', ', $failed)
                    . '. Fix the worker (run `knossos doctor`) and rescan; the graph is unchanged.',
            );
        }
    }

    /**
     * What reconciliation is asked to write for this scan.
     *
     * @param array<string, mixed> $projectConfig
     */
    private static function fullScanRequest(
        ScanPreparation $preparation,
        ScanPlan $plan,
        LanguageScanResult $language,
        ScanAnalysis $analysis,
        array $projectConfig,
        ?string $name,
    ): FullScanRequest {
        return new FullScanRequest(
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
            // Captured before discovery walked the tree, so the commit the
            // scan records is one its own bytes cannot predate.
            $preparation->gitHead,
            $preparation->dirtyPaths,
            self::workerInputs($language, $plan, $preparation->discovery->hashedPaths()),
            $language->readGroups,
        );
    }

    /**
     * Persist the terminal attempt so it is observable and reapable by
     * stale-scan cleanup. Best-effort: never let bookkeeping mask the
     * original failure, and never record for a project that reconcile
     * never created (recordFailedScan no-ops when the project is absent).
     */
    private static function recordFailedScan(SqliteGraphRepository $repository, string $projectId, string $effectiveMode, \Throwable $error): void
    {
        $status = $error instanceof ScanCancelledException ? 'cancelled' : 'failed';
        try {
            $repository->recordFailedScan(
                \Knossos\Store\StableId::scan($projectId, bin2hex(random_bytes(16))),
                $projectId,
                $effectiveMode,
                $status,
            );
        } catch (\Throwable) {
            // Ignore: the original failure is what matters.
        }
    }

    /** Milliseconds since a hrtime() mark, for the stage timings. */

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    /**
     * Whether the project's graph holds any node owned by one of these scanners.
     *
     * Every fact a scanner contributes is owned by a key that starts with its
     * id and a colon, and every file it analysed carries at least its file
     * node, so a scanner with no node under that prefix has nothing to lose.
     *
     * @param list<string> $scannerIds
     */
    private function graphHoldsFactsOf(string $projectId, array $scannerIds): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM nodes WHERE project_id = :project AND owner_key >= :low AND owner_key < :high LIMIT 1',
        );
        foreach ($scannerIds as $scannerId) {
            // ';' is the character after ':', so the range is exactly the
            // keys that start with "<id>:" and the owner index can serve it.
            $statement->execute(['project' => $projectId, 'low' => $scannerId . ':', 'high' => $scannerId . ';']);
            $found = $statement->fetchColumn() !== false;
            $statement->closeCursor();
            if ($found) {
                return true;
            }
        }

        return false;
    }

    /**
     * The project's stored configuration, needed to honour snapshot retention on completion.
     *
     * The ignores are recorded because the staleness probe has to exclude what
     * this scan excluded, and the scan is the only thing that knows what that
     * was: knossos.json can be edited or deleted after the fact, and a probe
     * rebuilding the set from the file it finds later would be describing a
     * different walk. Stored, a probe counting an ignored path as drift is
     * impossible rather than merely unlikely.
     *
     * @return array<string, mixed>
     */
    private function projectConfig(ScanPreparation $preparation): array
    {
        return [
            'input_hash' => $preparation->discovery->inputHash,
            'configuration_hash' => $preparation->discovery->configurationHash,
            'ignores' => $preparation->configuration->ignores,
            'snapshot_retention' => $preparation->snapshotRetention,
            'dead_code_suppressions' => $preparation->configuration->deadCodeSuppressions,
        ];
    }

    /**
     * Every file a kept contribution read that discovery did not hash, with
     * the hash it was read at, recorded on the scan for drift checks.
     *
     * Derived from the cache entries rather than from this scan's requests: a
     * contribution reused from the cache still depends on what it read when it
     * was built, and a scan that sent no request for it must record that too.
     * A probed miss is left out; it names no bytes to compare.
     *
     * @param array<string, object> $discovered every path discovery hashed
     * @return array<string, string>
     */
    private static function workerInputs(LanguageScanResult $language, ScanPlan $plan, array $discovered): array
    {
        $inputs = array_filter(self::entryReads($language, $plan, $discovered), static fn(?string $hash): bool => $hash !== null);
        ksort($inputs, SORT_STRING);

        return $inputs;
    }

    /**
     * Every file a kept contribution read that discovery did not hash, with
     * the hash it was read at, or null for a probed miss.
     *
     * @param array<string, object> $discovered every path discovery hashed
     * @return array<string, ?string>
     */
    private static function entryReads(LanguageScanResult $language, ScanPlan $plan, array $discovered): array
    {
        $groups = $language->readGroups + ($plan->cachedReads->groupReads ?? []);
        $reads = [];
        foreach ($language->cacheEntries as $entry) {
            foreach ([$entry->reads, $entry->readGroup === null ? [] : ($groups[$entry->readGroup] ?? [])] as $entryReads) {
                foreach ($entryReads as $path => $hash) {
                    if (!isset($discovered[(string) $path])) {
                        $reads[(string) $path] = $hash;
                    }
                }
            }
        }

        return $reads;
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
     * @param callable(): void $verifyUndiscovered
     */
    private function noChangeFastPath(ScanPlan $plan, LanguageScanResult $language, ScanPreparation $preparation, array $projectConfig, ?string $name, callable $verifyUndiscovered, SqliteGraphRepository $repository): ?ReconciliationResult
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
        $verifyUndiscovered();
        $this->recordVerifiedGraph($repository, $plan->projectId, (string) $row['active_scan_id'], $preparation->discovery->files);
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
    private function recordVerifiedGraph(SqliteGraphRepository $repository, string $projectId, string $activeScanId, array $files): void
    {
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
