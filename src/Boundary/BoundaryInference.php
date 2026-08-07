<?php

declare(strict_types=1);

namespace Knossos\Boundary;

use InvalidArgumentException;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;

/**
 * Infers boundaries from directory structure when none are declared.
 *
 * A first approximation so policy checks and diagrams have something to work with
 * on an unconfigured project; inferred boundaries are labelled as such, so they are
 * never mistaken for an intentional architecture.
 */
final class BoundaryInference
{
    private const SYNTHETIC_NODE_KINDS = ['route', 'endpoint'];
    private const PHP_NAMESPACE_SEGMENT = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/';
    private const PATH_SEGMENT = '/^[A-Za-z0-9_.@-]+$/';

    /**
     * Infer boundaries from directory structure when the project declares none.
     *
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
        $manifestKinds = ['composer' => true, 'node' => true, 'python' => true, 'typescript' => true];
        $legacyIdentityCounts = [];
        foreach ($units as $unit) {
            if (!isset($manifestKinds[$unit->kind])) {
                continue;
            }
            $directory = dirname($unit->configPath);
            $prefix = $directory === '.' ? '' : rtrim(str_replace('\\', '/', $directory), '/') . '/';
            // Keyed by the manifest's own path, never by its declared name: two
            // packages can legitimately declare the same name (a vendored or forked
            // copy), and keying on the name silently dropped one whole boundary along
            // with the path prefix that defined its members. The declared name
            // survives as the display label. TypeScript units have no declared
            // package name (tsconfig.json carries none), so they keep displaying
            // their key, as before this change.
            $rule = ['source' => 'inferred', 'matcher' => ['type' => 'path_prefix', 'value' => $prefix]];
            if ($unit->kind !== 'typescript') {
                $label = is_string($unit->metadata['name'] ?? null) ? $unit->metadata['name'] : ($prefix === '' ? 'root' : rtrim($prefix, '/'));
                $rule['display'] = $label;
                // The pre-fix rule key ("kind:name") is what every already-persisted
                // boundary's stable id was derived from. Recording it here lets the
                // facts loop below reuse it as $identityName whenever it is still
                // unique, so the overwhelmingly common case (one manifest per kind)
                // keeps its existing id even though $display dropped the kind prefix.
                $rule['legacy_identity'] = $unit->kind . ':' . $label;
                $legacyIdentityCounts[$rule['legacy_identity']] = ($legacyIdentityCounts[$rule['legacy_identity']] ?? 0) + 1;
            }
            $rules[$unit->kind . ':' . $unit->configPath] = $rule;
        }
        foreach ($rules as $key => $rule) {
            if (!isset($rule['legacy_identity'])) {
                continue;
            }
            // Two manifests colliding on the same declared name is exactly the case
            // this fix exists for: reusing their shared legacy identity here would
            // just move the silent overwrite from the rule key to the stable id, so
            // fall back to each rule's own unique key instead.
            $rules[$key]['identity'] = $legacyIdentityCounts[$rule['legacy_identity']] === 1 ? $rule['legacy_identity'] : $key;
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
            $baseName = $rule['display'] ?? $name;
            $displayName = $baseName;
            // A manifest rule's pinned legacy identity (see above) keeps its stable id
            // put even though $baseName no longer carries the kind prefix. Rules with
            // no pinned identity (language-derived and explicit rules) fall back to
            // null, meaning "same as $name" — unchanged from before this fix.
            $identityName = $rule['identity'] ?? null;
            if (isset($rule['merged_names'])) {
                // The merged-from list is display-only: appending it to $displayName must
                // not perturb the boundary's stable id, or every scan whose merge
                // composition shifts (e.g. a package.json added next to composer.json)
                // would rename AND re-id the boundary, breaking persisted policy
                // references. $identityName pins the id to the surviving primary rule's
                // pre-suffix identity (its pinned legacy identity when it has one,
                // otherwise its base display name).
                $displayName .= ' (+' . implode(', ', $rule['merged_names']) . ')';
                $identityName = $rule['identity'] ?? $baseName;
            }
            $facts[] = new BoundaryFact($displayName, $rule['matcher'], $rule['source'], array_values(array_unique($members)), $identityName);
        }
        return $facts;
    }

    /**
     * Whether a node belongs to an inferred boundary.
     *
     * @param array{type: string, value: string} $matcher
     */
    private function matches(NodeFact $node, array $matcher): bool
    {
        if ($matcher['type'] === 'path_prefix') {
            // An external node has no source file of its own: scanners evidence it at
            // the reference site, so node:fs carries the path of whichever file
            // imported it first. A path prefix says where code lives, so letting that
            // borrowed path decide membership would make node:fs a member of that
            // importer's boundary — and every other boundary importing fs would then
            // read as depending on it. Namespace prefixes still apply: an external
            // class genuinely is in the namespace its name declares.
            return ($node->attributes['external'] ?? false) !== true
                && str_starts_with($node->evidence->relativePath, $matcher['value']);
        }

        return str_starts_with(ltrim($node->canonicalName, '\\'), ltrim($matcher['value'], '\\'));
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
    /** The path prefix defining an inferred boundary's membership. */

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
