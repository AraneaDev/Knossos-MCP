<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use DirectoryIterator;
use Throwable;

final readonly class ProjectDiscoverer
{
    private RootGuard $rootGuard;
    private IgnoreMatcher $ignoreMatcher;

    public function __construct(private DiscoveryConfig $config)
    {
        $this->rootGuard = new RootGuard($config->allowedRoots);
        $this->ignoreMatcher = new IgnoreMatcher($config->ignorePatterns);
    }

    public function discover(string $requestedRoot): DiscoveryResult
    {
        $root = $this->rootGuard->resolve($requestedRoot);
        $files = [];
        $units = [];
        $diagnostics = [];
        $stack = [$root];
        $inputCount = 0;

        while ($stack !== []) {
            $directory = array_pop($stack);
            try {
                $entries = new DirectoryIterator($directory);
            } catch (Throwable $error) {
                $diagnostics[] = new DiscoveryDiagnostic(
                    'warning',
                    'DISCOVERY_DIRECTORY_UNREADABLE',
                    $error->getMessage(),
                    $this->relative($root, $directory),
                );
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                $absolute = str_replace('\\', '/', $entry->getPathname());
                $relative = $this->relative($root, $absolute);
                $configurationFile = in_array(strtolower(basename($relative)), ['knossos.json', 'knossos.jsonc'], true);
                if (!$configurationFile && $this->ignoreMatcher->matches($relative)) {
                    continue;
                }

                if ($entry->isLink()) {
                    $target = realpath($absolute);
                    $escapes = $target === false || !RootGuard::contains($root, str_replace('\\', '/', $target));
                    $diagnostics[] = new DiscoveryDiagnostic(
                        'warning',
                        $escapes ? 'DISCOVERY_SYMLINK_ESCAPE' : 'DISCOVERY_SYMLINK_SKIPPED',
                        $escapes
                            ? 'Symlink target escapes the project root and was rejected.'
                            : 'Symlink was skipped because discovery does not follow symlinks.',
                        $relative,
                    );
                    continue;
                }

                if ($entry->isDir()) {
                    $stack[] = $absolute;
                    continue;
                }
                if (!$entry->isFile()) {
                    continue;
                }

                $language = self::languageFor($relative);
                $unitKind = self::unitKindFor($relative);
                if ($language === null && $unitKind === null) {
                    continue;
                }

                ++$inputCount;
                if ($inputCount > $this->config->maxFiles) {
                    throw new DiscoveryException(sprintf('Discovery file limit exceeded (%d).', $this->config->maxFiles));
                }

                $size = $entry->getSize();
                if ($size > $this->config->maxFileBytes) {
                    $diagnostics[] = new DiscoveryDiagnostic(
                        'warning',
                        'DISCOVERY_FILE_TOO_LARGE',
                        sprintf('File exceeds the %d-byte discovery limit.', $this->config->maxFileBytes),
                        $relative,
                    );
                    continue;
                }

                $fingerprint = FileFingerprint::compute($absolute);
                if ($fingerprint === null) {
                    $diagnostics[] = new DiscoveryDiagnostic(
                        'warning',
                        'DISCOVERY_FILE_UNREADABLE',
                        'File could not be hashed.',
                        $relative,
                    );
                    continue;
                }
                $contentHash = $fingerprint->contentHash;

                if ($language !== null) {
                    $files[] = new DiscoveredFile(
                        $relative,
                        $absolute,
                        $language,
                        $size,
                        max(0, $entry->getMTime()),
                        $contentHash,
                        $fingerprint->lineCount,
                    );
                }

                if ($unitKind !== null) {
                    $unit = $this->readUnit($unitKind, $relative, $absolute, $contentHash, $diagnostics);
                    if ($unit !== null) {
                        $units[] = $unit;
                    }
                }
            }
        }

        usort($files, static fn(DiscoveredFile $left, DiscoveredFile $right): int =>
            $left->relativePath <=> $right->relativePath);
        usort($units, static fn(ProjectUnit $left, ProjectUnit $right): int =>
            [$left->kind, $left->configPath] <=> [$right->kind, $right->configPath]);

        $inputParts = array_map(
            static fn(DiscoveredFile $file): string => $file->relativePath . '=' . $file->contentHash,
            $files,
        );
        $configParts = array_map(
            static fn(ProjectUnit $unit): string => $unit->kind . ':' . $unit->configPath . '=' . $unit->contentHash,
            $units,
        );

        return new DiscoveryResult(
            $root,
            $files,
            $units,
            $diagnostics,
            hash('sha256', implode("\n", $inputParts)),
            hash('sha256', implode("\n", $configParts)),
        );
    }

    /**
     * @param list<DiscoveryDiagnostic> $diagnostics
     */
    private function readUnit(
        string $kind,
        string $relative,
        string $absolute,
        string $contentHash,
        array &$diagnostics,
    ): ?ProjectUnit {
        $contents = file_get_contents($absolute);
        if ($contents === false) {
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_CONFIG_UNREADABLE',
                'Configuration file could not be read.',
                $relative,
            );
            return null;
        }

        if ($kind === 'python') {
            $name = null;
            if (preg_match('/^\s*name\s*=\s*["\']([^"\']+)["\']/m', $contents, $matches) === 1) {
                $name = $matches[1];
            }
            return new ProjectUnit($kind, $relative, $contentHash, ['name' => $name]);
        }

        try {
            $decoded = JsonConfig::decode($contents, in_array($kind, ['typescript', 'knossos'], true));
        } catch (DiscoveryException $error) {
            $diagnostics[] = new DiscoveryDiagnostic(
                'warning',
                'DISCOVERY_CONFIG_INVALID',
                $error->getMessage(),
                $relative,
            );
            return null;
        }

        $metadata = match ($kind) {
            'composer' => [
                'name' => is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
                'psr4' => self::composerPsr4($decoded),
                'requires' => self::composerRequirements($decoded),
            ],
            'node' => [
                'name' => is_string($decoded['name'] ?? null) ? $decoded['name'] : null,
                'type' => is_string($decoded['type'] ?? null) ? $decoded['type'] : null,
                'workspaces' => self::workspaces($decoded['workspaces'] ?? []),
            ],
            'typescript' => self::typescriptMetadata($decoded),
            'knossos' => ['version' => $decoded['version'] ?? null],
            default => [],
        };

        return new ProjectUnit($kind, $relative, $contentHash, $metadata);
    }

    /** @param array<string, mixed> $composer @return array<string, string|list<string>> */
    private static function composerPsr4(array $composer): array
    {
        $mappings = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $composer[$section]['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $namespace => $paths) {
                if (!is_string($namespace) || (!is_string($paths) && !is_array($paths))) {
                    continue;
                }
                if (is_array($paths) && !array_is_list($paths)) {
                    continue;
                }
                $mappings[$namespace] = $paths;
            }
        }

        ksort($mappings, SORT_STRING);
        return $mappings;
    }

    /** @param array<string, mixed> $composer @return array<string, string> */
    private static function composerRequirements(array $composer): array
    {
        $requirements = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (is_array($composer[$section] ?? null) ? $composer[$section] : [] as $package => $constraint) {
                if (is_string($package) && is_string($constraint)) {
                    $requirements[strtolower($package)] = $constraint;
                }
            }
        }
        ksort($requirements, SORT_STRING);
        return $requirements;
    }

    /** @return list<string> */
    private static function workspaces(mixed $workspaces): array
    {
        if (is_array($workspaces) && isset($workspaces['packages'])) {
            $workspaces = $workspaces['packages'];
        }
        if (!is_array($workspaces) || !array_is_list($workspaces)) {
            return [];
        }

        return array_values(array_filter($workspaces, 'is_string'));
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private static function typescriptMetadata(array $config): array
    {
        $compiler = is_array($config['compilerOptions'] ?? null) ? $config['compilerOptions'] : [];
        $references = [];
        if (is_array($config['references'] ?? null)) {
            foreach ($config['references'] as $reference) {
                if (is_array($reference) && is_string($reference['path'] ?? null)) {
                    $references[] = $reference['path'];
                }
            }
        }

        return [
            'extends' => is_string($config['extends'] ?? null) ? $config['extends'] : null,
            'allow_js' => ($compiler['allowJs'] ?? false) === true,
            'base_url' => is_string($compiler['baseUrl'] ?? null) ? $compiler['baseUrl'] : null,
            'paths' => is_array($compiler['paths'] ?? null) ? $compiler['paths'] : [],
            'references' => $references,
        ];
    }

    private static function languageFor(string $relativePath): ?string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        return match ($extension) {
            'php' => 'php',
            'ts', 'tsx', 'mts', 'cts' => 'typescript',
            'js', 'jsx', 'mjs', 'cjs' => 'javascript',
            'py', 'pyi' => 'python',
            default => null,
        };
    }

    private static function unitKindFor(string $relativePath): ?string
    {
        $basename = strtolower(basename($relativePath));
        if ($basename === 'composer.json') {
            return 'composer';
        }
        if ($basename === 'knossos.json' || $basename === 'knossos.jsonc') {
            return 'knossos';
        }
        if ($basename === 'package.json') {
            return 'node';
        }
        if ($basename === 'pyproject.toml') {
            return 'python';
        }
        if ($basename === 'tsconfig.json' || (str_starts_with($basename, 'tsconfig.') && str_ends_with($basename, '.json'))) {
            return 'typescript';
        }

        return null;
    }

    private function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return ltrim(substr($path, strlen($root)), '/');
    }
}
