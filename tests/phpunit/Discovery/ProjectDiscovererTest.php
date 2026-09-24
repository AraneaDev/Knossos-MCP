<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Discovery\ProjectUnit;
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
        // Resolved, because the link tests compare targets against the real
        // root: on macOS /tmp is itself a link to /private/tmp, and an absolute
        // target spelled through it would read as outside the root.
        $this->base = (string) realpath(sys_get_temp_dir()) . '/knossos-pd-test-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/project';
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->base);
    }

    // ── Directory-level diagnostics ──────────────────────────────────

    /** Vue, Svelte and Astro components are read by the TypeScript worker. */
    public function testComponentsAreScannedAsTypeScript(): void
    {
        foreach (['src/App.vue', 'src/lib/Counter.svelte', 'src/pages/index.astro', 'src/App.VUE'] as $path) {
            self::assertSame('typescript', ProjectDiscoverer::languageFor($path), $path);
        }
    }

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

        // An unreadable config file is caught during fingerprinting and reported
        // as DISCOVERY_FILE_UNREADABLE before it reaches config parsing, so that
        // is the diagnostic discovery actually emits for it.
        $codes = array_column($result->diagnostics, 'code');
        $this->assertContains('DISCOVERY_FILE_UNREADABLE', $codes);
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

    /**
     * A link that resolves to nothing cannot escape anything. It used to be
     * reported as DISCOVERY_SYMLINK_ESCAPE, which told the reader the project
     * pointed outside itself when it only had a dangling or looping link inside.
     */
    public function testDiscoverReportsABrokenSymlinkInsideTheRootAsBroken(): void
    {
        mkdir($this->root . '/src');
        symlink('missing.php', $this->root . '/src/relative.php');
        symlink($this->root . '/src/gone.php', $this->root . '/src/absolute.php');
        symlink('loop-b.php', $this->root . '/src/loop-a.php');
        symlink('loop-a.php', $this->root . '/src/loop-b.php');

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $byPath = [];
        foreach ($result->diagnostics as $diagnostic) {
            $byPath[(string) $diagnostic->relativePath] = $diagnostic->code;
        }
        foreach (['src/relative.php', 'src/absolute.php', 'src/loop-a.php', 'src/loop-b.php'] as $path) {
            assertSame('DISCOVERY_SYMLINK_BROKEN', $byPath[$path] ?? null, $path);
        }
        assertSame(false, in_array('DISCOVERY_SYMLINK_ESCAPE', array_values($byPath), true));
    }

    /** A dangling link whose target would lie outside the root still escapes, whether spelled relative or absolute. */
    public function testDiscoverReportsABrokenSymlinkOutsideTheRootAsAnEscape(): void
    {
        symlink('../outside/missing.php', $this->root . '/relative-out.php');
        symlink($this->base . '/missing-target.txt', $this->root . '/absolute-out.php');

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $byPath = [];
        foreach ($result->diagnostics as $diagnostic) {
            $byPath[(string) $diagnostic->relativePath] = $diagnostic->code;
        }
        ksort($byPath);
        assertSame(['absolute-out.php' => 'DISCOVERY_SYMLINK_ESCAPE', 'relative-out.php' => 'DISCOVERY_SYMLINK_ESCAPE'], $byPath);
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

    public function testDiscoverReadsAComposerLibrarysAutoloadRootsAsItsPublishedCode(): void
    {
        mkdir($this->root . '/sdk');
        file_put_contents($this->root . '/sdk/composer.json', json_encode([
            'name' => 'test/sdk',
            'type' => 'library',
            'autoload' => ['psr-4' => ['Sdk\\' => 'src/', 'Sdk\\Extra\\' => ['extra/']]],
            'autoload-dev' => ['psr-4' => ['Sdk\\Tests\\' => 'tests/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/app',
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $units = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root)->units;
        $roots = [];
        foreach ($units as $unit) {
            if ($unit->kind === 'composer') {
                $roots[$unit->configPath] = $unit->metadata['library_roots'];
            }
        }

        // An application's classes are its own; only a library publishes.
        // Composer's default type is library, but an application that never
        // named its type is the common case, so only an explicit one counts.
        $this->assertSame(['composer.json' => [], 'sdk/composer.json' => ['sdk/extra', 'sdk/src']], $roots);
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

    // ── Manifest entry points ────────────────────────────────────────

    /**
     * A package manifest is the only thing that references a bin or a script:
     * nothing imports `scripts/build.js`, npm invokes it by name. Recording
     * the paths it names is what lets classification tag them as entry points
     * instead of leaving them to read as unreferenced code.
     */
    public function testDiscoverReadsNodeEntryPointsFromBinAndScripts(): void
    {
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test/entrypoints',
            'bin' => ['tool' => './bin/tool.js'],
            'main' => 'src/index.js',
            'scripts' => [
                'build' => 'node scripts/build.mjs --watch',
                'lint' => 'eslint src/',
                'check' => 'npm run build && node scripts/check.js',
            ],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'node'));
        $this->assertNotEmpty($nodeUnits);
        assertSame(
            ['bin/tool.js', 'scripts/build.mjs', 'scripts/check.js', 'src/index.js'],
            $nodeUnits[0]->metadata['entry_points'],
        );
    }

    /** A nested manifest names paths relative to itself, not to the root. */
    public function testDiscoverAnchorsEntryPointsToTheManifestDirectory(): void
    {
        mkdir($this->root . '/packages/web', 0o777, true);
        file_put_contents($this->root . '/packages/web/package.json', json_encode([
            'name' => 'test/web',
            'scripts' => ['build' => 'node ./build.js'],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'node'));
        $this->assertNotEmpty($nodeUnits);
        assertSame(['packages/web/build.js'], $nodeUnits[0]->metadata['entry_points']);
    }

    /**
     * A manifest inside a dotted directory — `.github/actions/setup` is the
     * common one — must anchor to that directory, dot included. Trimming the
     * leading dot turned `.github/...` into `github/...`, which matches
     * nothing and silently loses the entry point.
     */
    public function testDiscoverKeepsALeadingDotInTheManifestDirectory(): void
    {
        mkdir($this->root . '/.github/actions/setup', 0o777, true);
        file_put_contents($this->root . '/.github/actions/setup/package.json', json_encode([
            'name' => 'test/action',
            'main' => 'index.js',
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'node'));
        $this->assertNotEmpty($nodeUnits);
        assertSame(['.github/actions/setup/index.js'], $nodeUnits[0]->metadata['entry_points']);
    }

    /**
     * A file git ignores is a cache, a local tool's state or generated output,
     * not the project's source, and each one scanned became a node that
     * dead-code analysis reported as unreferenced. Every `.gitignore` in the
     * tree applies below its own directory, and each is itself a unit, so an
     * edit to one changes what a rescan would walk.
     */
    public function testDiscoverSkipsWhatTheTreesGitignoreFilesIgnore(): void
    {
        $files = [
            '.gitignore' => "/var/\n*.gen.ts\n.agents/\n",
            'var/cache/Container.php' => "<?php\n",
            'src/a.ts' => "export const a = 1;\n",
            'src/b.gen.ts' => "export const b = 1;\n",
            '.agents/skills/helper.js' => "export const h = 1;\n",
            'web/.gitignore' => "styled-system\n",
            'web/styled-system/css.mjs' => "export const css = 1;\n",
            'web/app.ts' => "export const app = 1;\n",
            'storage/views/.gitignore' => "*\n!.gitignore\n",
            'storage/views/compiled.php' => "<?php\n",
        ];
        foreach ($files as $relative => $contents) {
            @mkdir(dirname($this->root . '/' . $relative), 0700, true);
            file_put_contents($this->root . '/' . $relative, $contents);
        }

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $paths = array_map(fn(DiscoveredFile $file): string => $file->relativePath, $result->files);
        sort($paths);
        assertSame(['src/a.ts', 'web/app.ts'], $paths);
        $gitignores = array_values(array_map(
            fn(ProjectUnit $unit): string => $unit->configPath,
            array_filter($result->units, fn(ProjectUnit $unit): bool => $unit->kind === 'gitignore'),
        ));
        assertSame(['.gitignore', 'storage/views/.gitignore', 'web/.gitignore'], $gitignores);
    }

    /**
     * A Claude Code plugin runs its hooks and MCP servers from commands in JSON
     * files, and knip lists a project's entry files under `entry`. Nothing
     * imports any of those files, so each carried no inbound edge while running
     * on every tool call. Knip's `ignore` names files that must not count.
     */
    public function testDiscoverReadsEntryPointsFromAgentPluginFilesAndKnip(): void
    {
        foreach (['hooks', '.claude-plugin', '.claude', 'packages/web'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }
        file_put_contents($this->root . '/hooks/hooks.json', json_encode(['hooks' => ['PreToolUse' => [['hooks' => [
            ['type' => 'command', 'command' => 'bun "$CLAUDE_PLUGIN_ROOT/src/hooks/pre-tool-use.ts"'],
        ]]]]], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/.mcp.json', '{"mcpServers":{"x":{"command":"node","args":["${CLAUDE_PLUGIN_ROOT}/dist/server.js","bin/serve.mjs"]}}}');
        file_put_contents($this->root . '/.claude/settings.json', '{"hooks":{"Stop":[{"hooks":[{"type":"command","command":"python3 tools/on_stop.py"}]}]}}');
        file_put_contents($this->root . '/knip.json', json_encode([
            'entry' => ['src/cli.ts', 'scripts/*.ts'],
            'ignore' => ['src/legacy.ts'],
            'workspaces' => ['packages/web' => ['entry' => 'main.tsx']],
        ], JSON_THROW_ON_ERROR));

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $entryPoints = [];
        foreach ($result->units as $unit) {
            foreach ($unit->metadata['entry_points'] ?? [] as $path) {
                $entryPoints[$path] = $unit->configPath;
            }
        }
        assertSame('hooks/hooks.json', $entryPoints['src/hooks/pre-tool-use.ts'] ?? null);
        assertSame('.mcp.json', $entryPoints['bin/serve.mjs'] ?? null);
        assertSame('.mcp.json', $entryPoints['dist/server.js'] ?? null);
        assertSame('.claude/settings.json', $entryPoints['tools/on_stop.py'] ?? null);
        assertSame('knip.json', $entryPoints['src/cli.ts'] ?? null);
        assertSame('knip.json', $entryPoints['packages/web/main.tsx'] ?? null);
        assertSame(false, isset($entryPoints['src/legacy.ts']));
    }

    /**
     * The TypeScript a package declares decides which compiler defaults its
     * sources are checked under, since 6.0 changed several of them.
     */
    public function testDiscoverRecordsTheTypescriptRangeEachManifestDeclares(): void
    {
        mkdir($this->root . '/web', 0700, true);
        mkdir($this->root . '/lib', 0700, true);
        file_put_contents($this->root . '/package.json', '{"devDependencies":{"typescript":"^5.9.3"}}');
        file_put_contents($this->root . '/web/package.json', '{"dependencies":{"typescript":"6.0.3"}}');
        file_put_contents($this->root . '/lib/package.json', '{"dependencies":{"react":"19"}}');

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $ranges = [];
        foreach ($result->units as $unit) {
            if ($unit->kind === 'node') {
                $ranges[$unit->configPath] = $unit->metadata['typescript_range'];
            }
        }
        assertSame(['lib/package.json' => null, 'package.json' => '^5.9.3', 'web/package.json' => '6.0.3'], $ranges);
    }

    /**
     * Whether a package depends on Vue decides whether its bundler resolves
     * `./Card` to `Card.vue`; the worker is told per manifest, never by what
     * a request happens to hold.
     */
    public function testDiscoverRecordsWhetherEachManifestDependsOnVue(): void
    {
        mkdir($this->root . '/web', 0700, true);
        mkdir($this->root . '/lib', 0700, true);
        file_put_contents($this->root . '/package.json', '{"devDependencies":{"typescript":"^5.9.3"}}');
        file_put_contents($this->root . '/web/package.json', '{"dependencies":{"vue":"^3.5.0"}}');
        file_put_contents($this->root . '/lib/package.json', '{"peerDependencies":{"vue":"^2.7.0"}}');

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $vue = [];
        foreach ($result->units as $unit) {
            if ($unit->kind === 'node') {
                $vue[$unit->configPath] = $unit->metadata['vue'];
            }
        }
        ksort($vue);
        assertSame(['lib/package.json' => true, 'package.json' => false, 'web/package.json' => true], $vue);
    }

    /**
     * An agent config is read for the paths it names even when it is not
     * valid JSON (cut off mid-edit): its raw text still names them.
     */
    public function testAnAgentConfigThatDoesNotParseStillNamesItsScripts(): void
    {
        mkdir($this->root . '/.claude', 0700, true);
        file_put_contents($this->root . '/.claude/settings.json', '{"hooks":{"Stop":[{"hooks":[{"type":"command","command":"python3 tools/on_stop.py"}');

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $entryPoints = [];
        foreach ($result->units as $unit) {
            foreach ($unit->metadata['entry_points'] ?? [] as $path) {
                $entryPoints[] = $path;
            }
        }
        assertArrayContains('tools/on_stop.py', $entryPoints);
    }

    /**
     * An `outDir` that is the config's own directory, or that leaves it, names
     * no separate build output, so nothing is mapped back to a source.
     */
    public function testAnOutDirThatIsNoSeparateDirectoryMapsNothing(): void
    {
        foreach (['inplace' => '.', 'absolute' => '/tmp/out', 'outside' => '../out'] as $package => $outDir) {
            mkdir($this->root . '/' . $package, 0700, true);
            file_put_contents($this->root . '/' . $package . '/package.json', '{"main":"out/index.js"}');
            file_put_contents($this->root . '/' . $package . '/tsconfig.json', json_encode(['compilerOptions' => ['outDir' => $outDir]], JSON_THROW_ON_ERROR));
        }

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        foreach ($result->units as $unit) {
            if ($unit->kind === 'node') {
                assertSame([dirname($unit->configPath) . '/out/index.js'], $unit->metadata['entry_points'], $unit->configPath);
            }
        }
    }

    /**
     * A package's `main`, `bin` and scripts name what runs, which for a
     * compiled package is the build output: `dist/index.js`. Discovery skips
     * `dist/`, so the name matched nothing and the source it is compiled from,
     * `src/index.ts`, was reported as reachable only from its tests. The
     * tsconfig's `outDir` and `rootDir` say which source each output comes from.
     */
    public function testDiscoverMapsEntryPointsInTheBuildOutputBackToTheirSources(): void
    {
        mkdir($this->root . '/packages/cli', 0700, true);
        file_put_contents($this->root . '/package.json', json_encode([
            'main' => 'dist/index.js',
            'bin' => ['tool' => './dist/bin/tool.mjs'],
            'scripts' => ['start' => 'node dist/server.js', 'lint' => 'eslint lib/other.js'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/tsconfig.json', json_encode([
            'compilerOptions' => ['outDir' => './dist/', 'rootDir' => 'src'],
        ], JSON_THROW_ON_ERROR));
        // No rootDir: the compiler infers one, so both usual answers are offered.
        file_put_contents($this->root . '/packages/cli/package.json', '{"main":"out/main.js"}');
        file_put_contents($this->root . '/packages/cli/tsconfig.json', '{"compilerOptions":{"outDir":"out"}}');

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $entryPoints = [];
        foreach ($result->units as $unit) {
            if ($unit->kind === 'node') {
                $entryPoints[$unit->configPath] = $unit->metadata['entry_points'];
            }
        }
        foreach (['src/index.ts', 'src/index.tsx', 'src/bin/tool.mts', 'src/server.ts', 'dist/index.js'] as $path) {
            assertArrayContains($path, $entryPoints['package.json']);
        }
        // Only output under outDir is mapped.
        assertSame(false, in_array('src/other.ts', $entryPoints['package.json'], true));
        assertArrayContains('packages/cli/src/main.ts', $entryPoints['packages/cli/package.json']);
        assertArrayContains('packages/cli/main.ts', $entryPoints['packages/cli/package.json']);
    }

    /**
     * An Azure Functions handler is named by its binding manifest and imported
     * by nothing, so `scriptFile` is the only record of what actually runs.
     *
     * The case that made this necessary had `index.js` and a stale `index.ts`
     * side by side. TypeScript's module resolution answers a sibling's
     * `require('../management')` with the `.ts`, so the graph credited the
     * fossil with the dependency and reported the live 19 kB handler as dead
     * code. The manifest settles it on the host's authority.
     */
    public function testDiscoverReadsAnAzureFunctionsHandlerFromItsBindingManifest(): void
    {
        mkdir($this->root . '/api/management', 0700, true);
        file_put_contents($this->root . '/api/management/function.json', json_encode([
            'scriptFile' => 'index.js',
            'bindings' => [],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'azure_function'));
        $this->assertNotEmpty($units);
        assertSame(['api/management/index.js'], $units[0]->metadata['entry_points']);
    }

    /**
     * `scriptFile` is optional and usually omitted — 45 of the 69 manifests in
     * the project that prompted this leave it out — and the host then loads the
     * conventional handler from the manifest's own directory.
     *
     * Both conventional names are offered because matching downstream is by
     * exact project-relative path, so the one the directory does not hold
     * matches nothing.
     */
    public function testDiscoverFallsBackToTheConventionalHandlerWhenNoScriptFileIsNamed(): void
    {
        mkdir($this->root . '/api/me', 0700, true);
        file_put_contents($this->root . '/api/me/function.json', json_encode([
            'bindings' => [['type' => 'httpTrigger', 'direction' => 'in', 'name' => 'req']],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'azure_function'));
        $this->assertNotEmpty($units);
        assertSame(['api/me/index.js', 'api/me/__init__.py'], $units[0]->metadata['entry_points']);
    }

    /**
     * The basename is generic enough that another tool could own it, so a
     * `function.json` with neither a `scriptFile` nor bindings contributes
     * nothing rather than guessing at a handler.
     */
    public function testDiscoverIgnoresAFunctionManifestThatIsNotAnAzureOne(): void
    {
        file_put_contents($this->root . '/function.json', json_encode([
            'name' => 'something else entirely',
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'azure_function'));
        $this->assertNotEmpty($units);
        assertSame([], $units[0]->metadata['entry_points']);
    }

    /**
     * An explicit `scriptFile` wins over the convention, which is the whole
     * point of the key: a directory holding both `index.js` and a stale
     * `index.ts` needs the manifest to settle which one the host runs.
     */
    public function testDiscoverPrefersAnExplicitScriptFileOverTheConvention(): void
    {
        mkdir($this->root . '/api/legacy', 0700, true);
        file_put_contents($this->root . '/api/legacy/function.json', json_encode([
            'scriptFile' => 'handler.js',
            'bindings' => [['type' => 'httpTrigger', 'direction' => 'in', 'name' => 'req']],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'azure_function'));
        $this->assertNotEmpty($units);
        assertSame(['api/legacy/handler.js'], $units[0]->metadata['entry_points']);
    }

    /**
     * `"bin": "./cli.js"` — the single-binary shorthand npm documents first,
     * and a different shape from the `{name: path}` map above.
     */
    public function testDiscoverReadsANodeBinDeclaredAsAPlainString(): void
    {
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test/single-bin',
            'bin' => './cli.js',
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'node'));
        $this->assertNotEmpty($nodeUnits);
        assertSame(['cli.js'], $nodeUnits[0]->metadata['entry_points']);
    }

    /**
     * Composer scripts are commonly a list of commands rather than one string
     * — this repository's own `composer.json` declares `lint` that way, and
     * every path in it would be missed if only strings were read.
     */
    public function testDiscoverReadsEveryLineOfAMultiCommandScript(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/multi-command',
            'scripts' => [
                'lint' => ['php -l tools/first.php', 'php -l tools/second.php'],
            ],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $composerUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'composer'));
        $this->assertNotEmpty($composerUnits);
        assertSame(['tools/first.php', 'tools/second.php'], $composerUnits[0]->metadata['entry_points']);
    }

    /** A manifest with no scripts at all yields the declared paths and nothing else. */
    public function testDiscoverHandlesAManifestWithoutScripts(): void
    {
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test/no-scripts',
            'main' => 'src/index.js',
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'node'));
        $this->assertNotEmpty($nodeUnits);
        assertSame(['src/index.js'], $nodeUnits[0]->metadata['entry_points']);
    }

    public function testDiscoverReadsComposerEntryPointsFromBinAndScripts(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/php-entrypoints',
            'bin' => ['bin/console'],
            'scripts' => [
                'lint' => 'php tools/lint.php',
                'test' => '@php vendor/bin/phpunit',
            ],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $composerUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'composer'));
        $this->assertNotEmpty($composerUnits);
        // `bin/console` has no extension and `vendor/bin/phpunit` is a
        // dependency's binary; only the project's own source file is recorded.
        assertSame(['tools/lint.php'], $composerUnits[0]->metadata['entry_points']);
    }

    /**
     * A script is a shell command, not a path list. Flags, bare words, and
     * quoted globs must not be mistaken for files.
     */
    public function testDiscoverIgnoresNonPathTokensInScripts(): void
    {
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test/noise',
            'scripts' => [
                'noisy' => "npm --prefix workers/typescript run check && find src -name '*.js' -print0",
                'inline' => 'node -e "require(\'./build/index.js\')"',
            ],
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'node'));
        $this->assertNotEmpty($nodeUnits);
        // `*.js` is a glob, not a file. The inline `require` argument is a real
        // path shape and is kept — it names build output no scan will emit, so
        // it simply matches nothing.
        assertSame(['build/index.js'], $nodeUnits[0]->metadata['entry_points']);
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

    // ── Python / pyproject.toml ──────────────────────────────────────

    private function pythonUnit(string $toml): ProjectUnit
    {
        file_put_contents($this->root . '/pyproject.toml', $toml);
        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);
        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'python'));
        $this->assertNotEmpty($units);

        return $units[0];
    }

    public function testDiscoverReadsPythonDependenciesAndOptionalGroups(): void
    {
        $unit = $this->pythonUnit(<<<'TOML'
[project]
name = "demo"
dependencies = [
    "Django>=4.2",
    "fastapi[all]",
    "requests @ https://example.com/requests.whl",
]

[project.optional-dependencies]
test = ["pytest>=8"]
docs = ["sphinx"]
TOML
        );
        $requires = $unit->metadata['requires'];
        assertSame(['django', 'fastapi', 'pytest', 'requests', 'sphinx'], array_keys($requires));
    }

    public function testDiscoverReadsPythonScriptsAsEntryPoints(): void
    {
        $unit = $this->pythonUnit(<<<'TOML'
[project]
name = "demo"

[project.scripts]
cli = "demo.cli:main"
worker = "demo.jobs.worker:run"
TOML
);
        assertSame(['demo/cli.py', 'demo/jobs/worker.py'], $unit->metadata['entry_points']);
    }

    public function testDiscoverReadsABuildablePythonPackageWithoutScriptsAsALibrary(): void
    {
        $library = $this->pythonUnit(<<<'TOML'
[build-system]
requires = ["hatchling"]

[project]
name = "sdk"
TOML);
        assertSame([''], $library->metadata['library_roots']);

        // A package that installs a command is an application.
        $application = $this->pythonUnit(<<<'TOML'
[build-system]
requires = ["hatchling"]

[project]
name = "server"

[project.scripts]
server = "server.main:run"
TOML);
        assertSame([], $application->metadata['library_roots']);
        // Nor is a Poetry project that says it is no package.
        assertSame([], $this->pythonUnit("[build-system]\nrequires = [\"poetry-core\"]\n\n[tool.poetry]\nname = \"service\"\npackage-mode = false\n")->metadata['library_roots']);
        // Nor is a project with nothing to build.
        assertSame([], $this->pythonUnit("[project]\nname = \"app\"\n")->metadata['library_roots']);
    }

    public function testDiscoverReadsPoetryMetadataAsPythonFallback(): void
    {
        $unit = $this->pythonUnit(<<<'TOML'
[tool.poetry]
name = "poetry-demo"

[tool.poetry.dependencies]
python = "^3.11"
fastapi = "^0.110"

[tool.poetry.scripts]
cli = "poetry_demo.main:cli"
TOML);

        assertSame('poetry-demo', $unit->metadata['name']);
        assertSame(['poetry_demo/main.py'], $unit->metadata['entry_points']);
        // Poetry states dependencies as a table, not a PEP 621 list. Missing
        // them here left ScanPlanner unable to see fastapi and the worker's
        // framework enrichment gated off for every Poetry project. `python` is
        // the interpreter constraint, not a package.
        assertSame(['fastapi'], array_keys($unit->metadata['requires']));
    }

    public function testDiscoverReadsPoetryGroupAndSubTableDependencies(): void
    {
        $unit = $this->pythonUnit(<<<'TOML'
[tool.poetry]
name = "poetry-groups"

[tool.poetry.dev-dependencies]
pytest = "^8.0"

[tool.poetry.group.docs.dependencies]
sphinx = "^7.0"

[tool.poetry.dependencies.flask]
version = "^3.0"
extras = ["async"]
TOML);

        // The sub-table form names its package in the header; its body holds
        // only that package's settings, so `version` and `extras` are not
        // dependencies.
        assertSame(['flask', 'pytest', 'sphinx'], array_keys($unit->metadata['requires']));
    }

    public function testDiscoverReadsTopLevelScriptModuleAsEntryPoint(): void
    {
        $unit = $this->pythonUnit(<<<'TOML'
[project]
name = "demo"

[project.scripts]
cli = "app:main"
TOML);

        // A single-segment module is a real file. Dropping it left app.py with
        // an in-degree of zero, reading as unreferenced code.
        assertSame(['app.py'], $unit->metadata['entry_points']);
    }

    public function testDiscoverReadsPipRequirementsAsUnit(): void
    {
        file_put_contents($this->root . '/requirements.txt', <<<'TXT'
# comment
fastapi==0.136.1
uvicorn[standard]==0.46.0
-e ./local-pkg
psutil>=7.2 ; python_version >= "3.11"
TXT);
        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);
        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'requirements'));
        $this->assertNotEmpty($units);
        assertSame(['fastapi', 'psutil', 'uvicorn'], array_keys($units[0]->metadata['requires']));
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

    // ── Rust / Cargo ────────────────────────────────────────────────

    public function testDiscoverClassifiesRustSourcesAndCargoManifests(): void
    {
        mkdir($this->root . '/src', 0700, true);
        file_put_contents($this->root . '/Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        file_put_contents($this->root . '/src/lib.rs', "pub fn go() {}\n");
        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $rust = array_values(array_filter(
            $result->files,
            static fn(DiscoveredFile $file): bool => $file->relativePath === 'src/lib.rs',
        ));
        assertSame(1, count($rust));
        assertSame('rust', $rust[0]->language);

        $cargo = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'cargo'));
        assertSame(1, count($cargo));
        assertSame('Cargo.toml', $cargo[0]->configPath);
    }

    private function cargoUnitName(string $toml): ?string
    {
        file_put_contents($this->root . '/Cargo.toml', $toml);
        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);
        $cargo = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'cargo'));
        $this->assertNotEmpty($cargo);

        return $cargo[0]->metadata['name'];
    }

    public function testDiscoverReadsCargoPackageName(): void
    {
        assertSame('demo', $this->cargoUnitName("[package]\nname = \"demo\"\nversion = \"0.1.0\"\n"));
    }

    public function testDiscoverAcceptsCargoPackageNameWithCRLF(): void
    {
        assertSame('demo', $this->cargoUnitName("[package]\r\nname = \"demo\"\r\nversion = \"0.1.0\"\r\n"));
    }

    /**
     * `[[bin]]` naming a binary before `[package]` must not be mistaken for
     * the crate name — a bare first-match regex over the whole file picks
     * this up as `mytool` instead of the crate's own `demo`. This is the
     * assertion that catches an unscoped regex.
     */
    public function testDiscoverIgnoresABinNameDeclaredBeforePackage(): void
    {
        assertSame('demo', $this->cargoUnitName(
            "[[bin]]\nname = \"mytool\"\npath = \"src/main.rs\"\n\n[package]\nname = \"demo\"\nversion = \"0.1.0\"\n",
        ));
    }

    /** A dependency table's own `name` key, appearing before `[package]`, must not leak in either. */
    public function testDiscoverIgnoresADependencyNameDeclaredBeforePackage(): void
    {
        assertSame('demo', $this->cargoUnitName(
            "[dependencies.foo]\nname = \"foo-real\"\nversion = \"1\"\n\n[package]\nname = \"demo\"\n",
        ));
    }

    /**
     * A virtual workspace manifest has no `[package]` table. A `[[test]]`
     * target's `name` must not be read as the (nonexistent) crate name.
     */
    public function testDiscoverReturnsNullNameForTestTargetInVirtualWorkspace(): void
    {
        $this->assertNull($this->cargoUnitName(
            "[workspace]\nmembers = [\"a\"]\n\n[[test]]\nname = \"integration\"\npath = \"tests/it.rs\"\n",
        ));
    }

    public function testDiscoverReturnsNullNameForWorkspaceOnlyManifestWithoutAnyName(): void
    {
        $this->assertNull($this->cargoUnitName("[workspace]\nmembers = [\"a\", \"b\"]\n"));
    }

    private function cargoUnit(string $toml): ProjectUnit
    {
        file_put_contents($this->root . '/Cargo.toml', $toml);
        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);
        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'cargo'));
        $this->assertNotEmpty($units);

        return $units[0];
    }

    public function testDiscoverReadsCargoDependencies(): void
    {
        $unit = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"

[dependencies]
serde = "1"
axum = { version = "0.7", features = ["macros"] }

[dev-dependencies]
tokio = "1"

[build-dependencies]
cc = "1"

[target.'cfg(unix)'.dependencies]
libc = "0.2"
TOML);
        assertSame(['axum', 'cc', 'libc', 'serde', 'tokio'], array_keys($unit->metadata['requires']));
    }

    /**
     * A renamed dependency states the real crate in a `package` key and uses
     * the table key only as a local alias. Recording the alias alone hid the
     * crate from ScanPlanner's framework detection, which matches on the real
     * name, so worker enrichment was gated off for a crate that genuinely
     * depended on the framework.
     */
    public function testDiscoverReadsRenamedCargoDependencies(): void
    {
        $unit = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"
edition = "2021"

[dependencies]
web = { package = "actix-web", version = "4" }
plain = "1"

[dependencies.rt]
package = "tokio"
version = "1"
TOML);
        // Both names survive: the alias is what a `use` writes, the package is
        // what framework detection matches.
        assertSame(['actix-web', 'plain', 'rt', 'tokio', 'web'], array_keys($unit->metadata['requires']));
    }

    public function testDiscoverReadsCargoDependencySubTables(): void
    {
        $unit = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"

[dependencies.axum]
version = "0.7"
features = ["macros"]

[target.'cfg(unix)'.dev-dependencies.rocket]
version = "0.5"
TOML);
        // A crate that takes a sub-table is declared by its header, so `axum`
        // and `rocket` are dependencies while `version` and `features` are
        // that crate's own settings.
        assertSame(['axum', 'rocket'], array_keys($unit->metadata['requires']));
    }

    public function testDiscoverReadsCargoBinPathsAsEntryPoints(): void
    {
        $unit = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"

[[bin]]
name = "mytool"
path = "tools/mytool.rs"

[[bin]]
name = "other"
TOML);
        // No `edition` key means the 2015 edition, where declaring a target by
        // hand turns auto-discovery off. src/main.rs is therefore not a target
        // of this manifest and must not be reported.
        assertSame(['src/bin/other.rs', 'tools/mytool.rs'], $unit->metadata['entry_points']);
    }

    /**
     * The 2015 rule is per target kind. Cargo's prose says auto-discovery is
     * off once "at least one target" is declared by hand, but `cargo metadata`
     * on 1.94.1 still reports `src/main.rs` as a binary for a 2015 manifest
     * declaring `[lib]` or `[[test]]`; only a `[[bin]]` stops it. Reading the
     * prose literally would drop a real entry point, so this pins the
     * behaviour cargo actually has.
     */
    public function testDiscoverKeepsImplicitMainWhenOnlyNonBinTargetsAreDeclared(): void
    {
        $withLib = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"

[lib]
name = "demo"
path = "src/lib.rs"
TOML);
        assertSame(['src/main.rs'], $withLib->metadata['entry_points']);

        $withTest = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"

