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
