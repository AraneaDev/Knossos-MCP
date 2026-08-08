<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Configuration;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('project-configuration-loader')]
final class ProjectConfigurationLoaderTest extends TestCase
{
    private string $tempDir;

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
    }

    // ----- helpers -----

    private function freshProjectRoot(): string
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-project-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        return $this->tempDir;
    }

    /**
     * A project root outside $tempDir, cleaned immediately.
     *
     * The boundary tests need two roots in one test — one at the limit, one over
     * it — and tearDown only removes the last $tempDir, so the second would leak.
     */
    private static function isolatedRoot(string $filename, string $contents): string
    {
        $root = sys_get_temp_dir() . '/knossos-bound-' . uniqid('', true);
        mkdir($root, 0o777, true);
        file_put_contents($root . '/' . $filename, $contents);
        register_shutdown_function(static function () use ($root, $filename): void {
            @unlink($root . '/' . $filename);
            @rmdir($root);
        });

        return $root;
    }

    private function writeConfig(string $filename, string $contents): string
    {
        $root = $this->tempDir ?? $this->freshProjectRoot();
        $path = $root . '/' . $filename;
        file_put_contents($path, $contents);

        return $root;
    }

    private static function minimalValidJson(): string
    {
        return <<<'JSON'
{
    "version": 1,
    "ignores": ["vendor/", "*.bak"]
}
JSON;
    }

    // ----- happy paths -----

    public function testLoadReturnsDefaultConfigurationWhenNoKnossosFileExists(): void
    {
        $root = $this->freshProjectRoot();

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame(null, $config->path);
        assertSame([], $config->ignores);
        assertSame([], $config->frameworks);
        assertSame([], $config->boundaries);
    }

    public function testLoadAcceptsMinimalValidKnossosJson(): void
    {
        $root = $this->writeConfig('knossos.json', self::minimalValidJson());

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame('knossos.json', $config->path);
        assertSame(['vendor/', '*.bak'], $config->ignores);
    }

    public function testLoadPrefersKnossosJsoncWhenPresent(): void
    {
        $root = $this->writeConfig('knossos.jsonc', self::minimalValidJson());

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame('knossos.jsonc', $config->path);
    }

    public function testLoadRejectsAmbiguousBothFilesPresent(): void
    {
        $root = $this->freshProjectRoot();
        file_put_contents($root . '/knossos.json', self::minimalValidJson());
        file_put_contents($root . '/knossos.jsonc', self::minimalValidJson());

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_AMBIGUOUS', $error->getMessage());
    }

    // ----- file-level rejection -----

    public function testLoadRejectsConfigFileLargerThanOneMegabyte(): void
    {
        $oversized = str_repeat('x', 1_000_001);
        $root = $this->writeConfig('knossos.json', $oversized);

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
    }

    // ----- version validation -----

    public function testLoadRejectsVersionThatIsNotOne(): void
    {
        // Cover several non-1 versions and the missing-version case under
        // the SAME source branch — the contract is that ($data['version'] ?? null) !== 1.
        $root = $this->freshProjectRoot();
        $cases = [
            '{"version": 2, "ignores": []}',
            '{"version": 0, "ignores": []}',
            '{"version": -1, "ignores": []}',
            '{"ignores": []}',
        ];
        foreach ($cases as $contents) {
            $path = $root . '/knossos.json';
            file_put_contents($path, $contents);

            $error = captureThrows(
                static fn() => ProjectConfigurationLoader::load($root, [$root]),
                DiscoveryException::class,
            );

            $this->assertStringContainsString('PROJECT_CONFIG_VERSION_UNSUPPORTED', $error->getMessage());
        }
    }

    // ----- unknown keys -----

    public function testLoadRejectsUnknownRootKey(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "ignores": [], "wat": true}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNKNOWN_KEY', $error->getMessage());
    }

    public function testLoadRejectsUnknownLimitsKey(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "limits": {"wat": 1}}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNKNOWN_KEY', $error->getMessage());
    }

    // ----- ignore pattern validation -----

    public function testLoadRejectsIgnorePatternWithNulByte(): void
    {
        // json_decode (with JSON_THROW_ON_ERROR, inside JsonConfig::decode)
        // rejects NUL bytes inside string literals BEFORE the source can
        // inspect the value, so the str_contains($pattern, "\0") guard is
        // defense-in-depth (unreachable from any valid JSON document). The
        // observable contract — that NUL-bearing configs are rejected — is
        // exercised here via the JSON-decode failure path.
        $root = $this->freshProjectRoot();
        $path = $root . '/knossos.json';
        file_put_contents($path, "{\"version\":1,\"ignores\":[\"a\0b\"]}");

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('Invalid JSON configuration', $error->getMessage());
    }

    public function testLoadRejectsIgnorePatternWithParentTraversal(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "ignores": ["../etc/passwd"]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
    }

    public function testLoadRejectsIgnorePatternWithLeadingSlash(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "ignores": ["/abs/path"]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
    }

    public function testLoadRejectsAnIgnorePatternOverFiveHundredBytes(): void
    {
        $tooLong = str_repeat('a', 501);
        $root = $this->writeConfig('knossos.json', json_encode(['version' => 1, 'ignores' => [$tooLong]]));

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
        $this->assertStringContainsString('500 bytes', $error->getMessage());
    }

    /**
     * The whole point of compiling ignore patterns eagerly at load time is
     * that a pattern which cannot compile is attributed to the configuration
     * file that declared it, rather than surfacing later, mid-discovery, on
     * whatever arbitrary path happened to exercise it. '[z-a]' is a
     * descending character-class range, which has no valid PCRE
     * interpretation even once the class body is correctly escaped.
     */
    public function testLoadRejectsAnIgnorePatternThatCannotCompile(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "ignores": ["[z-a]"]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_INVALID', $error->getMessage());
        $this->assertStringContainsString('[z-a]', $error->getMessage());
    }

    // ----- limits validation -----

    public function testLoadRejectsMaxFilesBelowOne(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "limits": {"max_files": 0}}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_INVALID', $error->getMessage());
    }

    public function testLoadAcceptsMaxFilesAtBoundaries(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "limits": {"max_files": 1, "max_file_bytes": 1}}');

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame(1, $config->maxFiles);
        assertSame(1, $config->maxFileBytes);
    }

    // ----- frameworks validation -----

    public function testLoadAcceptsWhitelistedFrameworks(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "frameworks": ["laravel", "react"]}');

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame(['laravel', 'react'], $config->frameworks);
    }

    public function testLoadRejectsUnsupportedFrameworkHint(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "frameworks": ["ruby-on-rails"]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_INVALID', $error->getMessage());
    }

    public function testLoadDedupesAndPreservesFrameworkOrder(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "frameworks": ["laravel", "react", "laravel"]}');

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame(['laravel', 'react'], $config->frameworks);
    }

    // ----- boundaries validation -----

    public function testLoadAcceptsBoundaryWithPathPrefix(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "boundaries": [{"name": "Core", "path_prefix": "src/Domain"}]}');

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame([['name' => 'Core', 'path_prefix' => 'src/Domain']], $config->boundaries);
    }

    public function testLoadRejectsBoundaryWithoutName(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "boundaries": [{"path_prefix": "src/Domain"}]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_INVALID', $error->getMessage());
    }

    public function testLoadRejectsBoundaryWithAbsolutePathPrefix(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "boundaries": [{"name": "Core", "path_prefix": "/abs/foo"}]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
    }

    public function testLoadRejectsDuplicateBoundaryNames(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "boundaries": [{"name": "Core", "path_prefix": "src/A"}, {"name": "Core", "path_prefix": "src/B"}]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('duplicate boundary name', $error->getMessage());
    }

    public function testLoadRejectsBoundaryDeclaringBothPathAndNamespacePrefix(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "boundaries": [{"name": "Core", "path_prefix": "src/A", "namespace_prefix": "App\\\\Core"}]}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('not both', $error->getMessage());
    }

    // ----- quality budgets -----

    public function testLoadAcceptsValidQualityBudgets(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "quality_budgets": {"new_cycles": 0, "warning_diagnostics": 50}}');

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame(['new_cycles' => 0, 'warning_diagnostics' => 50], $config->qualityBudgets);
    }

    public function testLoadRejectsQualityBudgetAboveOneHundredThousand(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "quality_budgets": {"new_cycles": 100001}}');

        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_INVALID', $error->getMessage());
    }

    // ----- class shape -----

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(ProjectConfigurationLoader::class);

        $this->assertTrue($reflection->isFinal());
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function testLoadedConfigIsProjectConfigurationInstance(): void
    {
        $root = $this->writeConfig('knossos.json', self::minimalValidJson());

        $config = ProjectConfigurationLoader::load($root, [$root]);

        assertSame(true, $config instanceof ProjectConfiguration);
    }

    // ----- dead code suppressions -----

    public function testLoaderAcceptsDeadCodeSuppressions(): void
    {
        $root = sys_get_temp_dir() . '/knossos-config-' . bin2hex(random_bytes(6));
        mkdir($root, 0755, true);
        try {
            file_put_contents(
                $root . '/knossos.json',
                json_encode(['version' => 1, 'dead_code_suppressions' => ['App\\Legacy\\Exporter', 'KnossosPhpScanner\\FactCollector::*']]),
            );
            $config = \Knossos\Configuration\ProjectConfigurationLoader::load($root, [$root]);
            assertSame(['App\\Legacy\\Exporter', 'KnossosPhpScanner\\FactCollector::*'], $config->deadCodeSuppressions);
        } finally {
            @unlink($root . '/knossos.json');
            @rmdir($root);
        }
    }

    public function testLoaderRejectsNonStringSuppressions(): void
    {
        $root = sys_get_temp_dir() . '/knossos-config-' . bin2hex(random_bytes(6));
        mkdir($root, 0755, true);
        try {
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'dead_code_suppressions' => [42]]));
            assertThrows(
                static fn() => \Knossos\Configuration\ProjectConfigurationLoader::load($root, [$root]),
                \Knossos\Discovery\DiscoveryException::class,
            );
        } finally {
            @unlink($root . '/knossos.json');
            @rmdir($root);
        }
    }

    /**
     * The declared limits are behaviour, not decoration.
     *
     * Mutation testing left every one of these bounds alive: the ignore-list cap,
     * the max_files ceiling, and the configuration size cap could each be shifted
     * by one without a test noticing. A limit nothing pins is a limit that can be
     * changed by accident, and these ones decide whether a hostile or malformed
     * project configuration is rejected.
     */
    public function testTheIgnoreListCapAcceptsExactlyItsLimitAndRejectsOneMore(): void
    {
        $atLimit = $this->writeConfig('knossos.json', json_encode([
            'version' => 1,
            'ignores' => array_map(static fn(int $i): string => 'pattern' . $i, range(1, 100)),
        ], JSON_THROW_ON_ERROR));
        assertSame(100, count(ProjectConfigurationLoader::load($atLimit, [$atLimit])->ignores));

        $overLimit = self::isolatedRoot('knossos.json', json_encode([
            'version' => 1,
            'ignores' => array_map(static fn(int $i): string => 'pattern' . $i, range(1, 101)),
        ], JSON_THROW_ON_ERROR));
        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($overLimit, [$overLimit]),
            DiscoveryException::class,
        );
        assertContains('ignores must be a bounded list', $error->getMessage());
    }

    public function testTheMaxFilesCeilingAcceptsItsUpperBoundAndRejectsOneMore(): void
    {
        $atLimit = $this->writeConfig('knossos.json', json_encode([
            'version' => 1, 'limits' => ['max_files' => 100000],
        ], JSON_THROW_ON_ERROR));
        assertSame(100000, ProjectConfigurationLoader::load($atLimit, [$atLimit])->maxFiles);

        $overLimit = self::isolatedRoot('knossos.json', json_encode([
            'version' => 1, 'limits' => ['max_files' => 100001],
        ], JSON_THROW_ON_ERROR));
        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($overLimit, [$overLimit]),
            DiscoveryException::class,
        );
        assertContains('max_files', $error->getMessage());
    }

    public function testTheConfigurationSizeCapAcceptsItsLimitAndRejectsOneMoreByte(): void
    {
        // Padding inside a comment keeps the document valid JSONC while letting the
        // file size be controlled to the byte, so the boundary is exercised without
        // depending on how the parser handles a truncated document.
        $prefix = '{"version":1,"ignores":["x"]} //';
        $atLimit = $this->writeConfig('knossos.jsonc', $prefix . str_repeat('p', 1000000 - strlen($prefix)));
        assertSame(['x'], ProjectConfigurationLoader::load($atLimit, [$atLimit])->ignores);

        $overLimit = self::isolatedRoot('knossos.jsonc', $prefix . str_repeat('p', 1000001 - strlen($prefix)));
        $error = captureThrows(
            static fn() => ProjectConfigurationLoader::load($overLimit, [$overLimit]),
            DiscoveryException::class,
        );
        assertContains('PROJECT_CONFIG_UNSAFE', $error->getMessage());
    }

}
