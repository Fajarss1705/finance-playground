<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\FileController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
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
