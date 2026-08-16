<?php

use App\Models\Organization;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06ItemPlafonAnggaran;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActiveSessionService;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    app(Vite::class)->useScriptTagAttributes([])->useBuildDirectory('build');
    $this->withoutVite();
});

// --- Helpers ---

function setupAdminUser(string ...$permissionNames): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;

    $allPerms = array_unique(array_merge(['admin.index'], $permissionNames));

    foreach ($allPerms as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace, $team];
}

function activateAdminSession(object $test, User $user, Role $role, Workspace $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function adminPp06AuthorDefaults(): array
{
    $defaults = [
        'tanggal_mulai_pra_raker' => now()->subMonths(3)->toDateString(),
        'tanggal_penetapan_program' => now()->subMonths(2)->toDateString(),
    ];
    foreach (['pp01', 'pp02', 'pp03', 'pp04', 'pp05'] as $step) {
        $defaults["{$step}_created_by_user_name"] = 'Test User';
        $defaults["{$step}_created_by_role_name"] = 'Test Role';
        $defaults["{$step}_created_by_organization_name"] = 'Test Org';
        $defaults["{$step}_created_by_workspace_name"] = 'Test Workspace';
        $defaults["{$step}_created_at"] = now()->subMonth();
    }

    return $defaults;
}

function adminCreatePp06WithPlafon(int $workspaceId, int $teamId, int $tahun = 2026, float $plafon = 45000000): array
{
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspaceId,
        'history' => [
            ['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspaceId, 'at' => now()->subMonth()->toIso8601String()],
        ],
    ]);

    $pp06 = Pp06PeriodeTahunan::create(array_merge(adminPp06AuthorDefaults(), [
        'pp_workflow_id' => $ppWorkflow->id,
        'tahun' => $tahun,
        'revision' => 0,
    ]));

    $pp06Plafon = Pp06ItemPlafonAnggaran::create([
        'pp06_periode_tahunan_id' => $pp06->id,
        'team_id' => $teamId,
        'kode_team' => '01',
        'plafon_anggaran' => $plafon,
        'nama_bank' => 'Bank Test',
        'nama_rekening' => 'Test Account',
        'nomor_rekening' => '1234567890',
    ]);

    return [$ppWorkflow, $pp06, $pp06Plafon];
}

function adminPkCreatorDefaults(User $user, Role $role): array
{
    return [
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $role->team_id,
        'created_by_org_id' => $role->team->organization_id,
    ];
}

function adminCreatePkWithPk04(int $workspaceId, int $teamId, int $ppWorkflowId, bool $completed = true, string $tipe = 'raker', ?User $user = null, ?Role $role = null): array
{
    if (! $user || ! $role) {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $dummyTeam = Team::factory()->for($org)->create();
        $role = Role::factory()->for($dummyTeam)->create();
    }

    $history = [
        ['step' => 'PK01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $teamId, 'org' => null, 'workspace' => $workspaceId, 'at' => now()->subMonths(2)->toIso8601String()],
    ];

    if ($completed) {
        $history[] = ['step' => 'PK04', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspaceId, 'at' => now()->subMonth()->toIso8601String()];
    }

    $pkWorkflow = PkWorkflow::create(array_merge(adminPkCreatorDefaults($user, $role), [
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspaceId,
        'team_id' => $teamId,
        'pp_workflow_id' => $ppWorkflowId,
        'tipe' => $tipe,
        'history' => $history,
    ]));

    $pk04 = Pk04ProgramTahunan::create([
        'pk_workflow_id' => $pkWorkflow->id,
        'revision' => 0,
        'pk01_created_by_user_name' => 'Test User',
        'pk01_created_by_role_name' => 'Test Role',
        'pk01_created_by_team_name' => 'Test Team',
        'pk01_created_by_organization_name' => 'Test Org',
        'pk01_created_by_workspace_name' => 'Test Workspace',
        'pk01_created_at' => now()->subMonth(),
        'kode_kategori' => '01',
        'nama_program' => 'Program Test',
        'deskripsi_program' => 'Deskripsi test',
        'tujuan_program' => 'Tujuan test',
        'nomer_program' => 1,
    ]);

    return [$pkWorkflow, $pk04];
}

function adminCreatePk04Anggaran(int $pk04Id, int $bulan, float $nominal = 5000000): Pk04Anggaran
{
    $kegiatan = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04Id,
        'nama_kegiatan' => "Kegiatan bulan {$bulan}",
        'bulan' => $bulan,
        'nomer_kegiatan' => 1,
    ]);

    return Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => '01',
        'kode_sub_bidang' => '01',
        'kode_jenis' => '01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Deskripsi test',
        'nominal_anggaran' => $nominal,
        'nomer_anggaran' => 1,
        'status_item' => 'active',
    ]);
}

