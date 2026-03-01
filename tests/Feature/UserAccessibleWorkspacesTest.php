<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;

it('returns false when user has no roles', function () {
    $user = User::factory()->create();

    expect($user->hasRoles())->toBeFalse();
});

it('returns true when user has roles', function () {
    $user = User::factory()->withRole()->create();

    expect($user->hasRoles())->toBeTrue();
});

it('returns empty collection when user has no accessible workspaces', function () {
    $user = User::factory()->create();

    expect($user->getAccessibleWorkspaces())->toBeEmpty();
});

it('returns workspaces with grouped roles', function () {
    $user = User::factory()->create();

    $org = Organization::factory()->create();
    $team = Team::factory()->for($org)->create();
    $role1 = Role::factory()->for($team)->create(['name' => 'Ketua']);
    $role2 = Role::factory()->for($team)->create(['name' => 'Sekretaris']);
    $workspace = Workspace::factory()->create();

    $org->workspaces()->attach($workspace);
    $user->roles()->attach([$role1->id, $role2->id]);

    $result = $user->getAccessibleWorkspaces();

    expect($result)->toHaveCount(1);
    expect($result[0]['workspace']->id)->toBe($workspace->id);
    expect($result[0]['roles'])->toHaveCount(2);
    expect($result[0]['roles']->pluck('name')->all())->toEqual(['Ketua', 'Sekretaris']);
});

it('groups roles under multiple workspaces', function () {
    $user = User::factory()->create();

    $org = Organization::factory()->create();
    $team = Team::factory()->for($org)->create();
    $role = Role::factory()->for($team)->create();

    $ws1 = Workspace::factory()->create(['name' => 'WS 2026']);
    $ws2 = Workspace::factory()->create(['name' => 'WS 2027']);

    $org->workspaces()->attach([$ws1->id, $ws2->id]);
    $user->roles()->attach($role);

    $result = $user->getAccessibleWorkspaces();

    expect($result)->toHaveCount(2);
    expect($result[0]['roles'])->toHaveCount(1);
    expect($result[1]['roles'])->toHaveCount(1);
});

it('handles roles from different organizations in same workspace', function () {
    $user = User::factory()->create();

    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    $team1 = Team::factory()->for($org1)->create();
    $team2 = Team::factory()->for($org2)->create();

    $role1 = Role::factory()->for($team1)->create(['name' => 'Role A']);
    $role2 = Role::factory()->for($team2)->create(['name' => 'Role B']);

    $workspace = Workspace::factory()->create();

    $org1->workspaces()->attach($workspace);
    $org2->workspaces()->attach($workspace);

    $user->roles()->attach([$role1->id, $role2->id]);

    $result = $user->getAccessibleWorkspaces();

    expect($result)->toHaveCount(1);
    expect($result[0]['roles'])->toHaveCount(2);
    expect($result[0]['roles']->pluck('name')->sort()->values()->all())->toEqual(['Role A', 'Role B']);
});
