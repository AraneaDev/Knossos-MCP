<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class SourceExcerptTest extends KnossosTestCase
{
    #[Group('query')]
    public function testIncludeSourceInlinesBoundedSnippets(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('mixed');
        try {
            $queries = new ArchitectureQueryService($pdo);
            $result = $queries->architectureContext($projectId, files: ['src/CheckoutService.php'], includeSource: true);
            $dossiers = $result->data['context']['sections']['dossiers'];
            assertSame('included', $dossiers['status']);
            $first = $dossiers['content']['items'][0];
            assertSame('included', $first['snippet']['status']);
            assertSame('src/CheckoutService.php', $first['snippet']['path']);
            assertSame(true, strlen($first['snippet']['code']) > 0);
            assertSame(true, $first['snippet']['end_line'] - $first['snippet']['start_line'] < 40);
            assertSame(true, str_contains(implode(' ', $result->warnings), 'working tree'));

            // Default off: no snippet key at all.
            $plain = $queries->architectureContext($projectId, files: ['src/CheckoutService.php']);
            assertSame(false, array_key_exists('snippet', $plain->data['context']['sections']['dossiers']['content']['items'][0]));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testReaderRefusesPathsOutsideTheProjectRoot(): void
    {
        // The reader is the sole disk-touching seam; test its containment
        // guard directly with a real filesystem layout.
        $base = sys_get_temp_dir() . '/knossos-excerpt-' . bin2hex(random_bytes(6));
        mkdir($base . '/project/src', 0755, true);
        try {
            file_put_contents($base . '/project/src/Ok.php', "<?php\n// line 2\n// line 3\n");
            file_put_contents($base . '/secret.txt', 'top secret');
            $reader = new \Knossos\Query\SourceExcerptReader();

            $ok = $reader->read($base . '/project', 'src/Ok.php', 1, 3);
            assertSame('included', $ok['status']);
            assertSame(true, str_contains($ok['code'], 'line 2'));

            $escape = $reader->read($base . '/project', '../secret.txt', 1, 1);
            assertSame('unavailable', $escape['status']);
            assertSame('outside_project_root_or_missing', $escape['reason']);

            $missing = $reader->read($base . '/project', 'src/Gone.php', 1, 1);
            assertSame('unavailable', $missing['status']);

            $stale = $reader->read($base . '/project', 'src/Ok.php', 99, 120);
            assertSame('unavailable', $stale['status']);
            assertSame('stale_line_evidence', $stale['reason']);
        } finally {
            @unlink($base . '/project/src/Ok.php');
            @unlink($base . '/secret.txt');
            @rmdir($base . '/project/src');
            @rmdir($base . '/project');
            @rmdir($base);
        }
    }
}
