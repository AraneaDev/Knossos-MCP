<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use Knossos\Bundle\RedactionMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A redacted bundle used to replace `files.relative_path` only, with an
 * unsalted hash: every other column that spelled a path (TS names, owner keys,
 * boundary matchers, Python module ids) still carried it, and the hash of a
 * guessable path named that path. These pin the map that replaces a
 * discovered path wherever it occurs as a token, with a per-export salt.
 */
#[Group('bundle')]
final class RedactionMapTest extends TestCase
{
    private const SALT = 'a-fixed-salt-for-tests-only-0000';

    public function testAFilePathIsReplacedAndTheSymbolAfterItIsKept(): void
    {
        $map = $this->map(['src/a.ts']);
        $token = $map->token('src/a.ts');

        self::assertNotNull($token);
        self::assertSame($token . '#Foo', $map->scrub('src/a.ts#Foo'));
    }

    public function testATokenHasTheSaltedShapeAndKeepsTheLowerCasedExtension(): void
    {
        $map = $this->map(['src/Deep/Secret.PHP', 'Makefile']);

        $expected = 'redacted/' . substr(hash_hmac('sha256', 'src/Deep/Secret.PHP', self::SALT), 0, 24) . '.php';
        self::assertSame($expected, $map->token('src/Deep/Secret.PHP'));
        self::assertSame('redacted/' . substr(hash_hmac('sha256', 'Makefile', self::SALT), 0, 24), $map->token('Makefile'));
        self::assertNull($map->token('src/unknown.php'));
    }

    public function testAnOwnerKeyKeepsItsScannerPrefix(): void
    {
        $map = $this->map(['src/a.ts']);

        self::assertSame('knossos.typescript:file:' . $map->token('src/a.ts'), $map->scrub('knossos.typescript:file:src/a.ts'));
    }

    public function testALongerPathThatIsNotKnownIsLeftAlone(): void
    {
        $map = $this->map(['src/a.ts']);

        self::assertSame('see src/a.tsx', $map->scrub('see src/a.tsx'));
        self::assertSame('xsrc/a.ts', $map->scrub('xsrc/a.ts'));
    }

    public function testEveryOccurrenceInOneStringIsReplaced(): void
    {
        $map = $this->map(['src/a.ts', 'src/b.ts']);

        self::assertSame(
            sprintf('"%s", \'%s\' (%s) =%s', $map->token('src/a.ts'), $map->token('src/b.ts'), $map->token('src/a.ts'), $map->token('src/b.ts')),
            $map->scrub('"src/a.ts", \'src/b.ts\' (src/a.ts) =src/b.ts'),
        );
    }

    public function testAPythonModuleIdIsReplacedAndItsQualifiedTailKept(): void
    {
        $map = $this->map(['pkg/mod.py'], [$this->module('pkg.mod')]);

        $token = 'redacted_' . substr(hash_hmac('sha256', 'pkg.mod', self::SALT), 0, 24);
        self::assertSame($token . '.Klass::m', $map->scrub('pkg.mod.Klass::m'));
        self::assertSame('py:module:' . $token, $map->scrub('py:module:pkg.mod'));
    }

    public function testAModuleNeverMatchesInsideALongerWordOrAfterADot(): void
    {
        $map = $this->map(['app/__init__.py'], [$this->module('app')]);

        self::assertSame('django.apps', $map->scrub('django.apps'));
        self::assertSame('django.app', $map->scrub('django.app'));
        self::assertSame('apps', $map->scrub('apps'));
        self::assertSame('redacted_' . substr(hash_hmac('sha256', 'app', self::SALT), 0, 24), $map->scrub('app'));
    }

    public function testOnlyPythonModulesThatComeFromAFileAreKeys(): void
    {
        $nodes = [
            ['language' => 'py', 'kind' => 'module', 'canonical_name' => 'from.a.file', 'file_id' => 'f1'],
            ['language' => 'py', 'kind' => 'module', 'canonical_name' => 'external.module', 'file_id' => null],
            ['language' => 'py', 'kind' => 'class', 'canonical_name' => 'a.Klass', 'file_id' => 'f1'],
            ['language' => 'ts', 'kind' => 'module', 'canonical_name' => 'ts.module', 'file_id' => 'f1'],
        ];
        $map = RedactionMap::fromPayload(['files' => [], 'nodes' => $nodes, 'boundaries' => []], self::SALT);

        self::assertNotSame('from.a.file', $map->scrub('from.a.file'));
        self::assertSame('external.module', $map->scrub('external.module'));
        self::assertSame('a.Klass', $map->scrub('a.Klass'));
        self::assertSame('ts.module', $map->scrub('ts.module'));
    }

    public function testAnAncestorDirectoryOfAFileIsReplacedAsAPathOrAQuotedWord(): void
    {
        $map = $this->map(['src/secret/deep/a.ts']);
        $token = 'redacted-dir/' . substr(hash_hmac('sha256', 'src/secret', self::SALT), 0, 24);
        $deeper = 'redacted-dir/' . substr(hash_hmac('sha256', 'src/secret/deep', self::SALT), 0, 24);

        self::assertSame($token . '/', $map->scrub('src/secret/'));
        self::assertSame('"' . $token . '"', $map->scrub('"src/secret"'));
        self::assertSame($deeper . '/b.ts', $map->scrub('src/secret/deep/b.ts'));
        self::assertSame($token, $map->token('src/secret'));
    }

    public function testAOneSegmentAncestorIsAnOrdinaryWordAndStays(): void
    {
        $map = $this->map(['src/a.ts']);

        self::assertNull($map->token('src'));
        self::assertSame('src', $map->scrub('src'));
    }

