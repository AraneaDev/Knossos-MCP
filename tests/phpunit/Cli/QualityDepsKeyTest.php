<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * CI publishes the quality image's dependency stages once per value of
 * `tools/quality-deps-key` and adds the source on top in every lane. A file a
 * dependency stage copies that the key does not hash would let a changed input
 * reuse a stale dependency image, which passes CI and differs from a clean
 * build. The source stages have the opposite duty: they run on every push, so
 * anything that installs or rewrites a whole tree there puts the slow, large
 * layers back on every run.
 */
final class QualityDepsKeyTest extends KnossosTestCase
{
    /** The stages the published dependency images are built from. */
    private const DEPENDENCY_STAGES = ['node_runtime', 'composer_runtime', 'rust_builder', 'runtime_deps', 'quality_tools'];

    /** The stages that add this commit's source on top of a dependency image. */
    private const SOURCE_STAGES = ['runtime', 'quality'];

    /** What a source stage must never run: installs, downloads, builds and recursive ownership rewrites. */
    private const FORBIDDEN_IN_SOURCE_STAGES = ['apt-get', 'npm ci', 'npm install', 'composer install', 'pip install', 'curl', 'cargo build', 'chown -R'];

    /** Every file a dependency stage copies from the build context is hashed into the key. */
    #[Group('documentation')]
    public function testEveryDependencyStageInputIsHashedIntoTheKey(): void
    {
        $inputs = array_values(array_filter(explode("\n", $this->runKeyScript(self::repositoryRoot(), ['--inputs'])), static fn(string $line): bool => $line !== ''));
        assertNotSame([], $inputs);

        $stages = self::stages();
        $unkeyed = [];
        foreach (self::DEPENDENCY_STAGES as $name) {
            assertArrayHasKey($name, $stages, 'Dockerfile stage missing');
            foreach ($stages[$name] as $instruction) {
                foreach (self::contextSources($instruction) as $source) {
                    if (!self::covered($source, $inputs)) {
                        $unkeyed[] = $name . ': ' . $source;
                    }
                }
            }
        }
        assertSame([], $unkeyed, 'copied into a dependency stage but not hashed by tools/quality-deps-key');
    }

    /** The source stages only copy and arrange; every install lives in a dependency stage. */
    #[Group('documentation')]
    public function testSourceStagesInstallNothing(): void
    {
        $stages = self::stages();
        $offending = [];
        foreach (self::SOURCE_STAGES as $name) {
            assertArrayHasKey($name, $stages, 'Dockerfile stage missing');
            foreach ($stages[$name] as $instruction) {
                if (preg_match('/^COPY\s.*--from=rust_builder\b/i', $instruction) === 1) {
                    $offending[] = $name . ': ' . $instruction;
                }
                if (preg_match('/^RUN\s/i', $instruction) !== 1) {
                    continue;
                }
                foreach (self::FORBIDDEN_IN_SOURCE_STAGES as $needle) {
                    if (str_contains($instruction, $needle)) {
                        $offending[] = $name . ': ' . $needle;
                    }
                }
            }
        }
        assertSame([], $offending, 'a source stage installs, downloads or rewrites a tree');
    }

    /** `docker build .` still yields the quality image, and the runtime target compose pins still exists. */
    #[Group('documentation')]
    public function testQualityIsTheFinalStageAndRuntimeExists(): void
    {
        $names = array_keys(self::stages());
        assertSame('quality', $names[array_key_last($names)]);
        assertArrayHasKey('runtime', array_flip($names));
    }

    /** The key is short, stable, follows its inputs, and ignores local build output under the Rust worker. */
    #[Group('documentation')]
    public function testKeyFollowsItsInputsAndIgnoresBuildOutput(): void
    {
        $this->inSandbox(function (string $sandbox): void {
            $first = $this->runKeyScript($sandbox, []);
            assertMatchesRegularExpression('/^[0-9a-f]{16}\n$/', $first);
            assertSame($first, $this->runKeyScript($sandbox, []));

            foreach (['target/debug/out', 'bin/knossos-rust-worker', 'mutants.out/outcomes.json'] as $output) {
                mkdir(dirname($sandbox . '/workers/rust/' . $output), 0o777, true);
                file_put_contents($sandbox . '/workers/rust/' . $output, 'local build output');
            }
            assertSame($first, $this->runKeyScript($sandbox, []), 'local Rust build output changed the key');

            file_put_contents($sandbox . '/workers/rust/Cargo.toml', "changed\n");
            $changedRust = $this->runKeyScript($sandbox, []);
            assertNotSame($first, $changedRust, 'a Rust worker change kept the key');

            file_put_contents($sandbox . '/composer.lock', "changed\n");
            assertNotSame($changedRust, $this->runKeyScript($sandbox, []), 'a lockfile change kept the key');
        });
    }

