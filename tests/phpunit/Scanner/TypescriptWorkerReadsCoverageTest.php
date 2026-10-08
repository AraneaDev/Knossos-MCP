<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the TypeScript worker reads and attributes, through the real worker
 * process, for the rules a request's exclusions carry beyond directory names
 * (segment pairs, file-name suffixes, negated patterns), for a component an
 * import without an extension reaches in a Vue project, and for the files a
 * relative `require` loads from outside the program, directly and through a
 * link.
 */
#[Group('typescript-scanner')]
final class TypescriptWorkerReadsCoverageTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-cover-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testExclusionsLeaveOutSegmentPairsSuffixesAndWhatAPatternIgnores(): void
    {
        $this->write('src/a.ts', implode("\n", [
            "import { cached } from '../docs/.vitepress/cache/dep';",
            "import { bundled } from '../bundle.min.js';",
            "import { x } from './x.gen';",
            "import { keep } from './keep.gen';",
            'export const all = [cached, bundled, x, keep];',
            '',
        ]));
        $this->write('docs/.vitepress/cache/dep.ts', "export const cached = 1;\n");
        $this->write('bundle.min.js', "export const bundled = 1;\n");
        $this->write('src/x.gen.ts', "export const x = 1;\n");
        $this->write('src/keep.gen.ts', "export const keep = 1;\n");
        $exclusions = [
            'segments' => ['node_modules'],
            'prefixes' => [],
            'sequences' => [['.vitepress', 'cache']],
            'suffixes' => ['.min.js'],
            'path_prefixes' => [],
            'patterns' => [
                ['regex' => '[^/]*\.gen\.ts', 'anchored' => false, 'negated' => false],
                ['regex' => 'keep\.gen\.ts', 'anchored' => false, 'negated' => true],
            ],
        ];

        [$result] = $this->scan(['src/a.ts'], ['exclusions' => $exclusions]);

        $read = array_keys($result['input_hashes']);
        self::assertContains('src/keep.gen.ts', $read);
        self::assertNotContains('src/x.gen.ts', $read);
        self::assertNotContains('bundle.min.js', $read);
        assertSame([], array_values(array_filter($read, static fn(string $path): bool => str_starts_with($path, 'docs/'))));
    }

    public function testAVueImportWithoutAnExtensionCarriesBothAttemptsMisses(): void
    {
        $card = "<template><p>card</p></template>\n<script setup lang=\"ts\"></script>\n";
        $this->write('src/main.ts', "import Card from './Card';\nimport Gone from './Gone';\nexport default [Card, Gone];\n");
        $this->write('src/Card.vue', $card);

        [, $contributions] = $this->scan(['src/main.ts'], ['vue_projects' => ['']]);

        $reads = $contributions['src/main.ts']->reads ?? [];
        assertSame(hash('sha256', $card), $reads['src/Card.vue'] ?? 'missing');
        // The first attempt's misses stay with the importer, and so do both
        // attempts' for an import neither finds.
        self::assertArrayHasKey('src/Card.ts', $reads);
        self::assertArrayHasKey('src/Gone.ts', $reads);
        self::assertArrayHasKey('src/Gone.vue.ts', $reads);
    }

    public function testARequireOutsideTheProgramIsReadDirectlyAndThroughALink(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true}, "include": ["src/**/*.ts"]}' . "\n");
        $this->write('src/a.ts', "const y = require('./y');\nconst l = require('./lnk');\nexport { y, l };\n");
        $this->write('src/y.js', "module.exports = 1;\n");
        $this->write('real/target.js', "module.exports = 2;\n");
        symlink('../real/target.js', $this->root . '/src/lnk.js');

        [$result, $contributions] = $this->scan(['src/a.ts'], ['config_files' => ['tsconfig.json']]);

        $reads = $contributions['src/a.ts']->reads ?? [];
        assertSame(hash('sha256', "module.exports = 1;\n"), $reads['src/y.js'] ?? 'missing');
        assertSame(hash('sha256', "module.exports = 2;\n"), $reads['src/lnk.js'] ?? 'missing');
        assertSame(hash('sha256', "module.exports = 2;\n"), $reads['real/target.js'] ?? 'missing');
        foreach ($reads as $path => $hash) {
            assertSame($hash, $result['input_hashes'][$path]);
        }
    }

    /**
     * @param list<string> $files
     * @param array<string, mixed> $params
     * @return array{0: array<string, mixed>, 1: array<string, ScanContribution>}
     */
    private function scan(array $files, array $params): array
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = [];
            foreach ($client->scan(['root' => $this->root, 'files' => $files] + $params) as $contribution) {
                $contributions[substr($contribution->ownerKey, strlen('knossos.typescript:file:'))] = $contribution;
            }
            $result = $client->lastScanResult();
        } finally {
            $client->shutdown();
        }

        return [$result, $contributions];
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }
}
