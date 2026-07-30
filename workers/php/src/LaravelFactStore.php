<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

/**
 * Accumulates the nodes, edges, and diagnostics one file's Laravel analysis found.
 *
 * Facts are collected rather than emitted as they are discovered, because a single
 * file yields several kinds and the worker returns them as one contribution. The
 * static helpers read literal values out of the parse tree, returning null rather
 * than guessing whenever an argument is computed at runtime — an inferred value
 * recorded as certain would be worse than an absent one.
 */
final class LaravelFactStore
{
    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];
    /** @var array<string, array<string, mixed>> */
    private array $edges = [];
    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];

    /** @param string $relativePath the file these facts came from, used as their evidence path */
    public function __construct(private readonly string $relativePath) {}

    /**
     * The node facts collected so far.
     *
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /**
     * The edge facts collected so far.
     *
     * @return list<array<string, mixed>>
     */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /**
     * What the analysis could not resolve, reported with the scan.
     *
     * @return list<array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Record a node fact, defaulting to framework-convention origin since that is what these collectors observe.
     *
     * @param array<string, mixed> $attributes
     */
    public function addNode(string $id, string $kind, string $canonical, string $display, Node $at, array $attributes, string $origin = 'framework_convention', string $confidence = 'certain'): void
    {
        $this->nodes[$id] ??= [
            'local_id' => $id,
            'kind' => $kind,
            'canonical_name' => $canonical,
            'display_name' => $display,
            'origin' => $origin,
            'confidence' => $confidence,
            'evidence' => $this->evidence($at),
            'attributes' => (object) $attributes,
        ];
    }

    /**
     * Record an edge fact with its evidence location.
     *
     * @param array<string, mixed> $attributes
     */
    public function addEdge(string $kind, string $source, string $target, Node $at, array $attributes = []): void
    {
        $key = implode("\0", [$kind, $source, $target, (string) $at->getStartLine(), json_encode($attributes)]);
        $this->edges[$key] = [
            'kind' => $kind,
            'source' => $source,
            'target' => $target,
            'origin' => 'framework_convention',
            'confidence' => 'certain',
            'evidence' => $this->evidence($at),
            'attributes' => (object) $attributes,
        ];
    }

    /** Record something the analysis could not resolve, reported with the scan rather than thrown. */
    public function addDiagnostic(string $code, string $message, Node $at): void
    {
        $this->diagnostics[] = [
            'severity' => 'warning',
            'code' => $code,
            'message' => $message,
            'evidence' => $this->evidence($at),
        ];
    }

    /** The class named by a `Foo::class` argument, or null for anything computed at runtime. */
    public static function classArgument(?Node $node): ?string
    {
        return $node instanceof Expr\ClassConstFetch && $node->class instanceof Name
            && $node->name instanceof Identifier && strtolower($node->name->toString()) === 'class'
            ? self::name($node->class) : null;
    }

    /**
     * Class names from a `::class` argument or an array of them, skipping computed elements.
     *
     * @return list<string>
     */
    public static function classArguments(?Node $node): array
    {
        if ($node instanceof Expr\Array_) {
            $result = [];
            foreach ($node->items as $item) {
                $class = self::classArgument($item?->value);
                if ($class !== null) {
                    $result[] = $class;
                }
            }
            return $result;
        }
        $class = self::classArgument($node);
        return $class === null ? [] : [$class];
    }

    /** A literal string argument's value, or null when it is not a literal. */
    public static function string(?Node $node): ?string
    {
        return $node instanceof Scalar\String_ ? $node->value : null;
    }

    /** @return list<string> */
    public static function strings(?Node $node): array
    {
        $single = self::string($node);
        if ($single !== null) {
            return [$single];
        }
        if (!$node instanceof Expr\Array_) {
            return [];
        }
        $result = [];
        foreach ($node->items as $item) {
            $value = self::string($item?->value);
            if ($value !== null) {
                $result[] = $value;
            }
        }
        return $result;
    }
    /** A resolved name node as a fully-qualified string. */

    public static function name(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        return ($resolved instanceof Name ? $resolved : $name)->toString();
    }
    /** A class name in the canonical form the graph uses for an external reference. */

    public static function classReference(string $name): string
    {
        return 'php:class:' . ltrim($name, '\\');
    }

    /** @return array{path: string, start_line: int, end_line: int} */
    private function evidence(Node $node): array
    {
        $start = max(1, $node->getStartLine());
        return ['path' => $this->relativePath, 'start_line' => $start, 'end_line' => max($start, $node->getEndLine())];
    }
}
