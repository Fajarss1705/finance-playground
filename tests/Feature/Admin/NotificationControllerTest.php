<?php

use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupAdminNotifSession(string ...$permissionNames): array
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

function activateAdminNotifSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- GET /admin/notifications ---

it('displays all notifications in workspace', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.index');
    activateAdminNotifSession($this, $user, $role, $workspace);

    // Own notification
    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Own notification',
    ]);

    // Other user's notification in same workspace
    $otherUser = User::factory()->create();
    Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Other user notification',
    ]);

    $this->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/notifications/index')
            ->has('notifications.data', 2)
            ->has('filters')
        );
});

it('scopes admin notifications to active workspace', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.index');
    activateAdminNotifSession($this, $user, $role, $workspace);

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
        'user_id' => $otherUser->id,
        'role_id' => $otherUser->roles->first()->id,
        'workspace_id' => $otherWorkspace->id,
        'subject' => 'Other workspace',
    ]);

    $this->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.subject', 'In workspace')
        );
});

it('filters admin notifications by search', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.index');
    activateAdminNotifSession($this, $user, $role, $workspace);

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

    $this->get(route('admin.notifications.index', ['search' => 'Rapat']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.subject', 'Rapat Evaluasi')
        );
});

it('filters admin notifications by status', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.index');
    activateAdminNotifSession($this, $user, $role, $workspace);

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

    $this->get(route('admin.notifications.index', ['status' => 'unread']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data', 1)
            ->where('notifications.data.0.is_read', false)
        );
});

it('eager loads user and role.team for admin notifications', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.index');
    activateAdminNotifSession($this, $user, $role, $workspace);

    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications.data.0.user')
            ->has('notifications.data.0.role.team')
        );
});

// --- PATCH /admin/notifications/mark-all-read ---

it('marks only own notifications as read in admin', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.mark-all-read');
    activateAdminNotifSession($this, $user, $role, $workspace);

    $ownUnread = Notification::factory()->count(2)->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $otherUser = User::factory()->create();
    $otherNotification = Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->patch(route('admin.notifications.mark-all-read'))
        ->assertRedirect();

    $ownUnread->each(fn ($n) => expect($n->refresh()->is_read)->toBeTrue());
    expect($otherNotification->refresh()->is_read)->toBeFalse();
});

// --- Permission check ---

it('returns 403 without admin.notifications.index permission', function () {
    [$user, $role, $workspace] = setupAdminNotifSession();
    activateAdminNotifSession($this, $user, $role, $workspace);

    $this->get(route('admin.notifications.index'))
        ->assertForbidden();
});

// --- GET /admin/notifications/{notification} ---

it('displays admin notification show page', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.show');
    activateAdminNotifSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Admin Show Test',
    ]);

    $this->get(route('admin.notifications.show', $notification))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/notifications/show')
            ->where('notification.subject', 'Admin Show Test')
            ->has('notification.user')
            ->has('notification.role.team')
        );
});

it('shows other users notification in admin show', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.show');
    activateAdminNotifSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('admin.notifications.show', $notification))
        ->assertOk();
});

it('marks own notification as read in admin show', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.show');
    activateAdminNotifSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    expect($notification->is_read)->toBeFalse();

    $this->get(route('admin.notifications.show', $notification))
        ->assertOk();

    expect($notification->refresh()->is_read)->toBeTrue();
});

it('does not mark other users notification as read in admin show', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.show');
    activateAdminNotifSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('admin.notifications.show', $notification))
        ->assertOk();

    expect($notification->refresh()->is_read)->toBeFalse();
});

it('returns 403 for notification in different workspace in admin show', function () {
    [$user, $role, $workspace] = setupAdminNotifSession('admin.notifications.show');
    activateAdminNotifSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->withRole()->create();
    $otherWorkspace = $otherUser->roles->first()->team->organization->workspaces->first();

    $notification = Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $otherUser->roles->first()->id,
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->get(route('admin.notifications.show', $notification))
        ->assertForbidden();
});

it('returns 403 without admin.notifications.show permission', function () {
    [$user, $role, $workspace] = setupAdminNotifSession();
    activateAdminNotifSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('admin.notifications.show', $notification))
        ->assertForbidden();
});
