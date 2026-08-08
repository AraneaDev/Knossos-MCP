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
     */
    public function __construct(
        public string $key,
        public array $languages,
        public array $command,
        public string $stage,
        public int $scanBatchFiles = WorkerExecutionPolicy::SCAN_BATCH_FILES,
        public int $scanBatchSourceBytes = WorkerExecutionPolicy::SCAN_BATCH_SOURCE_BYTES,
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
        ];
    }
}
