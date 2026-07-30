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
}
