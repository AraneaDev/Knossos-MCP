<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\ToolConfigModuleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('tool-config-rule')]
final class ToolConfigModuleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('core.tooling.config.v1', (new ToolConfigModuleRule())->id());
    }

    /**
     * A tool loads its own config by filename; nothing in the project imports
     * it. Every one of these therefore has an in-degree of zero by
     * construction — a self-scan of a 111-file TypeScript project reported
     * eight unreferenced-code candidates and all eight were config or script
     * entry points of exactly this shape.
     */
    #[DataProvider('configPaths')]
    public function testClassifyTagsToolConfigModules(string $path): void
    {
        $facts = (new ToolConfigModuleRule())->classify($this->makeNode($path));

        assertSame(1, count($facts));
        assertSame('tooling.config', $facts[0]->role);
        assertSame('core.tooling.config.v1', $facts[0]->ruleId);
        assertSame($path, $facts[0]->attributes['matched_path']);
    }

    /** @return array<string, array{string}> */
    public static function configPaths(): array
    {
        return [
            'eslint flat config' => ['eslint.config.js'],
            'vitest config' => ['vitest.config.ts'],
            'stryker config' => ['stryker.config.mjs'],
            'vite config in a monorepo package' => ['packages/web/vite.config.ts'],
            'karma conf' => ['karma.conf.js'],
            'eslintrc dotfile' => ['.eslintrc.cjs'],
            'prettierrc dotfile' => ['.prettierrc.js'],
            'gulpfile' => ['gulpfile.js'],
            'capitalised gruntfile' => ['Gruntfile.js'],
            'pytest conftest' => ['tests/conftest.py'],
            // Extension and stem are both matched case-insensitively; a
            // case-sensitive comparison would silently miss these.
            'uppercase extension' => ['VITE.CONFIG.TS'],
            'mixed-case stem' => ['Vite.Config.ts'],
        ];
    }

    /**
     * The role only exists to explain a structural in-degree of zero, so it
     * must not swallow ordinary source. A module that merely reads config, or
     * one whose name happens to contain "config", is normal code.
     */
    #[DataProvider('sourcePaths')]
    public function testClassifyIgnoresOrdinarySource(string $path): void
    {
        assertSame([], (new ToolConfigModuleRule())->classify($this->makeNode($path)));
    }

    /** @return array<string, array{string}> */
    public static function sourcePaths(): array
    {
        return [
            'config loader' => ['src/utils/config-loader.ts'],
            'config module' => ['src/config.ts'],
            'name containing config' => ['src/reconfigure.ts'],
            'php class' => ['src/Configuration/ProjectConfigurationLoader.php'],
            'json config is not a module' => ['tsconfig.json'],
            'test file' => ['src/__tests__/config.test.ts'],
            'python module' => ['app/settings.py'],
            // A config-shaped stem on a data extension is still data: the role
            // exists to explain modules whose declarations need classifying.
            'config stem on a json file' => ['vite.config.json'],
            // The rc convention is a *dotfile* convention. A stem that merely
            // ends in "rc" is an ordinary name.
            'stem ending in rc without a leading dot' => ['src/marc.js'],
            // `.config` alone names no tool, so the suffix rule requires
            // something in front of it.
            'stem that is exactly the suffix' => ['.config.ts'],
            'no extension at all' => ['Makefile'],
            'bare dotfile' => ['.gitignore'],
        ];
    }

    /**
     * Declarations inside a config file are reached the same way the file is,
     * so the role is keyed on the file rather than on the module node alone.
     */
    public function testClassifyTagsDeclarationsInsideAConfigFile(): void
    {
        $node = new NodeFact(
            'ts:function:vitest.config.ts#resolveAliases',
            'function',
            'resolveAliases',
            'resolveAliases',
            Origin::Ast,
            Confidence::Certain,
            new Evidence('vitest.config.ts', 4, 9),
            [],
        );

        $facts = (new ToolConfigModuleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('ts:function:vitest.config.ts#resolveAliases', $facts[0]->nodeReference);
    }

    private function makeNode(string $relativePath): NodeFact
    {
        return new NodeFact(
            'file:' . $relativePath,
            'file',
            $relativePath,
            basename($relativePath),
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 10),
            [],
        );
    }
}
