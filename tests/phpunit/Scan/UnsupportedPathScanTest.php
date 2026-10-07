<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class UnsupportedPathScanTest extends KnossosTestCase
{
    /** One file name that is not valid UTF-8 used to abort the whole scan with a JsonException. */
    #[Group('scan')]
    public function testScanSkipsAnUnsupportedNameAndIndexesTheRest(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o700, true);
        try {
            file_put_contents($root . "/caf\xe9.php", "<?php\nfunction a() {}\n");
            file_put_contents($root . '/b.php', "<?php\nfunction b() {}\n");
            $pdo = $this->freshTestDatabase();

            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            $paths = $pdo->prepare('SELECT relative_path FROM files WHERE project_id = :id');
            $paths->execute(['id' => $result->projectId]);
            self::assertSame(['b.php'], $paths->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
