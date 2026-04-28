<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\FileController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\Workflows\PpWorkflowController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\Workflows\PabdWorkflowController;
use App\Http\Controllers\Workflows\PkWorkflowController;
use App\Http\Controllers\Workflows\PrblWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role.selected', 'check.permission'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('index');

        // Trash & Restore (before resource routes to avoid parameter conflicts)
        Route::get('organizations/trash', [OrganizationController::class, 'trash'])->name('organizations.trash');
        Route::post('organizations/{organization}/restore', [OrganizationController::class, 'restore'])->name('organizations.restore')->withTrashed();

        Route::get('teams/trash', [TeamController::class, 'trash'])->name('teams.trash');
        Route::post('teams/{team}/restore', [TeamController::class, 'restore'])->name('teams.restore')->withTrashed();

        Route::get('workspaces/trash', [WorkspaceController::class, 'trash'])->name('workspaces.trash');
        Route::post('workspaces/{workspace}/restore', [WorkspaceController::class, 'restore'])->name('workspaces.restore')->withTrashed();

        Route::get('roles/trash', [RoleController::class, 'trash'])->name('roles.trash');
        Route::post('roles/{role}/restore', [RoleController::class, 'restore'])->name('roles.restore')->withTrashed();

        Route::get('users/trash', [UserController::class, 'trash'])->name('users.trash');
        Route::post('users/{user}/restore', [UserController::class, 'restore'])->name('users.restore')->withTrashed();

        // Notifications
        Route::get('notifications', [AdminNotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/{notification}', [AdminNotificationController::class, 'show'])->name('notifications.show');
        Route::patch('notifications/mark-all-read', [AdminNotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');

        // Files
        Route::get('files/trash', [FileController::class, 'trash'])->name('files.trash');
        Route::post('files/{file}/restore', [FileController::class, 'restore'])->name('files.restore')->withTrashed();
        Route::get('files', [FileController::class, 'index'])->name('files.index');
        Route::post('files/upload', [FileController::class, 'upload'])->name('files.upload');
        Route::delete('files/{file}', [FileController::class, 'destroy'])->name('files.destroy');

        // PP Workflow (functional prototype)
        Route::prefix('workflows/pp')->name('workflows.pp.')->group(function () {
            Route::get('/', [PpWorkflowController::class, 'index'])->name('index');
            Route::post('/create', [PpWorkflowController::class, 'create'])->name('create');
            Route::get('/{ppWorkflow}', [PpWorkflowController::class, 'show'])->name('show');
            Route::post('/{ppWorkflow}/comment', [PpWorkflowController::class, 'comment'])->name('comment');
            Route::post('/{ppWorkflow}/terminate', [PpWorkflowController::class, 'terminate'])->name('terminate');
            Route::delete('/{ppWorkflow}', [PpWorkflowController::class, 'destroy'])->name('destroy');

            // PP01
            Route::get('/{ppWorkflow}/pp01/{pp01Data}', [PpWorkflowController::class, 'pp01Show'])->name('pp01.show');
            Route::post('/{ppWorkflow}/pp01/{pp01Data}/draft', [PpWorkflowController::class, 'pp01Draft'])->name('pp01.draft');
            Route::post('/{ppWorkflow}/pp01/{pp01Data}/submit', [PpWorkflowController::class, 'pp01Submit'])->name('pp01.submit');

            // PP02
            Route::get('/{ppWorkflow}/pp02/{pp02Data}', [PpWorkflowController::class, 'pp02Show'])->name('pp02.show');
            Route::post('/{ppWorkflow}/pp02/{pp02Data}/draft', [PpWorkflowController::class, 'pp02Draft'])->name('pp02.draft');
            Route::post('/{ppWorkflow}/pp02/{pp02Data}/submit', [PpWorkflowController::class, 'pp02Submit'])->name('pp02.submit');

            // PP03
            Route::get('/{ppWorkflow}/pp03/{pp03Data}', [PpWorkflowController::class, 'pp03Show'])->name('pp03.show');
            Route::post('/{ppWorkflow}/pp03/{pp03Data}/draft', [PpWorkflowController::class, 'pp03Draft'])->name('pp03.draft');
            Route::post('/{ppWorkflow}/pp03/{pp03Data}/submit', [PpWorkflowController::class, 'pp03Submit'])->name('pp03.submit');

            // PP04
            Route::get('/{ppWorkflow}/pp04/{pp04Data}', [PpWorkflowController::class, 'pp04Show'])->name('pp04.show');
            Route::post('/{ppWorkflow}/pp04/{pp04Data}/draft', [PpWorkflowController::class, 'pp04Draft'])->name('pp04.draft');
            Route::post('/{ppWorkflow}/pp04/{pp04Data}/submit', [PpWorkflowController::class, 'pp04Submit'])->name('pp04.submit');

            // PP05
            Route::get('/{ppWorkflow}/pp05', [PpWorkflowController::class, 'pp05Show'])->name('pp05.show');
            Route::post('/{ppWorkflow}/pp05/approve', [PpWorkflowController::class, 'pp05Approve'])->name('pp05.approve');
            Route::post('/{ppWorkflow}/pp05/reject', [PpWorkflowController::class, 'pp05Reject'])->name('pp05.reject');

            // PP06
            Route::get('/{ppWorkflow}/pp06', [PpWorkflowController::class, 'pp06Show'])->name('pp06.show');
            Route::get('/{ppWorkflow}/pp06/export/pdf', [PpWorkflowController::class, 'pp06ExportPdf'])->name('pp06.export.pdf');
            Route::get('/{ppWorkflow}/pp06/export/excel', [PpWorkflowController::class, 'pp06ExportExcel'])->name('pp06.export.excel');
            Route::get('/{ppWorkflow}/pp06/export/zip', [PpWorkflowController::class, 'pp06ExportZip'])->name('pp06.export.zip');

            // PP07
            Route::post('/{ppWorkflow}/pp07/create', [PpWorkflowController::class, 'pp07Create'])->name('pp07.create');
            Route::get('/{ppWorkflow}/pp07/{pp07Data}', [PpWorkflowController::class, 'pp07Show'])->name('pp07.show');
            Route::post('/{ppWorkflow}/pp07/{pp07Data}/draft', [PpWorkflowController::class, 'pp07Draft'])->name('pp07.draft');
            Route::post('/{ppWorkflow}/pp07/{pp07Data}/submit', [PpWorkflowController::class, 'pp07Submit'])->name('pp07.submit');
        });

        // PK Workflow (admin scope — read-only for PK01)
        Route::prefix('workflows/pk')->name('workflows.pk.')->group(function () {
            Route::get('/', [PkWorkflowController::class, 'index'])->name('index');
            Route::get('/{pkWorkflow}', [PkWorkflowController::class, 'show'])->name('show');
            Route::post('/{pkWorkflow}/comment', [PkWorkflowController::class, 'comment'])->name('comment');
            Route::post('/{pkWorkflow}/terminate', [PkWorkflowController::class, 'terminate'])->name('terminate');
            Route::delete('/{pkWorkflow}', [PkWorkflowController::class, 'destroy'])->name('destroy');

            // PK01 (read-only for admin)
            Route::get('/{pkWorkflow}/pk01/{pk01Data}', [PkWorkflowController::class, 'pk01Show'])->name('pk01.show');

            // PK02A (admin scope — show + approve/reject)
            Route::get('/{pkWorkflow}/pk02a', [PkWorkflowController::class, 'pk02aShow'])->name('pk02a.show');
            Route::post('/{pkWorkflow}/pk02a/approve', [PkWorkflowController::class, 'pk02aApprove'])->name('pk02a.approve');
            Route::post('/{pkWorkflow}/pk02a/reject', [PkWorkflowController::class, 'pk02aReject'])->name('pk02a.reject');

            // PK02B (admin scope — show + approve/reject)
            Route::get('/{pkWorkflow}/pk02b', [PkWorkflowController::class, 'pk02bShow'])->name('pk02b.show');
            Route::post('/{pkWorkflow}/pk02b/approve', [PkWorkflowController::class, 'pk02bApprove'])->name('pk02b.approve');
            Route::post('/{pkWorkflow}/pk02b/reject', [PkWorkflowController::class, 'pk02bReject'])->name('pk02b.reject');

            // PK03 (admin scope — show + approve/reject)
            Route::get('/{pkWorkflow}/pk03', [PkWorkflowController::class, 'pk03Show'])->name('pk03.show');
            Route::post('/{pkWorkflow}/pk03/approve', [PkWorkflowController::class, 'pk03Approve'])->name('pk03.approve');
            Route::post('/{pkWorkflow}/pk03/reject', [PkWorkflowController::class, 'pk03Reject'])->name('pk03.reject');

            // PK04 (admin scope — show + export)
            Route::get('/{pkWorkflow}/pk04', [PkWorkflowController::class, 'pk04Show'])->name('pk04.show');
            Route::get('/{pkWorkflow}/pk04/export/pdf', [PkWorkflowController::class, 'pk04ExportPdf'])->name('pk04.export.pdf');
            Route::get('/{pkWorkflow}/pk04/export/excel', [PkWorkflowController::class, 'pk04ExportExcel'])->name('pk04.export.excel');
            Route::get('/{pkWorkflow}/pk04/export/zip', [PkWorkflowController::class, 'pk04ExportZip'])->name('pk04.export.zip');

            // PK05 (admin scope — revision)
            Route::post('/{pkWorkflow}/pk05', [PkWorkflowController::class, 'pk05Create'])->name('pk05.create');
            Route::get('/{pkWorkflow}/pk05/{pk05Data}', [PkWorkflowController::class, 'pk05Show'])->name('pk05.show');
            Route::post('/{pkWorkflow}/pk05/{pk05Data}/draft', [PkWorkflowController::class, 'pk05Draft'])->name('pk05.draft');
            Route::post('/{pkWorkflow}/pk05/{pk05Data}/submit', [PkWorkflowController::class, 'pk05Submit'])->name('pk05.submit');
        });

        // PABD Workflow (admin scope)
        Route::prefix('workflows/pabd')->name('workflows.pabd.')->group(function () {
            Route::get('/', [PabdWorkflowController::class, 'index'])->name('index');
            Route::get('/{pabdWorkflow}', [PabdWorkflowController::class, 'show'])->name('show');
            Route::post('/{pabdWorkflow}/comment', [PabdWorkflowController::class, 'comment'])->name('comment');
            Route::post('/{pabdWorkflow}/admin-reset', [PabdWorkflowController::class, 'adminReset'])->name('admin_reset');
            Route::post('/admin-create', [PabdWorkflowController::class, 'adminCreate'])->name('admin_create');

            // PABD01 (admin scope — read-only)
            Route::get('/{pabdWorkflow}/pabd01/{pabd01Data}', [PabdWorkflowController::class, 'pabd01Show'])->name('pabd01.show');

            // PABD02A (admin scope — read-only)
            Route::get('/{pabdWorkflow}/pabd02a/{pabd02aData}', [PabdWorkflowController::class, 'pabd02aShow'])->name('pabd02a.show');

            // PABD02B (admin scope — show + draft + approve/reject)
            Route::get('/{pabdWorkflow}/pabd02b/{pabd02bData}', [PabdWorkflowController::class, 'pabd02bShow'])->name('pabd02b.show');
            Route::post('/{pabdWorkflow}/pabd02b/{pabd02bData}/draft', [PabdWorkflowController::class, 'pabd02bDraft'])->name('pabd02b.draft');
            Route::post('/{pabdWorkflow}/pabd02b/{pabd02bData}/approve', [PabdWorkflowController::class, 'pabd02bApprove'])->name('pabd02b.approve');
            Route::post('/{pabdWorkflow}/pabd02b/{pabd02bData}/reject', [PabdWorkflowController::class, 'pabd02bReject'])->name('pabd02b.reject');

            // PABD03 (admin scope — show + approve/reject, no data table param)
            Route::get('/{pabdWorkflow}/pabd03', [PabdWorkflowController::class, 'pabd03Show'])->name('pabd03.show');
            Route::post('/{pabdWorkflow}/pabd03/approve', [PabdWorkflowController::class, 'pabd03Approve'])->name('pabd03.approve');
            Route::post('/{pabdWorkflow}/pabd03/reject', [PabdWorkflowController::class, 'pabd03Reject'])->name('pabd03.reject');

            // PABD04 (admin scope — show + draft + submit, Kantor Pusat)
            Route::get('/{pabdWorkflow}/pabd04/{pabd04Data}', [PabdWorkflowController::class, 'pabd04Show'])->name('pabd04.show');
            Route::post('/{pabdWorkflow}/pabd04/{pabd04Data}/draft', [PabdWorkflowController::class, 'pabd04Draft'])->name('pabd04.draft');
            Route::post('/{pabdWorkflow}/pabd04/{pabd04Data}/submit', [PabdWorkflowController::class, 'pabd04Submit'])->name('pabd04.submit');

            // PABD05 (admin scope — show + export, readonly)
            Route::get('/{pabdWorkflow}/pabd05', [PabdWorkflowController::class, 'pabd05Show'])->name('pabd05.show');
            Route::get('/{pabdWorkflow}/pabd05/export/pdf', [PabdWorkflowController::class, 'pabd05ExportPdf'])->name('pabd05.export.pdf');
            Route::get('/{pabdWorkflow}/pabd05/export/excel', [PabdWorkflowController::class, 'pabd05ExportExcel'])->name('pabd05.export.excel');
            Route::get('/{pabdWorkflow}/pabd05/export/zip', [PabdWorkflowController::class, 'pabd05ExportZip'])->name('pabd05.export.zip');
        });

        // PRBL Workflow (admin scope)
        Route::prefix('workflows/prbl')->name('workflows.prbl.')->group(function () {
            Route::get('/', [PrblWorkflowController::class, 'index'])->name('index');
            Route::get('/{prblWorkflow}', [PrblWorkflowController::class, 'show'])->name('show');
            Route::post('/{prblWorkflow}/comment', [PrblWorkflowController::class, 'comment'])->name('comment');
            Route::post('/{prblWorkflow}/admin-reset', [PrblWorkflowController::class, 'adminReset'])->name('admin_reset');
            Route::post('/admin-create', [PrblWorkflowController::class, 'adminCreate'])->name('admin_create');

            // PRBL01 (admin scope — read-only)
            Route::get('/{prblWorkflow}/prbl01/{prbl01Data}', [PrblWorkflowController::class, 'prbl01Show'])->name('prbl01.show');

            // PRBL02A (admin scope — show + approve/reject, no data table param)
            Route::get('/{prblWorkflow}/prbl02a', [PrblWorkflowController::class, 'prbl02aShow'])->name('prbl02a.show');
            Route::post('/{prblWorkflow}/prbl02a/approve', [PrblWorkflowController::class, 'prbl02aApprove'])->name('prbl02a.approve');
            Route::post('/{prblWorkflow}/prbl02a/reject', [PrblWorkflowController::class, 'prbl02aReject'])->name('prbl02a.reject');

            // PRBL02B (admin scope — show + approve/reject, no data table param)
            Route::get('/{prblWorkflow}/prbl02b', [PrblWorkflowController::class, 'prbl02bShow'])->name('prbl02b.show');
            Route::post('/{prblWorkflow}/prbl02b/approve', [PrblWorkflowController::class, 'prbl02bApprove'])->name('prbl02b.approve');
            Route::post('/{prblWorkflow}/prbl02b/reject', [PrblWorkflowController::class, 'prbl02bReject'])->name('prbl02b.reject');

            // PRBL03 (admin scope — read-only)
            Route::get('/{prblWorkflow}/prbl03/{prbl03Data}', [PrblWorkflowController::class, 'prbl03Show'])->name('prbl03.show');

            // PRBL04 (admin scope — show + approve/reject, no data table param)
            Route::get('/{prblWorkflow}/prbl04', [PrblWorkflowController::class, 'prbl04Show'])->name('prbl04.show');
            Route::post('/{prblWorkflow}/prbl04/approve', [PrblWorkflowController::class, 'prbl04Approve'])->name('prbl04.approve');
            Route::post('/{prblWorkflow}/prbl04/reject', [PrblWorkflowController::class, 'prbl04Reject'])->name('prbl04.reject');

            // PRBL05 (admin scope — read-only show + exports)
            Route::get('/{prblWorkflow}/prbl05', [PrblWorkflowController::class, 'prbl05Show'])->name('prbl05.show');
            Route::get('/{prblWorkflow}/prbl05/export/pdf', [PrblWorkflowController::class, 'prbl05ExportPdf'])->name('prbl05.export.pdf');
            Route::get('/{prblWorkflow}/prbl05/export/excel', [PrblWorkflowController::class, 'prbl05ExportExcel'])->name('prbl05.export.excel');
            Route::get('/{prblWorkflow}/prbl05/export/zip', [PrblWorkflowController::class, 'prbl05ExportZip'])->name('prbl05.export.zip');
        });

        // Resource routes
        Route::resource('organizations', OrganizationController::class)->except(['show']);
        Route::resource('teams', TeamController::class)->except(['show']);
        Route::resource('workspaces', WorkspaceController::class)->except(['show']);
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('users', UserController::class)->except(['show']);
    });
