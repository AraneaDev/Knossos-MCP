<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `$x = Factory::make(); $x->use()` is how most PHP reaches a collaborator it did
 * not construct itself, and a scanner reading one file at a time cannot see the
 * return type when the factory lives elsewhere. The call then leaves no edge, and
 * everything it reaches reads as unreferenced: this repository's own
 * `WatchScanAttempt::isTerminal` was reported as dead code while `WatchService`
 * called it in a loop.
 *
 * The scanner names what it knows — the member, and the call whose result it is
 * on — and the reconciler, which sees every file, finishes the resolution using
 * the return types the scanner already reports.
 */
final class ReturnedReceiverTest extends KnossosTestCase
{
    #[Group('php-scanner')]
    public function testACallOnAValueReturnedByAnotherFileResolvesToTheDeclaringMethod(): void
    {
        $root = sys_get_temp_dir() . '/knossos-incremental-returned-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o755, true)) {
            throw new \RuntimeException('Unable to create fixture tree.');
        }
        file_put_contents($root . '/composer.json', json_encode(['name' => 'fixture/returned'], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/src/Attempt.php', <<<'PHP'
            <?php

            namespace Fixture;

            final class Attempt
            {
                public static function make(): self
                {
                    return new self();
                }

                public function isTerminal(): bool
                {
                    return true;
                }
            }
            PHP);
        file_put_contents($root . '/src/Service.php', <<<'PHP'
            <?php

            namespace Fixture;

            final class Registry
            {
                public function latest(): Attempt
                {
                    return Attempt::make();
                }
            }

            final class Service
            {
                public function run(): bool
                {
                    $attempt = Attempt::make();

                    return $attempt->isTerminal();
                }

                public function chained(): bool
                {
                    return Attempt::make()->isTerminal();
                }

                public function viaCollaborator(Registry $registry): bool
                {
                    return $registry->latest()->isTerminal();
                }
            }
            PHP);
        $pdo = SqliteConnection::open($root . '/graph.sqlite');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();

        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full');

        $calls = $pdo->query(
            "SELECT s.canonical_name AS source, t.canonical_name AS target, t.kind AS target_kind FROM edges e " .
            "JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id " .
            "WHERE e.kind = 'calls' AND t.display_name = 'isTerminal' ORDER BY s.canonical_name",
        )->fetchAll();

        assertSame(
            [
                ['source' => 'Fixture\\Service::chained', 'target' => 'Fixture\\Attempt::isTerminal', 'target_kind' => 'method'],
                ['source' => 'Fixture\\Service::run', 'target' => 'Fixture\\Attempt::isTerminal', 'target_kind' => 'method'],
                ['source' => 'Fixture\\Service::viaCollaborator', 'target' => 'Fixture\\Attempt::isTerminal', 'target_kind' => 'method'],
            ],
            array_map(static fn(array $row): array => ['source' => $row['source'], 'target' => $row['target'], 'target_kind' => $row['target_kind']], $calls),
        );

        unset($pdo);
        $this->removeFixtureTree($root);
    }
    #[Group('php-scanner')]
    public function testAnInferenceTheGraphCannotConfirmLeavesNoEdgeBehind(): void
    {
        // The deferred reference is a guess about a receiver, so a guess that
        // does not pay off has to disappear: inventing an external symbol for a
        // member that may not exist would put the inference in the graph as if
        // it were an observation.
        $root = sys_get_temp_dir() . '/knossos-incremental-returned-' . bin2hex(random_bytes(6));
        try {
            if (!mkdir($root . '/src', 0o755, true)) {
                throw new \RuntimeException('Unable to create fixture tree.');
            }
            file_put_contents($root . '/composer.json', json_encode(['name' => 'fixture/unconfirmed'], JSON_THROW_ON_ERROR));
            file_put_contents($root . '/src/Service.php', <<<'PHP'
                <?php

                namespace Fixture;

                final class Service
                {
                    public function unknownFactory(): mixed
                    {
                        // Nothing in the project declares Vendor\Client, so the
                        // call it is said to return cannot be resolved.
                        $client = \Vendor\Client::make();

                        return $client->send();
                    }

                    public function untypedFactory(): mixed
                    {
                        // Declared here, but with no return type to follow.
                        $thing = self::build();

                        return $thing->use();
                    }

                    public static function build(): mixed
                    {
                        return null;
                    }
                }
                PHP);
            $pdo = SqliteConnection::open($root . '/graph.sqlite');
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();

            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full');

            // No node, external or otherwise, is invented for the unresolved members.
            assertSame(0, (int) $pdo->query(
                "SELECT COUNT(*) FROM nodes WHERE canonical_name LIKE '%method_of_return%' OR display_name IN ('send', 'use')",
            )->fetchColumn());
            assertSame(0, (int) $pdo->query(
                "SELECT COUNT(*) FROM edges e JOIN nodes t ON t.id = e.target_id WHERE t.display_name IN ('send', 'use')",
            )->fetchColumn());
        } finally {
            unset($pdo);
            $this->removeFixtureTree($root);
        }
    }

}
