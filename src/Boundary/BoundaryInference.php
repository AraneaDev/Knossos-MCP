<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;

final class BoundaryInference
{
    /**
     * @param list<ProjectUnit> $units
     * @param list<ScanContribution> $contributions
     * @param list<array<string, mixed>> $explicit
     * @return list<BoundaryFact>
     */
    public function infer(array $units, array $contributions, array $explicit = []): array
    {
        $nodes = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $nodes[$node->localId] = $node;
            }
        }
        $rules = [];
        foreach ($units as $unit) {
            $directory = dirname($unit->configPath);
            $prefix = $directory === '.' ? '' : rtrim(str_replace('\\', '/', $directory), '/') . '/';
            if ($unit->kind === 'composer') {
                $label = is_string($unit->metadata['name'] ?? null) ? $unit->metadata['name'] : ($prefix === '' ? 'root' : rtrim($prefix, '/'));
                $rules['composer:' . $label] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $prefix]];
            } elseif ($unit->kind === 'node') {
                $label = is_string($unit->metadata['name'] ?? null) ? $unit->metadata['name'] : ($prefix === '' ? 'root' : rtrim($prefix, '/'));
                $rules['node:' . $label] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $prefix]];
            } elseif ($unit->kind === 'typescript') {
                $rules['typescript:' . $unit->configPath] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $prefix]];
            } elseif ($unit->kind === 'python') {
                $label = is_string($unit->metadata['name'] ?? null) ? $unit->metadata['name'] : ($prefix === '' ? 'root' : rtrim($prefix, '/'));
                $rules['python:' . $label] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $prefix]];
            }
        }
        foreach ($nodes as $node) {
            if (str_starts_with($node->localId, 'php:') && str_contains($node->canonicalName, '\\')) {
                $namespace = explode('\\', ltrim($node->canonicalName, '\\'))[0];
                if ($namespace !== '') {
                    $rules['namespace:' . $namespace] = ['source' => 'inferred', 'matcher' => ['type' => 'namespace_prefix', 'value' => $namespace . '\\']];
                }
            }
            if (str_starts_with($node->localId, 'ts:')) {
                $path = explode('#', $node->canonicalName, 2)[0];
                $top = explode('/', ltrim($path, '/'))[0] ?? '';
                if ($top !== '' && str_contains($path, '/')) {
                    $rules['module:' . $top] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $top . '/']];
                }
            }
            if (str_starts_with($node->localId, 'py:')) {
                $top = explode('/', ltrim($node->evidence->relativePath, '/'))[0] ?? '';
                if ($top !== '' && str_contains($node->evidence->relativePath, '/')) {
                    $rules['python-package:' . $top] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $top . '/']];
                }
            }
        }
        foreach ($explicit as $rule) {
            if (!is_array($rule) || !is_string($rule['name'] ?? null)) {
                throw new InvalidArgumentException('Explicit boundary requires a name.');
            }
            $matcher = null;
            if (is_string($rule['path_prefix'] ?? null)) {
                $matcher = ['type' => 'path_prefix', 'value' => $this->pathPrefix($rule['path_prefix'])];
            }
            if (is_string($rule['namespace_prefix'] ?? null)) {
                $matcher = ['type' => 'namespace_prefix', 'value' => ltrim($rule['namespace_prefix'], '\\')];
            }
            if ($matcher === null) {
                throw new InvalidArgumentException('Explicit boundary requires path_prefix or namespace_prefix.');
            }
            $rules['explicit:' . $rule['name']] = ['source' => 'explicit', 'matcher' => $matcher, 'display' => $rule['name']];
        }
        ksort($rules, SORT_STRING);
        $facts = [];
        foreach ($rules as $name => $rule) {
            $members = [];
            foreach ($nodes as $node) {
                if ($this->matches($node, $rule['matcher'])) {
                    $members[] = $node->localId;
                }
            }
            sort($members, SORT_STRING);
            $facts[] = new BoundaryFact($rule['display'] ?? $name, $rule['matcher'], $rule['source'], array_values(array_unique($members)));
        }
        return $facts;
    }

    /** @param array{type: string, value: string} $matcher */
    private function matches(NodeFact $node, array $matcher): bool
    {
        return $matcher['type'] === 'path_prefix'
            ? str_starts_with($node->evidence->relativePath, $matcher['value'])
            : str_starts_with(ltrim($node->canonicalName, '\\'), ltrim($matcher['value'], '\\'));
    }

    private function pathPrefix(string $prefix): string
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        foreach (explode('/', $prefix) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Boundary path prefix must be project-relative.');
            }
        }
        return $prefix === '' ? '' : $prefix . '/';
    }
}
