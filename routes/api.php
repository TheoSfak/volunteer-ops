<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Εδώ καταχωρούνται τα API routes της εφαρμογής.
| Τα routes φορτώνονται από τον ModuleServiceProvider αυτόματα.
|
*/

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'κατάσταση' => 'ok',
        'έκδοση' => '1.0.0',
        'χρόνος' => now()->toIso8601String(),
    ]);
});

// API version info
Route::get('/', function () {
    return response()->json([
        'εφαρμογή' => 'Εθελοντικές Αποστολές API',
        'έκδοση' => '1.0.0',
        'τεκμηρίωση' => '/api/docs',
        'μήνυμα' => 'Καλωσορίσατε στο API διαχείρισης εθελοντικών αποστολών.',
    ]);
});
