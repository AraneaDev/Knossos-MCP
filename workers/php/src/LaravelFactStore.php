<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;

final class LaravelFactStore
{
    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];
    /** @var array<string, array<string, mixed>> */
    private array $edges = [];
    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];

    public function __construct(private readonly string $relativePath) {}

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return list<array<string, mixed>> */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @param array<string, mixed> $attributes */
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

    /** @param array<string, mixed> $attributes */
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

    public function addDiagnostic(string $code, string $message, Node $at): void
    {
        $this->diagnostics[] = [
            'severity' => 'warning',
            'code' => $code,
            'message' => $message,
            'evidence' => $this->evidence($at),
        ];
    }

    public static function classArgument(?Node $node): ?string
    {
        return $node instanceof Expr\ClassConstFetch && $node->class instanceof Name
            && $node->name instanceof Identifier && strtolower($node->name->toString()) === 'class'
            ? self::name($node->class) : null;
    }

    /** @return list<string> */
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

    public static function name(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        return ($resolved instanceof Name ? $resolved : $name)->toString();
    }

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
