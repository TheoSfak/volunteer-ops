<?php

use App\Modules\Participation\Controllers\ParticipationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Module Participation - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για διαχείριση συμμετοχών/αιτήσεων
*/

Route::middleware('auth:sanctum')->group(function () {
    // Αίτηση συμμετοχής σε βάρδια
    Route::post('/shifts/{shiftId}/apply', [ParticipationController::class, 'apply'])
        ->name('api.participation.apply');

    // Διαχείριση αιτήσεων
    Route::post('/participations/{id}/approve', [ParticipationController::class, 'approve'])
        ->name('api.participation.approve');
    
    Route::post('/participations/{id}/reject', [ParticipationController::class, 'reject'])
        ->name('api.participation.reject');
    
    Route::post('/participations/{id}/cancel', [ParticipationController::class, 'cancel'])
        ->name('api.participation.cancel');

    // Οι συμμετοχές μου
    Route::get('/me/participations', [ParticipationController::class, 'myParticipations'])
        ->name('api.participation.my');

    // Εκκρεμείς αιτήσεις τμήματος
    Route::get('/participations/pending', [ParticipationController::class, 'pending'])
        ->name('api.participation.pending');

    // Λεπτομέρειες αίτησης
    Route::get('/participations/{id}', [ParticipationController::class, 'show'])
        ->name('api.participation.show');
});
