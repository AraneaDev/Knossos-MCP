<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use RuntimeException;

/** The worker was given a request it cannot accept; refused rather than best-guessed. */
final class WorkerInputException extends RuntimeException {}
