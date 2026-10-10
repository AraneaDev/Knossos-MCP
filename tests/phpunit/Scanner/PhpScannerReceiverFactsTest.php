<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Facts the PHP scanner derives from one file, read in-process: classes named
 * in annotation strings, receivers typed by a loop over an enum's cases, and a
 * call on the result of a call on a constructed object.
 */
#[Group('php-scanner')]
final class PhpScannerReceiverFactsTest extends KnossosTestCase
{
    public function testAnAnnotationStringNamesAClass(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            namespace App\Entity;

            /**
             * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
             * @Assert\Callback(class="\App\Validator\Rules")
             */
            final class User {}
            PHP);

        assertArrayContains(['references', 'php:class:App\Entity\User', 'php:class:App\Repository\UserRepository'], $edges);
        assertArrayContains(['references', 'php:class:App\Entity\User', 'php:class:App\Validator\Rules'], $edges);
    }

    public function testALoopOverEnumCasesTypesItsVariable(): void
    {
        $scan = $this->scan(<<<'PHP'
            <?php
            namespace App;

            enum Suit
            {
                case Hearts;

                public function label(): string { return ''; }
            }

            final class Table
            {
                public function deal(array $rows): void
                {
                    foreach (Suit::cases() as $suit) {
                        $suit->label();
                    }
                    foreach ($rows as $suit) {
                        $suit->shuffle();
                    }
                }
            }
            PHP);

        assertArrayContains(['calls', 'php:method:App\Table::deal', 'php:method:App\Suit::label'], $scan['edges']);
        // Any other loop leaves the variable untyped again.
        assertSame(false, in_array(['calls', 'php:method:App\Table::deal', 'php:method:App\Suit::shuffle'], $scan['edges'], true));
        assertArrayContains('shuffle', $scan['untyped']);
    }

    public function testACallOnAConstructedObjectsResultIsNamedThroughTheCall(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            namespace App;

            final class Boot
            {
                public function run(): void
                {
                    (new Kernel())->server()->start();
                }
            }
            PHP);

        assertArrayContains(['calls', 'php:method:App\Boot::run', 'php:method_of_return:App\Kernel::server::start'], $edges);
    }

    /**
     * `$x ??= new Foo()` is how PHP spells a collaborator built on first use;
     * a call through it, plain or nullsafe, names that class.
     */
    public function testACoalescingAssignmentOfAConstructionTypesItsVariable(): void
    {
        $scan = $this->scan(<<<'PHP'
            <?php
            namespace App;

            final class Index
            {
                public function map(): array { return []; }
                public function spelling(): string { return ''; }
            }

            final class Resolver
            {
                public function resolve(bool $folded, $other): void
                {
                    $index = null;
                    if ($folded) {
                        $index ??= new Index();
                        $index->map();
                    }
                    $index?->spelling();
                    $other ??= $folded;
                    $other->drop();
                    $held = new Index();
                    $held ??= new Resolver();
                    $held->either();
                }
            }
            PHP);

        assertArrayContains(['calls', 'php:method:App\Resolver::resolve', 'php:method:App\Index::map'], $scan['edges']);
        assertArrayContains(['calls', 'php:method:App\Resolver::resolve', 'php:method:App\Index::spelling'], $scan['edges']);
        // A coalescing assignment of something untyped types nothing, and one
        // of another class than the variable held leaves it either.
        assertArrayContains('drop', $scan['untyped']);
        assertArrayContains('either', $scan['untyped']);
    }

    /**
     * A loop over a parameter whose docblock names the element type types its
     * value variable, however the docblock lays its tags out and whichever
     * array shape it names.
     */
    public function testALoopOverADocumentedParameterTypesItsValue(): void
    {
        $scan = $this->scan(<<<'PHP'
            <?php
            namespace App;

            use App\Facts\Boundary as Fact;

            final class Resolver
            {
                /**
                 * @param list<Fact> $facts @param array<string, string> $names
                 */
                public function resolve(array $facts, array $names): void
                {
                    foreach ($facts as $fact) {
                        $fact->matcher();
                    }
                    foreach ($names as $name) {
                        $name->nothing();
                    }
                }

                /**
                 * @param array<string, \App\Rule> $byId
                 * @param Edge[] $edges
                 */
                public function index(array $byId, iterable $edges): void
                {
                    foreach ($byId as $id => $rule) {
                        $rule->apply();
                    }
                    foreach ($edges as $edge) {
                        $edge->target();
                    }
                    $edges = [];
                    foreach ($edges as $edge) {
                        $edge->reassigned();
                    }
                }
            }
            PHP);

        assertArrayContains(['calls', 'php:method:App\Resolver::resolve', 'php:method:App\Facts\Boundary::matcher'], $scan['edges']);
        assertArrayContains(['calls', 'php:method:App\Resolver::index', 'php:method:App\Rule::apply'], $scan['edges']);
        assertArrayContains(['calls', 'php:method:App\Resolver::index', 'php:method:App\Edge::target'], $scan['edges']);
        // A scalar element type names no class, and a reassigned parameter no longer holds what its docblock says.
        assertArrayContains('nothing', $scan['untyped']);
        assertArrayContains('reassigned', $scan['untyped']);
    }

    /**
     * A method declared to return `\Closure(): X` hands back work that yields
     * an X: the variable the closure's result is assigned to holds one. A
     * closure literal with a declared return type says the same.
     */
    public function testTheResultOfInvokingATypedClosureTypesItsVariable(): void
    {
        $scan = $this->scan(<<<'PHP'
            <?php
            namespace App;

            use App\Query\Envelope;

            final class Tools
            {
                public function call(): void
                {
                    $run = $this->prepare();
                    $envelope = $run();
                    $envelope->withWarnings();
                    $make = static fn(): Report => new Report();
                    $report = $make();
                    $report->render();
                    $plain = $this->plain();
                    $result = $plain();
                    $result->unknown();
                }

                /** @return \Closure(): Envelope */
                private function prepare(): \Closure
                {
                    return static fn(): Envelope => new Envelope();
                }

                private function plain(): \Closure
                {
                    return static fn() => null;
                }
            }
            PHP);

        assertArrayContains(['calls', 'php:method:App\Tools::call', 'php:method:App\Query\Envelope::withWarnings'], $scan['edges']);
        assertArrayContains(['calls', 'php:method:App\Tools::call', 'php:method:App\Report::render'], $scan['edges']);
        // A closure whose result type nothing declares types nothing.
        assertArrayContains('unknown', $scan['untyped']);
    }

    /** @return list<array{string, string, string}> */
    private function edges(string $source): array
    {
        return $this->scan($source)['edges'];
    }

    /** @return array{edges: list<array{string, string, string}>, untyped: list<string>} */
    private function scan(string $source): array
    {
        require_once self::repositoryRoot() . '/workers/php/vendor/autoload.php';
        $root = sys_get_temp_dir() . '/knossos-stale-php-facts-' . bin2hex(random_bytes(6));
        mkdir($root);
        file_put_contents($root . '/Subject.php', $source);
        try {
            $contribution = (new \KnossosPhpScanner\PhpScanner())->scan($root, $root . '/Subject.php', 'Subject.php');
        } finally {
            $this->removeTempTree($root);
        }
        $edges = [];
        foreach ($contribution['edges'] as $edge) {
            $edges[] = [$edge['kind'], $edge['source'], $edge['target']];
        }
        $untyped = [];
        foreach ($contribution['nodes'] as $node) {
            foreach (((array) $node['attributes'])['unresolved_member_calls'] ?? [] as $name) {
                $untyped[] = $name;
            }
        }

        return ['edges' => $edges, 'untyped' => $untyped];
    }
}
