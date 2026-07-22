<?php

namespace App\Jobs;

use Illuminate\Bus\Bus;

final class BusDispatcher
{
    public function handle(): void
    {
        Bus::dispatch(new GenerateInvoiceJob());
    }
}
