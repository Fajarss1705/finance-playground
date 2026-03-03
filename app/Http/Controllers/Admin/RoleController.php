<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Role::query()
            ->with('team')
            ->withCount('permissions')
            ->orderBy('name');

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->integer('team_id'));
        }

        return Inertia::render('admin/roles/index', [
            'roles' => $query->paginate(15)->withQueryString(),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'team_id' => $request->input('team_id'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/roles/create', [
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name']),
            'selectedTeamId' => $request->input('team_id'),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $role = Role::create([
            'team_id' => $validated['team_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $role->permissions()->sync($validated['permission_ids'] ?? []);

        return to_route('admin.roles.index');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('admin/roles/edit', [
            'role' => $role->load(['team', 'permissions:id,name']),
            'teams' => Team::query()->orderBy('name')->get(['id', 'name']),
            'permissions' => Permission::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $validated = $request->validated();

        $role->update([
            'team_id' => $validated['team_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $role->permissions()->sync($validated['permission_ids'] ?? []);

        return to_route('admin.roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->users()->exists()) {
            return back()->withErrors(['delete' => 'Role tidak dapat dihapus karena masih memiliki pengguna yang terkait.']);
        }

        $role->delete();

        return to_route('admin.roles.index');
    }
}
