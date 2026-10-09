<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use JsonException;
use Knossos\Bundle\BundleRedactor;
use Knossos\Bundle\RedactionMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('bundle')]
final class BundleRedactorTest extends TestCase
{
    /**
     * The salt is the only thing that keeps a token from being reversed by
     * hashing guessed paths. An export that fails part-way must not carry it
     * out in the arguments of its stack trace.
     */
    public function testAFailedRedactionKeepsTheSaltOutOfItsTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $salt = 'salt-that-must-never-be-printed!';
        try {
            BundleRedactor::redact(['files' => [['id' => 'f1', 'relative_path' => 'src/a.php']], 'nodes' => [['id' => 'n1', 'attributes_json' => 'not json']]], false, $salt);
            self::fail('Invalid attributes must stop the export.');
        } catch (JsonException $error) {
            $frames = array_filter($error->getTrace(), static fn(array $frame): bool => str_starts_with($frame['class'] ?? '', 'Knossos\\Bundle\\'));
            self::assertNotSame([], $frames);
            self::assertStringNotContainsString($salt, print_r(array_column($frames, 'args'), true));
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    /**
     * Rows are re-sorted by their new ids, so the order of a redacted table
     * says nothing about the ids it had; memberships by boundary, then node.
     */
    public function testRowsAreOrderedByTheirNewIds(): void
    {
        $salt = str_repeat('k', 32);
        $nodes = array_map(static fn(int $index): array => ['id' => 'symbol_' . $index], range(0, 7));
        $memberships = [];
        foreach (['boundary_a', 'boundary_b'] as $boundary) {
            foreach (range(0, 3) as $index) {
                $memberships[] = ['boundary_id' => $boundary, 'node_id' => 'symbol_' . $index];
            }
        }
        $map = RedactionMap::fromPayload(['files' => [], 'nodes' => [], 'boundaries' => []], $salt);

        $tables = BundleRedactor::redact(['nodes' => $nodes, 'memberships' => $memberships], false, $salt);

        $inInputOrder = array_map(static fn(array $node): string => $map->id($node['id']), $nodes);
        $sorted = $inInputOrder;
        sort($sorted, SORT_STRING);
        self::assertNotSame($sorted, $inInputOrder, 'The input must not already be in the output order.');
        self::assertSame($sorted, array_column($tables['nodes'], 'id'));
        $expected = array_map(static fn(array $row): array => ['boundary_id' => $map->id($row['boundary_id']), 'node_id' => $map->id($row['node_id'])], $memberships);
        usort($expected, static fn(array $left, array $right): int => [$left['boundary_id'], $left['node_id']] <=> [$right['boundary_id'], $right['node_id']]);
        self::assertNotSame($expected, array_map(static fn(array $row): array => ['boundary_id' => $map->id($row['boundary_id']), 'node_id' => $map->id($row['node_id'])], $memberships));
        self::assertSame($expected, $tables['memberships']);
    }

    /**
     * A display name is replaced by its token's last segment only when it is
     * a whole last segment of the redacted canonical name, never a suffix
     * that happens to end it.
     */
    public function testADisplayNameIsReplacedOnlyWhenItIsAWholeLastSegment(): void
    {
        $salt = str_repeat('k', 32);
        $tables = [
            'files' => [['id' => 'file_1', 'relative_path' => 'src/data.ts']],
            'nodes' => [
                ['id' => 'symbol_1', 'language' => 'ts', 'kind' => 'module', 'canonical_name' => 'src/data.ts', 'display_name' => 'data.ts', 'file_id' => 'file_1'],
                ['id' => 'symbol_2', 'language' => 'ts', 'kind' => 'module', 'canonical_name' => 'src/data.ts', 'display_name' => 'a.ts', 'file_id' => 'file_1'],
                ['id' => 'symbol_3', 'language' => 'ts', 'kind' => 'module', 'canonical_name' => 'src/data.ts', 'display_name' => 'Other', 'file_id' => 'file_1'],
                ['id' => 'symbol_4', 'language' => 'py', 'kind' => 'module', 'canonical_name' => 'pkg.secret', 'display_name' => 'pkg.secret', 'file_id' => 'file_1'],
                ['id' => 'symbol_5', 'language' => 'py', 'kind' => 'package', 'canonical_name' => 'pkg.secret', 'display_name' => 'secret', 'file_id' => 'file_1'],
                ['id' => 'symbol_6', 'language' => 'py', 'kind' => 'package', 'canonical_name' => 'pkg.secret', 'display_name' => 'ecret', 'file_id' => 'file_1'],
                ['id' => 'symbol_7', 'language' => 'ts', 'kind' => 'module', 'canonical_name' => null, 'display_name' => 'data.ts', 'file_id' => 'file_1'],
            ],
        ];
        $map = RedactionMap::fromPayload($tables, $salt);

        $redacted = BundleRedactor::redact($tables, false, $salt)['nodes'];

        $byId = array_column($redacted, 'display_name', 'id');
        self::assertSame(basename((string) $map->token('src/data.ts')), $byId[$map->id('symbol_1')]);
        self::assertSame('a.ts', $byId[$map->id('symbol_2')]);
        self::assertSame('Other', $byId[$map->id('symbol_3')]);
        self::assertSame($map->token('pkg.secret'), $byId[$map->id('symbol_4')]);
        self::assertSame($map->token('pkg.secret'), $byId[$map->id('symbol_5')]);
        self::assertSame('ecret', $byId[$map->id('symbol_6')]);
        self::assertSame('data.ts', $byId[$map->id('symbol_7')]);
    }

    /**
     * A scanner-local id is redacted past its `<scanner>:<kind>:` prefix only:
     * a module named like the scanner (`py`) must not rename the prefix.
     */
    public function testAScannerLocalIdKeepsItsPrefixAndTheScannerItsName(): void
    {
        $salt = str_repeat('k', 32);
        $tables = [
            'files' => [['id' => 'file_1', 'relative_path' => 'py.py']],
            'nodes' => [['id' => 'symbol_1', 'language' => 'py', 'kind' => 'module', 'canonical_name' => 'py', 'file_id' => 'file_1', 'attributes_json' => '{"scanner":"py","scanner_local_id":"py:module:py"}']],
        ];
        $map = RedactionMap::fromPayload($tables, $salt);

        $attributes = json_decode(BundleRedactor::redact($tables, false, $salt)['nodes'][0]['attributes_json'], true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(['scanner' => 'py', 'scanner_local_id' => 'py:module:' . $map->token('py')], $attributes);
    }
}
