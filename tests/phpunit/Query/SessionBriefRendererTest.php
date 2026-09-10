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
    public function testStaleNamesTheMissingRootInsteadOfAScanThatWouldBeRejected(): void
    {
        // "Scanned, therefore permitted" is false: `knossos scan` self-authorises
        // whatever root it is given, so a CLI-scanned project can sit outside
        // every allowed root and still report STALE. Telling that agent to run
        // scan_project hands it the one command the server will refuse.
        $brief = new SessionBrief(
            'stale',
            'project_1b4f41',
            'Knossos-MCP',
            '/root/Knossos-MCP',
            1_468_800,
            118,
            402,
            [],
            [],
            [],
            [],
            false,
        );
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(
            true,
            str_starts_with(
                $text,
                'STALE (118 files, 17d), and /root/Knossos-MCP is not an allowed root. '
                    . 'Add it: knossos allow-root /root/Knossos-MCP --execute',
            ),
        );
        assertSame(false, str_contains($text, 'scan_project'));
        assertSame(true, strlen($text) <= SessionBriefRenderer::BUDGETS['stale']);
    }

    #[Group('query')]
    public function testStaleKeepsTodaysWordingWhenAllowed(): void
    {
        // Mirror of the test above, so an always-false flag cannot pass both.
        $text = (new SessionBriefRenderer())->render($this->brief('stale', 1_468_800, 118));

        assertSame(true, str_starts_with($text, 'STALE (118 files, 17d). Run scan_project path=/root/Knossos-MCP first.'));
        assertSame(false, str_contains($text, 'allow-root'));
    }

    #[Group('query')]
    public function testUnverifiedNamesTheMissingRootWhenNotAllowed(): void
    {
        // Same reasoning as stale: "rescan if exactness matters" is advice the
        // reader cannot act on while the root is not permitted.
        $brief = new SessionBrief(
            'unverified',
            'project_1b4f41',
            'Knossos-MCP',
            '/root/Knossos-MCP',
            345_600,
            0,
            1240,
            [],
            [],
            [],
            [],
            false,
        );
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(
            true,
            str_starts_with(
                $text,
                'UNVERIFIED (1240 files, over probe limit; scanned 4d ago), and /root/Knossos-MCP is not an '
                    . 'allowed root. Add it: knossos allow-root /root/Knossos-MCP --execute',
            ),
        );
        assertSame(false, str_contains($text, 'Rescan if exactness matters.'));
        assertSame(true, strlen($text) <= SessionBriefRenderer::BUDGETS['unverified']);
    }

    #[Group('query')]
    public function testFreshIgnoresTheFlagBecauseItAsksForNothing(): void
    {
        // The one verdict with a single form. It requests no command, so there
        // is nothing a root warning could redirect, and adding one would put
        // noise on the only verdict that needs none.
        $allowed = (new SessionBriefRenderer())->render($this->brief('fresh'));
        $notAllowed = (new SessionBriefRenderer())->render(new SessionBrief(
            'fresh',
            'project_1b4f41',
            'Knossos-MCP',
            '/root/Knossos-MCP',
            3600,
            0,
            402,
            ['core -x-> php-worker, tooling, tests'],
            ['ToolService.php: the seam is ToolCatalog, not ToolService'],
            ['StdioServer (class): src/Mcp/StdioServer.php'],
            ['ScannerClient'],
            false,
        ));

        assertSame($allowed, $notAllowed);
        assertSame(false, str_contains($notAllowed, 'allow-root'));
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
    public function testUnscannedNamesTheMissingRootWhenNotAllowed(): void
    {
        $brief = new SessionBrief('unscanned', null, null, '/root/Elsewhere', null, 0, 0, [], [], [], [], false);
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(
            true,
            str_contains(
                $text,
                'NOT SCANNED, and /root/Elsewhere is not an allowed root. Add it: knossos allow-root /root/Elsewhere --execute',
            ),
        );
    }

    #[Group('query')]
    public function testUnscannedKeepsTodaysWordingWhenAllowed(): void
    {
        // Pinned so the plumbing that computes the flag cannot silently start
        // warning on every allowed path without a test noticing.
        $brief = new SessionBrief('unscanned', null, null, '/root/Elsewhere', null, 0, 0, [], [], [], [], true);
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(true, str_contains($text, 'NOT SCANNED. Run scan_project path=/root/Elsewhere to map this repository.'));
        assertSame(false, str_contains($text, 'allow-root'));
    }

    #[Group('query')]
    public function testMissingNamesTheMissingRootWhenNotAllowed(): void
    {
        $brief = new SessionBrief('missing', 'project_1b4f41', 'Knossos-MCP', '/root/Knossos-MCP', null, 0, 402, [], [], [], [], false);
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(
            true,
            str_contains(
                $text,
                'NO GRAPH, and /root/Knossos-MCP is not an allowed root. Add it: knossos allow-root /root/Knossos-MCP --execute',
            ),
        );
    }

    #[Group('query')]
    public function testMissingKeepsTodaysWordingWhenAllowed(): void
    {
        $brief = new SessionBrief('missing', 'project_1b4f41', 'Knossos-MCP', '/root/Knossos-MCP', null, 0, 402, [], [], [], [], true);
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(true, str_contains($text, 'NO GRAPH. Run scan_project path=/root/Knossos-MCP first.'));
        assertSame(false, str_contains($text, 'allow-root'));
    }

    #[Group('query')]
    public function testTheIdentityLineDisclosesWhenTheProjectIsAnAncestorOfTheQueriedPath(): void
    {
        // A project is found by walking parents, so a repository that merely
        // lives inside a scanned one, a vendored clone or a checkout under a
        // scanned $HOME, resolves to its ancestor. The brief was then
        // confidently wrong: a FRESH verdict and the ancestor's id, with
        // nothing saying the reader was looking at the wrong project. Nothing
        // in the graph can tell that apart from an ordinary subdirectory, so
        // the ancestry is disclosed rather than guessed at.
        $brief = new SessionBrief(
            'fresh',
            'project_1b4f41',
            'Knossos-MCP',
            '/root/Knossos-MCP',
            3600,
            0,
            402,
            [],
            [],
            [],
            [],
            true,
            true,
            '/root/Knossos-MCP/vendor/nested',
        );
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(
            true,
            str_contains(
                $text,
                'Knossos project_1b4f41 (Knossos-MCP), rooted at /root/Knossos-MCP. '
                    . '/root/Knossos-MCP/vendor/nested lies inside it and is not a scanned project of its own.',
            ),
        );
    }

    #[Group('query')]
    public function testTheIdentityLineIsUnchangedWhenTheQueriedPathIsTheProjectRoot(): void
    {
        // The mirror, and the common case: a disclosure that always fired would
        // pass the test above and be noise on every brief. Both spellings of
        // "nothing to disclose" are checked, since gather() supplies the
        // resolved path and hand-built briefs supply null.
        $sameRoot = new SessionBrief(
            'fresh',
            'project_1b4f41',
            'Knossos-MCP',
            '/root/Knossos-MCP',
            3600,
            0,
            402,
            [],
            [],
            [],
            [],
            true,
            true,
            '/root/Knossos-MCP',
        );
        $unset = $this->brief('fresh');

        $text = (new SessionBriefRenderer())->render($sameRoot);
        $textUnset = (new SessionBriefRenderer())->render($unset);

        assertSame(true, str_contains($text, "\nKnossos project_1b4f41 (Knossos-MCP)\n"));
        assertSame(false, str_contains($text, 'rooted at'));
        assertSame(false, str_contains($textUnset, 'rooted at'));
        assertSame(true, str_contains($textUnset, "\nKnossos project_1b4f41 (Knossos-MCP)\n"));
    }

    #[Group('query')]
    public function testAMissingPathOutranksTheRootWarningInEveryStateThatHasOne(): void
    {
        // A path that is not on disk cannot be scanned and cannot be granted,
        // so neither of the other two continuations is worth printing. The four
        // states are checked together because the form is shared: a regression
        // that wired it into one of them would leave the other three still
        // recommending a command that cannot run.
        $expected = [
            'unscanned' => 'NOT SCANNED, and /root/Gone does not exist. '
                . 'Neither scan_project nor allow-root will accept it.',
            'missing' => 'NO GRAPH, and /root/Gone does not exist. '
                . 'Neither scan_project nor allow-root will accept it.',
            'stale' => 'STALE (118 files, 17d), and /root/Gone does not exist. '
                . 'Neither scan_project nor allow-root will accept it.',
            'unverified' => 'UNVERIFIED (1240 files, over probe limit; scanned 17d ago), and /root/Gone does not '
                . 'exist. Neither scan_project nor allow-root will accept it.',
        ];
        foreach ($expected as $state => $line) {
            // pathAllowed is left true, so the missing-path form cannot be
            // passing here merely because the root warning happened to fire.
            $brief = new SessionBrief(
                $state,
                'project_1b4f41',
                'Knossos-MCP',
                '/root/Gone',
                1_468_800,
                118,
                1240,
                [],
                [],
                [],
                [],
                true,
                false,
            );
            $text = (new SessionBriefRenderer())->render($brief);

            assertSame(true, str_starts_with($text, $line));
            // The two command names appear in the line, as the things that will
            // not work. What must be absent is either of them handed over as an
            // instruction to run.
            assertSame(false, str_contains($text, 'knossos allow-root'));
            assertSame(false, str_contains($text, 'scan_project path='));
        }
    }

    #[Group('query')]
    public function testAMissingPathIsPreferredOverTheRootWarningWhenBothApply(): void
    {
        // Both flags false. Only one line can be printed, and it has to be the
        // one that names the blocker the reader hits first: granting a root
        // that is not a directory fails for exactly the reason the other form
        // would have been hiding.
        $brief = new SessionBrief('unscanned', null, null, '/root/Gone', null, 0, 0, [], [], [], [], false, false);
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(true, str_contains($text, '/root/Gone does not exist.'));
        assertSame(false, str_contains($text, 'is not an allowed root'));
    }

    #[Group('query')]
    public function testVerdictAndPointerSurviveEvenWhenTheVerdictAloneExceedsBudget(): void
    {
        // An unbounded project path can make the verdict line alone longer than
        // the state's budget. The floor still wins: the path must come through
        // verbatim or `scan_project path=...` is not a command anyone can run,
        // and the pointer is what arms the skill.
        $path = '/root/' . str_repeat('a', 150);
        $brief = new SessionBrief('unscanned', null, null, $path, null, 0, 0);
        $text = (new SessionBriefRenderer())->render($brief);

        assertSame(true, str_contains($text, '`knossos` skill'));
        assertSame(
            true,
            str_contains($text, sprintf('NOT SCANNED. Run scan_project path=%s to map this repository.', $path)),
        );
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
