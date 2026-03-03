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
    [$user, $role, $workspace] = setupFileTestUser('personal.files');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('personal.files'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('personal/files')
            ->has('files.data', 1)
        );
});

it('only shows own files in personal page', function () {
    [$user, $role, $workspace] = setupFileTestUser('personal.files');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    // Another user's file in same workspace — not public
    File::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('personal.files'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('files.data', 1)
        );
});

it('shows workspace public files in personal page', function () {
    [$user, $role, $workspace] = setupFileTestUser('personal.files');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    // Another user's file that is workspace public
    File::factory()->create([
        'workspace_id' => $workspace->id,
        'is_workspace_public' => true,
    ]);

    $this->get(route('personal.files'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('files.data', 2)
        );
});

it('returns 403 for personal without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('personal.index');
    activateFileSession($this, $user, $role, $workspace);

    $this->get(route('personal.files'))
        ->assertForbidden();
});

// --- Team ---

it('displays team files page', function () {
    [$user, $role, $workspace] = setupFileTestUser('team.files.index');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'team_id' => $role->team_id,
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('team.files.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('team/files')
            ->has('files.data', 1)
        );
});

it('shows workspace public files in team page', function () {
    [$user, $role, $workspace] = setupFileTestUser('team.files.index');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'team_id' => $role->team_id,
        'workspace_id' => $workspace->id,
    ]);

    // Different team's file that is workspace public
    File::factory()->create([
        'workspace_id' => $workspace->id,
        'is_workspace_public' => true,
    ]);

    $this->get(route('team.files.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('files.data', 2)
        );
});

it('returns 403 for team without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('personal.index');
    activateFileSession($this, $user, $role, $workspace);

    $this->get(route('team.files.index'))
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
    [$user, $role, $workspace] = setupFileTestUser('personal.index');
    activateFileSession($this, $user, $role, $workspace);

    $file = File::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('files.download', $file))
        ->assertForbidden();
});

it('returns 403 for download from different workspace', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('files.download');
    activateFileSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->withRole()->create();
    $otherWorkspace = $otherUser->roles->first()->team->organization->workspaces->first();

    $file = File::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'disk' => 'local',
        'path' => 'files/test/other.pdf',
    ]);

    $this->get(route('files.download', $file))
        ->assertForbidden();
});

// --- Admin: Index ---

it('displays admin files index', function () {
    [$user, $role, $workspace] = setupFileTestUser('admin.files.index');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->count(3)->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->get(route('admin.files.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/files/index')
            ->has('files.data', 3)
        );
});

// --- Admin: Upload (50MB limit) ---

it('uploads a file via admin route', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('admin.files.upload');
    activateFileSession($this, $user, $role, $workspace);

    $file = UploadedFile::fake()->create('admin-doc.pdf', 1024, 'application/pdf');

    $this->post(route('admin.files.upload'), [
        'file' => $file,
        'is_workspace_public' => true,
    ])->assertRedirect();

    $stored = File::first();
    expect($stored->original_filename)->toBe('admin-doc.pdf');
    expect($stored->is_workspace_public)->toBeTrue();
});

it('allows up to 50MB on admin upload', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('admin.files.upload');
    activateFileSession($this, $user, $role, $workspace);

    // 30MB — exceeds user limit (25MB) but within admin limit (50MB)
    $file = UploadedFile::fake()->create('big.pdf', 30000, 'application/pdf');

    $this->post(route('admin.files.upload'), [
        'file' => $file,
    ])->assertRedirect();

    expect(File::count())->toBe(1);
});

it('rejects files over 50MB on admin upload', function () {
    Storage::fake('local');

    [$user, $role, $workspace] = setupFileTestUser('admin.files.upload');
    activateFileSession($this, $user, $role, $workspace);

    $file = UploadedFile::fake()->create('huge.pdf', 52000, 'application/pdf');

    $this->post(route('admin.files.upload'), [
        'file' => $file,
    ])->assertSessionHasErrors('file');
});

// --- Admin: Destroy (soft delete) ---

it('soft deletes a file via admin destroy', function () {
    [$user, $role, $workspace] = setupFileTestUser('admin.files.destroy');
    activateFileSession($this, $user, $role, $workspace);

    $file = File::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->delete(route('admin.files.destroy', $file))
        ->assertRedirect();

    expect(File::count())->toBe(0);
    expect(File::withTrashed()->count())->toBe(1);
});

it('returns 403 for admin destroy without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('personal.index');
    activateFileSession($this, $user, $role, $workspace);

    $file = File::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->delete(route('admin.files.destroy', $file))
        ->assertForbidden();
});

it('returns 403 for admin destroy from different workspace', function () {
    [$user, $role, $workspace] = setupFileTestUser('admin.files.destroy');
    activateFileSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->withRole()->create();
    $otherWorkspace = $otherUser->roles->first()->team->organization->workspaces->first();

    $file = File::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->delete(route('admin.files.destroy', $file))
        ->assertForbidden();

    expect(File::count())->toBe(1);
});

// --- Admin: Trash ---

it('displays admin files trash', function () {
    [$user, $role, $workspace] = setupFileTestUser('admin.files.trash');
    activateFileSession($this, $user, $role, $workspace);

    File::factory()->create([
        'workspace_id' => $workspace->id,
        'deleted_at' => now(),
    ]);

    $this->get(route('admin.files.trash'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/files/trash')
            ->has('files.data', 1)
        );
});

// --- Admin: Restore ---

it('restores a soft-deleted file', function () {
    [$user, $role, $workspace] = setupFileTestUser('admin.files.restore');
    activateFileSession($this, $user, $role, $workspace);

    $file = File::factory()->create([
        'workspace_id' => $workspace->id,
        'deleted_at' => now(),
    ]);

    $this->post(route('admin.files.restore', $file))
        ->assertRedirect();

    expect(File::count())->toBe(1);
    expect($file->fresh()->deleted_at)->toBeNull();
});

it('returns 403 for admin restore without permission', function () {
    [$user, $role, $workspace] = setupFileTestUser('personal.index');
    activateFileSession($this, $user, $role, $workspace);

    $file = File::factory()->create([
        'workspace_id' => $workspace->id,
        'deleted_at' => now(),
    ]);

    $this->post(route('admin.files.restore', $file))
        ->assertForbidden();
});

it('returns 403 for admin restore from different workspace', function () {
    [$user, $role, $workspace] = setupFileTestUser('admin.files.restore');
    activateFileSession($this, $user, $role, $workspace);

    $otherUser = User::factory()->withRole()->create();
    $otherWorkspace = $otherUser->roles->first()->team->organization->workspaces->first();

    $file = File::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'deleted_at' => now(),
    ]);

    $this->post(route('admin.files.restore', $file))
        ->assertForbidden();

    expect($file->fresh()->deleted_at)->not->toBeNull();
});

// --- Guest ---

it('redirects guests to login', function () {
    $this->get(route('personal.files'))
        ->assertRedirect(route('login'));
});
