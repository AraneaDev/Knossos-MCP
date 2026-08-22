<?php

declare(strict_types=1);

require __DIR__ . '/lib/git-ignore.php';

$root = dirname(__DIR__);
$errors = [];
$files = repositoryFiles($root);
foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    if (filesize($path) > 2_000_000 && $relative !== 'docs/Architecture-MCP-Project-Plan.docx') {
        $errors[] = "$relative exceeds the 2 MB repository limit";
    }
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if ($extension === 'json' && !str_starts_with(basename($relative), 'tsconfig')) {
        try {
            json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $errors[] = "$relative is invalid JSON: {$error->getMessage()}";
        }
    }
    if (in_array($extension, ['php', 'js', 'py', 'md', 'json', 'jsonc', 'yaml', 'yml', 'sh'], true)) {
        $contents = (string) file_get_contents($path);
        if (str_contains($contents, "\r")) {
            $errors[] = "$relative contains CR line endings";
        }
        if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $contents) === 1) {
            $errors[] = "$relative contains a private key";
        }
        if (preg_match('/(?:AKIA|ASIA)[A-Z0-9]{16}/', $contents) === 1) {
            $errors[] = "$relative contains an AWS access-key-shaped value";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
printf("Repository JSON, size, line-ending, and secret checks passed: %d files.\n", count($files));

/**
 * Every file this repository actually carries, relative to `$root`.
 *
 * Git-ignored files are skipped. This gate enforces repository rules — a 2 MB
 * ceiling, no CR line endings, no committed secrets — and an ignored file is by
 * definition not in the repository, so it cannot violate any of them. Checking it
 * anyway made the gate disagree with itself depending on where it ran: CI works
 * from a fresh checkout where ignored files do not exist, so it never saw them,
 * while a developer who had run Infection locally failed on
 * `chaos-infection-log.json` — a 3.4 MB generated artifact that .gitignore
 * excludes and no commit could ever contain.
 *
 * The hard-coded directory list below survives as a WALK filter, not as a
 * correctness one: `vendor/`, `node_modules/` and `coverage/` are already ignored,
 * but descending into them costs far more than the check itself, and `.git` must
 * never be read. It used to be the only filter, which is what made it a
 * hand-maintained approximation of .gitignore that drifted — every entry after
 * `.git` is there because someone hit a false failure. Deferring to git means the
 * next generated artifact needs no edit here.
 *
 * `workers/rust/target/` and `workers/rust/bin/` are the one exception where this
 * list IS load-bearing for correctness, not just cost: the quality container
 * builds and runs the crate (`cargo test`, `cargo llvm-cov`) before this gate
 * runs, but carries the source without a `.git` directory, so gitIgnoredPaths()
 * below cannot see them and fails open. Without the walk filter, every full
 * quality run fails here on its own build output — `workers/rust/target/debug/…`
 * and `workers/rust/bin/knossos-rust-worker` both exceed 2 MB. They are matched
 * by full relative path rather than by bare directory name the way the entries
 * above are, because `bin` is not a unique basename in this repository (`bin/`,
 * `workers/php/bin/`, `workers/python/bin/`, `workers/typescript/bin/` are all
 * tracked source directories); a basename match would silently stop walking
 * those too.
 *
 * They pay for themselves where git IS available too: a populated `target/` adds
 * 2,721 paths to the 1,127 the walk otherwise yields, every one of them handed to
 * `git check-ignore` only to come back ignored, for a byte-identical list of
 * files. The wall-clock cost of that varies with `target/`'s size and page-cache
 * state and is not asserted here; the path counts above are the deterministic
 * part of the argument.
 *
 * Untracked-but-not-ignored files are still checked: a file added in the working
 * tree and not yet committed is on its way in, and skipping it would let a secret
 * land in exactly the window this gate exists to cover.
 *
 * @return list<string>
 */
function repositoryFiles(string $root): array
{
    $skippedDirectories = ['.git', 'node_modules', 'vendor', 'coverage', '.knossos', '.mypy_cache', '.ruff_cache'];
    // Matched by full relative path, not bare basename -- see the docblock above.
    $skippedPathPrefixes = ['workers/rust/target/', 'workers/rust/bin/'];
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (array_intersect(explode('/', $relative), $skippedDirectories) !== []) {
            continue;
        }
        foreach ($skippedPathPrefixes as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                continue 2;
            }
        }
        $paths[] = $relative;
    }
    sort($paths, SORT_STRING);
    $ignored = gitIgnoredPaths($root, $paths);

    return array_values(array_filter($paths, static fn(string $path): bool => !isset($ignored[$path])));
}
