<?php

use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;
use Illuminate\Foundation\Vite;

beforeEach(function () {
    app(Vite::class)->useScriptTagAttributes([])->useBuildDirectory('build');
    $this->withoutVite();
});

// --- EnsureRoleSelected middleware ---

it('redirects to role-selector when no active session', function () {
    $user = User::factory()->withRole()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('role-selector.index'));
});

it('redirects to no-access when user has no roles and no session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('no-access'));
});

// --- CheckPermission middleware ---

it('returns 403 when permission is missing', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $this->get(route('dashboard'))
        ->assertForbidden();
});

it('grants access when permission matches route name', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permission = Permission::create(['name' => 'dashboard']);
    $role->permissions()->attach($permission);

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $this->get(route('dashboard'))
        ->assertSuccessful();
});

// --- Inertia shared data ---

it('shares active role and workspace via Inertia', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permission = Permission::create(['name' => 'dashboard']);
    $role->permissions()->attach($permission);

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('auth.activeRole')
            ->has('auth.activeWorkspace')
            ->has('auth.permissions')
            ->has('auth.workspaces')
            ->where('auth.activeRole.id', $role->id)
            ->where('auth.activeWorkspace.id', $workspace->id)
            ->where('auth.permissions', ['dashboard'])
        );
});

// --- Full flow integration ---

it('completes full login to dashboard flow', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permission = Permission::create(['name' => 'dashboard']);
    $role->permissions()->attach($permission);

    // Login → role-selector
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('role-selector.index'));

    // Select role → dashboard
    $this->post(route('role-selector.store'), [
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ])->assertRedirect(route('dashboard'));

    // Dashboard accessible
    $this->get(route('dashboard'))
        ->assertSuccessful();

    expect(session('active_role_id'))->toBe($role->id);
    expect(session('active_workspace_id'))->toBe($workspace->id);
});
