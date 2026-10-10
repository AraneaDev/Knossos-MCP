<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\EntryPointResolver;
use Knossos\Discovery\ProjectUnit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The cross-unit entry-point passes, asked with units built by hand. */
#[Group('discovery')]
final class EntryPointResolverTest extends TestCase
{
    /**
     * A declaration file in an `outDir` stands for the source it describes:
     * `.d.ts` for `.js` sources, `.d.mts` for `.mjs`, `.d.cts` for `.cjs`,
     * under the declared `rootDir` and at the same relative path.
     */
    public function testADeclarationInTheOutDirNamesItsSources(): void
    {
        $units = EntryPointResolver::resolve([
            new ProjectUnit('node', 'package.json', 'h', [
                'entry_points' => [],
                'public_entry_points' => ['dist/index.d.ts', 'dist/esm/index.d.mts', 'dist/cjs/main.d.cts'],
            ]),
            new ProjectUnit('typescript', 'tsconfig.json', 'h', ['out_dir' => 'dist', 'root_dir' => 'src']),
        ], []);

        assertSame([
            'dist/cjs/main.d.cts',
            'dist/esm/index.d.mts',
            'dist/index.d.ts',
            'src/cjs/main.cjs',
            'src/cjs/main.cts',
            'src/esm/index.mjs',
            'src/esm/index.mts',
            'src/index.js',
            'src/index.jsx',
            'src/index.ts',
            'src/index.tsx',
        ], $units[0]->metadata['public_entry_points']);
    }
}
