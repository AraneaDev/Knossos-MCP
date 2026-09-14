<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Scanner\Protocol\RelativePath;

/**
 * The manifests and configuration files a scan read but holds no `files` row
 * for, with the content hash it read them at. The encoded form also carries
 * successful worker reads of files discovery never hashed.
 *
 * These decide real things: which frameworks are enriched, which analyzer
 * configuration hash a contribution is cached under, which paths are entry
 * points. Editing one changes what a scan would produce. But only
 * language-classified paths become `files` rows, and worker reads such as
 * dependency declarations under `node_modules` do not. Both kinds are kept
 * beside the scan so drift probes and incremental cache reuse can see them.
 *
 * Recording path and hash makes a manifest decidable by exactly the rule every
 * other input is decided by — hash what is on disk now against what the scan
 * stored. {@see ScannedPaths} closes the other half by agreeing that such a
 * path is one the scanner tracks at all, so a manifest that appears where
 * there was none reads as an addition rather than as nothing.
 *
 * {@see self::MAX_INPUTS} bounds what is persisted, and past it the set is
 * marked incomplete rather than trimmed, because a trimmed set would read as
 * a complete one.
 */
final readonly class UnitInputSet
{
    /**
     * Inputs persisted before the set is declared incomplete.
     *
     * A manifest per package is the shape of this set, so even a large
     * monorepo is hundreds. The bound exists so a pathological tree cannot put
     * a megabyte of paths on a scan row, not because a real project is
     * expected to approach it.
     */
    public const MAX_INPUTS = 5000;

    /** @param array<string, string> $inputs relative path => content hash */
    private function __construct(public array $inputs, public bool $complete) {}

    /**
     * The set discovery found, marked incomplete when there are more inputs
     * than {@see self::MAX_INPUTS}.
     *
     * @param list<ProjectUnit> $units
     */
    public static function of(array $units): self
    {
        $inputs = [];
        foreach ($units as $unit) {
            // One path is one input whatever kind it was read as: the hash is
            // of the file, and a path read twice would otherwise carry two
            // identical entries.
            $inputs[$unit->configPath] = $unit->contentHash;
        }
        ksort($inputs, SORT_STRING);
        if (count($inputs) > self::MAX_INPUTS) {
            return new self(array_slice($inputs, 0, self::MAX_INPUTS, true), false);
        }

        return new self($inputs, true);
    }

    /**
     * The set a scan persisted, or null when it recorded none that can be
     * compared against.
     *
     * Null covers the column never written (a scan predating it), a value that
     * no longer parses, and a set the scan marked incomplete. None of those is
     * "this project has no manifests", and the oracles must not read it as
     * one: an unrecorded manifest is a path with no stored hash, which they
     * report as an addition and which one rescan then settles.
     */
    public static function decode(?string $json): ?self
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded['complete'] ?? null) !== true || !is_array($decoded['inputs'] ?? null)) {
            return null;
        }
        $inputs = [];
        foreach ($decoded['inputs'] as $path => $hash) {
            if (!is_string($path) || !is_string($hash)) {
                return null;
            }
            $inputs[$path] = $hash;
        }

        return new self($inputs, true);
    }

    /**
     * The persisted form, carrying the completeness flags the decoders refuse
     * to guess at. Worker input hashes are successful reads of files discovery
     * did not hash, such as dependency declarations under `node_modules`.
     * Null worker reads are verification evidence, not dependency bytes, so
     * they are deliberately not retained for freshness tracking.
     *
     * @param array<array-key, string|null> $workerInputs
     */
    public function encode(array $workerInputs = []): string
    {
        $recordedWorkerInputs = [];
        foreach ($workerInputs as $path => $hash) {
            if ($hash === null) {
                continue;
            }
            $path = (string) $path;
            RelativePath::assertValid($path, 'worker input ' . $path);
            $recordedWorkerInputs[$path] = $hash;
        }
        ksort($recordedWorkerInputs, SORT_STRING);
        $workerComplete = count($recordedWorkerInputs) <= self::MAX_INPUTS;
        if (!$workerComplete) {
            $recordedWorkerInputs = array_slice($recordedWorkerInputs, 0, self::MAX_INPUTS, true);
        }

        return (string) json_encode([
            'inputs' => $this->inputs,
            'complete' => $this->complete,
            'worker_inputs' => [
                'inputs' => $recordedWorkerInputs,
                'complete' => $workerComplete,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Worker dependency hashes in a scan record, or null when the record was
     * written before worker inputs were persisted, is malformed, or exceeded
     * the safe retention bound.
     *
     * @return array<string, string>|null relative path => content hash
     */
    public static function decodeWorkerInputs(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        $worker = is_array($decoded) ? ($decoded['worker_inputs'] ?? null) : null;
        if (!is_array($worker) || ($worker['complete'] ?? null) !== true || !is_array($worker['inputs'] ?? null)) {
            return null;
        }
        $inputs = [];
        foreach ($worker['inputs'] as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                return null;
            }
            try {
                RelativePath::assertValid($path, 'worker input ' . $path);
            } catch (\InvalidArgumentException) {
                return null;
            }
            $inputs[$path] = $hash;
        }

        return $inputs;
    }
}
