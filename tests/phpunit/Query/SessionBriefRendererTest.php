<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\SessionBrief;
use Knossos\Query\SessionBriefRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SessionBriefRendererTest extends TestCase
{
    private function brief(string $state, ?int $age = 3600, int $changed = 0, int $tracked = 402): SessionBrief
    {
        return new SessionBrief(
            $state,
            'project_1b4f41',
            'Knossos-MCP',
            '/root/Knossos-MCP',
            $age,
            $changed,
            $tracked,
            ['core -x-> php-worker, tooling, tests'],
            ['ToolService.php: the seam is ToolCatalog, not ToolService'],
            ['StdioServer (class): src/Mcp/StdioServer.php'],
            ['ScannerClient'],
        );
    }

    #[Group('query')]
    public function testFreshBriefCarriesEveryLayerAndStaysInBudget(): void
    {
        $text = (new SessionBriefRenderer())->render($this->brief('fresh'));

        assertSame(true, str_starts_with($text, 'FRESH'));
        assertSame(true, str_contains($text, 'project_1b4f41'));
        assertSame(true, str_contains($text, 'core -x-> php-worker'));
        assertSame(true, str_contains($text, 'ToolCatalog'));
        assertSame(true, str_contains($text, 'StdioServer'));
        assertSame(true, str_contains($text, 'ScannerClient'));
        assertSame(true, str_contains($text, '`knossos` skill'));
        assertSame(true, strlen($text) <= SessionBriefRenderer::BUDGETS['fresh']);
    }

    #[Group('query')]
    public function testStaleBriefKeepsConfigAndNotesAndDropsGraphSections(): void
    {
        // The whole point of the config/graph split: a stale graph invalidates
        // hubs and entry points, but boundary rules are declarations and
        // annotations survive rescans, so both stay true and stay in.
        $text = (new SessionBriefRenderer())->render($this->brief('stale', 1_468_800, 118));

        assertSame(true, str_starts_with($text, 'STALE (118 files, 17d).'));
        assertSame(true, str_contains($text, 'scan_project path=/root/Knossos-MCP'));
        assertSame(true, str_contains($text, 'core -x-> php-worker'));
        assertSame(true, str_contains($text, 'ToolCatalog'));
        assertSame(false, str_contains($text, 'StdioServer'));
        assertSame(false, str_contains($text, 'ScannerClient'));
        assertSame(true, strlen($text) <= SessionBriefRenderer::BUDGETS['stale']);
    }

    #[Group('query')]
    public function testUnverifiedHasItsOwnConditionalWording(): void
    {
        // Not stale's wording: probing was skipped, so the graph may be fine and
        // the instruction is conditional. Reusing stale's line would make it the
        // permanent verdict on every repository over the probe limit.
        $text = (new SessionBriefRenderer())->render($this->brief('unverified', 345_600, 0, 1240));

        assertSame(true, str_starts_with($text, 'UNVERIFIED (1240 files, over probe limit; scanned 4d ago).'));
        assertSame(true, str_contains($text, 'Rescan if exactness matters.'));
        assertSame(false, str_contains($text, 'StdioServer'));
        assertSame(true, strlen($text) <= SessionBriefRenderer::BUDGETS['unverified']);
    }

    #[Group('query')]
    public function testUnscannedAndMissingAreShortAndActionable(): void
    {
        $unscanned = (new SessionBriefRenderer())->render(
            new SessionBrief('unscanned', null, null, '/root/Elsewhere', null, 0, 0, [], [], [], []),
        );
        assertSame(true, str_starts_with($unscanned, 'NOT SCANNED.'));
        assertSame(true, str_contains($unscanned, 'scan_project path=/root/Elsewhere'));
        assertSame(true, strlen($unscanned) <= SessionBriefRenderer::BUDGETS['unscanned']);

        $missing = (new SessionBriefRenderer())->render($this->brief('missing', null));
        assertSame(true, str_starts_with($missing, 'NO GRAPH.'));
        assertSame(true, strlen($missing) <= SessionBriefRenderer::BUDGETS['missing']);
    }

    #[Group('query')]
    public function testOverlongSectionsAreDroppedWholeAndTheSkillPointerSurvives(): void
    {
        $huge = array_map(static fn(int $i): string => 'Hub' . $i . str_repeat('x', 80), range(1, 40));
        $brief = new SessionBrief(
            'fresh',
            'project_1b4f41',
            'Knossos-MCP',
            '/root/Knossos-MCP',
            3600,
            0,
            402,
            ['core -x-> php-worker'],
            [],
            [],
            $huge,
        );
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(true, strlen($text) <= SessionBriefRenderer::BUDGETS['fresh']);
        assertSame(true, str_contains($text, '`knossos` skill'));  // never dropped
        assertSame(false, str_contains($text, 'Hub1xxxx'));        // dropped whole, not truncated
    }
}
