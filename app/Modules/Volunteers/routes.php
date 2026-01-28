<?php

use App\Modules\Volunteers\Controllers\VolunteerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Module Volunteers - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για διαχείριση εθελοντών
*/

Route::middleware('auth:sanctum')->group(function () {
    // Λίστα εθελοντών
    Route::get('/volunteers', [VolunteerController::class, 'index'])
        ->name('api.volunteers.index');
    
    // Προβολή εθελοντή
    Route::get('/volunteers/{id}', [VolunteerController::class, 'show'])
        ->name('api.volunteers.show');
    
    // Ενημέρωση εθελοντή
    Route::put('/volunteers/{id}', [VolunteerController::class, 'update'])
        ->name('api.volunteers.update');

    // Αναζήτηση εθελοντών
    Route::get('/volunteers/search', [VolunteerController::class, 'search'])
        ->name('api.volunteers.search');

    // Στατιστικά εθελοντή
    Route::get('/volunteers/{id}/stats', [VolunteerController::class, 'stats'])
        ->name('api.volunteers.stats');

    // Ιστορικό συμμετοχών εθελοντή
    Route::get('/volunteers/{id}/history', [VolunteerController::class, 'history'])
        ->name('api.volunteers.history');
});
