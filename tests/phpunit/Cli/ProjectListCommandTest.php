<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\{CliCommandContext, CliInputLoader, CliOptionParser};
use Knossos\Cli\Command\ProjectListCommand;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/** The lists the pane counts, each as a command of its own. */
#[Group('cli')]
final class ProjectListCommandTest extends KnossosTestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = sys_get_temp_dir() . '/knossos-stale-list-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->database, $this->database . '-wal', $this->database . '-shm'] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testDeadCodeListsEveryCandidatePastOnePageAndLeavesAnnotatedOnesOut(): void
    {
        [, $repository, $ids] = $this->storeFixture(null, $this->pdo());
        for ($i = 0; $i < 130; $i++) {
            $name = sprintf('App\\Orphan%03d', $i);
            $repository->saveNode(StableId::symbol($ids['project'], 'php', 'class', $name), $ids['project'], 'php', 'class', $name, 'Orphan' . $i, null, $ids['file'], 100 + $i, 100 + $i, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        (new ArchitectureQueryService($this->pdo()))->annotateComponent($ids['project'], 'App\\Orphan000', 'intentional', 'parked', execute: true);

        $out = $this->runList('dead-code', [$ids['project']], ['json' => ['1']]);
        $names = array_column(array_column($out['candidates'], 'component'), 'canonical_name');
        self::assertContains('App\\Orphan129', $names);
        self::assertNotContains('App\\Orphan000', $names);
        self::assertSame(1, $out['excluded']['annotated_intentional']);
        self::assertSame(count($names), $out['total']);
        self::assertGreaterThan(100, $out['total']);

        $text = $this->runList('dead-code', [$ids['project']], [], json: false);
        self::assertStringContainsString('App\\Orphan129', $text);
        self::assertStringContainsString('1 annotated intentional', $text);
    }

    private function pdo(): PDO
    {
        return (new RuntimeFactory(self::repositoryRoot()))->database($this->database);
    }

    /**
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     */
    private function runList(string $command, array $positionals, array $options, bool $json = true): mixed
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->database);
        ob_start();
        try {
            (new ProjectListCommand())->run($command, $positionals, $options, $context);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $json ? json_decode($output, true, 512, JSON_THROW_ON_ERROR) : $output;
    }
}
