<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\GitIgnoreRules;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The patterns a `.gitignore` holds, applied the way git applies them.
 *
 * A file git ignores is not the project's source: a framework cache, a local
 * tool's state, a generated client, a second checkout parked under the tree.
 * Discovery read none of those files, so a scan walked and graphed every one
 * of them, and dead-code analysis reported each as unreferenced code.
 */
#[Group('discovery')]
final class GitIgnoreRulesTest extends KnossosTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public static function rootPatterns(): iterable
    {
        yield 'a bare name matches a file at any depth' => ['*.log', 'a/b/debug.log', false, true];
        yield 'a bare name matches a directory at any depth' => ['cache', 'var/cache', true, true];
        yield 'a bare name does not match a longer name' => ['cache', 'var/caches', true, false];
        yield 'a leading slash anchors to the file\'s directory' => ['/var/', 'var', true, true];
        yield 'an anchored name does not match deeper' => ['/var/', 'app/var', true, false];
        yield 'a middle slash anchors too' => ['src/wasm/pkg/', 'src/wasm/pkg', true, true];
        yield 'a middle-slash pattern does not match below the root' => ['src/gen', 'lib/src/gen', true, false];
        yield 'a trailing slash matches only directories' => ['build-out/', 'build-out', false, false];
        yield 'a trailing slash matches the directory' => ['build-out/', 'build-out', true, true];
        yield 'a star does not cross a slash' => ['/public/*', 'public/assets', true, true];
        yield 'a star does not reach two levels down' => ['/public/*.js', 'public/js/app.js', false, false];
        yield 'a leading double star matches at any depth' => ['**/generated', 'a/b/generated', true, true];
        yield 'a middle double star spans segments' => ['docs/**/api', 'docs/v1/v2/api', true, true];
        yield 'a middle double star spans zero segments' => ['docs/**/api', 'docs/api', true, true];
        yield 'a trailing double star matches everything inside' => ['tmp/**', 'tmp/a/b.ts', false, true];
        yield 'a question mark matches one character' => ['file?.ts', 'file1.ts', false, true];
        yield 'a class matches one of its characters' => ['file[0-9].ts', 'file7.ts', false, true];
        yield 'a negated class excludes its characters' => ['file[!0-9].ts', 'file7.ts', false, false];
        yield 'a comment is not a pattern' => ['# cache', '# cache', false, false];
        yield 'an escaped hash is a literal' => ['\\#notes.ts', '#notes.ts', false, true];
        yield 'trailing spaces are dropped' => ["cache   ", 'cache', true, true];
    }

    #[DataProvider('rootPatterns')]
    public function testRootPatternsFollowGitSemantics(string $pattern, string $path, bool $isDirectory, bool $expected): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('', $pattern . "\n");

        self::assertSame($expected, $rules->ignores($path, $isDirectory));
    }

    public function testTheLastMatchingPatternInAFileWins(): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('', "*.ts\n!keep.ts\n");

        self::assertTrue($rules->ignores('src/drop.ts', false));
        self::assertFalse($rules->ignores('src/keep.ts', false));
    }

    public function testANestedFileAppliesRelativeToItsOwnDirectory(): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('web', "styled-system\n/local/\n");

        self::assertTrue($rules->ignores('web/styled-system', true));
        self::assertTrue($rules->ignores('web/app/styled-system', true));
        self::assertTrue($rules->ignores('web/local', true));
        self::assertFalse($rules->ignores('web/app/local', true));
        // Outside its directory it says nothing.
        self::assertFalse($rules->ignores('api/styled-system', true));
        self::assertFalse($rules->ignores('webstyled-system', true));
    }

    public function testADeeperFileOverridesAShallowerOne(): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('', "*.gen.ts\n");
        $rules->add('src/keep', "!*.gen.ts\n");

        self::assertTrue($rules->ignores('src/other/a.gen.ts', false));
        self::assertFalse($rules->ignores('src/keep/a.gen.ts', false));
    }

    public function testAnIgnoreEverythingFileKeepsItsOwnReinclusion(): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('storage/views', "*\n!.gitignore\n");

        self::assertTrue($rules->ignores('storage/views/abc.php', false));
        self::assertFalse($rules->ignores('storage/views/.gitignore', false));
    }

    /**
     * Git never looks inside an ignored directory, so a pattern cannot
     * re-include a file below one. The drift probe asks about single paths
     * rather than walking, so it needs the ancestor check the walk gets for free.
     */
    public function testAPathBelowAnIgnoredDirectoryIsIgnoredWhateverItsOwnRulesSay(): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('', "/var/\n!/var/keep.php\n");

        self::assertTrue($rules->ignoresWithAncestors('var/keep.php', false));
        self::assertTrue($rules->ignoresWithAncestors('var/cache/dev/a.php', false));
        self::assertFalse($rules->ignoresWithAncestors('src/var.php', false));
    }

    public function testAnUnterminatedClassIsSkippedRatherThanFailingTheWalk(): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('', "file[.ts\ncache\n");

        self::assertFalse($rules->ignores('file[.ts', false));
        self::assertTrue($rules->ignores('cache', true));
    }

    public function testCarriageReturnLineEndingsAreAccepted(): void
    {
        $rules = new GitIgnoreRules();
        $rules->add('', "cache\r\n/out/\r\n");

        self::assertTrue($rules->ignores('cache', true));
        self::assertTrue($rules->ignores('out', true));
    }
}
