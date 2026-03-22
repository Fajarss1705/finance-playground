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
use Illuminate\Support\Str;

beforeEach(function () {
    app(Vite::class)->useScriptTagAttributes([])->useBuildDirectory('build');
    $this->withoutVite();
});

// --- Helpers ---

function setupTeamUser(string ...$permissionNames): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;

    $allPerms = array_unique(array_merge(['team.index'], $permissionNames));

    foreach ($allPerms as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace, $team];
}

function activateTeamDashboardSession(object $test, User $user, Role $role, Workspace $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function pp06AuthorDefaults(): array
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

function createPp06WithPlafon(int $workspaceId, int $teamId, int $tahun = 2026, float $plafon = 45000000): array
{
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspaceId,
        'history' => [
            ['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspaceId, 'at' => now()->subMonth()->toIso8601String()],
        ],
    ]);

    $pp06 = Pp06PeriodeTahunan::create(array_merge(pp06AuthorDefaults(), [
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

function pkCreatorDefaults(User $user, Role $role): array
{
    return [
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $role->team_id,
        'created_by_org_id' => $role->team->organization_id,
    ];
}

function createPkWithPk04(int $workspaceId, int $teamId, int $ppWorkflowId, bool $completed = true, ?User $user = null, ?Role $role = null): array
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

    $pkWorkflow = PkWorkflow::create(array_merge(pkCreatorDefaults($user, $role), [
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspaceId,
        'team_id' => $teamId,
        'pp_workflow_id' => $ppWorkflowId,
        'tipe' => 'raker',
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

function createPk04Anggaran(int $pk04Id, int $bulan, float $nominal = 5000000): Pk04Anggaran
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

function createTeamPabdWorkflow(int $workspaceId, int $teamId, int $ppWorkflowId, int $bulan, array $history): PabdWorkflow
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

function createTeamPrblWorkflow(int $workspaceId, int $teamId, int $ppWorkflowId, int $pabdWorkflowId, int $bulan, array $history): PrblWorkflow
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

// --- Auth & Permission Tests ---

test('guests are redirected to the login page', function () {
    $response = $this->get(route('team.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with team.index permission can visit the dashboard', function () {
    [$user, $role, $workspace] = setupTeamUser();

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('team/index'));
});

test('authenticated users without team.index permission get 403', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $this->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);

    $response = $this->get(route('team.index'));
    $response->assertForbidden();
});

// --- No PP06 empty state ---

test('no PP06 shows empty state with no year selector', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('team/index')
        ->where('team.name', $team->name)
        ->where('year', null)
        ->where('availableYears', [])
        ->where('pkStatus', null)
        ->where('calendar', [])
        ->where('activeWorkflows', [])
    );
});

// --- Year selector ---

test('year selector returns available PP06 years', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    createPp06WithPlafon($workspace->id, $team->id, 2025);
    createPp06WithPlafon($workspace->id, $team->id, 2026);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('year', 2026)
        ->where('availableYears', [2026, 2025])
    );
});

test('year selector filters data by selected year', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    createPp06WithPlafon($workspace->id, $team->id, 2025, 30000000);
    createPp06WithPlafon($workspace->id, $team->id, 2026, 45000000);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index', ['year' => 2025]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('year', 2025)
        ->where('pkStatus.plafon', fn ($v) => (float) $v === 30000000.0)
    );
});

test('invalid year falls back to latest', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    createPp06WithPlafon($workspace->id, $team->id, 2026);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index', ['year' => 9999]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('year', 2026));
});

// --- PK Status Bar ---

test('PK status shows belum_ada when no PK workflow', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    createPp06WithPlafon($workspace->id, $team->id);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('pkStatus.rakerStatus', 'belum_ada')
        ->where('pkStatus.plafon', fn ($v) => (float) $v === 45000000.0)
    );
});

test('PK status shows selesai when PK04 completed', function () {
    [$user, $role, $workspace, $team] = setupTeamUser(
        'team.workflows.pk.pk01.draft',
    );

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id, true);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('pkStatus.rakerStatus', 'selesai')
        ->where('pkStatus.rakerRevision', 0)
    );
});

