<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;

final readonly class ResultEnricher
{
    private const COMPACT_EVIDENCE = 3;

    public function __construct(
        private StalenessProbe $probe,
        private NextStepPlanner $planner,
    ) {}

    public function enrich(ResultEnvelope $envelope, string $toolName, string $verbosity): ResultEnvelope
    {
        $total = count($envelope->evidence);
        $base = $verbosity === 'compact'
            ? $this->compact($envelope)
            : $envelope;
        $shown = count($base->evidence);

        $staleness = $this->probe->probe($envelope->projectId);
        $steps = $this->planner->plan($toolName, $envelope);

        $withoutMeta = $base->with(staleness: $staleness, nextSteps: $steps);
        $resultBytes = strlen((string) json_encode($withoutMeta->jsonSerialize(), JSON_UNESCAPED_SLASHES));

        return $withoutMeta->with(meta: [
            'result_bytes' => $resultBytes,
            'verbosity' => $verbosity,
            'evidence_total' => $total,
            'evidence_shown' => $shown,
        ]);
    }

    private function compact(ResultEnvelope $envelope): ResultEnvelope
    {
        if (count($envelope->evidence) <= self::COMPACT_EVIDENCE) {
            return $envelope;
        }
        return new ResultEnvelope(
            $envelope->projectId,
            $envelope->snapshotId,
            $envelope->summary,
            $envelope->data,
            array_slice($envelope->evidence, 0, self::COMPACT_EVIDENCE),
            $envelope->warnings,
            $envelope->truncated,
            $envelope->staleness,
            $envelope->nextSteps,
            $envelope->meta,
        );
    }
}
