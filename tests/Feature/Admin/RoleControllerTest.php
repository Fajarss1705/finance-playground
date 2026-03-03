<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupRoleTestUser(string ...$permissionNames): array
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

function activateRoleSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Index ---

it('displays roles index page', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.index');
    activateRoleSession($this, $user, $role, $workspace);

    $this->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/index')
            ->has('roles.data')
            ->has('teams')
            ->has('filters')
        );
});

it('filters roles by team', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.index');
    activateRoleSession($this, $user, $role, $workspace);

    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    Role::factory()->count(2)->create(['team_id' => $team1->id]);
    Role::factory()->count(3)->create(['team_id' => $team2->id]);

    $this->get(route('admin.roles.index', ['team_id' => $team1->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('roles.data', 2)
        );
});

it('returns 403 without permission', function () {
    [$user, $role, $workspace] = setupRoleTestUser('dashboard');
    activateRoleSession($this, $user, $role, $workspace);

    $this->get(route('admin.roles.index'))
        ->assertForbidden();
});

// --- Create ---

it('displays create form with teams and permissions lists', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.create');
    activateRoleSession($this, $user, $role, $workspace);

    $this->get(route('admin.roles.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/create')
            ->has('teams')
            ->has('permissions')
        );
});

// --- Store ---

it('stores a new role', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.store');
    activateRoleSession($this, $user, $role, $workspace);

    $team = Team::factory()->create();

    $this->post(route('admin.roles.store'), [
        'team_id' => $team->id,
        'name' => 'Role Baru',
        'description' => 'Deskripsi role',
    ])->assertRedirect(route('admin.roles.index'));

    $this->assertDatabaseHas('roles', [
        'team_id' => $team->id,
        'name' => 'Role Baru',
        'description' => 'Deskripsi role',
    ]);
});

it('stores a role with permission assignment', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.store');
    activateRoleSession($this, $user, $role, $workspace);

    $team = Team::factory()->create();
    $perms = Permission::factory()->count(2)->create();

    $this->post(route('admin.roles.store'), [
        'team_id' => $team->id,
        'name' => 'Role Dengan Permission',
        'permission_ids' => $perms->pluck('id')->toArray(),
    ])->assertRedirect(route('admin.roles.index'));

    $newRole = Role::where('name', 'Role Dengan Permission')->first();
    expect($newRole->permissions)->toHaveCount(2);
});

it('validates required team_id on store', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.store');
    activateRoleSession($this, $user, $role, $workspace);

    $this->post(route('admin.roles.store'), [
        'name' => 'Test',
    ])->assertSessionHasErrors('team_id');
});

it('validates required name on store', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.store');
    activateRoleSession($this, $user, $role, $workspace);

    $team = Team::factory()->create();

    $this->post(route('admin.roles.store'), [
        'team_id' => $team->id,
        'name' => '',
    ])->assertSessionHasErrors('name');
});

it('validates team exists on store', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.store');
    activateRoleSession($this, $user, $role, $workspace);

    $this->post(route('admin.roles.store'), [
        'team_id' => 99999,
        'name' => 'Test',
    ])->assertSessionHasErrors('team_id');
});

it('validates permission_ids contain valid ids', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.store');
    activateRoleSession($this, $user, $role, $workspace);

    $team = Team::factory()->create();

    $this->post(route('admin.roles.store'), [
        'team_id' => $team->id,
        'name' => 'Test',
        'permission_ids' => [99999],
    ])->assertSessionHasErrors('permission_ids.0');
});

// --- Edit ---

it('displays edit form with role data, teams, and permissions', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.edit');
    activateRoleSession($this, $user, $role, $workspace);

    $editRole = Role::factory()->create(['name' => 'Role Edit']);

    $this->get(route('admin.roles.edit', $editRole))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/edit')
            ->where('role.name', 'Role Edit')
            ->has('teams')
            ->has('permissions')
        );
});

// --- Update ---

it('updates a role', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.update');
    activateRoleSession($this, $user, $role, $workspace);

    $editRole = Role::factory()->create();

    $this->put(route('admin.roles.update', $editRole), [
        'team_id' => $editRole->team_id,
        'name' => 'Nama Baru',
        'description' => 'Deskripsi baru',
    ])->assertRedirect(route('admin.roles.index'));

    expect($editRole->fresh()->name)->toBe('Nama Baru');
});

it('syncs permissions on update', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.update');
    activateRoleSession($this, $user, $role, $workspace);

    $permA = Permission::factory()->create();
    $permB = Permission::factory()->create();
    $editRole = Role::factory()->create();
    $editRole->permissions()->attach($permA);

    $this->put(route('admin.roles.update', $editRole), [
        'team_id' => $editRole->team_id,
        'name' => $editRole->name,
        'permission_ids' => [$permB->id],
    ])->assertRedirect(route('admin.roles.index'));

    $editRole->refresh();
    expect($editRole->permissions)->toHaveCount(1);
    expect($editRole->permissions->first()->id)->toBe($permB->id);
});

// --- Destroy ---

it('soft deletes a role without users', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.destroy');
    activateRoleSession($this, $user, $role, $workspace);

    $deleteRole = Role::factory()->create();

    $this->delete(route('admin.roles.destroy', $deleteRole))
        ->assertRedirect(route('admin.roles.index'));

    $this->assertSoftDeleted('roles', ['id' => $deleteRole->id]);
});

it('prevents deleting a role with users', function () {
    [$user, $role, $workspace] = setupRoleTestUser('admin.roles.destroy');
    activateRoleSession($this, $user, $role, $workspace);

    $deleteRole = Role::factory()->create();
    $attachedUser = User::factory()->create();
    $deleteRole->users()->attach($attachedUser);

    $this->delete(route('admin.roles.destroy', $deleteRole))
        ->assertRedirect()
        ->assertSessionHasErrors('delete');

    expect($deleteRole->fresh()->deleted_at)->toBeNull();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('admin.roles.index'))
        ->assertRedirect(route('login'));
});
