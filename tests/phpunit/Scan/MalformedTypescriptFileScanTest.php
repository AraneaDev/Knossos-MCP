<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * One TypeScript file with a syntax error beside a valid one, scanned through
 * the real worker.
 *
 * The worker named a declaration it could not read with an empty string, the
 * core refused the reply, and the scan kept no TypeScript fact at all: one
 * malformed file cost the whole language.
 */
#[Group('scan')]
final class MalformedTypescriptFileScanTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-malformed-ts-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/package.json', '{"name":"web"}');
        file_put_contents($this->root . '/src/a.ts', "export function (( {\n");
        file_put_contents($this->root . '/src/b.ts', "export function ok(): number {\n    return 1;\n}\n");
        // Valid TypeScript whose member name is the empty string, which the
        // core refused the same way.
        file_put_contents($this->root . '/src/c.ts', "export class C {\n    \"\"(): void {}\n}\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testTheValidFilesFactsSurviveAMalformedNeighbour(): void
    {
        $pdo = $this->freshTestDatabase();
        $scans = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);

        $scans->scan($this->root);

        $codes = $pdo->query('SELECT code FROM diagnostics')->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('WORKER_CONTRIBUTION_INVALID', $codes);
        assertSame(['src/a.ts#{anonymous}@1:1', 'src/b.ts#ok'], $this->functions($pdo));
        assertSame([], $pdo->query("SELECT id FROM nodes WHERE display_name = ''")->fetchAll(PDO::FETCH_COLUMN));
        assertSame(['""'], $pdo->query("SELECT display_name FROM nodes WHERE canonical_name = 'src/c.ts#C::\"\"'")->fetchAll(PDO::FETCH_COLUMN));

        // Again, from the contributions cached by the first scan.
        $scans->scan($this->root);
        assertSame(['src/a.ts#{anonymous}@1:1', 'src/b.ts#ok'], $this->functions($pdo));
    }

    /** @return list<string> the canonical names of the function nodes, sorted */
    private function functions(PDO $pdo): array
    {
        return $pdo->query("SELECT canonical_name FROM nodes WHERE kind = 'function' ORDER BY canonical_name")->fetchAll(PDO::FETCH_COLUMN);
    }
}
