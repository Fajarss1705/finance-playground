<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFileRequest;
use App\Models\File;
use App\Services\ActiveSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function personal(): Response
    {
        $session = app(ActiveSessionService::class);

        return Inertia::render('files/personal', [
            'files' => File::query()
                ->where('workspace_id', $session->getActiveWorkspaceId())
                ->where('user_id', auth()->id())
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function team(): Response
    {
        $session = app(ActiveSessionService::class);
        $role = auth()->user()->roles()->find($session->getActiveRoleId());

        return Inertia::render('files/team', [
            'files' => File::query()
                ->where('workspace_id', $session->getActiveWorkspaceId())
                ->where('team_id', $role?->team_id)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function download(File $file): StreamedResponse
    {
        return Storage::disk($file->disk)->download($file->path, $file->original_filename);
    }

    public function upload(StoreFileRequest $request): RedirectResponse
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
        ]);

        return back();
    }

    public function destroy(File $file): RedirectResponse
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->forceDelete();

        return back();
    }
}
