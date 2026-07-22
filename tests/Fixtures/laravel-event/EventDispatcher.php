<?php

namespace App\Events;

use Illuminate\Events\Event;

final class EventDispatcher
{
    public function handle(): void
    {
        Event::dispatch(new CheckoutCompleted());
    }
}
