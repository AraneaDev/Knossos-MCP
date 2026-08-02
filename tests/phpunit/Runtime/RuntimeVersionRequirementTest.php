<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\RuntimeVersionRequirement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('doctor-service')]
final class RuntimeVersionRequirementTest extends TestCase
{
    public function testAVersionAtTheMinimumIsAccepted(): void
    {
        $requirement = new RuntimeVersionRequirement('Node', '/^v(\d+)\./', '22');

        assertSame('v22.0.0', $requirement->verify('v22.0.0'));
    }

    public function testAVersionAboveTheMinimumIsAccepted(): void
    {
        $requirement = new RuntimeVersionRequirement('Node', '/^v(\d+)\./', '22');

        assertSame('v24.4.0', $requirement->verify('v24.4.0'));
    }

    public function testAVersionNewerThanAnythingReleasedTodayIsStillAccepted(): void
    {
        // The requirement is a floor, not a range: a runtime newer than the one
        // this release was built against must not be reported as broken.
        $requirement = new RuntimeVersionRequirement('Node', '/^v(\d+)\./', '22');

        assertSame('v99.1.0', $requirement->verify('v99.1.0'));
    }

    public function testAVersionBelowTheMinimumIsRejected(): void
    {
        $requirement = new RuntimeVersionRequirement('Node', '/^v(\d+)\./', '22');

        $error = self::assertThrowsWith(static fn() => $requirement->verify('v20.11.1'), \RuntimeException::class);

        $this->assertStringContainsString('v20.11.1', $error->getMessage());
        $this->assertStringContainsString('Node 22 or newer is required', $error->getMessage());
    }

    public function testAnUnparsableVersionIsRejected(): void
    {
        $requirement = new RuntimeVersionRequirement('Python', '/^Python (\d+\.\d+)\./', '3.11');

        $error = self::assertThrowsWith(static fn() => $requirement->verify('command not found'), \RuntimeException::class);

        $this->assertStringContainsString('could not be determined', $error->getMessage());
    }

    public function testAMinorVersionBelowTheFloorOfTheSameMajorIsRejected(): void
    {
        // 8.2 < 8.3 has to be compared as a version, not as a major number, or
        // the PHP floor would accept every 8.x release.
        $requirement = new RuntimeVersionRequirement('PHP', '/^(\d+\.\d+)\./', '8.3');

        self::assertThrowsWith(static fn() => $requirement->verify('8.2.29'), \RuntimeException::class);
    }

    public function testAMinorVersionAboveTheFloorOfTheSameMajorIsAccepted(): void
    {
        $requirement = new RuntimeVersionRequirement('PHP', '/^(\d+\.\d+)\./', '8.3');

        assertSame('8.5.1', $requirement->verify('8.5.1'));
    }

    public function testAnOlderMinorIsRejectedEvenThoughItSortsHigherAsAString(): void
    {
        // '3.9' > '3.11' lexically, so a string comparison would wrongly accept
        // Python 3.9. The floor has to be compared as a version.
        $requirement = new RuntimeVersionRequirement('Python', '/^Python (\d+\.\d+)\./', '3.11');

        self::assertThrowsWith(static fn() => $requirement->verify('Python 3.9.18'), \RuntimeException::class);
    }

    public function testSurroundingWhitespaceIsTrimmedFromTheAcceptedVersion(): void
    {
        $requirement = new RuntimeVersionRequirement('Python', '/^Python (\d+\.\d+)\./', '3.11');

        assertSame('Python 3.12.3', $requirement->verify("  Python 3.12.3\n"));
    }

    public function testAFutureMajorIsAcceptedRatherThanReadAsUnparsable(): void
    {
        // A floor with no ceiling has to mean it: Python 4 is above 3.11, and
        // failing to parse it would report a working runtime as unreadable.
        $requirement = new RuntimeVersionRequirement('Python', '/^Python (\d+\.\d+)\./', '3.11');

        assertSame('Python 4.0.1', $requirement->verify('Python 4.0.1'));
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $expected
     *
     * @return T
     */
    private static function assertThrowsWith(callable $operation, string $expected): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            self::assertInstanceOf($expected, $error);

            return $error;
        }
        self::fail(sprintf('Expected %s to be thrown.', $expected));
    }
}
