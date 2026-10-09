<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use JsonException;
use Knossos\Bundle\BundleRedactor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('bundle')]
final class BundleRedactorTest extends TestCase
{
    /**
     * The salt is the only thing that keeps a token from being reversed by
     * hashing guessed paths. An export that fails part-way must not carry it
     * out in the arguments of its stack trace.
     */
    public function testAFailedRedactionKeepsTheSaltOutOfItsTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $salt = 'salt-that-must-never-be-printed!';
        try {
            BundleRedactor::redact(['files' => [['id' => 'f1', 'relative_path' => 'src/a.php']], 'nodes' => [['id' => 'n1', 'attributes_json' => 'not json']]], false, $salt);
            self::fail('Invalid attributes must stop the export.');
        } catch (JsonException $error) {
            $frames = array_filter($error->getTrace(), static fn(array $frame): bool => str_starts_with($frame['class'] ?? '', 'Knossos\\Bundle\\'));
            self::assertNotSame([], $frames);
            self::assertStringNotContainsString($salt, print_r(array_column($frames, 'args'), true));
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }
}