    public function testADirectoryABoundaryNamesIsReplacedAtAnyDepth(): void
    {
        $boundaries = [
            ['matcher_json' => '{"type":"path_prefix","value":"lib/"}'],
            ['matcher_json' => '{"type":"path_prefix","value":"empty/named/"}'],
            ['matcher_json' => '{"type":"path_prefix","value":""}'],
            ['matcher_json' => '{"type":"namespace_prefix","value":"App\\\\"}'],
            ['matcher_json' => 'not json'],
            ['matcher_json' => '{"type":"path_prefix","value":7}'],
        ];
        $map = RedactionMap::fromPayload(['files' => [], 'nodes' => [], 'boundaries' => $boundaries], self::SALT);

        self::assertSame('redacted-dir/' . substr(hash_hmac('sha256', 'lib', self::SALT), 0, 24) . '/', $map->scrub('lib/'));
        self::assertSame('redacted-dir/' . substr(hash_hmac('sha256', 'empty/named', self::SALT), 0, 24), $map->scrub('empty/named'));
        self::assertSame('App\\', $map->scrub('App\\'));
        self::assertSame('', $map->scrub(''));
    }

    public function testAFileWinsOverAModuleOfTheSameSpellingAndAModuleOverADirectory(): void
    {
        $boundaries = [['matcher_json' => '{"type":"path_prefix","value":"pkg/"}'], ['matcher_json' => '{"type":"path_prefix","value":"bin/tool/"}']];
        $map = RedactionMap::fromPayload(['files' => [['relative_path' => 'bin/tool']], 'nodes' => [$this->module('pkg'), $this->module('bin/tool')], 'boundaries' => $boundaries], self::SALT);

        self::assertStringStartsWith('redacted_', (string) $map->token('pkg'));
        self::assertStringStartsWith('redacted/', (string) $map->token('bin/tool'));
    }

    public function testTheLongestKnownKeyWins(): void
    {
        $map = $this->map(['pkg/mod.py', 'pkg/mod/inner.py'], [$this->module('pkg.mod'), $this->module('pkg.mod.inner')]);

        self::assertSame((string) $map->token('pkg.mod.inner') . '.f', $map->scrub('pkg.mod.inner.f'));
        self::assertSame((string) $map->token('pkg/mod/inner.py'), $map->scrub('pkg/mod/inner.py'));
    }

    public function testTheSameSaltGivesTheSameOutputAndAnotherSaltAnother(): void
    {
        $first = $this->map(['src/a.ts']);
        $again = $this->map(['src/a.ts']);
        $other = RedactionMap::fromPayload(['files' => [['relative_path' => 'src/a.ts']], 'nodes' => [], 'boundaries' => []], 'another-salt');

        self::assertSame($first->scrub('src/a.ts#X'), $again->scrub('src/a.ts#X'));
        self::assertNotSame($first->token('src/a.ts'), $other->token('src/a.ts'));
        self::assertNotSame($first->id('symbol_abc'), $other->id('symbol_abc'));
        self::assertNotSame($first->hashContent(str_repeat('a', 64)), $other->hashContent(str_repeat('a', 64)));
    }

    public function testAnIdKeepsItsPrefixAndLosesItsValue(): void
    {
        $map = $this->map([]);

        self::assertSame('symbol_' . substr(hash_hmac('sha256', 'symbol_abc', self::SALT), 0, 48), $map->id('symbol_abc'));
        self::assertSame('id_' . substr(hash_hmac('sha256', 'f1', self::SALT), 0, 48), $map->id('f1'));
        self::assertSame('id_' . substr(hash_hmac('sha256', '_x', self::SALT), 0, 48), $map->id('_x'));
    }

    public function testAContentHashIsSaltedToAFullLengthDigest(): void
    {
        $hash = hash('sha256', 'contents');

        self::assertSame(hash_hmac('sha256', $hash, self::SALT), $this->map([])->hashContent($hash));
    }

    /**
     * A key index that is scanned per key, or a regex alternation over every
     * key, turns the export of a large project quadratic; this bounds it.
     */
    public function testTwentyThousandKeysBuildAndScrubInBoundedTime(): void
    {
        $files = [];
        for ($index = 0; $index < 20_000; ++$index) {
            $files[] = ['relative_path' => sprintf('src/module%d/sub%d/File%d.ts', $index % 97, $index % 13, $index)];
        }
        $started = hrtime(true);
        $map = RedactionMap::fromPayload(['files' => $files, 'nodes' => [], 'boundaries' => []], self::SALT);
        foreach ($files as $file) {
            $scrubbed = $map->scrub('knossos.typescript:file:' . $file['relative_path'] . '#Symbol');
            self::assertStringNotContainsString($file['relative_path'], $scrubbed);
        }
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertLessThan(20.0, $seconds);
    }

    /**
     * A map over the given file paths and Python module nodes.
     *
     * @param list<string> $paths @param list<array<string, mixed>> $nodes
     */
    private function map(array $paths, array $nodes = []): RedactionMap
    {
        return RedactionMap::fromPayload(['files' => array_map(static fn(string $path): array => ['relative_path' => $path], $paths), 'nodes' => $nodes, 'boundaries' => []], self::SALT);
    }

    /**
     * A Python module node that came from a file.
     *
     * @return array<string, mixed>
     */
    private function module(string $name): array
    {
        return ['language' => 'py', 'kind' => 'module', 'canonical_name' => $name, 'file_id' => 'f1'];
    }
}
