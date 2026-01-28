<?php

use App\Modules\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes Module Auth - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για αυθεντικοποίηση χρηστών με Laravel Sanctum
*/

Route::prefix('auth')->group(function () {
    // Δημόσιες διαδρομές
    Route::post('/register', [AuthController::class, 'register'])
        ->name('auth.register');
    
    Route::post('/login', [AuthController::class, 'login'])
        ->name('auth.login');

    // Προστατευμένες διαδρομές
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');
        
        Route::get('/me', [AuthController::class, 'me'])
            ->name('auth.me');
        
        Route::put('/me', [AuthController::class, 'updateProfile'])
            ->name('auth.update-profile');
        
        Route::post('/change-password', [AuthController::class, 'changePassword'])
            ->name('auth.change-password');
        
        Route::post('/refresh-token', [AuthController::class, 'refreshToken'])
            ->name('auth.refresh-token');
    });
});
