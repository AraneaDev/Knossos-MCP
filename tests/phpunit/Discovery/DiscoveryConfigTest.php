<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use InvalidArgumentException;
use Knossos\Discovery\DiscoveryConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('discovery-config')]
final class DiscoveryConfigTest extends TestCase
{
    public function testConstructorAssignsAllFieldsAndDefaults(): void
    {
        $config = new DiscoveryConfig(
            allowedRoots: ['/projects'],
            ignorePatterns: ['*.bak'],
            maxFiles: 50_000,
            maxFileBytes: 1_000_000,
        );

        assertSame(['/projects'], $config->allowedRoots);
        assertSame(['*.bak'], $config->ignorePatterns);
        assertSame(50_000, $config->maxFiles);
        assertSame(1_000_000, $config->maxFileBytes);
    }

    public function testDefaultsAreAppliedWhenOptionalFieldsOmitted(): void
    {
        $config = new DiscoveryConfig(allowedRoots: ['/projects']);

        assertSame(['/projects'], $config->allowedRoots);
        assertSame([], $config->ignorePatterns);
        assertSame(100_000, $config->maxFiles);
        assertSame(2_000_000, $config->maxFileBytes);
    }

    public function testEmptyAllowedRootsThrows(): void
    {
        $error = captureThrows(
            static fn () => new DiscoveryConfig(allowedRoots: []),
            InvalidArgumentException::class,
        );

        assertSame('At least one allowed root is required.', $error->getMessage());
    }

    public function testZeroMaxFilesThrows(): void
    {
        $error = captureThrows(
            static fn () => new DiscoveryConfig(allowedRoots: ['/projects'], maxFiles: 0),
            InvalidArgumentException::class,
        );

        assertSame('Discovery limits must be positive.', $error->getMessage());
    }

    public function testNegativeMaxFilesThrows(): void
    {
        $error = captureThrows(
            static fn () => new DiscoveryConfig(allowedRoots: ['/projects'], maxFiles: -10),
            InvalidArgumentException::class,
        );

        assertSame('Discovery limits must be positive.', $error->getMessage());
    }

    public function testZeroMaxFileBytesThrows(): void
    {
        $error = captureThrows(
            static fn () => new DiscoveryConfig(allowedRoots: ['/projects'], maxFileBytes: 0),
            InvalidArgumentException::class,
        );

        assertSame('Discovery limits must be positive.', $error->getMessage());
    }

    public function testNegativeMaxFileBytesThrows(): void
    {
        $error = captureThrows(
            static fn () => new DiscoveryConfig(allowedRoots: ['/projects'], maxFileBytes: -100),
            InvalidArgumentException::class,
        );

        assertSame('Discovery limits must be positive.', $error->getMessage());
    }

    public function testBoundaryValuesAreAcceptedAt1(): void
    {
        // 1 is the minimum allowed value (≥ 1).
        $config = new DiscoveryConfig(allowedRoots: ['/projects'], maxFiles: 1, maxFileBytes: 1);

        assertSame(1, $config->maxFiles);
        assertSame(1, $config->maxFileBytes);
    }

    public function testCustomIgnorePatternsAreStoredAsGiven(): void
    {
        $patterns = ['*.tmp', '*.bak', 'temp/**'];

        $config = new DiscoveryConfig(allowedRoots: ['/projects'], ignorePatterns: $patterns);

        assertSame($patterns, $config->ignorePatterns);
    }

    public function testMultipleAllowedRootsAreAllStored(): void
    {
        $roots = ['/projects', '/scratch', '/workspace'];

        $config = new DiscoveryConfig(allowedRoots: $roots);

        assertSame($roots, $config->allowedRoots);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(DiscoveryConfig::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}
