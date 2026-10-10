<?php

declare(strict_types=1);

namespace Knossos\Query\Diagram;

/**
 * Writes a chosen slice of the graph as the source text of one diagram format.
 *
 * The slice, its aliases and its labels are decided before a renderer sees
 * them; a renderer owns only the syntax and the escaping its format needs.
 */
interface DiagramRenderer
{
    /**
     * The diagram source, one statement per line, ending in a newline.
     *
     * @param string $direction `LR` or `TB`
     * @param array<string, string> $nodes alias => label, control characters already blanked
     * @param list<array{source: string, target: string, label: string}> $edges by alias, the label already reduced to safe characters
     */
    public function render(string $direction, array $nodes, array $edges): string;
}
