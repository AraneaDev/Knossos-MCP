<?php

use App\Http\Controllers\CheckoutController;
use Illuminate\Support\Facades\Route;

// Route::any() — exercises the $methods = ['ANY'] branch
Route::any('/any-catchall', [CheckoutController::class, 'show']);

// String-based action (Controller@method format) — exercises the str_contains($string, '@') path
Route::get('/string-action', 'App\Http\Controllers\CheckoutController@show');
