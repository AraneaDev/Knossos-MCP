<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;

final class StableId
{
    private function __construct() {}

    public static function project(string $identity): string
    {
        return self::make('project', [$identity]);
    }

    public static function file(string $projectId, string $relativePath): string
    {
        return self::make('file', [$projectId, $relativePath]);
    }

    public static function scan(string $projectId, string $nonce): string
    {
        return self::make('scan', [$projectId, $nonce]);
    }

    public static function symbol(
        string $projectId,
        string $language,
        string $kind,
        string $canonicalName,
        string $signature = '',
    ): string {
        $parts = [$projectId, $language, $kind, $canonicalName];
        if ($signature !== '') {
            $parts[] = $signature;
        }

        return self::make('symbol', $parts);
    }

    /** @param list<string> $methods */
    public static function route(string $projectId, array $methods, string $uri, string $action): string
    {
        sort($methods, SORT_STRING);

        return self::make('route', [$projectId, implode(',', $methods), $uri, $action]);
    }

    public static function edge(
        string $projectId,
        string $kind,
        string $sourceId,
        string $targetId,
        string $evidenceIdentity,
    ): string {
        return self::make('edge', [$projectId, $kind, $sourceId, $targetId, $evidenceIdentity]);
    }

    public static function classification(string $projectId, string $nodeId, string $role, string $ruleId): string
    {
        return self::make('classification', [$projectId, $nodeId, $role, $ruleId]);
    }

    public static function boundary(string $projectId, string $name, string $source): string
    {
        return self::make('boundary', [$projectId, $name, $source]);
    }

    /** @param list<string> $parts */
    private static function make(string $prefix, array $parts): string
    {
        foreach ($parts as $part) {
            if ($part === '') {
                throw new InvalidArgumentException(sprintf('%s ID parts must not be empty.', $prefix));
            }
        }

        $payload = json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $prefix . '_' . hash('sha256', $payload);
    }
}
