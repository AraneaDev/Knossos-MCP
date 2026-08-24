<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class DocumentationTest extends KnossosTestCase
{
    #[Group('documentation')]
    public function testGeneratedCliMcpReferencesAndDocumentationLinksStayCurrent(): void
    {
        $root = self::repositoryRoot();
        [$referenceExit, $referenceOutput, $referenceErrors] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/generate-reference.php', '--check']);
        if ($referenceExit !== 0) {
            throw new RuntimeException($referenceErrors);
        }
        assertContains('Generated reference is current.', $referenceOutput);
        assertContains('knossos architecture-summary', (string) file_get_contents($root . '/docs/reference/cli.md'));
        assertContains('## `architecture_summary`', (string) file_get_contents($root . '/docs/reference/mcp-tools.md'));

        [$linksExit, $linksOutput, $linksErrors] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/documentation-check.php']);
        if ($linksExit !== 0) {
            throw new RuntimeException($linksErrors);
        }
        assertContains('Documentation links passed:', $linksOutput);

        [$apiExit, $apiOutput, $apiErrors] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/api-documentation-check.php']);
        if ($apiExit !== 0) {
            throw new RuntimeException($apiErrors);
        }
        assertContains('API documentation passed:', $apiOutput);
    }

    /**
     * The API gate covers the interfaces under `src/`, and it selects its files
     * by looking for the word `interface`. Prose containing that word — "every
     * interface and enum", say — used to pull a plain class into the check and
     * name every contract in it after whatever word followed, so the report
     * described contracts that do not exist and the failure text pointed at
     * nothing. Every reported PHP contract must name a real interface.
     */
    #[Group('documentation')]
    public function testApiDocumentationContractsNameDeclaredInterfaces(): void
    {
        $root = self::repositoryRoot();
        [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/api-documentation-check.php']);
        if ($exit !== 0) {
            throw new RuntimeException($errors);
        }

        // Discovery must span the same files as tools/api-documentation-check.php,
        // which recurses. A one-level glob here would report every interface
        // nested deeper (Mcp/Protocol, Scanner/Worker) as an unknown contract
        // purely because this side could not see it.
        $declared = [];
        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/^\s*interface\s+(\w+)/m', (string) file_get_contents($file->getPathname()), $match) === 1) {
                $declared[$match[1]] = true;
            }
        }

        $report = json_decode(
            (string) file_get_contents($root . '/coverage/quality/api-documentation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $unknown = [];
        foreach ($report['contracts'] as $contract) {
            if (!str_starts_with($contract, 'php:')) {
                continue;
            }
            $separator = strpos($contract, '::');
            $name = $separator === false ? $contract : substr($contract, 4, $separator - 4);
            if (!isset($declared[$name])) {
                $unknown[] = $contract;
            }
        }

        assertSame([], $unknown);
    }

    /**
     * The link check has no claim on a working copy.
     *
     * `/docs/superpowers/` is git-ignored — .gitignore calls it "Local-only
     * superpowers specs and plans" — but the checker walked the filesystem and
     * validated it anyway. One stale link in a developer's private planning
     * note therefore failed this suite for everyone who happened to have that
     * file on disk, and the failure named a document no reviewer could see.
     *
     * The fixture below is deliberately BROKEN: it must be skipped because it is
     * ignored, not because its link happens to resolve.
     */
    #[Group('documentation')]
    public function testDocumentationLinkCheckSkipsGitIgnoredFiles(): void
    {
        $root = self::repositoryRoot();
        // Its OWN subdirectory: the sibling fixture in RepositoryCheckTest lives
        // under the same ignored parent, and sharing one directory means either
        // test's teardown can remove the other's ground.
        $parent = $root . '/docs/superpowers';
        $directory = $parent . '/link-check-fixture';
        $fixture = $directory . '/broken.md';
        $createdParent = !is_dir($parent);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory);
        }
        // Never write over something a developer already has there.
        self::assertFileDoesNotExist($fixture);

        try {
            file_put_contents($fixture, "[a link that does not resolve](./nowhere.md)\n");
            // Guard: without git the checker fails OPEN and validates everything,
            // which would make this assert something the fix never promised.
            [$ignoreExit] = $this->runFixtureCommandOutput(['git', '-C', $root, 'check-ignore', '-q', $fixture]);
            if ($ignoreExit !== 0) {
                self::markTestSkipped('git cannot report ignore status here.');
            }

            [$exit, $output] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/documentation-check.php']);

            assertSame(0, $exit);
            assertContains('Documentation links passed:', $output);
        } finally {
            @unlink($fixture);
            @rmdir($directory);
            if ($createdParent) {
                @rmdir($parent);
            }
        }
    }

    /**
     * A badge host must never gate the quality profile.
     *
     * mcpobservatory.com reset the connection to a GitHub runner and failed
     * `tools/quality full` on a tree whose 2,274 tests had all passed. The
     * `(?<!!)` guard in the link checker was supposed to leave images alone,
     * but the nested `[![alt](image)](href)` form badges use defeats it: the
     * outer bracket is not preceded by `!`, so every badge image was being
     * fetched. UNFETCHED_HOSTS is what actually keeps them out now.
     *
     * This asserts the list still covers every host README.md draws a badge
     * from, so adding a badge from a new service fails here instead of
     * silently making CI depend on that service being up.
     */
    #[Group('documentation')]
    public function testEveryBadgeHostIsExcludedFromExternalFetching(): void
    {
        $root = self::repositoryRoot();
        $checker = (string) file_get_contents($root . '/tools/documentation-check.php');
        self::assertSame(
            1,
            preg_match('/const UNFETCHED_HOSTS = \[([^\]]*)\];/', $checker, $listMatch),
            'tools/documentation-check.php no longer declares UNFETCHED_HOSTS.',
        );
        preg_match_all("/'([^']+)'/", $listMatch[1], $hostMatches);
        $excluded = $hostMatches[1];

        // Badge images are the `![alt](url)` inside a `[...](href)` wrapper.
        // The optional `"title"` is part of the form the checker itself
        // accepts, so a titled badge would be fetched while a narrower
        // pattern here left this guard blind to it.
        preg_match_all(
            '/\[!\[[^]]*]\((https:\/\/[^) ]+)(?:\s+"[^"]*")?\)]/',
            (string) file_get_contents($root . '/README.md'),
            $badges,
        );
        $badgeHosts = array_values(array_unique(array_map(
            static fn(string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)),
            $badges[1],
        )));

        self::assertNotEmpty($badgeHosts, 'README.md declares no badges, so this guard has nothing to protect.');
        self::assertSame([], array_values(array_diff($badgeHosts, $excluded)));
    }
}
