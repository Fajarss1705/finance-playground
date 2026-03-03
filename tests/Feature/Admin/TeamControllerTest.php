<?php

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupTeamTestUser(string ...$permissionNames): array
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

function activateTeamSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Index ---

it('displays teams index page', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.index');
    activateTeamSession($this, $user, $role, $workspace);

    $this->get(route('admin.teams.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/teams/index')
            ->has('teams.data')
            ->has('organizations')
            ->has('filters')
        );
});

it('filters teams by organization', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.index');
    activateTeamSession($this, $user, $role, $workspace);

    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    Team::factory()->count(2)->create(['organization_id' => $org1->id]);
    Team::factory()->count(3)->create(['organization_id' => $org2->id]);

    $this->get(route('admin.teams.index', ['organization_id' => $org1->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('teams.data', 2)
        );
});

it('returns 403 without permission', function () {
    [$user, $role, $workspace] = setupTeamTestUser('dashboard');
    activateTeamSession($this, $user, $role, $workspace);

    $this->get(route('admin.teams.index'))
        ->assertForbidden();
});

// --- Create ---

it('displays create form with organizations list', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.create');
    activateTeamSession($this, $user, $role, $workspace);

    $this->get(route('admin.teams.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/teams/create')
            ->has('organizations')
        );
});

// --- Store ---

it('stores a new team', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.store');
    activateTeamSession($this, $user, $role, $workspace);

    $org = Organization::factory()->create();

    $this->post(route('admin.teams.store'), [
        'organization_id' => $org->id,
        'name' => 'Tim Baru',
        'description' => 'Deskripsi tim',
    ])->assertRedirect(route('admin.teams.index'));

    $this->assertDatabaseHas('teams', [
        'organization_id' => $org->id,
        'name' => 'Tim Baru',
    ]);
});

it('validates required fields on store', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.store');
    activateTeamSession($this, $user, $role, $workspace);

    $this->post(route('admin.teams.store'), [])
        ->assertSessionHasErrors(['name', 'organization_id']);
});

it('validates organization exists on store', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.store');
    activateTeamSession($this, $user, $role, $workspace);

    $this->post(route('admin.teams.store'), [
        'organization_id' => 99999,
        'name' => 'Tim Test',
    ])->assertSessionHasErrors('organization_id');
});

// --- Edit ---

it('displays edit form with team data', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.edit');
    activateTeamSession($this, $user, $role, $workspace);

    $team = Team::factory()->create(['name' => 'Tim Edit']);

    $this->get(route('admin.teams.edit', $team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/teams/edit')
            ->where('team.name', 'Tim Edit')
            ->has('organizations')
        );
});

// --- Update ---

it('updates a team', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.update');
    activateTeamSession($this, $user, $role, $workspace);

    $team = Team::factory()->create();

    $this->put(route('admin.teams.update', $team), [
        'organization_id' => $team->organization_id,
        'name' => 'Nama Tim Baru',
        'description' => 'Deskripsi baru',
    ])->assertRedirect(route('admin.teams.index'));

    expect($team->fresh()->name)->toBe('Nama Tim Baru');
});

// --- Destroy ---

it('soft deletes a team without roles', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.destroy');
    activateTeamSession($this, $user, $role, $workspace);

    $team = Team::factory()->create();

    $this->delete(route('admin.teams.destroy', $team))
        ->assertRedirect(route('admin.teams.index'));

    $this->assertSoftDeleted('teams', ['id' => $team->id]);
});

it('prevents deleting a team with roles', function () {
    [$user, $role, $workspace] = setupTeamTestUser('admin.teams.destroy');
    activateTeamSession($this, $user, $role, $workspace);

    $team = Team::factory()->create();
    Role::factory()->create(['team_id' => $team->id]);

    $this->delete(route('admin.teams.destroy', $team))
        ->assertRedirect()
        ->assertSessionHasErrors('delete');

    expect($team->fresh()->deleted_at)->toBeNull();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('admin.teams.index'))
        ->assertRedirect(route('login'));
});
