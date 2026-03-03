<?php

use App\Models\File;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupAdminFileTestUser(string ...$permissionNames): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    foreach ($permissionNames as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace];
}

function activateAdminFileSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Index ---

it('displays all files in workspace', function () {
    [$user, $role, $workspace] = setupAdminFileTestUser('admin.files.index');
    activateAdminFileSession($this, $user, $role, $workspace);

    File::factory()->count(3)->create([
        'workspace_id' => $workspace->id,
    ]);

    // File in different workspace should not appear
    File::factory()->create();

    $this->get(route('admin.files.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/files/index')
            ->has('files.data', 3)
        );
});

it('returns 403 without permission', function () {
    [$user, $role, $workspace] = setupAdminFileTestUser('dashboard');
    activateAdminFileSession($this, $user, $role, $workspace);

    $this->get(route('admin.files.index'))
        ->assertForbidden();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('admin.files.index'))
        ->assertRedirect(route('login'));
});
