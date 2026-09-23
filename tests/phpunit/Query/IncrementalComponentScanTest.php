<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An incremental scan sends a worker only the files that changed. A fact that
 * depended on which other files were in the same request then changed with
 * the batch: editing the one file that loads a directory through
 * `require.context`, or that imports a Vue component without its extension,
 * left every module it reached unreferenced. The verdicts must be the same
 * whichever files a request happens to hold.
 */
#[Group('query')]
final class IncrementalComponentScanTest extends KnossosTestCase
{
    public function testEditingTheLoaderAloneKeepsWhatItLoadsLive(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-incremental-' . bin2hex(random_bytes(6));
        mkdir($root . '/js/store/modules/nested', 0o777, true);
        mkdir($root . '/js/components', 0o777, true);
        $files = [
            'package.json' => '{"name":"app","private":true,"dependencies":{"vue":"^2.7.0"}}',
            'js/app.js' => "import store from './store';\nimport Card from './components/Card';\nexport default [store, Card];\n",
            'js/store/index.js' => "const modules = require.context('./modules', false, /\\.js$/);\nexport default modules;\n",
            'js/store/modules/auth.js' => "export default { state: {} };\n",
            // Below a non-recursive context, so nothing loads it.
            'js/store/modules/nested/legacy.js' => "export default {};\n",
            'js/components/Card.vue' => "<template><div/></template>\n<script>\nexport default { name: 'Card' };\n</script>\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $service->scan($root, mode: 'full');
            // Only the files that load the others change.
            file_put_contents($root . '/js/store/index.js', $files['js/store/index.js'] . "// edited\n");
            file_put_contents($root . '/js/app.js', $files['js/app.js'] . "// edited\n");
            $projectId = $service->scan($root, mode: 'incremental')->projectId;
            $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 100)->data;
        } finally {
            $this->removeTempTree($root);
        }

        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        self::assertNotContains('js/store/modules/auth.js', $names);
        self::assertNotContains('js/components/Card.vue', $names);
        self::assertContains('js/store/modules/nested/legacy.js', $names);
    }
}
