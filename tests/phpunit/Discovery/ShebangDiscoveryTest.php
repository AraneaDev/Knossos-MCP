<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Extensionless executables are source too.
 *
 * Found by running Knossos over its own tree: `architecture_health` reported
 * `WorkerServer::run` as a probable dead-code candidate. It is called from
 * `workers/php/bin/worker`, but that file has no extension, so discovery never
 * classified it and the only edge to `run()` was invisible. The same shape hits
 * real projects — Laravel's `artisan` and Symfony's `bin/console` are both
 * extensionless PHP — so every entry point they invoke looked deletable.
 */
final class ShebangDiscoveryTest extends KnossosTestCase
{
    #[Group('discovery')]
    public function testExtensionlessScriptsAreClassifiedByTheirShebang(): void
    {
        $root = $this->tree([
            'artisan' => "#!/usr/bin/php8.3\n<?php\nclass Artisan {}\n",
            'bin/console' => "#!/usr/bin/env php\n<?php\nclass Console {}\n",
            'bin/cli' => "#!/usr/bin/env node\nconsole.log(1);\n",
            'bin/tool' => "#!/usr/bin/env python3\nprint(1)\n",
        ]);

        assertSame(
            ['artisan' => 'php', 'bin/cli' => 'javascript', 'bin/console' => 'php', 'bin/tool' => 'python'],
            $this->languages($root),
        );
    }

    #[Group('discovery')]
    public function testNonSourceExtensionlessFilesAreStillSkipped(): void
    {
        $root = $this->tree([
            'LICENSE' => "MIT License\n\nCopyright\n",
            'Dockerfile' => "FROM alpine\n",
            'bin/deploy' => "#!/bin/sh\necho hi\n",
            // A shebang whose interpreter path merely *contains* an interpreter
            // name must not be read as that language, or every JetBrains wrapper
            // script in a tree becomes a PHP file.
            'bin/decoy' => "#!/opt/phpstorm/bin/launcher\nnot php at all\n",
        ]);

        assertSame([], $this->languages($root));
    }

    /**
     * An unrecognised interpreter is a CLEAN skip, not a swallowed error.
     *
     * `languageFor` runs inside the walk's `catch (Throwable)`, which exists so
     * a file that vanishes mid-walk degrades to a warning instead of failing
     * the scan. That net also catches programming errors: dropping the
     * shebang match's `default => null` arm makes `#!/bin/sh` raise
     * `UnhandledMatchError`, which the catch turns into a
     * `DISCOVERY_FILE_UNREADABLE` warning and the walk carries on. The
     * language map is then identical — the file is skipped either way — so
     * asserting only on languages cannot tell a deliberate skip from a bug
     * being absorbed.
     *
     * Asserting that the walk is diagnostic-FREE is what separates them, and it
     * generalises: any future error raised while classifying an ordinary file
     * shows up here rather than dissolving into a warning nobody reads.
     */
    #[Group('discovery')]
    public function testAnUnrecognisedInterpreterSkipsCleanlyWithoutADiagnostic(): void
    {
        $root = $this->tree([
            'bin/deploy' => "#!/bin/sh\necho hi\n",
            'bin/ruby-tool' => "#!/usr/bin/env ruby\nputs 1\n",
            'LICENSE' => "MIT License\n",
        ]);

        $result = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);

        assertSame([], $this->languages($root));
        assertSame(
            [],
            array_map(static fn($diagnostic): string => $diagnostic->code, $result->diagnostics),
            'Skipping an unrecognised interpreter must not report the file as unreadable.',
        );
    }

    /**
     * A first line that merely mentions a language is not a shebang.
     *
     * The `#!` guard is what stops the interpreter patterns from being applied
     * to arbitrary text. Removing it leaves every extensionless file in a tree
     * — LICENSE, NOTICE, a CODEOWNERS file — matched against `\bpython\b` and
     * friends, so prose that happens to name a language is scanned as source.
     * The existing fixtures all either start with `#!` or contain no language
     * name at all, so the guard could be deleted with the suite still green.
     */
    #[Group('discovery')]
    public function testAFirstLineMentioningALanguageWithoutAShebangIsNotSource(): void
    {
        $root = $this->tree([
            'NOTICE' => "python and php bindings are documented elsewhere\n",
            'CODEOWNERS' => "* @team-node\n",
        ]);

        assertSame([], $this->languages($root));
    }

    #[Group('discovery')]
    public function testAnExtensionStillWinsOverAShebang(): void
    {
        // The extension is the cheaper and more reliable signal, so it is consulted
        // first; the shebang is a fallback, not an override.
        $root = $this->tree(['script.py' => "#!/usr/bin/env php\nprint(1)\n"]);

        assertSame(['script.py' => 'python'], $this->languages($root));
    }

    /**
     * Relative path to detected language for every discovered file.
     *
     * @return array<string, string>
     */
    private function languages(string $root): array
    {
        $result = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);
        $languages = [];
        foreach ($result->files as $file) {
            $languages[$file->relativePath] = $file->language;
        }
        ksort($languages);

        return $languages;
    }

    /**
     * Write a fixture tree and return its root.
     *
     * @param array<string, string> $files relative path to contents
     */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir() . '/knossos-shebang-' . uniqid('', true);
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

    /** @var list<string> */
    private array $roots = [];

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
        if (!str_starts_with($root, sys_get_temp_dir() . '/knossos-shebang-') || !is_dir($root)) {
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
