<?php

use App\Modules\Reports\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('reports')->group(function () {
    // Dashboard γενικά στατιστικά
    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    
    // Αναφορές αποστολών
    Route::get('/missions', [ReportController::class, 'missions']);
    
    // Αναφορές βαρδιών
    Route::get('/shifts', [ReportController::class, 'shifts']);
    
    // Αναφορές εθελοντών
    Route::get('/volunteers', [ReportController::class, 'volunteers']);
    
    // Αναφορές συμμετοχών
    Route::get('/participations', [ReportController::class, 'participations']);
    
    // Αναφορά ανά τμήμα
    Route::get('/departments', [ReportController::class, 'departments']);
    
    // Εξαγωγή αναφοράς
    Route::get('/export/{type}', [ReportController::class, 'export']);
});
