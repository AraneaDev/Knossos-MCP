<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Evidence implements JsonSerializable
{
    public function __construct(
        public string $relativePath,
        public int $startLine,
        public int $endLine,
    ) {
        RelativePath::assertValid($relativePath, 'Evidence path');

        if ($startLine < 1 || $endLine < $startLine) {
            throw new InvalidArgumentException('Evidence lines must be a valid one-based range.');
        }
    }

    /** @return array{path: string, start_line: int, end_line: int} */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->relativePath,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
        ];
    }
}
