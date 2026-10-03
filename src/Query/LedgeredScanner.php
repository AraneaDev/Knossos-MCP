<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Discovery\AllowedRoots;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Watch\TreeFingerprint;
use PDO;
use Throwable;

/**
 * A scanner that records each scan of an existing project in the
 * {@see ScanLedger}: what every file it changed held before it, and the
 * policy violations those files held before it.
 *
 * Before scanning it fingerprints the tree and compares it with the hashes
 * the graph holds, which names the files the scan is about to change while
 * the graph still describes them; their violations are read then, one file
 * at a time so a later reader can take each file's from the first scan that
 * changed it. A first scan (no project yet) is passed through unrecorded:
 * there is no earlier graph to describe.
 */
final readonly class LedgeredScanner
{
    /** Files whose violations are read before a scan; past it, that scan records none. */
    public const MAX_CHECKED = 20;

    /**
     * @param PDO $pdo the graph database the scan writes
     * @param \Closure(string, ?string, ?CancellationToken): ResultEnvelope $inner the scan itself: root, mode, cancellation
     * @param list<array<string, mixed>>|null $policies the policies to check; null reads the project's knossos.json
     */
    public function __construct(
        private PDO $pdo,
        private \Closure $inner,
        private AllowedRoots $roots,
        private ?array $policies = null,
    ) {}

    /** A ledgered scan with a {@see ProjectScanService} of `$roots` as the scan itself. */
    public static function local(PDO $pdo, string $installationRoot, AllowedRoots $roots, ?array $policies = null): self
    {
        $service = new ProjectScanService($pdo, $installationRoot, $roots);
        return new self($pdo, static fn(string $root, ?string $mode, ?CancellationToken $cancellation): ResultEnvelope => $service->scan($root, mode: $mode, cancellation: $cancellation), $roots, $policies);
    }

    /** Scans `$root` in `$mode`, recording what the scan changed when the project already exists. */
    public function scan(string $root, ?string $mode = null, ?CancellationToken $cancellation = null): ResultEnvelope
    {
        $run = fn(): ResultEnvelope => ($this->inner)($root, $mode, $cancellation);
        $project = (new ProjectPathResolver($this->pdo))->resolve(realpath($root) ?: $root);
        if ($project === null) {
            return $run();
        }
        $projectId = (string) $project['id'];
        $ledger = new ScanLedger($this->pdo);
        $from = $ledger->activeSnapshot($projectId);
        $before = $ledger->hashes($projectId);
        $baselines = $this->baselines($projectId, (string) $project['root_realpath'], $before);
        $result = $run();
        $ledger->record($projectId, $from, $result->snapshotId, $before, $ledger->hashes($projectId), $baselines);
        return $result;
    }

    /**
     * The violations each file about to change holds now, by path; null when
     * the project declares no policies, the tree cannot be read, or more than
     * {@see self::MAX_CHECKED} files are about to change.
     *
     * @param array<string, string> $graph the hashes the graph holds
     * @return array<string, array{violations: array<string, array<string, mixed>>, truncated: bool}>|null
     */
    private function baselines(string $projectId, string $root, array $graph): ?array
    {
        $policies = FileViolationQuery::policies($root, $this->policies);
        if ($policies === []) {
            return null;
        }
        try {
            $tree = TreeFingerprint::of($root, $this->roots, false);
        } catch (Throwable) {
            return null;
        }
        $changing = array_keys(TreeFingerprint::changes($graph, $tree));
        if (count($changing) > self::MAX_CHECKED) {
            return null;
        }
        $query = new FileViolationQuery($this->pdo);
        $baselines = [];
        foreach ($changing as $path) {
            $found = $query->inFiles($projectId, $policies, [(string) $path]);
            if ($found !== null) {
                $baselines[(string) $path] = $found;
            }
        }
        return $baselines;
    }
}
