<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\Group;

/**
 * Guards `failOnWarning="true"` in phpunit.xml, without which every mutation
 * score this repository produces is silently wrong.
 *
 * WHY, precisely — Infection writes its own PHPUnit config for each mutant and
 * unconditionally sets `stopOnDefect="true"` (MutationConfigBuilder), so the
 * suite halts the moment a mutant proves itself killed. A PHP warning is a
 * defect for that setting, but with `failOnWarning="false"` it is not a
 * failure, so:
 *
 *  1. a mutant makes some early test emit a warning — `foreach (null)` is the
 *     common one, from a negated `is_array()` guard;
 *  2. PHPUnit stops there and exits 0, having run a fraction of the suite;
 *  3. Infection reads exit 0 as "tests passed" and reports the mutant as
 *     ESCAPED — never reaching the test that asserts the mutated behaviour.
 *
 * Measured on this repository: mutating the `is_array($manifest['scripts'])`
 * guard in ProjectDiscoverer stopped the run after 86 of 1858 tests with exit
 * 0, and Infection scored it escaped. Run to completion, that same mutant
 * fails ProjectDiscovererTest five times over.
 *
 * The blast radius is every consumer of those scores: Chaos-MCP's
 * `audit_code_resilience`, the weekly `.github/workflows/mutation.yml` gate,
 * and the `minMsi` floor in infection.json5 — all of which would read a
 * survivable-looking codebase as one with genuine coverage gaps, and send a
 * reader off writing tests for mutants their suite already kills.
 *
 * A warning in a test is a defect worth failing on in its own right; this test
 * exists because the mutation-testing consequence is invisible without it.
 */
#[Group('quality')]
final class FailOnWarningTest extends KnossosTestCase
{
    public function testPhpunitConfigurationFailsOnWarning(): void
    {
        $config = self::repositoryRoot() . '/phpunit.xml';
        $document = new \DOMDocument();
        $document->load($config);
        $root = $document->documentElement;
        self::assertNotNull($root);

        assertSame(
            'true',
            $root->getAttribute('failOnWarning'),
            'phpunit.xml must set failOnWarning="true"; see this class docblock for what breaks otherwise.',
        );
    }

    /**
     * `failOnRisky` has the same shape of consequence and is already enabled;
     * pinning it here keeps the pair from drifting apart.
     */
    public function testPhpunitConfigurationFailsOnRisky(): void
    {
        $document = new \DOMDocument();
        $document->load(self::repositoryRoot() . '/phpunit.xml');
        $root = $document->documentElement;
        self::assertNotNull($root);

        assertSame('true', $root->getAttribute('failOnRisky'));
    }
}
