<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SwitcherController;
use App\Http\Controllers\Team\TeamDashboardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('role-selector', [SwitcherController::class, 'index'])->name('role-selector.index');
    Route::post('role-selector', [SwitcherController::class, 'store'])->name('role-selector.store');
    Route::inertia('no-access', 'no-access')->name('no-access');

    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::patch('{notification}/read', [NotificationController::class, 'markAsRead'])->name('mark-read');
        Route::get('{notification}/go', [NotificationController::class, 'go'])->name('go');
    });

    Route::get('notifications/{notification}/redirect', [NotificationController::class, 'redirect'])
        ->middleware('signed')
        ->name('notifications.redirect');
});

Route::middleware(['auth', 'verified', 'role.selected', 'check.permission'])->group(function () {
    // Personal scope
    Route::prefix('personal')->name('personal.')->group(function () {
        Route::inertia('/', 'personal/index')->name('index');
        Route::get('files', [FileController::class, 'personal'])->name('files');
        Route::get('notifications', [NotificationController::class, 'personal'])->name('notifications');
        Route::patch('notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    });

    // Shared file routes
    Route::get('files/{file}/download', [FileController::class, 'download'])->name('files.download');

    // Team scope
    Route::prefix('team')->name('team.')->group(function () {
        Route::get('/', TeamDashboardController::class)->name('index');
        Route::get('files', [FileController::class, 'team'])->name('files.index');
    });

    // Browser test routes — remove after testing
    Route::inertia('test/permission-check', 'test/permission-check')->name('test.permission-check');
    Route::get('test/unnamed-route', fn () => 'This should never be visible');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
