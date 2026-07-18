<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$excluded = ['.git', 'node_modules', 'vendor', 'coverage', '.knossos', '.mypy_cache', '.ruff_cache'];
$errors = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $parts = explode('/', $relative);
    if (array_intersect($parts, $excluded) !== []) {
        continue;
    }
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
echo "Repository JSON, size, line-ending, and secret checks passed.\n";
