<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Discovery\SourceClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The path classification discovery, the drift oracles and the watcher share,
 * asked directly: one case per branch, so a broken branch names itself rather
 * than surfacing as a file missing from a scan.
 */
#[Group('discovery')]
final class SourceClassifierTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/knossos-classifier-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function unitKinds(): iterable
    {
        yield 'gitignore' => ['sub/.gitignore', 'gitignore'];
        yield 'readme' => ['README.md', 'readme'];
        yield 'localised readme' => ['docs/readme.nl.md', 'readme'];
        yield 'not a readme' => ['readme-notes.md', null];
        yield 'shell' => ['bin/run.sh', 'shell'];
        yield 'bash' => ['bin/run.bash', 'shell'];
        yield 'dockerfile' => ['Dockerfile', 'dockerfile'];
        yield 'dockerfile variant' => ['Dockerfile.dev', 'dockerfile'];
        yield 'dockerfile suffix' => ['api.dockerfile', 'dockerfile'];
        yield 'composer' => ['composer.json', 'composer'];
        yield 'knossos' => ['knossos.json', 'knossos'];
        yield 'knossos jsonc' => ['knossos.jsonc', 'knossos'];
        yield 'node' => ['pkg/package.json', 'node'];
        yield 'azure function' => ['fn/function.json', 'azure_function'];
        yield 'html' => ['index.html', 'html'];
        yield 'htm' => ['page.htm', 'html'];
        yield 'yml' => ['.github/workflows/ci.yml', 'yaml'];
        yield 'yaml' => ['config/services.yaml', 'yaml'];
        yield 'neon' => ['phpstan.neon', 'yaml'];
        yield 'neon dist' => ['phpstan.neon.dist', 'yaml'];
        yield 'hooks' => ['hooks/hooks.json', 'agent_config'];
        yield 'mcp' => ['.mcp.json', 'agent_config'];
        yield 'plugin' => ['.claude-plugin/plugin.json', 'agent_config'];
        yield 'claude settings' => ['.claude/settings.local.json', 'agent_config'];
        yield 'claude settings at root' => ['.claude/settings.json', 'agent_config'];
        yield 'knip' => ['knip.json', 'knip'];
        yield 'knip jsonc' => ['.knip.jsonc', 'knip'];
        yield 'pyproject' => ['pyproject.toml', 'python'];
        yield 'requirements' => ['requirements.txt', 'requirements'];
        yield 'requirements variant' => ['requirements-dev.txt', 'requirements'];
        yield 'not requirements' => ['requirements-dev.md', null];
        yield 'tsconfig' => ['tsconfig.json', 'typescript'];
        yield 'tsconfig variant' => ['tsconfig.build.json', 'typescript'];
        yield 'cargo' => ['crates/a/Cargo.toml', 'cargo'];
        yield 'tool config' => ['vite.config.ts', 'tool_config'];
        yield 'cypress' => ['cypress.json', 'tool_config'];
        yield 'plain source' => ['src/a.ts', null];
        yield 'other json' => ['data.json', null];
    }

    #[DataProvider('unitKinds')]
    public function testUnitKindForNamesEveryManifestKind(string $path, ?string $kind): void
    {
        assertSame($kind, SourceClassifier::unitKindFor($path));
        assertSame($kind, ProjectDiscoverer::unitKindFor($path));
    }

    /** Only package and build manifests make their directory a manifest root. */
    public function testIsManifestCoversOnlyPackageAndBuildManifests(): void
    {
        foreach (['composer.json', 'package.json', 'pyproject.toml', 'requirements.txt', 'tsconfig.json', 'Cargo.toml'] as $path) {
            self::assertTrue(SourceClassifier::isManifest($path), $path);
        }
        foreach (['knossos.json', 'index.html', 'ci.yml', 'Dockerfile', 'src/a.ts'] as $path) {
            self::assertFalse(SourceClassifier::isManifest($path), $path);
        }
        assertSame(SourceClassifier::MANIFEST_UNIT_KINDS, ProjectDiscoverer::MANIFEST_UNIT_KINDS);
        self::assertTrue(ProjectDiscoverer::isManifest('composer.json'));
    }

    /** The configuration exemption is by basename, case-insensitive, and nothing else. */
    public function testIsConfigurationFileMatchesOnlyTheKnossosConfig(): void
    {
        self::assertTrue(SourceClassifier::isConfigurationFile('knossos.json'));
        self::assertTrue(SourceClassifier::isConfigurationFile('sub/KNOSSOS.JSONC'));
        self::assertFalse(SourceClassifier::isConfigurationFile('knossos.json.bak'));
        self::assertTrue(ProjectDiscoverer::isConfigurationFile('knossos.json'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function extensions(): iterable
    {
        yield 'php' => ['a.php', 'php'];
        yield 'tsx' => ['a.TSX', 'typescript'];
        yield 'vue' => ['a.vue', 'typescript'];
        yield 'mjs' => ['a.mjs', 'javascript'];
        yield 'pyi' => ['a.pyi', 'python'];
        yield 'rs' => ['a.rs', 'rust'];
        yield 'unknown' => ['a.txt', null];
    }

    #[DataProvider('extensions')]
    public function testLanguageForReadsTheExtension(string $path, ?string $language): void
    {
        assertSame($language, SourceClassifier::languageFor($path));
        assertSame($language, ProjectDiscoverer::languageFor($path));
    }

    /** An extensionless file is classified by its shebang, and only when its path is given. */
    public function testLanguageForReadsAShebangOnlyForExtensionlessFiles(): void
    {
        $scripts = [
            'php' => "#!/usr/bin/env php8.3\n<?php\n",
            'node' => "#!/usr/bin/env node\n",
            'python' => "#!/usr/bin/python3\n",
            'shell' => "#!/bin/sh\n",
            'plain' => "no shebang\n",
            'phpstorm' => "#!/opt/phpstorm/bin/run\n",
        ];
        foreach ($scripts as $name => $contents) {
            file_put_contents($this->directory . '/' . $name, $contents);
        }
        file_put_contents($this->directory . '/script.txt', "#!/usr/bin/env php\n");

        assertSame('php', SourceClassifier::languageFor('php', $this->directory . '/php'));
        assertSame('javascript', SourceClassifier::languageFor('node', $this->directory . '/node'));
        assertSame('python', SourceClassifier::languageFor('python', $this->directory . '/python'));
        self::assertNull(SourceClassifier::languageFor('shell', $this->directory . '/shell'));
        self::assertNull(SourceClassifier::languageFor('plain', $this->directory . '/plain'));
        self::assertNull(SourceClassifier::languageFor('phpstorm', $this->directory . '/phpstorm'));
        self::assertNull(SourceClassifier::languageFor('php'));
        self::assertNull(SourceClassifier::languageFor('script.txt', $this->directory . '/script.txt'));
        self::assertNull(SourceClassifier::languageFor('missing', $this->directory . '/missing'));
    }

    /** The interpreter in a shebang is matched without regard to case. */
    public function testLanguageForReadsAShebangInAnyCase(): void
    {
        file_put_contents($this->directory . '/upper-php', "#!/usr/bin/env PHP\n");
        file_put_contents($this->directory . '/upper-node', "#!/usr/bin/env NODE\n");
        file_put_contents($this->directory . '/upper-python', "#!/usr/bin/env Python3\n");

        assertSame('php', SourceClassifier::languageFor('upper-php', $this->directory . '/upper-php'));
        assertSame('javascript', SourceClassifier::languageFor('upper-node', $this->directory . '/upper-node'));
        assertSame('python', SourceClassifier::languageFor('upper-python', $this->directory . '/upper-python'));
    }

    /** A name a stable id cannot carry: invalid UTF-8 or a control character. */
    public function testIsSupportedPathRejectsInvalidUtf8AndControlCharacters(): void
    {
        self::assertTrue(SourceClassifier::isSupportedPath('src/ünïcode.ts'));
        self::assertFalse(SourceClassifier::isSupportedPath("src/\xff.ts"));
        self::assertFalse(SourceClassifier::isSupportedPath("src/a\tb.ts"));
        self::assertFalse(SourceClassifier::isSupportedPath("src/a\x7fb.ts"));
        self::assertTrue(ProjectDiscoverer::isSupportedPath('src/a.ts'));
    }

    /** `tsc` output needs both a TypeScript sibling and the source-map trailer. */
    public function testIsCompiledSiblingNeedsTheSiblingAndTheSourceMapTrailer(): void
    {
        $mapped = $this->directory . '/mapped.js';
        $plain = $this->directory . '/plain.js';
        file_put_contents($mapped, "export {};\n//# sourceMappingURL=mapped.js.map\n");
        file_put_contents($plain, "export {};\n");
        $exists = static fn(array $paths): \Closure => static fn(string $path): bool => in_array($path, $paths, true);

        self::assertTrue(SourceClassifier::isCompiledSibling('src/mapped.js', $mapped, $exists(['src/mapped.ts'])));
        self::assertTrue(SourceClassifier::isCompiledSibling('src/mapped.js', $mapped, $exists(['src/mapped.tsx'])));
        self::assertTrue(SourceClassifier::isCompiledSibling('src/mapped.mjs', $mapped, $exists(['src/mapped.mts'])));
        self::assertTrue(SourceClassifier::isCompiledSibling('src/mapped.cjs', $mapped, $exists(['src/mapped.cts'])));
        self::assertFalse(SourceClassifier::isCompiledSibling('src/mapped.mjs', $mapped, $exists(['src/mapped.ts'])));
        self::assertFalse(SourceClassifier::isCompiledSibling('src/mapped.js', $mapped, $exists([])));
        self::assertFalse(SourceClassifier::isCompiledSibling('src/plain.js', $plain, $exists(['src/plain.ts'])));
        self::assertFalse(SourceClassifier::isCompiledSibling('src/mapped.ts', $mapped, $exists(['src/mapped.ts'])));
        self::assertFalse(SourceClassifier::isCompiledSibling('src/gone.js', $this->directory . '/gone.js', $exists(['src/gone.ts'])));
        self::assertTrue(ProjectDiscoverer::isCompiledSibling('src/mapped.js', $mapped, $exists(['src/mapped.ts'])));
    }

    /** Only the compiled sibling is dropped, and the remaining files keep their order. */
    public function testWithoutCompiledSiblingsDropsOnlyTscOutput(): void
    {
        $mapped = $this->directory . '/mapped.js';
        file_put_contents($mapped, "export {};\n//# sourceMappingURL=mapped.js.map\n");
        $file = static fn(string $relative, string $absolute): DiscoveredFile => new DiscoveredFile($relative, $absolute, 'javascript', 1, 0, 'h');
        $source = $file('src/mapped.ts', $this->directory . '/mapped.ts');
        $compiled = $file('src/mapped.js', $mapped);
        $alone = $file('lib/mapped.js', $mapped);

        assertSame([$source, $alone], SourceClassifier::withoutCompiledSiblings([$source, $compiled, $alone]));
    }
}
