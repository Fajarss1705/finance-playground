<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\ActiveSessionService;
use Illuminate\Support\Facades\Storage;
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
                ->where(fn ($q) => $q
                    ->where('user_id', auth()->id())
                    ->orWhere('is_workspace_public', true)
                )
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

        return Inertia::render('team/files', [
            'files' => File::query()
                ->where('workspace_id', $session->getActiveWorkspaceId())
                ->where(fn ($q) => $q
                    ->where('team_id', $role?->team_id)
                    ->orWhere('is_workspace_public', true)
                )
                ->with(['user:id,name', 'role:id,name'])
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function download(File $file): StreamedResponse
    {
        return Storage::disk($file->disk)->download($file->path, $file->original_filename);
    }
}
