<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\JsTestRunner;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/**
 * A JavaScript test is run by the runner its nearest package.json names:
 * Vitest or Jest from its dependencies or its test script, nothing when that
 * is ambiguous or unknown, so no command is guessed for it.
 */
final class JsTestRunnerTest extends KnossosTestCase
{
    /** Each case: the package.json files by directory, then the expected runner of every test. */
    #[Group('query')]
    public function testTheNearestPackageJsonNamesTheRunner(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        try {
            $this->package($root, '', ['devDependencies' => ['vitest' => '^3']]);
            $this->package($root, 'packages/legacy', ['devDependencies' => ['jest' => '^29']]);
            $this->package($root, 'packages/scripted', ['scripts' => ['test' => 'jest --ci']]);
            $this->package($root, 'packages/both', ['devDependencies' => ['jest' => '^29', 'vitest' => '^3']]);
            $this->package($root, 'packages/both-scripted', ['devDependencies' => ['jest' => '^29', 'vitest' => '^3'], 'scripts' => ['test' => 'vitest run']]);
            $this->package($root, 'packages/mocha', ['scripts' => ['test' => 'mocha']]);
            $this->package($root, 'packages/plain', ['name' => 'plain']);

            $tests = [
                ['path' => 'src/a.spec.ts', 'distance' => 1],
                ['path' => 'packages/legacy/src/b.test.js', 'distance' => 1],
                ['path' => 'packages/scripted/c.test.tsx', 'distance' => 2],
                ['path' => 'packages/both/d.spec.ts', 'distance' => 2],
                ['path' => 'packages/both-scripted/e.spec.ts', 'distance' => 2],
                ['path' => 'packages/mocha/f.spec.mjs', 'distance' => 3],
                ['path' => 'packages/plain/g.spec.ts', 'distance' => 3],
                ['path' => 'tests/GreeterTest.php', 'distance' => 1],
            ];

            assertSame(
                [
                    ['path' => 'src/a.spec.ts', 'distance' => 1, 'js_runner' => 'vitest'],
                    ['path' => 'packages/legacy/src/b.test.js', 'distance' => 1, 'js_runner' => 'jest'],
                    ['path' => 'packages/scripted/c.test.tsx', 'distance' => 2, 'js_runner' => 'jest'],
                    ['path' => 'packages/both/d.spec.ts', 'distance' => 2, 'js_runner' => null],
                    ['path' => 'packages/both-scripted/e.spec.ts', 'distance' => 2, 'js_runner' => 'vitest'],
                    ['path' => 'packages/mocha/f.spec.mjs', 'distance' => 3, 'js_runner' => null],
                    // A package.json that names no runner and no test script leaves it to the one above.
                    ['path' => 'packages/plain/g.spec.ts', 'distance' => 3, 'js_runner' => 'vitest'],
                    ['path' => 'tests/GreeterTest.php', 'distance' => 1],
                ],
                JsTestRunner::annotate($root, $tests),
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** No package.json up to the project root, or one that is not JSON: unknown. */
    #[Group('query')]
    public function testNoReadablePackageJsonIsUnknown(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        try {
            mkdir($root . '/broken', 0o777, true);
            file_put_contents($root . '/broken/package.json', '{not json');
            assertSame(
                [['path' => 'a.spec.ts', 'distance' => 1, 'js_runner' => null], ['path' => 'broken/b.spec.ts', 'distance' => 1, 'js_runner' => null]],
                JsTestRunner::annotate($root, [['path' => 'a.spec.ts', 'distance' => 1], ['path' => 'broken/b.spec.ts', 'distance' => 1]]),
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Writes `$manifest` as `$directory`'s package.json under `$root`.
     *
     * @param array<string, mixed> $manifest
     */
    private function package(string $root, string $directory, array $manifest): void
    {
        $dir = rtrim($root . '/' . $directory, '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir . '/package.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
