<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\ManifestEntryPointRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('manifest-entry-point-rule')]
final class ManifestEntryPointRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('core.manifest.entrypoints.v1', (new ManifestEntryPointRule([]))->id());
    }

    /**
     * `npm run build` invokes `scripts/build.mjs` by name and nothing imports
     * it, so its in-degree is zero however central it is to the project. The
     * manifest is the reference, and the role is what carries it into the
     * graph.
     */
    public function testClassifyTagsAFileNamedByTheManifest(): void
    {
        $rule = new ManifestEntryPointRule(['scripts/build.mjs', 'bin/tool.js']);

        $facts = $rule->classify($this->makeNode('scripts/build.mjs'));

        assertSame(1, count($facts));
        assertSame('application.entry_point', $facts[0]->role);
        assertSame('core.manifest.entrypoints.v1', $facts[0]->ruleId);
        assertSame('scripts/build.mjs', $facts[0]->attributes['matched_path']);
    }

    public function testClassifyIgnoresAFileTheManifestDoesNotName(): void
    {
        $rule = new ManifestEntryPointRule(['scripts/build.mjs']);

        assertSame([], $rule->classify($this->makeNode('src/index.ts')));
    }

    /** A near-miss must not match: resolution is by exact path. */
    public function testClassifyDoesNotMatchOnASuffix(): void
    {
        $rule = new ManifestEntryPointRule(['scripts/build.mjs']);

        assertSame([], $rule->classify($this->makeNode('packages/web/scripts/build.mjs')));
    }

    public function testClassifyWithNoEntryPointsTagsNothing(): void
    {
        assertSame([], (new ManifestEntryPointRule([]))->classify($this->makeNode('scripts/build.mjs')));
    }

    /**
     * Declarations inside an entry-point file are reached exactly as the file
     * is, so the role is keyed on the file rather than the module node alone.
     */
    public function testClassifyTagsDeclarationsInsideAnEntryPointFile(): void
    {
        $node = new NodeFact(
            'js:function:scripts/build.mjs#main',
            'function',
            'main',
            'main',
            Origin::Ast,
            Confidence::Certain,
            new Evidence('scripts/build.mjs', 3, 8),
            [],
        );

        $facts = (new ManifestEntryPointRule(['scripts/build.mjs']))->classify($node);

        assertSame(1, count($facts));
        assertSame('js:function:scripts/build.mjs#main', $facts[0]->nodeReference);
    }

    private function makeNode(string $relativePath): NodeFact
    {
        return new NodeFact(
            'file:' . $relativePath,
            'module',
            $relativePath,
            basename($relativePath),
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 10),
            [],
        );
    }
}
