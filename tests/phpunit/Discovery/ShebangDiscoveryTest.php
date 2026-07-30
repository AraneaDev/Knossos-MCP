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
