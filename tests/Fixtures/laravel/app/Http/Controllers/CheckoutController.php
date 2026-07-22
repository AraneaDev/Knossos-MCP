<?php

namespace App\Http\Controllers;

use App\Events\CheckoutCompleted;
use Illuminate\Routing\Controller;

final class CheckoutController extends Controller
{
    public function show(): void
    {
        CheckoutCompleted::dispatch();
        event(new CheckoutCompleted());
    }
}
