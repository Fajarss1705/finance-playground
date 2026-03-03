<?php

use App\Models\File;
use App\Models\Permission;
use App\Models\User;
use App\Services\ActiveSessionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
});

function setupFileTestUser(string ...$permissionNames): array
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

function activateFileSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Personal ---

it('displays personal files page', function () {
    [$user, $role, $workspace] = setupFileTestUser('files.personal');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('files.personal'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('files/personal')
            ->has('files.data', 1)
        );
});

it('only shows own files in personal page', function () {
    [$user, $role, $workspace] = setupFileTestUser('files.personal');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    // Another user's file in same workspace
    File::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('files.personal'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('files.data', 1)
        );
});

it('returns 403 for personal without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('dashboard');
    activateFileSession($this, $user, $role, $workspace);

    $this->get(route('files.personal'))
        ->assertForbidden();
});

// --- Team ---

it('displays team files page', function () {
    [$user, $role, $workspace] = setupFileTestUser('files.team');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'team_id' => $role->team_id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('files.team'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('files/team')
            ->has('files.data', 1)
        );
});

it('returns 403 for team without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('dashboard');
    activateFileSession($this, $user, $role, $workspace);

    $this->get(route('files.team'))
        ->assertForbidden();
});

// --- Upload ---

it('uploads a file successfully', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('files.upload');
    activateFileSession($this, $user, $role, $workspace);

    $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

    $this->post(route('files.upload'), [
        'file' => $file,
    ])->assertRedirect();

    expect(File::count())->toBe(1);

    $stored = File::first();
    expect($stored->original_filename)->toBe('document.pdf');
    expect($stored->user_id)->toBe($user->id);
    expect($stored->workspace_id)->toBe($workspace->id);
    expect($stored->role_id)->toBe($role->id);
    expect($stored->team_id)->toBe($role->team_id);

    Storage::disk('local')->assertExists($stored->path);
});

it('validates file is required on upload', function () {
    [$user, $role, $workspace] = setupFileTestUser('files.upload');
    activateFileSession($this, $user, $role, $workspace);

    $this->post(route('files.upload'), [])
        ->assertSessionHasErrors('file');
});

it('validates max file size on upload', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('files.upload');
    activateFileSession($this, $user, $role, $workspace);

    $file = UploadedFile::fake()->create('large.pdf', 11000, 'application/pdf');

    $this->post(route('files.upload'), [
        'file' => $file,
    ])->assertSessionHasErrors('file');
});

it('returns 403 for upload without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('dashboard');
    activateFileSession($this, $user, $role, $workspace);

    $this->post(route('files.upload'), [])
        ->assertForbidden();
});

// --- Download ---

it('downloads a file', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('files.download');
    activateFileSession($this, $user, $role, $workspace);

    $uploadedFile = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
    Storage::disk('local')->putFileAs('files/test', $uploadedFile, 'test.pdf');

    $file = File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'disk' => 'local',
        'path' => 'files/test/test.pdf',
        'original_filename' => 'test.pdf',
    ]);

    $this->get(route('files.download', $file))
        ->assertOk();
});

it('returns 403 for download without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('dashboard');
    activateFileSession($this, $user, $role, $workspace);

    $file = File::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('files.download', $file))
        ->assertForbidden();
});

// --- Destroy ---

it('destroys a file and removes from storage', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('files.destroy');
    activateFileSession($this, $user, $role, $workspace);

    $uploadedFile = UploadedFile::fake()->create('delete-me.pdf', 100, 'application/pdf');
    Storage::disk('local')->putFileAs('files/test', $uploadedFile, 'delete-me.pdf');

    $file = File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'disk' => 'local',
        'path' => 'files/test/delete-me.pdf',
    ]);

    $this->delete(route('files.destroy', $file))
        ->assertRedirect();

    expect(File::count())->toBe(0);
    Storage::disk('local')->assertMissing('files/test/delete-me.pdf');
});

it('returns 403 for destroy without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('dashboard');
    activateFileSession($this, $user, $role, $workspace);

    $file = File::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->delete(route('files.destroy', $file))
        ->assertForbidden();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('files.personal'))
        ->assertRedirect(route('login'));
});
