<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A component that moved to another file and changed along the way (its
 * lines, at least) was listed under `changed` and again under `moved`, and
 * counted twice in `total_changes`. A move is now one change, and its record
 * still shows every field that changed.
 */
final class SnapshotDiffTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAComponentMovedAndEditedIsCountedOnce(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $repository->completeScan($project, $ids['scan']);
        $repository->archiveActiveSnapshot($project, hash('sha256', '{}'), 5);
        $next = StableId::scan($project, 'scan-2');
        $repository->createScan($next, $project, 'incremental', hash('sha256', 'scanner-set'));
        $file = StableId::file($project, 'src/InvoiceService.php');
        $repository->saveFile($file, $project, 'src/InvoiceService.php', hash('sha256', 'invoice'), 40, 1, 'php', '0.1.0', $next);
        $before = $pdo->query("SELECT start_line FROM nodes WHERE id = '" . $ids['invoice'] . "'")->fetchColumn();
        $repository->saveNode($ids['invoice'], $project, 'php', 'class', 'App\\InvoiceService', 'InvoiceService', null, $file, 3, 17, 'ast', 'certain', [], 'php:file:src/InvoiceService.php', $next);
        $repository->completeScan($project, $next);

        $diff = ArchitectureQueryService::forDatabase($pdo)->snapshotDiff($project, $ids['scan']);

        $counts = $diff->data['changes']['components']['counts'];
        self::assertSame(0, $counts['changed']);
        self::assertSame(1, $counts['moved']);
        $other = 0;
        foreach ($diff->data['changes'] as $name => $section) {
            $other += $name === 'components' ? 0 : $section['counts']['added'] + $section['counts']['removed'] + $section['counts']['changed'];
        }
        $components = $counts['added'] + $counts['removed'] + $counts['changed'] + $counts['moved'];
        self::assertSame($other + $components, $diff->data['bounds']['total_changes'], 'The moved component adds 1 to the total, not 2.');
        $moved = $diff->data['changes']['components']['moved'][0];
        self::assertSame((int) $before, (int) $moved['before']['start_line']);
        self::assertSame(3, $moved['after']['start_line'], 'The move record still shows the edit.');
        self::assertSame('src/InvoiceService.php', $moved['after']['path']);
        self::assertContains('start_line', $moved['changed_fields']);
    }
}
