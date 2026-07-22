<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ScanAnalysis;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-analysis')]
final class ScanAnalysisTest extends TestCase
{
    public function testConstructorAssignsAllFieldsViaNamedArgs(): void
    {
        $classifications = [$this->fakeObject('classification'), $this->fakeObject('classification')];
        $boundaries = [$this->fakeObject('boundary')];

        $analysis = new ScanAnalysis(
            classifications: $classifications,
            boundaries: $boundaries,
        );

        assertSame($classifications, $analysis->classifications);
        assertSame($boundaries, $analysis->boundaries);
    }

    public function testConstructorAssignsAllFieldsViaPositionalArgs(): void
    {
        $classifications = [$this->fakeObject('classification')];
        $boundaries = [];

        $analysis = new ScanAnalysis($classifications, $boundaries);

        assertSame($classifications, $analysis->classifications);
        assertSame([], $analysis->boundaries);
    }

    public function testReadonlyFieldsCannotBeReassigned(): void
    {
        $analysis = new ScanAnalysis([], []);

        // Reassigning a readonly typed property triggers a PHP Error.
        $error = captureThrows(static function () use ($analysis): void {
            $analysis->classifications = ['hacked'];
        }, \Error::class);

        // The Error is the readonly-reassignment error.
        assertContains('readonly', $error->getMessage());
    }

    public function testEmptyArraysAreAcceptedForBothFields(): void
    {
        $analysis = new ScanAnalysis(classifications: [], boundaries: []);

        assertSame([], $analysis->classifications);
        assertSame([], $analysis->boundaries);
    }

    public function testDistinctListObjectsForClassificationsAndBoundaries(): void
    {
        $classifications = [$this->fakeObject('classification')];
        $boundaries = [$this->fakeObject('boundary'), $this->fakeObject('boundary')];

        $analysis = new ScanAnalysis($classifications, $boundaries);

        assertSame(1, count($analysis->classifications));
        assertSame(2, count($analysis->boundaries));
    }

    private function fakeObject(string $tag): object
    {
        return new class ($tag) {
            public function __construct(public readonly string $tag) {}
        };
    }
}