    /**
     * Every release pull request bumps the version LABEL, and comments change
     * nothing a build produces, so neither may rebuild the dependency images.
     * A parser directive changes how every stage is read, so it must.
     */
    #[Group('documentation')]
    public function testKeyIgnoresDockerfileCommentsAndTheReleaseVersion(): void
    {
        $this->inSandbox(function (string $sandbox): void {
            $dockerfile = static fn(string $directive, string $comment, string $version): string => implode("\n", [
                '# syntax=' . $directive,
                '',
                'FROM php:8.5 AS runtime_deps',
                '# ' . $comment,
                'RUN true \\',
                '    # ' . $comment,
                '    && true',
                '',
                'FROM runtime_deps AS runtime',
                '# x-release-please-start-version',
                'LABEL org.opencontainers.image.version="' . $version . '"',
                '# x-release-please-end',
                '',
            ]);
            file_put_contents($sandbox . '/Dockerfile', $dockerfile('docker/dockerfile:1', 'why', '0.20.0'));
            $first = $this->runKeyScript($sandbox, []);

            file_put_contents($sandbox . '/Dockerfile', $dockerfile('docker/dockerfile:1', 'why', '0.21.0'));
            assertSame($first, $this->runKeyScript($sandbox, []), 'a version bump changed the key');

            file_put_contents($sandbox . '/Dockerfile', $dockerfile('docker/dockerfile:1', 'a reworded reason', '0.20.0'));
            assertSame($first, $this->runKeyScript($sandbox, []), 'a comment-only edit changed the key');

            file_put_contents($sandbox . '/Dockerfile', $dockerfile('docker/dockerfile:1.7', 'why', '0.20.0'));
            assertNotSame($first, $this->runKeyScript($sandbox, []), 'a parser directive change kept the key');

            file_put_contents($sandbox . '/Dockerfile', str_replace('RUN true', 'RUN false', $dockerfile('docker/dockerfile:1', 'why', '0.20.0')));
            assertNotSame($first, $this->runKeyScript($sandbox, []), 'an instruction change kept the key');
        });
    }

    /** The release version is a label of the source stages; in a dependency stage it would rebuild them on every release. */
    #[Group('documentation')]
    public function testNoDependencyStageCarriesTheReleaseVersion(): void
    {
        $stages = self::stages();
        $labelled = [];
        foreach (self::DEPENDENCY_STAGES as $name) {
            foreach ($stages[$name] ?? [] as $instruction) {
                if (preg_match('/^LABEL\s.*org\.opencontainers\.image\.version/i', $instruction) === 1) {
                    $labelled[] = $name;
                }
            }
        }
        assertSame([], $labelled, 'the version LABEL sits in a dependency stage');
    }

    /**
     * Run $test against a throwaway copy of the key script, with every input
     * present as a small file, and remove the copy afterwards.
     *
     * @param callable(string): void $test
     */
    private function inSandbox(callable $test): void
    {
        $sandbox = sys_get_temp_dir() . '/knossos-deps-key-' . bin2hex(random_bytes(6));
        try {
            $inputs = array_values(array_filter(explode("\n", $this->runKeyScript(self::repositoryRoot(), ['--inputs'])), static fn(string $line): bool => $line !== ''));
            mkdir($sandbox . '/tools', 0o777, true);
            copy(self::repositoryRoot() . '/tools/quality-deps-key', $sandbox . '/tools/quality-deps-key');
            chmod($sandbox . '/tools/quality-deps-key', 0o755);
            foreach ($inputs as $input) {
                $path = $input === 'workers/rust' ? $sandbox . '/workers/rust/Cargo.toml' : $sandbox . '/' . $input;
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0o777, true);
                }
                file_put_contents($path, $input . "\n");
            }
            $test($sandbox);
        } finally {
            if (is_dir($sandbox)) {
                $this->runCommand(['rm', '-rf', $sandbox], null);
            }
        }
    }

    /**
     * The Dockerfile's instructions per stage, in order, with comment lines
     * dropped and continuation lines joined the way Docker reads them.
     *
     * @return array<string, list<string>>
     */
    private static function stages(): array
    {
        $lines = file(self::repositoryRoot() . '/Dockerfile', FILE_IGNORE_NEW_LINES) ?: [];
        $instructions = [];
        $current = '';
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (str_ends_with(rtrim($line), '\\')) {
                $current .= substr(rtrim($line), 0, -1) . ' ';
                continue;
            }
            $current .= $line;
            if (trim($current) !== '') {
                $instructions[] = trim((string) preg_replace('/\s+/', ' ', $current));
            }
            $current = '';
        }

        $stages = [];
        $name = null;
        foreach ($instructions as $instruction) {
            if (preg_match('/^FROM\s+\S+\s+AS\s+(\S+)$/i', $instruction, $match) === 1) {
                $name = $match[1];
                $stages[$name] = [];
                continue;
            }
            if ($name !== null) {
                $stages[$name][] = $instruction;
            }
        }

        return $stages;
    }

    /**
     * The build-context paths a COPY or ADD instruction reads; none for a copy from another stage.
     *
     * @return list<string>
     */
    private static function contextSources(string $instruction): array
    {
        if (preg_match('/^(COPY|ADD)\s+(.+)$/i', $instruction, $match) !== 1) {
            return [];
        }
        $arguments = explode(' ', $match[2]);
        $sources = [];
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--from=')) {
                return [];
            }
            if (!str_starts_with($argument, '--')) {
                $sources[] = $argument;
            }
        }
        array_pop($sources);

        return array_map(static fn(string $source): string => rtrim((string) preg_replace('#^\./#', '', $source), '/'), $sources);
    }

    /**
     * Whether $source is one of the key's inputs or lies under one.
     *
     * @param list<string> $inputs
     */
    private static function covered(string $source, array $inputs): bool
    {
        foreach ($inputs as $input) {
            if ($source === $input || str_starts_with($source, $input . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the key script found under $root with $arguments and return its standard output.
     *
     * @param list<string> $arguments
     */
    private function runKeyScript(string $root, array $arguments): string
    {
        return $this->runCommand(['sh', $root . '/tools/quality-deps-key', ...$arguments], $root);
    }

    /**
     * Run $command and return its standard output, failing the test on a non-zero exit.
     *
     * @param list<string> $command
     */
    private function runCommand(array $command, ?string $cwd): string
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start ' . $command[0] . '.');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        assertSame(0, $status, implode(' ', $command) . ' failed: ' . $errors);

        return $output;
    }
}
