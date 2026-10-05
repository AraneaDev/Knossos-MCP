<?php

use Illuminate\Support\Facades\Route;

Route::view('/welcome', 'welcome');
Route::redirect('/here', '/there');
Route::permanentRedirect('/old', '/new');
Route::redirect('/profile', 'https://example.com/@user');
Route::view('/mail@home', 'mail@inbox');
