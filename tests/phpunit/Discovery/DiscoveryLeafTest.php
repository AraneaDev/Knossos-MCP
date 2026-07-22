<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use InvalidArgumentException;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryDiagnostic;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\ProjectUnit;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Direct tests for the 4 Discovery leaf-tier files:
 *
 *   - src/Discovery/DiscoveryConfig.php    (mut-active, constructor with throws)
 *   - src/Discovery/DiscoveryResult.php    (mut-active, promoted-props DTO)
 *   - src/Discovery/DiscoveryDiagnostic.php (mut-active, promoted-props DTO)
 *   - src/Discovery/DiscoveryException.php (structural-infimum, extends RuntimeException)
 *
 * Per the close-out doc § 9, Batch 11a covers these 4 leaf files as
 * the smallest low-interdependency tier of the Discovery module
 * (batches 11b/c/d cover DTOs/guards/ProjectDiscoverer).
 *
 * Conventions match batches 1-10c: bare global helpers from
 * `tests/phpunit/Support/Assertions.php` (assertSame / assertNotSame /
 * assertContains / assertArrayContains / assertThrows / captureThrows /
 * canonicalJsonValue); class-level `#[Group('discovery-leaf')]`.
 * NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('discovery-leaf')]
final class DiscoveryLeafTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    // ===== DiscoveryConfig ==================================================

    public function testDiscoveryConfigConstructorStoresPromotedProperties(): void
    {
        // M1 / DiscoveryConfig::__construct() success arm: all 4 promoted
        // properties assigned correctly.
        $config = new DiscoveryConfig(
            allowedRoots: ['/repo/a', '/repo/b'],
            ignorePatterns: ['*.tmp', 'node_modules/'],
            maxFiles: 50_000,
            maxFileBytes: 1_000_000,
        );
        assertSame(['/repo/a', '/repo/b'], $config->allowedRoots);
        assertSame(['*.tmp', 'node_modules/'], $config->ignorePatterns);
        assertSame(50_000, $config->maxFiles);
        assertSame(1_000_000, $config->maxFileBytes);
    }

    public function testDiscoveryConfigDefaultsIgnorePatternsToEmptyList(): void
    {
        // M2 / DiscoveryConfig::__construct() default arg: ignorePatterns
        // defaults to [] when not provided.
        $config = new DiscoveryConfig(allowedRoots: ['/repo']);
        assertSame([], $config->ignorePatterns);
        assertSame(100_000, $config->maxFiles);
        assertSame(2_000_000, $config->maxFileBytes);
    }

    public function testDiscoveryConfigThrowsOnEmptyAllowedRoots(): void
    {
        // M3 / DiscoveryConfig::__construct() throw arm 1: empty
        // allowedRoots list throws InvalidArgumentException.
        assertThrows(
            fn() => new DiscoveryConfig(allowedRoots: []),
            InvalidArgumentException::class,
        );
    }

    public function testDiscoveryConfigThrowsOnZeroMaxFiles(): void
    {
        // M4 / DiscoveryConfig::__construct() throw arm 2a: maxFiles < 1
        // throws InvalidArgumentException.
        assertThrows(
            fn() => new DiscoveryConfig(allowedRoots: ['/repo'], maxFiles: 0),
            InvalidArgumentException::class,
        );
    }

    public function testDiscoveryConfigThrowsOnZeroMaxFileBytes(): void
    {
        // M5 / DiscoveryConfig::__construct() throw arm 2b: maxFileBytes < 1
        // throws InvalidArgumentException.
        assertThrows(
            fn() => new DiscoveryConfig(allowedRoots: ['/repo'], maxFileBytes: 0),
            InvalidArgumentException::class,
        );
    }

    public function testDiscoveryConfigThrowsOnNegativeLimits(): void
    {
        // M6 / DiscoveryConfig::__construct() throw arm 2c: negative
        // limits (maxFiles = -1, maxFileBytes = -1) also throw via the
        // same guard line.
        assertThrows(
            fn() => new DiscoveryConfig(allowedRoots: ['/repo'], maxFiles: -1),
            InvalidArgumentException::class,
        );
        assertThrows(
            fn() => new DiscoveryConfig(allowedRoots: ['/repo'], maxFileBytes: -1),
            InvalidArgumentException::class,
        );
    }

    // ===== DiscoveryResult =================================================

    public function testDiscoveryResultConstructorStoresPromotedProperties(): void
    {
        // M7 / DiscoveryResult::__construct() success arm: all 6 promoted
        // properties assigned correctly.
        $result = new DiscoveryResult(
            rootRealpath: '/repo',
            files: [],
            units: [],
            diagnostics: [],
            inputHash: 'input-hash-1',
            configurationHash: 'config-hash-1',
        );
        assertSame('/repo', $result->rootRealpath);
        assertSame([], $result->files);
        assertSame([], $result->units);
        assertSame([], $result->diagnostics);
        assertSame('input-hash-1', $result->inputHash);
        assertSame('config-hash-1', $result->configurationHash);
    }

    public function testDiscoveryResultAcceptsListOfDiscoveredFilesAndProjectUnits(): void
    {
        // M8 / DiscoveryResult::__construct() shape assertion: the
        // `files` and `units` properties accept list<DiscoveredFile> and
        // list<ProjectUnit> arrays. We construct valid placeholder
        // DiscoveredFile + ProjectUnit entries with all required args
        // (per their actual signatures read inline:
        // DiscoveredFile has 7 props including absolutePath / language /
        // mtime / contentHash; ProjectUnit has 4 props including
        // contentHash before the default metadata=[]).
        $discovered = new DiscoveredFile(
            relativePath: 'src/Foo.php',
            absolutePath: '/repo/src/Foo.php',
            language: 'php',
            size: 42,
            mtime: 1_700_000_000,
            contentHash: 'abc123',
            // lineCount defaults to 0
        );
        $unit = new ProjectUnit(
            kind: 'composer',
            configPath: 'composer.json',
            contentHash: 'unit-hash-1',
            metadata: ['name' => 'foo/bar'],
        );
        $diagnostic = new DiscoveryDiagnostic(
            severity: 'warning',
            code: 'large-file',
            message: 'File exceeds preferred size',
        );
        $result = new DiscoveryResult(
            rootRealpath: '/repo',
            files: [$discovered],
            units: [$unit],
            diagnostics: [$diagnostic],
            inputHash: 'h1',
            configurationHash: 'h2',
        );
        assertSame(1, count($result->files));
        assertSame('src/Foo.php', $result->files[0]->relativePath);
        assertSame(1, count($result->units));
        assertSame('composer.json', $result->units[0]->configPath);
        assertSame(1, count($result->diagnostics));
        assertSame('large-file', $result->diagnostics[0]->code);
    }

    // ===== DiscoveryDiagnostic =============================================

    public function testDiscoveryDiagnosticConstructorStoresPromotedProperties(): void
    {
        // M9 / DiscoveryDiagnostic::__construct() success arm: all 4
        // promoted properties assigned correctly (5th is default null).
        $diag = new DiscoveryDiagnostic(
            severity: 'error',
            code: 'invalid-extension',
            message: 'File extension is not supported',
            relativePath: 'src/Bad.exe',
        );
        assertSame('error', $diag->severity);
        assertSame('invalid-extension', $diag->code);
        assertSame('File extension is not supported', $diag->message);
        assertSame('src/Bad.exe', $diag->relativePath);
    }

    public function testDiscoveryDiagnosticDefaultsRelativePathToNull(): void
    {
        // M10 / DiscoveryDiagnostic::__construct() default arg:
        // relativePath defaults to null when not provided.
        $diag = new DiscoveryDiagnostic(
            severity: 'info',
            code: 'cache-hit',
            message: 'Used cached fingerprint',
        );
        assertSame(null, $diag->relativePath);
    }

    // ===== DiscoveryException ===============================================

    public function testDiscoveryExceptionIsRuntimeExceptionSubclass(): void
    {
        // M11 / DiscoveryException structural-infimum: extends
        // RuntimeException. Verifies the class hierarchy with no body
        // mutations to test against (per batch 7 classification rule:
        // empty-body extends-Exception classes are structural-infimum).
        assertSame(true, is_subclass_of(DiscoveryException::class, RuntimeException::class));
        assertSame(true, is_subclass_of(DiscoveryException::class, \Throwable::class));
    }

    public function testDiscoveryExceptionAcceptsMessageAndCanBeThrown(): void
    {
        // M12 / DiscoveryException instantiation + throw: the empty-body
        // class still accepts a message via its inherited parent
        // constructor and can be thrown / caught as RuntimeException.
        // Use a traditional closure with explicit void return type for
        // clarity over the arrow-function-with-throw form.
        assertThrows(
            function (): void {
                throw new DiscoveryException('discovery limit exceeded');
            },
            RuntimeException::class,
        );
    }

    public function testDiscoveryExceptionMessagePreservedFromConstructor(): void
    {
        // M13 / DiscoveryException message preservation: instanceof
        // check + getMessage() call. Verifies that the standard
        // RuntimeException constructor accepts the message arg.
        $exception = new DiscoveryException('disk full');
        assertSame(true, $exception instanceof DiscoveryException);
        assertSame('disk full', $exception->getMessage());
    }
}
