<?php

namespace App\Providers;

use App\Events\CheckoutCompleted;
use App\Listeners\SendReceiptListener;
use App\Models\Order;
use App\Policies\OrderPolicy;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        CheckoutCompleted::class => [SendReceiptListener::class],
    ];

    protected $policies = [
        Order::class => OrderPolicy::class,
    ];
}
