<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\StatGate;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/** An idle tree costs stat calls, not hashes: the gate opens only when something the fingerprint saw moved. */
final class StatGateTest extends KnossosTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/deep', 0o700, true);
        file_put_contents($this->root . '/src/deep/A.php', 'a');
        file_put_contents($this->root . '/B.php', 'b');
        // Out of the racy second: the gate trusts these stats.
        $this->backdate(10);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    private function backdate(int $seconds): void
    {
        foreach (['', '/src', '/src/deep', '/src/deep/A.php', '/B.php'] as $path) {
            touch($this->root . $path, time() - $seconds);
        }
    }

    private function gate(int $fullEveryMs = StatGate::FULL_EVERY_MS): StatGate
    {
        $gate = new StatGate($this->root, $fullEveryMs);
        $gate->remember(['src/deep/A.php' => 'h', 'B.php' => 'h']);
        return $gate;
    }

    #[Group('watch')]
    public function testAnUntouchedTreeKeepsTheGateShutUntilTheFullCheckIsDue(): void
    {
        $gate = $this->gate(300);
        for ($i = 0; $i < 20; ++$i) {
            assertSame(false, $gate->mayHaveChanged());
        }
        usleep(350_000);
        assertSame(true, $gate->mayHaveChanged());
    }

    #[Group('watch')]
    public function testAnEditAFileAddedBesideAnotherAndADeletionEachOpenTheGate(): void
    {
        $gate = $this->gate();
        file_put_contents($this->root . '/src/deep/A.php', 'aa');
        assertSame(true, $gate->mayHaveChanged());

        $this->backdate(10);
        $gate = $this->gate();
        file_put_contents($this->root . '/src/deep/New.php', 'n');
        assertSame(true, $gate->mayHaveChanged());

        unlink($this->root . '/src/deep/New.php');
        $this->backdate(10);
        $gate = $this->gate();
        unlink($this->root . '/B.php');
        assertSame(true, $gate->mayHaveChanged());
    }

    #[Group('watch')]
    public function testAFileWrittenInTheSecondItWasSeenKeepsTheGateOpen(): void
    {
        touch($this->root . '/B.php');
        $gate = $this->gate();
        assertSame(true, $gate->mayHaveChanged());
        assertSame(true, $gate->mayHaveChanged());
    }
}
