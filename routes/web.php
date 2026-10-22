<?php

use Illuminate\Support\Facades\Route;

// The Vue single-page app handles every non-API route.
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|up|storage).*$')->name('spa');
