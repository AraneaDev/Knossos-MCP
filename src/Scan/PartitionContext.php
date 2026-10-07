<?php

declare(strict_types=1);

namespace Knossos\Scan;

use PDO;

/**
 * Everything {@see ContributionCacheService::partition()} checks one
 * language's files against, apart from the files and the worker's manifest:
 * what the plan decided for the scan, the hashes the cache is keyed on, and
 * where the reused rows' payloads and reads come from.
 */
final readonly class PartitionContext
{
    /**
     * @param string $configurationHash the language's configuration hash a reusable row must carry
     * @param array<string, array<string, mixed>> $cache metadata rows keyed by scanner id and path;
     *        a row may carry `payload_json` itself, which is used when no PDO is given
     * @param bool $force whether every file is rescanned whatever the cache holds (a full scan)
     * @param string $analysisHash see {@see AnalysisHash}
     * @param ?string $leftOutConfigurationHash see {@see ContributionCacheService::leftOutConfigurationHash()};
     *        a row stored under it is reused too, and counted as left out
     * @param array<string, true> $invalidatedOwners owners a change reached ({@see ReadSetInvalidator}), counted as changed
     * @param ?PDO $pdo where the reused rows' payloads are read from, with `$projectId`
     * @param ?CachedReads $cachedReads the stored reads a reused entry carries over
     */
    public function __construct(
        public string $configurationHash,
        public array $cache,
        public bool $force,
        public string $analysisHash,
        public ?string $leftOutConfigurationHash = null,
        public array $invalidatedOwners = [],
        public ?PDO $pdo = null,
        public ?string $projectId = null,
        public ?CachedReads $cachedReads = null,
    ) {}
}
