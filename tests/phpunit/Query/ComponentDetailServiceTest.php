<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ComponentDetailService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertSame;

/**
 * One component as the architecture pane shows it, addressed by a path in
 * the project and the component's name: where it lives, its boundaries and
 * who it touches, or a status saying why there is nothing to show.
 */
final class ComponentDetailServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    #[Group('query')]
    public function testAComponentReportsWhereItIsAndWhoUsesIt(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new ComponentDetailService($pdo))->detail($root, 'Greeter');
            assertSame('ok', $d['status']);
            assertSame($projectId, $d['project_id']);
            assertSame('Greeter', $d['name']);
            assertSame([], $d['candidates']);
            $c = $d['component'];
            assertSame('App\Greeter', $c['name']);
            assertSame('class', $c['kind']);
            assertSame('src/Core/Greeter.php', $c['path']);
            assertSame(7, $c['line']);
            assertContains('Core', $c['boundaries']);
            assertGreaterThanOrEqual(1, $c['used_by']['count']);
            assertSame(false, $c['used_by']['truncated']);
            assertSame($c['used_by']['count'], count($c['used_by']['names']));
            assertSame(['count', 'truncated', 'names'], array_keys($c['uses']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testASubdirectoryResolvesToItsProject(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new ComponentDetailService($pdo))->detail($root . '/src/Core', 'Greeter');
            assertSame('ok', $d['status']);
            assertSame($projectId, $d['project_id']);
            assertSame(realpath($root . '/src/Core'), $d['path']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnUnknownNameIsNotFound(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new ComponentDetailService($pdo))->detail($root, 'Nope');
            assertSame('not-found', $d['status']);
            assertSame($projectId, $d['project_id']);
            assertSame(null, $d['component']);
            assertSame([], $d['candidates']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testANameSharedByTwoComponentsIsAmbiguous(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/' . self::FIXTURE, $root);
        foreach (['One', 'Two'] as $space) {
            mkdir($root . '/src/' . $space, 0o777, true);
            file_put_contents(
                $root . '/src/' . $space . '/Dup.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\" . $space . ";\n\nfinal class Dup {}\n",
            );
        }
        try {
            $pdo = $this->freshTestDatabase();
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $d = (new ComponentDetailService($pdo))->detail($root, 'Dup');
            assertSame('ambiguous', $d['status']);
            assertSame(null, $d['component']);
            assertContains('App\One\Dup', $d['candidates']);
            assertContains('App\Two\Dup', $d['candidates']);
            assertLessThanOrEqual(10, count($d['candidates']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnUnscannedPathReportsUnscanned(): void
    {
        $pdo = $this->freshTestDatabase();
        $empty = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($empty);
        try {
            $d = (new ComponentDetailService($pdo))->detail($empty, 'Greeter');
            assertSame('unscanned', $d['status']);
            assertSame(realpath($empty), $d['path']);
            assertSame(null, $d['project_id']);
            assertSame(null, $d['snapshot_id']);
            assertSame(null, $d['component']);
        } finally {
            $this->removeTempTree($empty);
        }
    }
}
