<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use InvalidArgumentException;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class GitTest extends KnossosTestCase
{
    #[Group('git')]
    public function testGitHistoryAndChangeAwareImpactAreBoundedDeterministicAndReadOnly(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $invoiceFile = StableId::file($ids['project'], 'src/InvoiceService.php');
        $repository->saveFile(
            $invoiceFile,
            $ids['project'],
            'src/InvoiceService.php',
            hash('sha256', 'invoice'),
            50,
            1,
            'php',
            '0.2.0',
            $ids['scan'],
        );
        $repository->saveNode(
            $ids['invoice'],
            $ids['project'],
            'php',
            'class',
            'App\\InvoiceService',
            'InvoiceService',
            null,
            $invoiceFile,
            21,
            35,
            'ast',
            'certain',
            [],
            'php:file:src/InvoiceService.php',
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'change-impact'),
            $ids['project'],
            'calls',
            $ids['invoice'],
            $ids['checkout'],
            $invoiceFile,
            30,
            30,
            'ast',
            'certain',
            [],
            'change:file:src/InvoiceService.php',
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);
        $historyProvider = new class implements GitHistoryProvider {
            public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array
            {
                assertSame('/workspace/fixture-shop', $projectRoot);
                return ['files' => [
                    'src/Checkout.php' => ['commit_count' => 5, 'authors' => ['a@example.test', 'b@example.test'], 'last_changed_at' => '2026-07-17T10:00:00+00:00'],
                    'src/InvoiceService.php' => ['commit_count' => 1, 'authors' => ['a@example.test'], 'last_changed_at' => '2026-07-16T10:00:00+00:00'],
                ], 'commits_examined' => 6, 'truncated' => false];
            }
        };
        $query = new ArchitectureQueryService($pdo, gitHistory: $historyProvider);
        $result = $query->changeImpact($ids['project'], $ids['invoice']);
        assertSame(true, $result->data['git']['available']);
        assertSame(6, $result->data['git']['commits_examined']);
        assertSame('App\\Checkout', $result->data['risk_ranking'][0]['component']['canonical_name']);
        assertSame(21, $result->data['risk_ranking'][0]['score']);
        assertSame(5, $result->data['risk_ranking'][0]['change_signals']['commit_count']);
        assertContains('not proof of risk', $result->warnings[array_key_last($result->warnings)]);

        $fallback = (new ArchitectureQueryService($pdo))->changeImpact($ids['project'], $ids['invoice']);
        assertSame(false, $fallback->data['git']['available']);
        assertSame(0, $fallback->data['risk_ranking'][0]['change_signals']['commit_count']);
        assertContains('provider_unavailable', implode(' ', $fallback->warnings));
        assertThrows(fn() => $query->changeImpact($ids['project'], $ids['invoice'], sinceDays: 0), InvalidArgumentException::class);
        assertThrows(fn() => $query->changeImpact($ids['project'], $ids['invoice'], maxCommits: 0), InvalidArgumentException::class);
        assertThrows(fn() => $query->changeImpact($ids['project'], $ids['invoice'], maxCommits: 5001), InvalidArgumentException::class);

        $root = sys_get_temp_dir() . '/knossos-git-' . bin2hex(random_bytes(6));
        $plain = sys_get_temp_dir() . '/knossos-git-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0700, true) || !mkdir($plain, 0700, true)) {
            throw new RuntimeException('Unable to create Git fixtures.');
        }
        try {
            $this->runFixtureCommand(['git', 'init', '--quiet', $root]);
            $this->runFixtureCommand(['git', '-C', $root, 'config', 'user.name', 'Knossos Test']);
            $this->runFixtureCommand(['git', '-C', $root, 'config', 'user.email', 'test@example.test']);
            file_put_contents($root . '/src/example.php', "<?php\n");
            $this->runFixtureCommand(['git', '-C', $root, 'add', 'src/example.php']);
            $this->runFixtureCommand(['git', '-C', $root, 'commit', '--quiet', '-m', 'first']);
            file_put_contents($root . '/src/example.php', "<?php\n// second\n");
            $this->runFixtureCommand(['git', '-C', $root, 'add', 'src/example.php']);
            $this->runFixtureCommand(['git', '-C', $root, 'commit', '--quiet', '-m', 'second']);
            $history = (new ProcessGitHistoryProvider())->history($root, 30, 10, 2000);
            assertSame(2, $history['commits_examined']);
            assertSame(2, $history['files']['src/example.php']['commit_count']);
            assertSame(['test@example.test'], $history['files']['src/example.php']['authors']);
            file_put_contents($root . '/src/example.php', "<?php\n// working tree\n");
            file_put_contents($root . '/src/untracked.php', "<?php\n");
            $changes = (new ProcessGitWorkingTreeProvider())->changes($root, null, 10, 2000);
            assertSame(['src/example.php', 'src/untracked.php'], $changes['paths']);
            assertSame(['src/example.php'], (new ProcessGitWorkingTreeProvider())->changes($root, 'HEAD~1', 10, 2000)['paths']);
            assertThrows(fn() => (new ProcessGitHistoryProvider())->history($plain, 30, 10, 2000), RuntimeException::class);
            assertThrows(fn() => (new ProcessGitHistoryProvider(maxOutputBytes: 10))->history($root, 30, 10, 2000), RuntimeException::class);
            assertThrows(fn() => (new ProcessGitWorkingTreeProvider())->changes($root, '--bad', 10, 2000), RuntimeException::class);
        } finally {
            $this->removeGitFixture($root);
            $this->removeGitFixture($plain);
        }
    }

    #[Group('git')]
    public function testChangedFileImpactMapsExplicitAndWorkingTreePathsWithoutExecution(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $workingTree = new class implements GitWorkingTreeProvider {
            public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
            {
                assertSame('/workspace/fixture-shop', $projectRoot);
                assertSame('main', $baseRef);
                return ['paths' => ['src/Checkout.php', 'src/missing.php'], 'renames' => [
                    ['from' => 'src/Old.php', 'to' => 'src/Checkout.php'],
                ], 'truncated' => false];
            }
        };
        $query = new ArchitectureQueryService($pdo, gitWorkingTree: $workingTree);
        assertThrows(fn() => $query->changedFilesImpact($ids['project'], ['some.php'], baseRef: 'main'), InvalidArgumentException::class);
        $explicit = $query->changedFilesImpact($ids['project'], ['src/Checkout.php', 'src/missing.php']);
        assertSame(2, count($explicit->data['direct_components']));
        assertSame(['src/missing.php'], $explicit->data['unresolved_files']);
        assertSame(false, $explicit->data['git']['used']);
        assertSame('src/Checkout.php', $explicit->evidence[0]['path']);

        $discovered = $query->changedFilesImpact($ids['project'], workingTree: true, baseRef: 'main');
        assertSame(true, $discovered->data['git']['used']);
        assertSame('src/Old.php', $discovered->data['git']['renames'][0]['from']);
        assertThrows(fn() => $query->changedFilesImpact($ids['project']), InvalidArgumentException::class);
        assertThrows(fn() => $query->changedFilesImpact($ids['project'], ['../escape.php']), InvalidArgumentException::class);
        assertThrows(fn() => $query->changedFilesImpact($ids['project'], ['src/Checkout.php'], workingTree: true), InvalidArgumentException::class);
        assertThrows(fn() => $query->changedFilesImpact($ids['project'], array_fill(0, 51, 'x.php')), InvalidArgumentException::class);
        assertThrows(fn() => $query->changedFilesImpact($ids['project'], [1, 2, 3]), InvalidArgumentException::class);
        assertThrows(fn() => (new ArchitectureQueryService($pdo))->changedFilesImpact($ids['project'], [], true), InvalidArgumentException::class);
    }
}
