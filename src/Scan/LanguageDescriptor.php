<?php

declare(strict_types=1);

namespace Knossos\Scan;

final readonly class LanguageDescriptor
{
    /** @param list<string> $languages @param list<string> $command */
    public function __construct(
        public string $key,
        public array $languages,
        public array $command,
        public string $stage,
    ) {}

    /** @return list<self> */
    public static function defaults(string $installationRoot): array
    {
        return [
            new self('php', ['php'], [PHP_BINARY, '-d', 'memory_limit=512M', $installationRoot . '/workers/php/bin/worker'], 'scanner_php'),
            new self('typescript', ['typescript', 'javascript'], ['node', '--max-old-space-size=512', $installationRoot . '/workers/typescript/bin/worker.js'], 'scanner_typescript'),
            new self('python', ['python'], ['python3', '-I', '-B', $installationRoot . '/workers/python/bin/worker.py'], 'scanner_python'),
        ];
    }
}
