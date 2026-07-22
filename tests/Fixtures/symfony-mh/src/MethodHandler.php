<?php

namespace App;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class InvoiceGenerated
{
}

final class MethodHandler
{
    #[AsMessageHandler]
    public function handle(InvoiceGenerated $message): void
    {
    }
}
