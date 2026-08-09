<?php

declare(strict_types=1);

require __DIR__ . '/lib/git-ignore.php';

$root = dirname(__DIR__);
$errors = [];
$files = repositoryFiles($root);
foreach ($files as $relative) {
    $file = new SplFileInfo($root . '/' . $relative);
    if ($file->getSize() > 2_000_000 && $relative !== 'docs/Architecture-MCP-Project-Plan.docx') {
        $errors[] = "$relative exceeds the 2 MB repository limit";
    }
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if ($extension === 'json' && !str_starts_with(basename($relative), 'tsconfig')) {
        try {
            json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $errors[] = "$relative is invalid JSON: {$error->getMessage()}";
        }
    }
    if (in_array($extension, ['php', 'js', 'py', 'md', 'json', 'jsonc', 'yaml', 'yml', 'sh'], true)) {
        $contents = (string) file_get_contents($file->getPathname());
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
 * Untracked-but-not-ignored files are still checked: a file added in the working
 * tree and not yet committed is on its way in, and skipping it would let a secret
 * land in exactly the window this gate exists to cover.
 *
 * @return list<string>
 */
function repositoryFiles(string $root): array
{
    $skippedDirectories = ['.git', 'node_modules', 'vendor', 'coverage', '.knossos', '.mypy_cache', '.ruff_cache'];
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
        $paths[] = $relative;
    }
    sort($paths, SORT_STRING);
    $ignored = gitIgnoredPaths($root, $paths);

    return array_values(array_filter($paths, static fn(string $path): bool => !isset($ignored[$path])));
}
