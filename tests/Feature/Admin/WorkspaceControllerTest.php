<?php

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupWorkspaceTestUser(string ...$permissionNames): array
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

function activateWorkspaceSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Index ---

it('displays workspaces index page', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.index');
    activateWorkspaceSession($this, $user, $role, $workspace);

    Workspace::factory()->count(2)->create();

    $this->get(route('admin.workspaces.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workspaces/index')
            ->has('workspaces.data', 3) // 2 + 1 from withRole factory
        );
});

it('returns 403 without permission', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('dashboard');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $this->get(route('admin.workspaces.index'))
        ->assertForbidden();
});

// --- Create ---

it('displays create form with organizations list', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.create');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $this->get(route('admin.workspaces.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workspaces/create')
            ->has('organizations')
        );
});

// --- Store ---

it('stores a new workspace', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.store');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $this->post(route('admin.workspaces.store'), [
        'name' => 'Workspace Baru',
        'description' => 'Deskripsi workspace baru',
    ])->assertRedirect(route('admin.workspaces.index'));

    $this->assertDatabaseHas('workspaces', [
        'name' => 'Workspace Baru',
        'description' => 'Deskripsi workspace baru',
    ]);
});

it('stores a workspace with organization assignment', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.store');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $orgs = Organization::factory()->count(2)->create();

    $this->post(route('admin.workspaces.store'), [
        'name' => 'Workspace Dengan Org',
        'organization_ids' => $orgs->pluck('id')->toArray(),
    ])->assertRedirect(route('admin.workspaces.index'));

    $newWorkspace = Workspace::where('name', 'Workspace Dengan Org')->first();
    expect($newWorkspace->organizations)->toHaveCount(2);
});

it('validates required name on store', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.store');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $this->post(route('admin.workspaces.store'), [
        'name' => '',
    ])->assertSessionHasErrors('name');
});

it('validates organization_ids contain valid ids', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.store');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $this->post(route('admin.workspaces.store'), [
        'name' => 'Test',
        'organization_ids' => [99999],
    ])->assertSessionHasErrors('organization_ids.0');
});

// --- Edit ---

it('displays edit form with workspace data and organizations', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.edit');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $ws = Workspace::factory()->create(['name' => 'Test Workspace']);

    $this->get(route('admin.workspaces.edit', $ws))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workspaces/edit')
            ->where('workspace.name', 'Test Workspace')
            ->has('organizations')
        );
});

// --- Update ---

it('updates a workspace', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.update');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $ws = Workspace::factory()->create();

    $this->put(route('admin.workspaces.update', $ws), [
        'name' => 'Nama Baru',
        'description' => 'Deskripsi baru',
    ])->assertRedirect(route('admin.workspaces.index'));

    expect($ws->fresh()->name)->toBe('Nama Baru');
});

it('syncs organizations on update', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.update');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $ws = Workspace::factory()->create();
    $ws->organizations()->attach($orgA);

    $this->put(route('admin.workspaces.update', $ws), [
        'name' => $ws->name,
        'organization_ids' => [$orgB->id],
    ])->assertRedirect(route('admin.workspaces.index'));

    $ws->refresh();
    expect($ws->organizations)->toHaveCount(1);
    expect($ws->organizations->first()->id)->toBe($orgB->id);
});

// --- Destroy ---

it('soft deletes a workspace without organizations', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.destroy');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $ws = Workspace::factory()->create();

    $this->delete(route('admin.workspaces.destroy', $ws))
        ->assertRedirect(route('admin.workspaces.index'));

    $this->assertSoftDeleted('workspaces', ['id' => $ws->id]);
});

it('prevents deleting a workspace with organizations', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.destroy');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $ws = Workspace::factory()->create();
    $ws->organizations()->attach(Organization::factory()->create());

    $this->delete(route('admin.workspaces.destroy', $ws))
        ->assertRedirect()
        ->assertSessionHasErrors('delete');

    expect($ws->fresh()->deleted_at)->toBeNull();
});

// --- Trash ---

it('displays trash page with soft-deleted workspaces', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.trash');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $ws = Workspace::factory()->create();
    $ws->delete();

    $this->get(route('admin.workspaces.trash'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workspaces/trash')
            ->has('workspaces.data', 1)
        );
});

it('returns 403 for trash without permission', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('dashboard');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $this->get(route('admin.workspaces.trash'))
        ->assertForbidden();
});

// --- Restore ---

it('restores a soft-deleted workspace', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('admin.workspaces.restore');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $ws = Workspace::factory()->create();
    $ws->delete();

    $this->post(route('admin.workspaces.restore', $ws))
        ->assertRedirect(route('admin.workspaces.trash'));

    expect($ws->fresh()->deleted_at)->toBeNull();
});

it('returns 403 for restore without permission', function () {
    [$user, $role, $workspace] = setupWorkspaceTestUser('dashboard');
    activateWorkspaceSession($this, $user, $role, $workspace);

    $ws = Workspace::factory()->create();
    $ws->delete();

    $this->post(route('admin.workspaces.restore', $ws))
        ->assertForbidden();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('admin.workspaces.index'))
        ->assertRedirect(route('login'));
});
