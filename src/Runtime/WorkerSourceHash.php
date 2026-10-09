<?php

declare(strict_types=1);

namespace Knossos\Runtime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The hash of the source a compiled worker is built from.
 *
 * The Rust worker embeds this hash at build time (`workers/rust/build.rs`) and
 * announces it as `source_hash` in its manifest. `git pull` updates the source
 * but not the git-ignored binary, so comparing the two is how `doctor` notices
 * a binary built from older source. One definition, mirrored in
 * `workers/rust/src/source_hash.rs` and held together by a shared fixture and
 * golden value:
 *
 * - The inputs are `Cargo.toml`, `Cargo.lock` and `build.rs` at the crate root
 *   when they are regular files, plus every regular file below `src/`. A
 *   symlinked directory below `src/` is not descended into.
 * - Each input is named by its path relative to the crate root, with `/`
 *   separators, and the names are sorted by their bytes.
 * - The hash is the lowercase hex SHA-256 of, for each input in that order, its
 *   name, a NUL byte, the lowercase hex SHA-256 of its bytes, and `\n`.
 * - A crate root without a `src/` directory has no hash.
 *
 * Content only, never mtimes: a checkout resets mtimes, so they say nothing
 * about which source a binary came from.
 */
final class WorkerSourceHash
{
    /** The crate-root files that feed the build besides `src/`. */
    private const ROOT_INPUTS = ['Cargo.toml', 'Cargo.lock', 'build.rs'];

    /** The source hash of the crate rooted at `$root`, or null without a `src/`. */
    public static function of(string $root): ?string
    {
        if (!is_dir($root . '/src')) {
            return null;
        }
        $inputs = array_values(array_filter(self::ROOT_INPUTS, static fn(string $name): bool => is_file($root . '/' . $name)));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile()) {
                $inputs[] = 'src/' . substr($file->getPathname(), strlen($root . '/src/'));
            }
        }
        sort($inputs, SORT_STRING);

        $digest = hash_init('sha256');
        foreach ($inputs as $name) {
            $hash = @hash_file('sha256', $root . '/' . $name);
            if ($hash === false) {
                return null;
            }
            hash_update($digest, $name . "\0" . $hash . "\n");
        }

        return hash_final($digest);
    }
}
