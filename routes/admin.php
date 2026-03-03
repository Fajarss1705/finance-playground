<?php

use App\Http\Controllers\Admin\AdminDashboardController;
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

        // Resource routes
        Route::resource('organizations', OrganizationController::class)->except(['show']);
        Route::resource('teams', TeamController::class)->except(['show']);
        Route::resource('workspaces', WorkspaceController::class)->except(['show']);
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('users', UserController::class)->except(['show']);
    });