function adminCreatePabdWorkflow(int $workspaceId, int $teamId, int $ppWorkflowId, int $bulan, array $history): PabdWorkflow
{
    return PabdWorkflow::create([
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspaceId,
        'team_id' => $teamId,
        'pp_workflow_id' => $ppWorkflowId,
        'bulan_anggaran' => $bulan,
        'tahun_anggaran' => 2026,
        'history' => $history,
    ]);
}

function adminCreatePrblWorkflow(int $workspaceId, int $teamId, int $ppWorkflowId, int $pabdWorkflowId, int $bulan, array $history): PrblWorkflow
{
    return PrblWorkflow::create([
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspaceId,
        'team_id' => $teamId,
        'pabd_workflow_id' => $pabdWorkflowId,
        'pp_workflow_id' => $ppWorkflowId,
        'bulan_laporan' => $bulan,
        'tahun_laporan' => 2026,
        'history' => $history,
    ]);
}

function addTeamWithPlafon(Organization $org, int $pp06Id, string $name, string $kode, float $plafon): array
{
    $team = Team::factory()->for($org)->create(['name' => $name]);
    $pp06Plafon = Pp06ItemPlafonAnggaran::create([
        'pp06_periode_tahunan_id' => $pp06Id,
        'team_id' => $team->id,
        'kode_team' => $kode,
        'plafon_anggaran' => $plafon,
        'nama_bank' => 'Bank Test',
        'nama_rekening' => 'Test Account',
        'nomor_rekening' => '1234567890',
    ]);

    return [$team, $pp06Plafon];
}

function snapshotAuthorDefaults(string $prefix): array
{
    $defaults = [];
    $defaults["{$prefix}_user_name"] = 'Test User';
    $defaults["{$prefix}_role_name"] = 'Test Role';
    $defaults["{$prefix}_team_name"] = 'Test Team';
    $defaults["{$prefix}_organization_name"] = 'Test Org';
    $defaults["{$prefix}_workspace_name"] = 'Test Workspace';

    return $defaults;
}

function snapshotAuthorTimestamp(string $prefix): array
{
    return ["{$prefix}_at" => now()->subWeek()];
}

function pabd05SnapshotDefaults(): array
{
    return array_merge(
        snapshotAuthorDefaults('pabd01_created_by'),
        snapshotAuthorTimestamp('pabd01_created'),
        snapshotAuthorDefaults('pabd03_approved_by'),
        snapshotAuthorTimestamp('pabd03_approved'),
        snapshotAuthorDefaults('pabd04_created_by'),
        snapshotAuthorTimestamp('pabd04_created'),
    );
}

function prbl05SnapshotDefaults(): array
{
    return array_merge(
        snapshotAuthorDefaults('prbl01_created_by'),
        snapshotAuthorTimestamp('prbl01_created'),
        snapshotAuthorDefaults('prbl02a_approved_by'),
        snapshotAuthorTimestamp('prbl02a_approved'),
        snapshotAuthorDefaults('prbl02b_approved_by'),
        snapshotAuthorTimestamp('prbl02b_approved'),
        snapshotAuthorDefaults('prbl03_created_by'),
        snapshotAuthorTimestamp('prbl03_created'),
        snapshotAuthorDefaults('prbl04_approved_by'),
        snapshotAuthorTimestamp('prbl04_approved'),
    );
}

// --- Auth & Permission Tests ---

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with admin.index permission can visit the dashboard', function () {
    [$user, $role, $workspace] = setupAdminUser();

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/index'));
});

test('authenticated users without admin.index permission get 403', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $response = $this->get(route('admin.index'));
    $response->assertForbidden();
});

// --- No PP06 empty state ---

test('no PP06 shows empty state', function () {
    [$user, $role, $workspace] = setupAdminUser();

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/index')
        ->where('year', null)
        ->where('availableYears', [])
        ->where('activeWorkflows', [])
        ->where('teamSummaries', [])
        ->where('teamOptions', [])
        ->where('monthlyData', [])
    );
});

// --- Year selector ---

test('year selector returns available PP06 years', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    adminCreatePp06WithPlafon($workspace->id, $team->id, 2025);
    adminCreatePp06WithPlafon($workspace->id, $team->id, 2026);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('year', 2026)
        ->where('availableYears', [2026, 2025])
    );
});

test('year selector filters data by selected year', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    adminCreatePp06WithPlafon($workspace->id, $team->id, 2025, 30000000);
    adminCreatePp06WithPlafon($workspace->id, $team->id, 2026, 45000000);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index', ['year' => 2025]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('year', 2025)
        ->where('teamSummaries.0.plafon', fn ($v) => (float) $v === 30000000.0)
    );
});

