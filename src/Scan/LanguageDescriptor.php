<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\WorkerExecutionPolicy;

/**
 * How to launch one language's scanner worker, and what it claims to handle.
 *
 * Keeps the command, memory cap, and expected worker id in one place so adding a
 * language is a descriptor rather than a change to the runner.
 */
final readonly class LanguageDescriptor
{
    /**
     * @param list<string> $languages
     * @param list<string> $command
     * @param int $scanBatchFiles most files in one scan request, guarding the deadline
     * @param int $scanBatchSourceBytes most source bytes in one scan request, guarding the output-byte cap
     * @param bool $optional whether a missing worker binary is tolerated
     */
    public function __construct(
        public string $key,
        public array $languages,
        public array $command,
        public string $stage,
        public int $scanBatchFiles = WorkerExecutionPolicy::SCAN_BATCH_FILES,
        public int $scanBatchSourceBytes = WorkerExecutionPolicy::SCAN_BATCH_SOURCE_BYTES,
        public bool $optional = false,
    ) {}

    /**
     * The packaged worker descriptors for every supported language.
     *
     * Only TypeScript overrides the defaults, and both of its overrides exist
     * for the same reason: it pays for a whole `ts.createProgram` plus
     * `ts.getPreEmitDiagnostics` on EVERY request, a cost set by the program
     * rather than by how many files the request asked for. Splitting its work
     * into more requests therefore repeats the expensive part. Measured on a
     * 366-file / 2.7 MB corpus of real hand-written TypeScript and JavaScript
     * (KaTeX plus this repository's own worker): one request 4.6 s, five
     * requests 8.5 s.
     *
     * So TypeScript takes a 2,000-file cap and a 3 MB source budget, sized so a
     * real project is one request. Both are optimistic, and deliberately: real
     * TypeScript expands 1.68-1.81x from source, well inside the cap at 3 MB,
     * but a codebase dense in declared symbols can expand far more. That case is
     * handled by halving the budget and retrying rather than by making every
     * scan pay for it — see WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS.
     *
     * PHP and Python keep the defaults. Both were measured on real sources
     * (2.24x and 1.88x respectively), and both pay per file rather than per
     * program, so nothing is gained by widening their batches.
     *
     * Rust pays per file rather than per program too, like PHP and Python, so
     * nothing is gained by widening its file cap. Its source-byte budget is
     * narrower than the 4 MB default, though: measured on real hand-written
     * Rust (`serde-rs/serde`, 208 files / 1.2 MB) it expands 2.59x, and
     * `WorkerLimits::maxOutputBytes` is 20 MB, so the 4 MB default would
     * project 10.36 MB of output per batch — over the 10 MB half-cap every
     * other language's budget is measured to stay under. A 3 MB budget projects
     * 7.77 MB, back in the same margin as the others.
     *
     * Rust is the only OPTIONAL language. Its worker is a compiled binary rather
     * than a script run by a runtime the installer already requires, so a native
     * installation without cargo simply has no Rust scanner. Making it mandatory
     * would break every existing install on upgrade for the sake of a language
     * most of those projects do not contain.
     *
     * @return list<self>
     */
    public static function defaults(string $installationRoot): array
    {
        return [
            new self('php', ['php'], [PHP_BINARY, '-d', 'memory_limit=512M', $installationRoot . '/workers/php/bin/worker'], 'scanner_php'),
            new self(
                'typescript',
                ['typescript', 'javascript'],
                ['node', '--max-old-space-size=512', $installationRoot . '/workers/typescript/bin/worker.js'],
                'scanner_typescript',
                scanBatchFiles: 2_000,
                scanBatchSourceBytes: 3_000_000,
            ),
            new self('python', ['python'], ['python3', '-I', '-B', $installationRoot . '/workers/python/bin/worker.py'], 'scanner_python'),
            new self(
                'rust',
                ['rust'],
                [$installationRoot . '/workers/rust/bin/knossos-rust-worker'],
                'scanner_rust',
                scanBatchSourceBytes: 3_000_000,
                optional: true,
            ),
        ];
    }

    /**
     * Whether this language's worker is actually present.
     *
     * Only an optional descriptor is probed. A mandatory worker's `command[0]`
     * is an interpreter name resolved through PATH (`node`, `python3`), not a
     * path, so probing it would report every one of them missing.
     */
    public function isInstalled(): bool
    {
        return !$this->optional || is_file($this->command[0]);
    }

    /**
     * The packaged descriptors whose workers are present on this installation.
     *
     * `defaults()` stays a pure list so it can be asserted on without a
     * filesystem; this is the one place that asks the disk. Both this and
     * DoctorService go through {@see self::isInstalled()}, so scan and doctor
     * cannot disagree about whether a language is available.
     *
     * @return list<self>
     */
    public static function installed(string $installationRoot): array
    {
        return array_values(array_filter(
            self::defaults($installationRoot),
            static fn(self $descriptor): bool => $descriptor->isInstalled(),
        ));
    }
}
