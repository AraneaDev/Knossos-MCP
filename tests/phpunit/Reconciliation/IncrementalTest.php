<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class IncrementalTest extends KnossosTestCase
{
    #[Group('incremental')]
    public function testIncrementalContributionCacheDetectsEditsDeletesAndRenamesWithFullEquivalence(): void
    {
        $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
        $database = tempnam(sys_get_temp_dir(), 'knossos-incremental-db-');
        if ($database === false || !mkdir($root . '/src', 0700, true)) {
            throw new RuntimeException('Unable to create incremental fixture.');
        }
        file_put_contents($root . '/composer.json', json_encode([
            'name' => 'fixture/incremental', 'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/src/A.php', "<?php\nnamespace Fixture;\nfinal class A { public function __construct(private B \$value) {} }\n");
        file_put_contents($root . '/src/B.php', "<?php\nnamespace Fixture;\nfinal class B {}\n");
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $first = $service->scan($root);
            assertSame('full', $first->data['mode']);
            assertSame(2, $first->data['parsed_files']);
            assertSame(2, $first->data['added_files']);

            $unchanged = $service->scan($root);
            assertSame('incremental', $unchanged->data['mode']);
            assertSame(0, $unchanged->data['parsed_files']);
            assertSame(2, $unchanged->data['unchanged_files']);
            assertSame(0, $unchanged->data['deleted_files']);

            file_put_contents($root . '/src/B.php', "<?php\nnamespace Fixture;\nfinal class C {}\n");
            $changed = $service->scan($root);
            assertSame(1, $changed->data['parsed_files']);
            assertSame(1, $changed->data['unchanged_files']);
            assertSame(1, $changed->data['changed_files']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE canonical_name = 'Fixture\\B' AND origin = 'derived'")->fetchColumn());

            file_put_contents($root . '/src/A.php', "<?php\nnamespace Fixture;\nfinal class A { public function __construct(private C \$value) {} }\n");
            $relinked = $service->scan($root);
            assertSame(1, $relinked->data['parsed_files']);
            assertSame(1, (int) $pdo->query(
                "SELECT COUNT(*) FROM edges e JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'injects' AND t.canonical_name = 'Fixture\\C'",
            )->fetchColumn());

            rename($root . '/src/B.php', $root . '/src/C.php');
            $renamed = $service->scan($root);
            assertSame(1, $renamed->data['parsed_files']);
            assertSame(1, $renamed->data['added_files']);
            assertSame(1, $renamed->data['deleted_files']);
            assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM contribution_cache')->fetchColumn());

            $incrementalGraph = $this->graphSignature($pdo);
            $full = $service->scan($root, mode: 'full');
            assertSame('full', $full->data['mode']);
            assertSame(2, $full->data['parsed_files']);
            assertSame($incrementalGraph, $this->graphSignature($pdo));
        } finally {
            unset($pdo);
            $this->removeFixtureTree($root);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
