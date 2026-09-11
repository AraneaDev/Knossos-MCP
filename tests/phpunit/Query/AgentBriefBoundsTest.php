<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The brief's edges: the budget it accepts, what its opening line says when the
 * graph cannot answer, and how it lists what it found.
 *
 * AgentBriefService scored 49% under mutation testing. Its tests read the brief
 * for the presence of names and respect a budget, so the advertised max_chars
 * range, the fallbacks for a project with no languages and a scan with no finish
 * time, the five-language cap, and the path suffix on an entry point could all
 * change with them green.
 */
final class AgentBriefBoundsTest extends KnossosTestCase
{
    /** The advertised budget range is accepted at both ends and refused just outside. */
    #[Group('query')]
    public function testTheAdvertisedBudgetRangeIsAcceptedAtBothEnds(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        assertSame(1_000, $queries->exportAgentBrief($ids['project'], 1_000)->data['max_chars']);
        assertSame(20_000, $queries->exportAgentBrief($ids['project'], 20_000)->data['max_chars']);

        foreach ([999, 20_001] as $outside) {
            assertThrows(fn() => $queries->exportAgentBrief($ids['project'], $outside), InvalidArgumentException::class);
        }
    }

    /** The opening line names the language mix, or says it is unknown. */
    #[Group('query')]
    public function testTheOpeningLineNamesTheLanguageMixOrSaysItIsUnknown(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        assertSame(true, str_contains($queries->exportAgentBrief($ids['project'])->data['markdown'], 'php: '));

        $pdo->exec("DELETE FROM files WHERE project_id = '" . $ids['project'] . "'");

        assertSame(
            true,
            str_contains($queries->exportAgentBrief($ids['project'])->data['markdown'], 'language mix unknown'),
            'With no files to count, the mix is unknown rather than blank.',
        );
    }

    /** Five languages are named, and the rest are counted. */
    #[Group('query')]
    public function testTheLanguageMixNamesFiveAndCountsTheRest(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $scan = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '" . $ids['project'] . "'")->fetchColumn();
        // One file each, so the tie-break is the language name and the order is
        // known: aaa through ggg, then the fixture's own php file.
        foreach (['aaa', 'bbb', 'ccc', 'ddd', 'eee', 'fff', 'ggg'] as $language) {
            self::addFile($pdo, $ids['project'], $scan, $language);
        }

        $markdown = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'])->data['markdown'];

        assertSame(true, str_contains($markdown, 'aaa: 1, bbb: 1, ccc: 1, ddd: 1, eee: 1, +3 more'), $markdown);
        assertSame(false, str_contains($markdown, 'fff: 1'), 'Only five languages are named.');
    }

    /**
     * Exactly five languages are all named, with nothing counted after them.
     *
     * Eight languages cannot tell the cap from an off-by-one, because both
     * readings truncate. Five is the only count that separates them: the rule as
     * written names all five, and one step out appends a remainder of zero.
     */
    #[Group('query')]
    public function testExactlyFiveLanguagesAreAllNamedWithNoRemainder(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $scan = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '" . $ids['project'] . "'")->fetchColumn();
        // Four more beside the fixture's own php file makes five.
        foreach (['aaa', 'bbb', 'ccc', 'ddd'] as $language) {
            self::addFile($pdo, $ids['project'], $scan, $language);
        }

        $markdown = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'])->data['markdown'];

        assertSame(true, str_contains($markdown, 'aaa: 1, bbb: 1, ccc: 1, ddd: 1, php: 1'), $markdown);
        assertSame(false, str_contains($markdown, '+0 more'), 'Five languages are five, not five and a remainder of none.');
    }

    /** A scan with no finish time is described as the active snapshot. */
    #[Group('query')]
    public function testAScanWithNoFinishTimeIsDescribedAsTheActiveSnapshot(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        assertSame(false, str_contains($queries->exportAgentBrief($ids['project'])->data['markdown'], 'the active snapshot'));

        $pdo->exec("UPDATE scans SET finished_at = NULL WHERE id = '" . $ids['scan'] . "'");

        assertSame(true, str_contains($queries->exportAgentBrief($ids['project'])->data['markdown'], 'the scan of the active snapshot'));
    }

    /**
     * An entry point names the file it lives in, and says nothing about a file
     * when there is none to name.
     */
    #[Group('query')]
    public function testAnEntryPointNamesItsFileOnlyWhenItHasOne(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $filed = StableId::symbol($ids['project'], 'php', 'class', 'App\\ShipCommand');
        $repository->saveNode($filed, $ids['project'], 'php', 'class', 'App\\ShipCommand', 'ShipCommand', null, $ids['file'], 50, 54, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $unfiled = StableId::symbol($ids['project'], 'php', 'class', 'App\\ZipCommand');
        $repository->saveNode($unfiled, $ids['project'], 'php', 'class', 'App\\ZipCommand', 'ZipCommand', null, null, null, null, 'ast', 'certain', [], 'test:unfiled', $ids['scan']);
        foreach ([$filed, $unfiled] as $node) {
            $repository->saveClassification(
                StableId::classification($ids['project'], $node, 'application.command', 'rule.command'),
                $ids['project'],
                $node,
                'application.command',
                'derived',
                'probable',
                'rule.command',
                $ids['file'],
                50,
                54,
                [],
                $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $markdown = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'])->data['markdown'];

        assertSame(true, str_contains($markdown, '- ShipCommand (class): src/Checkout.php'), $markdown);
        assertSame(true, str_contains($markdown, "- ZipCommand (class)\n"), $markdown);
    }

    /** One more file, in its own language, for the mix to count. */
    private static function addFile(PDO $pdo, string $projectId, string $scanId, string $language): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) ' .
            "VALUES (:id, :project, :path, 'h', 1, 1, :language, '1', :scan)",
        );
        $statement->execute([
            'id' => $projectId . ':' . $language,
            'project' => $projectId,
            'path' => 'src/' . $language . '.txt',
            'language' => $language,
            'scan' => $scanId,
        ]);
    }
}
