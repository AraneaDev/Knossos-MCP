<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerLimits;

trait WorkerClients
{
    public function fakeWorkerClient(string $mode, ?WorkerLimits $limits = null): ProcessScannerClient
    {
        return new ProcessScannerClient(
            [PHP_BINARY, self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php', $mode],
            $limits ?? new WorkerLimits(),
        );
    }

    public function phpWorkerClient(): ProcessScannerClient
    {
        $coverageDirectory = getenv('KNOSSOS_PHP_COVERAGE_DIR');
        $coverageArguments = is_string($coverageDirectory) && $coverageDirectory !== ''
            ? [
                '-d', 'pcov.directory=' . self::repositoryRoot(),
                '-d', 'auto_prepend_file=' . self::repositoryRoot() . '/tools/pcov-prepend.php',
            ]
            : [];
        return new ProcessScannerClient(
            [PHP_BINARY, ...$coverageArguments, self::repositoryRoot() . '/workers/php/bin/worker'],
            new WorkerLimits(requestTimeoutMs: 10_000),
        );
    }

    public function typescriptWorkerClient(): ProcessScannerClient
    {
        $coverageDirectory = getenv('KNOSSOS_JS_COVERAGE_DIR');
        $command = is_string($coverageDirectory) && $coverageDirectory !== ''
            ? [
                'env',
                'NODE_V8_COVERAGE=' . $coverageDirectory,
                'node',
                self::repositoryRoot() . '/workers/typescript/bin/worker.js',
            ]
            : ['node', self::repositoryRoot() . '/workers/typescript/bin/worker.js'];
        return new ProcessScannerClient(
            $command,
            new WorkerLimits(requestTimeoutMs: 20_000, maxLineBytes: 2_000_000, maxOutputBytes: 30_000_000),
        );
    }

    public function pythonWorkerClient(): ProcessScannerClient
    {
        return new ProcessScannerClient(
            $this->pythonWorkerCommand(),
            new WorkerLimits(requestTimeoutMs: 10_000, maxLineBytes: 2_000_000, maxOutputBytes: 30_000_000),
        );
    }

    /** @return non-empty-list<string> */
    public function pythonWorkerCommand(): array
    {
        $coverageDirectory = getenv('KNOSSOS_PYTHON_COVERAGE_DIR');
        return is_string($coverageDirectory) && $coverageDirectory !== ''
            ? [
                'coverage',
                'run',
                '--branch',
                '--parallel-mode',
                '--data-file=' . $coverageDirectory . '/.coverage',
                '--source=' . self::repositoryRoot() . '/workers/python/bin',
                self::repositoryRoot() . '/workers/python/bin/worker.py',
            ]
            : ['python3', '-I', '-B', self::repositoryRoot() . '/workers/python/bin/worker.py'];
    }

    /** @param list<string> $messages @return list<array<string, mixed>> */
    public function runPythonWorkerProtocol(array $messages): array
    {
        $pipes = [];
        $process = proc_open($this->pythonWorkerCommand(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start Python worker protocol fixture.');
        }
        foreach ($messages as $message) {
            fwrite($pipes[0], $message . "\n");
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit);
        self::assertSame('', $stderr);

        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($stdout)))),
        );
    }
}
