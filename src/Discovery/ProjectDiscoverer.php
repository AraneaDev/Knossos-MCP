<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Knossos\Scan\CancellationToken;

/**
 * Discovers a project: the files worth analysing and the units that configure
 * them, in a stable order and hashed.
 *
 * {@see TreeWalker} walks the tree and reads the units, {@see EntryPointResolver}
 * adds the entry points that take more than one unit to see, and this class
 * orders and hashes the result. The classification statics stay here as
 * delegates to {@see SourceClassifier}, so callers that ask discovery's
 * questions about a path keep one entry point.
 */
final readonly class ProjectDiscoverer
{
    /** The unit kinds that make their directory a manifest root; see {@see SourceClassifier::MANIFEST_UNIT_KINDS}. */
    public const MANIFEST_UNIT_KINDS = SourceClassifier::MANIFEST_UNIT_KINDS;

    private TreeWalker $walker;

    /**
     * @param ?FileContentReader $contents how a file discovery uses twice is
     *        read. Production reads the filesystem; injected only so a test
     *        can answer a second read with different bytes, which is the one
     *        way to show that a manifest's hash and its metadata come from a
     *        single read rather than from two that can disagree.
     */
    public function __construct(DiscoveryConfig $config, ?FileContentReader $contents = null)
    {
        $this->walker = new TreeWalker($config, $contents ?? new FilesystemContentReader());
    }

    /** Walk the tree and select the files worth analysing, within the configured caps. */
    public function discover(string $requestedRoot, ?CancellationToken $cancellation = null): DiscoveryResult
    {
        return self::result($this->walker->walk($requestedRoot, $cancellation));
    }

    /**
     * The walk's files and units in a stable order, with the entry points
     * derived across units, and the input and configuration hashes over both.
     *
     * @param array{
     *     root: string,
     *     files: list<DiscoveredFile>,
     *     units: list<ProjectUnit>,
     *     diagnostics: list<DiscoveryDiagnostic>,
     *     unparsedManifestHashes: array<string, string>,
     *     manifestRoots: list<string>,
     *     directories: list<string>,
     * } $walk what {@see TreeWalker::walk()} read
     */
    private static function result(array $walk): DiscoveryResult
    {
        $files = $walk['files'];
        $units = $walk['units'];
        $files = SourceClassifier::withoutCompiledSiblings($files);
        usort($files, static fn(DiscoveredFile $left, DiscoveredFile $right): int =>
            $left->relativePath <=> $right->relativePath);
        usort($units, static fn(ProjectUnit $left, ProjectUnit $right): int =>
            [$left->kind, $left->configPath] <=> [$right->kind, $right->configPath]);
        $units = EntryPointResolver::resolve($units, $files);

        $inputParts = array_map(
            static fn(DiscoveredFile $file): string => $file->relativePath . '=' . $file->contentHash,
            $files,
        );
        $configParts = array_map(
            static fn(ProjectUnit $unit): string => $unit->kind . ':' . $unit->configPath . '=' . $unit->contentHash,
            $units,
        );

        return new DiscoveryResult(
            $walk['root'],
            $files,
            $units,
            $walk['diagnostics'],
            hash('sha256', implode("\n", $inputParts)),
            hash('sha256', implode("\n", $configParts)),
            $walk['unparsedManifestHashes'],
            $walk['manifestRoots'],
            $walk['directories'],
        );
    }

    /** Whether a path names a package or build manifest; see {@see SourceClassifier::isManifest()}. */
    public static function isManifest(string $relativePath): bool
    {
        return SourceClassifier::isManifest($relativePath);
    }

    /**
     * Whether a JavaScript file is `tsc` output beside its TypeScript source;
     * see {@see SourceClassifier::isCompiledSibling()}.
     *
     * @param callable(string): bool $exists whether a project-relative path exists
     */
    public static function isCompiledSibling(string $relativePath, string $absolutePath, callable $exists): bool
    {
        return SourceClassifier::isCompiledSibling($relativePath, $absolutePath, $exists);
    }

    /** Whether a path is configuration discovery always reads; see {@see SourceClassifier::isConfigurationFile()}. */
    public static function isConfigurationFile(string $relativePath): bool
    {
        return SourceClassifier::isConfigurationFile($relativePath);
    }

    /** The source language of a path, or null; see {@see SourceClassifier::languageFor()}. */
    public static function languageFor(string $relativePath, ?string $absolutePath = null): ?string
    {
        return SourceClassifier::languageFor($relativePath, $absolutePath);
    }

    /** The project unit kind a path configures, or null; see {@see SourceClassifier::unitKindFor()}. */
    public static function unitKindFor(string $relativePath): ?string
    {
        return SourceClassifier::unitKindFor($relativePath);
    }

    /** Whether a path's name can be carried by a stable id; see {@see SourceClassifier::isSupportedPath()}. */
    public static function isSupportedPath(string $relative): bool
    {
        return SourceClassifier::isSupportedPath($relative);
    }
}
