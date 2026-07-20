<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\JsonConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class DiscoveryTest extends KnossosTestCase
{
    #[Group('discovery')]
    public function testMixedProjectDiscoveryFindsLanguageAndPackageInputs(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$root]));
        $result = $discoverer->discover($root);

        assertSame([
            'frontend/src/index.ts',
            'frontend/src/legacy.js',
            'src/CheckoutService.php',
        ], array_column($result->files, 'relativePath'));
        assertSame(['typescript', 'javascript', 'php'], array_column($result->files, 'language'));
        assertSame(['composer', 'node', 'node', 'typescript'], array_column($result->units, 'kind'));
        assertSame(64, strlen($result->inputHash));
        assertSame(64, strlen($result->configurationHash));
        assertSame([], $result->diagnostics);
        assertSame(false, file_exists($root . '/SHOULD_NOT_EXIST'));

        $typescriptUnits = array_values(array_filter(
            $result->units,
            fn($unit): bool => $unit->kind === 'typescript',
        ));
        assertSame(true, $typescriptUnits[0]->metadata['allow_js']);
        assertSame(['../shared'], $typescriptUnits[0]->metadata['references']);
        assertSame($result->inputHash, $discoverer->discover($root)->inputHash);
    }

    #[Group('discovery')]
    public function testDiscoveryAppliesCustomIgnoresAndFileLimits(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $result = (new ProjectDiscoverer(new DiscoveryConfig(
            [$root],
            ['frontend/src/legacy.js'],
        )))->discover($root);
        assertSame([
            'frontend/src/index.ts',
            'src/CheckoutService.php',
        ], array_column($result->files, 'relativePath'));

        assertThrows(
            fn() => (new ProjectDiscoverer(new DiscoveryConfig([$root], maxFiles: 1)))->discover($root),
            DiscoveryException::class,
        );

        $limited = (new ProjectDiscoverer(new DiscoveryConfig([$root], maxFileBytes: 10)))->discover($root);
        assertContains('DISCOVERY_FILE_TOO_LARGE', implode(' ', array_column($limited->diagnostics, 'code')));
    }

    #[Group('discovery')]
    public function testDiscoveryRejectsRootsAndSymlinksOutsideAllowedScope(): void
    {
        $base = sys_get_temp_dir() . '/knossos-discovery-' . bin2hex(random_bytes(6));
        $root = $base . '/project';
        $outside = $base . '/outside.php';
        mkdir($root, 0700, true);
        file_put_contents($outside, "<?php\n");
        symlink($outside, $root . '/escape.php');

        try {
            $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$root]));
            $result = $discoverer->discover($root);
            assertSame([], $result->files);
            assertSame('DISCOVERY_SYMLINK_ESCAPE', $result->diagnostics[0]->code);
            assertThrows(fn() => $discoverer->discover($base), DiscoveryException::class);
        } finally {
            unlink($root . '/escape.php');
            unlink($outside);
            rmdir($root);
            rmdir($base);
        }
    }

    #[Group('discovery')]
    public function testJsoncParsingPreservesCommentLikeStringContent(): void
    {
        $decoded = JsonConfig::decode(<<<'JSON'
    {
      // comment
      "url": "https://example.test/a//b",
      "items": [1, 2,],
    }
    JSON, true);

        assertSame('https://example.test/a//b', $decoded['url']);
        assertSame([1, 2], $decoded['items']);
    }
}
