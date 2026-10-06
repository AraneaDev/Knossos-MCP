<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Discovery\DiscoveryException;
use Knossos\Mcp\ToolErrorMapper;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class ToolErrorMapperTest extends KnossosTestCase
{
    /** A message written for the caller is kept; anything else is logged and replaced. */
    #[Group('mcp')]
    public function testOnlyCallerFacingMessagesSurvive(): void
    {
        assertSame('KNOSSOS_UNSAFE_PATH', ToolErrorMapper::code(new DiscoveryException('outside the roots')));
        assertSame('outside the roots', ToolErrorMapper::publicMessage(new DiscoveryException('outside the roots')));
        assertSame('KNOSSOS_INVALID_ARGUMENT', ToolErrorMapper::code(new InvalidArgumentException('bad limit')));

        $message = '';
        $logged = $this->errorLogOf(function () use (&$message): void {
            $message = ToolErrorMapper::publicMessage(new RuntimeException('SQLSTATE[HY000]: /srv/internal/db is locked'));
        });

        assertSame(ToolErrorMapper::GENERIC_MESSAGE, $message);
        assertSame('KNOSSOS_TOOL_ERROR', ToolErrorMapper::code(new RuntimeException('x')));
        assertSame(true, str_contains($logged, 'SQLSTATE[HY000]'));
    }
}
