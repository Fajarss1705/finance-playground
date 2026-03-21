<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActiveSessionService;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Str;

beforeEach(function () {
    app(Vite::class)->useScriptTagAttributes([])->useBuildDirectory('build');
    $this->withoutVite();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('personal.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with active session and permission can visit the dashboard', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permission = Permission::create(['name' => 'personal.index']);
    $role->permissions()->attach($permission);

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $response = $this->get(route('personal.index'));
    $response->assertOk();
});

test('authenticated users without active session are redirected to role-selector', function () {
    $user = User::factory()->withRole()->create();
    $this->actingAs($user);

    $response = $this->get(route('personal.index'));
    $response->assertRedirect(route('role-selector.index'));
});

// --- Personal Dashboard Task Tests ---

function setupDashboardUser(string ...$permissionNames): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;

    // Always grant personal.index so the route doesn't 403
    $allPerms = array_unique(array_merge(['personal.index'], $permissionNames));

    foreach ($allPerms as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace, $team];
}

function addSecondRole(User $user, Workspace $workspace, string $roleName, string ...$permissionNames): array
{
    $organization = $workspace->organizations()->first();
    $team = Team::factory()->for($organization)->create(['name' => 'Tim Bendahara Umum']);
    $role = Role::factory()->for($team)->create(['name' => $roleName]);
    $user->roles()->attach($role);

    foreach ($permissionNames as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$role, $team];
}

function activateDashboardSession(object $test, User $user, Role $role, Workspace $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function createCompletedPpWorkflow(int $workspaceId): PpWorkflow
{
    return PpWorkflow::create([
        'workspace_id' => $workspaceId,
        'history' => [
            ['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspaceId, 'at' => now()->subMonth()->toIso8601String()],
        ],
    ]);
}

function createPabdWorkflow(int $workspaceId, int $teamId, array $history, int $bulan = 4, int $tahun = 2026): PabdWorkflow
{
    $ppWorkflow = createCompletedPpWorkflow($workspaceId);

    return PabdWorkflow::create([
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspaceId,
        'team_id' => $teamId,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => $bulan,
        'tahun_anggaran' => $tahun,
        'history' => $history,
    ]);
}

function createPrblWorkflow(int $workspaceId, int $teamId, array $history, int $bulan = 3, int $tahun = 2026): PrblWorkflow
{
    $ppWorkflow = createCompletedPpWorkflow($workspaceId);

    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspaceId,
        'team_id' => $teamId,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => $bulan,
        'tahun_anggaran' => 2026,
        'history' => [
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspaceId, 'at' => now()->subWeek()->toIso8601String()],
        ],
    ]);

    return PrblWorkflow::create([
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspaceId,
        'team_id' => $teamId,
        'pabd_workflow_id' => $pabdWorkflow->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => $bulan,
        'tahun_laporan' => $tahun,
        'history' => $history,
    ]);
}

test('bapel role sees PABD team-scope tasks', function () {
    [$user, $role, $workspace, $team] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );

    $pabd = createPabdWorkflow($workspace->id, $team->id, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
    ]);

    Pabd01Data::create(['pabd_workflow_id' => $pabd->id]);

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('personal/index')
        ->has('activeRoleTasks', 1)
        ->where('activeRoleTasks.0.stepCode', 'PABD01')
        ->where('activeRoleTasks.0.stepName', 'Konfirmasi Anggaran')
        ->where('activeRoleTasks.0.statusHint', 'Menunggu diisi')
        ->where('activeRoleTasks.0.workflowType', 'pabd')
    );
});

test('admin role sees PABD admin-scope tasks', function () {
    [$user, $role, $workspace, $team] = setupDashboardUser(
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.pabd03.reject',
    );

    $org = $role->team->organization;
    $otherTeam = Team::factory()->for($org)->create(['name' => 'Divisi Pendidikan']);

    createPabdWorkflow($workspace->id, $otherTeam->id, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $otherTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(5)->toIso8601String()],
        ['step' => 'PABD01', 'action' => 'submitted', 'by' => 1, 'role' => null, 'team' => $otherTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(4)->toIso8601String()],
        ['step' => 'PABD02A', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(3)->toIso8601String()],
        ['step' => 'PABD02B', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(2)->toIso8601String()],
    ]);

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('personal/index')
        ->has('activeRoleTasks', 1)
        ->where('activeRoleTasks.0.stepCode', 'PABD03')
        ->where('activeRoleTasks.0.statusHint', 'Menunggu review')
    );
});

