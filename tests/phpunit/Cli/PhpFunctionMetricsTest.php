<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Function spans and complexity as the maintainability budgets need them. */
final class PhpFunctionMetricsTest extends KnossosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once self::repositoryRoot() . '/tools/lib/php-function-metrics.php';
    }

    /** "{$x}" inside a string opens a brace that closes with a plain "}", so it must count as an opener. */
    #[Group('quality-tools')]
    public function testInterpolatedBracesDoNotEndTheFunctionEarly(): void
    {
        $source = "<?php\nfunction f(\$a) {\n    \$s = \"x{\$a}y\";\n    \$t = \"\${a}\";\n    if (\$a) {\n        return 1;\n    }\n    return 2;\n}\nfunction g() {\n    return 3;\n}\n";

        $metrics = \phpFunctionMetrics($source, 'x.php');
        $byName = array_column($metrics, null, 'symbol');

        self::assertSame(8, $byName['f']['lines']);
        self::assertSame(2, $byName['f']['complexity']);
        self::assertSame(3, $byName['g']['lines']);
    }

    /** An abstract method has no body, so it must not claim the next function's braces. */
    #[Group('quality-tools')]
    public function testABodilessMethodDoesNotStealTheNextBody(): void
    {
        $source = "<?php\nabstract class A {\n    abstract function a(): int;\n    function b() {\n        return 1;\n    }\n}\n";

        $byName = array_column(\phpFunctionMetrics($source, 'x.php'), null, 'symbol');

        self::assertArrayNotHasKey('a', $byName);
        self::assertSame(3, $byName['b']['lines']);
    }
}
