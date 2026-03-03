<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTeamRequest;
use App\Http\Requests\Admin\UpdateTeamRequest;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Team::query()
            ->with('organization')
            ->withCount('roles')
            ->orderBy('name');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        }

        return Inertia::render('admin/teams/index', [
            'teams' => $query->paginate(15)->withQueryString(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'organization_id' => $request->input('organization_id'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/teams/create', [
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'selectedOrganizationId' => $request->input('organization_id'),
        ]);
    }

    public function store(StoreTeamRequest $request): RedirectResponse
    {
        Team::create($request->validated());

        return to_route('admin.teams.index');
    }

    public function edit(Team $team): Response
    {
        return Inertia::render('admin/teams/edit', [
            'team' => $team->load('organization'),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team): RedirectResponse
    {
        $team->update($request->validated());

        return to_route('admin.teams.index');
    }

    public function destroy(Team $team): RedirectResponse
    {
        if ($team->roles()->exists()) {
            return back()->withErrors(['delete' => 'Tim tidak dapat dihapus karena masih memiliki role.']);
        }

        $team->delete();

        return to_route('admin.teams.index');
    }
}
