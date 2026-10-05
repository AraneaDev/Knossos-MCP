<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A class method that fulfils a member of a type the class extends or
 * implements is marked `overrides`: the source says so with `override`, or the
 * checker finds the member on a heritage type or, for an object literal, on
 * the type of the parameter it is handed to. A dependency's base class or a
 * built-in interface is not in the graph, so without the mark the dispatch
 * through it reads as no reference at all.
 */
#[Group('typescript-scanner')]
final class TypescriptSupertypeMemberTest extends KnossosTestCase
{
    public function testMethodsFulfillingAHeritageTypesMemberAreMarked(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/typescript-supertypes',
                'files' => ['src/walker.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }

        $overrides = [];
        foreach ($contributions[0]->nodes as $node) {
            if ($node->kind === 'method') {
                $overrides[$node->canonicalName] = $node->attributes['overrides'] ?? false;
            }
        }
        ksort($overrides);

        self::assertSame([
            'src/walker.ts#Factory::enter' => false,
            // The contract's own member is not a fulfilment of it.
            'src/walker.ts#Hooks::resolve' => false,
            'src/walker.ts#Walker::enter' => true,
            'src/walker.ts#Walker::helper' => false,
            'src/walker.ts#Walker::leave' => true,
            'src/walker.ts#Walker::next' => true,
            // A literal handed to a parameter its type names.
            'src/walker.ts#{object}@36:10::resolve' => true,
        ], $overrides);
    }
}
