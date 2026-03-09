<?php

namespace App\Services;

use App\Models\File;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CommentService
{
    public function __construct(
        private WorkflowEngine $engine,
    ) {}

    /**
     * Store a comment on a workflow instance.
     *
     * @param  Model  $workflow  The workflow model (PpWorkflow, etc.)
     * @param  string  $source  Comment source — step code (e.g. "pp01") or "show"
     * @param  string  $notes  Comment text (required)
     * @param  array<int, UploadedFile>  $uploadedFiles  Optional file attachments
     * @param  int  $userId  The commenting user's ID
     * @param  array<string, mixed>  $sessionContext  Keys: role, team, org, workspace
     * @param  string  $workflowPrefix  Route prefix for source_route (e.g. "pp")
     */
    public function store(
        Model $workflow,
        string $source,
        string $notes,
        array $uploadedFiles,
        int $userId,
        array $sessionContext,
        string $workflowPrefix,
    ): void {
        $fileIds = $this->storeFiles(
            $uploadedFiles,
            $workflow,
            "{$workflowPrefix}.{$source}.comment",
            $userId,
            $sessionContext,
        );

        $extra = ['source' => $source];
        if (! empty($fileIds)) {
            $extra['files'] = $fileIds;
        }

        $this->engine->recordAction(
            workflow: $workflow,
            step: $source,
            action: 'commented',
            userId: $userId,
            sessionContext: $sessionContext,
            notes: $notes,
            extra: $extra,
        );
    }

    /**
     * Store uploaded files and attach them polymorphically to the given model.
     *
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @param  array<string, mixed>  $sessionContext
     * @return list<int> File IDs
     */
    public function storeFiles(
        array $uploadedFiles,
        Model $attachable,
        string $sourceRoute,
        int $userId,
        array $sessionContext,
    ): array {
        if (empty($uploadedFiles)) {
            return [];
        }

        $workspaceId = $sessionContext['workspace'] ?? null;
        $roleId = $sessionContext['role'] ?? null;
        $role = $roleId ? Role::with('team')->find($roleId) : null;

        $fileIds = [];

        foreach ($uploadedFiles as $uploadedFile) {
            $uuid = (string) Str::uuid();
            $ext = $uploadedFile->getClientOriginalExtension();
            $filename = "{$uuid}.{$ext}";
            $path = "files/{$workspaceId}/".now()->format('Y/m')."/{$filename}";

            Storage::disk('local')->putFileAs(
                dirname($path),
                $uploadedFile,
                basename($path),
            );

            $file = File::create([
                'uuid' => $uuid,
                'original_filename' => $uploadedFile->getClientOriginalName(),
                'filename' => $filename,
                'mime_type' => $uploadedFile->getClientMimeType(),
                'size' => $uploadedFile->getSize(),
                'disk' => 'local',
                'path' => $path,
                'user_id' => $userId,
                'role_id' => $role?->id,
                'team_id' => $role?->team_id,
                'organization_id' => $role?->team?->organization_id,
                'workspace_id' => $workspaceId,
                'source_route' => $sourceRoute,
                'is_workspace_public' => false,
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
            ]);

            $fileIds[] = $file->id;
        }

        return $fileIds;
    }
}
