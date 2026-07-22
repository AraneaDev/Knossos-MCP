<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryDiagnostic;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('discovery-diagnostic')]
final class DiscoveryDiagnosticTest extends TestCase
{
    public function testAllFieldsAreStoredAndExposedAsReadonly(): void
    {
        $diagnostic = new DiscoveryDiagnostic(
            severity: 'error',
            code: 'permission_denied',
            message: 'cannot read',
            relativePath: 'src/Foo.php',
        );

        assertSame('error', $diagnostic->severity);
        assertSame('permission_denied', $diagnostic->code);
        assertSame('cannot read', $diagnostic->message);
        assertSame('src/Foo.php', $diagnostic->relativePath);
    }

    public function testRelativePathDefaultsToNullWhenOmitted(): void
    {
        $diagnostic = new DiscoveryDiagnostic(
            severity: 'warning',
            code: 'deprecated',
            message: 'old config',
        );

        assertSame(null, $diagnostic->relativePath);
    }

    public function testRelativePathCanBeExplicitlyNull(): void
    {
        $diagnostic = new DiscoveryDiagnostic(
            severity: 'info',
            code: 'scan_complete',
            message: 'ok',
            relativePath: null,
        );

        assertSame(null, $diagnostic->relativePath);
    }

    public function testEmptyStringsAreStoredAsGiven(): void
    {
        $diagnostic = new DiscoveryDiagnostic(
            severity: '',
            code: '',
            message: '',
            relativePath: '',
        );

        assertSame('', $diagnostic->severity);
        assertSame('', $diagnostic->code);
        assertSame('', $diagnostic->message);
        assertSame('', $diagnostic->relativePath);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(DiscoveryDiagnostic::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}