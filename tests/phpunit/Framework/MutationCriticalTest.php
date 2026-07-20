<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\RelativePath;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class MutationCriticalTest extends KnossosTestCase
{
    #[Group('mutation-critical')]
    public function testRelativePathPropertiesRejectTraversalAbsoluteAndMalformedVariants(): void
    {
        $state = 0x5EED1234;
        $next = function () use (&$state): int {
            $state = (int) (($state * 1103515245 + 12345) & 0x7fffffff);
            return $state;
        };
        for ($case = 0; $case < 250; ++$case) {
            $segments = [];
            $count = 1 + ($next() % 6);
            for ($segment = 0; $segment < $count; ++$segment) {
                $segments[] = 'part-' . dechex($next());
            }
            $valid = implode('/', $segments);
            RelativePath::assertValid($valid);
            foreach ([
                '', '/' . $valid, 'C:/' . $valid, 'root\\' . $valid,
                $valid . "\0tail", $valid . '/', 'root//' . $valid,
                'root/./' . $valid, 'root/../' . $valid,
            ] as $invalid) {
                assertThrows(fn() => RelativePath::assertValid($invalid), InvalidArgumentException::class);
            }
        }
    }
}
