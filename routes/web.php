<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SwitcherController;
use App\Http\Controllers\Team\TeamDashboardController;
use App\Http\Controllers\VerifyController;
use App\Http\Controllers\Workflows\PkWorkflowController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('verify/{code}', VerifyController::class)->name('verify');
    Route::get('role-selector', [SwitcherController::class, 'index'])->name('role-selector.index');
    Route::post('role-selector', [SwitcherController::class, 'store'])->name('role-selector.store');
    Route::inertia('no-access', 'no-access')->name('no-access');

    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::patch('{notification}/read', [NotificationController::class, 'markAsRead'])->name('mark-read');
        Route::get('{notification}/go', [NotificationController::class, 'go'])->name('go');
        Route::get('{notification}/show', [NotificationController::class, 'show'])->name('show');
    });

    Route::get('notifications/{notification}/redirect', [NotificationController::class, 'redirect'])
        ->middleware('signed')
        ->name('notifications.redirect');
});

Route::middleware(['auth', 'verified', 'role.selected', 'check.permission'])->group(function () {
    // Personal scope
    Route::prefix('personal')->name('personal.')->group(function () {
        Route::inertia('/', 'personal/index')->name('index');
        Route::inertia('verify', 'personal/verify')->name('verify');
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

        // PK Workflow (team scope)
        Route::prefix('workflows/pk')->name('workflows.pk.')->group(function () {
            Route::get('/', [PkWorkflowController::class, 'index'])->name('index');
            Route::post('/create/{ppWorkflow}', [PkWorkflowController::class, 'create'])->name('create');
            Route::get('/{pkWorkflow}', [PkWorkflowController::class, 'show'])->name('show');
            Route::post('/{pkWorkflow}/comment', [PkWorkflowController::class, 'comment'])->name('comment');
            Route::post('/{pkWorkflow}/terminate', [PkWorkflowController::class, 'terminate'])->name('terminate');

            // PK01
            Route::get('/{pkWorkflow}/pk01/{pk01Data}', [PkWorkflowController::class, 'pk01Show'])->name('pk01.show');
            Route::post('/{pkWorkflow}/pk01/{pk01Data}/draft', [PkWorkflowController::class, 'pk01Draft'])->name('pk01.draft');
            Route::post('/{pkWorkflow}/pk01/{pk01Data}/submit', [PkWorkflowController::class, 'pk01Submit'])->name('pk01.submit');

            // PK02A (team scope — read-only)
            Route::get('/{pkWorkflow}/pk02a', [PkWorkflowController::class, 'pk02aShow'])->name('pk02a.show');

            // PK02B (team scope — read-only)
            Route::get('/{pkWorkflow}/pk02b', [PkWorkflowController::class, 'pk02bShow'])->name('pk02b.show');
        });

        // Workflow prototypes — PABD (team scope)
        Route::prefix('workflows/pabd')->name('workflows.pabd.')->group(function () {
            Route::inertia('/', 'team/workflows/pabd/index')->name('index');
            Route::inertia('/pabd-uuid-001', 'team/workflows/pabd/show')->name('show');
            Route::inertia('/pabd-uuid-001/pabd01', 'team/workflows/pabd/pabd01')->name('pabd01.show');
            Route::inertia('/pabd-uuid-001/pabd02a', 'team/workflows/pabd/pabd02a')->name('pabd02a.show');
            Route::inertia('/pabd-uuid-001/pabd04', 'team/workflows/pabd/pabd04')->name('pabd04.show');
            Route::inertia('/pabd-uuid-001/pabd05', 'team/workflows/pabd/pabd05')->name('pabd05.show');
        });

        // Workflow prototypes — PRBL (team scope)
        Route::prefix('workflows/prbl')->name('workflows.prbl.')->group(function () {
            Route::inertia('/', 'team/workflows/prbl/index')->name('index');
            Route::inertia('/prbl-uuid-001', 'team/workflows/prbl/show')->name('show');
            Route::inertia('/prbl-uuid-001/prbl01', 'team/workflows/prbl/prbl01')->name('prbl01.show');
            Route::inertia('/prbl-uuid-001/prbl03', 'team/workflows/prbl/prbl03')->name('prbl03.show');
            Route::inertia('/prbl-uuid-001/prbl05', 'team/workflows/prbl/prbl05')->name('prbl05.show');
        });
    });

    // Browser test routes — remove after testing
    Route::inertia('test/permission-check', 'test/permission-check')->name('test.permission-check');
    Route::get('test/unnamed-route', fn () => 'This should never be visible');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
