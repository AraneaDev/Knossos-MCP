<?php

declare(strict_types=1);

namespace Knossos\Cli;

use InvalidArgumentException;

final class CliInputLoader
{
    /** @return list<array<string, mixed>> */
    public function policies(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Policy file is not readable: ' . $path);
        }
        $size = filesize($path);
        if (!is_int($size) || $size > 1_000_000) {
            throw new InvalidArgumentException('Policy file must not exceed 1000000 bytes.');
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new InvalidArgumentException('Unable to read policy file: ' . $path);
        }
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new InvalidArgumentException('Policy file must contain a JSON array.');
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    public function jsonObject(string $path): array
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > 1_000_000) {
            throw new InvalidArgumentException('JSON object file is unreadable or exceeds 1000000 bytes: ' . $path);
        }
        $json = file_get_contents($path);
        $decoded = is_string($json) ? json_decode($json, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('JSON file must contain an object.');
        }
        return $decoded;
    }

    public function bundle(string $path): string
    {
        $inputSize = is_file($path) ? filesize($path) : false;
        if (!is_readable($path) || !is_int($inputSize) || $inputSize > 10_000_000) {
            throw new InvalidArgumentException('Bundle input is unreadable or exceeds 10000000 bytes.');
        }
        $bundle = file_get_contents($path);
        if (!is_string($bundle)) {
            throw new InvalidArgumentException('Unable to read bundle input.');
        }
        return $bundle;
    }
}
