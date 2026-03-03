<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminFileRequest;
use App\Models\File;
use App\Services\ActiveSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FileController extends Controller
{
    public function index(): Response
    {
        $session = app(ActiveSessionService::class);

        return Inertia::render('admin/files/index', [
            'files' => File::query()
                ->where('workspace_id', $session->getActiveWorkspaceId())
                ->with(['user:id,name', 'role:id,name', 'team:id,name'])
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function upload(StoreAdminFileRequest $request): RedirectResponse
    {
        $session = app(ActiveSessionService::class);
        $role = auth()->user()->roles()->find($session->getActiveRoleId());

        $uploadedFile = $request->file('file');
        $uuid = (string) Str::uuid();
        $ext = $uploadedFile->getClientOriginalExtension();
        $filename = $uuid.'.'.$ext;
        $workspaceId = $session->getActiveWorkspaceId();
        $path = 'files/'.$workspaceId.'/'.now()->format('Y/m').'/'.$filename;

        Storage::disk('local')->putFileAs(
            dirname($path),
            $uploadedFile,
            basename($path),
        );

        File::create([
            'uuid' => $uuid,
            'original_filename' => $uploadedFile->getClientOriginalName(),
            'filename' => $filename,
            'mime_type' => $uploadedFile->getClientMimeType(),
            'size' => $uploadedFile->getSize(),
            'disk' => 'local',
            'path' => $path,
            'user_id' => auth()->id(),
            'role_id' => $role?->id,
            'team_id' => $role?->team_id,
            'organization_id' => $role?->team?->organization_id,
            'workspace_id' => $workspaceId,
            'source_route' => $request->validated('source_route'),
            'is_workspace_public' => $request->boolean('is_workspace_public'),
        ]);

        return back();
    }

    public function destroy(File $file): RedirectResponse
    {
        $activeWorkspaceId = app(ActiveSessionService::class)->getActiveWorkspaceId();
        abort_unless($file->workspace_id === $activeWorkspaceId, 403);

        $file->delete();

        return back();
    }

    public function trash(): Response
    {
        $session = app(ActiveSessionService::class);

        return Inertia::render('admin/files/trash', [
            'files' => File::onlyTrashed()
                ->where('workspace_id', $session->getActiveWorkspaceId())
                ->with(['user:id,name', 'role:id,name', 'team:id,name'])
                ->orderByDesc('deleted_at')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function restore(File $file): RedirectResponse
    {
        $activeWorkspaceId = app(ActiveSessionService::class)->getActiveWorkspaceId();
        abort_unless($file->workspace_id === $activeWorkspaceId, 403);

        $file->restore();

        return back();
    }
}
