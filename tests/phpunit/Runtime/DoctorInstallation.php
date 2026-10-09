<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\WorkerSourceHash;

/**
 * A throwaway installation root for `doctor`, with a scripted Rust worker.
 *
 * The real PHP, TypeScript and Python workers and the migrations are symlinked
 * in, so every check but the Rust one reports what the host really has; the
 * Rust worker is a script whose announced `source_hash` the test chooses.
 */
final class DoctorInstallation
{
    /** An installation whose Rust worker announces `$sourceHash`, see {@see self::rustWorker()}. */
    public static function create(?string $sourceHash): string
    {
        $root = sys_get_temp_dir() . '/knossos-doctor-install-' . uniqid('', true);
        mkdir($root . '/workers', 0777, true);
        $repository = dirname(__DIR__, 3);
        symlink($repository . '/migrations', $root . '/migrations');
        foreach (['php', 'typescript', 'python'] as $language) {
            symlink($repository . '/workers/' . $language, $root . '/workers/' . $language);
        }
        self::rustWorker($root, $sourceHash);

        return $root;
    }

    /** Remove an installation, unlinking its symlinks rather than following them. */
    public static function remove(string $root): void
    {
        exec('rm -rf ' . escapeshellarg($root));
    }

    /**
     * Put a scripted Rust worker and a small Rust source tree in `$root`.
     *
     * The script answers `initialize` like the real binary, announcing
     * `$sourceHash`: null announces the hash of the tree beside it (a fresh
     * build), '' announces none (a worker from before the field existed).
     */
    public static function rustWorker(string $root, ?string $sourceHash): void
    {
        $worker = $root . '/workers/rust';
        mkdir($worker . '/src', 0777, true);
        mkdir($worker . '/bin');
        file_put_contents($worker . '/Cargo.toml', "[package]\nname = \"fake\"\n");
        file_put_contents($worker . '/src/main.rs', "fn main() {}\n");
        $sourceHash ??= WorkerSourceHash::of($worker);
        $manifest = [
            'id' => 'knossos.rust', 'version' => '0.2.0', 'protocol_version' => '1.0', 'output_schema_version' => '1.0',
            'languages' => ['rust'], 'file_extensions' => ['rs'], 'capabilities' => ['partial_ast'],
        ] + ($sourceHash === '' ? [] : ['source_hash' => $sourceHash]);
        $script = <<<'PHP'
            #!/usr/bin/env php
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $request = json_decode($line, true);
                $result = $request['method'] === 'initialize' ? json_decode(MANIFEST, true) : null;
                fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]) . "\n");
                fflush(STDOUT);
                if ($request['method'] === 'shutdown') {
                    exit(0);
                }
            }
            PHP;
        file_put_contents($worker . '/bin/knossos-rust-worker', str_replace('MANIFEST', var_export(json_encode($manifest), true), $script));
        chmod($worker . '/bin/knossos-rust-worker', 0755);
    }

    /** Skip unless node and python3 are on PATH, which the real workers need. */
    public static function runtimesMissing(): ?string
    {
        foreach (['node', 'python3'] as $runtime) {
            if (trim((string) @shell_exec('command -v ' . $runtime . ' 2>/dev/null')) === '') {
                return $runtime . ' is not on PATH.';
            }
        }

        return null;
    }
}