test('user with both roles sees active role tasks in section 1 and other roles in section 2', function () {
    [$user, $bapelRole, $workspace, $bapelTeam] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );

    [$buRole] = addSecondRole(
        $user,
        $workspace,
        'Bendahara Umum 1',
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.pabd03.reject',
    );

    // PABD at PABD01 for bapel team
    createPabdWorkflow($workspace->id, $bapelTeam->id, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $bapelTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(5)->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
    ]);

    // Another PABD at PABD03 for BU review
    $org = $bapelRole->team->organization;
    $anotherTeam = Team::factory()->for($org)->create(['name' => 'Divisi Kepemudaan']);

    createPabdWorkflow($workspace->id, $anotherTeam->id, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $anotherTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(10)->toIso8601String()],
        ['step' => 'PABD01', 'action' => 'submitted', 'by' => 1, 'role' => null, 'team' => $anotherTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(8)->toIso8601String()],
        ['step' => 'PABD02A', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(7)->toIso8601String()],
        ['step' => 'PABD02B', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(6)->toIso8601String()],
    ], 5, 2026);

    activateDashboardSession($this, $user, $bapelRole, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('personal/index')
        ->has('activeRoleTasks', 1)
        ->where('activeRoleTasks.0.stepCode', 'PABD01')
        ->has('otherRoles', 1)
        ->where('otherRoles.0.name', 'Bendahara Umum 1')
        ->has('otherRoles.0.tasks', 1)
        ->where('otherRoles.0.tasks.0.stepCode', 'PABD03')
    );
});

test('draft detection shows Draft status hint', function () {
    [$user, $role, $workspace, $team] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );

    createPabdWorkflow($workspace->id, $team->id, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(5)->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
        ['step' => 'PABD01', 'action' => 'drafted', 'by' => $user->id, 'role' => $role->id, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(3)->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
    ]);

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeRoleTasks', 1)
        ->where('activeRoleTasks.0.statusHint', 'Draft')
    );
});

test('empty state returns no tasks', function () {
    [$user, $role, $workspace] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
    );

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('personal/index')
        ->has('activeRoleTasks', 0)
        ->where('activeRole.id', $role->id)
    );
});

test('ganti kerjakan switches role and redirects to step page', function () {
    [$user, $role, $workspace] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
    );

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->post('/role-selector', [
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
        'redirect_to' => '/team/workflows/pabd/1/pabd01/1',
    ]);

    $response->assertRedirect('/team/workflows/pabd/1/pabd01/1');
    expect(session('active_role_id'))->toBe($role->id);
});

test('ganti kerjakan without redirect_to goes to personal index', function () {
    [$user, $role, $workspace] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
    );

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->post('/role-selector', [
        'role_id' => $role->id,
        'workspace_id' => $workspace->id,
    ]);

    $response->assertRedirect(route('personal.index'));
});

test('PRBL team-scope tasks appear for bapel role', function () {
    [$user, $role, $workspace, $team] = setupDashboardUser(
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.submit',
    );

    createPrblWorkflow($workspace->id, $team->id, [
        ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'prbl01_data', 'id' => 1],
    ]);

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeRoleTasks', 1)
        ->where('activeRoleTasks.0.stepCode', 'PRBL01')
        ->where('activeRoleTasks.0.stepName', 'Laporan Realisasi')
        ->where('activeRoleTasks.0.workflowType', 'prbl')
    );
});

test('parallel steps appear as separate task items', function () {
    [$user, $role, $workspace, $team] = setupDashboardUser(
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.prbl02a.reject',
        'admin.workflows.prbl.prbl02b.approve',
        'admin.workflows.prbl.prbl02b.reject',
    );

    $org = $role->team->organization;
    $bapelTeam = Team::factory()->for($org)->create(['name' => 'Divisi Kaderisasi']);

    createPrblWorkflow($workspace->id, $bapelTeam->id, [
        ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $bapelTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(5)->toIso8601String()],
        ['step' => 'PRBL01', 'action' => 'submitted', 'by' => 1, 'role' => null, 'team' => $bapelTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(3)->toIso8601String()],
    ]);

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeRoleTasks', 2)
        ->where('activeRoleTasks.0.stepCode', 'PRBL02A')
        ->where('activeRoleTasks.1.stepCode', 'PRBL02B')
    );
});

test('completed workflows do not appear as tasks', function () {
    [$user, $role, $workspace, $team] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );

    createPabdWorkflow($workspace->id, $team->id, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(10)->toIso8601String()],
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinute()->toIso8601String()],
    ]);

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeRoleTasks', 0)
    );
});

test('rejection re-entry shows Dikembalikan status', function () {
    [$user, $role, $workspace, $team] = setupDashboardUser(
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );

    createPabdWorkflow($workspace->id, $team->id, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(20)->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
        ['step' => 'PABD01', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(18)->toIso8601String()],
        ['step' => 'PABD02A', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(17)->toIso8601String()],
        ['step' => 'PABD03', 'action' => 'rejected', 'by' => 999, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(10)->toIso8601String()],
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(9)->toIso8601String(), 'table' => 'pabd01_data', 'id' => 2],
    ]);

    activateDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('personal.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeRoleTasks', 1)
        ->where('activeRoleTasks.0.statusHint', 'Dikembalikan')
    );
});
