<?php

use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupNotificationIndexSession(): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    return [$user, $role, $workspace];
}

function activateNotificationSession(object $test, User $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function grantPersonalNotificationsPermission($role): void
{
    $permission = Permission::firstOrCreate(['name' => 'personal.notifications']);
    $role->permissions()->syncWithoutDetaching($permission);
}

// --- GET /personal/notifications ---

it('displays personal notifications page', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);
    grantPersonalNotificationsPermission($role);

    Notification::factory()->count(3)->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('personal.notifications'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('personal/notifications')
            ->has('notifications.data', 3)
            ->has('filters')
        );
});

it('scopes notifications to active workspace', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);
    grantPersonalNotificationsPermission($role);

    // Notification in active workspace
    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'In workspace',
    ]);

    // Notification in different workspace
    $otherUser = User::factory()->withRole()->create();
    $otherWorkspace = $otherUser->roles->first()->team->organization->workspaces->first();

    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $otherWorkspace->id,
        'subject' => 'Other workspace',
    ]);

    $this->get(route('personal.notifications'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.subject', 'In workspace')
        );
});

it('only shows own notifications', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);
    grantPersonalNotificationsPermission($role);

    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'My notification',
    ]);

    $otherUser = User::factory()->create();
    Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Other user notification',
    ]);

    $this->get(route('personal.notifications'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.subject', 'My notification')
        );
});

it('filters notifications by search', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);
    grantPersonalNotificationsPermission($role);

    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Laporan Bulanan',
    ]);

    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Rapat Evaluasi',
    ]);

    $this->get(route('personal.notifications', ['search' => 'Laporan']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.subject', 'Laporan Bulanan')
        );
});

it('filters notifications by unread status', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);
    grantPersonalNotificationsPermission($role);

    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    Notification::factory()->read()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('personal.notifications', ['status' => 'unread']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.is_read', false)
        );
});

it('filters notifications by read status', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);
    grantPersonalNotificationsPermission($role);

    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    Notification::factory()->read()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('personal.notifications', ['status' => 'read']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.is_read', true)
        );
});

// --- PATCH /personal/notifications/mark-all-read ---

it('marks all own unread notifications as read', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    $markAllReadPermission = Permission::firstOrCreate(['name' => 'personal.notifications.mark-all-read']);
    $role->permissions()->syncWithoutDetaching($markAllReadPermission);

    $unread = Notification::factory()->count(3)->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->patch(route('personal.notifications.mark-all-read'))
        ->assertRedirect();

    $unread->each(fn ($n) => expect($n->refresh()->is_read)->toBeTrue());
});

it('does not mark other users notifications when marking all read', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    $markAllReadPermission = Permission::firstOrCreate(['name' => 'personal.notifications.mark-all-read']);
    $role->permissions()->syncWithoutDetaching($markAllReadPermission);

    $otherUser = User::factory()->create();
    $otherNotification = Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->patch(route('personal.notifications.mark-all-read'))
        ->assertRedirect();

    expect($otherNotification->refresh()->is_read)->toBeFalse();
});

// --- Permission check ---

it('returns 403 without personal.notifications permission', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    $this->get(route('personal.notifications'))
        ->assertForbidden();
});

// --- GET /notifications/{notification}/show ---

it('displays personal notification show page', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Test Show',
    ]);

    $this->get(route('notifications.show', $notification))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('personal/notifications/show')
            ->where('notification.subject', 'Test Show')
            ->has('notification.role.team')
        );
});

it('marks notification as read when viewing show page', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    expect($notification->is_read)->toBeFalse();

    $this->get(route('notifications.show', $notification))
        ->assertOk();

    expect($notification->refresh()->is_read)->toBeTrue();
});

it('switches role when viewing notification from different role', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    // Create another role for the same user in the same workspace
    $otherRole = \App\Models\Role::factory()->create([
        'team_id' => \App\Models\Team::factory()->create([
            'organization_id' => $role->team->organization_id,
        ])->id,
    ]);
    $user->roles()->attach($otherRole);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $otherRole->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('notifications.show', $notification))
        ->assertOk();

    expect(session('active_role_id'))->toBe($otherRole->id);
    expect($notification->refresh()->is_read)->toBeTrue();
});

it('returns 403 for other users notification on show', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('notifications.show', $notification))
        ->assertForbidden();
});

it('returns 403 for notification in different workspace on show', function () {
    [$user, $role, $workspace] = setupNotificationIndexSession();
    activateNotificationSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->withRole()->create();
    $otherWorkspace = $otherUser->roles->first()->team->organization->workspaces->first();

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->get(route('notifications.show', $notification))
        ->assertForbidden();
});
