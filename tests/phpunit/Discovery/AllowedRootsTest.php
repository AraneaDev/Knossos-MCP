<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootGuard;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The allow-list resolves on use rather than at construction. That is the whole
 * mechanism behind one installation serving every project, so these tests are
 * about *when* roots are read at least as much as which ones.
 */
final class AllowedRootsTest extends KnossosTestCase
{
    #[Group('discovery')]
    public function testAGrantAddedAfterConstructionTakesEffectWithoutRebuilding(): void
    {
        $granted = $this->makeTempDir();
        $file = $this->makeTempDir() . '/roots.json';
        $roots = new AllowedRoots([], $file);

        assertSame([], $roots->current());

        file_put_contents($file, json_encode(['roots' => [$granted]], JSON_THROW_ON_ERROR));
        // No rebuild, no restart: the same instance must see the new grant, or a
        // long-lived MCP server could never pick up a newly added project.
        assertSame([$granted], $roots->current());
    }

    #[Group('discovery')]
    public function testStaticAndFileRootsAreUnionedWithoutDuplicates(): void
    {
        $shared = $this->makeTempDir();
        $fileOnly = $this->makeTempDir();
        $file = $this->makeTempDir() . '/roots.json';
        file_put_contents($file, json_encode(['roots' => [$shared, $fileOnly]], JSON_THROW_ON_ERROR));

        $roots = new AllowedRoots([$shared], $file);

        assertSame([$shared, $fileOnly], $roots->current());
        assertSame(
            [AllowedRoots::SOURCE_STATIC, AllowedRoots::SOURCE_FILE],
            array_column($roots->describe(), 'source'),
        );
    }

    #[Group('discovery')]
    public function testABareArrayFileIsAcceptedAndTrailingSlashesNormalised(): void
    {
        $granted = $this->makeTempDir();
        $file = $this->makeTempDir() . '/roots.json';
        // A hand-edited file in the obvious shape must work; silently granting
        // nothing is the worst outcome for something an operator types by hand.
        file_put_contents($file, json_encode([$granted . '/'], JSON_THROW_ON_ERROR));

        assertSame([$granted], (new AllowedRoots([], $file))->current());
    }

    #[Group('discovery')]
    public function testAMalformedFileGrantsNothingRatherThanBreakingTheServer(): void
    {
        $configured = $this->makeTempDir();
        $file = $this->makeTempDir() . '/roots.json';
        file_put_contents($file, '{ this is not json');

        // The flag-supplied root still works: a typo in an optional file must not
        // take down a server that was configured perfectly well without it.
        assertSame([$configured], (new AllowedRoots([$configured], $file))->current());
    }

    #[Group('discovery')]
    public function testAMissingFileIsSimplyNoGrants(): void
    {
        $roots = new AllowedRoots([], $this->makeTempDir() . '/absent.json');

        assertSame([], $roots->current());
        assertSame([], $roots->describe());
    }

    #[Group('discovery')]
    public function testDescribeFlagsRootsThatDoNotExistOnThisServer(): void
    {
        $real = $this->makeTempDir();
        $ghost = sys_get_temp_dir() . '/knossos-absent-' . uniqid('', true);
        $file = $this->makeTempDir() . '/roots.json';
        file_put_contents($file, json_encode(['roots' => [$real, $ghost]], JSON_THROW_ON_ERROR));

        $described = (new AllowedRoots([], $file))->describe();

        // A host path handed to a containerised server looks exactly like a
        // working one until a scan fails; existence is what distinguishes them.
        assertSame([true, false], array_column($described, 'exists'));
    }

    #[Group('discovery')]
    public function testOfAcceptsBothAListAndAnInstance(): void
    {
        $root = $this->makeTempDir();
        $instance = new AllowedRoots([$root]);

        assertSame([$root], AllowedRoots::of([$root])->current());
        // Identity, not a copy: passing an instance through must not sever it
        // from the file it re-reads.
        assertSame($instance, AllowedRoots::of($instance));
    }

    #[Group('discovery')]
    public function testGuardRejectionNamesTheRootsFileAndTheConfiguredRoots(): void
    {
        $granted = $this->makeTempDir();
        $outside = $this->makeTempDir();
        $file = $this->makeTempDir() . '/roots.json';
        file_put_contents($file, json_encode(['roots' => [$granted]], JSON_THROW_ON_ERROR));

        $guard = new RootGuard(new AllowedRoots([], $file));
        $error = captureThrows(static fn() => $guard->resolve($outside), DiscoveryException::class);

        // Everything a caller needs to fix it themselves: what was refused, what
        // is allowed, and where to record the new grant.
        $this->assertStringContainsString($outside, $error->getMessage());
        $this->assertStringContainsString('Configured roots: ' . $granted, $error->getMessage());
        $this->assertStringContainsString($file, $error->getMessage());
        $this->assertStringContainsString('no restart is needed', $error->getMessage());
    }

    #[Group('discovery')]
    public function testGuardHonoursAGrantAddedAfterItWasConstructed(): void
    {
        $target = $this->makeTempDir();
        $file = $this->makeTempDir() . '/roots.json';
        file_put_contents($file, json_encode(['roots' => []], JSON_THROW_ON_ERROR));
        $guard = new RootGuard(new AllowedRoots([], $file));

        captureThrows(static fn() => $guard->resolve($target), DiscoveryException::class);

        file_put_contents($file, json_encode(['roots' => [$target]], JSON_THROW_ON_ERROR));
        assertSame(realpath($target), $guard->resolve($target));
    }

    #[Group('discovery')]
    public function testDefaultConfigPathSitsBesideTheDatabaseUnlessOverridden(): void
    {
        assertSame('/var/data/roots.json', AllowedRoots::defaultConfigPath('/var/data/knossos.sqlite'));

        putenv('KNOSSOS_ROOTS_FILE=/elsewhere/custom.json');
        try {
            assertSame('/elsewhere/custom.json', AllowedRoots::defaultConfigPath('/var/data/knossos.sqlite'));
        } finally {
            putenv('KNOSSOS_ROOTS_FILE');
        }
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/knossos-allowedroots-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
