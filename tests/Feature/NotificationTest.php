<?php

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->withoutVite();
});

// --- Helper to set up a user with active session ---

function setupUserWithSession(): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    return [$user, $role, $workspace];
}

function activateSession(object $test, User $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- NotificationService::send ---

it('creates notification record and stores email html', function () {
    Mail::fake();

    [$user, $role, $workspace] = setupUserWithSession();

    $notification = app(NotificationService::class)->send(
        $user, $role, $workspace,
        'Test Subject',
        'Test body content.',
        '/dashboard',
    );

    expect($notification)->toBeInstanceOf(Notification::class);
    expect($notification->subject)->toBe('Test Subject');
    expect($notification->body)->toBe('Test body content.');
    expect($notification->link)->toBe('/dashboard');
    expect($notification->fresh()->is_read)->toBeFalse();
    expect($notification->email_html)->not->toBeNull();
    expect($notification->email_html)->toContain('Test body content.');

    Mail::assertQueued(NotificationMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

it('creates notification without link', function () {
    Mail::fake();

    [$user, $role, $workspace] = setupUserWithSession();

    $notification = app(NotificationService::class)->send(
        $user, $role, $workspace,
        'No Link Notification',
        'This has no link.',
    );

    expect($notification->link)->toBeNull();

    Mail::assertQueued(NotificationMail::class);
});

// --- NotificationService::resend ---

it('resends notification with new signed url', function () {
    Mail::fake();

    [$user, $role, $workspace] = setupUserWithSession();

    $notification = app(NotificationService::class)->send(
        $user, $role, $workspace,
        'Original',
        'Original body.',
        '/dashboard',
    );

    $originalHtml = $notification->email_html;

    // Travel forward so the new signed URL differs
    $this->travel(1)->hours();

    app(NotificationService::class)->resend($notification);

    $notification->refresh();
    expect($notification->email_html)->not->toBe($originalHtml);

    Mail::assertQueued(NotificationMail::class, 2);
});

// --- GET /notifications (index) ---

it('returns notifications grouped by current role and all roles', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    activateSession($this, $user, $role, $workspace);

    // Create a second role for the same user
    $otherUser = User::factory()->withRole()->create();
    $otherRole = $otherUser->roles->first();
    $otherWorkspace = $otherRole->team->organization->workspaces->first();

    // Attach other role to our user
    $user->roles()->attach($otherRole);

    // Current role notification
    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'subject' => 'Current role notification',
    ]);

    // Other role notification
    Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $otherRole->id,
        'workspace_id' => $otherWorkspace->id,
        'subject' => 'Other role notification',
    ]);

    $response = $this->getJson(route('notifications.index'));

    $response->assertSuccessful();

    $data = $response->json();
    expect($data['currentRole']['items'])->toHaveCount(1);
    expect($data['currentRole']['items'][0]['subject'])->toBe('Current role notification');
    expect($data['allRoles']['items'])->toHaveCount(1);
    expect($data['allRoles']['items'][0]['subject'])->toBe('Other role notification');
});

// --- PATCH /notifications/{id}/read ---

it('marks notification as read', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    activateSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    expect($notification->is_read)->toBeFalse();

    $this->patchJson(route('notifications.mark-read', $notification))
        ->assertSuccessful();

    expect($notification->refresh()->is_read)->toBeTrue();
});

it('prevents marking another users notification as read', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    activateSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->create();
    $notification = Notification::factory()->create([
        'user_id' => $otherUser->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->patchJson(route('notifications.mark-read', $notification))
        ->assertForbidden();
});

// --- GET /notifications/{id}/go ---

it('marks read and redirects to notification link', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    activateSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'link' => '/dashboard',
    ]);

    $this->get(route('notifications.go', $notification))
        ->assertRedirect('/dashboard');

    expect($notification->refresh()->is_read)->toBeTrue();
});

it('redirects to dashboard when notification has no link', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    activateSession($this, $user, $role, $workspace);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'link' => null,
    ]);

    $this->get(route('notifications.go', $notification))
        ->assertRedirect(route('dashboard'));
});

// --- GET /notifications/{id}/redirect (signed URL) ---

it('switches role and workspace via signed url redirect', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    $this->actingAs($user);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'link' => '/dashboard',
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'notifications.redirect',
        now()->addDays(7),
        ['notification' => $notification->id],
    );

    $this->get($signedUrl)
        ->assertRedirect('/dashboard');

    expect($notification->refresh()->is_read)->toBeTrue();
    expect(session('active_role_id'))->toBe($role->id);
    expect(session('active_workspace_id'))->toBe($workspace->id);
});

it('returns 403 for expired signed url', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    $this->actingAs($user);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'notifications.redirect',
        now()->subDay(),
        ['notification' => $notification->id],
    );

    $this->get($signedUrl)
        ->assertForbidden();
});

it('returns 403 for tampered signed url', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    $this->actingAs($user);

    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'notifications.redirect',
        now()->addDays(7),
        ['notification' => $notification->id],
    );

    // Tamper with the URL
    $tamperedUrl = str_replace('signature=', 'signature=tampered', $signedUrl);

    $this->get($tamperedUrl)
        ->assertForbidden();
});

// --- Inertia shared data ---

it('shares unread notifications count via Inertia', function () {
    [$user, $role, $workspace] = setupUserWithSession();
    activateSession($this, $user, $role, $workspace);

    $dashboardPermission = Permission::create(['name' => 'dashboard']);
    $role->permissions()->attach($dashboardPermission);

    // Create 2 unread + 1 read
    Notification::factory()->count(2)->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    Notification::factory()->read()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('auth.unreadNotificationsCount', 2)
        );
});
