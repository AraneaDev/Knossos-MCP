<?php

declare(strict_types=1);

namespace Knossos\Query\Diagram;

/**
 * PlantUML component diagram source, its labels escaped as string literals.
 *
 * PlantUML lays out top to bottom by default, so only `LR` needs a statement.
 */
final readonly class PlantUmlRenderer implements DiagramRenderer
{
    /** `@startuml`, the direction, one `component` per node and one labelled arrow per edge, then `@enduml`. */
    public function render(string $direction, array $nodes, array $edges): string
    {
        $lines = ['@startuml'];
        if ($direction === 'LR') {
            $lines[] = 'left to right direction';
        }
        foreach ($nodes as $alias => $label) {
            $lines[] = sprintf('component "%s" as %s', str_replace(['\\', '"'], ['\\\\', '\\"'], $label), $alias);
        }
        foreach ($edges as $edge) {
            $lines[] = sprintf('%s --> %s : %s', $edge['source'], $edge['target'], $edge['label']);
        }
        $lines[] = '@enduml';

        return implode("\n", $lines) . "\n";
    }
}
