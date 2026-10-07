<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Discovery skips a file whose name holds a control character, but a tsconfig
 * `include` glob still hands it to the compiler, which reads it and reports
 * the read. That report must not cost the language its scan.
 */
#[Group('scan')]
final class TypescriptUnnameableFileScanTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-unnameable-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/tsconfig.json', '{"compilerOptions":{"strict":true},"include":["src/**/*"]}');
        file_put_contents($this->root . '/src/ok.ts', "export class Ok {}\n");
        if (@file_put_contents($this->root . "/src/a\nb.ts", "export const ab = 1;\n") === false) {
            self::markTestSkipped('The filesystem rejects control characters in names.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /** The compiler's read of the unnameable file is dropped; the rest of the language is scanned. */
    public function testAFileDiscoveryCannotNameDoesNotDegradeTypescript(): void
    {
        $pdo = $this->freshTestDatabase();

        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        assertSame([], $result->data['degraded_languages']);
        $owners = $pdo->query('SELECT DISTINCT owner_key FROM nodes')->fetchAll(\PDO::FETCH_COLUMN);
        assertSame(true, in_array('knossos.typescript:file:src/ok.ts', $owners, true));
    }
}
