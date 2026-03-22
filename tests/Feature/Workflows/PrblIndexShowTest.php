<?php

use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\PrblWorkflow;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\WorkflowEngine;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
});

function setupPrblIndexUser(string ...$permissionNames): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;

    foreach ($permissionNames as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace, $team];
}

function activatePrblIndexSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function pp06AuthorSnapshotIndex(): array
{
    $snapshot = [];
    foreach (['pp01', 'pp02', 'pp03', 'pp04', 'pp05'] as $step) {
        $snapshot["{$step}_created_by_user_name"] = 'Test User';
        $snapshot["{$step}_created_by_role_name"] = 'Test Role';
        $snapshot["{$step}_created_by_organization_name"] = 'Test Org';
        $snapshot["{$step}_created_by_workspace_name"] = 'Test WS';
        $snapshot["{$step}_created_at"] = now();
    }

    return $snapshot;
}

function setupCompletedPpIndex($workspace, $team): array
{
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    Pp01Data::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->subDays(10)->toDateString(),
        'tanggal_penetapan_program' => now()->addDays(30)->toDateString(),
    ]);

    $pp06 = Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->subDays(10)->toDateString(),
        'tanggal_penetapan_program' => now()->addDays(30)->toDateString(),
        ...pp06AuthorSnapshotIndex(),
    ]);

    $pp06->itemPlafonAnggaran()->create([
        'team_id' => $team->id,
        'kode_team' => 'T01',
        'plafon_anggaran' => 50000000,
        'nama_bank' => 'Bank Mandiri',
        'nama_rekening' => 'Demo Test',
        'nomor_rekening' => '1234567890',
    ]);

    return [$ppWorkflow, $pp06];
}

function setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, $bulan = 3, $user = null, $role = null): PrblWorkflow
{
    $theUser = $user ?? User::first() ?? User::factory()->create();
    $theRole = $role;
    $org = $team->organization;

    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $theUser->id,
        'created_by_role_id' => $theRole?->id ?? 1,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $org?->id ?? 1,
        'history' => [
            ['step' => 'PK04', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $pk04 = Pk04ProgramTahunan::create([
        'pk_workflow_id' => $pkWorkflow->id,
        'revision' => 0,
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Kegiatan',
        'deskripsi_program' => 'Deskripsi program kegiatan',
        'tujuan_program' => 'Tujuan program kegiatan',
        'nomer_program' => 1,
        'pk01_created_by_user_name' => $theUser->name,
        'pk01_created_by_role_name' => 'Bapel',
        'pk01_created_by_team_name' => $team->name ?? 'Test Team',
        'pk01_created_by_organization_name' => $org->name ?? 'Test Org',
        'pk01_created_by_workspace_name' => $workspace->name ?? 'Test WS',
        'pk01_created_at' => now(),
    ]);

    $kegiatan = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Retreat Remaja',
        'bulan' => $bulan,
        'nomer_kegiatan' => 1,
    ]);

    Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Konsumsi retreat remaja',
        'nominal_anggaran' => 2500000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.001.2027.03.rev0.M0',
        'status_item' => 'active',
        'status_pencairan' => 'dicairkan',
    ]);

    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => $bulan,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => $theUser->id,
        'created_by_role_id' => $theRole?->id ?? 1,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $org?->id ?? 1,
        'history' => [
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $prblWorkflow = PrblWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => $bulan,
        'tahun_laporan' => 2027,
        'created_by_user_id' => $theUser->id,
        'created_by_role_id' => $theRole?->id ?? 1,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $org?->id ?? 1,
        'history' => [],
    ]);

    $prbl01 = Prbl01Data::create(['prbl_workflow_id' => $prblWorkflow->id]);

    $engine = new WorkflowEngine;
    $engine->recordAction(
        workflow: $prblWorkflow,
        step: 'PRBL01',
        action: 'created',
        userId: null,
        sessionContext: [],
        table: 'prbl01_data',
        dataId: $prbl01->id,
    );

    return $prblWorkflow;
}

// ── Team Index ──

it('shows PRBL index page for team scope', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->get(route('team.workflows.prbl.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/prbl/index')
        ->has('workflows.data', 1)
        ->where('scope', 'team')
        ->has('filters')
        ->has('availablePpPeriods')
    );
});

