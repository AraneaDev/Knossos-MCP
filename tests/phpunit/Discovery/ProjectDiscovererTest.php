<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ScanCancelledException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for ProjectDiscoverer covering edge cases and error diagnostics
 * that the integration-level DiscoveryTest does not reach.
 *
 * @see DiscoveryTest for the main discovery integration tests
 */
#[Group('discovery')]
final class ProjectDiscovererTest extends KnossosTestCase
{
    private string $base;
    private string $root;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/knossos-pd-test-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/project';
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->base);
    }

    // ── Directory-level diagnostics ──────────────────────────────────

    public function testDiscoverReportsUnreadableDirectoryDiagnostic(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test permission errors when running as root.');
        }
        $sub = $this->root . '/locked';
        mkdir($sub, 0000, true);
        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$sub]));
        $result = $discoverer->discover($sub);

        $this->assertCount(1, $result->diagnostics);
        assertSame('DISCOVERY_DIRECTORY_UNREADABLE', $result->diagnostics[0]->code);
        chmod($sub, 0700);
    }

    public function testDiscoverReportsFileTooLargeDiagnostic(): void
    {
        file_put_contents($this->root . '/large.php', "<?php\n" . str_repeat("// padding\n", 100));
        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root], maxFileBytes: 10));
        $result = $discoverer->discover($this->root);

        $codes = array_column($result->diagnostics, 'code');
        $this->assertContains('DISCOVERY_FILE_TOO_LARGE', $codes);
        $this->assertEmpty(array_column($result->files, 'relativePath'));
    }

    public function testDiscoverReportsUnreadableFileDiagnostic(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test permission errors when running as root.');
        }
        $file = $this->root . '/secret.php';
        file_put_contents($file, "<?php\n");
        chmod($file, 0000);

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $codes = array_column($result->diagnostics, 'code');
        $this->assertContains('DISCOVERY_FILE_UNREADABLE', $codes);
        chmod($file, 0600);
    }

    public function testDiscoverReportsConfigUnreadableDiagnostic(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test permission errors when running as root.');
        }
        $config = $this->root . '/composer.json';
        file_put_contents($config, '{"name":"test/pkg"}');
        chmod($config, 0000);

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $codes = array_column($result->diagnostics, 'code');
        $this->assertContains('DISCOVERY_CONFIG_UNREADABLE', $codes);
        chmod($config, 0600);
    }

    public function testDiscoverReportsConfigInvalidDiagnostic(): void
    {
        file_put_contents($this->root . '/composer.json', 'this is not valid json');

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $codes = array_column($result->diagnostics, 'code');
        $this->assertContains('DISCOVERY_CONFIG_INVALID', $codes);
    }

    // ── Symlink diagnostics ─────────────────────────────────────────

    public function testDiscoverReportsSymlinkSkippedWhenInsideRoot(): void
    {
        // Target must be INSIDE the project root to trigger DISCOVERY_SYMLINK_SKIPPED
        file_put_contents($this->root . '/real.txt', 'data');
        symlink($this->root . '/real.txt', $this->root . '/linked.txt');

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $codes = array_column($result->diagnostics, 'code');
        $this->assertContains('DISCOVERY_SYMLINK_SKIPPED', $codes);
    }

    public function testDiscoverThrowsOnFileLimitExceeded(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            file_put_contents($this->root . "/f{$i}.php", "<?php\n");
        }

        assertThrows(
            fn() => (new ProjectDiscoverer(new DiscoveryConfig([$this->root], maxFiles: 2)))->discover($this->root),
            DiscoveryException::class,
        );
    }

    // ── Composer metadata edge cases ─────────────────────────────────

    public function testDiscoverReadsComposerAutoloadDev(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/autoload-dev',
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $composerUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'composer',
        ));
        $this->assertNotEmpty($composerUnits);
        $psr4 = $composerUnits[0]->metadata['psr4'];
        $this->assertArrayHasKey('App\\', $psr4);
        $this->assertArrayHasKey('App\\Tests\\', $psr4);
    }

    public function testDiscoverHandlesComposerWithDevOnlyAutoload(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/dev-only',
            'autoload-dev' => ['psr-4' => ['Dev\\' => 'dev/']],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $composerUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'composer',
        ));
        $this->assertNotEmpty($composerUnits);
        $this->assertArrayHasKey('Dev\\', $composerUnits[0]->metadata['psr4']);
    }

    public function testDiscoverSkipsInvalidPsr4Mappings(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/invalid',
            'autoload' => ['psr-4' => [
                'Valid\\' => 'src/',
                'Broken\\' => false,  // non-string path — filtered by !is_string($paths)
            ]],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $composerUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'composer',
        ));
        $this->assertNotEmpty($composerUnits);
        $psr4 = $composerUnits[0]->metadata['psr4'];
        $this->assertArrayNotHasKey('Broken\\', $psr4);
        $this->assertArrayHasKey('Valid\\', $psr4);
    }

    // ── Workspaces edge cases ───────────────────────────────────────

    public function testDiscoverReadsWorkspacesFromPackagesKey(): void
    {
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test/monorepo',
            'workspaces' => ['packages' => ['pkg-a', 'pkg-b']],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'node',
        ));
        $this->assertNotEmpty($nodeUnits);
        assertSame(['pkg-a', 'pkg-b'], $nodeUnits[0]->metadata['workspaces']);
    }

    // ── TypeScript metadata edge cases ───────────────────────────────

    public function testDiscoverReadsTypeScriptMetadata(): void
    {
        file_put_contents($this->root . '/tsconfig.json', json_encode([
            'compilerOptions' => [
                'allowJs' => true,
                'baseUrl' => '.',
                'paths' => ['@/*' => ['src/*']],
            ],
            'references' => [['path' => '../shared']],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $tsUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'typescript',
        ));
        $this->assertNotEmpty($tsUnits);
        $meta = $tsUnits[0]->metadata;
        assertSame(true, $meta['allow_js']);
        assertSame('.', $meta['base_url']);
        $this->assertArrayHasKey('@/*', $meta['paths']);
        $this->assertContains('../shared', $meta['references']);
    }

    public function testDiscoverReadsStringWorkspacesDirectly(): void
    {
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test/legacy-workspaces',
            'workspaces' => ['legacy-a', 'legacy-b'],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'node',
        ));
        $this->assertNotEmpty($nodeUnits);
        assertSame(['legacy-a', 'legacy-b'], $nodeUnits[0]->metadata['workspaces']);
    }

    // ── File type detection edge cases ───────────────────────────────

    public function testDiscoverProcessesTsconfigNamedVariants(): void
    {
        file_put_contents($this->root . '/tsconfig.app.json', json_encode([
            'compilerOptions' => [],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $tsUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'typescript',
        ));
        $this->assertNotEmpty($tsUnits);
    }

    // ── Broken symlink ──────────────────────────────────────────────

    public function testDiscoverReportsBrokenSymlinkEscape(): void
    {
        // A symlink whose target does not exist: realpath($absolute)
        // returns false, which means $escapes = true →
        // DISCOVERY_SYMLINK_ESCAPE.
        $outside = $this->base . '/missing-target.txt';
        @symlink($outside, $this->root . '/broken.php');

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $codes = array_column($result->diagnostics, 'code');
        assertArrayContains('DISCOVERY_SYMLINK_ESCAPE', $codes);
    }

    // ── Python with no name ──────────────────────────────────────────

    public function testDiscoverReadsPythonUnitWithoutName(): void
    {
        // pyproject.toml without a name field — exercises the
        // preg_match === 0 branch in readUnit().
        file_put_contents($this->root . '/pyproject.toml', <<<'TOML'
[project]
# no name field here
dependencies = []
TOML
        );

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $pyUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'python',
        ));
        $this->assertNotEmpty($pyUnits);
        $this->assertNull($pyUnits[0]->metadata['name']);
    }

    // ── Knossos config ───────────────────────────────────────────────

    public function testDiscoverReadsKnossosJsoncConfig(): void
    {
        // knossos.jsonc with version — exercises the 'knossos' case in
        // readUnit() match.
        file_put_contents($this->root . '/knossos.jsonc', json_encode([
            'version' => '2',
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $knossosUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'knossos',
        ));
        $this->assertNotEmpty($knossosUnits);
        assertSame('2', $knossosUnits[0]->metadata['version']);
    }

    // ── Composer non-string constraint ───────────────────────────────

    public function testDiscoverSkipsComposerNonStringConstraint(): void
    {
        // A package with an integer constraint (not string) is filtered
        // by the !is_string($constraint) guard in composerRequirements().
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/nonstring-constraint',
            'require' => [
                'valid/pkg' => '^1.0',
                'bogus/pkg' => 42,  // integer constraint — filtered out
            ],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $composerUnits = array_values(array_filter(
            $result->units,
            fn($u): bool => $u->kind === 'composer',
        ));
        $this->assertNotEmpty($composerUnits);
        $requires = $composerUnits[0]->metadata['requires'];
        $this->assertArrayHasKey('valid/pkg', $requires);
        $this->assertArrayNotHasKey('bogus/pkg', $requires);
    }

    // ── Cancellation ─────────────────────────────────────────────────

    public function testDiscoverObservesCancellationDuringWalk(): void
    {
        // The token is polled every 512 entries; create enough files to cross it.
        for ($i = 0; $i < 600; ++$i) {
            file_put_contents(sprintf('%s/f%04d.php', $this->root, $i), "<?php\n");
        }
        $token = new CancellationToken();
        $token->cancel();

        assertThrows(
            fn() => (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root, $token),
            ScanCancelledException::class,
        );
    }

    public function testDiscoverWithFreshTokenCompletesNormally(): void
    {
        file_put_contents($this->root . '/a.php', "<?php\n");
        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root, new CancellationToken());

        $this->assertCount(1, $result->files);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \DirectoryIterator($path);
        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                $this->rmrf($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