[[test]]
name = "it"
path = "tests/it.rs"
TOML);
        assertSame(['src/main.rs'], $withTest->metadata['entry_points']);
    }

    /** From the 2018 edition on, auto-discovery runs alongside `[[bin]]`. */
    public function testDiscoverAddsImplicitMainAlongsideBinFrom2018Onwards(): void
    {
        $unit = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"
edition = "2021"

[[bin]]
name = "mytool"
path = "tools/mytool.rs"
TOML);
        assertSame(['src/main.rs', 'tools/mytool.rs'], $unit->metadata['entry_points']);
    }

    public function testDiscoverHonoursAutobinsFalse(): void
    {
        $unit = $this->cargoUnit(<<<'TOML'
[package]
name = "demo"
version = "0.1.0"
edition = "2021"
autobins = false

[[bin]]
name = "mytool"
path = "tools/mytool.rs"
TOML);
        // The edition would leave discovery on, so `autobins = false` is the
        // only thing that can suppress src/main.rs here.
        assertSame(['tools/mytool.rs'], $unit->metadata['entry_points']);
    }

    /**
     * Cargo reads binaries under src/bin/ off the file system rather than the
     * manifest, so discovery has to look there too. Both `src/bin/x.rs` and
     * `src/bin/x/main.rs` are binary targets.
     */
    public function testDiscoverFindsBinariesUnderSrcBin(): void
    {
        mkdir($this->root . '/src/bin/nested', 0o700, true);
        file_put_contents($this->root . '/src/bin/tool.rs', "fn main() {}\n");
        file_put_contents($this->root . '/src/bin/nested/main.rs', "fn main() {}\n");
        file_put_contents($this->root . '/src/lib.rs', "pub fn x() {}\n");

        $unit = $this->cargoUnit("[package]\nname = \"demo\"\nversion = \"0.1.0\"\nedition = \"2021\"\n");

        assertSame(
            ['src/bin/nested/main.rs', 'src/bin/tool.rs', 'src/main.rs'],
            $unit->metadata['entry_points'],
        );
    }

    /** `autobins = false` stops the src/bin scan as well as the inferred main. */
    public function testDiscoverSkipsSrcBinScanWhenAutobinsIsFalse(): void
    {
        mkdir($this->root . '/src/bin', 0o700, true);
        file_put_contents($this->root . '/src/bin/tool.rs', "fn main() {}\n");

        $unit = $this->cargoUnit("[package]\nname = \"demo\"\nversion = \"0.1.0\"\nautobins = false\n");

        assertSame([], $unit->metadata['entry_points']);
    }

    /**
     * ManifestEntryPointRule matches on the exact project-relative path a
     * scanner emitted. A nested manifest that reports `src/main.rs` instead of
     * `services/api/src/main.rs` therefore matches nothing, and the entry
     * point is lost without a diagnostic. composer.json and package.json have
     * always anchored to their own directory; Cargo and pyproject did not.
     */
    public function testDiscoverAnchorsNestedManifestEntryPointsToTheirDirectory(): void
    {
        mkdir($this->root . '/services/api', 0o700, true);
        file_put_contents($this->root . '/services/api/Cargo.toml', "[package]\nname = \"api\"\nversion = \"0.1.0\"\n");
        file_put_contents($this->root . '/services/api/pyproject.toml', <<<'TOML'
[project]
name = "api"

[project.scripts]
cli = "app:main"
worker = "api.jobs.worker:run"
TOML);

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);
        $byKind = [];
        foreach ($result->units as $unit) {
            $byKind[$unit->kind] = $unit->metadata['entry_points'] ?? null;
        }

        assertSame(['services/api/src/main.rs'], $byKind['cargo']);
        assertSame(['services/api/api/jobs/worker.py', 'services/api/app.py'], $byKind['python']);
    }

    public function testDiscoverInfersImplicitCargoBinaryPath(): void
    {
        $unit = $this->cargoUnit("[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        assertSame(['src/main.rs'], $unit->metadata['entry_points']);
    }

    public function testDiscoverLeavesVirtualWorkspaceWithoutEntryPoints(): void
    {
        $unit = $this->cargoUnit("[workspace]\nmembers = [\"a\", \"b\"]\n");
        assertSame([], $unit->metadata['entry_points']);
    }

    /**
     * `ENTRY_POINT_EXTENSIONS` is what lets a manifest-declared path be
     * recognised as a real source file. A `.rs` path named by a `bin` field
     * was silently dropped before `rs` was added to that list — extension
     * filtering happens before the value is ever compared against emitted
     * nodes, so the loss was invisible downstream.
     */
    public function testDiscoverRecognisesARustPathNamedByAManifestBinField(): void
    {
        file_put_contents($this->root . '/package.json', json_encode([
            'name' => 'test/rust-bin',
            'bin' => 'src/main.rs',
        ], JSON_THROW_ON_ERROR));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $nodeUnits = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'node'));
        $this->assertNotEmpty($nodeUnits);
        assertSame(['src/main.rs'], $nodeUnits[0]->metadata['entry_points']);
    }

    /**
     * A browser enters a single-page application through the `<script>` tag in
     * its HTML shell, and nothing in the project imports that module, so its
     * in-degree is zero however live it is.
     */
    public function testDiscoverReadsScriptSourcesFromAnHtmlShell(): void
    {
        mkdir($this->root . '/frontend', 0700, true);
        file_put_contents($this->root . '/frontend/index.html', implode("\n", [
            '<!doctype html>',
            '<html><head>',
            '  <link rel="icon" href="/vite.svg" />',
            '  <script src="/config.js"></script>',
            '</head><body>',
            '  <script type="module" src="/src/main.tsx"></script>',
            '</body></html>',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'html'));
        $this->assertNotEmpty($units);
        // `/config.js` is root-absolute against the WEB root, which a bundler
        // serves out of `public/` or `static/`. Every reading is offered; the
        // ones naming no emitted file match nothing downstream.
        assertSame([
            'frontend/config.js',
            'frontend/public/config.js',
            'frontend/static/config.js',
            'frontend/src/main.tsx',
            'frontend/public/src/main.tsx',
            'frontend/static/src/main.tsx',
        ], $units[0]->metadata['entry_points']);
    }

    /**
     * The icon and stylesheet links in the same shell are not code, so nothing
     * about them should reach the entry-point list — the extension guard in
     * {@see ProjectDiscoverer::entryPointPath()} is what keeps them out, and a
     * reader that scraped every `href` would defeat it.
     */
    public function testDiscoverIgnoresNonScriptReferencesInHtml(): void
    {
        file_put_contents($this->root . '/index.html', implode("\n", [
            '<link rel="stylesheet" href="/theme.css" />',
            '<img src="/logo.png" />',
            '<a href="/docs/guide.js">not a script</a>',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'html'));
        $this->assertNotEmpty($units);
        assertSame([], $units[0]->metadata['entry_points']);
    }

    /**
     * Single quotes and an unquoted attribute are both legal HTML, and a shell
     * written by hand uses whichever. A reader that only understood double
     * quotes would silently drop the entry point.
     */
    public function testDiscoverReadsScriptSourcesRegardlessOfAttributeQuoting(): void
    {
        file_put_contents($this->root . '/index.html', implode("\n", [
            "<script src='./a.js'></script>",
            '<script src=b.js></script>',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'html'));
        $this->assertNotEmpty($units);
        // Relative sources are anchored to the file's own directory and get no
        // web-root readings: they are already project-relative.
        assertSame(['a.js', 'b.js'], $units[0]->metadata['entry_points']);
    }

    /**
     * A Compose file mounts a source file into a container by path, and a CI
     * workflow runs one by name. Neither is an import, so the file looks
     * orphaned while being the only reason the stack starts.
     *
     * The mount is one scalar holding a host path, a container path and a
     * flag, colon-separated. Only the host side can name a file in this
     * project; the container path resolves to nothing and falls away.
     */
    public function testDiscoverReadsPathLikeStringsFromYaml(): void
    {
        mkdir($this->root . '/docker/local', 0700, true);
        file_put_contents($this->root . '/docker/local/docker-compose.yml', implode("\n", [
            'services:',
            '  frontend:',
            '    volumes:',
            '      - ./frontend/vite.config.docker.ts:/app/vite.config.ts:ro',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'yaml'));
        $this->assertNotEmpty($units);
        assertSame([
            'docker/local/frontend/vite.config.docker.ts',
            'frontend/vite.config.docker.ts',
            'docker/local/app/vite.config.ts',
            'app/vite.config.ts',
        ], $units[0]->metadata['entry_points']);
    }

    /**
     * The tokenising is loose on purpose, so the guard that keeps it safe is
     * the extension list: a YAML file is mostly keys, image names and version
     * strings, and none of them may reach the entry-point list.
     */
    public function testDiscoverIgnoresYamlScalarsThatAreNotSourcePaths(): void
    {
        file_put_contents($this->root . '/ci.yml', implode("\n", [
            'jobs:',
            '  build:',
            '    runs-on: ubuntu-24.04',
            '    steps:',
            '      - uses: actions/checkout@v5',
            '      - run: npm ci && npm test',
            '      - image: node:22.1.0',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'yaml'));
        $this->assertNotEmpty($units);
        assertSame([], $units[0]->metadata['entry_points']);
    }

    /**
     * A path climbing out of the project cannot name one of its files, and
     * `entryPointPath()` already refuses it. Pinned here because a YAML file
     * is the most likely place to find one.
     *
     * The container half of the bind mount (`/app/loader.js`) happens to
     * survive the same guard, since nothing here can tell a container path
     * from a project one from the text alone. That is not this test's point
     * and is not asserted as desired behaviour — only that the host half,
     * which unambiguously climbs out of the project, is rejected.
     */
    public function testDiscoverIgnoresYamlPathsThatClimbOutOfTheProject(): void
    {
        file_put_contents($this->root . '/mounts.yaml', "volumes:\n  - ../../secrets/loader.js:/app/loader.js\n");

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'yaml'));
        $this->assertNotEmpty($units);
        assertSame(false, in_array('../../secrets/loader.js', $units[0]->metadata['entry_points'], true));
    }

    /**
     * A `#` comment names a source path just as effectively as an executed
     * line does — the tokeniser has no notion of YAML syntax, only of the
     * text — so a path mentioned only in an explanatory comment must not
     * suppress the finding this analysis exists to produce.
     */
    public function testDiscoverIgnoresYamlPathsNamedOnlyInAComment(): void
    {
        file_put_contents($this->root . '/notes.yaml', implode("\n", [
            '# see src/legacy/notes.php for context',
            'jobs:',
            '  build:',
            '    steps:',
            '      - run: echo hello',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'yaml'));
        $this->assertNotEmpty($units);
        assertSame([], $units[0]->metadata['entry_points']);
    }

    /**
     * `codecov.yml`'s `ignore:` block names paths to leave OUT of coverage,
     * not files the project loads. Blanket tokenising, which is right for a
     * genuine reference like a Compose bind mount, would suppress the exact
     * candidate the exclusion list identifies as unused.
     */
    public function testDiscoverIgnoresYamlPathsInAnExclusionBlockSequence(): void
    {
        file_put_contents($this->root . '/codecov.yml', implode("\n", [
            'ignore:',
            '  - src/legacy/old.php',
            'coverage:',
            '  status:',
            '    project: yes',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'yaml'));
        $this->assertNotEmpty($units);
        assertSame([], $units[0]->metadata['entry_points']);
    }

    /**
     * The exclusion block and the wanted path sit several lines apart, with
     * blank lines between them, so resolving the wanted token's line number
     * has to walk past more than one line boundary. Pinned to catch an
     * off-by-one in the binary search that turns a byte offset into a line
     * number: shifted by one, it would either pull the wanted token under the
     * exclusion block or misplace the exclusion block itself.
     */
    public function testDiscoverKeepsAYamlPathSeveralLinesAfterAnExclusionBlock(): void
    {
        file_put_contents($this->root . '/pipeline.yml', implode("\n", [
            'ignore:',
            '  - src/legacy/excluded.php',
            '',
            '',
            '',
            'jobs:',
            '  build:',
            '    steps:',
            '      - run: node tools/wanted.mjs',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'yaml'));
        $this->assertNotEmpty($units);
        assertSame(['tools/wanted.mjs'], $units[0]->metadata['entry_points']);
    }

    /**
     * A workflow `run:` step executes from the repository root, not from the
     * directory holding the workflow. Anchoring only to the file's own
     * directory resolved `node tools/coverage-badge.mjs` to
     * `.github/workflows/tools/coverage-badge.mjs`, which names nothing, so the
     * CI half of this reader did not work for any workflow below the root.
     */
    public function testDiscoverAnchorsAYamlPathToTheProjectRootAsWellAsItsOwnDirectory(): void
    {
        mkdir($this->root . '/.github/workflows', 0700, true);
        file_put_contents($this->root . '/.github/workflows/quality.yml', implode("\n", [
            'jobs:',
            '  badge:',
            '    steps:',
            '      - run: node tools/coverage-badge.mjs',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'yaml'));
        $this->assertNotEmpty($units);
        assertSame([
            '.github/workflows/tools/coverage-badge.mjs',
            'tools/coverage-badge.mjs',
        ], $units[0]->metadata['entry_points']);
    }

    /**
     * Vitest loads its setup file before every test and nothing imports it, so
     * it read as dead code while running on every single test invocation.
     *
     * The value may be a bare string or an array of them; YCI writes the array
     * form, and a reader that only understood one would miss the other.
     */
    public function testDiscoverReadsFilesATestRunnerConfigLoads(): void
    {
        mkdir($this->root . '/frontend', 0700, true);
        file_put_contents($this->root . '/frontend/vite.config.ts', implode("\n", [
            "import { defineConfig } from 'vite'",
            "import react from '@vitejs/plugin-react'",
            'export default defineConfig({',
            '  test: {',
            "    setupFiles: ['./src/test/setup.ts'],",
            "    globalSetup: './src/test/global.ts',",
            '  },',
            '})',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'tool_config'));
        $this->assertNotEmpty($units);
        assertSame([
            'frontend/src/test/setup.ts',
            'frontend/src/test/global.ts',
        ], $units[0]->metadata['entry_points']);
    }

    /**
     * Bundler and desktop-shell configs name the modules they build from under
     * `entrypoint` or `entryPoints`. The bundled main process is loaded by the
     * shell and imported by nothing, so it read as unreferenced.
     */
    public function testDiscoverReadsTheEntrypointsABundlerConfigNames(): void
    {
        file_put_contents($this->root . '/electrobun.config.ts', implode("\n", [
            'export default {',
            "  build: { bun: { entrypoint: 'electrobun/index.ts' }, views: { main: { entrypoint: 'src/Main.tsx' } } },",
            '};',
            '',
        ]));
        file_put_contents($this->root . '/esbuild.config.mjs', "export default { entryPoints: ['src/worker.ts'] };\n");

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $entryPoints = [];
        foreach ($result->units as $unit) {
            if ($unit->kind === 'tool_config') {
                $entryPoints = [...$entryPoints, ...$unit->metadata['entry_points']];
            }
        }
        sort($entryPoints);
        assertSame(['electrobun/index.ts', 'src/Main.tsx', 'src/worker.ts'], $entryPoints);
    }

    /**
     * Symfony and Doctrine wire classes up by name in YAML: a Doctrine filter,
     * a service, an event listener. The class is instantiated by the container
     * and referenced by nothing in PHP, so it read as dead. Composer's PSR-4
     * map turns the class name into the file that declares it.
     */
    public function testDiscoverReadsClassNamesFromYamlThroughThePsr4Map(): void
    {
        mkdir($this->root . '/config/packages', 0700, true);
        file_put_contents($this->root . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'src/', 'Acme\\Lib\\' => ['lib/']]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/config/packages/doctrine.yaml', implode("\n", [
            'doctrine:',
            '    orm:',
            '        filters:',
            '            end_of_sale:',
            '                class: App\\AppBundle\\Doctrine\\EndOfSaleFilter',
            'services:',
            "    'Acme\\Lib\\Mailer': ~",
            '    Vendor\\Unmapped\\Thing: ~',
            '',
        ]));

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $yaml = array_values(array_filter($result->units, fn(ProjectUnit $u): bool => $u->configPath === 'config/packages/doctrine.yaml'))[0];
        assertArrayContains('src/AppBundle/Doctrine/EndOfSaleFilter.php', $yaml->metadata['entry_points']);
        assertArrayContains('lib/Mailer.php', $yaml->metadata['entry_points']);
        assertSame([], array_values(array_filter($yaml->metadata['entry_points'], fn(string $p): bool => str_contains($p, 'Unmapped'))));
    }

    /**
     * A server started by the container (`CMD ["node", "server/index.mjs"]`)
     * or by the test runner (`webServer: { command: 'node server/index.mjs' }`)
     * is imported by nothing, and both files name it only in a command line.
     */
    public function testDiscoverReadsEntryPointsFromDockerfilesAndCommandLines(): void
    {
        mkdir($this->root . '/docker', 0700, true);
        file_put_contents($this->root . '/Dockerfile', "FROM node:22\nCOPY . .\nCMD [\"node\", \"server/index.mjs\"]\n");
        file_put_contents($this->root . '/docker/worker.Dockerfile', "FROM python:3.12\nENTRYPOINT python3 jobs/run.py --once\n");
        file_put_contents($this->root . '/playwright.config.ts', "export default { webServer: { command: 'node server/preview.mjs --port 4173' } };\n");

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $entryPoints = [];
        foreach ($result->units as $unit) {
            foreach ($unit->metadata['entry_points'] ?? [] as $path) {
                $entryPoints[$path] = $unit->configPath;
            }
        }
        assertSame('Dockerfile', $entryPoints['server/index.mjs'] ?? null);
        assertSame('docker/worker.Dockerfile', $entryPoints['jobs/run.py'] ?? null);
        assertSame('playwright.config.ts', $entryPoints['server/preview.mjs'] ?? null);
    }

    /**
     * `scripts/manage.sh` runs `cd server && npx tsx src/scripts/reset.ts`:
     * the script is started by the shell script and imported by nothing, and
     * its path is relative to the directory the line changed into.
     */
    public function testDiscoverReadsEntryPointsFromShellScripts(): void
    {
        mkdir($this->root . '/scripts', 0700, true);
        file_put_contents($this->root . '/scripts/manage.sh', implode("\n", [
            '#!/usr/bin/env bash',
            '# node old/unused.js is only mentioned in a comment',
            'case "$1" in',
            '  reset) cd server && npx tsx src/scripts/reset.ts "$@" ;;',
            '  seed) node tools/seed.mjs ;;',
            // Another branch's `cd` does not reach this one.
            '  clean) node src/cleanup.ts ;;',
            '  build)',
            '    cd web',
            '    node src/build.mjs',
            '    ;;',
            'esac',
            'node src/after.mjs',
            '{',
            '    cd api',
            '    node src/inner.mjs',
            '}',
            'node src/outer.mjs',
            '',
        ]));

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'shell'));
        assertSame(1, count($units));
        $entryPoints = $units[0]->metadata['entry_points'];
        self::assertContains('server/src/scripts/reset.ts', $entryPoints);
        self::assertContains('tools/seed.mjs', $entryPoints);
        self::assertNotContains('old/unused.js', $entryPoints);
        self::assertContains('src/cleanup.ts', $entryPoints);
        self::assertNotContains('server/src/cleanup.ts', $entryPoints);
        self::assertContains('web/src/build.mjs', $entryPoints);
        self::assertNotContains('web/src/after.mjs', $entryPoints);
        // A brace block ends a `cd` inside it.
        self::assertContains('api/src/inner.mjs', $entryPoints);
        self::assertNotContains('api/src/outer.mjs', $entryPoints);
    }

    /**
     * A published build output stands for the source compiled to it: the
     * tsconfig's `rootDir` when it declares one, and `src/` only when no
     * tsconfig lays the build out.
     */
    public function testAPublishedBuildOutputStandsForItsDeclaredSourceOnly(): void
    {
        mkdir($this->root . '/declared', 0700, true);
        mkdir($this->root . '/undeclared', 0700, true);
        file_put_contents($this->root . '/declared/package.json', '{"name":"declared","main":"dist/index.js"}');
        file_put_contents($this->root . '/declared/tsconfig.json', '{"compilerOptions":{"outDir":"dist","rootDir":"lib"}}');
        file_put_contents($this->root . '/undeclared/package.json', '{"name":"undeclared","module":"dist/esm.mjs"}');

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $published = [];
        foreach ($result->units as $unit) {
            if ($unit->kind === 'node') {
                $published[$unit->configPath] = $unit->metadata['public_entry_points'] ?? [];
            }
        }
        self::assertContains('declared/lib/index.ts', $published['declared/package.json']);
        self::assertNotContains('declared/src/index.ts', $published['declared/package.json']);
        self::assertContains('undeclared/src/esm.ts', $published['undeclared/package.json']);
    }

    /**
     * PHPStan loads the rules and extensions `phpstan.neon` names, by class;
     * nothing in PHP does. A rule class the config does not name is not
     * loaded, so it stays reportable.
     */
    public function testDiscoverReadsTheClassesAPhpstanConfigRegisters(): void
    {
        mkdir($this->root . '/app/Support/PHPStan', 0700, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->root . '/phpstan.neon', implode("\n", [
            'parameters:',
            '    excludePaths:',
            '        - app/Legacy/Old.php',
            '    excludePaths:',
            '        analyse:',
            '            - app/Legacy/Older.php',
            'rules:',
            '    - App\\Support\\PHPStan\\NoRawHttpRule',
            'services:',
            '    -',
            '        class: App\\Support\\PHPStan\\ReturnTypeExtension',
            '',
        ]));
        foreach (['NoRawHttpRule', 'ReturnTypeExtension', 'UnregisteredRule'] as $class) {
            file_put_contents($this->root . '/app/Support/PHPStan/' . $class . '.php', "<?php\nnamespace App\\Support\\PHPStan;\nfinal class {$class} {}\n");
        }

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $entryPoints = [];
        foreach ($result->units as $unit) {
            $entryPoints = [...$entryPoints, ...($unit->metadata['entry_points'] ?? [])];
        }
        self::assertContains('app/Support/PHPStan/NoRawHttpRule.php', $entryPoints);
        self::assertContains('app/Support/PHPStan/ReturnTypeExtension.php', $entryPoints);
        self::assertNotContains('app/Support/PHPStan/UnregisteredRule.php', $entryPoints);
        // A path PHPStan is told to skip is no path it loads.
        self::assertNotContains('app/Legacy/Old.php', $entryPoints);
        self::assertNotContains('app/Legacy/Older.php', $entryPoints);
    }

    /**
     * Doctrine loads every migration in the directories `migrations_paths`
     * names, and nothing imports one. A migration class outside them is not
     * loaded, so it stays reportable.
     */
    public function testDiscoverReadsDoctrineMigrationDirectoriesAsEntryPoints(): void
    {
        mkdir($this->root . '/config/packages', 0700, true);
        mkdir($this->root . '/src/Migrations/Archive', 0700, true);
        mkdir($this->root . '/src/Legacy', 0700, true);
        file_put_contents($this->root . '/config/packages/doctrine_migrations.yaml', implode("\n", [
            'doctrine_migrations:',
            '    migrations_paths: # where the migrations live',
            "        'DoctrineMigrations': '%kernel.project_dir%/src/Migrations' # the only path",
            '',
        ]));
        foreach (['src/Migrations/Version1.php', 'src/Migrations/Archive/Version0.php', 'src/Legacy/Version9.php'] as $file) {
            file_put_contents($this->root . '/' . $file, "<?php\nfinal class V {}\n");
        }

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);

        $entryPoints = [];
        foreach ($result->units as $unit) {
            $entryPoints = [...$entryPoints, ...($unit->metadata['entry_points'] ?? [])];
        }
        self::assertContains('src/Migrations/Version1.php', $entryPoints);
        self::assertContains('src/Migrations/Archive/Version0.php', $entryPoints);
        self::assertNotContains('src/Legacy/Version9.php', $entryPoints);
    }

    /**
     * The reason this reader is key-scoped rather than tokenising the whole
     * file the way the YAML one does. A config names files to EXCLUDE as well
     * as files to load, and an excluded path is exactly the kind of file that
     * turns out to be dead. Marking it an entry point would hide the finding.
     */
    public function testDiscoverIgnoresPathsAConfigExcludesRatherThanLoads(): void
    {
        file_put_contents($this->root . '/vitest.config.ts', implode("\n", [
            'export default {',
            "  test: {",
            "    setupFiles: ['./setup.ts'],",
            "    exclude: ['src/legacy/old.ts'],",
            "    coverage: { exclude: ['src/generated/client.ts'] },",
            '  },',
            '}',
            '',
        ]));

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        $units = array_values(array_filter($result->units, fn($u): bool => $u->kind === 'tool_config'));
        $this->assertNotEmpty($units);
        assertSame(['setup.ts'], $units[0]->metadata['entry_points']);
    }

    /**
     * `config.ts` on its own is ordinary application source, not a tool's
     * config, and reading its string literals as entry points would suppress
     * dead code across the codebase. Discovery and classification share one
     * predicate so the two can never disagree about which is which.
     */
    public function testDiscoverDoesNotTreatOrdinarySourceAsAToolConfig(): void
    {
        mkdir($this->root . '/src', 0700, true);
        file_put_contents($this->root . '/src/config.ts', "export const paths = ['./src/legacy/old.ts'];\n");

        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$this->root]));
        $result = $discoverer->discover($this->root);

        assertSame([], array_values(array_filter($result->units, fn($u): bool => $u->kind === 'tool_config')));
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
