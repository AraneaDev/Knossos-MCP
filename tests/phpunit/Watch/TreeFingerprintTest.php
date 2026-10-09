<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Discovery\AllowedRoots;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\TreeFingerprint;
use PHPUnit\Framework\Attributes\Group;

/** What one walk of a tree tells the watcher: the fingerprint, the directories it opened, and when it began. */
final class TreeFingerprintTest extends KnossosTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/new', 0o700, true);
        file_put_contents($this->root . '/src/a.ts', "export const a = 1;\n");
        file_put_contents($this->root . '/package.json', '{"name":"web"}');
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    #[Group('watch')]
    public function testObserveReturnsTheFingerprintTheDirectoriesAndTheWalksStart(): void
    {
        $roots = AllowedRoots::of([$this->root]);
        $before = time();

        [$fingerprint, $directories, $startedAt] = TreeFingerprint::observe($this->root, $roots);

        assertSame(['package.json', 'src/a.ts'], array_keys($fingerprint));
        assertSame(['src', 'src/new'], $directories);
        self::assertGreaterThanOrEqual($before, $startedAt);
        self::assertLessThanOrEqual(time(), $startedAt);
        assertSame($fingerprint, TreeFingerprint::of($this->root, $roots));
        assertSame(['src/a.ts'], array_keys(TreeFingerprint::observe($this->root, $roots, false)[0]));
    }
}
