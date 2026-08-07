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
     * The two batch bounds differ per language because the two costs they guard
     * differ per language:
     *
     * - PHP and Python pay per file, so the file-count cap is the binding one.
     * - TypeScript pays for a whole `ts.createProgram` plus
     *   `ts.getPreEmitDiagnostics` on EVERY request, a cost set by the program
     *   rather than by how many files the request asked for. Splitting its work
     *   into more requests therefore repeats the expensive part: measured on a
     *   1,200-file single-tsconfig corpus, three 400-file requests cost 2.27 s
     *   against 1.32 s for one request. Its file cap is raised to 2,000 so a
     *   normal project is one request again. Its source-byte budget is cut to
     *   600 KB instead, because TypeScript output expands ~15.5x from source
     *   against PHP's ~2.2x; 600 KB * 15.5 is ~9 MB, the same share of the
     *   20 MB cap that PHP's 4 MB * 2.2 takes.
     *
     * Python keeps the defaults: its expansion has not been measured, so it
     * inherits the conservative PHP-derived budget rather than a guess.
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
                scanBatchSourceBytes: 600_000,
            ),
            new self('python', ['python'], ['python3', '-I', '-B', $installationRoot . '/workers/python/bin/worker.py'], 'scanner_python'),
        ];
    }
}
