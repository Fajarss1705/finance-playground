<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Services\ActiveSessionService;
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
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }
}
