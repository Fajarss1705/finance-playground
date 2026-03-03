<?php

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SwitcherController;
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
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Browser test routes — remove after testing
    Route::inertia('test/permission-check', 'test/permission-check')->name('test.permission-check');
    Route::get('test/unnamed-route', fn () => 'This should never be visible');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