it('returns empty state when no PRBL workflows exist for team', function () {
    [$user, $role, $workspace] = setupPrblIndexUser('team.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.workflows.prbl.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/prbl/index')
        ->has('workflows.data', 0)
    );
});

it('filters PRBL index by bulan for team scope', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    // Filter by bulan 3 — should return 1
    $response = $this->get(route('team.workflows.prbl.index', ['bulan' => 3]));
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by bulan 5 — should return 0
    $response = $this->get(route('team.workflows.prbl.index', ['bulan' => 5]));
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('denies PRBL index access without permission', function () {
    [$user, $role, $workspace] = setupPrblIndexUser(); // no permissions
    activatePrblIndexSession($this, $user, $role, $workspace);

    $this->get(route('team.workflows.prbl.index'))->assertStatus(403);
});

// ── Admin Index ──

it('shows PRBL index page for admin scope with team column', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('admin.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->get(route('admin.workflows.prbl.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/prbl/index')
        ->has('workflows.data', 1)
        ->where('scope', 'admin')
        ->has('availableTeams')
        ->where('workflows.data.0.team_name', $team->name)
    );
});

it('filters PRBL admin index by team', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('admin.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    // Filter by correct team — should return 1
    $response = $this->get(route('admin.workflows.prbl.index', ['team' => $team->id]));
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by wrong team — should return 0
    $response = $this->get(route('admin.workflows.prbl.index', ['team' => 99999]));
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('filters PRBL index by status', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    // Filter by active — should return 1
    $response = $this->get(route('team.workflows.prbl.index', ['status' => 'active']));
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by completed — should return 0 (workflow is still active)
    $response = $this->get(route('team.workflows.prbl.index', ['status' => 'completed']));
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('filters PRBL index by PP period', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    // Filter by PP tahun 2027 — should return 1
    $response = $this->get(route('team.workflows.prbl.index', ['pp' => '2027']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by PP tahun 2099 — should return 0
    $response = $this->get(route('team.workflows.prbl.index', ['pp' => '2099']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('returns correct PRBL row data structure', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.index');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->get(route('team.workflows.prbl.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('workflows.data.0', fn ($row) => $row
            ->has('id')
            ->where('bulan_laporan', 3)
            ->where('bulan_label', 'Maret')
            ->where('tahun_laporan', 2027)
            ->where('status', 'active')
            ->has('step_aktif')
            ->where('pp_label', 'PP-2027')
            ->where('total_realisasi', null)
            ->where('total_refund', null)
            ->has('terakhir_name')
            ->has('terakhir_role')
            ->has('tanggal')
        )
    );
});

// ── Team Show ──

it('shows PRBL show page for team scope', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser(
        'team.workflows.prbl.index',
        'team.workflows.prbl.show',
        'team.workflows.prbl.comment',
    );
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    $prblWorkflow = setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->get(route('team.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/prbl/show')
        ->where('scope', 'team')
        ->has('workflow', fn ($wf) => $wf
            ->has('id')
            ->has('label')
            ->has('status')
            ->has('history')
            ->has('stepper_cycles')
        )
        ->has('informasi', fn ($info) => $info
            ->where('team_name', $team->name)
            ->where('bulan_laporan', 3)
            ->where('bulan_label', 'Maret')
            ->where('tahun_laporan', 2027)
            ->where('pp_label', 'PP-2027')
            ->has('pp_workflow_id')
            ->has('pabd_label')
            ->has('pabd_workflow_id')
            ->has('dibuat_tanggal')
            ->has('status')
            ->has('step_aktif')
        )
        ->where('dataTerbaru', null)
        ->has('canComment')
        ->has('canExportZip')
        ->has('activeRoleName')
    );
});

it('shows PRBL show page with dataTerbaru when PRBL05 compiled', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser(
        'team.workflows.prbl.show',
    );
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    $prblWorkflow = setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    // Create PRBL05 compiled data
    Prbl05LaporanBulanan::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'verification_code' => 'ABC12DEF',
        'prbl01_created_by_user_name' => $user->name,
        'prbl01_created_by_role_name' => 'Bapel',
        'prbl01_created_by_team_name' => $team->name,
        'prbl01_created_by_organization_name' => 'Test Org',
        'prbl01_created_by_workspace_name' => 'Test WS',
        'prbl01_created_at' => now(),
        'prbl02a_approved_by_user_name' => 'Reviewer A',
        'prbl02a_approved_by_role_name' => 'Monev',
        'prbl02a_approved_by_team_name' => $team->name,
        'prbl02a_approved_by_organization_name' => 'Test Org',
        'prbl02a_approved_by_workspace_name' => 'Test WS',
        'prbl02a_approved_at' => now(),
        'prbl02b_approved_by_user_name' => 'Reviewer B',
        'prbl02b_approved_by_role_name' => 'BU',
        'prbl02b_approved_by_team_name' => $team->name,
        'prbl02b_approved_by_organization_name' => 'Test Org',
        'prbl02b_approved_by_workspace_name' => 'Test WS',
        'prbl02b_approved_at' => now(),
        'prbl03_created_by_user_name' => $user->name,
        'prbl03_created_by_role_name' => 'Bapel',
        'prbl03_created_by_team_name' => $team->name,
        'prbl03_created_by_organization_name' => 'Test Org',
        'prbl03_created_by_workspace_name' => 'Test WS',
        'prbl03_created_at' => now(),
        'prbl04_approved_by_user_name' => 'Final Reviewer',
        'prbl04_approved_by_role_name' => 'BU',
        'prbl04_approved_by_team_name' => $team->name,
        'prbl04_approved_by_organization_name' => 'Test Org',
        'prbl04_approved_by_workspace_name' => 'Test WS',
        'prbl04_approved_at' => now(),
        'total_anggaran_dicairkan' => 3500000,
        'total_realisasi' => 3000000,
        'total_refund' => 500000,
        'total_item' => 2,
    ]);

    $response = $this->get(route('team.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->has('dataTerbaru', fn ($dt) => $dt
            ->where('bulan_label', 'Maret')
            ->where('tahun_laporan', 2027)
            ->where('verification_code', 'ABC12DEF')
            ->has('prbl05_id')
            ->where('total_anggaran_dicairkan', 3500000)
            ->where('total_realisasi', 3000000)
            ->where('total_refund', 500000)
            ->where('total_item', 2)
            ->where('kegiatan_count', 0)
            ->where('foto_count', 0)
            ->where('bukti_count', 0)
        )
    );
});

// ── Admin Show ──

it('shows PRBL show page for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser(
        'admin.workflows.prbl.show',
    );
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    $prblWorkflow = setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->get(route('admin.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/prbl/show')
        ->where('scope', 'admin')
    );
});

