<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use Throwable;

final class WorkerServer
{
    public const VERSION = '0.2.0';

    public function __construct(private readonly PhpScanner $scanner = new PhpScanner()) {}

    public function run(): int
    {
        while (($line = fgets(STDIN)) !== false) {
            try {
                $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($request) || array_is_list($request)) {
                    throw new WorkerInputException('Request must be a JSON object.');
                }
                $this->handle($request);
            } catch (Throwable $error) {
                $id = isset($request) && is_array($request) ? ($request['id'] ?? null) : null;
                $this->write([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32602, 'message' => $error->getMessage()],
                ]);
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $request */
    private function handle(array $request): void
    {
        $method = $request['method'] ?? null;
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];
        if (!is_string($method) || !is_array($params)) {
            throw new WorkerInputException('Method and params are required.');
        }

        if ($method === 'cancel') {
            return;
        }

        $result = match ($method) {
            'initialize' => $this->initialize($params),
            'discover' => $this->discover($params),
            'scan' => $this->scan($params),
            'shutdown' => ['status' => 'bye'],
            default => throw new WorkerInputException(sprintf('Unknown method: %s', $method)),
        };

        $this->write(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result]);
        if ($method === 'shutdown') {
            exit(0);
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function initialize(array $params): array
    {
        return [
            'id' => 'knossos.php',
            'version' => self::VERSION,
            'protocol_version' => '1.0',
            'output_schema_version' => '1.0',
            'languages' => ['php'],
            'file_extensions' => ['php'],
            'capabilities' => ['discover', 'partial_ast'],
        ];
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function discover(array $params): array
    {
        $root = $this->validatedRoot($params);

        return [
            'root' => $root,
            'languages' => ['php'],
            'file_extensions' => ['php'],
            'config_files' => $this->relativeComposerFiles($root),
        ];
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function scan(array $params): array
    {
        $root = $this->validatedRoot($params);
        $files = $params['files'] ?? null;
        if (!is_array($files) || !array_is_list($files)) {
            throw new WorkerInputException('Scan files must be a list of project-relative paths.');
        }
        $limits = is_array($params['limits'] ?? null) ? $params['limits'] : [];
        $maxFiles = is_int($limits['max_files'] ?? null) ? $limits['max_files'] : 100_000;
        $maxFileBytes = is_int($limits['max_file_bytes'] ?? null) ? $limits['max_file_bytes'] : 2_000_000;
        $frameworks = $params['frameworks'] ?? [];
        if (!is_array($frameworks) || !array_is_list($frameworks)) {
            throw new WorkerInputException('Frameworks must be a list.');
        }
        $laravel = in_array('laravel', $frameworks, true);
        $symfony = in_array('symfony', $frameworks, true);
        if ($maxFiles < 1 || $maxFileBytes < 1 || count($files) > $maxFiles) {
            throw new WorkerInputException('PHP scan limits are invalid or exceeded.');
        }

        $count = 0;
        foreach ($files as $relativePath) {
            if (!is_string($relativePath)) {
                throw new WorkerInputException('Scan file paths must be strings.');
            }
            $absolutePath = $this->validatedFile($root, $relativePath);
            $size = filesize($absolutePath);
            if ($size === false || $size > $maxFileBytes) {
                throw new WorkerInputException(sprintf('PHP scan file exceeds the size limit: %s', $relativePath));
            }
            $contribution = $this->scanner->scan($root, $absolutePath, $relativePath, $laravel, $symfony);
            $this->write([
                'jsonrpc' => '2.0',
                'method' => 'scan/contribution',
                'params' => $contribution,
            ]);
            ++$count;
        }

        return ['files_scanned' => $count];
    }

    /** @param array<string, mixed> $params */
    private function validatedRoot(array $params): string
    {
        $root = $params['root'] ?? null;
        if (!is_string($root) || $root === '') {
            throw new WorkerInputException('A project root is required.');
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new WorkerInputException('Project root does not exist.');
        }

        return str_replace('\\', '/', $real);
    }

    private function validatedFile(string $root, string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if (
            $normalized === ''
            || str_starts_with($normalized, '/')
            || str_contains($normalized, "\0")
            || strtolower(pathinfo($normalized, PATHINFO_EXTENSION)) !== 'php'
        ) {
            throw new WorkerInputException('PHP scan path is invalid.');
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new WorkerInputException('PHP scan path contains an invalid segment.');
            }
        }

        $real = realpath($root . '/' . $normalized);
        if ($real === false || !is_file($real)) {
            throw new WorkerInputException('PHP scan file does not exist.');
        }
        $real = str_replace('\\', '/', $real);
        if (!($real === $root || str_starts_with($real, rtrim($root, '/') . '/'))) {
            throw new WorkerInputException('PHP scan path escapes the project root.');
        }

        return $real;
    }

    /** @return list<string> */
    private function relativeComposerFiles(string $root): array
    {
        $path = $root . '/composer.json';
        return is_file($path) ? ['composer.json'] : [];
    }

    /** @param array<string, mixed> $message */
    private function write(array $message): void
    {
        fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }
}
