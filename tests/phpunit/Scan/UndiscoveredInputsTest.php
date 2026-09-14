<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scan\UndiscoveredInputs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Worker reads of files discovery never hashed, accumulated across one scan. */
#[Group('scan')]
final class UndiscoveredInputsTest extends TestCase
{
    public function testMapsAreMergedAndTheSameValueTwiceIsThatValue(): void
    {
        $inputs = new UndiscoveredInputs();

        $inputs->add(['node_modules/dep/index.d.ts' => hash('sha256', 'dep'), 'vendor/missing.py' => null]);
        $inputs->add(['node_modules/dep/index.d.ts' => hash('sha256', 'dep'), 'vendor/missing.py' => null, 'build/big.ts' => hash('sha256', 'big')]);
        $inputs->add([]);

        assertSame(
            ['node_modules/dep/index.d.ts' => hash('sha256', 'dep'), 'vendor/missing.py' => null, 'build/big.ts' => hash('sha256', 'big')],
            $inputs->all(),
        );
    }

    /** @return iterable<string, array{string|null, string|null}> */
    public static function conflicts(): iterable
    {
        yield 'two different hashes' => [hash('sha256', 'before'), hash('sha256', 'after')];
        yield 'a hash, then a failed read' => [hash('sha256', 'before'), null];
        yield 'a failed read, then a hash' => [null, hash('sha256', 'after')];
    }

    #[DataProvider('conflicts')]
    public function testTheSamePathWithTwoValuesFailsTheScan(?string $first, ?string $second): void
    {
        $inputs = new UndiscoveredInputs();
        $inputs->add(['node_modules/dep/index.d.ts' => $first]);

        $error = captureThrows(fn() => $inputs->add(['node_modules/dep/index.d.ts' => $second]), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::inputReadInconsistently('node_modules/dep/index.d.ts')->getMessage(), $error->getMessage());
        assertContains('node_modules/dep/index.d.ts was read more than once during the scan with different results', $error->getMessage());
        // The first value stands: a refused map is not half merged.
        assertSame(['node_modules/dep/index.d.ts' => $first], $inputs->all());
    }

    public function testAConflictingMapIsNotMergedAtAll(): void
    {
        $inputs = new UndiscoveredInputs();
        $inputs->add(['b.ts' => hash('sha256', 'b')]);

        captureThrows(fn() => $inputs->add(['a.ts' => hash('sha256', 'a'), 'b.ts' => null]), ScanSnapshotChangedException::class);

        assertSame(['b.ts' => hash('sha256', 'b')], $inputs->all());
    }
}
