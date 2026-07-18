<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\NodeFact;

interface ClassificationRule
{
    /** Return the stable identifier recorded as classification provenance. */
    public function id(): string;

    /**
     * Classify one graph node without mutating it.
     *
     * @return list<ClassificationFact>
     */
    public function classify(NodeFact $node): array;
}
