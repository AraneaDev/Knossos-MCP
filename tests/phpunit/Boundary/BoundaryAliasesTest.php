<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Boundary;

use Knossos\Boundary\BoundaryAliases;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A boundary's former names travel inside its stored matcher, since there is
 * no column for them, and every reader takes them out again before showing
 * the matcher.
 */
#[Group('boundary-aliases')]
final class BoundaryAliasesTest extends KnossosTestCase
{
    public function testMergeAddsTheAliasesOnlyWhenThereAreAny(): void
    {
        $matcher = ['type' => 'path_prefix', 'value' => ''];

        assertSame($matcher, BoundaryAliases::merge($matcher, []));
        assertSame($matcher + ['aliases' => ['a', 'b']], BoundaryAliases::merge($matcher, ['a', 'b']));
    }

    public function testSplitSeparatesTheMatcherFromItsAliases(): void
    {
        $matcher = ['type' => 'path_prefix', 'value' => 'src/'];

        assertSame([$matcher, ['a', 'b']], BoundaryAliases::split($matcher + ['aliases' => ['a', 'b']]));
        assertSame([$matcher, []], BoundaryAliases::split($matcher));
        // A stored matcher is read from the database, which an imported bundle
        // also writes: anything but a list of non-empty strings is no alias.
        assertSame([$matcher, []], BoundaryAliases::split($matcher + ['aliases' => 'a']));
        assertSame([$matcher, ['a']], BoundaryAliases::split($matcher + ['aliases' => ['a', 3, '', ['b']]]));
    }

    /** Stored through a real scan: the aliases ride along in matcher_json and membership is untouched. */
    public function testAScanStoresTheAliasesBesideTheMatcher(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-aliases-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        try {
            file_put_contents($root . '/composer.json', '{"name":"acme/lib"}');
            file_put_contents($root . '/package.json', '{"name":"web"}');
            file_put_contents($root . '/src/A.php', "<?php\n\nnamespace App;\n\nfinal class A {}\n");
            $pdo = $this->freshTestDatabase();

            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);

            $row = $pdo->query("SELECT id, matcher_json FROM boundaries WHERE name = 'composer:acme/lib (+node:web)'")->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            assertSame(
                ['type' => 'path_prefix', 'value' => '', 'aliases' => ['composer:acme/lib', 'node:web']],
                json_decode((string) $row['matcher_json'], true),
            );
            $members = $pdo->prepare('SELECT COUNT(*) FROM boundary_memberships WHERE boundary_id = :id');
            $members->execute(['id' => $row['id']]);
            self::assertGreaterThan(0, (int) $members->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }
}