test('invalid year falls back to latest', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    adminCreatePp06WithPlafon($workspace->id, $team->id, 2026);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index', ['year' => 9999]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('year', 2026));
});

// --- Per-Team Summary ---

test('team summary shows plafon for each team', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();
    $org = $role->team->organization;

    [$ppWorkflow, $pp06] = adminCreatePp06WithPlafon($workspace->id, $team->id, 2026, 45000000);
    [$teamB] = addTeamWithPlafon($org, $pp06->id, 'Divisi Kepemasaranan', '02', 30000000);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('teamSummaries', 2)
    );
});

test('team summary shows PK raker and proposal totals', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);
    [, $pk04Raker] = adminCreatePkWithPk04($workspace->id, $team->id, $ppWorkflow->id, true, 'raker');
    [, $pk04Proposal] = adminCreatePkWithPk04($workspace->id, $team->id, $ppWorkflow->id, true, 'proposal');

    adminCreatePk04Anggaran($pk04Raker->id, 3, 7000000);
    adminCreatePk04Anggaran($pk04Proposal->id, 3, 2000000);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('teamSummaries.0.totalPkRaker', fn ($v) => (float) $v === 7000000.0)
        ->where('teamSummaries.0.totalPkProposal', fn ($v) => (float) $v === 2000000.0)
    );
});

test('team summary shows PABD progress fraction', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = adminCreatePkWithPk04($workspace->id, $team->id, $ppWorkflow->id);

    adminCreatePk04Anggaran($pk04->id, 3, 5000000);
    adminCreatePk04Anggaran($pk04->id, 6, 3000000);

    // Completed PABD for bulan 3
    adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 3, [
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subWeek()->toIso8601String()],
    ]);

    // Active PABD for bulan 6
    adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 6, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('teamSummaries.0.pabdCompleted', 1)
        ->where('teamSummaries.0.pabdTotal', 2)
    );
});

test('team summary shows dash for PABD/PRBL when no PK04', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    adminCreatePp06WithPlafon($workspace->id, $team->id);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('teamSummaries.0.pabdTotal', 0)
        ->where('teamSummaries.0.prblTotal', 0)
    );
});

test('team summary realisasi from PRBL05', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = adminCreatePkWithPk04($workspace->id, $team->id, $ppWorkflow->id);
    adminCreatePk04Anggaran($pk04->id, 3, 5000000);

    $completedPabd = adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 3, [
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subWeek()->toIso8601String()],
    ]);

    $prblWf = adminCreatePrblWorkflow($workspace->id, $team->id, $ppWorkflow->id, $completedPabd->id, 3, [
        ['step' => 'PRBL05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
    ]);

    // Create PRBL05 data
    DB::table('prbl05_laporan_bulanan')->insert(array_merge(prbl05SnapshotDefaults(), [
        'prbl_workflow_id' => $prblWf->id,
        'total_anggaran_dicairkan' => 5000000,
        'total_realisasi' => 4500000,
        'total_refund' => 500000,
        'total_item' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('teamSummaries.0.totalRealisasi', fn ($v) => (float) $v === 4500000.0)
    );
});

// --- Active Workflows (Work Queue) ---

test('active PABD workflow appears in work queue', function () {
    [$user, $role, $workspace, $team] = setupAdminUser(
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.pabd03.reject',
    );

    $org = $role->team->organization;

    [$ppWorkflow, $pp06] = adminCreatePp06WithPlafon($workspace->id, $team->id);
    [$teamB] = addTeamWithPlafon($org, $pp06->id, 'Divisi Pendidikan', '02', 30000000);

    // Active PABD at PABD03
    adminCreatePabdWorkflow($workspace->id, $teamB->id, $ppWorkflow->id, 4, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $teamB->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(10)->toIso8601String()],
        ['step' => 'PABD01', 'action' => 'submitted', 'by' => 1, 'role' => null, 'team' => $teamB->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(8)->toIso8601String()],
        ['step' => 'PABD02A', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(7)->toIso8601String()],
        ['step' => 'PABD02B', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(6)->toIso8601String()],
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 1)
        ->where('activeWorkflows.0.workflowType', 'pabd')
        ->where('activeWorkflows.0.canAct', true)
        ->where('activeWorkflows.0.currentSteps.0.stepCode', 'PABD03')
        ->where('activeWorkflows.0.teamName', 'Divisi Pendidikan')
    );
});

test('completed workflows are excluded from active list', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 1, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMonths(2)->toIso8601String()],
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMonth()->toIso8601String()],
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('activeWorkflows', [])
    );
});

