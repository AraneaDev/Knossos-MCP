<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SessionBriefService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class SessionBriefServiceTest extends KnossosTestCase
{
    #[Group('query')]
    public function testUnscannedPathYieldsTheShortestBrief(): void
    {
        [$pdo] = $this->storeFixture();

        $text = (new SessionBriefService($pdo))->brief(sys_get_temp_dir());

        assertSame(true, str_starts_with($text, 'NOT SCANNED.'));
        assertSame(true, str_contains($text, 'scan_project path='));
    }

    #[Group('query')]
    public function testScannedProjectCarriesItsIdAndAnyNotes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, created_at, updated_at) ' .
            "VALUES(:p, 'App\\\\Checkout', 'note', 'the seam is the repository, not the controller', :t, :t)",
        );
        $insert->execute(['p' => $ids['project'], 't' => '2026-09-09T12:00:00+00:00']);

        $text = (new SessionBriefService($pdo))->brief($root);

        assertSame(true, str_contains($text, $ids['project']));
        assertSame(true, str_contains($text, 'the seam is the repository'));
        assertSame(true, str_contains($text, '`knossos` skill'));
    }

    #[Group('query')]
    public function testNotesAreCappedNewestFirstAndOnlyOfKindNote(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $root = (string) $pdo->query('SELECT root_realpath FROM projects')->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO annotations(project_id, canonical_name, kind, value, created_at, updated_at) ' .
            'VALUES(:p, :n, :k, :v, :t, :t)',
        );
        // Seven notes, oldest first, so the cap has something to cut and the
        // ordering has a wrong answer available to it.
        for ($i = 1; $i <= 7; $i++) {
            $insert->execute([
                'p' => $ids['project'],
                'n' => 'App\\Note' . $i,
                'k' => 'note',
                'v' => 'note body ' . $i,
                't' => sprintf('2026-09-%02dT12:00:00+00:00', $i),
            ]);
        }
        // The other three kinds change how other read surfaces behave. They are
        // not orientation material and must not consume the notes budget.
        $insert->execute([
            'p' => $ids['project'],
            'n' => 'App\\Suppressed',
            'k' => 'false_positive',
            'v' => 'not dead, reached through the container',
            't' => '2026-09-30T12:00:00+00:00',
        ]);

        $text = (new SessionBriefService($pdo))->brief($root);

        assertSame(true, str_contains($text, 'note body 7'));   // newest kept
        assertSame(true, str_contains($text, 'note body 3'));   // fifth-newest kept
        assertSame(false, str_contains($text, 'note body 2'));  // beyond the cap of 5
        assertSame(false, str_contains($text, 'note body 1'));
        assertSame(false, str_contains($text, 'reached through the container')); // wrong kind
    }
}
