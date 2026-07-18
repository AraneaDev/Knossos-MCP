<?php

declare(strict_types=1);

namespace Knossos\Configuration;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\JsonConfig;
use Knossos\Discovery\RootGuard;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;

final class ProjectConfigurationLoader
{
    private const ROOT_KEYS = ['$schema', 'version', 'ignores', 'limits', 'boundaries', 'frameworks', 'snapshot_retention', 'policies', 'quality_budgets'];
    private const BUDGET_KEYS = ['new_cycles', 'boundary_violations', 'error_diagnostics', 'warning_diagnostics', 'hub_degree_growth', 'unreferenced_candidates', 'public_surface_changes'];

    private function __construct() {}

    /** @param list<string> $allowedRoots */
    public static function load(string $requestedRoot, array $allowedRoots): ProjectConfiguration
    {
        $root = (new RootGuard($allowedRoots))->resolve($requestedRoot);
        $json = $root . '/knossos.json';
        $jsonc = $root . '/knossos.jsonc';
        if (is_file($json) && is_file($jsonc)) {
            throw new DiscoveryException('PROJECT_CONFIG_AMBIGUOUS: keep only knossos.json or knossos.jsonc.');
        }
        $path = is_file($jsonc) ? $jsonc : (is_file($json) ? $json : null);
        if ($path === null) {
            return new ProjectConfiguration();
        }
        $size = filesize($path);
        if (!is_int($size) || $size > 1_000_000) {
            throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: project configuration exceeds 1000000 bytes.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new DiscoveryException('PROJECT_CONFIG_UNREADABLE: unable to read project configuration.');
        }
        $data = JsonConfig::decode($contents, str_ends_with($path, '.jsonc'));
        self::knownKeys($data, self::ROOT_KEYS, 'project configuration');
        if (($data['version'] ?? null) !== 1) {
            throw new DiscoveryException('PROJECT_CONFIG_VERSION_UNSUPPORTED: version must be 1.');
        }

        $ignores = self::stringList($data['ignores'] ?? [], 'ignores', 100);
        foreach ($ignores as $pattern) {
            if (str_starts_with($pattern, '/') || str_contains($pattern, "\0") || in_array('..', explode('/', str_replace('\\', '/', $pattern)), true)) {
                throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: ignore patterns must be relative and may not contain parent traversal.');
            }
        }
        $limits = self::object($data['limits'] ?? [], 'limits');
        self::knownKeys($limits, ['max_files', 'max_file_bytes', 'worker_timeout_ms'], 'limits');
        $maxFiles = self::optionalInteger($limits, 'max_files', 1, 100_000);
        $maxBytes = self::optionalInteger($limits, 'max_file_bytes', 1, 100_000_000);
        $workerTimeoutMs = self::optionalInteger(
            $limits,
            'worker_timeout_ms',
            WorkerExecutionPolicy::MIN_REQUEST_TIMEOUT_MS,
            WorkerExecutionPolicy::MAX_REQUEST_TIMEOUT_MS,
        );
        $retention = self::optionalInteger($data, 'snapshot_retention', 0, 20);
        $frameworks = self::stringList($data['frameworks'] ?? [], 'frameworks', 20);
        foreach ($frameworks as $framework) {
            if (!in_array($framework, ['laravel', 'symfony', 'django', 'fastapi', 'nextjs', 'nestjs', 'react', 'vue'], true)) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: unsupported framework hint ' . $framework . '.');
            }
        }
        $boundaries = self::objectList($data['boundaries'] ?? [], 'boundaries', 50);
        foreach ($boundaries as $boundary) {
            self::knownKeys($boundary, ['name', 'path_prefix', 'namespace_prefix'], 'boundary');
            if (!is_string($boundary['name'] ?? null) || $boundary['name'] === '') {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary name must be non-empty.');
            }
            if (!isset($boundary['path_prefix']) && !isset($boundary['namespace_prefix'])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary must declare path_prefix or namespace_prefix.');
            }
            $pathPrefix = $boundary['path_prefix'] ?? null;
            if ($pathPrefix !== null && (!is_string($pathPrefix) || str_starts_with($pathPrefix, '/') || in_array('..', explode('/', str_replace('\\', '/', $pathPrefix)), true))) {
                throw new DiscoveryException('PROJECT_CONFIG_UNSAFE: boundary path_prefix must be project-relative.');
            }
            if (isset($boundary['namespace_prefix']) && !is_string($boundary['namespace_prefix'])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: boundary namespace_prefix must be a string.');
            }
        }
        $policies = self::objectList($data['policies'] ?? [], 'policies', 50);
        foreach ($policies as $policy) {
            self::knownKeys($policy, ['id', 'from_boundary', 'allow_targets', 'deny_targets', 'edge_kinds'], 'policy');
            if (!is_string($policy['id'] ?? null) || $policy['id'] === '' || !is_string($policy['from_boundary'] ?? null) || $policy['from_boundary'] === '') {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: policies require non-empty id and from_boundary.');
            }
            if (!isset($policy['allow_targets']) && !isset($policy['deny_targets'])) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: policies require allow_targets or deny_targets.');
            }
            foreach (['allow_targets', 'deny_targets', 'edge_kinds'] as $list) {
                if (isset($policy[$list])) {
                    self::stringList($policy[$list], 'policy ' . $list, 100);
                }
            }
        }
        $budgets = self::object($data['quality_budgets'] ?? [], 'quality_budgets');
        self::knownKeys($budgets, self::BUDGET_KEYS, 'quality_budgets');
        $typedBudgets = [];
        foreach ($budgets as $key => $value) {
            if (!is_int($value) || $value < 0 || $value > 100_000) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: quality budget ' . $key . ' must be between 0 and 100000.');
            }
            $typedBudgets[$key] = $value;
        }
        return new ProjectConfiguration(basename($path), $ignores, $maxFiles, $maxBytes, $workerTimeoutMs, $boundaries, array_values(array_unique($frameworks)), $retention, $policies, $typedBudgets);
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private static function knownKeys(array $data, array $allowed, string $scope): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new DiscoveryException('PROJECT_CONFIG_UNKNOWN_KEY: unknown ' . $scope . ' key(s): ' . implode(', ', $unknown) . '.');
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must be an object.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must be a bounded list.');
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must contain non-empty strings.');
            }
        }
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private static function objectList(mixed $value, string $field, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' must be a bounded list.');
        }
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new DiscoveryException('PROJECT_CONFIG_INVALID: ' . $field . ' entries must be objects.');
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optionalInteger(array $data, string $key, int $minimum, int $maximum): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new DiscoveryException(sprintf('PROJECT_CONFIG_INVALID: %s must be between %d and %d.', $key, $minimum, $maximum));
        }
        return $value;
    }
}
