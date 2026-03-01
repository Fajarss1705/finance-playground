<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\Workspace;
use App\Services\ActiveSessionService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => $this->getAuthData($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAuthData(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return ['user' => null];
        }

        $session = app(ActiveSessionService::class);

        $data = [
            'user' => $user,
            'permissions' => $session->getActivePermissions(),
        ];

        $activeRoleId = $session->getActiveRoleId();
        $activeWorkspaceId = $session->getActiveWorkspaceId();

        if ($activeRoleId) {
            $data['activeRole'] = Role::with('team.organization')->find($activeRoleId);
        }

        if ($activeWorkspaceId) {
            $data['activeWorkspace'] = Workspace::find($activeWorkspaceId);
        }

        $data['workspaces'] = $user->getAccessibleWorkspaces();

        return $data;
    }
}
