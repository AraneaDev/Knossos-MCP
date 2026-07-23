<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Error;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Watch\WatchScanAttempt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('watch-scan-attempt')]
final class WatchScanAttemptTest extends TestCase
{
    // ----- helpers -----

    private static function successfulScanner(): ProjectScanner
    {
        return new class () implements ProjectScanner {
            public function scan(
                string $root,
                ?string $name = null,
                ?int $maxFiles = null,
                ?int $maxFileBytes = null,
                ?array $explicitBoundaries = null,
                ?string $mode = null,
                ?CancellationToken $cancellation = null,
                ?int $snapshotRetention = null,
                ?int $workerTimeoutMs = null,
            ): ResultEnvelope {
                return new ResultEnvelope('project-1', 'snapshot-1', 'ok', ['parsed_files' => 7]);
            }
        };
    }

    private static function throwingScanner(\Throwable $error): ProjectScanner
    {
        return new class ($error) implements ProjectScanner {
            public function __construct(private \Throwable $error)
            {
            }

            public function scan(
                string $root,
                ?string $name = null,
                ?int $maxFiles = null,
                ?int $maxFileBytes = null,
                ?array $explicitBoundaries = null,
                ?string $mode = null,
                ?CancellationToken $cancellation = null,
                ?int $snapshotRetention = null,
                ?int $workerTimeoutMs = null,
            ): ResultEnvelope {
                throw $this->error;
            }
        };
    }

    // ----- SUCCESS path -----

