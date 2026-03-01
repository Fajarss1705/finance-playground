<?php

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
});

Route::middleware(['auth', 'verified', 'role.selected', 'check.permission'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
