<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

/**
 * How far a fact is inferred rather than proven.
 *
 * The distinction Knossos exists to preserve: `Certain` is provable from syntax,
 * `Probable` and `Possible` rest on convention or inference. Queries filter and
 * report on it, so recording a guess as certain does not merely mislabel one
 * fact — it makes the whole graph's confidence reporting untrustworthy.
 */
enum Confidence: string
{
    case Certain = 'certain';
    case Probable = 'probable';
    case Possible = 'possible';
}
