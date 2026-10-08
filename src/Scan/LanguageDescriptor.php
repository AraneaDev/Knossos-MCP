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
     * @param list<string> $analysisInputs paths under the installation root, `dir/**` for a tree,
     *                                     whose bytes decide what the worker emits; see {@see AnalysisHash}
     * @param ?int $workerMemoryMb heap/memory cap in mebibytes, or null when the runtime decides.
     *                             It caps a heap, not the process: the TypeScript worker runs a
     *                             second V8 isolate (the scan thread) that mirrors the same heap
     *                             cap, so its resident memory can exceed this value (1.16 GB RSS
     *                             was observed at a 1024 MB cap).
     * @param bool $addedFilesAffectAll whether a file added in one of these languages can change what
     *                                  every file already scanned produced, as a global script can; the
     *                                  planner then rebuilds every cached file of this scanner on an added
     *                                  file. Authoritative for the packaged workers: their manifests also
     *                                  declare `added_files_affect_all`, which the core does not consult.
     * @param ?string $layoutMarkers a pattern for the project-relative paths whose deletion changes
     *                               where every file of these languages resolves, as a top-level
     *                               `__init__.py` decides whether its directory is a Python source
     *                               root; the planner then rebuilds every cached file of this scanner
     *                               when a cached file it matches is gone. Adding one needs no rule:
     *                               the worker reads its absence, and every file shares that read.
     * @param bool $directReads whether every contribution's reads name each file its facts came
     *                          from, so that rebuilding one of its files with the same bytes changes
     *                          nothing a reader of that file read. The planner then reaches a reader
     *                          only through a file whose bytes changed, not through every owner a
     *                          change rebuilt. A worker whose importer names only a module file and
     *                          relies on the module's own reads for what it re-exports must not set it.
     */
    public function __construct(
        public string $key,
        public array $languages,
        public array $command,
        public string $stage,
        public int $scanBatchFiles = WorkerExecutionPolicy::SCAN_BATCH_FILES,
        public int $scanBatchSourceBytes = WorkerExecutionPolicy::SCAN_BATCH_SOURCE_BYTES,
        public bool $optional = false,
        public ?int $workerMemoryMb = null,
        public array $analysisInputs = [],
        public bool $addedFilesAffectAll = false,
        public ?string $layoutMarkers = null,
        public bool $directReads = false,
    ) {}

    /**
     * The packaged worker descriptors for every supported language.
     *
     * TypeScript overrides the batch defaults the most, and both of its
     * overrides exist for the same reason: it pays for a whole `ts.createProgram` plus
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
     * Rust walks per file, like PHP and Python, and reads and indexes every
     * Rust file of the project once per request, parsing only bytes its
     * process has not indexed before, so nothing is gained by widening its
     * file cap. Its source-byte budget is
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
            new self('php', ['php'], [PHP_BINARY, '-d', 'memory_limit=512M', $installationRoot . '/workers/php/bin/worker'], 'scanner_php', workerMemoryMb: 512, analysisInputs: ['workers/php/src/**', 'workers/php/bin/worker', 'workers/php/composer.lock']),
            new self(
                'typescript',
                ['typescript', 'javascript'],
                // 2048, not 1024: one scan request of a mid-sized Vue project
                // peaked at 1.35 GB of used heap, so the old default sat below
                // what a real project needs and whether a scan survived came
                // down to GC timing. The failure is not a graceful one — the
                // worker dies and the scan commits a graph with that whole
                // language missing. A cap is not an allocation, so a small
                // project pays nothing for the headroom.
                //
                // --expose-gc lets the worker collect each program it releases
                // before building the next. Without it a request over many
                // tsconfigs left the released programs resident under that
                // headroom: 1.7 GB of RSS for under 0.5 GB live, enough for a
                // host memory guard to SIGTERM the worker.
                ['node', '--max-old-space-size=2048', '--expose-gc', $installationRoot . '/workers/typescript/bin/worker.js'],
                'scanner_typescript',
                scanBatchFiles: 2_000,
                scanBatchSourceBytes: 3_000_000,
                workerMemoryMb: 2048,
                analysisInputs: ['workers/typescript/src/**', 'workers/typescript/bin/worker.js', 'workers/typescript/node_modules/typescript/package.json'],
                // A script declares globals any file may use, and no read of
                // an existing file names a script that did not exist.
                addedFilesAffectAll: true,
            ),
            // Python 3.12+ prints a SyntaxWarning to stderr for every invalid
            // escape the parser meets; ignoring that category at start keeps a
            // file full of them from turning stderr into noise.
            new self(
                'python',
                ['python'],
                ['python3', '-I', '-B', '-W', 'ignore::SyntaxWarning', $installationRoot . '/workers/python/bin/worker.py'],
                'scanner_python',
                analysisInputs: ['workers/python/bin/worker.py'],
                // A top-level package marker decides whether its directory is
                // a source root, so deleting one renames every module below
                // it and moves imports elsewhere, and none of those files
                // need have read it.
                layoutMarkers: '#\A[^/]+/__init__\.py\z#',
            ),
            new self(
                'rust',
                ['rust'],
                [$installationRoot . '/workers/rust/bin/knossos-rust-worker'],
                'scanner_rust',
                scanBatchSourceBytes: 3_000_000,
                optional: true,
                analysisInputs: ['workers/rust/bin/knossos-rust-worker'],
                // A Rust file's facts follow the declaration index, whose
                // answers come from the bytes of the files it read, never
                // from what those files read in turn: `pub use` adds nothing
                // to the index.
                directReads: true,
            ),
        ];
    }

    /**
     * The scanner id this language's worker answers under, which is also the
     * owner its worker diagnostics, metadata and cache rows are keyed by.
     */
    public function scannerId(): string
    {
        return 'knossos.' . $this->key;
    }

    /**
     * The scanner id each source language is scanned by, from the default
     * descriptors. Which worker claims a language does not depend on where
     * the workers are installed.
     *
     * @return array<string, string> language to scanner id
     */
    public static function scannerIdsByLanguage(): array
    {
        $scanners = [];
        foreach (self::defaults('') as $descriptor) {
            foreach ($descriptor->languages as $language) {
                $scanners[$language] = $descriptor->scannerId();
            }
        }

        return $scanners;
    }

    /**
     * The scanner ids whose added files can affect every file they scanned,
     * from the default descriptors.
     *
     * @return array<string, true>
     */
    public static function scannersWhoseAddedFilesAffectAll(): array
    {
        $scanners = [];
        foreach (self::defaults('') as $descriptor) {
            if ($descriptor->addedFilesAffectAll) {
                $scanners[$descriptor->scannerId()] = true;
            }
        }

        return $scanners;
    }

    /**
     * The scanner ids whose reads name every file their facts came from
     * ({@see self::$directReads}), from the default descriptors.
     *
     * @return array<string, true>
     */
    public static function scannersWithDirectReads(): array
    {
        $scanners = [];
        foreach (self::defaults('') as $descriptor) {
            if ($descriptor->directReads) {
                $scanners[$descriptor->scannerId()] = true;
            }
        }

        return $scanners;
    }

    /**
     * The pattern of layout markers ({@see self::$layoutMarkers}) of each
     * scanner that has one, from the default descriptors.
     *
     * @return array<string, string> scanner id to pattern
     */
    public static function layoutMarkersByScanner(): array
    {
        $patterns = [];
        foreach (self::defaults('') as $descriptor) {
            if ($descriptor->layoutMarkers !== null) {
                $patterns[$descriptor->scannerId()] = $descriptor->layoutMarkers;
            }
        }

        return $patterns;
    }

    /**
     * Return a copy with the runtime's memory cap adjusted to the given
     * mebibytes, leaving scan-batch and other settings unchanged. A null
     * argument or null $workerMemoryMb returns the descriptor unchanged.
     *
     * Only typescript and php encode their cap in the command array. Python
     * and Rust are runtime- or compile-time managed and have no flag to
     * adjust, so the descriptor is returned unmodified.
     */
    public function withMemoryMb(?int $mb): self
    {
        if ($mb === null || $this->workerMemoryMb === null) {
            return $this;
        }
        if ($mb === $this->workerMemoryMb) {
            return $this;
        }
        $command = $this->command;
        if ($this->key === 'typescript') {
            // replace '--max-old-space-size=N' in place
            foreach ($command as $i => $arg) {
                if (str_starts_with($arg, '--max-old-space-size=')) {
                    $command[$i] = "--max-old-space-size={$mb}";
                    break;
                }
            }
        } elseif ($this->key === 'php') {
            // replace 'memory_limit=NM' in place
            foreach ($command as $i => $arg) {
                if (str_starts_with($arg, '-d') && ($command[$i + 1] ?? '') !== '') {
                    $next = $command[$i + 1];
                    if (str_starts_with($next, 'memory_limit=')) {
                        $command[$i + 1] = "memory_limit={$mb}M";
                        break;
                    }
                }
            }
        }
        return new self(
            $this->key,
            $this->languages,
            $command,
            $this->stage,
            scanBatchFiles: $this->scanBatchFiles,
            scanBatchSourceBytes: $this->scanBatchSourceBytes,
            optional: $this->optional,
            workerMemoryMb: $mb,
            analysisInputs: $this->analysisInputs,
            addedFilesAffectAll: $this->addedFilesAffectAll,
            layoutMarkers: $this->layoutMarkers,
            directReads: $this->directReads,
        );
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
