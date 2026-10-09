<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\StdioServer;
use RuntimeException;

/**
 * Drives a server through its real stdio read loop, so a test sees exactly the
 * frames a client would receive, including the ones run() writes itself.
 */
trait StdioFrames
{
    /** What the last runFrames() call wrote to the server's diagnostics stream. */
    private string $stdioErrors = '';

    /**
     * Feed lines through the server's stdio loop and decode each response frame.
     *
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private function runFrames(StdioServer $server, array $lines): array
    {
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        $errors = fopen('php://temp', 'w+');
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Unable to allocate stdio test streams.');
        }
        fwrite($input, implode('', $lines));
        rewind($input);
        $server->run($input, $output, $errors);
        rewind($errors);
        $this->stdioErrors = (string) stream_get_contents($errors);
        rewind($output);
        $frames = [];
        foreach (explode("\n", trim((string) stream_get_contents($output))) as $line) {
            if ($line !== '') {
                $frames[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        foreach ([$input, $output, $errors] as $stream) {
            fclose($stream);
        }

        return $frames;
    }
}
