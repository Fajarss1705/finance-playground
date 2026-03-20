<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SwitcherController;
use App\Http\Controllers\Team\TeamDashboardController;
use App\Http\Controllers\VerifyController;
use App\Http\Controllers\Workflows\PabdWorkflowController;
use App\Http\Controllers\Workflows\PkWorkflowController;
use App\Http\Controllers\Workflows\PrblWorkflowController;
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

            // PK03 (team scope — read-only)
            Route::get('/{pkWorkflow}/pk03', [PkWorkflowController::class, 'pk03Show'])->name('pk03.show');

            // PK04 (team scope — show + export)
            Route::get('/{pkWorkflow}/pk04', [PkWorkflowController::class, 'pk04Show'])->name('pk04.show');
            Route::get('/{pkWorkflow}/pk04/export/pdf', [PkWorkflowController::class, 'pk04ExportPdf'])->name('pk04.export.pdf');
            Route::get('/{pkWorkflow}/pk04/export/excel', [PkWorkflowController::class, 'pk04ExportExcel'])->name('pk04.export.excel');
            Route::get('/{pkWorkflow}/pk04/export/zip', [PkWorkflowController::class, 'pk04ExportZip'])->name('pk04.export.zip');
        });

        // PABD Workflow (team scope)
        Route::prefix('workflows/pabd')->name('workflows.pabd.')->group(function () {
            Route::get('/', [PabdWorkflowController::class, 'index'])->name('index');
            Route::get('/{pabdWorkflow}', [PabdWorkflowController::class, 'show'])->name('show');
            Route::post('/{pabdWorkflow}/comment', [PabdWorkflowController::class, 'comment'])->name('comment');

            // PABD01
            Route::get('/{pabdWorkflow}/pabd01/{pabd01Data}', [PabdWorkflowController::class, 'pabd01Show'])->name('pabd01.show');
            Route::post('/{pabdWorkflow}/pabd01/{pabd01Data}/draft', [PabdWorkflowController::class, 'pabd01Draft'])->name('pabd01.draft');
            Route::post('/{pabdWorkflow}/pabd01/{pabd01Data}/submit', [PabdWorkflowController::class, 'pabd01Submit'])->name('pabd01.submit');

            // PABD02A
            Route::get('/{pabdWorkflow}/pabd02a/{pabd02aData}', [PabdWorkflowController::class, 'pabd02aShow'])->name('pabd02a.show');
            Route::post('/{pabdWorkflow}/pabd02a/{pabd02aData}/draft', [PabdWorkflowController::class, 'pabd02aDraft'])->name('pabd02a.draft');
            Route::post('/{pabdWorkflow}/pabd02a/{pabd02aData}/submit', [PabdWorkflowController::class, 'pabd02aSubmit'])->name('pabd02a.submit');

            // PABD02B (team scope — read-only)
            Route::get('/{pabdWorkflow}/pabd02b/{pabd02bData}', [PabdWorkflowController::class, 'pabd02bShow'])->name('pabd02b.show');

            // PABD03 (team scope — read-only, no data table param)
            Route::get('/{pabdWorkflow}/pabd03', [PabdWorkflowController::class, 'pabd03Show'])->name('pabd03.show');

            // PABD04 (team scope — read-only)
            Route::get('/{pabdWorkflow}/pabd04/{pabd04Data}', [PabdWorkflowController::class, 'pabd04Show'])->name('pabd04.show');

            // PABD05 (team scope — show + export, readonly)
            Route::get('/{pabdWorkflow}/pabd05', [PabdWorkflowController::class, 'pabd05Show'])->name('pabd05.show');
            Route::get('/{pabdWorkflow}/pabd05/export/pdf', [PabdWorkflowController::class, 'pabd05ExportPdf'])->name('pabd05.export.pdf');
            Route::get('/{pabdWorkflow}/pabd05/export/excel', [PabdWorkflowController::class, 'pabd05ExportExcel'])->name('pabd05.export.excel');
            Route::get('/{pabdWorkflow}/pabd05/export/zip', [PabdWorkflowController::class, 'pabd05ExportZip'])->name('pabd05.export.zip');
        });

        // PRBL Workflow (team scope)
        Route::prefix('workflows/prbl')->name('workflows.prbl.')->group(function () {
            Route::get('/', [PrblWorkflowController::class, 'index'])->name('index');
            Route::get('/{prblWorkflow}', [PrblWorkflowController::class, 'show'])->name('show');

            // PRBL01
            Route::get('/{prblWorkflow}/prbl01/{prbl01Data}', [PrblWorkflowController::class, 'prbl01Show'])->name('prbl01.show');
            Route::post('/{prblWorkflow}/prbl01/{prbl01Data}/draft', [PrblWorkflowController::class, 'prbl01Draft'])->name('prbl01.draft');
            Route::post('/{prblWorkflow}/prbl01/{prbl01Data}/submit', [PrblWorkflowController::class, 'prbl01Submit'])->name('prbl01.submit');
            Route::post('/{prblWorkflow}/prbl01/{prbl01Data}/foto-upload', [PrblWorkflowController::class, 'prbl01FotoUpload'])->name('prbl01.foto.upload');
            Route::post('/{prblWorkflow}/prbl01/{prbl01Data}/foto-delete', [PrblWorkflowController::class, 'prbl01FotoDelete'])->name('prbl01.foto.delete');
            Route::post('/{prblWorkflow}/prbl01/{prbl01Data}/nota-upload', [PrblWorkflowController::class, 'prbl01NotaUpload'])->name('prbl01.nota.upload');
            Route::post('/{prblWorkflow}/prbl01/{prbl01Data}/nota-delete', [PrblWorkflowController::class, 'prbl01NotaDelete'])->name('prbl01.nota.delete');

            // PRBL03 (team scope — show + draft + submit)
            Route::get('/{prblWorkflow}/prbl03/{prbl03Data}', [PrblWorkflowController::class, 'prbl03Show'])->name('prbl03.show');
            Route::post('/{prblWorkflow}/prbl03/{prbl03Data}/draft', [PrblWorkflowController::class, 'prbl03Draft'])->name('prbl03.draft');
            Route::post('/{prblWorkflow}/prbl03/{prbl03Data}/submit', [PrblWorkflowController::class, 'prbl03Submit'])->name('prbl03.submit');

            // PRBL04 (team scope — read-only)
            Route::get('/{prblWorkflow}/prbl04', [PrblWorkflowController::class, 'prbl04Show'])->name('prbl04.show');

            // PRBL05 (team scope — read-only show + exports)
            Route::get('/{prblWorkflow}/prbl05', [PrblWorkflowController::class, 'prbl05Show'])->name('prbl05.show');
            Route::get('/{prblWorkflow}/prbl05/export/pdf', [PrblWorkflowController::class, 'prbl05ExportPdf'])->name('prbl05.export.pdf');
            Route::get('/{prblWorkflow}/prbl05/export/excel', [PrblWorkflowController::class, 'prbl05ExportExcel'])->name('prbl05.export.excel');
            Route::get('/{prblWorkflow}/prbl05/export/zip', [PrblWorkflowController::class, 'prbl05ExportZip'])->name('prbl05.export.zip');

            // Comment
            Route::post('/{prblWorkflow}/comment', [PrblWorkflowController::class, 'comment'])->name('comment');
        });
    });

    // Browser test routes — remove after testing
    Route::inertia('test/permission-check', 'test/permission-check')->name('test.permission-check');
    Route::get('test/unnamed-route', fn () => 'This should never be visible');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
