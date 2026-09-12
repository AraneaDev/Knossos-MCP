<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The file listing on its page bounds and its search escaping.
 *
 * FileMetricsQueryService scored 78% under mutation testing. Its defaults were
 * never asserted, so the page size and starting offset could change silently,
 * and the escaping that keeps a search term literal was untested, so a path
 * containing an underscore searched as a wildcard and matched files it should
 * not have.
 */
final class FileMetricsBoundsTest extends KnossosTestCase
{
    /** Fifty files a page, starting at the first, unless the caller says otherwise. */
    #[Group('query')]
    public function testTheDefaultPageIsFiftyFilesFromTheStart(): void
    {
        [$pdo, $project] = $this->files(51);
        $queries = new ArchitectureQueryService($pdo);

        $page = $queries->fileMetrics($project)->data['files'];

        assertSame(50, count($page), 'Fifty a page by default.');
        assertSame('src/File000.php', $page[0]['path'], 'Starting at the first file, not the second.');

        // The caller's own values are used when given.
        assertSame(2, count($queries->fileMetrics($project, limit: 2)->data['files']));
        assertSame('src/File001.php', $queries->fileMetrics($project, limit: 1, offset: 1)->data['files'][0]['path']);
    }

    /** The advertised offset ceiling is accepted, and one past it is refused. */
    #[Group('query')]
    public function testTheOffsetCeilingIsAHundredThousand(): void
    {
        [$pdo, $project] = $this->files(1);
        $queries = new ArchitectureQueryService($pdo);

        assertSame([], $queries->fileMetrics($project, offset: 100_000)->data['files']);
        assertThrows(fn() => $queries->fileMetrics($project, offset: 100_001), InvalidArgumentException::class);
        assertThrows(fn() => $queries->fileMetrics($project, offset: -1), InvalidArgumentException::class);
        // The limit is checked too, by the shared guard.
        assertThrows(fn() => $queries->fileMetrics($project, limit: 0), InvalidArgumentException::class);
    }

    /** Sizes and line counts are numbers, not the strings a driver might hand back. */
    #[Group('query')]
    public function testSizesAndLineCountsAreReportedAsNumbers(): void
    {
        [$pdo, $project] = $this->files(1);

        $file = (new ArchitectureQueryService($pdo))->fileMetrics($project)->data['files'][0];

        assertSame(120, $file['bytes']);
        assertSame(12, $file['line_count']);
    }

    /**
     * A search term is matched literally, so its wildcards are its own
     * characters.
     *
     * An underscore is SQL's single-character wildcard. Unescaped, a search for
     * `a_b` also returns `axb`, which is the kind of quiet wrongness that makes
     * a listing untrustworthy rather than visibly broken.
     */
    #[Group('query')]
    public function testASearchTermIsMatchedLiterally(): void
    {
        [$pdo, $project] = $this->files(0);
        self::addFile($pdo, $project, 'src/a_b.php');
        self::addFile($pdo, $project, 'src/axb.php');
        self::addFile($pdo, $project, 'src/100%done.php');

        $queries = new ArchitectureQueryService($pdo);

        assertSame(
            ['src/a_b.php'],
            array_column($queries->fileMetrics($project, pathContains: 'a_b')->data['files'], 'path'),
            'The underscore is a character here, not a wildcard.',
        );
        assertSame(
            ['src/100%done.php'],
            array_column($queries->fileMetrics($project, pathContains: '100%d')->data['files'], 'path'),
            'Nor is the percent sign.',
        );
    }

    /** Sorting runs on the column asked for, in the direction asked for. */
    #[Group('query')]
    public function testSortingRunsOnTheColumnAndDirectionAskedFor(): void
    {
        [$pdo, $project] = $this->files(0);
        self::addFile($pdo, $project, 'src/aaa.php', lineCount: 5);
        self::addFile($pdo, $project, 'src/zzz.php', lineCount: 900);
        $queries = new ArchitectureQueryService($pdo);

        assertSame('src/zzz.php', $queries->fileMetrics($project)->data['files'][0]['path'], 'Longest first by default.');
        assertSame('src/aaa.php', $queries->fileMetrics($project, sortBy: 'line_count', order: 'asc')->data['files'][0]['path']);
        assertSame('src/zzz.php', $queries->fileMetrics($project, sortBy: 'path')->data['files'][0]['path'], 'Path sort is descending by default too.');
        assertSame('src/aaa.php', $queries->fileMetrics($project, sortBy: 'path', order: 'asc')->data['files'][0]['path']);

        assertThrows(fn() => $queries->fileMetrics($project, sortBy: 'bytes'), InvalidArgumentException::class);
        assertThrows(fn() => $queries->fileMetrics($project, order: 'sideways'), InvalidArgumentException::class);
    }

    /** @return array{0: PDO, 1: string} */
    private function files(int $count): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $pdo->exec("DELETE FROM files WHERE project_id = '" . $ids['project'] . "'");
        for ($index = 0; $index < $count; ++$index) {
            self::addFile($pdo, $ids['project'], sprintf('src/File%03d.php', $index));
        }

        return [$pdo, $ids['project']];
    }

    private static function addFile(PDO $pdo, string $projectId, string $path, int $lineCount = 12): void
    {
        $scanId = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '" . $projectId . "'")->fetchColumn();
        $statement = $pdo->prepare(
            'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) ' .
            "VALUES (:id, :project, :path, 'h', 120, 1, 'php', '1', :scan, :lines)",
        );
        $statement->execute(['id' => $projectId . ':' . $path, 'project' => $projectId, 'path' => $path, 'scan' => $scanId, 'lines' => $lineCount]);
    }
}
