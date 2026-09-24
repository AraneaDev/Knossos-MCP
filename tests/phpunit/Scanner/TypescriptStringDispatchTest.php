<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A Vuex store's actions, mutations and getters are reached by name:
 * `dispatch('cookie/setInternal')`, `commit('SET_INTERNAL')`,
 * `mapActions({ tickets: 'cookie/setAllTickets' })`. No call site names the
 * member itself, so each read as probably dead. The names count as calls on a
 * receiver nothing types, which is what they are.
 */
#[Group('typescript-scanner')]
final class TypescriptStringDispatchTest extends KnossosTestCase
{
    public function testNamesAStoreIsAskedForCountAsUntypedCalls(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/ts-string-dispatch',
                'files' => ['src/component.js', 'src/store/cookie.js'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }
        $untyped = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'module') {
                    $untyped[$node->canonicalName] = $node->attributes['unresolved_member_calls'] ?? [];
                }
            }
        }

        self::assertSame(['internal', 'setAllTickets', 'setInternal'], array_values(array_intersect($untyped['src/component.js'] ?? [], ['internal', 'setAllTickets', 'setInternal', 'tickets'])));
        self::assertContains('SET_INTERNAL', $untyped['src/store/cookie.js'] ?? []);
        self::assertContains('refresh', $untyped['src/store/cookie.js'] ?? []);
        // A name nothing asks for stays unmentioned.
        self::assertNotContains('unused', [...($untyped['src/component.js'] ?? []), ...($untyped['src/store/cookie.js'] ?? [])]);
    }
}
