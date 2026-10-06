<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A module that exports nothing and calls something when it loads is run,
 * not imported: `node scripts/seed.js`, or a bundler's entry. Nothing can
 * import anything from it, so being unreferenced says nothing about whether
 * it is used, as with a shebang or a main guard.
 */
#[Group('typescript-scanner')]
final class TypescriptRunOnLoadScriptTest extends KnossosTestCase
{
    public function testAModuleThatExportsNothingAndCallsOnLoadIsExecutable(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-run-on-load',
                'files' => ['scripts/exported.js', 'scripts/import-rows.js', 'scripts/seed.js', 'src/main.ts', 'src/quiet.ts', 'src/start.ts'],
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

        // A module that exports is imported, whatever it calls on load; one
        // that calls nothing on load only declares.
        self::assertSame([
            'scripts/exported.js' => false,
            'scripts/import-rows.js' => true,
            'scripts/seed.js' => true,
            'src/main.ts' => true,
            'src/quiet.ts' => false,
            'src/start.ts' => false,
        ], $executable);
    }
}