it('denies PRBL show access without permission', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser(); // no permissions
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    $prblWorkflow = setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $this->get(route('team.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]))
        ->assertStatus(403);
});

it('denies PRBL show for wrong team in team scope', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.show');
    activatePrblIndexSession($this, $user, $role, $workspace);

    // Create workflow for a different team
    [$user2, , , $otherTeam] = setupPrblIndexUser();
    [$ppWorkflow] = setupCompletedPpIndex($workspace, $otherTeam);
    $prblWorkflow = setupPrblWorkflowIndex($workspace, $otherTeam, $ppWorkflow, 3, $user2);

    $this->get(route('team.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]))
        ->assertStatus(403);
});

it('shows PRBL show with PABD reference link', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.show');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    $prblWorkflow = setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->get(route('team.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertInertia(fn ($page) => $page
        ->where('informasi.pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
        ->where('informasi.pabd_label', fn ($label) => str_starts_with($label, 'PABD-'))
    );
});

it('includes stepper data with parallelGroup for PRBL02A and PRBL02B', function () {
    [$user, $role, $workspace, $team] = setupPrblIndexUser('team.workflows.prbl.show');
    activatePrblIndexSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpIndex($workspace, $team);
    $prblWorkflow = setupPrblWorkflowIndex($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->get(route('team.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertInertia(fn ($page) => $page
        ->has('workflow.stepper_cycles')
    );

    // Verify parallelGroup is injected
    $stepperCycles = $response->original->getData()['page']['props']['workflow']['stepper_cycles'];
    $steps = collect($stepperCycles)->flatMap(fn ($c) => $c['steps']);

    $prbl02a = $steps->firstWhere('code', 'PRBL02A');
    $prbl02b = $steps->firstWhere('code', 'PRBL02B');
    $prbl01 = $steps->firstWhere('code', 'PRBL01');

    expect($prbl02a['parallelGroup'])->toBe('prbl02');
    expect($prbl02b['parallelGroup'])->toBe('prbl02');
    expect($prbl01['parallelGroup'])->toBeNull();
});
