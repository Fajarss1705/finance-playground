<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role.selected', 'check.permission'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('index');
        Route::resource('organizations', OrganizationController::class)->except(['show']);
        Route::resource('teams', TeamController::class)->except(['show']);
        Route::resource('workspaces', WorkspaceController::class)->except(['show']);
    });
