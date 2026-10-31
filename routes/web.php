<?php

use Illuminate\Support\Facades\Route;

// This project is an API only. The web app lives in the separate frontend repo.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
    'docs' => 'See README.md for the list of API endpoints.',
]));
