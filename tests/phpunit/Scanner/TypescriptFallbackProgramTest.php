<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A package's tests often sit outside its tsconfig's `include`, and its test
 * runner still resolves them through the package's paths and aliases. Read
 * with no options at all, every `@/x` import in them resolved to nothing:
 * the edge was lost and the file reported a diagnostic per import.
 */
#[Group('typescript-scanner')]
final class TypescriptFallbackProgramTest extends KnossosTestCase
{
    public function testAFileOutsideEveryIncludeIsReadUnderTheConfigBesideIt(): void
    {
        $client = $this->typescriptWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/fallback-options',
                'files' => ['shared/greet.ts', 'web/src/util.ts', 'web/tests/util.test.ts'],
                'config_files' => ['web/tsconfig.json', 'web/tsconfig.shared.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }

        $imports = [];
        $codes = [];
        foreach ($contributions as $contribution) {
            self::assertInstanceOf(ScanContribution::class, $contribution);
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'imports') {
                    $imports[] = $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
            foreach ($contribution->diagnostics as $diagnostic) {
                $codes[] = $diagnostic->code;
            }
        }

        self::assertContains('ts:module:web/tests/util.test.ts -> ts:module:web/src/util.ts', $imports);
        // `#shared/*` names a referenced project's build output, which stands
        // for its sources as it does in the config's own program.
        self::assertContains('ts:module:web/tests/util.test.ts -> ts:module:shared/greet.ts', $imports);
        // Its `rootDir` describes the package's build, not a test beside it.
        self::assertSame([], $codes);
    }
}
