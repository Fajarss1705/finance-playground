<?php

namespace App\Http\Controllers;

use App\Http\Requests\SwitchRoleRequest;
use App\Models\Role;
use App\Models\Workspace;
use App\Services\ActiveSessionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SwitcherController extends Controller
{
    public function __construct(private ActiveSessionService $sessionService) {}

    public function index(): Response|RedirectResponse
    {
        $user = auth()->user();

        if (! $user->hasRoles()) {
            return to_route('no-access');
        }

        return Inertia::render('role-selector', [
            'workspaces' => $user->getAccessibleWorkspaces(),
        ]);
    }

    public function store(SwitchRoleRequest $request): RedirectResponse
    {
        $role = Role::findOrFail($request->validated('role_id'));
        $workspace = Workspace::findOrFail($request->validated('workspace_id'));

        $this->sessionService->switchTo($role, $workspace);

        return to_route('dashboard');
    }
}
