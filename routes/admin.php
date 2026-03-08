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
            Route::post('/{ppWorkflow}/pp04/{pp04Data}/submit', [PpWorkflowController::class, 'pp04Submit'])->name('pp04.submit');

            // PP05
            Route::get('/{ppWorkflow}/pp05', [PpWorkflowController::class, 'pp05Show'])->name('pp05.show');
            Route::post('/{ppWorkflow}/pp05/approve', [PpWorkflowController::class, 'pp05Approve'])->name('pp05.approve');
            Route::post('/{ppWorkflow}/pp05/reject', [PpWorkflowController::class, 'pp05Reject'])->name('pp05.reject');

            // PP06
            Route::get('/{ppWorkflow}/pp06', [PpWorkflowController::class, 'pp06Show'])->name('pp06.show');

            // PP07
            Route::post('/{ppWorkflow}/pp07/create', [PpWorkflowController::class, 'pp07Create'])->name('pp07.create');
            Route::get('/{ppWorkflow}/pp07/{pp07Data}', [PpWorkflowController::class, 'pp07Show'])->name('pp07.show');
            Route::post('/{ppWorkflow}/pp07/{pp07Data}/draft', [PpWorkflowController::class, 'pp07Draft'])->name('pp07.draft');
            Route::post('/{ppWorkflow}/pp07/{pp07Data}/submit', [PpWorkflowController::class, 'pp07Submit'])->name('pp07.submit');
        });

        // Workflow prototypes — PABD (admin scope)
        Route::prefix('workflows/pabd')->name('workflows.pabd.')->group(function () {
            Route::inertia('/', 'admin/workflows/pabd/index')->name('index');
            Route::inertia('/pabd-uuid-001', 'admin/workflows/pabd/show')->name('show');
            Route::inertia('/pabd-uuid-001/pabd02b', 'admin/workflows/pabd/pabd02b')->name('pabd02b.show');
            Route::inertia('/pabd-uuid-001/pabd03', 'admin/workflows/pabd/pabd03')->name('pabd03.show');
            Route::inertia('/pabd-uuid-001/pabd05', 'admin/workflows/pabd/pabd05')->name('pabd05.show');
        });

        // Workflow prototypes — PRBL (admin scope)
        Route::prefix('workflows/prbl')->name('workflows.prbl.')->group(function () {
            Route::inertia('/', 'admin/workflows/prbl/index')->name('index');
            Route::inertia('/prbl-uuid-001', 'admin/workflows/prbl/show')->name('show');
            Route::inertia('/prbl-uuid-001/prbl02a', 'admin/workflows/prbl/prbl02a')->name('prbl02a.show');
            Route::inertia('/prbl-uuid-001/prbl02b', 'admin/workflows/prbl/prbl02b')->name('prbl02b.show');
            Route::inertia('/prbl-uuid-001/prbl04', 'admin/workflows/prbl/prbl04')->name('prbl04.show');
            Route::inertia('/prbl-uuid-001/prbl05', 'admin/workflows/prbl/prbl05')->name('prbl05.show');
        });

        // Resource routes
        Route::resource('organizations', OrganizationController::class)->except(['show']);
        Route::resource('teams', TeamController::class)->except(['show']);
        Route::resource('workspaces', WorkspaceController::class)->except(['show']);
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('users', UserController::class)->except(['show']);
    });
