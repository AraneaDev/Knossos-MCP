<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommand;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\CliCommandSet;
use Knossos\Cli\Command\QueryCommand;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The CLI reference is the contract for which options each command takes.
 *
 * Three commands documented options their allowlist rejected as unknown
 * (`list-usages --edge-kind/--min-confidence`, `architecture-context
 * --include-source`, `list-annotations --component`), and no test passed
 * them. This reads the usage block of docs/reference/cli.md itself, so the
 * reference and the allowlists cannot drift apart again.
 */
final class CliDocumentedOptionsTest extends KnossosTestCase
{
    #[Group('cli')]
    public function testEveryOptionInTheReferenceIsAcceptedByItsCommand(): void
    {
        $commands = CliCommandSet::all(new CliHelpRenderer(), 'test');
        $missing = [];
        $commandsChecked = 0;

        foreach (self::documentedOptions() as $command => $options) {
            $handler = self::handlerFor($commands, $command);
            assertSame(true, $handler !== null, sprintf('No command handles the documented `%s`.', $command));
            $allowed = $handler->allowedOptions($command);
            if ($allowed === [CliOptionParser::ANY]) {
                continue;
            }
            $rejected = array_values(array_diff($options, $allowed));
            if ($rejected !== []) {
                $missing[$command] = $rejected;
            }
            ++$commandsChecked;
        }

        assertSame([], $missing, 'Documented but rejected: ' . json_encode($missing));
        assertSame(true, $commandsChecked >= 40, sprintf('Expected to check at least 40 commands, checked %d.', $commandsChecked));
    }

    /** A query command without an arm of its own borrowed another command's list; now it has to have one. */
    #[Group('cli')]
    public function testEveryQueryCommandHasItsOwnAllowlist(): void
    {
        $query = new QueryCommand();
        $commands = (new \ReflectionClassConstant(QueryCommand::class, 'COMMANDS'))->getValue();

        foreach ($commands as $command) {
            assertSame(true, $query->allowedOptions($command) !== [], $command);
        }
        $error = captureThrows(static fn() => $query->allowedOptions('not-a-query'), \LogicException::class);
        assertSame('No option allowlist for not-a-query.', $error->getMessage());
    }

    /** Every query command reads a graph and renders JSON, so every one accepts --db and --json, documented or not. */
    #[Group('cli')]
    public function testEveryQueryCommandTakesTheDatabaseAndJsonOptions(): void
    {
        $query = new QueryCommand();
        foreach ((new \ReflectionClassConstant(QueryCommand::class, 'COMMANDS'))->getValue() as $command) {
            assertSame([], array_values(array_diff(['db', 'json'], $query->allowedOptions($command))), $command);
        }
    }

    /** help prints the help and nothing else; version prints the version and no help. */
    #[Group('cli')]
    public function testHelpAndVersionPrintOnlyTheirOwnText(): void
    {
        foreach (['help' => true, 'version' => false] as $command => $isHelp) {
            $help = fopen('php://memory', 'w+');
            assertSame(true, is_resource($help));
            $context = new \Knossos\Cli\CliCommandContext(new CliOptionParser(), new \Knossos\Cli\CliInputLoader(), new \Knossos\Runtime\RuntimeFactory(self::repositoryRoot()), null);
            ob_start();
            $status = (new \Knossos\Cli\Command\MetaCommand(new CliHelpRenderer($help), '9.9.9'))->run($command, [], [], $context);
            $printed = (string) ob_get_clean();
            rewind($help);
            $helpText = (string) stream_get_contents($help);

            assertSame(0, $status, $command);
            assertSame($isHelp, str_contains($helpText, 'Usage:'), $command);
            assertSame($isHelp ? '' : 'Knossos 9.9.9' . PHP_EOL, $printed, $command);
        }
    }

    /**
     * Each command in the reference's usage block with the `--options` it documents.
     *
     * A line `  knossos <command> ...` starts a command; deeper-indented lines continue it.
     *
     * @return array<string, list<string>>
     */
    private static function documentedOptions(): array
    {
        $reference = (string) file_get_contents(self::repositoryRoot() . '/docs/reference/cli.md');
        assertSame(1, preg_match('/```text\n(.*?)```/s', $reference, $block), 'The reference has a usage block.');
        $options = [];
        $current = null;
        foreach (explode("\n", $block[1]) as $line) {
            if (preg_match('/^  knossos ([a-z][a-z-]*)/', $line, $start) === 1) {
                $current = $start[1];
                $options[$current] ??= [];
            } elseif (!str_starts_with($line, '   ')) {
                $current = null;
            }
            if ($current !== null && preg_match_all('/--([a-z][a-z0-9-]*)/', $line, $found) > 0) {
                $options[$current] = array_values(array_unique([...$options[$current], ...$found[1]]));
            }
        }

        return $options;
    }

    /** @param list<CliCommand> $commands */
    private static function handlerFor(array $commands, string $command): ?CliCommand
    {
        foreach ($commands as $candidate) {
            if ($candidate->supports($command)) {
                return $candidate;
            }
        }

        return null;
    }
}
