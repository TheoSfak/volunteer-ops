<?php

use App\Modules\Documents\Controllers\FileController;
use App\Modules\Documents\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Module Documents - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για διαχείριση αρχείων και εγγράφων
*/

Route::middleware('auth:sanctum')->group(function () {
    // Μεταφόρτωση αρχείων
    Route::post('/files/upload', [FileController::class, 'upload'])
        ->name('api.files.upload');

    // Έγγραφα
    Route::get('/documents', [DocumentController::class, 'index'])
        ->name('api.documents.index');
    
    Route::post('/documents', [DocumentController::class, 'store'])
        ->name('api.documents.store');
    
    Route::get('/documents/{id}', [DocumentController::class, 'show'])
        ->name('api.documents.show');
    
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy'])
        ->name('api.documents.destroy');

    // Λήψη αρχείου
    Route::get('/files/{id}/download', [FileController::class, 'download'])
        ->name('api.files.download');
});
