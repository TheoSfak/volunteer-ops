<?php

use App\Modules\Shifts\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Module Shifts - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για διαχείριση βαρδιών
*/

Route::middleware('auth:sanctum')->group(function () {
    // Βάρδιες ανά αποστολή
    Route::get('/missions/{missionId}/shifts', [ShiftController::class, 'index'])
        ->name('api.shifts.index');
    
    Route::post('/missions/{missionId}/shifts', [ShiftController::class, 'store'])
        ->name('api.shifts.store');

    // Διαχείριση μεμονωμένης βάρδιας
    Route::get('/shifts/{id}', [ShiftController::class, 'show'])
        ->name('api.shifts.show');
    
    Route::put('/shifts/{id}', [ShiftController::class, 'update'])
        ->name('api.shifts.update');
    
    Route::post('/shifts/{id}/lock', [ShiftController::class, 'lock'])
        ->name('api.shifts.lock');

    // Εθελοντές βάρδιας
    Route::get('/shifts/{id}/volunteers', [ShiftController::class, 'volunteers'])
        ->name('api.shifts.volunteers');
});
