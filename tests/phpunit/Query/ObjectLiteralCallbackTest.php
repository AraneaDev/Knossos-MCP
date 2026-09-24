<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A method of an object literal that is handed to a library, as a component
 * prop or an option, is called by that library, and no scanner sees the call.
 * Where no type in the project names the literal's methods, their absence of
 * inbound edges proves little, so they are reported as possibly dead only.
 */
#[Group('query')]
final class ObjectLiteralCallbackTest extends KnossosTestCase
{
    public function testACallbackInsideAnOptionsObjectIsOnlyPossiblyDead(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-literal-callback-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'package.json' => '{"name":"app","private":true}',
            'src/main.js' => "import table from './table.js';\nexport default table;\n",
            'src/table.js' => "export default {\n  data() {\n    return {\n      filters: [{ name: 'all', func() { return true; } }],\n    };\n  },\n};\nexport class Unused {\n  render() { return 1; }\n}\nconst local = { unused() { return 2; } };\nconst renderer = { heading() { return 3; } };\nexport function install(lib) {\n  lib.use({ renderer });\n}\n",
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

        $confidence = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $confidence[(string) $candidate['component']['display_name']] = $candidate['confidence'];
        }
        self::assertSame('possible', $confidence['func'] ?? null);
        self::assertSame('probable', $confidence['render'] ?? null);
        // A literal a binding names is that binding's, not handed anywhere:
        // nothing implies an unseen caller.
        self::assertSame('probable', $confidence['unused'] ?? null);
        // A binding handed on as a value goes wherever the literal would.
        self::assertSame('possible', $confidence['heading'] ?? null);
    }
}
