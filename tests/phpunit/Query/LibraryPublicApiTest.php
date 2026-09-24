<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A package that publishes an entry (`main`, `exports`) and is not private is
 * a library: what its entry re-exports, and the members of the classes it
 * re-exports, are API for consumers outside the repository. Every method
 * nothing in the repository happened to call read as dead. What the entry does
 * not publish, and a private package's exports, stay reportable.
 */
#[Group('query')]
final class LibraryPublicApiTest extends KnossosTestCase
{
    public function testWhatAPublicPackagesEntryReExportsIsNotDeadCode(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-public-api-' . bin2hex(random_bytes(6));
        mkdir($root . '/packages/core/src', 0o777, true);
        mkdir($root . '/packages/app/src', 0o777, true);
        $files = [
            'package.json' => '{"name":"workspace","private":true}',
            'packages/core/package.json' => '{"name":"@acme/core","main":"src/index.ts","exports":{".":{"types":"./dist/index.d.ts","default":"./dist/index.js"}}}',
            'packages/core/tsconfig.json' => '{"compilerOptions":{"strict":true,"noEmit":true,"outDir":"dist","rootDir":"src"},"include":["src"]}',
            'packages/core/src/index.ts' => "export { Engine } from './engine';\nexport * from './math';\n",
            'packages/core/src/engine.ts' => "export class Engine {\n    start(): void {}\n}\nexport function notPublished(): void {}\n",
            'packages/core/src/math.ts' => "export function clamp(v: number): number { return v; }\n",
            'packages/app/package.json' => '{"name":"app","private":true,"main":"src/index.ts"}',
            'packages/app/src/index.ts' => "export { helper } from './helper';\n",
            'packages/app/src/helper.ts' => "export function helper(): void {}\n",
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
        foreach (['packages/core/src/engine.ts#Engine::start', 'packages/core/src/math.ts#clamp'] as $published) {
            self::assertNotContains($published, $names);
        }
        // Not re-exported by the entry, and a private package's export.
        self::assertContains('packages/core/src/engine.ts#notPublished', $names);
        self::assertContains('packages/app/src/helper.ts#helper', $names);
    }

    /**
     * A package that publishes only its build output (`dist/esm.mjs`) is
     * compiled from the same path under `src/`, which is what its consumers
     * are calling.
     */
    public function testAnEntryInTheBuildOutputPublishesItsSourceTwin(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-public-dist-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'package.json' => '{"name":"@acme/sdk","main":"dist/t.js","module":"dist/esm.mjs","types":"types/index.d.ts"}',
            'tsconfig.json' => '{"compilerOptions":{"strict":true,"noEmit":true},"include":["src"]}',
            'src/esm.ts' => "export { TrackerCore } from './core';\n",
            'src/core.ts' => "export class TrackerCore {\n    getStats(): number { return 1; }\n}\nexport function notPublished(): void {}\n",
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
        self::assertNotContains('src/core.ts#TrackerCore::getStats', $names);
        self::assertContains('src/core.ts#notPublished', $names);
    }
}
