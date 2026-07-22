<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\LanguageScanResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-result')]
final class LanguageScanResultTest extends TestCase
{
    public function testConstructorAssignsAllFieldsViaNamedArgs(): void
    {
        $manifests = [$this->fakeObject('manifest')];
        $contributions = [$this->fakeObject('contribution')];
        $cacheEntries = [$this->fakeObject('cache')];
        $stageMilliseconds = ['planning' => 3.5, 'analysis' => 12.4];
        $scannerMetadata = ['php' => ['scanner' => 'knossos.php', 'version' => '0.1.0']];

        $result = new LanguageScanResult(
            manifests: $manifests,
            contributions: $contributions,
            cacheEntries: $cacheEntries,
            parsed: 42,
            unchanged: 7,
            added: 3,
            changed: 5,
            scannerMetadata: $scannerMetadata,
            stageMilliseconds: $stageMilliseconds,
        );

        assertSame($manifests, $result->manifests);
        assertSame($contributions, $result->contributions);
        assertSame($cacheEntries, $result->cacheEntries);
        assertSame(42, $result->parsed);
        assertSame(7, $result->unchanged);
        assertSame(3, $result->added);
        assertSame(5, $result->changed);
        assertSame($scannerMetadata, $result->scannerMetadata);
        assertSame($stageMilliseconds, $result->stageMilliseconds);
    }

    public function testConstructorAssignsAllFieldsViaPositionalArgs(): void
    {
        $stage = ['planning' => 1.0];

        $result = new LanguageScanResult([], [], [], 10, 8, 1, 1, [], $stage);

        assertSame([], $result->manifests);
        assertSame([], $result->contributions);
        assertSame([], $result->cacheEntries);
        assertSame(10, $result->parsed);
        assertSame(8, $result->unchanged);
        assertSame(1, $result->added);
        assertSame(1, $result->changed);
        assertSame([], $result->scannerMetadata);
        assertSame($stage, $result->stageMilliseconds);
    }

    public function testReadonlyFieldsCannotBeReassignedForScalarCounters(): void
    {
        $result = new LanguageScanResult([], [], [], 0, 0, 0, 0, [], []);

        $error = captureThrows(static function () use ($result): void {
            $result->parsed = 9999;
        }, \Error::class);

        assertContains('readonly', $error->getMessage());
    }

    public function testReadonlyFieldsCannotBeReassignedForArrayFields(): void
    {
        $result = new LanguageScanResult([], [], [], 0, 0, 0, 0, [], []);

        $error = captureThrows(static function () use ($result): void {
            $result->manifests = ['hacked'];
        }, \Error::class);

        assertContains('readonly', $error->getMessage());
    }

    public function testZeroCountersAndEmptyArraysAreAccepted(): void
    {
        $result = new LanguageScanResult([], [], [], 0, 0, 0, 0, [], []);

        assertSame(0, $result->parsed);
        assertSame(0, $result->unchanged);
        assertSame(0, $result->added);
        assertSame(0, $result->changed);
        assertSame([], $result->scannerMetadata);
        assertSame([], $result->stageMilliseconds);
    }

    private function fakeObject(string $tag): object
    {
        return new class ($tag) {
            public function __construct(public readonly string $tag) {}
        };
    }
}