test('PK status shows aktif with current step when PK in progress', function () {
    [$user, $role, $workspace, $team] = setupTeamUser(
        'team.workflows.pk.pk01.draft',
    );

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id, false);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('pkStatus.rakerStatus', 'aktif')
        ->where('pkStatus.rakerCurrentStep', 'PK01')
    );
});

test('PK status shows proposal count', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);

    // Create 2 proposal workflows
    PkWorkflow::create(array_merge(pkCreatorDefaults($user, $role), [
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'proposal',
        'history' => [
            ['step' => 'PK04', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]));
    PkWorkflow::create(array_merge(pkCreatorDefaults($user, $role), [
        'uuid' => Str::uuid()->toString(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'proposal',
        'history' => [
            ['step' => 'PK04', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]));

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('pkStatus.proposalCount', 2)
    );
});

test('no plafon shows tidak_tersedia status', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    // Create PP06 without plafon for this team
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMonth()->toIso8601String()],
        ],
    ]);
    Pp06PeriodeTahunan::create(array_merge(pp06AuthorDefaults(), [
        'pp_workflow_id' => $ppWorkflow->id,
        'tahun' => 2026,
        'revision' => 0,
    ]));

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('pkStatus.rakerStatus', 'tidak_tersedia')
        ->where('pkStatus.plafon', 0)
    );
});

// --- Calendar Cell States ---

test('calendar shows belum for months with budget but no workflow', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id);

    createPk04Anggaran($pk04->id, 3, 5000000);
    createPk04Anggaran($pk04->id, 6, 3000000);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('calendar', 12)
        ->where('calendar.2.bulan', 3)
        ->where('calendar.2.pabd.state', 'belum')
        ->where('calendar.2.prbl.state', 'belum')
        ->where('calendar.5.bulan', 6)
        ->where('calendar.5.pabd.state', 'belum')
    );
});

test('calendar shows skip for months with no budget', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id);

    createPk04Anggaran($pk04->id, 3, 5000000);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('calendar.0.bulan', 1)
        ->where('calendar.0.pabd.state', 'skip')
        ->where('calendar.0.prbl.state', 'skip')
    );
});

test('calendar shows aktif for active workflows', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id);
    createPk04Anggaran($pk04->id, 4, 5000000);

    createTeamPabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 4, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
    ]);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('calendar.3.bulan', 4)
        ->where('calendar.3.pabd.state', 'aktif')
        ->where('calendar.3.pabd.currentStep', 'PABD01')
    );
});

test('calendar shows selesai for completed workflows', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id);
    createPk04Anggaran($pk04->id, 1, 5000000);

    createTeamPabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 1, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMonths(2)->toIso8601String()],
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMonth()->toIso8601String()],
    ]);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('calendar.0.bulan', 1)
        ->where('calendar.0.pabd.state', 'selesai')
        ->where('calendar.0.pabd.url', fn ($url) => str_contains($url, '/workflows/pabd/'))
    );
});

// --- Anggaran Table ---

test('anggaran table shows direncanakan from pk04', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id);

    createPk04Anggaran($pk04->id, 3, 7000000);
    createPk04Anggaran($pk04->id, 3, 3000000);
    createPk04Anggaran($pk04->id, 6, 2000000);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('anggaranRows.2.bulan', 3)
        ->where('anggaranRows.2.direncanakan', fn ($v) => (float) $v === 10000000.0)
        ->where('anggaranRows.5.bulan', 6)
        ->where('anggaranRows.5.direncanakan', fn ($v) => (float) $v === 2000000.0)
        ->where('anggaranRows.0.direncanakan', fn ($v) => (float) $v === 0.0)
        ->where('anggaranTotals.direncanakan', fn ($v) => (float) $v === 12000000.0)
    );
});

