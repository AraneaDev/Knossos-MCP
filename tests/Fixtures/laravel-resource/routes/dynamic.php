<?php

use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::resource('tags', TagController::class)->only(TagController::ACTIONS);
Route::resource('labels', TagController::class)->except($hidden);
Route::resource('marks', TagController::class)->only([]);
