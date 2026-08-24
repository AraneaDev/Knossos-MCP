<?php

declare(strict_types=1);

namespace Knossos\Scan;

use InvalidArgumentException;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\{AllowedRoots, DiscoveryConfig, ProjectDiscoverer};
use Knossos\Store\StableId;
use PDO;

/**
 * Turns a scan request into a plan.
 *
 * Resolves project configuration, discovers files, decides full versus
 * incremental, and computes the analyzer hashes reuse is keyed on. Allowed roots
 * are resolved here at call time, so a project granted after the server started is
 * scannable without a restart.
 */
final readonly class ScanPlanner
{
    private readonly AllowedRoots $roots;

    /** @param AllowedRoots|list<string> $allowedRoots */
    public function __construct(private PDO $pdo, AllowedRoots|array $allowedRoots)
    {
        $this->roots = AllowedRoots::of($allowedRoots);
    }

    /**
     * Resolve configuration, discover files, and decide the scan mode and limits.
     *
     * @param list<array<string, mixed>>|null $explicitBoundaries
     */
    public function prepare(
        string $root,
        ?int $maxFiles,
        ?int $maxFileBytes,
        ?array $explicitBoundaries,
        ?string $mode,
        ?int $snapshotRetention,
        ?int $workerTimeoutMs,
        ?int $workerMemoryMb = null,
    ): ScanPreparation {
        $started = hrtime(true);
        // Resolved here, not in the constructor: a root added to the
        // configuration file must take effect on the next scan, not the next
        // restart.
        $allowedRoots = $this->roots->current();
        // Pass the resolver, not the resolved list: it carries the roots-file
        // path, which is what makes a rejection actionable.
        $configuration = ProjectConfigurationLoader::load($root, $this->roots);
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
            workerMemoryMb: $workerMemoryMb ?? $configuration->workerMemoryMb,
        );
        $configurationMilliseconds = self::elapsedMilliseconds($started);
        $started = hrtime(true);
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig(
            $allowedRoots,
            ignorePatterns: $configuration->ignores,
            maxFiles: $maxFiles,
            maxFileBytes: $maxFileBytes,
        )))->discover($root);
        $discoveryMilliseconds = self::elapsedMilliseconds($started);
        $started = hrtime(true);
        $laravel = in_array('laravel', $configuration->frameworks, true) || $this->hasComposerPackage($discovery->units, ['laravel/framework']);
        $symfony = in_array('symfony', $configuration->frameworks, true) || $this->hasComposerPackage($discovery->units, ['symfony/framework-bundle', 'symfony/http-kernel', 'symfony/console', 'symfony/messenger']);
        $pythonFrameworks = self::detectedFramework(
            $discovery->units,
            ['python', 'requirements'],
            ['fastapi' => 'fastapi', 'django' => 'django', 'flask' => 'flask'],
            $configuration->frameworks,
        );
        $rustFrameworks = self::detectedFramework(
            $discovery->units,
            ['cargo'],
            // actix-web is the crate name; the semantic framework is actix.
            ['axum' => 'axum', 'actix-web' => 'actix', 'rocket' => 'rocket'],
            $configuration->frameworks,
        );
        $configurationHashes = [
            'php' => $this->configurationHash($discovery->units, ['composer', 'knossos'], 'php-analysis-v3'),
            'typescript' => $this->configurationHash($discovery->units, ['node', 'typescript', 'knossos'], 'typescript-analysis-v2'),
            // 'requirements' is in the hash because detectedFramework() reads
            // requirements.txt for the Python framework gating above: without
            // it, adding fastapi to requirements.txt would reuse contributions
            // scanned with enrichment switched off.
            'python' => $this->configurationHash($discovery->units, ['python', 'requirements', 'knossos'], 'python-analysis-v3'),
            // Cargo.toml is now a recorded unit (kind 'cargo'), so editing it
            // invalidates a Rust contribution's cache entry the same way
            // composer.json and package.json do for PHP and TypeScript.
            'rust' => $this->configurationHash($discovery->units, ['cargo', 'knossos'], 'rust-analysis-v1'),
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
            pythonFrameworks: $pythonFrameworks,
            rustFrameworks: $rustFrameworks,
        );
    }
    /** Complete the plan once the analyzer set is known. */

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

    /**
     * Frameworks an interpreter worker should enrich, as short names.
     *
     * A framework is included when the project's configuration names it
     * explicitly or a manifest unit lists its crate/package. `$dependencyNames`
     * maps manifest dependency names (e.g. the `actix-web` crate) to the
     * semantic name the worker understands (e.g. `actix`).
     *
     * @param list<object> $units
     * @param array<string, string> $dependencyNames
     * @param list<string> $configured
     * @return list<string>
     */
    private static function detectedFramework(array $units, array $unitKinds, array $dependencyNames, array $configured): array
    {
        // Configured hints span every language; only this language's own
        // semantic names may seed the result.
        $semantic = array_values(array_unique(array_values($dependencyNames)));
        $hits = [];
        foreach ($configured as $framework) {
            if (in_array($framework, $semantic, true)) {
                $hits[$framework] = true;
            }
        }
        foreach ($units as $unit) {
            if (!in_array($unit->kind, $unitKinds, true)) {
                continue;
            }
            $requires = $unit->metadata['requires'] ?? [];
            if (!is_array($requires)) {
                continue;
            }
            foreach ($dependencyNames as $dependency => $framework) {
                if (isset($requires[$dependency])) {
                    $hits[$framework] = true;
                }
            }
        }
        $result = array_keys($hits);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Whether a Composer dependency is present, used to detect frameworks worth enriching.
     *
     * @param list<object> $units @param list<string> $packages
     */
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

    /**
     * Identity of the analyzer configuration, so a change invalidates incremental reuse.
     *
     * @param list<object> $units @param list<string> $kinds
     */
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
    /** Milliseconds since a hrtime() mark, for the stage timings. */

    private static function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
