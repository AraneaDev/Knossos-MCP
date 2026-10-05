<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ComponentDetailService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
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
            assertSame(['count', 'truncated', 'names', 'items'], array_keys($c['uses']));
            assertSame('Greeter', $c['display_name']);
            // The dashboard's label: the declared boundary.
            assertSame('Core', $c['boundary']);
            assertSame([], $c['annotations']);
            $caller = array_values(array_filter($c['used_by']['items'], static fn(array $i): bool => $i['canonical_name'] === 'App\\Caller::run'))[0] ?? null;
            assertSame(['name' => 'run', 'canonical_name' => 'App\\Caller::run', 'kind' => 'method', 'boundary' => 'Edge', 'edges' => $caller['edges'] ?? 0], $caller);
            assertGreaterThanOrEqual(1, $caller['edges']);
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

    /** Each direction lists its most connected counterparts first, at most eight, with edge counts. */
    #[Group('query')]
    public function testCounterpartsAreListedMostConnectedFirstAndBounded(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/' . self::FIXTURE, $root);
        for ($i = 0; $i < 10; ++$i) {
            // Caller 0 greets three times, the rest once each.
            $calls = str_repeat("\n        (new \\App\\Greeter())->greet('x');", $i === 0 ? 3 : 1);
            file_put_contents(
                $root . "/src/Edge/Many{$i}.php",
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Many{$i}\n{\n    public function go(): void\n    {{$calls}\n    }\n}\n",
            );
        }
        try {
            $pdo = $this->freshTestDatabase();
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $c = (new ComponentDetailService($pdo))->detail($root, 'App\Greeter::greet')['component'];
            // Ten callers that share one short name still count as ten.
            assertGreaterThanOrEqual(11, $c['used_by']['count']);
            assertSame(false, $c['used_by']['truncated']);
            assertCount(8, $c['used_by']['items']);
            // Names are distinct: ten methods called `go` are one name.
            assertSame(array_values(array_unique($c['used_by']['names'])), $c['used_by']['names']);
            assertLessThanOrEqual(5, count($c['used_by']['names']));
            assertSame('App\Many0::go', $c['used_by']['items'][0]['canonical_name']);
            assertSame(3, $c['used_by']['items'][0]['edges']);
            $edges = array_column($c['used_by']['items'], 'edges');
            $sorted = $edges;
            rsort($sorted);
            assertSame($sorted, $edges);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A component's annotations come with its detail. */
    #[Group('query')]
    public function testAnnotationsAreIncluded(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $insert = $pdo->prepare('INSERT INTO annotations(project_id, canonical_name, kind, value, author, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$projectId, 'App\Greeter', 'note', 'the one greeting', 'agent', '2026-10-03', '2026-10-03']);
            $insert->execute([$projectId, 'App\Greeter', 'intended_boundary', 'Core', 'agent', '2026-10-03', '2026-10-03']);
            $c = (new ComponentDetailService($pdo))->detail($root, 'Greeter')['component'];
            assertSame([['kind' => 'intended_boundary', 'value' => 'Core'], ['kind' => 'note', 'value' => 'the one greeting']], $c['annotations']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
