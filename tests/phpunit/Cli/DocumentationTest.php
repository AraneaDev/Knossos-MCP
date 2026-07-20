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
}
