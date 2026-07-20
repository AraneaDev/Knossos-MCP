<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

trait TempTrees
{
    public function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $item) {
            $rel = substr($item->getPathname(), strlen($from) + 1);
            $dest = $to . '/' . $rel;
            if ($item->isDir()) {
                @mkdir($dest, 0o777, true);
                continue;
            }
            @mkdir(dirname($dest), 0o777, true);
            copy($item->getPathname(), $dest);
        }
    }

    public function removeTempTree(string $root): void
    {
        $prefix = rtrim(sys_get_temp_dir(), '/') . '/knossos-stale-';
        if (!str_starts_with($root, $prefix)) {
            throw new \RuntimeException('Refusing to remove an unexpected fixture path.');
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($root);
    }

    public function removeFixtureTree(string $root): void
    {
        $prefix = rtrim(sys_get_temp_dir(), '/') . '/knossos-incremental-';
        if (!str_starts_with($root, $prefix)) {
            throw new \RuntimeException('Refusing to remove an unexpected fixture path.');
        }
        foreach (glob($root . '/src/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($root . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($root . '/src')) {
            rmdir($root . '/src');
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }

    /** @param non-empty-list<string> $command */
    public function runFixtureCommand(array $command): void
    {
        [$exit, $stdout, $stderr] = $this->runFixtureCommandOutput($command);
        if ($exit !== 0) {
            throw new \RuntimeException('Fixture command failed: ' . trim($stdout . ' ' . $stderr));
        }
    }

    /** @param non-empty-list<string> $command @return array{0: int, 1: string, 2: string} */
    public function runFixtureCommandOutput(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start fixture command.');
        }
        fclose($pipes[0]);

        // Drain stdout and stderr together. Reading one to EOF before touching
        // the other deadlocks as soon as the child fills the pipe nobody is
        // reading (64 KiB on Linux): the child blocks on write, we block on read.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, null) === false) {
                break;
            }
            foreach ($open as $key => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$key]);
                    }
                    continue;
                }
                $buffers[$key] .= $chunk;
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }

        return [proc_close($process), $buffers[1], $buffers[2]];
    }

    public function removeGitFixture(string $root): void
    {
        $prefix = rtrim(sys_get_temp_dir(), '/') . '/knossos-git-';
        if (!str_starts_with($root, $prefix) || !is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
