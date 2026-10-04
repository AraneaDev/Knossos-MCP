<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

/**
 * How many test files reach each of a few changed files, one file at a time:
 * what lets the architecture pane mark a changed file no test reaches.
 *
 * A test search over a set of files says which tests reach the set, not
 * which file each reaches; so each file is searched on its own
 * ({@see ArchitectureQueryService::testImpact()}), the most depended on
 * first, within a cap on files and a deadline across them all. A file is
 * answered with a count only when that is known: a file the graph places no
 * component in (a document, a configuration file) has no tests to count, and
 * a search cut short that found none says nothing either way, so neither is
 * listed. Neither is a file past the cap or the deadline.
 *
 * Read-only, never scans.
 */
final readonly class FileTestReach
{
    /** The most files searched: past them, a file is not marked either way. */
    public const MAX_FILES = 60;

    /** The time all the searches together may take. */
    public const DEADLINE_MS = 3000;

    /** The time one file's search may take. */
    private const PER_FILE_MS = 500;

    /** Tests counted per file: a count at it is a floor, which is all a mark needs. */
    public const COUNTED = 20;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param Closure|null $clock milliseconds since some start, so the deadline is testable
     */
    public function __construct(private PDO $pdo, private ?Closure $clock = null) {}

    /**
     * The tests reaching each of `$files` (project-relative), at most
     * {@see self::COUNTED}, for the files a count is known of.
     *
     * @param list<string> $files the files to search, the most telling first
     * @return array<string, int>
     */
    public function reach(string $projectId, array $files): array
    {
        $queries = new ArchitectureQueryService($this->pdo);
        $now = $this->clock ?? static fn(): float => microtime(true) * 1000;
        $until = $now() + self::DEADLINE_MS;
        $counts = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            $left = (int) ($until - $now());
            if ($left <= 0) {
                break;
            }
            $found = $queries->testImpact($projectId, [$file], limit: self::COUNTED, timeoutMs: max(1, min(self::PER_FILE_MS, $left)));
            $tests = count($found->data['test_files'] ?? []);
            $unplaced = in_array($file, $found->data['unresolved_files'] ?? [], true);
            if ($unplaced || ($tests === 0 && $found->truncated)) {
                continue;
            }
            $counts[$file] = $tests;
        }

        return $counts;
    }
}
