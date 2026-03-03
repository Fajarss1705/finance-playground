<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ActiveSessionService;

beforeEach(function () {
    $this->withoutVite();
});

function setupUserTestUser(string ...$permissionNames): array
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

function activateUserSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Index ---

it('displays users index page', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.index');
    activateUserSession($this, $user, $role, $workspace);

    $this->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->has('users.data')
            ->has('filters')
        );
});

it('returns 403 without permission', function () {
    [$user, $role, $workspace] = setupUserTestUser('dashboard');
    activateUserSession($this, $user, $role, $workspace);

    $this->get(route('admin.users.index'))
        ->assertForbidden();
});

it('searches users by name', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.index');
    activateUserSession($this, $user, $role, $workspace);

    User::factory()->create(['name' => 'Budi Santoso']);
    User::factory()->create(['name' => 'Ani Wijaya']);

    $this->get(route('admin.users.index', ['search' => 'Budi']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
        );
});

it('searches users by email', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.index');
    activateUserSession($this, $user, $role, $workspace);

    User::factory()->create(['email' => 'khusus@example.com']);
    User::factory()->create(['email' => 'lainnya@example.com']);

    $this->get(route('admin.users.index', ['search' => 'khusus']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 1)
        );
});

// --- Create ---

it('displays create form with roles list', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.create');
    activateUserSession($this, $user, $role, $workspace);

    $this->get(route('admin.users.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/create')
            ->has('roles')
        );
});

// --- Store ---

it('stores a new user', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $this->post(route('admin.users.store'), [
        'name' => 'Pengguna Baru',
        'email' => 'baru@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseHas('users', [
        'name' => 'Pengguna Baru',
        'email' => 'baru@example.com',
    ]);

    $newUser = User::where('email', 'baru@example.com')->first();
    expect($newUser->email_verified_at)->not->toBeNull();
});

it('stores a user with role assignment', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $roles = Role::factory()->count(2)->create();

    $this->post(route('admin.users.store'), [
        'name' => 'User Dengan Role',
        'email' => 'withrole@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_ids' => $roles->pluck('id')->toArray(),
    ])->assertRedirect(route('admin.users.index'));

    $newUser = User::where('email', 'withrole@example.com')->first();
    expect($newUser->roles)->toHaveCount(2);
});

it('stores a user with optional fields', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $this->post(route('admin.users.store'), [
        'name' => 'User Lengkap',
        'email' => 'lengkap@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone_number' => '08123456789',
        'jabatan' => 'Bendahara',
    ])->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseHas('users', [
        'email' => 'lengkap@example.com',
        'phone_number' => '08123456789',
        'jabatan' => 'Bendahara',
    ]);
});

it('validates required name on store', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $this->post(route('admin.users.store'), [
        'name' => '',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('name');
});

it('validates required email on store', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $this->post(route('admin.users.store'), [
        'name' => 'Test',
        'email' => '',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('validates unique email on store', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('admin.users.store'), [
        'name' => 'Test',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('validates required password on store', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $this->post(route('admin.users.store'), [
        'name' => 'Test',
        'email' => 'test@example.com',
    ])->assertSessionHasErrors('password');
});

it('validates password confirmation on store', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $this->post(route('admin.users.store'), [
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'wrongconfirm',
    ])->assertSessionHasErrors('password');
});

it('validates role_ids contain valid ids', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.store');
    activateUserSession($this, $user, $role, $workspace);

    $this->post(route('admin.users.store'), [
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_ids' => [99999],
    ])->assertSessionHasErrors('role_ids.0');
});

// --- Edit ---

it('displays edit form with user data and roles', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.edit');
    activateUserSession($this, $user, $role, $workspace);

    $editUser = User::factory()->create(['name' => 'User Edit']);

    $this->get(route('admin.users.edit', $editUser))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/edit')
            ->where('user.name', 'User Edit')
            ->has('roles')
        );
});

// --- Update ---

it('updates a user', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.update');
    activateUserSession($this, $user, $role, $workspace);

    $editUser = User::factory()->create();

    $this->put(route('admin.users.update', $editUser), [
        'name' => 'Nama Baru',
        'email' => $editUser->email,
    ])->assertRedirect(route('admin.users.index'));

    expect($editUser->fresh()->name)->toBe('Nama Baru');
});

it('updates password when provided', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.update');
    activateUserSession($this, $user, $role, $workspace);

    $editUser = User::factory()->create();
    $oldHash = $editUser->getAttributes()['password'];

    $this->put(route('admin.users.update', $editUser), [
        'name' => $editUser->name,
        'email' => $editUser->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertRedirect(route('admin.users.index'));

    $editUser->refresh();
    expect($editUser->getAttributes()['password'])->not->toBe($oldHash);
});

it('preserves password when not provided', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.update');
    activateUserSession($this, $user, $role, $workspace);

    $editUser = User::factory()->create();
    $oldHash = $editUser->getAttributes()['password'];

    $this->put(route('admin.users.update', $editUser), [
        'name' => 'Updated Name',
        'email' => $editUser->email,
    ])->assertRedirect(route('admin.users.index'));

    $editUser->refresh();
    expect($editUser->getAttributes()['password'])->toBe($oldHash);
});

it('syncs roles on update', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.update');
    activateUserSession($this, $user, $role, $workspace);

    $roleA = Role::factory()->create();
    $roleB = Role::factory()->create();
    $editUser = User::factory()->create();
    $editUser->roles()->attach($roleA);

    $this->put(route('admin.users.update', $editUser), [
        'name' => $editUser->name,
        'email' => $editUser->email,
        'role_ids' => [$roleB->id],
    ])->assertRedirect(route('admin.users.index'));

    $editUser->refresh();
    expect($editUser->roles)->toHaveCount(1);
    expect($editUser->roles->first()->id)->toBe($roleB->id);
});

it('allows same email on update for same user', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.update');
    activateUserSession($this, $user, $role, $workspace);

    $editUser = User::factory()->create(['email' => 'keep@example.com']);

    $this->put(route('admin.users.update', $editUser), [
        'name' => 'Updated',
        'email' => 'keep@example.com',
    ])->assertRedirect(route('admin.users.index'));

    expect($editUser->fresh()->name)->toBe('Updated');
});

// --- Destroy ---

it('soft deletes a user', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.destroy');
    activateUserSession($this, $user, $role, $workspace);

    $deleteUser = User::factory()->create();

    $this->delete(route('admin.users.destroy', $deleteUser))
        ->assertRedirect(route('admin.users.index'));

    $this->assertSoftDeleted('users', ['id' => $deleteUser->id]);
});

it('prevents self-deletion', function () {
    [$user, $role, $workspace] = setupUserTestUser('admin.users.destroy');
    activateUserSession($this, $user, $role, $workspace);

    $this->delete(route('admin.users.destroy', $user))
        ->assertRedirect()
        ->assertSessionHasErrors('delete');

    expect($user->fresh()->deleted_at)->toBeNull();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('admin.users.index'))
        ->assertRedirect(route('login'));
});
