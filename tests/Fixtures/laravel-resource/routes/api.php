<?php

use App\Http\Controllers\PhotoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::apiResource('users', UserController::class);
    Route::resource('photos', PhotoController::class)->only(['index', 'show', 'edit']);
    Route::apiResource('photo-tags', PhotoController::class)->except('destroy', 'update');
});
