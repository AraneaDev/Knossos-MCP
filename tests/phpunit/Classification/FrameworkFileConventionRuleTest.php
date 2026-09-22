<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\FrameworkFileConventionRule;
use Knossos\Query\ReportableComponent;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Astro serves `src/pages/**` endpoints and runs `src/middleware`, and
 * VitePress runs `*.data.ts` loaders and `[param].paths.ts` route generators,
 * all found by where they sit. Nothing imports them, so each read as
 * unreferenced. As with SvelteKit, the positions only count inside an app
 * whose config file is there.
 */
#[Group('classification')]
final class FrameworkFileConventionRuleTest extends KnossosTestCase
{
    /** @return array<string, array{string, bool}> */
    public static function astroPaths(): array
    {
        return [
            'endpoint' => ['site/src/pages/og/default.png.ts', true],
            'dynamic endpoint' => ['site/src/pages/sitemaps/[chunk].xml.ts', true],
            'middleware' => ['site/src/middleware.ts', true],
            'content config' => ['site/src/content.config.ts', true],
            'legacy content config' => ['site/src/content/config.ts', true],
            'library module' => ['site/src/lib/chart.ts', false],
            'pages of another app' => ['other/src/pages/index.ts', false],
        ];
    }

    #[DataProvider('astroPaths')]
    public function testAstroPositions(string $path, bool $expected): void
    {
        self::assertSame($expected, $this->classified(FrameworkFileConventionRule::astro(['site']), $path));
    }

    /** @return array<string, array{string, bool}> */
    public static function vitePressPaths(): array
    {
        return [
            'data loader in the theme' => ['.vitepress/theme/projects.data.ts', true],
            'data loader in content' => ['content/blog/posts.data.mts', true],
            'dynamic route paths' => ['content/en/skills/[skill].paths.ts', true],
            'theme composable' => ['.vitepress/theme/composables/useTheme.ts', false],
        ];
    }

    #[DataProvider('vitePressPaths')]
    public function testVitePressPositions(string $path, bool $expected): void
    {
        self::assertSame($expected, $this->classified(FrameworkFileConventionRule::vitePress(['']), $path));
    }

    public function testTheRoleIsAnEntryPointConvention(): void
    {
        $facts = FrameworkFileConventionRule::astro([''])->classify($this->node('src/middleware.ts'));

        self::assertSame('application.entry_point', $facts[0]->role);
        self::assertSame('astro.conventions.v1', $facts[0]->ruleId);
        self::assertTrue(ReportableComponent::isDiscoveredByConvention([$facts[0]->role]));
    }

    private function classified(FrameworkFileConventionRule $rule, string $path): bool
    {
        return $rule->classify($this->node($path)) !== [];
    }

    private function node(string $path): NodeFact
    {
        return new NodeFact('ts:module:' . $path, 'module', $path, basename($path), Origin::Ast, Confidence::Certain, new Evidence($path, 1, 1));
    }
}
