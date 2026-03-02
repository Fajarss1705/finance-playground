<?php

use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

it('shares zero unread count when no notifications exist', function () {
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
            ->where('auth.unreadNotificationsCount', 0)
        );
});

it('does not count other users notifications', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permission = Permission::create(['name' => 'dashboard']);
    $role->permissions()->attach($permission);

    // Create notification for a different user
    $otherUser = User::factory()->create();
    Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('auth.unreadNotificationsCount', 0)
        );
});

it('does not count read notifications', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permission = Permission::create(['name' => 'dashboard']);
    $role->permissions()->attach($permission);

    // Create only read notifications
    Notification::factory()->count(3)->read()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('auth.unreadNotificationsCount', 0)
        );
});
