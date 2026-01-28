<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\MissionController;
use App\Http\Controllers\Web\ShiftController;
use App\Http\Controllers\Web\VolunteerController;
use App\Http\Controllers\Web\DepartmentController;
use App\Http\Controllers\Web\ParticipationController;
use App\Http\Controllers\Web\DocumentController;
use App\Http\Controllers\Web\AuditController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\GamificationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Routes για το frontend της εφαρμογής διαχείρισης εθελοντών.
|
*/

// Ανακατεύθυνση αρχικής σελίδας
Route::get('/', function () {
    return redirect()->route('login');
});

// Routes για επισκέπτες (Guest)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
});

// Routes για πιστοποιημένους χρήστες
Route::middleware('auth')->group(function () {
    // Αποσύνδεση
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Προφίλ χρήστη
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::put('/profile/skills', [ProfileController::class, 'updateSkills'])->name('profile.skills');
    
    // Αποστολές
    Route::resource('missions', MissionController::class);
    Route::post('/missions/{mission}/publish', [MissionController::class, 'publish'])->name('missions.publish');
    Route::post('/missions/{mission}/activate', [MissionController::class, 'activate'])->name('missions.activate');
    Route::post('/missions/{mission}/complete', [MissionController::class, 'complete'])->name('missions.complete');
    Route::post('/missions/{mission}/cancel', [MissionController::class, 'cancel'])->name('missions.cancel');
    Route::post('/missions/{mission}/apply', [ParticipationController::class, 'applyToMission'])->name('missions.apply');
    
    // Βάρδιες
    Route::resource('shifts', ShiftController::class);
    
    // Εθελοντές
    Route::resource('volunteers', VolunteerController::class);
    
    // Τμήματα
    Route::resource('departments', DepartmentController::class)->except(['show']);
    
    // Συμμετοχές
    Route::get('/participations', [ParticipationController::class, 'index'])->name('participations.index');
    Route::post('/shifts/{shift}/apply', [ParticipationController::class, 'apply'])->name('participations.apply');
    Route::post('/participations/{participation}/cancel', [ParticipationController::class, 'cancel'])->name('participations.cancel');
    Route::post('/participations/{participation}/approve', [ParticipationController::class, 'approve'])->name('participations.approve');
    Route::post('/participations/{participation}/reject', [ParticipationController::class, 'reject'])->name('participations.reject');
    
    // Έγγραφα
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    
    // Αρχείο καταγραφής
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    
    // Αναφορές
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    
    // Ρυθμίσεις (μόνο για SYSTEM_ADMIN)
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/email', [SettingsController::class, 'updateEmail'])->name('settings.email.update');
    Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])->name('settings.email.test');
    Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
    Route::post('/settings/mission-types', [SettingsController::class, 'addMissionType'])->name('settings.mission-types.add');
    Route::delete('/settings/mission-types', [SettingsController::class, 'removeMissionType'])->name('settings.mission-types.remove');
    
    // Διαχείριση Τμημάτων (μέσα στα Settings)
    Route::post('/settings/departments', [SettingsController::class, 'addDepartment'])->name('settings.departments.add');
    Route::put('/settings/departments', [SettingsController::class, 'updateDepartment'])->name('settings.departments.update');
    Route::delete('/settings/departments', [SettingsController::class, 'removeDepartment'])->name('settings.departments.remove');
    
    // Email Templates (μέσα στα Settings)
    Route::put('/settings/email-template/{template}', [SettingsController::class, 'updateEmailTemplate'])->name('settings.email-template.update');
    Route::put('/settings/email-logo', [SettingsController::class, 'updateEmailLogo'])->name('settings.email-logo.update');
    
    // Ενημερώσεις Συστήματος
    Route::get('/settings/updates', [SettingsController::class, 'updates'])->name('settings.updates');
    Route::post('/settings/check-updates', [SettingsController::class, 'checkUpdates'])->name('settings.check-updates');
    
    // Gamification - Κατάταξη & Επιτεύγματα
    Route::get('/leaderboard', [GamificationController::class, 'leaderboard'])->name('gamification.leaderboard');
    Route::get('/achievements', [GamificationController::class, 'achievements'])->name('gamification.achievements');
    Route::get('/points-history', [GamificationController::class, 'pointsHistory'])->name('gamification.points-history');
    Route::post('/gamification/award-points', [GamificationController::class, 'awardPoints'])->name('gamification.award-points');
});
