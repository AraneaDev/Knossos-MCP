<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Reads class names out of docblocks the way the code around them would.
 *
 * A docblock names its types through the file's imports and namespace, but
 * the parser resolves only names in code, so the imports are read here once
 * per namespace block and every annotation is resolved against them. The
 * type grammar itself is the subset a receiver needs: a `@param`'s element
 * type, and what a returned closure yields.
 */
final class DocblockTypes
{
    /** One docblock type, generic arguments, shapes and callable signatures included. */
    private const DOC_TYPE = '(?:[^\\s<>{}()]|<(?:[^<>]|<[^<>]*>)*>|\\{[^{}]*\\}|\\([^()]*\\))+';

    /** Docblock types that name no class. */
    private const DOC_PSEUDO_TYPES = [
        'array', 'array-key', 'bool', 'boolean', 'callable', 'callable-string', 'class-string', 'double', 'false', 'float',
        'int', 'integer', 'iterable', 'list', 'mixed', 'negative-int', 'never', 'non-empty-array', 'non-empty-list',
        'non-empty-string', 'null', 'numeric', 'numeric-string', 'object', 'positive-int', 'resource', 'scalar', 'string',
        'true', 'void',
    ];

    /**
     * The file's class imports, per namespace block: its node id (0 for a file
     * without one) => lower-cased alias => imported name.
     *
     * A file may declare several namespaces, each importing its own classes
     * under one alias.
     *
     * @var array<int, array<string, string>>
     */
    private array $imports = [];

    /**
     * Record the class imports one namespace block's statements declare.
     *
     * Function and constant imports are skipped: a docblock type names a class.
     *
     * @param array<Node> $statements
     */
    public function readImports(int $scope, array $statements): void
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($statements, Stmt\Use_::class) as $use) {
            if ($use->type === Stmt\Use_::TYPE_NORMAL) {
                foreach ($use->uses as $item) {
                    $this->imports[$scope][strtolower($item->getAlias()->toString())] = $item->name->toString();
                }
            }
        }
        foreach ($finder->findInstanceOf($statements, Stmt\GroupUse::class) as $group) {
            foreach ($group->uses as $item) {
                if ($group->type === Stmt\Use_::TYPE_NORMAL || $item->type === Stmt\Use_::TYPE_NORMAL) {
                    $this->imports[$scope][strtolower($item->getAlias()->toString())] = $group->prefix->toString() . '\\' . $item->name->toString();
                }
            }
        }
    }

    /**
     * The class a relative name reaches through a namespace block's imports:
     * its first segment is the alias, the rest is appended. Null when nothing
     * imports that alias.
     */
    public function imported(int $scope, string $written): ?string
    {
        [$head, $rest] = array_pad(explode('\\', $written, 2), 2, null);
        $imported = $this->imports[$scope][strtolower($head)] ?? null;
        if ($imported === null) {
            return null;
        }

        return $rest === null ? $imported : $imported . '\\' . $rest;
    }

    /**
     * The class a docblock names, read the way the code would read it: fully
     * qualified, through the namespace block's imports, or in its namespace.
     * Null for a scalar or pseudo type, or for anything that is not one name.
     */
    public function documentedClass(string $written, int $scope, string $namespace, ?string $class): ?string
    {
        $written = ltrim($written, '?');
        if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $written) !== 1) {
            return null;
        }
        if (str_starts_with($written, '\\')) {
            return substr($written, 1);
        }
        $lower = strtolower($written);
        if ($lower === 'self' || $lower === 'static') {
            return $class;
        }
        if (in_array($lower, self::DOC_PSEUDO_TYPES, true)) {
            return null;
        }
        $imported = $this->imported($scope, $written);
        if ($imported !== null) {
            return $imported;
        }

        return $namespace === '' ? $written : $namespace . '\\' . $written;
    }

    /**
     * Each `@param` tag's type by parameter name, wherever the tag sits on its
     * line: a docblock may put several on one.
     *
     * @return array<string, string>
     */
    public static function documentedParameters(string $docComment): array
    {
        preg_match_all('/@(?:phpstan-|psalm-)?param\s+(' . self::DOC_TYPE . ')\s+&?(?:\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)/', $docComment, $tags, PREG_SET_ORDER);
        $types = [];
        foreach ($tags as [, $type, $name]) {
            $types[$name] = $type;
        }

        return $types;
    }

    /**
     * The element type of an array or iterable type as a docblock writes it:
     * `list<X>`, `array<K, X>`, `iterable<X>` or `X[]`.
     */
    public static function elementType(string $type): ?string
    {
        if (preg_match('/^(?:list|non-empty-list|array|non-empty-array|iterable)<(?:[^<>,]+,\s*)?([^<>,]+)>$/i', $type, $matches) === 1
            || preg_match('/^([^<>\[\]|{}()]+)\[\]$/', $type, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    /** What a docblock's `@return \Closure(): X` (or `callable(): X`) says calling the returned closure yields. */
    public static function documentedClosureResult(string $docComment): ?string
    {
        return preg_match('/@(?:phpstan-|psalm-)?return\s+\\\\?(?:Closure|callable)\s*\([^()]*\)\s*:\s*(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i', $docComment, $matches) === 1
            ? $matches[1]
            : null;
    }
}
