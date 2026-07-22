<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use Knossos\Scanner\Protocol\Confidence;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class ConfidenceTest extends TestCase
{
    public function testCertainHasExpectedValue(): void
    {
        assertSame('certain', Confidence::Certain->value);
    }

    public function testProbableHasExpectedValue(): void
    {
        assertSame('probable', Confidence::Probable->value);
    }

    public function testPossibleHasExpectedValue(): void
    {
        assertSame('possible', Confidence::Possible->value);
    }

    public function testFromValidStringSucceeds(): void
    {
        assertSame(Confidence::Certain, Confidence::from('certain'));
        assertSame(Confidence::Probable, Confidence::from('probable'));
        assertSame(Confidence::Possible, Confidence::from('possible'));
    }
}
