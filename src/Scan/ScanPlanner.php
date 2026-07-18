<?php

declare(strict_types=1);

namespace Knossos\Scan;

use InvalidArgumentException;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\{DiscoveryConfig, ProjectDiscoverer};
use Knossos\Store\StableId;
use PDO;

final readonly class ScanPlanner
{
    /** @param list<string> $allowedRoots */
    public function __construct(private PDO $pdo, private array $allowedRoots) {}

    /** @param list<array<string, mixed>>|null $explicitBoundaries */
    public function prepare(
        string $root,
        ?int $maxFiles,
        ?int $maxFileBytes,
        ?array $explicitBoundaries,
        ?string $mode,
        ?int $snapshotRetention,
        ?int $workerTimeoutMs,
    ): ScanPreparation {
        $started = hrtime(true);
        $configuration = ProjectConfigurationLoader::load($root, $this->allowedRoots);
        $maxFiles ??= $configuration->maxFiles ?? 100_000;
        $maxFileBytes ??= $configuration->maxFileBytes ?? 2_000_000;
        $explicitBoundaries ??= $configuration->boundaries;
        $mode ??= 'auto';
        $snapshotRetention ??= $configuration->snapshotRetention ?? 5;
        if (!in_array($mode, ['auto', 'full', 'incremental'], true)) {
            throw new InvalidArgumentException('Scan mode must be auto, full, or incremental.');
        }
        if ($snapshotRetention < 0 || $snapshotRetention > 20) {
            throw new InvalidArgumentException('snapshot_retention must be between 0 and 20.');
        }
        $executionPolicy = new \Knossos\Scanner\Worker\WorkerExecutionPolicy(
            $workerTimeoutMs ?? $configuration->workerTimeoutMs ?? \Knossos\Scanner\Worker\WorkerExecutionPolicy::DEFAULT_REQUEST_TIMEOUT_MS,
        );
        $configurationMilliseconds = self::elapsedMilliseconds($started);
        $started = hrtime(true);
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig(
            $this->allowedRoots,
            ignorePatterns: $configuration->ignores,
            maxFiles: $maxFiles,
            maxFileBytes: $maxFileBytes,
        )))->discover($root);
        $discoveryMilliseconds = self::elapsedMilliseconds($started);
        $started = hrtime(true);
        $laravel = in_array('laravel', $configuration->frameworks, true) || $this->hasComposerPackage($discovery->units, ['laravel/framework']);
        $symfony = in_array('symfony', $configuration->frameworks, true) || $this->hasComposerPackage($discovery->units, ['symfony/framework-bundle', 'symfony/http-kernel', 'symfony/console', 'symfony/messenger']);
        $configurationHashes = [
            'php' => $this->configurationHash($discovery->units, ['composer', 'knossos'], 'php-analysis-v3'),
            'typescript' => $this->configurationHash($discovery->units, ['node', 'typescript', 'knossos'], 'typescript-analysis-v2'),
            'python' => $this->configurationHash($discovery->units, ['python', 'knossos'], 'python-analysis-v2'),
        ];

        return new ScanPreparation(
            $configuration,
            $discovery,
            $maxFiles,
            $maxFileBytes,
            $explicitBoundaries,
            $mode,
            $snapshotRetention,
            $executionPolicy,
            $laravel,
            $symfony,
            $configurationHashes,
            $configurationMilliseconds,
            $discoveryMilliseconds,
            self::elapsedMilliseconds($started),
        );
    }

    public function finalize(ScanPreparation $preparation): ScanPlan
    {
        $projectId = StableId::project('root:' . $preparation->discovery->rootRealpath);
        $statement = $this->pdo->prepare('SELECT id, active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $existing = $statement->fetch();
        $effectiveMode = $preparation->requestedMode === 'full' || $existing === false || $existing['active_scan_id'] === null ? 'full' : 'incremental';
        $statement = $this->pdo->prepare('SELECT * FROM contribution_cache WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);
        $cachedRows = $statement->fetchAll();
        $cache = [];
        foreach ($cachedRows as $row) {
            $cache[$row['scanner_id'] . "\0" . $row['file_path']] = $row;
        }
        $current = array_fill_keys(array_map(static fn($file): string => $file->relativePath, $preparation->discovery->files), true);
        $old = array_fill_keys(array_column($cachedRows, 'file_path'), true);

        return new ScanPlan($preparation, $projectId, $effectiveMode, $cache, count(array_diff_key($old, $current)));
    }

    /** @param list<object> $units @param list<string> $packages */
    private function hasComposerPackage(array $units, array $packages): bool
    {
        foreach ($units as $unit) {
            $requirements = $unit->kind === 'composer' ? ($unit->metadata['requires'] ?? []) : [];
            if (!is_array($requirements)) {
                continue;
            }
            foreach ($packages as $package) {
                if (isset($requirements[$package])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param list<object> $units @param list<string> $kinds */
    private function configurationHash(array $units, array $kinds, string $version): string
    {
        $parts = [$version];
        foreach ($units as $unit) {
            if (in_array($unit->kind, $kinds, true)) {
                $parts[] = $unit->kind . ':' . $unit->configPath . '=' . $unit->contentHash;
            }
        }
        sort($parts, SORT_STRING);
        return hash('sha256', implode("\n", $parts));
    }

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
