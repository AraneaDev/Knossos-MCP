<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectDatabaseLocator;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;
use Knossos\Watch\SharedWatch;
use Knossos\Watch\WatchService;
use Throwable;

/**
 * `watch`: rescan a project as it changes, until interrupted.
 *
 * `--shared` is the live watcher the Claude Code mod starts (see
 * {@see SharedWatch}): only an existing project in an allowed root, one
 * watcher per project across sessions, its events one JSON object per line
 * on stdout, and it stops on its own once the process that started it is
 * gone. Like the mod's other commands it always exits 0 and never creates a
 * database.
 */
final class WatchCommand implements CliCommand
{
    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return $command === 'watch';
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return ['db', 'json', 'poll-ms', 'debounce-ms', 'max-queue', 'shared'];
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $root = $positionals[0] ?? throw new InvalidArgumentException('Usage: knossos watch <path> [options]');
        if ($context->options->flag($options, 'shared')) {
            return $this->shared($root, $options, $context);
        }
        $scanner = new ProjectScanService($context->database(), $context->installationRoot(), [$root]);
        $observer = static function (array $event): void {
            fwrite(STDERR, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        };
        $result = (new WatchService($scanner, [$root]))->run(
            $root,
            $context->options->integer($options, 'poll-ms', 500, 50, 60_000),
            $context->options->integer($options, 'debounce-ms', 300, 0, 60_000),
            $context->options->integer($options, 'max-queue', 1000, 1, 10_000),
            $context->cancellationToken(true),
            $observer,
        );
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary);
        return 0;
    }

    /**
     * The shared watcher: events on stdout, every failure an event, exit 0.
     *
     * @param array<string, list<string>> $options
     */
    private function shared(string $root, array $options, CliCommandContext $context): int
    {
        $emit = static function (array $event): void {
            echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
            flush();
        };
        try {
            $databasePath = (new ProjectDatabaseLocator())->locate($root, $options, $context);
            if (!is_file($databasePath)) {
                $emit(['event' => 'refused', 'status' => 'unscanned']);
                return 0;
            }
            $pdo = (new RuntimeFactory($context->installationRoot()))->database($databasePath);
            // Started by a session: when that process is gone (the watcher was re-parented), stop.
            $parent = function_exists('posix_getppid') ? posix_getppid() : null;
            $alive = $parent === null ? null : static fn(): bool => posix_getppid() === $parent;
            (new SharedWatch($pdo, $databasePath, $context->installationRoot()))->run(
                $root,
                $context->options->integer($options, 'poll-ms', 1000, 50, 60_000),
                $context->options->integer($options, 'debounce-ms', 300, 0, 60_000),
                $context->cancellationToken(true),
                $emit,
                $alive,
            );
        } catch (Throwable $error) {
            $emit(['event' => 'error', 'message' => $error->getMessage(), 'retryable' => false]);
            $emit(['event' => 'stopped', 'reason' => 'error']);
        }
        return 0;
    }
}
