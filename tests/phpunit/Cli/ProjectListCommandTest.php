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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
        ArchitectureQueryService::forDatabase($this->pdo())->upsertAnnotation($ids['project'], 'App\\Orphan000', 'intentional', 'parked', execute: true);

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

    /**
     * A search cut short by its time budget says so and exits 3: a partial
     * list printed as the whole one would read as "nothing else is dead".
     * (It exited 2, which is reserved for errors.)
     */
    public function testDeadCodeSaysWhenItsTimeBudgetCutTheListShort(): void
    {
        [, $repository, $ids] = $this->storeFixture(null, $this->pdo());
        $repository->completeScan($ids['project'], $ids['scan']);
        $time = 0;
        $clock = static function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        };
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->database);
        ob_start();
        try {
            $exit = (new ProjectListCommand($clock))->run('dead-code', [$ids['project']], ['candidate-timeout' => ['1']], $context);
        } finally {
            $text = (string) ob_get_clean();
        }
        self::assertSame(3, $exit);
        self::assertStringContainsString('Incomplete: the search ran out of its 1 ms', $text);
    }

    /** Exit 2 meant both "the list was cut" and "the command failed", so a script could not tell them apart. */
    #[Group('cli')]
    public function testAnIncompleteListExitsThreeAndAnErrorExitsTwo(): void
    {
        [, $repository, $ids] = $this->storeFixture(null, $this->pdo());
        $repository->completeScan($ids['project'], $ids['scan']);
        $time = 0;
        $clock = static function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        };
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->database);
        ob_start();
        try {
            $status = (new ProjectListCommand($clock))->run('dead-code', [$ids['project']], ['candidate-timeout' => ['1'], 'json' => ['']], $context);
        } finally {
            $json = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        }
        self::assertSame(3, $status);
        self::assertTrue($json['truncated']);

        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($errors);
        $failed = (new \Knossos\Application($errors))->run(['diagnostics', 'project_missing', '--db=' . $this->database]);
        rewind($errors);
        self::assertSame(2, $failed);
        self::assertStringStartsWith('KNOSSOS_INVALID_ARGUMENT:', (string) stream_get_contents($errors));
    }

    public function testDiagnosticsAreListedWholeAndFilteredBySeverity(): void
    {
        $pdo = $this->pdo();
        [, $repository, $ids] = $this->storeFixture(null, $pdo);
        $insert = $pdo->prepare("INSERT INTO diagnostics (id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'test')");
        $insert->execute(['d1', $ids['project'], $ids['scan'], $ids['file'], 'warning', 'TS6133', 'unused', 9, 9]);
        $insert->execute(['d2', $ids['project'], $ids['scan'], $ids['file'], 'error', 'TS2322', 'wrong type', 3, 3]);
        $repository->completeScan($ids['project'], $ids['scan']);

        $text = $this->runList('diagnostics', [$ids['project']], [], json: false);
        self::assertStringContainsString('src/Checkout.php:3 TS2322 error wrong type', $text);
        self::assertStringContainsString('2 diagnostics.', $text);
        $warnings = $this->runList('diagnostics', [$ids['project']], ['json' => ['1'], 'severity' => ['warning']]);
        self::assertSame(['TS6133'], array_column($warnings['diagnostics'], 'code'));
    }

    public function testPoliciesShowsWhatTheProjectDeclaresOrThatItDeclaresNone(): void
    {
        [, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $this->scanInto($root);
            $none = $this->runList('policies', [$root], [], json: false);
            self::assertStringContainsString('No policies declared in ', $none);
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1, 'boundaries' => [
                ['name' => 'Core', 'path_prefix' => 'src/Core/'],
                ['name' => 'Edge', 'path_prefix' => 'src/Edge/'],
            ], 'policies' => [['id' => 'core-alone', 'from_boundary' => 'Core', 'deny_targets' => ['Edge']]]]));
            self::assertStringContainsString('core-alone: Core may not depend on Edge', $this->runList('policies', [$root], [], json: false));
            $json = $this->runList('policies', [$root], ['json' => ['1']]);
            self::assertSame([2, 1], [count($json['boundaries']), count($json['policies'])]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Scans `$root` into this test's own database file, so the command reads it there. */
    private function scanInto(string $root): void
    {
        (new \Knossos\Scan\ProjectScanService($this->pdo(), self::repositoryRoot(), [$root]))->scan($root);
    }

    public function testPoliciesNamesAConfigurationItCannotRead(): void
    {
        [, , $root] = $this->scanTempFixture('turn-brief');
        try {
            $this->scanInto($root);
            file_put_contents($root . '/knossos.json', '{ not json');
            try {
                $this->runList('policies', [$root], [], json: false);
                self::fail('A broken knossos.json was read.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($root, $error->getMessage());
                self::assertStringContainsString('knossos.json', $error->getMessage());
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A listing stops at 10,000 diagnostics and says how many there are: a
     * project with more is better narrowed by severity or path than printed,
     * and holding every row at once ran a project with 100,000 of them out
     * of PHP's default 128 MB.
     *
     * In a process of its own: the peak it measures must not depend on what
     * the tests before it left allocated, and the ten thousand rows it lists
     * left allocator pages held in the shared process for every test after it.
     */
    #[RunInSeparateProcess]
    public function testDiagnosticsStopAtTenThousandAndSaySo(): void
    {
        $pdo = $this->pdo();
        [, $repository, $ids] = $this->storeFixture(null, $pdo);
        $pdo->prepare(
            "WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 10050) "
            . "INSERT INTO diagnostics (id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) "
            . "SELECT 'd' || i, ?, ?, ?, 'warning', 'W', 'w', i, i, 'test' FROM n",
        )->execute([$ids['project'], $ids['scan'], $ids['file']]);
        $repository->completeScan($ids['project'], $ids['scan']);
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->database);
        $before = memory_get_usage();
        ob_start();
        try {
            $exit = (new ProjectListCommand())->run('diagnostics', [$ids['project']], [], $context);
        } finally {
            $text = (string) ob_get_clean();
        }
        // 3, not 2: exit 2 is reserved for errors.
        self::assertSame(3, $exit);
        self::assertStringContainsString('Incomplete: listed the first 10000 of 10050', $text);
        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);
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
