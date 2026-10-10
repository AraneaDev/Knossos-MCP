<?php

declare(strict_types=1);

namespace Knossos\Query\Diagram;

/** Mermaid flowchart source, its labels escaped as HTML entities. */
final readonly class MermaidRenderer implements DiagramRenderer
{
    /** A `flowchart` in the given direction, then one node per line, then one labelled arrow per line. */
    public function render(string $direction, array $nodes, array $edges): string
    {
        $lines = ['flowchart ' . $direction];
        foreach ($nodes as $alias => $label) {
            $lines[] = sprintf('  %s["%s"]', $alias, str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $label));
        }
        foreach ($edges as $edge) {
            $lines[] = sprintf('  %s -->|%s| %s', $edge['source'], $edge['label'], $edge['target']);
        }

        return implode("\n", $lines) . "\n";
    }
}
