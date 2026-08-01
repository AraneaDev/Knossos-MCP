<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use Throwable;

/**
 * The worker's protocol loop: reads requests, dispatches, replies.
 *
 * Stdout carries protocol frames only, so anything diagnostic goes to stderr — a
 * stray write would corrupt the stream the host is parsing.
 */
final class WorkerServer
{
    public const VERSION = '0.3.0';

    /** Bytes read when probing an extensionless file's shebang; one short line is enough. */
    private const SHEBANG_PROBE_BYTES = 256;

    public function __construct(private readonly PhpScanner $scanner = new PhpScanner()) {}
    /** The protocol loop: read a request, dispatch it, write the reply. */

    public function run(): int
    {
        while (($line = fgets(STDIN)) !== false) {
            // Reset per iteration so a malformed-JSON error is never attributed
            // to the previous request's id.
            unset($request);
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

    /**
     * Dispatch one request to its method handler.
     *
     * @param array<string, mixed> $request
     */
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

    /**
     * Answer the handshake with this worker's identity and protocol version.
     *
     * @param array<string, mixed> $params @return array<string, mixed>
     */
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

    /**
     * Report which of the offered files this scanner claims.
     *
     * @param array<string, mixed> $params @return array<string, mixed>
     */
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

    /**
     * Analyse the requested files and return their contributions.
     *
     * @param array<string, mixed> $params @return array<string, mixed>
     */
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
            // A malformed path stays fatal: it names no file, so there is nothing
            // to attribute a diagnostic to, and echoing it into a contribution
            // would emit an owner key the graph rejects anyway.
            $this->assertScannablePath($relativePath);
            // Everything past that point is about one file, not the request: a
            // file deleted between discovery and scan, one over the byte cap, a
            // symlink leaving the tree, or an unexpected fault while collecting
            // all degrade to a diagnostic about that file. Raising them would
            // discard the facts every other file in the batch had already
            // contributed, so one unscannable file produced no graph at all.
            try {
                $absolutePath = $this->validatedFile($root, $relativePath);
                $size = filesize($absolutePath);
                if ($size === false || $size > $maxFileBytes) {
                    throw new WorkerInputException(
                        sprintf('PHP scan file exceeds the size limit: %s', $relativePath),
                    );
                }
                $contribution = $this->scanner->scan($root, $absolutePath, $relativePath, $laravel, $symfony);
            } catch (WorkerInputException $error) {
                $contribution = self::rejection($relativePath, 'PHP_UNSCANNABLE_FILE', $error->getMessage());
            } catch (Throwable $error) {
                $contribution = self::rejection($relativePath, 'PHP_INTERNAL_ERROR', $error->getMessage());
            }
            $this->write([
                'jsonrpc' => '2.0',
                'method' => 'scan/contribution',
                'params' => $contribution,
            ]);
            ++$count;
        }

        return ['files_scanned' => $count];
    }

    /**
     * A contribution that carries nothing but the reason one file was skipped.
     *
     * @return array<string, mixed>
     */
    private static function rejection(string $relativePath, string $code, string $message): array
    {
        return [
            'owner_key' => 'knossos.php:file:' . $relativePath,
            'nodes' => [],
            'edges' => [],
            'diagnostics' => [[
                'severity' => 'error',
                'code' => $code,
                'message' => $message,
                'evidence' => ['path' => $relativePath, 'start_line' => 1, 'end_line' => 1],
            ]],
        ];
    }

    /**
     * The project root, rejected unless it is an existing directory.
     *
     * @param array<string, mixed> $params
     */
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
    /**
     * A requested path, rejected unless it can name a file inside the project.
     *
     * Kept separate from reading the file: a path of the wrong shape cannot be
     * attributed to any file, so it fails the request, while a well-formed path
     * that simply cannot be scanned costs only that file.
     */
    private function assertScannablePath(string $relativePath): void
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if (
            $normalized === ''
            || str_starts_with($normalized, '/')
            || str_contains($normalized, "\0")
        ) {
            throw new WorkerInputException('PHP scan path is invalid.');
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new WorkerInputException('PHP scan path contains an invalid segment.');
            }
        }
    }

    /**
     * A requested file, rejected unless it is inside the project root.
     *
     * A `.php` extension is the usual signal, but discovery also classifies an
     * extensionless script by its shebang — `artisan`, `bin/console`, this
     * project's own `workers/php/bin/worker` — and routes it here. Gating on the
     * extension alone rejected exactly the files discovery had just resolved, so
     * an extensionless PHP script anywhere in a tree failed the whole scan.
     */
    private function validatedFile(string $root, string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if ($extension !== 'php' && $extension !== '') {
            throw new WorkerInputException('PHP scan path is invalid.');
        }

        $real = realpath($root . '/' . $normalized);
        if ($real === false || !is_file($real)) {
            throw new WorkerInputException('PHP scan file does not exist.');
        }
        $real = str_replace('\\', '/', $real);
        if (!($real === $root || str_starts_with($real, rtrim($root, '/') . '/'))) {
            throw new WorkerInputException('PHP scan path escapes the project root.');
        }
        // Resolved last: reading the file is only safe once the path is known to
        // be inside the root, so an extensionless path cannot be used to probe
        // the first line of an arbitrary file elsewhere on the host.
        if ($extension === '' && !self::namesPhpInShebang($real)) {
            throw new WorkerInputException('PHP scan path is invalid.');
        }

        return $real;
    }

    /**
     * Whether a script's first line names PHP as its interpreter.
     *
     * Mirrors the discoverer's rule (Knossos\Discovery\ProjectDiscoverer): match
     * both `#!/usr/bin/php` and `#!/usr/bin/env php`, tolerate a version suffix
     * such as `php8.3`, and anchor to a word boundary so a path like
     * `/opt/phpstorm/bin/foo` is not read as a PHP script. Only the first line is
     * read, so the cost is one bounded read per extensionless file.
     */
    private static function namesPhpInShebang(string $absolutePath): bool
    {
        $handle = @fopen($absolutePath, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        try {
            $first = (string) fgets($handle, self::SHEBANG_PROBE_BYTES);
        } finally {
            fclose($handle);
        }

        return str_starts_with($first, '#!') && preg_match('#\b(php)[0-9.]*\b#i', $first) === 1;
    }

    /**
     * Composer manifests among the requested files, which drive PSR-4 resolution.
     *
     * @return list<string>
     */
    private function relativeComposerFiles(string $root): array
    {
        $path = $root . '/composer.json';
        return is_file($path) ? ['composer.json'] : [];
    }

    /**
     * Write one protocol frame to stdout, which carries frames only.
     *
     * @param array<string, mixed> $message
     */
    private function write(array $message): void
    {
        // A scanned identifier or string literal may legally carry raw bytes
        // >= 0x80 (e.g. an ISO-8859-1 class name). JSON_INVALID_UTF8_SUBSTITUTE
        // degrades that one name to U+FFFD instead of throwing and aborting the
        // whole batch after facts were already streamed.
        fwrite(
            STDOUT,
            json_encode(
                $message,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ) . "\n",
        );
        fflush(STDOUT);
    }
}
