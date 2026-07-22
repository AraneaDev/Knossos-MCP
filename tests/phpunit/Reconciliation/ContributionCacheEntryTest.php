<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use InvalidArgumentException;
use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scanner\Protocol\ScanContribution;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('contribution-cache-entry')]
final class ContributionCacheEntryTest extends TestCase
{
    private static function buildEntry(array $overrides = []): ContributionCacheEntry
    {
        $args = array_merge([
            'filePath' => 'src/Foo.php',
            'contentHash' => 'h1',
            'scannerId' => 'test.knossos',
            'scannerVersion' => '0.1.0',
            'configurationHash' => 'c1',
            'contribution' => new ScanContribution('test.knossos:file:src/Foo.php'),
        ], $overrides);

        return new ContributionCacheEntry(
            $args['filePath'],
            $args['contentHash'],
            $args['scannerId'],
            $args['scannerVersion'],
            $args['configurationHash'],
            $args['contribution'],
        );
    }

    // ----- class shape -----

    public function testClassIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(ContributionCacheEntry::class))->isFinal());
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new ReflectionClass(ContributionCacheEntry::class))->isReadOnly());
    }

    public function testClassHasExactlySixPublicProperties(): void
    {
        $ref = new ReflectionClass(ContributionCacheEntry::class);
        $publicProps = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $ref->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        assertSame(
            ['filePath', 'contentHash', 'scannerId', 'scannerVersion', 'configurationHash', 'contribution'],
            $publicProps,
        );
    }

    // ----- happy path -----

    public function testConstructorStoresAllProperties(): void
    {
        $contribution = new ScanContribution('test.knossos:file:src/Foo.php');
        $entry = new ContributionCacheEntry(
            filePath: 'src/Foo.php',
            contentHash: 'hash-c',
            scannerId: 'test.knossos',
            scannerVersion: '0.1.0',
            configurationHash: 'config-h',
            contribution: $contribution,
        );

        assertSame('src/Foo.php', $entry->filePath);
        assertSame('hash-c', $entry->contentHash);
        assertSame('test.knossos', $entry->scannerId);
        assertSame('0.1.0', $entry->scannerVersion);
        assertSame('config-h', $entry->configurationHash);
        assertSame($contribution, $entry->contribution);
    }

    // ----- empty-string rejection -----

    public function testThrowsOnEmptyFilePath(): void
    {
        $error = captureThrows(
            static fn () => self::buildEntry(['filePath' => '']),
            InvalidArgumentException::class,
        );
        assertSame('Contribution cache metadata must not be empty.', $error->getMessage());
    }

    public function testThrowsOnEmptyContentHash(): void
    {
        $error = captureThrows(
            static fn () => self::buildEntry(['contentHash' => '']),
            InvalidArgumentException::class,
        );
        assertSame('Contribution cache metadata must not be empty.', $error->getMessage());
    }

    public function testThrowsOnEmptyScannerId(): void
    {
        $error = captureThrows(
            static fn () => self::buildEntry(['scannerId' => '']),
            InvalidArgumentException::class,
        );
        assertSame('Contribution cache metadata must not be empty.', $error->getMessage());
    }

    public function testThrowsOnEmptyScannerVersion(): void
    {
        $error = captureThrows(
            static fn () => self::buildEntry(['scannerVersion' => '']),
            InvalidArgumentException::class,
        );
        assertSame('Contribution cache metadata must not be empty.', $error->getMessage());
    }

    public function testThrowsOnEmptyConfigurationHash(): void
    {
        $error = captureThrows(
            static fn () => self::buildEntry(['configurationHash' => '']),
            InvalidArgumentException::class,
        );
        assertSame('Contribution cache metadata must not be empty.', $error->getMessage());
    }

    // ----- foreach order independence -----

    public function testThrowsExactlyOnceWhenMultipleFieldsAreEmpty(): void
    {
        // The foreach loop throws on the FIRST empty value it encounters and aborts.
        // Setting multiple fields empty must produce a single exception (not multiple)
        // with the canonical message. This kills mutations like `continue` instead of
        // `throw` that would let the loop iterate further, or aggregate-error mutations.
        $error = captureThrows(
            static fn () => self::buildEntry([
                'filePath' => '',
                'contentHash' => '',
                'scannerId' => '',
                'scannerVersion' => '',
                'configurationHash' => '',
            ]),
            InvalidArgumentException::class,
        );
        assertSame('Contribution cache metadata must not be empty.', $error->getMessage());
    }

    public function testDoesNotThrowWhenAllStringsAreNonEmpty(): void
    {
        // Reaches the end of the constructor's foreach loop without throwing — proves
        // that the loop terminates normally when no field is empty.
        $entry = self::buildEntry();
        assertSame('src/Foo.php', $entry->filePath);
    }

    public function testAcceptsNonEmptyButUnusualStringValues(): void
    {
        // Kills the `=== ''` mutation to `strlen(...) < 1` / `empty(...)`.
        // NUL byte is a single non-empty character that must NOT trip the
        // `=== ''` strict comparison. Same for whitespace and numeric strings:
        // they are not the empty string and must be accepted.
        foreach (["\0", ' ', '0', 'false'] as $nonEmpty) {
            $entry = self::buildEntry(['contentHash' => $nonEmpty]);
            assertSame($nonEmpty, $entry->contentHash);
        }
    }

    // ----- contribution field is required -----

    public function testContributionPropertyHasNoDefault(): void
    {
        // Kills any mutation that tries to default $contribution to a ScanContribution
        // instance or null. Inspecting the constructor signature directly is the only
        // way to verify the parameter is required.
        $ctor = (new ReflectionClass(ContributionCacheEntry::class))->getConstructor();
        $params = $ctor->getParameters();
        assertSame('contribution', $params[5]->getName());
        assertSame(false, $params[5]->isOptional());
        assertSame(false, $params[5]->allowsNull());
    }

    public function testAllMetadataParametersAreRequiredStringsWithoutDefaults(): void
    {
        // The 5 metadata strings have no defaults and are non-nullable — verifies
        // that no mutation introduces a default or relaxes the required contract.
        $ctor = (new ReflectionClass(ContributionCacheEntry::class))->getConstructor();
        $names = ['filePath', 'contentHash', 'scannerId', 'scannerVersion', 'configurationHash'];
        foreach ($names as $index => $name) {
            $param = $ctor->getParameters()[$index];
            assertSame($name, $param->getName());
            $this->assertFalse($param->isOptional());
            $this->assertFalse($param->allowsNull());
            assertSame('string', (string) $param->getType());
        }
    }
}
