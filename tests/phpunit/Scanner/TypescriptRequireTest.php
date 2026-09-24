<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The compiler binds `require('./local')` to a module only in JavaScript, so
 * a TypeScript file loading one lazily imported nothing, and the module and
 * everything it declared read as unreferenced.
 */
#[Group('typescript-scanner')]
final class TypescriptRequireTest extends KnossosTestCase
{
    public function testARelativeRequireInTypeScriptImportsTheModule(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-require',
                'files' => ['src/local.ts', 'src/storage.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $imports = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'imports') {
                    $imports[] = $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
        }

        // A module the project does not hold is no import.
        self::assertSame(['ts:function:src/storage.ts#createBackend -> ts:module:src/local.ts'], $imports);
    }
}
