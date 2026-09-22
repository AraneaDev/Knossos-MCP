<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A method called on a receiver no scanner can type (an untyped parameter)
 * gets no edge, so it read as probably dead however often it runs. Each
 * scanner records the member names called that way, and a method by one of
 * those names is reported as only possibly dead. A method nothing calls by
 * name keeps its probable verdict.
 */
#[Group('query')]
final class UntypedReceiverTest extends KnossosTestCase
{
    public function testAMethodNamedByAnUntypedCallIsOnlyPossiblyDeadInEachLanguage(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-untyped-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        $files = [
            'src/Mode.php' => "<?php\nfinal class Mode\n{\n    public function phpLabel(): string { return ''; }\n    public function phpUnused(): string { return ''; }\n}\n",
            'src/Loop.php' => "<?php\nfinal class Loop\n{\n    public function run(\$mode): string { return \$mode->phpLabel(); }\n}\n",
            'src/mode.js' => "export class Mode {\n    jsLabel() {}\n    jsUnused() {}\n}\n",
            'src/loop.js' => "export function loop(mode) {\n    mode.jsLabel();\n}\n",
            'src/mode.py' => "class Mode:\n    def py_label(self):\n        pass\n\n    def py_unused(self):\n        pass\n",
            'src/loop.py' => "def loop(mode):\n    mode.py_label()\n",
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

        $confidence = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $confidence[$candidate['component']['display_name']] = $candidate['confidence'];
        }
        foreach (['phpLabel', 'jsLabel', 'py_label'] as $called) {
            self::assertSame('possible', $confidence[$called] ?? null, $called);
        }
        foreach (['phpUnused', 'jsUnused', 'py_unused'] as $unused) {
            self::assertSame('probable', $confidence[$unused] ?? null, $unused);
        }
    }
}
