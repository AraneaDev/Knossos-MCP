<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class IncrementalLanguageTest extends KnossosTestCase
{
    #[Group('incremental-language')]
    public function testLongLivedScansReuseTypescriptProgramsAndInvalidateOnlyAffectedAnalyzers(): void
    {
        $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
        $database = tempnam(sys_get_temp_dir(), 'knossos-language-cache-');
        if ($database === false || !mkdir($root . '/src', 0700, true)) {
            throw new RuntimeException('Unable to create language cache fixture.');
        }
        $composer = ['name' => 'fixture/mixed-cache', 'require' => ['laravel/framework' => '^12.0'], 'autoload' => ['psr-4' => ['Fixture\\' => 'src/']]];
        file_put_contents($root . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        file_put_contents($root . '/package.json', json_encode(['name' => 'fixture-mixed-cache', 'private' => true], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/tsconfig.json', json_encode(['compilerOptions' => ['target' => 'ES2022'], 'include' => ['src/*.ts']], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/src/A.php', "<?php\nnamespace Fixture;\nuse Illuminate\\Support\\Facades\\Route;\nfinal class A {}\nRoute::get('/a', static fn () => null);\n");
        file_put_contents($root . '/src/A.ts', "export class A { value = 1; }\n");
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $first = $service->scan($root);
            assertSame(2, $first->data['parsed_files']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
            $none = $service->scan($root);
            assertSame(0, $none->data['parsed_files']);

            file_put_contents($root . '/src/A.ts', "export class A { value = 2; }\n");
            $typescriptChange = $service->scan($root);
            assertSame(1, $typescriptChange->data['parsed_files']);
            assertSame(1, $typescriptChange->data['unchanged_files']);
            assertSame(true, $typescriptChange->data['scanner_metadata']['knossos.typescript']['programs_reused'] >= 1);

            file_put_contents($root . '/tsconfig.json', json_encode(['compilerOptions' => ['target' => 'ES2022', 'strict' => true], 'include' => ['src/*.ts']], JSON_THROW_ON_ERROR));
            $typescriptConfig = $service->scan($root);
            assertSame(1, $typescriptConfig->data['parsed_files']);
            assertSame(1, $typescriptConfig->data['unchanged_files']);
            assertSame(true, isset($typescriptConfig->data['scanner_metadata']['knossos.typescript']));
            assertSame(false, isset($typescriptConfig->data['scanner_metadata']['knossos.php']));

            $composer['description'] = 'invalidate only PHP and Laravel enrichment';
            file_put_contents($root . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
            $phpConfig = $service->scan($root);
            assertSame(1, $phpConfig->data['parsed_files']);
            assertSame(1, $phpConfig->data['unchanged_files']);
            assertSame(true, isset($phpConfig->data['scanner_metadata']['knossos.php']));
            assertSame(false, isset($phpConfig->data['scanner_metadata']['knossos.typescript']));
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());

            $pdo->exec("UPDATE contribution_cache SET scanner_version = '0.0.0' WHERE scanner_id = 'knossos.typescript'");
            $versionChange = $service->scan($root);
            assertSame(1, $versionChange->data['parsed_files']);
            assertSame(1, $versionChange->data['unchanged_files']);
            assertSame('0.3.0', (string) $pdo->query(
                "SELECT scanner_version FROM contribution_cache WHERE scanner_id = 'knossos.typescript'",
            )->fetchColumn());
        } finally {
            unset($service, $pdo);
            $this->removeFixtureTree($root);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
