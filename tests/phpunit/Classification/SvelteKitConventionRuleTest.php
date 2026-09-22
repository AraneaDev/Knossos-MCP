<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\SvelteKitConventionRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * SvelteKit loads route modules, hooks, param matchers and the service worker
 * by where they sit, and nothing imports them. Each read as unreferenced code
 * while serving every request. The convention only holds inside an app that
 * has a `svelte.config.*`: elsewhere `src/hooks.ts` is an ordinary module.
 */
#[Group('classification')]
final class SvelteKitConventionRuleTest extends KnossosTestCase
{
    /** @return array<string, array{string, bool}> */
    public static function paths(): array
    {
        return [
            'page load' => ['apps/web/src/routes/[lang]/+page.ts', true],
            'server page' => ['apps/web/src/routes/+page.server.ts', true],
            'layout' => ['apps/web/src/routes/+layout.ts', true],
            'server layout' => ['apps/web/src/routes/(app)/+layout.server.js', true],
            'endpoint' => ['apps/web/src/routes/api/rates/+server.ts', true],
            'server hooks' => ['apps/web/src/hooks.server.ts', true],
            'universal hooks' => ['apps/web/src/hooks.ts', true],
            'client hooks' => ['apps/web/src/hooks.client.ts', true],
            'param matcher' => ['apps/web/src/params/lang.ts', true],
            'service worker' => ['apps/web/src/service-worker.ts', true],
            'ordinary lib module' => ['apps/web/src/lib/routes.ts', false],
            'a plus file outside routes' => ['apps/web/src/lib/+page.ts', false],
            'hooks outside any svelte app' => ['packages/ui/src/hooks.ts', false],
            'route file of another app' => ['apps/admin/src/routes/+page.ts', false],
        ];
    }

    #[DataProvider('paths')]
    public function testClassifiesFilesSvelteKitLoadsByPosition(string $path, bool $expected): void
    {
        $rule = new SvelteKitConventionRule(['apps/web']);
        $node = new NodeFact('ts:module:' . $path, 'module', $path, basename($path), Origin::Ast, Confidence::Certain, new Evidence($path, 1, 1));

        $facts = $rule->classify($node);

        self::assertSame($expected, $facts !== []);
        if ($expected) {
            self::assertSame('application.entry_point', $facts[0]->role);
        }
    }

    public function testAnAppAtTheProjectRootIsRecognised(): void
    {
        $rule = new SvelteKitConventionRule(['']);
        $node = new NodeFact('ts:module:src/hooks.server.ts', 'module', 'src/hooks.server.ts', 'hooks.server.ts', Origin::Ast, Confidence::Certain, new Evidence('src/hooks.server.ts', 1, 1));

        self::assertNotSame([], $rule->classify($node));
    }
}
