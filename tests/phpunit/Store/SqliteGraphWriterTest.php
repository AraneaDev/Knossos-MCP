<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\SqliteGraphWriter;
use Knossos\Store\SqliteStatementCache;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Row writes, asserted by reading each row back. These are the statements a
 * scan replays thousands of times, and until now they were covered almost
 * entirely by tests that ran a whole scan to reach them.
 */
final class SqliteGraphWriterTest extends KnossosTestCase
{
    #[Group('store')]
    public function testSaveNodeStoresEveryColumnAndEncodesAttributes(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $id = StableId::symbol($ids['project'], 'php', 'class', 'App\\Stored');

        self::writer($pdo)->saveNode($id, $ids['project'], 'php', 'class', 'App\\Stored', 'Stored', $ids['checkout'], $ids['file'], 7, 9, 'ast', 'probable', ['path' => 'src/Café.php'], 'owner:x', $ids['scan']);

        $row = self::row($pdo, 'SELECT * FROM nodes WHERE id = ?', [$id]);
        assertSame('App\\Stored', $row['canonical_name']);
        assertSame('Stored', $row['display_name']);
        assertSame($ids['checkout'], $row['parent_id']);
        assertSame(7, (int) $row['start_line']);
        assertSame(9, (int) $row['end_line']);
        assertSame('probable', $row['confidence']);
        assertSame('{"path":"src/Café.php"}', $row['attributes_json']);
    }

    #[Group('store')]
    public function testSaveBoundaryUpsertsInsteadOfDuplicating(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $writer = self::writer($pdo);
        $boundary = StableId::boundary($ids['project'], 'Domain', 'explicit');

        $writer->saveBoundary($boundary, $ids['project'], 'Domain', ['path_prefix' => 'src/Domain'], 'explicit', $ids['scan']);
        $writer->saveBoundary($boundary, $ids['project'], 'Domain', ['path_prefix' => 'src/Core'], 'explicit', $ids['scan']);

        assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE id = '" . $boundary . "'")->fetchColumn());
        assertSame('{"path_prefix":"src/Core"}', self::row($pdo, 'SELECT matcher_json FROM boundaries WHERE id = ?', [$boundary])['matcher_json']);
    }

    #[Group('store')]
    public function testReplaceContributionCacheDropsEntriesItWasNotGiven(): void
    {
        [$pdo, , $ids] = $this->storeFixture();
        $pdo->exec(sprintf(
            "INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at) VALUES ('%s', 'o', 'stale.php', 'h', 's', '1', 'c', '{}', 'x')",
            $ids['project'],
        ));

        self::writer($pdo)->replaceContributionCache($ids['project'], []);

        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM contribution_cache')->fetchColumn());
    }

    #[Group('store')]
    public function testReplaceContributionCacheRejectsSomethingThatIsNotAnEntry(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        assertThrows(static fn() => self::writer($pdo)->replaceContributionCache($ids['project'], ['not an entry']), InvalidArgumentException::class);
    }

    private static function writer(PDO $pdo): SqliteGraphWriter
    {
        return new SqliteGraphWriter(new SqliteStatementCache($pdo));
    }

    /**
     * @param list<string> $parameters
     * @return array<string, mixed>
     */
    private static function row(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetch();
    }
}
