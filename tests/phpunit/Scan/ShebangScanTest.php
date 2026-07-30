<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\Fixtures;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Discovery and the workers must agree on what counts as a source file.
 *
 * Discovery classifies an extensionless script by its shebang, but every worker
 * independently re-validates the paths it is handed and each one gated on the
 * file extension alone. A repository containing `artisan`, `bin/console`, or
 * this project's own `workers/php/bin/worker` therefore routed a file to a
 * worker that refused it, and the refusal was a request-level error — so one
 * extensionless script aborted the entire scan rather than degrading to a
 * diagnostic about that one file.
 */
final class ShebangScanTest extends KnossosTestCase
{
    use Fixtures;

    /** @var list<string> */
    private array $roots = [];

    #[Group('scan')]
    public function testAnExtensionlessPhpScriptIsScannedRatherThanAbortingTheScan(): void
    {
        $root = $this->tree([
            'composer.json' => '{"name":"fixture/shebang"}',
            'src/Regular.php' => "<?php\nnamespace Fixture;\nclass Regular {}\n",
            'bin/tool' => "#!/usr/bin/env php\n<?php\nnamespace Fixture;\nclass ShebangTool {}\n",
        ]);

        [$pdo, $result] = $this->scanTree($root);

        assertSame(2, $result->data['files']);
        assertArrayContains('Fixture\\ShebangTool', $this->canonicalNames($pdo));
    }

    #[Group('scan')]
    public function testExtensionlessNodeAndPythonScriptsAreScannedToo(): void
    {
        $root = $this->tree([
            'package.json' => '{"name":"fixture-shebang","version":"1.0.0"}',
            'bin/cli' => "#!/usr/bin/env node\nexport function shebangCli() {\n    return 1;\n}\n",
            'bin/task' => "#!/usr/bin/env python3\ndef shebang_task():\n    return 1\n",
        ]);

        [$pdo, $result] = $this->scanTree($root);

        assertSame(2, $result->data['files']);
        $names = $this->canonicalNames($pdo);
        assertSame(true, $this->anyContains($names, 'shebangCli'));
        assertSame(true, $this->anyContains($names, 'shebang_task'));
    }

    #[Group('scan')]
    public function testAnUnreadableSourceFileDegradesToADiagnosticInsteadOfFailingTheScan(): void
    {
        // A byte limit small enough to reject one file but not the other proves
        // the surviving file is still reconciled: a per-file rejection must not
        // discard the facts every other file already contributed.
        $root = $this->tree([
            'composer.json' => '{"name":"fixture/oversize"}',
            'src/Small.php' => "<?php\nnamespace Fixture;\nclass Small {}\n",
            'src/Large.php' => "<?php\nnamespace Fixture;\nclass Large {}\n" . str_repeat("// pad\n", 200),
        ]);

        [$pdo, $result] = $this->scanTree($root, maxFileBytes: 400);

        assertArrayContains('Fixture\\Small', $this->canonicalNames($pdo));
        assertSame(true, $result->data['diagnostics'] >= 1);
    }

    /**
     * Run a real end-to-end scan of a temporary tree.
     *
     * @return array{0: PDO, 1: \Knossos\Query\ResultEnvelope}
     */
    private function scanTree(string $root, ?int $maxFileBytes = null): array
    {
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);

        return [$pdo, $service->scan($root, maxFileBytes: $maxFileBytes)];
    }

    /**
     * Every component name the scan persisted.
     *
     * @return list<string>
     */
    private function canonicalNames(PDO $pdo): array
    {
        /** @var list<string> $names */
        $names = $pdo->query('SELECT canonical_name FROM nodes')->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }

    /**
     * Whether any name contains the needle, for scanners that qualify names by
     * module path (`bin/cli#shebangCli`) rather than by namespace.
     *
     * @param list<string> $names
     */
    private function anyContains(array $names, string $needle): bool
    {
        foreach ($names as $name) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Materialise a throwaway project tree.
     *
     * @param array<string, string> $files relative path to contents
     */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir() . '/knossos-shebang-scan-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o755, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create fixture root: ' . $root);
        }
        $this->roots[] = $root;
        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create fixture directory: ' . $directory);
            }
            file_put_contents($path, $contents);
        }

        return $root;
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
        $this->roots = [];
    }

    /** Depth-first removal, scoped to the fixture prefix this class creates. */
    private static function removeTree(string $root): void
    {
        if (!str_starts_with($root, sys_get_temp_dir() . '/knossos-shebang-scan-') || !is_dir($root)) {
            return;
        }
        /** @var iterable<\SplFileInfo> $entries */
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($root);
    }
}
