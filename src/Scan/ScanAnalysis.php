<?php

declare(strict_types=1);

namespace Knossos\Scan;

/** Classification and boundary facts derived from a scanned graph. */
final readonly class ScanAnalysis
{
    /** @param list<object> $classifications @param list<object> $boundaries */
    public function __construct(public array $classifications, public array $boundaries) {}
}
