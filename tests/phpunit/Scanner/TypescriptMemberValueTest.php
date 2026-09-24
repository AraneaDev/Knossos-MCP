<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `{ tick: board.tick }` hands an interface's method on as a value: the
 * wrapper's callers reach it through the field. The read named a method
 * signature, which counted as no declaration, so the member read as unused.
 */
#[Group('typescript-scanner')]
final class TypescriptMemberValueTest extends KnossosTestCase
{
    public function testAnInterfaceMethodReadAsAValueIsReferenced(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-member-values',
                'files' => ['src/board.ts', 'src/wrap.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $uses = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if (in_array($edge->kind, ['references', 'calls'], true) && str_contains($edge->targetReference, '#PlayerBoard::')) {
                    $uses[] = $edge->kind . ' ' . $edge->targetReference;
                }
            }
        }
        sort($uses);

        // Read as a value, called, and neither: `idle` stays unreferenced.
        self::assertSame([
            'calls ts:method:src/board.ts#PlayerBoard::size',
            'references ts:method:src/board.ts#PlayerBoard::tick',
        ], $uses);
    }
}
