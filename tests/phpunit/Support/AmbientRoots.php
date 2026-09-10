<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

/**
 * Isolation for the tests that assert what happens when no root is allowed.
 *
 * `serve` refuses to start without one, and proving that means proving there
 * is none. Clearing `KNOSSOS_ALLOWED_ROOTS` is only half of it: roots are
 * unioned from the environment AND from the roots file beside the database,
 * which for a test that lets the database path default is the developer's own
 * `.knossos/roots.json`. Running the product's own `knossos allow-root` on the
 * checkout therefore used to turn three passing tests red, which makes the
 * suite a report on the machine rather than on the code.
 *
 * `KNOSSOS_ROOTS_FILE` is what closes it: {@see \Knossos\Discovery\AllowedRoots::defaultConfigPath()}
 * honours it ahead of the file beside the database, so pointing it at a path
 * that does not exist removes the file source outright, whichever database the
 * command under test decides to open. Nothing is created, so there is nothing
 * to clean up: an absent file yields no roots, which is precisely the state
 * these tests are about.
 *
 * Both variables are restored to their previous value, or to being unset,
 * even when the callable throws.
 */
trait AmbientRoots
{
    /**
     * Run $callback with no allowed root reachable from environment or file.
     *
     * Covers a subprocess as well as an in-process call: `putenv()` changes the
     * environment `proc_open()` hands to the child.
     */
    protected function withoutAmbientAllowedRoots(callable $callback): void
    {
        $previousRoots = getenv('KNOSSOS_ALLOWED_ROOTS');
        $previousFile = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_ALLOWED_ROOTS');
        putenv('KNOSSOS_ROOTS_FILE=' . sys_get_temp_dir() . '/knossos-absent-roots-' . bin2hex(random_bytes(6)) . '.json');
        try {
            $callback();
        } finally {
            putenv(is_string($previousRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $previousRoots : 'KNOSSOS_ALLOWED_ROOTS');
            putenv(is_string($previousFile) ? 'KNOSSOS_ROOTS_FILE=' . $previousFile : 'KNOSSOS_ROOTS_FILE');
        }
    }
}
