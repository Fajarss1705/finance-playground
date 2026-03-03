<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWorkspaceRequest;
use App\Http\Requests\Admin\UpdateWorkspaceRequest;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/workspaces/index', [
            'workspaces' => Workspace::query()
                ->withCount('organizations')
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/workspaces/create', [
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $workspace = Workspace::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $workspace->organizations()->sync($validated['organization_ids'] ?? []);

        return to_route('admin.workspaces.index');
    }

    public function edit(Workspace $workspace): Response
    {
        return Inertia::render('admin/workspaces/edit', [
            'workspace' => $workspace->load('organizations:id,name'),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validated();

        $workspace->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $workspace->organizations()->sync($validated['organization_ids'] ?? []);

        return to_route('admin.workspaces.index');
    }

    public function destroy(Workspace $workspace): RedirectResponse
    {
        if ($workspace->organizations()->exists()) {
            return back()->withErrors(['delete' => 'Workspace tidak dapat dihapus karena masih memiliki organisasi yang terkait.']);
        }

        $workspace->delete();

        return to_route('admin.workspaces.index');
    }
}
