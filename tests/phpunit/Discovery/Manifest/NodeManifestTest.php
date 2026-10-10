<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\NodeManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** What a `package.json` publishes, read directly from the decoded manifest. */
#[Group('discovery')]
final class NodeManifestTest extends TestCase
{
    /** An `exports` that is a single string publishes that one module. */
    public function testAStringExportsIsPublished(): void
    {
        $metadata = (new NodeManifest())->read('pkg/package.json', '/absent/pkg/package.json', ['exports' => './src/index.ts']);

        assertSame(['pkg/src/index.ts'], $metadata['public_entry_points']);
    }

    /** Every string leaf of a conditional `exports` map is published, and nothing else in it. */
    public function testEveryStringInAnExportsMapIsPublished(): void
    {
        $metadata = (new NodeManifest())->read('pkg/package.json', '/absent/pkg/package.json', [
            'exports' => ['.' => ['import' => './src/a.ts', 'default' => './src/b.js'], './x' => null, './y' => false],
        ]);

        assertSame(['pkg/src/a.ts', 'pkg/src/b.js'], $metadata['public_entry_points']);
    }

    /** A private package publishes nothing, whatever it exports. */
    public function testAPrivatePackagePublishesNothing(): void
    {
        $metadata = (new NodeManifest())->read('package.json', '/absent/package.json', ['private' => true, 'exports' => './src/index.ts']);

        assertSame([], $metadata['public_entry_points']);
    }
}
