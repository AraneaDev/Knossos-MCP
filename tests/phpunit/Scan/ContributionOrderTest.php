<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * When two files declare the same symbol, which declaration the graph keeps
 * must not depend on which of the two was reused from the cache and which was
 * rescanned.
 */
#[Group('scan')]
final class ContributionOrderTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-order-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{"name": "app/order"}' . "\n");
        file_put_contents($this->root . '/src/A.php', "<?php\nnamespace App;\nclass Foo {}\n");
        file_put_contents($this->root . '/src/B.php', "<?php\nnamespace App;\nclass Foo {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testADuplicateDeclarationKeepsTheSameFileWhetherItWasCachedOrRescanned(): void
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $first = $this->declaringPath($pdo);

        file_put_contents($this->root . '/src/A.php', "<?php\n// edited\nnamespace App;\nclass Foo {}\n");
        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(1, $incremental->data['parsed_files']);
        assertSame($this->declaringPath($full), $this->declaringPath($pdo));
        assertSame($first, $this->declaringPath($pdo));
    }

    private function scan(PDO $pdo): \Knossos\Query\ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
    }

    private function declaringPath(PDO $pdo): string
    {
        return (string) $pdo->query("SELECT f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.canonical_name = 'App\\Foo' AND n.kind = 'class'")->fetchColumn();
    }
}
