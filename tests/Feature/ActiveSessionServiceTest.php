<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Workspace;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->service = new ActiveSessionService;
});

it('has no active session by default', function () {
    expect($this->service->hasActiveSession())->toBeFalse();
    expect($this->service->getActiveRoleId())->toBeNull();
    expect($this->service->getActiveWorkspaceId())->toBeNull();
    expect($this->service->getActivePermissions())->toBe([]);
});

it('switches to a role and workspace', function () {
    $role = Role::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->service->switchTo($role, $workspace);

    expect($this->service->hasActiveSession())->toBeTrue();
    expect($this->service->getActiveRoleId())->toBe($role->id);
    expect($this->service->getActiveWorkspaceId())->toBe($workspace->id);
});

it('caches role permissions on switch', function () {
    $role = Role::factory()->create();
    $workspace = Workspace::factory()->create();

    $permissions = Permission::factory()->count(3)->create();
    $role->permissions()->attach($permissions);

    $this->service->switchTo($role, $workspace);

    $activePermissions = $this->service->getActivePermissions();

    expect($activePermissions)->toHaveCount(3);
    expect($activePermissions)->toEqual($permissions->pluck('name')->all());
});

it('clears the active session', function () {
    $role = Role::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->service->switchTo($role, $workspace);
    $this->service->clearActiveSession();

    expect($this->service->hasActiveSession())->toBeFalse();
    expect($this->service->getActiveRoleId())->toBeNull();
    expect($this->service->getActiveWorkspaceId())->toBeNull();
    expect($this->service->getActivePermissions())->toBe([]);
});

it('overwrites previous session on re-switch', function () {
    $role1 = Role::factory()->create();
    $role2 = Role::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->service->switchTo($role1, $workspace);
    $this->service->switchTo($role2, $workspace);

    expect($this->service->getActiveRoleId())->toBe($role2->id);
});
