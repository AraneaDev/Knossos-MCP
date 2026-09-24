<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A JavaScript file with no import, export or `require` is a classic script:
 * a page loads it with a `<script>` tag and nothing can import anything from
 * it. Like a shell script, it is entered from outside the graph, so being
 * unreferenced says nothing about whether it is used.
 */
#[Group('typescript-scanner')]
final class TypescriptClassicScriptTest extends KnossosTestCase
{
    public function testAJavaScriptFileWithoutModuleSyntaxIsExecutable(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-classic-script',
                'files' => ['src/check.ts', 'src/helper.js', 'src/skin.js', 'src/umd.js'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $executable = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'module') {
                    $executable[$node->canonicalName] = $node->attributes['executable'] ?? null;
                }
            }
        }
        ksort($executable);

        // A module, CommonJS or UMD file exports something another file can
        // import; a TypeScript file without imports is a script only in name.
        self::assertSame([
            'src/check.ts' => false,
            'src/helper.js' => false,
            'src/skin.js' => true,
            'src/umd.js' => false,
        ], $executable);
    }
}
