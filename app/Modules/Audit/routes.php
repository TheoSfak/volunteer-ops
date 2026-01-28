<?php

/*
|--------------------------------------------------------------------------
| Routes Module Audit - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για προβολή audit logs (μόνο για διαχειριστές)
*/

use App\Modules\Audit\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/audit-logs', [AuditController::class, 'index'])
        ->name('api.audit.index');
    
    Route::get('/audit-logs/entity/{type}/{id}', [AuditController::class, 'entityHistory'])
        ->name('api.audit.entity');
});
