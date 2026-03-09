<?php

use App\Models\File;
use App\Models\Pp\PpWorkflow;
use App\Models\User;
use App\Services\CommentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->service = app(CommentService::class);
});

it('stores a comment as a history entry', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $workflow = PpWorkflow::factory()->create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $sessionContext = [
        'role' => $role->id,
        'team' => null,
        'org' => null,
        'workspace' => $workspace->id,
    ];

    $this->service->store(
        workflow: $workflow,
        source: 'pp01',
        notes: 'Tolong cek kode bidang',
        uploadedFiles: [],
        userId: $user->id,
        sessionContext: $sessionContext,
        workflowPrefix: 'pp',
    );

    $workflow->refresh();
    $history = $workflow->history;

    expect($history)->toHaveCount(2);

    $comment = $history[1];
    expect($comment['action'])->toBe('commented');
    expect($comment['step'])->toBe('pp01');
    expect($comment['notes'])->toBe('Tolong cek kode bidang');
    expect($comment['source'])->toBe('pp01');
    expect($comment['by'])->toBe($user->id);
    expect($comment['role'])->toBe($role->id);
});

it('stores comment with show as source', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $workflow = PpWorkflow::factory()->create([
        'workspace_id' => $workspace->id,
        'history' => [],
    ]);

    $sessionContext = [
        'role' => $role->id,
        'team' => null,
        'org' => null,
        'workspace' => $workspace->id,
    ];

    $this->service->store(
        workflow: $workflow,
        source: 'show',
        notes: 'Komentar dari show page',
        uploadedFiles: [],
        userId: $user->id,
        sessionContext: $sessionContext,
        workflowPrefix: 'pp',
    );

    $workflow->refresh();
    $comment = $workflow->history[0];

    expect($comment['source'])->toBe('show');
    expect($comment['step'])->toBe('show');
});

it('stores files and attaches them to workflow', function () {
    Storage::fake('local');

    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $workflow = PpWorkflow::factory()->create([
        'workspace_id' => $workspace->id,
        'history' => [],
    ]);

    $sessionContext = [
        'role' => $role->id,
        'team' => null,
        'org' => null,
        'workspace' => $workspace->id,
    ];

    $fakeFile = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

    $this->service->store(
        workflow: $workflow,
        source: 'pp01',
        notes: 'Lihat lampiran',
        uploadedFiles: [$fakeFile],
        userId: $user->id,
        sessionContext: $sessionContext,
        workflowPrefix: 'pp',
    );

    $workflow->refresh();
    $comment = $workflow->history[0];

    expect($comment)->toHaveKey('files');
    expect($comment['files'])->toHaveCount(1);

    $file = File::find($comment['files'][0]);
    expect($file)->not->toBeNull();
    expect($file->original_filename)->toBe('document.pdf');
    expect($file->attachable_type)->toBe($workflow->getMorphClass());
    expect($file->attachable_id)->toBe($workflow->id);
    expect($file->source_route)->toBe('pp.pp01.comment');
    expect($file->workspace_id)->toBe($workspace->id);
});

it('stores comment without files when none provided', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $workflow = PpWorkflow::factory()->create([
        'workspace_id' => $workspace->id,
        'history' => [],
    ]);

    $sessionContext = [
        'role' => $role->id,
        'team' => null,
        'org' => null,
        'workspace' => $workspace->id,
    ];

    $this->service->store(
        workflow: $workflow,
        source: 'pp01',
        notes: 'No files',
        uploadedFiles: [],
        userId: $user->id,
        sessionContext: $sessionContext,
        workflowPrefix: 'pp',
    );

    $workflow->refresh();
    $comment = $workflow->history[0];

    expect($comment)->not->toHaveKey('files');
});

it('storeFiles returns file IDs for uploaded files', function () {
    Storage::fake('local');

    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $workflow = PpWorkflow::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $sessionContext = [
        'role' => $role->id,
        'team' => null,
        'org' => null,
        'workspace' => $workspace->id,
    ];

    $files = [
        UploadedFile::fake()->create('doc1.pdf', 500),
        UploadedFile::fake()->create('doc2.xlsx', 300),
    ];

    $fileIds = $this->service->storeFiles(
        $files,
        $workflow,
        'pp.pp01.submit',
        $user->id,
        $sessionContext,
    );

    expect($fileIds)->toHaveCount(2);
    expect(File::find($fileIds[0]))->not->toBeNull();
    expect(File::find($fileIds[1]))->not->toBeNull();
});

it('storeFiles returns empty array when no files', function () {
    $fileIds = $this->service->storeFiles(
        [],
        PpWorkflow::factory()->create(),
        'pp.pp01.submit',
        1,
        ['workspace' => 1],
    );

    expect($fileIds)->toBe([]);
});
