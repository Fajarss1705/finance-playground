<?php

use App\Models\Permission;

it('creates permissions from auth routes', function () {
    $this->artisan('permissions:sync')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'personal.index')->exists())->toBeTrue();
    expect(Permission::query()->count())->toBeGreaterThan(0);
});

it('does not duplicate existing permissions', function () {
    Permission::query()->create(['name' => 'personal.index']);

    $this->artisan('permissions:sync')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'personal.index')->count())->toBe(1);
});

it('detects stale permissions without removing by default', function () {
    Permission::query()->create(['name' => 'old.stale.route']);

    $this->artisan('permissions:sync')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'old.stale.route')->exists())->toBeTrue();
});

it('removes stale permissions with --remove-stale', function () {
    Permission::query()->create(['name' => 'old.stale.route']);

    $this->artisan('permissions:sync', ['--remove-stale' => true])
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'old.stale.route')->exists())->toBeFalse();
});

it('does not make changes with --dry-run', function () {
    $this->artisan('permissions:sync', ['--dry-run' => true])
        ->assertSuccessful();

    expect(Permission::query()->count())->toBe(0);
});

it('does not remove stale with --dry-run and --remove-stale', function () {
    Permission::query()->create(['name' => 'old.stale.route']);

    $this->artisan('permissions:sync', ['--dry-run' => true, '--remove-stale' => true])
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'old.stale.route')->exists())->toBeTrue();
});

it('reports already in sync when nothing to do', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    $this->artisan('permissions:sync')
        ->assertSuccessful()
        ->expectsOutputToContain('unchanged');
});
