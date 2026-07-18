<?php

use App\Http\Controllers\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('shop')->name('shop.')->group(function (): void {
    Route::get('/checkout', [CheckoutController::class, 'show'])
        ->middleware('verified')
        ->name('checkout');
});

Route::match(['GET', 'POST'], '/matched', [CheckoutController::class, 'show']);

$uri = '/dynamic';
Route::get($uri, [CheckoutController::class, 'show']);

$methods = ['GET', 'POST'];
Route::match($methods, '/dynamic-methods', [CheckoutController::class, 'show']);
Route::match(['GET', 'POST'], $uri, [CheckoutController::class, 'show']);
