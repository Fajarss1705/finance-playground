<?php

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Team;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupUserWithPermissions(string ...$permissionNames): array
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

function activateOrgSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Index ---

it('displays organizations index page', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.index');
    activateOrgSession($this, $user, $role, $workspace);

    Organization::factory()->count(3)->create();

    $this->get(route('admin.organizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/organizations/index')
            ->has('organizations.data', 4) // 3 + 1 from withRole factory
        );
});

it('returns 403 without permission', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('dashboard');
    activateOrgSession($this, $user, $role, $workspace);

    $this->get(route('admin.organizations.index'))
        ->assertForbidden();
});

// --- Create ---

it('displays create form', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.create');
    activateOrgSession($this, $user, $role, $workspace);

    $this->get(route('admin.organizations.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/organizations/create'));
});

// --- Store ---

it('stores a new organization', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.store');
    activateOrgSession($this, $user, $role, $workspace);

    $this->post(route('admin.organizations.store'), [
        'name' => 'Organisasi Baru',
        'description' => 'Deskripsi baru',
    ])->assertRedirect(route('admin.organizations.index'));

    $this->assertDatabaseHas('organizations', [
        'name' => 'Organisasi Baru',
        'description' => 'Deskripsi baru',
    ]);
});

it('validates required name on store', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.store');
    activateOrgSession($this, $user, $role, $workspace);

    $this->post(route('admin.organizations.store'), [
        'name' => '',
    ])->assertSessionHasErrors('name');
});

// --- Edit ---

it('displays edit form with organization data', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.edit');
    activateOrgSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create(['name' => 'Test Org']);

    $this->get(route('admin.organizations.edit', $org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/organizations/edit')
            ->where('organization.name', 'Test Org')
        );
});

// --- Update ---

it('updates an organization', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.update');
    activateOrgSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create();

    $this->put(route('admin.organizations.update', $org), [
        'name' => 'Nama Baru',
        'description' => 'Deskripsi baru',
    ])->assertRedirect(route('admin.organizations.index'));

    expect($org->fresh()->name)->toBe('Nama Baru');
});

// --- Destroy ---

it('soft deletes an organization without teams', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.destroy');
    activateOrgSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create();

    $this->delete(route('admin.organizations.destroy', $org))
        ->assertRedirect(route('admin.organizations.index'));

    $this->assertSoftDeleted('organizations', ['id' => $org->id]);
});

it('prevents deleting an organization with teams', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.destroy');
    activateOrgSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create();
    Team::factory()->create(['organization_id' => $org->id]);

    $this->delete(route('admin.organizations.destroy', $org))
        ->assertRedirect()
        ->assertSessionHasErrors('delete');

    expect($org->fresh()->deleted_at)->toBeNull();
});

// --- Trash ---

it('displays trash page with soft-deleted organizations', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.trash');
    activateOrgSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create();
    $org->delete();

    $this->get(route('admin.organizations.trash'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/organizations/trash')
            ->has('organizations.data', 1)
        );
});

it('returns 403 for trash without permission', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('dashboard');
    activateOrgSession($this, $user, $role, $workspace);

    $this->get(route('admin.organizations.trash'))
        ->assertForbidden();
});

// --- Restore ---

it('restores a soft-deleted organization', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('admin.organizations.restore');
    activateOrgSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create();
    $org->delete();

    $this->post(route('admin.organizations.restore', $org))
        ->assertRedirect(route('admin.organizations.trash'));

    expect($org->fresh()->deleted_at)->toBeNull();
});

it('returns 403 for restore without permission', function () {
    [$user, $role, $workspace] = setupUserWithPermissions('dashboard');
    activateOrgSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create();
    $org->delete();

    $this->post(route('admin.organizations.restore', $org))
        ->assertForbidden();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('admin.organizations.index'))
        ->assertRedirect(route('login'));
});