test('canAct false when role has no actionable permission', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 4, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 1)
        ->where('activeWorkflows.0.canAct', false)
        ->where('activeWorkflows.0.actionUrl', null)
    );
});

test('actionable items sorted before non-actionable', function () {
    [$user, $role, $workspace, $team] = setupAdminUser(
        'admin.workflows.pabd.pabd03.approve',
    );

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    // Non-actionable (at PABD01)
    adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 3, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
    ]);

    // Actionable (at PABD03)
    adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 5, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(10)->toIso8601String()],
        ['step' => 'PABD01', 'action' => 'submitted', 'by' => 1, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(8)->toIso8601String()],
        ['step' => 'PABD02A', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(7)->toIso8601String()],
        ['step' => 'PABD02B', 'action' => 'skipped', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(6)->toIso8601String()],
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 2)
        ->where('activeWorkflows.0.canAct', true)
        ->where('activeWorkflows.0.currentSteps.0.stepCode', 'PABD03')
        ->where('activeWorkflows.1.canAct', false)
        ->where('activeWorkflows.1.currentSteps.0.stepCode', 'PABD01')
    );
});

test('PP workflow shows in work queue with null team', function () {
    [$user, $role, $workspace, $team] = setupAdminUser(
        'admin.workflows.pp.pp01.draft',
    );

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    // Create active PP workflow
    $activePp = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
        ],
    ]);

    \App\Models\Pp\Pp01Data::create([
        'pp_workflow_id' => $activePp->id,
        'tahun' => 2027,
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('activeWorkflows.0.teamId', null)
        ->where('activeWorkflows.0.teamName', null)
        ->where('activeWorkflows.0.workflowType', 'pp')
    );
});

test('parallel steps show both step codes in work queue', function () {
    [$user, $role, $workspace, $team] = setupAdminUser(
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.prbl02b.approve',
    );

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    $completedPabd = adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 3, [
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subWeek()->toIso8601String()],
    ]);

    adminCreatePrblWorkflow($workspace->id, $team->id, $ppWorkflow->id, $completedPabd->id, 3, [
        ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(5)->toIso8601String()],
        ['step' => 'PRBL01', 'action' => 'submitted', 'by' => 1, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMinutes(3)->toIso8601String()],
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 1)
        ->has('activeWorkflows.0.currentSteps', 2)
        ->where('activeWorkflows.0.currentSteps.0.stepCode', 'PRBL02A')
        ->where('activeWorkflows.0.currentSteps.1.stepCode', 'PRBL02B')
    );
});

// --- Monthly Financial Data ---

test('monthly data includes per-team per-month rows', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = adminCreatePkWithPk04($workspace->id, $team->id, $ppWorkflow->id);

    adminCreatePk04Anggaran($pk04->id, 3, 7000000);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        // 1 team × 12 months = 12 rows
        ->has('monthlyData', 12)
        ->has('teamOptions', 1)
    );
});

test('monthly data dicairkan from PABD05', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    $pabd = adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 3, [
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
    ]);

    DB::table('pabd05_pengajuan_bulanan')->insert(array_merge(pabd05SnapshotDefaults(), [
        'pabd_workflow_id' => $pabd->id,
        'total_anggaran_dicairkan' => 6000000,
        'total_item_dicairkan' => 1,
        'total_item_hangus' => 0,
        'nama_bank' => 'Bank Test',
        'nama_rekening' => 'Test Account',
        'nomor_rekening' => '1234567890',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    // Bulan 3 row for this team
    $response->assertInertia(fn ($page) => $page
        ->where('monthlyData.2.bulan', 3)
        ->where('monthlyData.2.dicairkan', fn ($v) => (float) $v === 6000000.0)
    );
});

test('monthly data null dicairkan when no PABD05', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('monthlyData.0.dicairkan', null)
        ->where('monthlyData.0.realisasi', null)
        ->where('monthlyData.0.refund', null)
    );
});

// --- Empty states ---

test('empty active workflows when all completed', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();

    [$ppWorkflow] = adminCreatePp06WithPlafon($workspace->id, $team->id);

    adminCreatePabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 1, [
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
    ]);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('activeWorkflows', [])
    );
});

test('team summaries sorted alphabetically', function () {
    [$user, $role, $workspace, $team] = setupAdminUser();
    $org = $role->team->organization;

    [$ppWorkflow, $pp06] = adminCreatePp06WithPlafon($workspace->id, $team->id, 2026, 45000000);

    // Add team with name that sorts before the first team
    [$teamA] = addTeamWithPlafon($org, $pp06->id, 'Anak Divisi', '02', 30000000);

    activateAdminSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('teamSummaries', 2)
        ->where('teamSummaries.0.teamName', 'Anak Divisi')
    );
});
