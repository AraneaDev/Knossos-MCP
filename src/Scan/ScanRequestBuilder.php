<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\ProjectUnit;

/**
 * The request a language worker is sent for a scan, apart from the files of
 * each batch.
 *
 * Everything here comes from the plan and from manifests discovery already
 * hashed, so every batch of a language carries the same request whichever
 * files it names.
 */
final class ScanRequestBuilder
{
    private function __construct() {}

    /**
     * Everything a scan request carries except `files`, which each batch
     * supplies for itself.
     *
     * @param list<object> $files
     * @return array<string, mixed>
     */
    public static function build(LanguageDescriptor $descriptor, ScanPlan $plan, array $files): array
    {
        $request = [
            'root' => $plan->preparation->discovery->rootRealpath,
            'limits' => ['max_files' => $plan->preparation->maxFiles, 'max_file_bytes' => $plan->preparation->maxFileBytes],
        ];
        if (in_array($descriptor->key, ['typescript', 'python'], true)) {
            // What discovery leaves out, so a worker resolving imports leaves
            // it out too instead of keeping a copy of the rules that drifts.
            // The manifest roots anchor build output as they did in the walk.
            $request['exclusions'] = (new IgnoreMatcher($plan->preparation->configuration->ignores))
                ->workerRules($plan->preparation->discovery->manifestRoots);
        }
        if ($descriptor->key === 'php') {
            $request['frameworks'] = array_keys(array_filter(['laravel' => $plan->preparation->laravel, 'symfony' => $plan->preparation->symfony]));
        } elseif ($descriptor->key === 'typescript') {
            $request['config_files'] = array_values(array_map(
                static fn($unit): string => $unit->configPath,
                array_filter($plan->preparation->discovery->units, static fn($unit): bool => $unit->kind === 'typescript'),
            ));
            $versions = self::typescriptVersions($plan->preparation->discovery->units);
            if ($versions !== []) {
                $request['typescript_versions'] = (object) $versions;
            }
            $vue = self::vueProjects($plan->preparation->discovery->units);
            if ($vue !== []) {
                $request['vue_projects'] = $vue;
            }
            $packages = self::packageDirectories($plan->preparation->discovery->units);
            if ($packages !== []) {
                $request['package_directories'] = $packages;
            }
            $request['source_files'] = self::sourceFiles($files);
        } elseif ($descriptor->key === 'python') {
            $request['frameworks'] = $plan->preparation->pythonFrameworks;
            // Which modules have contributions of their own, which name what
            // those modules re-export, so an importer need not.
            $request['source_files'] = self::sourceFiles($files);
        } elseif ($descriptor->key === 'rust') {
            $request['frameworks'] = $plan->preparation->rustFrameworks;
            $request['config_files'] = array_values(array_map(
                static fn($unit): string => $unit->configPath,
                array_filter($plan->preparation->discovery->units, static fn($unit): bool => $unit->kind === 'cargo'),
            ));
            // The declaration index is built from every Rust file, so a name
            // resolves the same whichever files a batch names.
            $request['source_files'] = self::sourceFiles($files);
        }

        return $request;
    }

    /**
     * The directories (`''` for the root) whose package.json depends on Vue.
     *
     * Its bundlers resolve `./Card` to `Card.vue`, and the worker needs to know
     * where that applies from manifests discovery hashed, not from which files
     * a request holds.
     *
     * @param list<ProjectUnit> $units
     * @return list<string>
     */
    private static function vueProjects(array $units): array
    {
        $directories = [];
        foreach ($units as $unit) {
            if ($unit->kind === 'node' && ($unit->metadata['vue'] ?? false) === true) {
                $directory = dirname($unit->configPath);
                $directories[] = $directory === '.' ? '' : $directory;
            }
        }
        sort($directories, SORT_STRING);

        return $directories;
    }

    /**
     * Every file of the language, sorted, whether or not this scan reads it
     * again.
     *
     * A tsconfig's program lists its own files, but a file no tsconfig
     * includes is read in a program of its neighbours, and that program must
     * hold the same files whichever of them a request names: an ambient
     * `declare module 'x'` satisfies `import ... from 'x'` only from inside
     * the importer's program, a test sees the globals its setup declares, and
     * the program's environment must not follow the request. The list also
     * tells the worker which of the files a program loaded have contributions
     * of their own, whose reads it then owes nobody, which is all the Python
     * worker takes it for.
     *
     * @param list<object> $files
     * @return list<string>
     */
    private static function sourceFiles(array $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[] = (string) $file->relativePath;
        }
        sort($paths, SORT_STRING);

        return array_values(array_unique($paths));
    }

    /**
     * The directory of every package.json (`''` for the root), sorted.
     *
     * A file no tsconfig includes is read as part of the package it sits in:
     * that package's TypeScript and installed types, not the root's.
     *
     * @param list<ProjectUnit> $units
     * @return list<string>
     */
    private static function packageDirectories(array $units): array
    {
        $directories = [];
        foreach ($units as $unit) {
            if ($unit->kind === 'node') {
                $directory = dirname($unit->configPath);
                $directories[] = $directory === '.' ? '' : $directory;
            }
        }
        sort($directories, SORT_STRING);

        return array_values(array_unique($directories));
    }

    /**
     * The TypeScript major each package.json declares, keyed by its directory (`''` for the root).
     *
     * TypeScript 6.0 changed defaults such as `types` and `strict`, so a
     * project on 5.x checked under the worker's bundled 6.x reported errors its
     * own compiler never does. The ranges come from manifests discovery already
     * hashed, so the worker decides which defaults apply without reading more.
     * A range naming no number, such as `latest`, is left out.
     *
     * @param list<ProjectUnit> $units
     * @return array<string, int>
     */
    private static function typescriptVersions(array $units): array
    {
        $versions = [];
        foreach ($units as $unit) {
            $range = $unit->kind === 'node' ? ($unit->metadata['typescript_range'] ?? null) : null;
            if (is_string($range) && preg_match('/(\d+)/', $range, $match) === 1) {
                $directory = dirname($unit->configPath);
                $versions[$directory === '.' ? '' : $directory] = (int) $match[1];
            }
        }
        ksort($versions, SORT_STRING);

        return $versions;
    }
}
