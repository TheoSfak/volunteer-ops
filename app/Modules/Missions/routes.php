<?php

use App\Modules\Missions\Controllers\MissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Module Missions - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για διαχείριση αποστολών
*/

Route::middleware('auth:sanctum')->group(function () {
    // CRUD Αποστολών
    Route::get('/missions', [MissionController::class, 'index'])
        ->name('api.missions.index');
    
    Route::post('/missions', [MissionController::class, 'store'])
        ->name('api.missions.store');
    
    Route::get('/missions/{id}', [MissionController::class, 'show'])
        ->name('api.missions.show');
    
    Route::put('/missions/{id}', [MissionController::class, 'update'])
        ->name('api.missions.update');

    // Ενέργειες αποστολών
    Route::post('/missions/{id}/publish', [MissionController::class, 'publish'])
        ->name('api.missions.publish');
    
    Route::post('/missions/{id}/close', [MissionController::class, 'close'])
        ->name('api.missions.close');
    
    Route::post('/missions/{id}/cancel', [MissionController::class, 'cancel'])
        ->name('api.missions.cancel');

    // Στατιστικά αποστολής
    Route::get('/missions/{id}/stats', [MissionController::class, 'stats'])
        ->name('api.missions.stats');
});
