<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/organizations/index', [
            'organizations' => Organization::query()
                ->withCount('teams')
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/organizations/create');
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        Organization::create($request->validated());

        return to_route('admin.organizations.index');
    }

    public function edit(Organization $organization): Response
    {
        return Inertia::render('admin/organizations/edit', [
            'organization' => $organization,
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $organization->update($request->validated());

        return to_route('admin.organizations.index');
    }

    public function destroy(Organization $organization): RedirectResponse
    {
        if ($organization->teams()->exists()) {
            return back()->withErrors(['delete' => 'Organisasi tidak dapat dihapus karena masih memiliki tim.']);
        }

        $organization->delete();

        return to_route('admin.organizations.index');
    }
}
