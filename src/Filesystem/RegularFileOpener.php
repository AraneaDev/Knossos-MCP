<?php

declare(strict_types=1);

namespace Knossos\Filesystem;

/**
 * Opens a regular file without allowing a concurrent FIFO replacement to hang
 * the process in open(2).
 *
 * PHP's file wrapper does not expose O_NONBLOCK for filesystem opens, and
 * stream_set_blocking() is too late because fopen() has already happened. On
 * Linux, FFI supplies the libc open call and the resulting descriptor is
 * wrapped as a PHP stream. Other platforms, or PHP builds without usable FFI,
 * use a short-lived PHP helper whose open is bounded while the parent drains
 * its output.
 */
final class RegularFileOpener
{
    private const O_NONBLOCK = 0x800;
    private const O_CLOEXEC = 0x80000;
    private const OPEN_DEADLINE_NS = 250_000_000;

    private static bool $ffiAttempted = false;
    private static ?object $libc = null;

    /**
     * Open a regular file, or return null for a missing, replaced, or refused
     * path. The returned resource is positioned at the beginning of the file.
     *
     * @return resource|null
     */
    public static function open(string $path): mixed
    {
        clearstatcache(true, $path);
        if (!self::isRegular(@stat($path))) {
            return null;
        }

        $handle = PHP_OS_FAMILY === 'Linux' ? self::openWithFfi($path) : null;
        $handle ??= self::openWithHelper($path);
        if (!is_resource($handle)) {
            return null;
        }

        if (!self::isRegular(fstat($handle))) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param array<array-key, int>|false $stat */
    private static function isRegular(array|false $stat): bool
    {
        return is_array($stat) && (($stat['mode'] ?? 0) & 0o170000) === 0o100000;
    }

    /** @return resource|null */
    private static function openWithFfi(string $path): mixed
    {
        $libc = self::libc();
        if ($libc === null) {
            return null;
        }

        $descriptor = $libc->open($path, self::O_NONBLOCK | self::O_CLOEXEC);
        if (!is_int($descriptor) || $descriptor < 0) {
            return null;
        }

        $handle = @fopen('php://fd/' . $descriptor, 'rb');
        if (!is_resource($handle)) {
            $libc->close($descriptor);

            return null;
        }

        return $handle;
    }

    private static function libc(): ?object
    {
        if (self::$ffiAttempted) {
            return self::$libc;
        }
        self::$ffiAttempted = true;
        if (!class_exists('FFI')) {
            return null;
        }

        try {
            self::$libc = \FFI::cdef(
                'int open(const char *pathname, int flags, ...); int close(int fd);',
                'libc.so.6',
            );
        } catch (\Throwable) {
            self::$libc = null;
        }

        return self::$libc;
    }

    /** @return resource|null */
    private static function openWithHelper(string $path): mixed
    {
        $helper = <<<'PHP'
$handle = @fopen($argv[1] ?? '', 'rb');
if (!is_resource($handle) || !is_array($stat = @fstat($handle)) || (($stat['mode'] ?? 0) & 0o170000) !== 0o100000) {
    exit(1);
}
if (@fwrite(STDOUT, "\x01") !== 1 || @stream_copy_to_stream($handle, STDOUT) === false) {
    exit(2);
}
PHP;
        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-r', $helper, $path],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process) || !isset($pipes[1]) || !is_resource($pipes[1])) {
            return null;
        }

        $output = $pipes[1];
        stream_set_blocking($output, false);
        $temporary = fopen('php://temp', 'w+b');
        $header = false;
        $deadline = hrtime(true) + self::OPEN_DEADLINE_NS;
        $failed = false;
        while (true) {
            if (!$header && hrtime(true) >= $deadline) {
                $failed = true;
                break;
            }

            $read = [$output];
            $write = [];
            $except = [];
            $wait = $header ? 50_000_000 : min(50_000_000, max(1, $deadline - hrtime(true)));
            $selected = @stream_select($read, $write, $except, intdiv($wait, 1_000_000_000), intdiv($wait % 1_000_000_000, 1_000));
            if ($selected === false) {
                $failed = true;
                break;
            }
            if ($selected > 0) {
                $chunk = fread($output, 65_536);
                if ($chunk === false) {
                    $failed = true;
                    break;
                }
                if ($chunk !== '') {
                    if (!$header) {
                        $header = $chunk[0] === "\x01";
                        $chunk = substr($chunk, 1);
                        if (!$header) {
                            $failed = true;
                            break;
                        }
                    }
                    if ($chunk !== '' && fwrite($temporary, $chunk) === false) {
                        $failed = true;
                        break;
                    }
                }
            }
            if (feof($output)) {
                break;
            }
        }

        if ($failed || !$header) {
            @proc_terminate($process, 9);
            fclose($output);
            @proc_close($process);
            fclose($temporary);

            return null;
        }

        fclose($output);
        $status = @proc_close($process);
        if ($status !== 0) {
            fclose($temporary);

            return null;
        }
        rewind($temporary);

        return $temporary;
    }
}
