<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

final class Protocol
{
    public const VERSION = '1.0';
    public const OUTPUT_SCHEMA_VERSION = '1.0';

    public const METHOD_INITIALIZE = 'initialize';
    public const METHOD_DISCOVER = 'discover';
    public const METHOD_SCAN = 'scan';
    public const METHOD_CANCEL = 'cancel';
    public const METHOD_SHUTDOWN = 'shutdown';

    private function __construct() {}
}
