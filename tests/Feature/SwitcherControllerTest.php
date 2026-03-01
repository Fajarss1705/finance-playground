<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Vite;

beforeEach(function () {
    app(Vite::class)->useScriptTagAttributes([])->useBuildDirectory('build');
    $this->withoutVite();
});

it('redirects unauthenticated users to login', function () {
    $this->get(route('role-selector.index'))
        ->assertRedirect(route('login'));
});

it('redirects to no-access if user has no roles', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('role-selector.index'))
        ->assertRedirect(route('no-access'));
});

it('renders role-selector page with workspaces', function () {
    $user = User::factory()->withRole()->create();

    $this->actingAs($user)
        ->get(route('role-selector.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('role-selector')
            ->has('workspaces', 1)
            ->has('workspaces.0.workspace')
            ->has('workspaces.0.roles', 1)
        );
});

it('switches role and workspace on store', function () {
    $user = User::factory()->create();

    $org = Organization::factory()->create();
    $team = Team::factory()->for($org)->create();
    $role = Role::factory()->for($team)->create();
    $workspace = Workspace::factory()->create();

    $org->workspaces()->attach($workspace);
    $user->roles()->attach($role);

    $this->actingAs($user)
        ->post(route('role-selector.store'), [
            'role_id' => $role->id,
            'workspace_id' => $workspace->id,
        ])
        ->assertRedirect(route('dashboard'));

    expect(session('active_role_id'))->toBe($role->id);
    expect(session('active_workspace_id'))->toBe($workspace->id);
});

it('rejects role that does not belong to user', function () {
    $user = User::factory()->withRole()->create();
    $otherRole = Role::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->actingAs($user)
        ->post(route('role-selector.store'), [
            'role_id' => $otherRole->id,
            'workspace_id' => $workspace->id,
        ])
        ->assertSessionHasErrors('role_id');
});

it('rejects workspace not accessible via role chain', function () {
    $user = User::factory()->create();

    $org = Organization::factory()->create();
    $team = Team::factory()->for($org)->create();
    $role = Role::factory()->for($team)->create();
    $workspace = Workspace::factory()->create();

    // Workspace NOT attached to org
    $user->roles()->attach($role);

    $this->actingAs($user)
        ->post(route('role-selector.store'), [
            'role_id' => $role->id,
            'workspace_id' => $workspace->id,
        ])
        ->assertSessionHasErrors('workspace_id');
});

it('renders no-access page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('no-access'))
        ->assertSuccessful();
});