    public function testRunReturnsSuccessWhenScannerReturnsResult(): void
    {
        $attempt = WatchScanAttempt::run(
            self::successfulScanner(),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        // outcome + is*() predicates
        assertSame(WatchScanAttempt::SUCCESS, $attempt->outcome);
        assertSame(true, $attempt->isSuccess());
        assertSame(false, $attempt->isCancelled());
        assertSame(false, $attempt->isRetryable());
        assertSame(false, $attempt->isTerminal());
        assertSame(null, $attempt->errorMessage);

        // ResultEnvelope is a final readonly value object — two separately
        // constructed instances are never identical, so compare field-by-field
        // to keep tight mutation kill on every promoted constructor arg AND on
        // the default values of the promoted props the stub leaves untouched.
        $result = $attempt->result;
        $this->assertInstanceOf(ResultEnvelope::class, $result);
        assertSame('project-1', $result->projectId);
        assertSame('snapshot-1', $result->snapshotId);
        assertSame('ok', $result->summary);
        assertSame(['parsed_files' => 7], $result->data);
        assertSame([], $result->evidence);
        assertSame([], $result->warnings);
        assertSame(false, $result->truncated);
        assertSame(null, $result->staleness);
        assertSame([], $result->nextSteps);
        assertSame(null, $result->meta);
    }

    // ----- CANCELLED: pre-cancellation -----

    public function testRunReturnsCancelledWithoutCallingScannerWhenTokenAlreadyCancelled(): void
    {
        $doesNotMatter = new class () implements ProjectScanner {
            public int $invocations = 0;

            public function scan(
                string $root,
                ?string $name = null,
                ?int $maxFiles = null,
                ?int $maxFileBytes = null,
                ?array $explicitBoundaries = null,
                ?string $mode = null,
                ?CancellationToken $cancellation = null,
                ?int $snapshotRetention = null,
                ?int $workerTimeoutMs = null,
            ): ResultEnvelope {
                ++$this->invocations;

                throw new \LogicException('scan() must not be called when cancellation is pre-set');
            }
        };

        $token = new CancellationToken();
        $token->cancel();

        $attempt = WatchScanAttempt::run($doesNotMatter, '/tmp/root', 'incremental', $token);

        assertSame(WatchScanAttempt::CANCELLED, $attempt->outcome);
        assertSame(true, $attempt->isCancelled());
        assertSame(0, $doesNotMatter->invocations);
        assertSame(null, $attempt->result);
        assertSame(null, $attempt->errorMessage);
    }

    // ----- CANCELLED: ScanCancelledException from scanner -----

    public function testRunReturnsCancelledWhenScannerThrowsScanCancelledException(): void
    {
        $attempt = WatchScanAttempt::run(
            self::throwingScanner(new ScanCancelledException('cancelled mid-scan')),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        assertSame(WatchScanAttempt::CANCELLED, $attempt->outcome);
        assertSame(true, $attempt->isCancelled());
        assertSame(null, $attempt->result);
    }

    // ----- TERMINAL: Error-class exceptions -----

    public function testRunReturnsTerminalWhenScannerThrowsAnError(): void
    {
        $attempt = WatchScanAttempt::run(
            self::throwingScanner(new Error('undefined method')),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        assertSame(WatchScanAttempt::TERMINAL, $attempt->outcome);
        assertSame(true, $attempt->isTerminal());
        assertSame('undefined method', $attempt->errorMessage);
        assertSame(null, $attempt->result);
    }

    public function testRunReturnsTerminalForTypeErrorSubclass(): void
    {
        $attempt = WatchScanAttempt::run(
            self::throwingScanner(new \TypeError('wrong arg type')),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        assertSame(WatchScanAttempt::TERMINAL, $attempt->outcome);
        assertSame(true, $attempt->isTerminal());
    }

    // ----- RETRYABLE: regular Exception subclasses -----

    public function testRunReturnsRetryableWhenScannerThrowsRuntimeException(): void
    {
        $attempt = WatchScanAttempt::run(
            self::throwingScanner(new RuntimeException('worker timed out')),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        assertSame(WatchScanAttempt::RETRYABLE, $attempt->outcome);
        assertSame(true, $attempt->isRetryable());
        assertSame(false, $attempt->isTerminal());
        assertSame('worker timed out', $attempt->errorMessage);
        assertSame(null, $attempt->result);
    }

    public function testRunReturnsRetryableWhenScannerThrowsArbitraryException(): void
    {
        $attempt = WatchScanAttempt::run(
            self::throwingScanner(new \LogicException('flaky storage')),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        assertSame(WatchScanAttempt::RETRYABLE, $attempt->outcome);
        assertSame(true, $attempt->isRetryable());
    }

    // ----- WorkerException classification by diagnostic code -----

    /** @return list<array{string}> */
    public static function transientWorkerCodes(): array
    {
        return [['WORKER_TIMEOUT'], ['WORKER_PIPE_BROKEN'], ['WORKER_IO_FAILED'], ['WORKER_EXITED']];
    }

    #[DataProvider('transientWorkerCodes')]
    public function testTransientWorkerExceptionIsRetryable(string $code): void
    {
        $attempt = WatchScanAttempt::run(
            self::throwingScanner(new WorkerException($code, 'transient worker fault')),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        assertSame(WatchScanAttempt::RETRYABLE, $attempt->outcome);
        assertSame(true, $attempt->isRetryable());
        assertSame('transient worker fault', $attempt->errorMessage);
    }

    /** @return list<array{string}> */
    public static function permanentWorkerCodes(): array
    {
        return [
            ['WORKER_START_FAILED'],
            ['WORKER_PROTOCOL_VERSION_MISMATCH'],
            ['WORKER_OUTPUT_SCHEMA_MISMATCH'],
            ['WORKER_CAPABILITY_MISMATCH'],
            ['WORKER_REQUEST_TOO_LARGE'],
        ];
    }

    #[DataProvider('permanentWorkerCodes')]
    public function testPermanentWorkerExceptionIsTerminal(string $code): void
    {
        $attempt = WatchScanAttempt::run(
            self::throwingScanner(new WorkerException($code, 'permanent worker fault')),
            '/tmp/root',
            'incremental',
            new CancellationToken(),
        );

        assertSame(WatchScanAttempt::TERMINAL, $attempt->outcome);
        assertSame(true, $attempt->isTerminal());
        assertSame(false, $attempt->isRetryable());
        assertSame('permanent worker fault', $attempt->errorMessage);
    }

    // ----- outcome constants + shape -----

    public function testOutcomeConstantsAreStableStrings(): void
    {
        assertSame('success', WatchScanAttempt::SUCCESS);
        assertSame('cancelled', WatchScanAttempt::CANCELLED);
        assertSame('retryable', WatchScanAttempt::RETRYABLE);
        assertSame('terminal', WatchScanAttempt::TERMINAL);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(WatchScanAttempt::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}