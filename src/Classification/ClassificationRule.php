<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\NodeFact;

/**
 * Contract for a rule that assigns architectural roles to graph nodes.
 *
 * Rules are pure: they read a node and return facts. That keeps them independently
 * testable and means adding a framework's conventions never risks changing what an
 * existing rule concludes.
 */
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