test('anggaran table shows null for months without PABD05/PRBL05', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    [, $pk04] = createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id);
    createPk04Anggaran($pk04->id, 4, 5000000);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('anggaranRows.3.dicairkan', null)
        ->where('anggaranRows.3.realisasi', null)
        ->where('anggaranRows.3.refund', null)
    );
});

// --- Active Workflows ---

test('active workflows list shows PABD at current step with canAct', function () {
    [$user, $role, $workspace, $team] = setupTeamUser(
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);

    $pabd = createTeamPabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 4, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
    ]);

    \App\Models\Pabd\Pabd01Data::create(['pabd_workflow_id' => $pabd->id]);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 1)
        ->where('activeWorkflows.0.workflowType', 'pabd')
        ->where('activeWorkflows.0.canAct', true)
        ->where('activeWorkflows.0.currentSteps.0.stepCode', 'PABD01')
        ->where('activeWorkflows.0.currentSteps.0.stepName', 'Konfirmasi Anggaran')
    );
});

test('active workflows shows Lihat when role cannot act', function () {
    [$user, $role, $workspace, $team] = setupTeamUser(
        'team.workflows.pabd.pabd01.view',
    );

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);

    createTeamPabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 4, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
    ]);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 1)
        ->where('activeWorkflows.0.canAct', false)
        ->where('activeWorkflows.0.actionUrl', null)
    );
});

test('completed workflows are excluded from active list', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);

    createTeamPabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 1, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMonths(2)->toIso8601String()],
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subMonth()->toIso8601String()],
    ]);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('activeWorkflows', [])
    );
});

test('active PK raker appears in active workflows', function () {
    [$user, $role, $workspace, $team] = setupTeamUser(
        'team.workflows.pk.pk01.draft',
        'team.workflows.pk.pk01.submit',
    );

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);
    createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id, false);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 1)
        ->where('activeWorkflows.0.workflowType', 'pk')
        ->where('activeWorkflows.0.canAct', true)
        ->where('activeWorkflows.0.currentSteps.0.stepCode', 'PK01')
    );
});

test('active workflows sorted PABD first then PRBL then PK', function () {
    [$user, $role, $workspace, $team] = setupTeamUser(
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.pk.pk01.draft',
    );

    [$ppWorkflow] = createPp06WithPlafon($workspace->id, $team->id);

    // PK (active)
    createPkWithPk04($workspace->id, $team->id, $ppWorkflow->id, false);

    // PABD (active, bulan 5)
    createTeamPabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 5, [
        ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pabd01_data', 'id' => 1],
    ]);

    // PRBL (active, bulan 3)
    $completedPabd = createTeamPabdWorkflow($workspace->id, $team->id, $ppWorkflow->id, 3, [
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subWeek()->toIso8601String()],
    ]);
    createTeamPrblWorkflow($workspace->id, $team->id, $ppWorkflow->id, $completedPabd->id, 3, [
        ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'prbl01_data', 'id' => 1],
    ]);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    // PABD(bulan=5) and PRBL(bulan=3) have same sort priority (1), ordered by bulan asc
    // So PRBL(3) before PABD(5), then PK
    $response->assertInertia(fn ($page) => $page
        ->has('activeWorkflows', 3)
        ->where('activeWorkflows.0.workflowType', 'prbl')
        ->where('activeWorkflows.1.workflowType', 'pabd')
        ->where('activeWorkflows.2.workflowType', 'pk')
    );
});

// --- Empty states ---

test('empty calendar and anggaran when no PK04', function () {
    [$user, $role, $workspace, $team] = setupTeamUser();

    createPp06WithPlafon($workspace->id, $team->id);

    activateTeamDashboardSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('calendar', 12)
        ->where('calendar.0.pabd.state', 'skip')
        ->where('calendar.0.prbl.state', 'skip')
        ->where('anggaranRows.0.direncanakan', fn ($v) => (float) $v === 0.0)
        ->where('anggaranTotals.direncanakan', fn ($v) => (float) $v === 0.0)
        ->where('activeWorkflows', [])
    );
});
