<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use Knossos\Classification\ClassificationRule;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Query\SemanticRanker;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for the five nano-interface declarations in src/ covered by
 * this batch:
 *
 *   src/Git/GitHistoryProvider.php              (15 LoC, interface, 1 method)
 *   src/Git/GitWorkingTreeProvider.php          (15 LoC, interface, 1 method)
 *   src/Cli/CliCommand.php                      (19 LoC, interface, 2 methods)
 *   src/Query/SemanticRanker.php                (19 LoC, interface, 2 methods)
 *   src/Classification/ClassificationRule.php   (20 LoC, interface, 2 methods)
 *
 * Each interface has no body code; methods are pure signatures. Infection
 * 0.31.9 cannot produce mutations against these bodies because the body is
 * empty — same structural-infimum pattern as batches 5 (markers) and 6
 * (DTOs). PHPUnit ground truth at `instanceof` + shape coverage is the
 * binding surface for these files; engine MSI is bound at 0 % for each.
 *
 * Conventions match tests/phpunit/ExceptionsTest.php (batch 5) and
 * tests/phpunit/NanoDtosTest.php (batch 6): bare global assertSame from
 * tests/phpunit/Support/Assertions.php, `#[Group('interfaces')]` at
 * class level.
 *
 * The five concrete stub classes are declared inline at the top of this
 * file. PHP allows multiple top-level class declarations per file as long
 * as only one carries the namespaced test class.
 */

/**
 * Stub: implements GitHistoryProvider with the empty shape from PHPDoc.
 * `final class` mirrors how production code (ProcessGitHistoryProvider)
 * realises the interface.
 */
final class FakeGitHistoryProvider implements GitHistoryProvider
{
    public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array
    {
        return ['files' => [], 'commits_examined' => 0, 'truncated' => false];
    }
}

/**
 * Stub: implements GitWorkingTreeProvider with the empty shape from PHPDoc.
 */
final class FakeGitWorkingTreeProvider implements GitWorkingTreeProvider
{
    public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
    {
        return ['paths' => [], 'renames' => [], 'truncated' => false];
    }
}

/**
 * Stub: implements CliCommand with supports() = ($command === 'fake'), and
 * run() returns 0 unconditionally. The real production CliCommand set
 * (e.g. ScanCommand in src/Cli/) carries actual body code; this stub is
 * for the interface contract only.
 */
final class FakeCliCommand implements CliCommand
{
    public function supports(string $command): bool
    {
        return $command === 'fake';
    }

    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        return 0;
    }
}

/**
 * Stub: implements SemanticRanker with id() = 'fake' and rank() = [].
 * Production rankers (LexicalRanker, EmbeddingRanker, etc.) carry actual
 * scoring logic; this stub is for the interface contract only.
 */
final class FakeSemanticRanker implements SemanticRanker
{
    public function id(): string
    {
        return 'fake';
    }

    public function rank(string $featureDescription, array $candidates, int $timeoutMs): array
    {
        return [];
    }
}

/**
 * Stub: implements ClassificationRule with id() = 'fake-classifier' and
 * classify() returning an empty list of facts. Production rules
 * (LaravelRoleRule, NestJsRoleRule, etc.) carry actual classification
 * logic; this stub is for the interface contract only.
 */
final class FakeClassificationRule implements ClassificationRule
{
    public function id(): string
    {
        return 'fake-classifier';
    }

    public function classify(NodeFact $node): array
    {
        return [];
    }
}

#[Group('interfaces')]
final class InterfacesStubsTest extends KnossosTestCase
{
    public function testGitHistoryProviderStubSatisfiesInterface(): void
    {
        $stub = new FakeGitHistoryProvider();
        self::assertInstanceOf(GitHistoryProvider::class, $stub);

        $result = $stub->history('/repo', 7, 100, 5_000);
        assertSame(['files' => [], 'commits_examined' => 0, 'truncated' => false], $result);

        // Boundary: arg types are passed verbatim — pin the int promotion
        // for $sinceDays / $maxCommits / $timeoutMs (PHP's int type-hint
        // accepts negative and zero for `$sinceDays`).
        $result2 = $stub->history('/repo', -1, 0, 0);
        assertSame(['files' => [], 'commits_examined' => 0, 'truncated' => false], $result2);
    }

    public function testGitWorkingTreeProviderStubSatisfiesInterface(): void
    {
        $stub = new FakeGitWorkingTreeProvider();
        self::assertInstanceOf(GitWorkingTreeProvider::class, $stub);

        $result = $stub->changes('/repo', null, 50, 5_000);
        assertSame(['paths' => [], 'renames' => [], 'truncated' => false], $result);

        // With a baseRef provided (the only optional arg in the signature).
        $result2 = $stub->changes('/repo', 'origin/main', 50, 5_000);
        assertSame(['paths' => [], 'renames' => [], 'truncated' => false], $result2);
    }

    public function testCliCommandStubSatisfiesInterface(): void
    {
        $stub = new FakeCliCommand();
        self::assertInstanceOf(CliCommand::class, $stub);

        assertSame(true, $stub->supports('fake'));
        assertSame(false, $stub->supports('other'));
        assertSame(false, $stub->supports(''));

        // Inline CliCommandContext fixture: CliOptionParser + CliInputLoader
        // are no-arg constructors, RuntimeFactory takes an installation
        // root string, databasePath is optional null. Calling run()
        // resolves the FakeCliCommand::run body which is short-circuited
        // (returns 0 unconditionally on the stub).
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            null,
        );

        assertSame(0, $stub->run('fake', [], [], $context));
    }

    public function testSemanticRankerStubSatisfiesInterface(): void
    {
        $stub = new FakeSemanticRanker();
        self::assertInstanceOf(SemanticRanker::class, $stub);

        assertSame('fake', $stub->id());

        // Empty candidate list — patches the documented `list<...>` shape.
        $result = $stub->rank('Checkouts handle payments', [], 1_000);
        assertSame([], $result);

        // Non-empty candidate list with whatever shape — the stub still
        // returns []. The shape of the float|int values is enforced by the
        // real SemanticRanker implementations, not by the interface sig.
        $result2 = $stub->rank('audit features', [['id' => 'a', 'text' => 't']], 5_000);
        assertSame([], $result2);
    }

    public function testClassificationRuleStubSatisfiesInterface(): void
    {
        $stub = new FakeClassificationRule();
        self::assertInstanceOf(ClassificationRule::class, $stub);

        assertSame('fake-classifier', $stub->id());

        // Inline NodeFact fixture: requires non-empty localId / kind /
        // canonicalName / displayName; Origin::Ast + Confidence::Certain
        // are valid enum cases; Evidence('src/x.php', 1, 5) passes the
        // relative-path validator (no leading slash, no backslash, no
        // empty segment).
        $node = new NodeFact(
            localId: 'id-test',
            kind: 'class',
            canonicalName: 'App\\Checkout',
            displayName: 'Checkout',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Checkout.php', 1, 5),
        );

        // Stub returns an empty list. Production rules return
        // list<ClassificationFact> (one or more entries).
        $result = $stub->classify($node);
        assertSame([], $result);
    }
}
