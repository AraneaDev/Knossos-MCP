<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Observers\OrderObserver;
use App\Services\StripeGateway;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, StripeGateway::class);
    }

    public function boot(): void
    {
        Order::observe(OrderObserver::class);
    }
}
