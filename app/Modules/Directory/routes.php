<?php

use App\Modules\Directory\Controllers\DepartmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Module Directory - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για διαχείριση τμημάτων και οργανωτικής δομής
*/

Route::middleware('auth:sanctum')->group(function () {
    // Τμήματα
    Route::apiResource('departments', DepartmentController::class)
        ->names([
            'index' => 'api.departments.index',
            'store' => 'api.departments.store',
            'show' => 'api.departments.show',
            'update' => 'api.departments.update',
            'destroy' => 'api.departments.destroy',
        ]);

    // Χρήστες ανά τμήμα
    Route::get('/departments/{id}/users', [DepartmentController::class, 'users'])
        ->name('api.departments.users');
});
