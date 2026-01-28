<?php

/*
|--------------------------------------------------------------------------
| Routes Module Notifications - Ελληνικά
|--------------------------------------------------------------------------
| Διαδρομές για διαχείριση ειδοποιήσεων
*/

use App\Modules\Notifications\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Λίστα ειδοποιήσεων
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('api.notifications.index');
    
    // Αριθμός μη αναγνωσμένων
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('api.notifications.unread-count');
    
    // Σήμανση ως αναγνωσμένη
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
        ->name('api.notifications.read');
    
    // Σήμανση όλων ως αναγνωσμένες
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('api.notifications.read-all');
});
