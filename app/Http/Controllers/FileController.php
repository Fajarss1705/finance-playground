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

        return Inertia::render('personal/files', [
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

    public function download(File $file): StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $session = app(ActiveSessionService::class);

        abort_unless($file->workspace_id === $session->getActiveWorkspaceId(), 403);

        if (! $file->is_workspace_public) {
            $requiredPermissions = $this->resolveShowPermissions($file->source_route);

            if ($requiredPermissions !== null) {
                $activePermissions = $session->getActivePermissions();
                $hasAccess = false;

                foreach ($requiredPermissions as $perm) {
                    if (in_array($perm, $activePermissions)) {
                        $hasAccess = true;
                        break;
                    }
                }

                abort_unless($hasAccess, 403);
            }
        }

        // Serve inline (Content-Disposition: inline) for image previews
        if (request()->boolean('inline') && str_starts_with((string) $file->mime_type, 'image/')) {
            return Storage::disk($file->disk)->response($file->path, $file->original_filename, [
                'Content-Type' => $file->mime_type,
            ]);
        }

        return Storage::disk($file->disk)->download($file->path, $file->original_filename);
    }

    /**
     * Derive the .show permission(s) required to download a file from its source_route.
     *
     * Source route patterns:
     *   - "{workflow}.{step}.{action}"  e.g. pp.pp04.dokumen, pk.pk01.draft, prbl.prbl01.foto_upload
     *   - "{step}.export.{format}"      e.g. pp06.export.pdf, pabd05.export.excel
     *
     * @return list<string>|null Candidate permissions (any match = access), null = no restriction
     */
    private function resolveShowPermissions(?string $sourceRoute): ?array
    {
        if (! $sourceRoute) {
            return null;
        }

        $parts = explode('.', $sourceRoute);
        $workflows = ['pp', 'pk', 'pabd', 'prbl'];

        // Pattern 1: "{workflow}.{step}.{action}"
        if (count($parts) >= 3 && in_array($parts[0], $workflows)) {
            $workflow = $parts[0];
            $step = strtolower($parts[1]);

            // "show" source (comments from show page) → workflow.show permission
            $suffix = $step === 'show' ? 'show' : "{$step}.show";

            $perms = ["admin.workflows.{$workflow}.{$suffix}"];
            if ($workflow !== 'pp') {
                $perms[] = "team.workflows.{$workflow}.{$suffix}";
            }

            return $perms;
        }

        // Pattern 2: "{step}.export.{format}" (from compile services)
        if (count($parts) >= 2 && $parts[1] === 'export') {
            $step = strtolower($parts[0]);
            $workflow = $this->inferWorkflowFromStep($step);

            if ($workflow) {
                $perms = ["admin.workflows.{$workflow}.{$step}.show"];
                if ($workflow !== 'pp') {
                    $perms[] = "team.workflows.{$workflow}.{$step}.show";
                }

                return $perms;
            }
        }

        // Unknown pattern — workspace check is still enforced
        return null;
    }

    private function inferWorkflowFromStep(string $step): ?string
    {
        if (str_starts_with($step, 'prbl')) {
            return 'prbl';
        }
        if (str_starts_with($step, 'pabd')) {
            return 'pabd';
        }
        if (str_starts_with($step, 'pk')) {
            return 'pk';
        }
        if (str_starts_with($step, 'pp')) {
            return 'pp';
        }

        return null;
    }
}
