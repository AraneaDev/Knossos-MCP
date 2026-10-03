<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * `$context->options->flag()` reaches a collaborator through a property of a
 * typed parameter, and the property is declared in another file, often as a
 * promoted constructor parameter. A scanner reading one file at a time cannot
 * see that declaration, so the call left no edge and everything reached only
 * this way read as unreferenced. The scanner names the property path; the
 * reconciler, which sees every file's declared property types, finishes it.
 *
 * A script's file-scope variables are the other half: `$endpoint = new
 * Endpoint(); $endpoint->handle();` in an entry script typed nothing, because
 * only a function's variables were tracked.
 */
final class PropertyReceiverTest extends KnossosTestCase
{
    #[Group('php-scanner')]
    public function testACallThroughATypedPropertyOrAScriptVariableResolvesToTheDeclaringMethod(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-property-receiver-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o755, true) || !mkdir($root . '/bin', 0o755, true)) {
            throw new \RuntimeException('Unable to create fixture tree.');
        }
        try {
            file_put_contents($root . '/composer.json', json_encode(['name' => 'fixture/property-receiver'], JSON_THROW_ON_ERROR));
            file_put_contents($root . '/src/Collaborators.php', <<<'PHP'
                <?php

                namespace Fixture;

                final class Parser
                {
                    public function flag(): bool
                    {
                        return true;
                    }

                    public function single(): ?string
                    {
                        return null;
                    }
                }

                final class Policy
                {
                    public function metadata(): array
                    {
                        return [];
                    }
                }

                final class Paths
                {
                    public function encode(): string
                    {
                        return '';
                    }
                }
                PHP);
            file_put_contents($root . '/src/Context.php', <<<'PHP'
                <?php

                namespace Fixture;

                final readonly class Context
                {
                    public function __construct(
                        public Parser $options,
                        public Preparation $preparation,
                        public ?Paths $paths = null,
                    ) {}
                }

                final class Preparation
                {
                    public Policy $policy;
                }

                abstract class BaseCommand
                {
                    protected Parser $parser;
                }
                PHP);
            file_put_contents($root . '/src/Command.php', <<<'PHP'
                <?php

                namespace Fixture;

                final class Command extends BaseCommand
                {
                    public function run(Context $context): bool
                    {
                        $context->preparation->policy->metadata();
                        $context->paths?->encode();
                        $context->missing->single();

                        return $context->options->flag();
                    }

                    public function inherited(): bool
                    {
                        return $this->parser->flag();
                    }
                }
                PHP);
            file_put_contents($root . '/bin/serve.php', <<<'PHP'
                <?php

                $parser = new \Fixture\Parser();
                $parser->single();
                PHP);
            $pdo = SqliteConnection::open($root . '/graph.sqlite');
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();

            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full');

            $calls = $pdo->query(
                "SELECT s.canonical_name AS source, t.canonical_name AS target FROM edges e " .
                "JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id " .
                "WHERE e.kind = 'calls' AND t.kind = 'method' ORDER BY s.canonical_name, t.canonical_name",
            )->fetchAll();

            assertSame(
                [
                    ['source' => 'Fixture\\Command::inherited', 'target' => 'Fixture\\Parser::flag'],
                    ['source' => 'Fixture\\Command::run', 'target' => 'Fixture\\Parser::flag'],
                    ['source' => 'Fixture\\Command::run', 'target' => 'Fixture\\Paths::encode'],
                    ['source' => 'Fixture\\Command::run', 'target' => 'Fixture\\Policy::metadata'],
                    // An undeclared property types nothing, so `single` is
                    // reached only from the script.
                    ['source' => 'bin/serve.php', 'target' => 'Fixture\\Parser::single'],
                ],
                array_map(static fn(array $row): array => ['source' => $row['source'], 'target' => $row['target']], $calls),
            );
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
