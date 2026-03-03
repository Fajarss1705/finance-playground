<?php

use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;
use Illuminate\Foundation\Vite;

beforeEach(function () {
    app(Vite::class)->useScriptTagAttributes([])->useBuildDirectory('build');
    $this->withoutVite();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('personal.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with active session and permission can visit the dashboard', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permission = Permission::create(['name' => 'personal.index']);
    $role->permissions()->attach($permission);

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $response = $this->get(route('personal.index'));
    $response->assertOk();
});

test('authenticated users without active session are redirected to role-selector', function () {
    $user = User::factory()->withRole()->create();
    $this->actingAs($user);

    $response = $this->get(route('personal.index'));
    $response->assertRedirect(route('role-selector.index'));
});
