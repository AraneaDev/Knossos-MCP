<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/** The files discovery selected, the project units it found, and what it could not read. */
final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredFile> $files
     * @param list<ProjectUnit> $units
     * @param list<DiscoveryDiagnostic> $diagnostics
     * @param array<string, string> $unparsedManifestHashes manifests discovery read and
     *        hashed but could not parse into a unit, keyed by project-relative path. They
     *        carry no metadata, yet a worker may still read them (a malformed
     *        package.json during module resolution), so they are verified like a unit.
     * @param list<string> $manifestRoots the directories the walk found a package or
     *        build manifest in, project-relative and sorted, `''` for the root. Build
     *        output directly below one of them is excluded, and a worker is told
     *        which they are so it excludes the same paths.
     * @param list<string> $directories every directory the walk opened, project-relative
     *        and sorted, the root left out. A watcher stats them, so a file created in one
     *        that held no discovered file is seen; a directory the walk skipped is not here.
     */
    public function __construct(
        public string $rootRealpath,
        public array $files,
        public array $units,
        public array $diagnostics,
        public string $inputHash,
        public string $configurationHash,
        public array $unparsedManifestHashes = [],
        public array $manifestRoots = [],
        public array $directories = [],
    ) {}

    /**
     * Every hash discovery took of a path, keyed by project-relative path: the
     * discovered files, the project units, and the manifests that did not parse.
     *
     * A worker's `input_hashes` entry is verified against this map, and the
     * post-worker snapshot check re-hashes every path in it. A path that is both
     * a source file and a unit (a tool config such as `vite.config.ts`) was
     * hashed from one buffer, so the two agree; the file's record is kept.
     *
     * @return array<string, object{contentHash: string}>
     */
    public function hashedPaths(): array
    {
        $byPath = [];
        foreach ($this->unparsedManifestHashes as $path => $contentHash) {
            $byPath[(string) $path] = new HashedPath((string) $path, $contentHash);
        }
        foreach ($this->units as $unit) {
            $byPath[$unit->configPath] = new HashedPath($unit->configPath, $unit->contentHash);
        }
        foreach ($this->files as $file) {
            $byPath[$file->relativePath] = new HashedPath($file->relativePath, $file->contentHash);
        }

        return $byPath;
    }
}
