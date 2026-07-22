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
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
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
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
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
                static fn () => ProjectConfigurationLoader::load($root, [$root]),
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
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNKNOWN_KEY', $error->getMessage());
    }

    public function testLoadRejectsUnknownLimitsKey(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "limits": {"wat": 1}}');

        $error = captureThrows(
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
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
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('Invalid JSON configuration', $error->getMessage());
    }

    public function testLoadRejectsIgnorePatternWithParentTraversal(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "ignores": ["../etc/passwd"]}');

        $error = captureThrows(
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
    }

    public function testLoadRejectsIgnorePatternWithLeadingSlash(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "ignores": ["/abs/path"]}');

        $error = captureThrows(
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
    }

    // ----- limits validation -----

    public function testLoadRejectsMaxFilesBelowOne(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "limits": {"max_files": 0}}');

        $error = captureThrows(
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
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
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
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
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_INVALID', $error->getMessage());
    }

    public function testLoadRejectsBoundaryWithAbsolutePathPrefix(): void
    {
        $root = $this->writeConfig('knossos.json', '{"version": 1, "boundaries": [{"name": "Core", "path_prefix": "/abs/foo"}]}');

        $error = captureThrows(
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
            DiscoveryException::class,
        );

        $this->assertStringContainsString('PROJECT_CONFIG_UNSAFE', $error->getMessage());
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
            static fn () => ProjectConfigurationLoader::load($root, [$root]),
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
}