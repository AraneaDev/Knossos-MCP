<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `import.meta.glob('./Pages/**\/*.vue')` is how a Vite app loads its pages:
 * no import statement names any of them, so each page read as unreferenced.
 */
#[Group('query')]
final class ViteGlobImportTest extends KnossosTestCase
{
    public function testAGlobImportKeepsTheModulesItMatchesLive(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-vite-glob-' . bin2hex(random_bytes(6));
        mkdir($root . '/js/Pages/Admin', 0o777, true);
        mkdir($root . '/js/Layouts', 0o777, true);
        mkdir($root . '/js/Widgets', 0o777, true);
        mkdir($root . '/js/Icons', 0o777, true);
        $page = "<template><div/></template>\n<script setup lang=\"ts\">\nconst title = 'x';\n</script>\n";
        $files = [
            'package.json' => '{"name":"app","private":true,"type":"module","dependencies":{"vue":"^3.4.0","vite":"^7.0.0"}}',
            'js/app.ts' => implode("\n", [
                "const pages = import.meta.glob<object>('./Pages/**/*.vue');",
                "const layouts = import.meta.glob(['./Layouts/{Main,Admin}.vue', '!./Layouts/Admin.vue'], { eager: true });",
                // A relative pattern resolves from `base`, and a character class matches one of its characters.
                "const widgets = import.meta.glob('./*.vue', { base: './Widgets' });",
                "const icons = import.meta.glob('./Icons/[ab].vue');",
                'export default [pages, layouts, widgets, icons];',
                '',
            ]),
            'js/Pages/Home.vue' => $page,
            'js/Pages/Admin/Users.vue' => $page,
            // Matched by no pattern: not a component, or never globbed.
            'js/Pages/helpers.ts' => "export const helper = 1;\n",
            'js/Layouts/Main.vue' => $page,
            'js/Layouts/Side.vue' => $page,
            'js/Widgets/Card.vue' => $page,
            'js/Icons/a.vue' => $page,
            'js/Icons/b.vue' => $page,
            'js/Icons/c.vue' => $page,
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full')->projectId;
            $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        self::assertNotContains('js/Pages/Home.vue', $names);
        self::assertNotContains('js/Pages/Admin/Users.vue', $names);
        self::assertNotContains('js/Layouts/Main.vue', $names);
        self::assertContains('js/Pages/helpers.ts', $names);
        self::assertContains('js/Layouts/Side.vue', $names);
        self::assertNotContains('js/Widgets/Card.vue', $names);
        self::assertNotContains('js/Icons/a.vue', $names);
        self::assertNotContains('js/Icons/b.vue', $names);
        self::assertContains('js/Icons/c.vue', $names);
    }
}
