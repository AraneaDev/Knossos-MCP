<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Everything a Vue, Svelte or Astro component used had no inbound edge from
 * it, because components were never read, so a helper used only by
 * components read as dead. Components are now scanned, their templates
 * included, and one nothing imports or routes to is still reported.
 */
#[Group('query')]
final class ComponentUsageTest extends KnossosTestCase
{
    public function testWhatComponentsUseIsLiveAndAnOrphanedComponentIsNot(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-components-' . bin2hex(random_bytes(6));
        foreach (['src/components', 'src/lib', 'src/pages', 'src/routes'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }
        $files = [
            'package.json' => '{"name":"site","private":true}',
            'tsconfig.json' => '{"compilerOptions":{"strict":true,"noEmit":true,"baseUrl":".","paths":{"@/*":["src/*"]}},"include":["src/**/*.ts","src/**/*.vue","src/**/*.svelte"]}',
            'svelte.config.js' => "export default {};\n",
            'astro.config.mjs' => "export default {};\n",
            'src/main.ts' => "import App from '@/App.vue';\nexport default App;\n",
            'src/util.ts' => "export function formatDate(): string { return ''; }\nexport function unusedUtil(): void {}\n",
            'src/App.vue' => "<template>\n  <user-card :when=\"formatDate()\" @click=\"onSave\" />\n</template>\n<script setup lang=\"ts\">\nimport UserCard from '@/components/UserCard.vue';\nimport { formatDate } from './util';\nfunction onSave(): void {}\n</script>\n",
            'src/components/UserCard.vue' => "<template><p>card</p></template>\n<script setup lang=\"ts\"></script>\n",
            'src/components/Orphan.vue' => "<template><p>nobody</p></template>\n",
            'src/lib/counter.ts' => "export function increment(): void {}\n",
            'src/routes/+page.svelte' => "<script lang=\"ts\">\nimport { increment } from '../lib/counter';\n</script>\n<button on:click={increment}>+</button>\n",
            'src/lib/site.ts' => "export function title(): string { return ''; }\n",
            'src/pages/index.astro' => "---\nimport { title } from '../lib/site';\n---\n<h1>{title()}</h1>\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        foreach ([
            'src/util.ts#formatDate',
            'src/App.vue#onSave',
            'src/components/UserCard.vue',
            'src/lib/counter.ts#increment',
            'src/lib/site.ts#title',
            'src/routes/+page.svelte',
            'src/pages/index.astro',
        ] as $live) {
            self::assertNotContains($live, $names, $live);
        }
        self::assertContains('src/util.ts#unusedUtil', $names);
        self::assertContains('src/components/Orphan.vue', $names);
    }
}
