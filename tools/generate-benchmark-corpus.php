<?php

declare(strict_types=1);

if ($argc !== 3 || !preg_match('/^[1-9][0-9]*$/', $argv[2])) {
    fwrite(STDERR, "usage: generate-benchmark-corpus.php OUTPUT FILES_PER_LANGUAGE\n");
    exit(2);
}

$root = rtrim($argv[1], '/');
$count = (int) $argv[2];
if ($count > 1000) {
    fwrite(STDERR, "FILES_PER_LANGUAGE must not exceed 1000.\n");
    exit(2);
}
if (file_exists($root) && (!is_dir($root) || count(scandir($root) ?: []) > 2)) {
    fwrite(STDERR, "OUTPUT must be absent or an empty directory.\n");
    exit(2);
}

foreach ([$root . '/php/src', $root . '/typescript/src', $root . '/python/app'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create benchmark corpus directory.');
    }
}

file_put_contents($root . '/composer.json', json_encode([
    'name' => 'knossos/benchmark-corpus',
    'autoload' => ['psr-4' => ['Benchmark\\' => 'php/src/']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($root . '/typescript/package.json', "{\n  \"name\": \"knossos-benchmark-typescript\",\n  \"private\": true\n}\n");
file_put_contents($root . '/typescript/tsconfig.json', "{\n  \"compilerOptions\": {\"strict\": true},\n  \"include\": [\"src/**/*.ts\"]\n}\n");
file_put_contents($root . '/python/pyproject.toml', "[project]\nname = \"knossos-benchmark-python\"\nversion = \"0.0.0\"\n");
file_put_contents($root . '/python/app/__init__.py', "\"\"\"Deterministic benchmark package.\"\"\"\n");

for ($index = 0; $index < $count; ++$index) {
    $previous = max(0, $index - 1);
    file_put_contents(
        sprintf('%s/php/src/Service%04d.php', $root, $index),
        sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Benchmark;\n\nfinal class Service%04d\n{\n    public function dependency(): Service%04d\n    {\n        return new Service%04d();\n    }\n}\n",
            $index,
            $previous,
            $previous,
        ),
    );
    file_put_contents(
        sprintf('%s/typescript/src/service-%04d.ts', $root, $index),
        sprintf(
            "import { Service%04d } from './service-%04d.js';\n\nexport class Service%04d {\n  dependency(): Service%04d {\n    return new Service%04d();\n  }\n}\n",
            $previous,
            $previous,
            $index,
            $previous,
            $previous,
        ),
    );
    file_put_contents(
        sprintf('%s/python/app/service_%04d.py', $root, $index),
        sprintf(
            "from app.service_%04d import Service%04d\n\n\nclass Service%04d:\n    def dependency(self) -> Service%04d:\n        return Service%04d()\n",
            $previous,
            $previous,
            $index,
            $previous,
            $previous,
        ),
    );
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    if ($entry->isFile()) {
        $files[str_replace($root . '/', '', $entry->getPathname())] = hash_file('sha256', $entry->getPathname());
    }
}
ksort($files, SORT_STRING);

fwrite(STDOUT, json_encode([
    'files_per_language' => $count,
    'generated_source_files' => $count * 3,
    'corpus_sha256' => hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
], JSON_THROW_ON_ERROR) . "\n");
