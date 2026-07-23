<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;

final class BoundaryInference
{
    private const SYNTHETIC_NODE_KINDS = ['route', 'endpoint'];
    private const PHP_NAMESPACE_SEGMENT = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';
    private const PATH_SEGMENT = '/^[A-Za-z0-9_.@-]+$/';

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
            // Synthetic nodes (routes, endpoints) have canonical names like
            // "GET /x => App\C::m" — structured labels, not namespaces or paths.
            // They may belong to boundaries but must never seed prefix rules.
            if (in_array($node->kind, self::SYNTHETIC_NODE_KINDS, true)) {
                continue;
            }
            if (str_starts_with($node->localId, 'php:') && str_contains($node->canonicalName, '\\')) {
                $namespace = explode('\\', ltrim($node->canonicalName, '\\'))[0];
                if ($namespace !== '' && preg_match(self::PHP_NAMESPACE_SEGMENT, $namespace) === 1) {
                    $rules['namespace:' . $namespace] = ['source' => 'inferred', 'matcher' => ['type' => 'namespace_prefix', 'value' => $namespace . '\\']];
                }
            }
            if (str_starts_with($node->localId, 'ts:')) {
                $path = explode('#', $node->canonicalName, 2)[0];
                $top = explode('/', ltrim($path, '/'))[0] ?? '';
                if ($top !== '' && str_contains($path, '/') && preg_match(self::PATH_SEGMENT, $top) === 1) {
                    $rules['module:' . $top] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $top . '/']];
                }
            }
            if (str_starts_with($node->localId, 'py:')) {
                $top = explode('/', ltrim($node->evidence->relativePath, '/'))[0] ?? '';
                if ($top !== '' && str_contains($node->evidence->relativePath, '/') && preg_match(self::PATH_SEGMENT, $top) === 1) {
                    $rules['python-package:' . $top] = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $top . '/']];
                }
            }
        }
        $seenExplicit = [];
        foreach ($explicit as $rule) {
            if (!is_array($rule) || !is_string($rule['name'] ?? null)) {
                throw new InvalidArgumentException('Explicit boundary requires a name.');
            }
            if (isset($seenExplicit[$rule['name']])) {
                throw new InvalidArgumentException(sprintf('Duplicate explicit boundary name: %s.', $rule['name']));
            }
            $seenExplicit[$rule['name']] = true;
            $hasPath = is_string($rule['path_prefix'] ?? null);
            $hasNamespace = is_string($rule['namespace_prefix'] ?? null);
            if ($hasPath && $hasNamespace) {
                throw new InvalidArgumentException(sprintf('Explicit boundary %s must declare either path_prefix or namespace_prefix, not both.', $rule['name']));
            }
            if ($hasPath) {
                $matcher = ['type' => 'path_prefix', 'value' => $this->pathPrefix($rule['path_prefix'])];
            } elseif ($hasNamespace) {
                $matcher = ['type' => 'namespace_prefix', 'value' => $this->namespacePrefix($rule['namespace_prefix'])];
            } else {
                throw new InvalidArgumentException('Explicit boundary requires path_prefix or namespace_prefix.');
            }
            $rules['explicit:' . $rule['name']] = ['source' => 'explicit', 'matcher' => $matcher, 'display' => $rule['name']];
        }
        ksort($rules, SORT_STRING);
        // Identical matchers produce identical member sets by construction; keep one
        // boundary per matcher. Only inferred rules merge — an explicit rule is a
        // user declaration and keeps its own identity even on a shared matcher.
        $byMatcher = [];
        foreach ($rules as $name => $rule) {
            if ($rule['source'] !== 'inferred') {
                continue;
            }
            $key = $rule['matcher']['type'] . "\0" . $rule['matcher']['value'];
            if (!isset($byMatcher[$key])) {
                $byMatcher[$key] = $name;
                continue;
            }
            $rules[$byMatcher[$key]]['merged_names'][] = $rule['display'] ?? $name;
            unset($rules[$name]);
        }
        $facts = [];
        foreach ($rules as $name => $rule) {
            $members = [];
            foreach ($nodes as $node) {
                if ($this->matches($node, $rule['matcher'])) {
                    $members[] = $node->localId;
                }
            }
            sort($members, SORT_STRING);
            $displayName = $rule['display'] ?? $name;
            if (isset($rule['merged_names'])) {
                $displayName .= ' (+' . implode(', ', $rule['merged_names']) . ')';
            }
            $facts[] = new BoundaryFact($displayName, $rule['matcher'], $rule['source'], array_values(array_unique($members)));
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

    /**
     * Anchor an explicit namespace prefix with a trailing separator so that
     * "App" matches "App\Service" but not "Apple\Service" or "AppKernel",
     * mirroring the inferred namespace rules.
     */
    private function namespacePrefix(string $prefix): string
    {
        $namespace = trim($prefix, '\\');

        return $namespace === '' ? '' : $namespace . '\\';
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
