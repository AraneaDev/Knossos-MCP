<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use InvalidArgumentException;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Knossos\Watch\WatchService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('watch-service-validation')]
final class WatchServiceValidationTest extends TestCase
{
    private const ROOT = '/tmp/knossos-watch-validation-root';

    /** @param list<string> $allowedRoots */
    private static function serviceWith(array $allowedRoots): WatchService
    {
        $scanner = new class () implements ProjectScanner {
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
                ?int $workerMemoryMb = null,
            ): ResultEnvelope {
                // always succeed; never reached by the validation tests below
                return new ResultEnvelope('project-id', 'snapshot-id', 'ok', ['parsed_files' => 0]);
            }
        };

        return new WatchService($scanner, $allowedRoots);
    }

    // ----- constructor -----

    public function testConstructorAcceptsAllowedRoots(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $this->assertNotNull($service);
    }

    // ----- run() input validation -----

    /**
     * Every range is accepted at both of its ends.
     *
     * The refusals below say nothing about where each range stops: a comparison
     * one step out still refuses everything these tests refuse. The bound itself
     * is what separates them, and it can be asserted without watching anything,
     * because an accepted set of bounds falls through to the maxPolls check and
     * is refused there instead, with a different message.
     *
     * @param array<string, int> $arguments
     */
    #[DataProvider('acceptedBounds')]
    public function testRunAcceptsEveryRangeAtItsBounds(array $arguments): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, ...[...$arguments, 'maxPolls' => 0]),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString(
            'maxPolls must be positive',
            $error->getMessage(),
            'These bounds are legal, so the refusal must come from the next guard: ' . json_encode($arguments),
        );
    }

    /** @return iterable<string, array{0: array<string, int>}> */
    public static function acceptedBounds(): iterable
    {
        yield 'poll floor' => [['pollMs' => 1]];
        yield 'poll ceiling' => [['pollMs' => 60_000]];
        yield 'debounce floor' => [['debounceMs' => 0]];
        yield 'debounce ceiling' => [['debounceMs' => 60_000]];
        yield 'queue floor' => [['maxQueue' => 1]];
        yield 'queue ceiling' => [['maxQueue' => 10_000]];
    }

    public function testRunRejectsZeroPollMs(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, pollMs: 0, maxPolls: 0),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Watch poll, debounce, or queue limit is invalid', $error->getMessage());
    }

    public function testRunRejectsPollMsAboveSixtyThousand(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, pollMs: 60_001, maxPolls: 0),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Watch poll, debounce, or queue limit is invalid', $error->getMessage());
    }

    public function testRunRejectsNegativeDebounceMs(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, debounceMs: -1, maxPolls: 0),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Watch poll, debounce, or queue limit is invalid', $error->getMessage());
    }

    public function testRunRejectsDebounceMsAboveSixtyThousand(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, debounceMs: 60_001, maxPolls: 0),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Watch poll, debounce, or queue limit is invalid', $error->getMessage());
    }

    public function testRunRejectsMaxQueueZero(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, maxQueue: 0, maxPolls: 0),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Watch poll, debounce, or queue limit is invalid', $error->getMessage());
    }

    public function testRunRejectsMaxQueueAboveTenThousand(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, maxQueue: 10_001, maxPolls: 0),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Watch poll, debounce, or queue limit is invalid', $error->getMessage());
    }

    public function testRunRejectsZeroMaxPollsWhenProvided(): void
    {
        $service = self::serviceWith([self::ROOT]);

        $error = captureThrows(
            static fn () => $service->run(self::ROOT, maxPolls: 0),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('maxPolls must be positive when provided', $error->getMessage());
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(WatchService::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}