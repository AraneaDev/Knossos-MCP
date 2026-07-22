<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class OriginTest extends TestCase
{
    /** @return list<array{string, Origin}> */
    public static function originValueProvider(): array
    {
        return [
            ['ast', Origin::Ast],
            ['composer', Origin::Composer],
            ['package_manifest', Origin::PackageManifest],
            ['config', Origin::Config],
            ['framework_convention', Origin::FrameworkConvention],
            ['derived', Origin::Derived],
            ['user_rule', Origin::UserRule],
        ];
    }

    /** @return list<array{Origin}> */
    public static function originCaseProvider(): array
    {
        return array_map(
            static fn (Origin $case): array => [$case],
            Origin::cases(),
        );
    }

    #[DataProvider('originValueProvider')]
    public function testOriginHasExpectedValue(string $expected, Origin $origin): void
    {
        assertSame($expected, $origin->value);
    }

    #[DataProvider('originCaseProvider')]
    public function testFromValidStringReturnsCorrectCase(Origin $case): void
    {
        assertSame($case, Origin::from($case->value));
    }
}
