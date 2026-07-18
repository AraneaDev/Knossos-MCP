<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

enum Confidence: string
{
    case Certain = 'certain';
    case Probable = 'probable';
    case Possible = 'possible';
}
