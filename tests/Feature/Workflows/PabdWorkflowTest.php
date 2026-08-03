<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\Pabd02aData;
use App\Models\Pabd\Pabd02aItemPerubahan;
use App\Models\Pabd\Pabd02bData;
use App\Models\Pabd\Pabd04Data;
use App\Models\Pabd\Pabd04ItemBuktiTransfer;
use App\Models\Pabd\Pabd05BuktiTransfer;
use App\Models\Pabd\Pabd05ItemAnggaran;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04AnggaranCatatanPerubahan;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\WorkflowEngine;
use App\Workflows\PabdWorkflowDefinition;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * Create a team-scope user with given PABD permissions.
 */
function setupPabdUser(string ...$permissionNames): array
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

function activatePabdSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function pp06AuthorSnapshotPabd(): array
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

function setupCompletedPpForPabd($workspace, $team): array
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
        ...pp06AuthorSnapshotPabd(),
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

/**
 * Create a PK workflow with PK04 final (compiled) containing anggaran in target month.
 */
function setupPk04WithAnggaran($workspace, $team, $ppWorkflow, $bulan = 3, $user = null, $role = null): array
{
    // Use provided user/role or fetch the first available
    $theUser = $user ?? User::first() ?? User::factory()->withRole()->create();
    $theRole = $role ?? $theUser->roles->first();
    $org = $theRole?->team?->organization;

    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $theUser->id,
        'created_by_role_id' => $theRole?->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $org?->id,
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

    $anggaran1 = Pk04Anggaran::create([
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
    ]);

    $anggaran2 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Transportasi',
        'deskripsi_pk' => 'Transportasi retreat remaja',
        'nominal_anggaran' => 1000000,
        'nomer_anggaran' => 2,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.002.2027.03.rev0.M0',
        'status_item' => 'active',
    ]);

    return [$pkWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2];
}

/**
 * Create a PABD workflow with PABD01 auto-created and items populated.
 */
function setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, $bulan = 3, $user = null, $role = null): array
{
    $theUser = $user ?? User::first() ?? User::factory()->create();
    $theRole = $role;
    $org = $team->organization ?? $theRole?->team?->organization;

    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => $bulan,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => $theUser->id,
        'created_by_role_id' => $theRole?->id ?? 1,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $org?->id ?? 1,
        'history' => [],
    ]);

    // Populate PABD01 data + items from PK04
    $anggaranIds = Pk04Anggaran::whereHas('pk04Kegiatan', fn ($q) => $q
        ->where('pk04_program_tahunan_id', $pk04->id)
        ->where('bulan', $bulan)
    )->pluck('id');

    $pabd01 = Pabd01Data::create([
        'pabd_workflow_id' => $pabdWorkflow->id,
        'ada_perubahan' => false,
        'pk04_revisions_snapshot' => [$pk04->id => $pk04->revision],
    ]);

    foreach ($anggaranIds as $anggaranId) {
        Pabd01ItemAnggaran::create([
            'pabd01_data_id' => $pabd01->id,
            'pk04_anggaran_id' => $anggaranId,
            'dicairkan' => false,
        ]);
    }

    // Record creation in history
    $engine = new WorkflowEngine;
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD01',
        action: 'created',
        userId: null,
        sessionContext: [],
        table: 'pabd01_data',
        dataId: $pabd01->id,
    );

    return [$pabdWorkflow, $pabd01];
}

// ── Index ──

it('renders PABD index page for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.index',
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/pabd/index')
        ->has('workflows.data', 1)
        ->where('workflows.data.0.id', $pabdWorkflow->id)
        ->where('workflows.data.0.bulan_label', 'Maret')
        ->where('workflows.data.0.tahun_anggaran', 2027)
        ->where('workflows.data.0.status', 'active')
        ->where('scope', 'team')
    );
});

it('renders PABD index page for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.pabd01.show',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('admin.workflows.pabd.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/pabd/index')
        ->has('workflows.data', 1)
        ->where('workflows.data.0.team_name', $team->name)
        ->where('scope', 'admin')
        ->has('availableTeams')
    );
});

it('filters PABD index by bulan', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.index',
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Filter by bulan 3 — should show
    $response = $this->get(route('team.workflows.pabd.index', ['bulan' => 3]));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('workflows.data', 1)
    );

    // Filter by bulan 6 — should be empty
    $response = $this->get(route('team.workflows.pabd.index', ['bulan' => 6]));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('workflows.data', 0)
    );
});

it('filters PABD index by status', function () {
    [$user, $role, $workspace, $team] = setupPabdUser('team.workflows.pabd.index');
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Filter by active — should return 1
    $response = $this->get(route('team.workflows.pabd.index', ['status' => 'active']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by completed — should return 0 (workflow is still active)
    $response = $this->get(route('team.workflows.pabd.index', ['status' => 'completed']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('filters PABD admin index by team', function () {
    [$user, $role, $workspace, $team] = setupPabdUser('admin.workflows.pabd.index');
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Filter by correct team — should return 1
    $response = $this->get(route('admin.workflows.pabd.index', ['team' => $team->id]));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by wrong team — should return 0
    $response = $this->get(route('admin.workflows.pabd.index', ['team' => 99999]));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('filters PABD index by PP period', function () {
    [$user, $role, $workspace, $team] = setupPabdUser('team.workflows.pabd.index');
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Filter by PP tahun 2027 — should return 1
    $response = $this->get(route('team.workflows.pabd.index', ['pp' => '2027']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by PP tahun 2099 — should return 0
    $response = $this->get(route('team.workflows.pabd.index', ['pp' => '2099']));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('denies PABD index without permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        // Missing: team.workflows.pabd.index
    );
    activatePabdSession($this, $user, $role, $workspace);

    $response = $this->get(route('team.workflows.pabd.index'));
    $response->assertForbidden();
});

// ── Show ──

it('renders PABD show page for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.index',
        'team.workflows.pabd.show',
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/pabd/show')
        ->where('workflow.id', $pabdWorkflow->id)
        ->where('workflow.status', 'active')
        ->where('informasi.team_name', $team->name)
        ->where('informasi.bulan_label', 'Maret')
        ->where('informasi.tahun_anggaran', 2027)
        ->where('dataTerbaru', null)
        ->where('canComment', true)
        ->where('canExportZip', false)
        ->where('scope', 'team')
    );
});

it('renders PABD show page for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.show',
        'admin.workflows.pabd.pabd01.show',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('admin.workflows.pabd.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/pabd/show')
        ->where('workflow.id', $pabdWorkflow->id)
        ->where('informasi.team_name', $team->name)
        ->where('scope', 'admin')
    );
});

it('shows PABD show page with dataTerbaru when PABD05 compiled', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.show',
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd05.show',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Create PABD05 compiled data
    $pabd05 = Pabd05PengajuanBulanan::create([
        'pabd_workflow_id' => $pabdWorkflow->id,
        'verification_code' => 'ABCD1234',
        'total_anggaran_dicairkan' => 2500000,
        'total_item_dicairkan' => 1,
        'total_item_hangus' => 1,
        'nama_bank' => 'Bank Mandiri',
        'nama_rekening' => 'Demo Test',
        'nomor_rekening' => '1234567890',
        'pabd01_created_by_user_name' => $user->name,
        'pabd01_created_by_role_name' => 'Bapel',
        'pabd01_created_by_team_name' => $team->name,
        'pabd01_created_by_organization_name' => 'Test Org',
        'pabd01_created_by_workspace_name' => 'Test WS',
        'pabd01_created_at' => now(),
        'pabd03_approved_by_user_name' => $user->name,
        'pabd03_approved_by_role_name' => 'BU',
        'pabd03_approved_by_team_name' => $team->name,
        'pabd03_approved_by_organization_name' => 'Test Org',
        'pabd03_approved_by_workspace_name' => 'Test WS',
        'pabd03_approved_at' => now(),
        'pabd04_created_by_user_name' => $user->name,
        'pabd04_created_by_role_name' => 'Staff KG',
        'pabd04_created_by_team_name' => $team->name,
        'pabd04_created_by_organization_name' => 'Test Org',
        'pabd04_created_by_workspace_name' => 'Test WS',
        'pabd04_created_at' => now(),
    ]);

    Pabd05ItemAnggaran::create([
        'pabd05_pengajuan_bulanan_id' => $pabd05->id,
        'pk04_anggaran_id' => $anggaran1->id,
        'status' => 'dicairkan',
        'nominal_anggaran' => 2500000,
    ]);
    Pabd05ItemAnggaran::create([
        'pabd05_pengajuan_bulanan_id' => $pabd05->id,
        'pk04_anggaran_id' => $anggaran2->id,
        'status' => 'hangus',
        'nominal_anggaran' => 1000000,
    ]);

    // Mark workflow as completed
    $pabdWorkflow->update(['history' => [
        ...$pabdWorkflow->history,
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
    ]]);

    $response = $this->get(route('team.workflows.pabd.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/pabd/show')
        ->where('workflow.status', 'completed')
        ->where('dataTerbaru.verification_code', 'ABCD1234')
        ->where('dataTerbaru.total_item_dicairkan', 1)
        ->where('dataTerbaru.total_item_hangus', 1)
        ->where('dataTerbaru.total_anggaran_dicairkan', fn ($v) => (float) $v === 2500000.0)
        ->where('dataTerbaru.total_anggaran_hangus', fn ($v) => (float) $v === 1000000.0)
        ->where('dataTerbaru.grand_total', fn ($v) => (float) $v === 3500000.0)
        ->where('dataTerbaru.bukti_transfer_count', 0)
    );
});

it('denies PABD show without permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.index',
        // Missing: team.workflows.pabd.show
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertForbidden();
});

it('denies PABD show from another team in team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.show',
    );
    activatePabdSession($this, $user, $role, $workspace);

    // Create workflow for a different team
    $otherUser = User::factory()->withRole()->create();
    $otherRole = $otherUser->roles->first();
    $otherTeam = $otherRole->team;

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $otherTeam);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $otherTeam, $ppWorkflow, 3, $otherUser, $otherRole);
    [$pabdWorkflow] = setupPabdWorkflow($workspace, $otherTeam, $ppWorkflow, $pk04, 3, $otherUser, $otherRole);

    $response = $this->get(route('team.workflows.pabd.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertForbidden();
});

// ── PABD01 Show ──

it('shows PABD01 page with anggaran items for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.pabd01.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd01')
            ->where('mode', 'edit')
            ->where('scope', 'team')
            ->where('canDraft', true)
            ->where('canSubmit', true)
            ->has('anggaranItems', 1)  // 1 program
            ->has('stepData.items', 2)  // 2 anggaran items
        );
});

it('shows PABD01 as readonly for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'admin.workflows.pabd.pabd01.show',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('admin.workflows.pabd.pabd01.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd01')
            ->where('mode', 'readonly')
            ->where('scope', 'admin')
            ->where('canDraft', false)
            ->where('canSubmit', false)
        );
});

// ── PABD01 Draft ──

it('saves PABD01 draft with checkbox states and ada_perubahan', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $items = $pabd01->itemAnggaran;

    $response = $this->post(route('team.workflows.pabd.pabd01.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), [
        'ada_perubahan' => true,
        'items' => [
            ['pabd01_item_anggaran_id' => $items[0]->id, 'dicairkan' => true],
            ['pabd01_item_anggaran_id' => $items[1]->id, 'dicairkan' => false],
        ],
        'expected_updated_at' => $pabd01->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $pabd01->refresh();
    expect($pabd01->ada_perubahan)->toBeTrue();

    $items = $pabd01->itemAnggaran()->orderBy('id')->get();
    expect($items[0]->dicairkan)->toBeTrue()
        ->and($items[1]->dicairkan)->toBeFalse();

    $pabdWorkflow->refresh();
    $lastEntry = collect($pabdWorkflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PABD01');
});

// ── PABD01 Submit (no changes — skip to PABD03) ──

it('submits PABD01 with ada_perubahan=false, skips PABD02A+02B, activates PABD03', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $items = $pabd01->itemAnggaran;

    $response = $this->post(route('team.workflows.pabd.pabd01.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), [
        'ada_perubahan' => false,
        'items' => [
            ['pabd01_item_anggaran_id' => $items[0]->id, 'dicairkan' => true],
            ['pabd01_item_anggaran_id' => $items[1]->id, 'dicairkan' => true],
        ],
        'expected_updated_at' => $pabd01->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    // Engine treats 'skipped' action as a completing action → status = 'completed'
    expect($statuses['PABD01']['status'])->toBe('completed')
        ->and($statuses['PABD02A']['status'])->toBe('completed')
        ->and($statuses['PABD02B']['status'])->toBe('completed')
        ->and($statuses['PABD03']['status'])->toBe('active');

    // Verify history has submitted + 2x skipped + created
    $actions = collect($pabdWorkflow->history)->pluck('action')->all();
    expect($actions)->toContain('submitted')
        ->and($actions)->toContain('skipped');
});

// ── PABD01 Submit (with changes — activate PABD02A) ──

it('submits PABD01 with ada_perubahan=true, activates PABD02A', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $items = $pabd01->itemAnggaran;

    $response = $this->post(route('team.workflows.pabd.pabd01.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), [
        'ada_perubahan' => true,
        'items' => [
            ['pabd01_item_anggaran_id' => $items[0]->id, 'dicairkan' => true],
            ['pabd01_item_anggaran_id' => $items[1]->id, 'dicairkan' => false],
        ],
        'expected_updated_at' => $pabd01->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    expect($statuses['PABD01']['status'])->toBe('completed')
        ->and($statuses['PABD02A']['status'])->toBe('active');

    // PABD02A data row created
    $pabd02a = Pabd02aData::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd02a)->not->toBeNull();
});

// ── Staleness Detection ──

it('detects PK04 staleness and resets to fresh PABD01', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.show',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Simulate PK04 revision: increment revision
    $pk04->update(['revision' => 1]);

    // Try to show PABD01 — should detect staleness and redirect
    // Send as Inertia request so Inertia::location() returns 409 + X-Inertia-Location
    $response = $this->get(route('team.workflows.pabd.pabd01.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), ['X-Inertia' => 'true']);

    // Inertia::location returns 409 with X-Inertia-Location header for Inertia clients
    $response->assertStatus(409);

    // Verify new PABD01 data was created
    $pabdWorkflow->refresh();
    $newPabd01 = $pabdWorkflow->latestPabd01();
    expect($newPabd01->id)->not->toBe($pabd01->id);
    expect($newPabd01->pk04_revisions_snapshot[$pk04->id])->toBe(1);

    // History should have reset entry
    $resetEntries = collect($pabdWorkflow->history)->where('action', 'reset');
    expect($resetEntries)->toHaveCount(1);
});

// ── Permission Denial ──

it('denies PABD01 draft without draft permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        // No draft permission
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd01.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), [
        'expected_updated_at' => $pabd01->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('denies PABD01 submit without submit permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        // No submit permission
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $items = $pabd01->itemAnggaran;

    $response = $this->post(route('team.workflows.pabd.pabd01.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), [
        'ada_perubahan' => false,
        'items' => [
            ['pabd01_item_anggaran_id' => $items[0]->id, 'dicairkan' => true],
            ['pabd01_item_anggaran_id' => $items[1]->id, 'dicairkan' => true],
        ],
        'expected_updated_at' => $pabd01->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

// ── Optimistic Locking ──

it('rejects PABD01 draft with stale expected_updated_at', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd01.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), [
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $response->assertStatus(409);
});

// ── PABD02A Helpers ──

/**
 * Advance a PABD workflow to PABD02A active state (PABD01 submitted with ada_perubahan=true).
 */
function setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role): array
{
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role);

    $engine = new WorkflowEngine;

    // Submit PABD01 with ada_perubahan=true
    $pabd01->update(['ada_perubahan' => true]);
    foreach ($pabd01->itemAnggaran as $item) {
        $item->update(['dicairkan' => true]);
    }

    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD01',
        action: 'submitted',
        userId: $user->id,
        sessionContext: ['role' => $role->id, 'team' => $team->id, 'org' => $team->organization_id, 'workspace' => $workspace->id],
        table: 'pabd01_data',
        dataId: $pabd01->id,
    );

    // Create PABD02A data
    $pabd02a = Pabd02aData::create([
        'pabd_workflow_id' => $pabdWorkflow->id,
    ]);

    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD02A',
        action: 'created',
        userId: null,
        sessionContext: [],
        table: 'pabd02a_data',
        dataId: $pabd02a->id,
    );

    return [$pabdWorkflow, $pabd01, $pabd02a];
}

/**
 * Create PK04 anggaran in a future month (for tarik maju picker).
 */
function setupFutureMonthAnggaran($pkWorkflow, $pk04, int $futureBulan): array
{
    $kegiatan = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => "Kegiatan Bulan {$futureBulan}",
        'bulan' => $futureBulan,
        'nomer_kegiatan' => 2,
    ]);

    $anggaran = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi Future',
        'deskripsi_pk' => 'Konsumsi kegiatan masa depan',
        'nominal_anggaran' => 3000000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => "R.01.01.01.01.01.001.002.001.2027.{$futureBulan}.rev0.M0",
        'status_item' => 'active',
    ]);

    return [$kegiatan, $anggaran];
}

// ── PABD02A Show ──

it('shows PABD02A page with step data for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
        'team.workflows.pabd.pabd02a.submit',
        'team.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.pabd02a.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd02a')
            ->where('mode', 'edit')
            ->where('scope', 'team')
            ->where('canDraft', true)
            ->where('canSubmit', true)
            ->has('stepData')
            ->has('pabd01ChecklistData')
            ->has('futureAnggaranItems')
            ->has('kodeRefs')
            ->has('budgetCounter')
        );
});

it('shows PABD02A as readonly for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd02a.show',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('admin.workflows.pabd.pabd02a.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd02a')
            ->where('mode', 'readonly')
            ->where('scope', 'admin')
            ->where('canDraft', false)
            ->where('canSubmit', false)
        );
});

// ── PABD02A Draft ──

it('saves PABD02A draft with tarik_maju item', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    // Add future month anggaran for tarik maju
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd02a.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'items' => [
            [
                'tipe_perubahan' => 'tarik_maju',
                'pk04_anggaran_id' => $futureAnggaran->id,
                'bulan_awal' => 6,
                'bulan_tujuan' => 3,
                'komentar' => 'Perlu dana lebih awal',
            ],
        ],
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify item was created
    $items = Pabd02aItemPerubahan::where('pabd02a_data_id', $pabd02a->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items->first()->tipe_perubahan)->toBe('tarik_maju')
        ->and($items->first()->pk04_anggaran_id)->toBe($futureAnggaran->id)
        ->and($items->first()->bulan_awal)->toBe(6)
        ->and($items->first()->bulan_tujuan)->toBe(3)
        ->and($items->first()->komentar)->toBe('Perlu dana lebih awal');

    // Verify history
    $pabdWorkflow->refresh();
    $lastEntry = collect($pabdWorkflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PABD02A');
});

// ── PABD02A Submit (tarik_mundur) ──

it('submits PABD02A with tarik_mundur item pushing an item to a later month', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
        'team.workflows.pabd.pabd02a.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd02a.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'items' => [
            [
                'tipe_perubahan' => 'tarik_mundur',
                'pk04_anggaran_id' => $futureAnggaran->id,
                'bulan_awal' => 6,
                'bulan_tujuan' => 9,
                'komentar' => 'Menunda anggaran bulan 6 ke bulan 9 karena kegiatan digeser.',
            ],
        ],
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $items = Pabd02aItemPerubahan::where('pabd02a_data_id', $pabd02a->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items->first()->tipe_perubahan)->toBe('tarik_mundur')
        ->and($items->first()->bulan_awal)->toBe(6)
        ->and($items->first()->bulan_tujuan)->toBe(9)
        ->and($items->first()->pk04_anggaran_id)->toBe($futureAnggaran->id);
});

it('rejects tarik_mundur when bulan_tujuan is not after bulan_awal', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
        'team.workflows.pabd.pabd02a.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // bulan_tujuan 4 is EARLIER than bulan_awal 6 — that is a tarik maju, not mundur.
    $response = $this->post(route('team.workflows.pabd.pabd02a.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'items' => [
            [
                'tipe_perubahan' => 'tarik_mundur',
                'pk04_anggaran_id' => $futureAnggaran->id,
                'bulan_awal' => 6,
                'bulan_tujuan' => 4,
                'komentar' => 'Arah salah, harus ditolak oleh validasi.',
            ],
        ],
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect(Pabd02aItemPerubahan::where('pabd02a_data_id', $pabd02a->id)->count())->toBe(0);
});

it('rejects tarik_maju when bulan_tujuan is after bulan_awal', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
        'team.workflows.pabd.pabd02a.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd02a.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'items' => [
            [
                'tipe_perubahan' => 'tarik_maju',
                'pk04_anggaran_id' => $futureAnggaran->id,
                'bulan_awal' => 6,
                'bulan_tujuan' => 9,
                'komentar' => 'Arah salah, harus ditolak oleh validasi.',
            ],
        ],
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertStatus(422);
    expect(Pabd02aItemPerubahan::where('pabd02a_data_id', $pabd02a->id)->count())->toBe(0);
});

// ── PABD02A Submit (tarik_maju) ──

it('submits PABD02A with tarik_maju item and activates PABD02B', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
        'team.workflows.pabd.pabd02a.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd02a.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'items' => [
            [
                'tipe_perubahan' => 'tarik_maju',
                'pk04_anggaran_id' => $futureAnggaran->id,
                'bulan_awal' => 6,
                'bulan_tujuan' => 3,
                'komentar' => 'Menarik anggaran bulan 6 ke bulan 3 karena kebutuhan mendesak.',
            ],
        ],
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify items persisted
    $items = Pabd02aItemPerubahan::where('pabd02a_data_id', $pabd02a->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items->first()->tipe_perubahan)->toBe('tarik_maju');

    // Verify PABD02B created and active
    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    expect($statuses['PABD02A']['status'])->toBe('completed')
        ->and($statuses['PABD02B']['status'])->toBe('active');

    $pabd02b = Pabd02bData::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd02b)->not->toBeNull();
});

// ── PABD02A Submit (proposal_baru) ──

it('submits PABD02A with proposal_baru item and creates PK Proposal', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
        'team.workflows.pabd.pabd02a.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Create a temp file for the proposal attachment
    $file = \Illuminate\Http\UploadedFile::fake()->create('proposal.pdf', 100);

    $response = $this->post(route('team.workflows.pabd.pabd02a.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'items' => [
            [
                'tipe_perubahan' => 'proposal_baru',
                'komentar' => 'Program baru untuk pelayanan pemuda yang sangat dibutuhkan.',
                'item_files' => [$file],
                'proposal' => [
                    'kode_kategori' => 'K01',
                    'nama_program' => 'Program Pelayanan Pemuda',
                    'deskripsi_program' => 'Deskripsi program pelayanan pemuda',
                    'tujuan_program' => 'Tujuan program pelayanan pemuda',
                    'kegiatan' => [
                        [
                            'nama_kegiatan' => 'Retreat Pemuda',
                            'bulan' => 5,
                            'anggaran' => [
                                [
                                    'kode_bidang' => 'B01',
                                    'kode_sub_bidang' => 'SB01',
                                    'kode_jenis' => 'J01',
                                    'mata_anggaran' => 'Konsumsi Retreat',
                                    'deskripsi_pk' => 'Konsumsi retreat pemuda',
                                    'nominal_anggaran' => 5000000,
                                ],
                            ],
                            'kuisioner' => [
                                [
                                    'pertanyaan' => 'Berapa jumlah peserta?',
                                    'tipe' => 'Kuantitatif',
                                    'satuan' => 'orang',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify item created with PK Proposal reference
    $items = Pabd02aItemPerubahan::where('pabd02a_data_id', $pabd02a->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items->first()->tipe_perubahan)->toBe('proposal_baru')
        ->and($items->first()->pk_workflow_id)->not->toBeNull();

    // Verify PK Proposal was created
    $proposalWorkflow = PkWorkflow::find($items->first()->pk_workflow_id);
    expect($proposalWorkflow)->not->toBeNull()
        ->and($proposalWorkflow->tipe)->toBe('proposal');

    // Verify PK01 data was created with kegiatan
    $pk01 = Pk01Data::where('pk_workflow_id', $proposalWorkflow->id)->first();
    expect($pk01)->not->toBeNull()
        ->and($pk01->nama_program)->toBe('Program Pelayanan Pemuda')
        ->and($pk01->kode_kategori)->toBe('K01');

    $kegiatan = $pk01->kegiatan;
    expect($kegiatan)->toHaveCount(1)
        ->and($kegiatan->first()->nama_kegiatan)->toBe('Retreat Pemuda')
        ->and($kegiatan->first()->bulan)->toBe(5);

    // Verify anggaran and kuisioner
    $anggaran = $kegiatan->first()->anggaran;
    expect($anggaran)->toHaveCount(1)
        ->and((float) $anggaran->first()->nominal_anggaran)->toBe(5000000.0);

    $kuisioner = $kegiatan->first()->kuisioner;
    expect($kuisioner)->toHaveCount(1)
        ->and($kuisioner->first()->pertanyaan)->toBe('Berapa jumlah peserta?');

    // Verify PABD02B activated
    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    expect($statuses['PABD02A']['status'])->toBe('completed')
        ->and($statuses['PABD02B']['status'])->toBe('active');
});

// ── PABD02A Permission Denial ──

it('denies PABD02A draft without draft permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        // No draft permission
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd02a.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('denies PABD02A submit without submit permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
        // No submit permission
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd02a.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'items' => [
            [
                'tipe_perubahan' => 'tarik_maju',
                'pk04_anggaran_id' => 1,
                'bulan_awal' => 6,
                'bulan_tujuan' => 3,
                'komentar' => 'Test submission without permission.',
            ],
        ],
        'expected_updated_at' => $pabd02a->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

// ── PABD02A Optimistic Locking ──

it('rejects PABD02A draft with stale expected_updated_at', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.draft',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow);
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('team.workflows.pabd.pabd02a.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02aData' => $pabd02a->id,
    ]), [
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $response->assertStatus(409);
});

// ── PABD02B Helpers ──

/**
 * Advance a PABD workflow to PABD02B active state.
 *
 * Creates PABD02A items (tarik_maju or proposal_baru), submits PABD02A,
 * and creates PABD02B with item_review rows.
 *
 * @param  string  $itemType  'tarik_maju' or 'proposal_baru'
 */
function setupPabd02bActive(
    $workspace,
    $team,
    $ppWorkflow,
    $pk04,
    int $bulan,
    $user,
    $role,
    string $itemType = 'tarik_maju',
    ?Pk04Anggaran $futureAnggaran = null,
    ?PkWorkflow $pkWorkflow = null,
): array {
    [$pabdWorkflow, $pabd01, $pabd02a] = setupPabd02aActive($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role);

    $engine = new WorkflowEngine;

    // Create PABD02A item(s)
    if ($itemType === 'tarik_maju' && $futureAnggaran) {
        Pabd02aItemPerubahan::create([
            'pabd02a_data_id' => $pabd02a->id,
            'tipe_perubahan' => 'tarik_maju',
            'pk04_anggaran_id' => $futureAnggaran->id,
            'bulan_awal' => $futureAnggaran->pk04Kegiatan->bulan,
            'bulan_tujuan' => $bulan,
            'komentar' => 'Perlu dana lebih awal untuk kegiatan bulan ini.',
        ]);
    } elseif ($itemType === 'proposal_baru' && $pkWorkflow) {
        Pabd02aItemPerubahan::create([
            'pabd02a_data_id' => $pabd02a->id,
            'tipe_perubahan' => 'proposal_baru',
            'pk_workflow_id' => $pkWorkflow->id,
            'komentar' => 'Program baru untuk pelayanan pemuda.',
        ]);
    } else {
        throw new \RuntimeException('setupPabd02bActive requires futureAnggaran for tarik_maju or pkWorkflow for proposal_baru');
    }

    // Record PABD02A submitted
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD02A',
        action: 'submitted',
        userId: $user->id,
        sessionContext: ['role' => $role->id, 'team' => $team->id, 'org' => $team->organization_id, 'workspace' => $workspace->id],
        table: 'pabd02a_data',
        dataId: $pabd02a->id,
    );

    // Create PABD02B data
    $pabd02b = Pabd02bData::create([
        'pabd_workflow_id' => $pabdWorkflow->id,
    ]);

    // Create pabd02b_item_review rows (1:1 with PABD02A items)
    foreach ($pabd02a->itemPerubahan()->get() as $item) {
        $pabd02b->itemReview()->create([
            'pabd02a_item_perubahan_id' => $item->id,
        ]);
    }

    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD02B',
        action: 'created',
        userId: null,
        sessionContext: [],
        table: 'pabd02b_data',
        dataId: $pabd02b->id,
    );

    return [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b];
}

/**
 * Create a PK Proposal workflow with PK01 data (for proposal_baru items).
 */
function setupPkProposal($workspace, $team, $ppWorkflow, $user, $role): PkWorkflow
{
    $org = $role->team->organization;

    $proposalWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'proposal',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $org->id,
        'history' => [],
    ]);

    $pk01 = Pk01Data::create([
        'pk_workflow_id' => $proposalWorkflow->id,
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Pelayanan Pemuda',
        'deskripsi_program' => 'Deskripsi program pelayanan pemuda',
        'tujuan_program' => 'Tujuan program pelayanan pemuda',
    ]);

    $kegiatan = $pk01->kegiatan()->create([
        'nama_kegiatan' => 'Retreat Pemuda',
        'bulan' => 5,
    ]);

    $kegiatan->anggaran()->create([
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi Retreat',
        'deskripsi_pk' => 'Konsumsi retreat pemuda',
        'nominal_anggaran' => 5000000,
    ]);

    $kegiatan->kuisioner()->create([
        'pertanyaan' => 'Berapa jumlah peserta?',
        'tipe' => 'Kuantitatif',
        'satuan' => 'orang',
    ]);

    return $proposalWorkflow;
}

// ── PABD02B Show ──

it('shows PABD02B page with review items for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        'admin.workflows.pabd.pabd02b.approve',
        'admin.workflows.pabd.pabd02b.reject',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $response = $this->get(route('admin.workflows.pabd.pabd02b.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd02b')
            ->where('mode', 'edit')
            ->where('scope', 'admin')
            ->where('canDraft', true)
            ->where('canApprove', true)
            ->where('canReject', true)
            ->has('stepData.items', 1)
            ->has('pabd01ChecklistData')
            ->has('pabd02aSubmitter')
            ->has('budgetCounter')
            ->where('tarikMajuCount', 1)
            ->where('proposalBaruCount', 0)
        );
});

it('shows PABD02B as readonly for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'team.workflows.pabd.pabd02b.show',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $response = $this->get(route('team.workflows.pabd.pabd02b.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd02b')
            ->where('mode', 'readonly')
            ->where('scope', 'team')
            ->where('canDraft', false)
            ->where('canApprove', false)
            ->where('canReject', false)
        );
});

// ── PABD02B Draft ──

it('saves PABD02B draft with per-item komentar_approval', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $reviewItem = $pabd02b->itemReview->first();

    $response = $this->post(route('admin.workflows.pabd.pabd02b.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [
            [
                'pabd02b_item_review_id' => $reviewItem->id,
                'komentar_approval' => 'Perlu penjelasan lebih detail mengenai urgensi.',
            ],
        ],
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $reviewItem->refresh();
    expect($reviewItem->komentar_approval)->toBe('Perlu penjelasan lebih detail mengenai urgensi.');

    $pabdWorkflow->refresh();
    $lastEntry = collect($pabdWorkflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PABD02B');
});

// ── PABD02B Approve (tarik_maju) ──

it('approves PABD02B with tarik_maju item and recompiles PK04', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        'admin.workflows.pabd.pabd02b.approve',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $reviewItem = $pabd02b->itemReview->first();
    $originalNominal = $futureAnggaran->nominal_anggaran;

    $response = $this->post(route('admin.workflows.pabd.pabd02b.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [
            [
                'pabd02b_item_review_id' => $reviewItem->id,
                'komentar_approval' => 'Disetujui, urgensi jelas.',
            ],
        ],
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify old anggaran was zeroed with status_item='ditarik_maju'
    $futureAnggaran->refresh();
    expect((float) $futureAnggaran->nominal_anggaran)->toBe(0.0)
        ->and($futureAnggaran->status_item)->toBe('ditarik_maju');

    // Verify new PK04 was created (revision incremented)
    $newPk04 = Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)
        ->orderByDesc('revision')
        ->first();
    expect($newPk04->revision)->toBe(1);

    // Verify new kegiatan created in destination month (bulan=3) with source='tarik_maju'
    $newKegiatan = Pk04Kegiatan::where('pk04_program_tahunan_id', $newPk04->id)
        ->where('source', 'tarik_maju')
        ->first();
    expect($newKegiatan)->not->toBeNull()
        ->and($newKegiatan->bulan)->toBe(3);

    // Verify new anggaran carries original nominal
    $newAnggaran = Pk04Anggaran::where('pk04_kegiatan_id', $newKegiatan->id)->first();
    expect($newAnggaran)->not->toBeNull()
        ->and((float) $newAnggaran->nominal_anggaran)->toBe((float) $originalNominal)
        ->and($newAnggaran->source)->toBe('tarik_maju')
        ->and($newAnggaran->previous_anggaran_id)->toBe($futureAnggaran->id);

    // Verify catatan_perubahan was created
    $catatan = Pk04AnggaranCatatanPerubahan::where('pk04_anggaran_id', $futureAnggaran->id)
        ->where('pabd_workflow_id', $pabdWorkflow->id)
        ->first();
    expect($catatan)->not->toBeNull()
        ->and($catatan->tipe_perubahan)->toBe('tarik_maju')
        ->and($catatan->catatan_approval)->toBe('Disetujui, urgensi jelas.');

    // Verify cycle-back: PABD02B invalidated by own cycle-back, PABD01 is active again
    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    // After approve cycle-back: PABD01=active (re-created), PABD02A/02B=pending (invalidated)
    expect($statuses['PABD01']['status'])->toBe('active')
        ->and($statuses['PABD02A']['status'])->toBe('pending')
        ->and($statuses['PABD02B']['status'])->toBe('pending');

    // Fresh PABD01 should exist with updated snapshot
    $freshPabd01 = $pabdWorkflow->latestPabd01();
    expect($freshPabd01->id)->not->toBe($pabd01->id)
        ->and($freshPabd01->pk04_revisions_snapshot[$newPk04->id])->toBe(1);
});

// ── PABD02B Approve — budget counter regression ──

it('budget counter accepted does not double-count after tarik_maju PK04 recompile', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        'admin.workflows.pabd.pabd02b.approve',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);

    // Total active before tarik maju: 2.5M + 1M (bulan 3) + 3M (bulan 6) = 6.5M
    $expectedTotal = 6500000;

    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $reviewItem = $pabd02b->itemReview->first();

    $this->post(route('admin.workflows.pabd.pabd02b.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [['pabd02b_item_review_id' => $reviewItem->id, 'komentar_approval' => null]],
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ])->assertRedirect();

    // After approve: PK04 was recompiled (snapshot + new revision).
    // PABD01 cycle 2 is now active — get the fresh pabd01.
    $pabdWorkflow->refresh();
    $freshPabd01 = $pabdWorkflow->latestPabd01();

    $response = $this->get(route('team.workflows.pabd.pabd01.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $freshPabd01->id,
    ]));

    $response->assertOk();

    // Snapshot copies on old pk04 revision must NOT be counted.
    $response->assertInertia(fn ($page) => $page
        ->where('budgetCounter.accepted', $expectedTotal)
    );
});

// ── PABD02B Approve (proposal_baru) ──

it('approves PABD02B with proposal_baru item and compiles to PK04', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        'admin.workflows.pabd.pabd02b.approve',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);

    // Create a PK Proposal
    $proposalWorkflow = setupPkProposal($workspace, $team, $ppWorkflow, $user, $role);

    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'proposal_baru', pkWorkflow: $proposalWorkflow,
    );

    $reviewItem = $pabd02b->itemReview->first();

    $response = $this->post(route('admin.workflows.pabd.pabd02b.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [
            [
                'pabd02b_item_review_id' => $reviewItem->id,
                'komentar_approval' => 'Program disetujui.',
            ],
        ],
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify PK Proposal was compiled to PK04
    $proposalPk04 = Pk04ProgramTahunan::where('pk_workflow_id', $proposalWorkflow->id)->first();
    expect($proposalPk04)->not->toBeNull()
        ->and($proposalPk04->nama_program)->toBe('Program Pelayanan Pemuda')
        ->and($proposalPk04->revision)->toBe(0);

    // Verify PK04 kegiatan and anggaran created
    $proposalKegiatan = Pk04Kegiatan::where('pk04_program_tahunan_id', $proposalPk04->id)->first();
    expect($proposalKegiatan)->not->toBeNull()
        ->and($proposalKegiatan->nama_kegiatan)->toBe('Retreat Pemuda')
        ->and($proposalKegiatan->source)->toBe('proposal')
        ->and($proposalKegiatan->source_pabd_workflow_id)->toBe($pabdWorkflow->id);

    $proposalAnggaran = Pk04Anggaran::where('pk04_kegiatan_id', $proposalKegiatan->id)->first();
    expect($proposalAnggaran)->not->toBeNull()
        ->and((float) $proposalAnggaran->nominal_anggaran)->toBe(5000000.0)
        ->and($proposalAnggaran->source)->toBe('proposal');

    // Verify cycle-back: PABD01=active, PABD02A/02B=pending (invalidated by approve cycle-back)
    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    expect($statuses['PABD01']['status'])->toBe('active')
        ->and($statuses['PABD02A']['status'])->toBe('pending')
        ->and($statuses['PABD02B']['status'])->toBe('pending');
});

// ── PABD02B Reject ──

it('rejects PABD02B, soft-deletes proposals, and cycles back to PABD01', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        'admin.workflows.pabd.pabd02b.reject',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);

    // Create a PK Proposal for proposal_baru
    $proposalWorkflow = setupPkProposal($workspace, $team, $ppWorkflow, $user, $role);

    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'proposal_baru', pkWorkflow: $proposalWorkflow,
    );

    $reviewItem = $pabd02b->itemReview->first();

    $response = $this->post(route('admin.workflows.pabd.pabd02b.reject', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [
            [
                'pabd02b_item_review_id' => $reviewItem->id,
                'komentar_approval' => 'Program belum sesuai prioritas.',
            ],
        ],
        'notes' => 'Proposal ditolak karena belum sesuai dengan prioritas tahunan.',
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify PK Proposal was soft-deleted
    $proposalWorkflow->refresh();
    expect($proposalWorkflow->trashed())->toBeTrue();

    // PK Proposal should NOT have PK04 compiled (rejected before compile)
    $proposalPk04 = Pk04ProgramTahunan::where('pk_workflow_id', $proposalWorkflow->id)->first();
    expect($proposalPk04)->toBeNull();

    // Verify cycle-back: PABD01=active, PABD02A/02B=pending (invalidated by reject cycle-back)
    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    expect($statuses['PABD01']['status'])->toBe('active')
        ->and($statuses['PABD02A']['status'])->toBe('pending')
        ->and($statuses['PABD02B']['status'])->toBe('pending');

    // History should have 'rejected'
    $rejectEntries = collect($pabdWorkflow->history)->where('action', 'rejected');
    expect($rejectEntries)->toHaveCount(1);
});

it('rejects PABD02B with tarik_maju item and creates catatan_perubahan audit', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        'admin.workflows.pabd.pabd02b.reject',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $reviewItem = $pabd02b->itemReview->first();

    $response = $this->post(route('admin.workflows.pabd.pabd02b.reject', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [
            [
                'pabd02b_item_review_id' => $reviewItem->id,
                'komentar_approval' => 'Tarik maju tidak disetujui.',
            ],
        ],
        'notes' => 'Perubahan anggaran ditolak. Silakan ajukan kembali dengan justifikasi yang lebih kuat.',
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify old anggaran NOT modified (reject doesn't recompile)
    $futureAnggaran->refresh();
    expect((float) $futureAnggaran->nominal_anggaran)->toBe(3000000.0)
        ->and($futureAnggaran->status_item)->toBe('active');

    // Verify catatan_perubahan was created for audit
    $catatan = Pk04AnggaranCatatanPerubahan::where('pk04_anggaran_id', $futureAnggaran->id)
        ->where('pabd_workflow_id', $pabdWorkflow->id)
        ->first();
    expect($catatan)->not->toBeNull()
        ->and($catatan->tipe_perubahan)->toBe('tarik_maju')
        ->and($catatan->catatan_approval)->toBe('Tarik maju tidak disetujui.');

    // Verify cycle-back: PABD01=active, PABD02A/02B=pending (invalidated by reject cycle-back)
    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    expect($statuses['PABD01']['status'])->toBe('active')
        ->and($statuses['PABD02A']['status'])->toBe('pending')
        ->and($statuses['PABD02B']['status'])->toBe('pending');
});

// ── PABD02B Permission Denial ──

it('denies PABD02B approve without approve permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        // No approve permission
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $reviewItem = $pabd02b->itemReview->first();

    $response = $this->post(route('admin.workflows.pabd.pabd02b.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [
            [
                'pabd02b_item_review_id' => $reviewItem->id,
                'komentar_approval' => 'Test',
            ],
        ],
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('denies PABD02B reject without reject permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
        // No reject permission
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $reviewItem = $pabd02b->itemReview->first();

    $response = $this->post(route('admin.workflows.pabd.pabd02b.reject', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'items' => [
            [
                'pabd02b_item_review_id' => $reviewItem->id,
                'komentar_approval' => 'Test',
            ],
        ],
        'notes' => 'Ditolak karena alasan yang jelas dan lengkap.',
        'expected_updated_at' => $pabd02b->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

// ── PABD02B Optimistic Locking ──

it('rejects PABD02B draft with stale expected_updated_at', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.show',
        'team.workflows.pabd.pabd02a.submit',
        'admin.workflows.pabd.pabd02b.show',
        'admin.workflows.pabd.pabd02b.draft',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$futureKegiatan, $futureAnggaran] = setupFutureMonthAnggaran($pkWorkflow, $pk04, 6);
    [$pabdWorkflow, $pabd01, $pabd02a, $pabd02b] = setupPabd02bActive(
        $workspace, $team, $ppWorkflow, $pk04, 3, $user, $role,
        itemType: 'tarik_maju', futureAnggaran: $futureAnggaran,
    );

    $response = $this->post(route('admin.workflows.pabd.pabd02b.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd02bData' => $pabd02b->id,
    ]), [
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $response->assertStatus(409);
});

// ── PABD03 Helpers ──

/**
 * Advance a PABD workflow to PABD03 active state.
 * (PABD01 submitted with ada_perubahan=false → PABD02A+02B skipped → PABD03 active)
 */
function setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role): array
{
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role);

    $items = $pabd01->itemAnggaran;

    // Mark items as dicairkan
    foreach ($items as $item) {
        $item->update(['dicairkan' => true]);
    }

    $engine = new WorkflowEngine;

    // Submit PABD01 with ada_perubahan = false
    $pabd01->update(['ada_perubahan' => false]);
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD01',
        action: 'submitted',
        userId: $user->id,
        sessionContext: ['role' => $role->id, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id],
        table: 'pabd01_data',
        dataId: $pabd01->id,
    );

    // Skip PABD02A + PABD02B
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD02A',
        action: 'skipped',
        userId: null,
        sessionContext: [],
    );
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD02B',
        action: 'skipped',
        userId: null,
        sessionContext: [],
    );

    // Record PABD03 created
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD03',
        action: 'created',
        userId: null,
        sessionContext: [],
        extra: ['triggered_by' => ['user_id' => $user->id, 'step' => 'PABD01', 'action' => 'submitted']],
    );

    return [$pabdWorkflow, $pabd01];
}

// ── PABD03 Show ──

it('shows PABD03 page for admin with approve/reject permissions', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.pabd03.reject',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('admin.workflows.pabd.pabd03.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd03')
            ->where('mode', 'edit')
            ->where('scope', 'admin')
            ->where('canApprove', true)
            ->where('canReject', true)
            ->has('pabd01ChecklistData', 1)
            ->has('summaryTotals')
            ->has('bankDetails')
        );
});

it('shows PABD03 as readonly for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd03.show',
        'team.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.pabd03.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd03')
            ->where('mode', 'readonly')
            ->where('scope', 'team')
            ->where('canApprove', false)
            ->where('canReject', false)
        );
});

// ── PABD03 Approve ──

it('approves PABD03 and creates PABD04 with Tier 2 pk04 lock', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd03.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
        'notes' => 'Transfer disetujui.',
    ]);

    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    // PABD03 completed, PABD04 active
    expect($statuses['PABD03']['status'])->toBe('completed')
        ->and($statuses['PABD04']['status'])->toBe('active');

    // PABD04 data row created
    $pabd04 = Pabd04Data::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd04)->not->toBeNull();

    // Verify Tier 2 PK04 lock: status_pencairan = 'menunggu_pencairan'
    $ang1->refresh();
    $ang2->refresh();
    expect($ang1->status_pencairan)->toBe('menunggu_pencairan')
        ->and($ang1->pencairan_pabd_workflow_id)->toBe($pabdWorkflow->id)
        ->and($ang2->status_pencairan)->toBe('menunggu_pencairan')
        ->and($ang2->pencairan_pabd_workflow_id)->toBe($pabdWorkflow->id);

    // History contains approved + PABD04 created
    $actions = collect($pabdWorkflow->history)->pluck('action')->all();
    expect($actions)->toContain('approved');
    $pabd04CreatedEntry = collect($pabdWorkflow->history)->firstWhere('step', 'PABD04');
    expect($pabd04CreatedEntry['action'])->toBe('created');
});

// ── PABD03 Reject ──

it('rejects PABD03 and cycles back to PABD01', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        'admin.workflows.pabd.pabd03.reject',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd03.reject', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
        'notes' => 'Transfer ditolak, revisi checklist diperlukan.',
    ]);

    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    // Cycle back — fresh PABD01 active
    expect($statuses['PABD01']['status'])->toBe('active')
        ->and($statuses['PABD01']['cycle'])->toBe(2);

    // Fresh pabd01_data created (not the original)
    $freshPabd01 = Pabd01Data::where('pabd_workflow_id', $pabdWorkflow->id)
        ->latest('id')
        ->first();
    expect($freshPabd01->id)->not->toBe($pabd01->id);

    // History contains rejected + PABD01 created with reason
    $rejectedEntry = collect($pabdWorkflow->history)->firstWhere(fn ($e) => ($e['step'] ?? '') === 'PABD03' && ($e['action'] ?? '') === 'rejected');
    expect($rejectedEntry)->not->toBeNull()
        ->and($rejectedEntry['notes'])->toBe('Transfer ditolak, revisi checklist diperlukan.');
});

it('rejects PABD03 reject when notes too short', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        'admin.workflows.pabd.pabd03.reject',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd03.reject', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
        'notes' => 'Too short',
    ]);

    $response->assertSessionHasErrors('notes');
});

// ── PABD03 Permission Denial ──

it('denies PABD03 approve without permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        // Missing: admin.workflows.pabd.pabd03.approve
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd03.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('denies PABD03 reject without permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        // Missing: admin.workflows.pabd.pabd03.reject
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd03.reject', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
        'notes' => 'Some rejection reason that is long enough.',
    ]);

    $response->assertForbidden();
});

// ══════════════════════════════════════════════════════
// PABD04 — Upload Bukti Transfer
// ══════════════════════════════════════════════════════

/**
 * Setup: PABD04 active (PABD03 approved, PABD04 data created).
 */
function setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role): array
{
    [$pabdWorkflow, $pabd01] = setupPabd03Active($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role);

    $engine = new WorkflowEngine;
    $sessionContext = ['role' => $role->id, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id];

    // Approve PABD03
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD03',
        action: 'approved',
        userId: $user->id,
        sessionContext: $sessionContext,
        notes: 'Disetujui.',
    );

    // Create PABD04 data
    $pabd04 = Pabd04Data::create([
        'pabd_workflow_id' => $pabdWorkflow->id,
    ]);

    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD04',
        action: 'created',
        userId: null,
        sessionContext: [],
        table: 'pabd04_data',
        dataId: $pabd04->id,
        extra: ['triggered_by' => ['user_id' => $user->id, 'step' => 'PABD03', 'action' => 'approved']],
    );

    return [$pabdWorkflow, $pabd01, $pabd04];
}

// ── PABD04 Show ──

it('shows PABD04 page for admin Kantor Pusat with draft/submit permissions', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.draft',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('admin.workflows.pabd.pabd04.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd04')
            ->where('mode', 'edit')
            ->where('scope', 'admin')
            ->where('canDraft', true)
            ->where('canSubmit', true)
            ->has('pabd01ChecklistData', 1)
            ->has('summaryTotals')
            ->has('bankDetails')
            ->has('pabd03ApprovalInfo')
            ->has('buktiTransferFiles')
            ->has('expectedUpdatedAt')
        );
});

it('shows PABD04 as readonly for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd04.show',
        'team.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.pabd04.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd04')
            ->where('mode', 'readonly')
            ->where('scope', 'team')
            ->where('canDraft', false)
            ->where('canSubmit', false)
        );
});

// ── PABD04 Draft ──

it('drafts PABD04 with bukti transfer file upload', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.draft',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $fakeFile = \Illuminate\Http\UploadedFile::fake()->create('bukti-transfer.pdf', 100);

    $response = $this->post(route('admin.workflows.pabd.pabd04.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->updated_at->toIso8601String(),
        'bukti_transfer_files' => [$fakeFile],
    ]);

    $response->assertRedirect();

    // File should be linked
    $itemCount = Pabd04ItemBuktiTransfer::where('pabd04_data_id', $pabd04->id)->count();
    expect($itemCount)->toBe(1);

    // History should have drafted
    $pabdWorkflow->refresh();
    $draftedEntry = collect($pabdWorkflow->history)->firstWhere(fn ($e) => ($e['step'] ?? '') === 'PABD04' && ($e['action'] ?? '') === 'drafted');
    expect($draftedEntry)->not->toBeNull();
});

it('drafts PABD04 with file removal', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.draft',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Pre-create a bukti transfer file
    $file = \App\Models\File::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'original_filename' => 'existing.pdf',
        'filename' => 'existing.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
        'disk' => 'local',
        'path' => 'files/test/existing.pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'attachable_type' => Pabd04Data::class,
        'attachable_id' => $pabd04->id,
    ]);

    $item = Pabd04ItemBuktiTransfer::create([
        'pabd04_data_id' => $pabd04->id,
        'file_id' => $file->id,
    ]);

    $pabd04->touch();

    $response = $this->post(route('admin.workflows.pabd.pabd04.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->fresh()->updated_at->toIso8601String(),
        'remove_file_ids' => [$item->id],
    ]);

    $response->assertRedirect();

    // Item should be removed
    $itemCount = Pabd04ItemBuktiTransfer::where('pabd04_data_id', $pabd04->id)->count();
    expect($itemCount)->toBe(0);
});

// ── PABD04 Submit ──

it('submits PABD04 with bukti transfer file', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $fakeFile = \Illuminate\Http\UploadedFile::fake()->create('bukti-transfer.pdf', 100);

    $response = $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->updated_at->toIso8601String(),
        'bukti_transfer_files' => [$fakeFile],
    ]);

    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    // PABD04 should be completed
    expect($statuses['PABD04']['status'])->toBe('completed');

    // File should be linked
    $itemCount = Pabd04ItemBuktiTransfer::where('pabd04_data_id', $pabd04->id)->count();
    expect($itemCount)->toBe(1);

    // History should have submitted
    $submittedEntry = collect($pabdWorkflow->history)->firstWhere(fn ($e) => ($e['step'] ?? '') === 'PABD04' && ($e['action'] ?? '') === 'submitted');
    expect($submittedEntry)->not->toBeNull();
});

it('rejects PABD04 submit with zero files', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->updated_at->toIso8601String(),
    ]);

    // Should fail — no files
    $response->assertStatus(422);
});

it('submits PABD04 with pre-existing files (no new upload needed)', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Pre-create a bukti transfer file (from earlier draft)
    $file = \App\Models\File::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'original_filename' => 'earlier-draft.pdf',
        'filename' => 'earlier-draft.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
        'disk' => 'local',
        'path' => 'files/test/earlier-draft.pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'attachable_type' => Pabd04Data::class,
        'attachable_id' => $pabd04->id,
    ]);

    Pabd04ItemBuktiTransfer::create([
        'pabd04_data_id' => $pabd04->id,
        'file_id' => $file->id,
    ]);

    $pabd04->touch();

    $response = $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->fresh()->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);
    expect($statuses['PABD04']['status'])->toBe('completed');
});

// ── PABD04 Permission Denial ──

it('denies PABD04 draft without draft permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        // Missing: admin.workflows.pabd.pabd04.draft
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd04.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('denies PABD04 submit without submit permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        // Missing: admin.workflows.pabd.pabd04.submit
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $fakeFile = \Illuminate\Http\UploadedFile::fake()->create('bukti.pdf', 100);

    $response = $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->updated_at->toIso8601String(),
        'bukti_transfer_files' => [$fakeFile],
    ]);

    $response->assertForbidden();
});

it('rejects PABD04 draft with stale expected_updated_at', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.draft',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.pabd04.draft', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $response->assertStatus(409);
});

// ── PABD05 — Auto-Compile from PABD04 Submit ──

it('compiles PABD05 when PABD04 is submitted', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Pre-create a bukti transfer file
    $file = \App\Models\File::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'original_filename' => 'bukti-transfer-bca.pdf',
        'filename' => 'bukti-transfer-bca.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1024,
        'disk' => 'local',
        'path' => 'files/test/bukti-transfer-bca.pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'attachable_type' => Pabd04Data::class,
        'attachable_id' => $pabd04->id,
    ]);

    Pabd04ItemBuktiTransfer::create([
        'pabd04_data_id' => $pabd04->id,
        'file_id' => $file->id,
    ]);

    $pabd04->touch();

    $response = $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->fresh()->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);

    // PABD04 should be completed
    expect($statuses['PABD04']['status'])->toBe('completed');

    // PABD05 should be completed
    expect($statuses['PABD05']['status'])->toBe('completed');

    // pabd05_pengajuan_bulanan row should exist
    $pabd05 = Pabd05PengajuanBulanan::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd05)->not->toBeNull();
    expect($pabd05->verification_code)->not->toBeNull();
    expect(strlen($pabd05->verification_code))->toBe(8);

    // Author snapshots should be populated
    expect($pabd05->pabd01_created_by_user_name)->not->toBeNull();
    expect($pabd05->pabd03_approved_by_user_name)->not->toBeNull();
    expect($pabd05->pabd04_created_by_user_name)->not->toBeNull();

    // Bank details should be snapshotted from PP06
    expect($pabd05->nama_bank)->toBe('Bank Mandiri');
    expect($pabd05->nama_rekening)->toBe('Demo Test');
    expect($pabd05->nomor_rekening)->toBe('1234567890');

    // pabd05_item_anggaran rows should exist (2 items from setupPk04WithAnggaran)
    $pabd05Items = Pabd05ItemAnggaran::where('pabd05_pengajuan_bulanan_id', $pabd05->id)->get();
    expect($pabd05Items)->toHaveCount(2);

    // All items were marked dicairkan in setupPabd03Active
    expect($pabd05Items->where('status', 'dicairkan')->count())->toBe(2);

    // Totals should match
    expect((float) $pabd05->total_anggaran_dicairkan)->toBe(3500000.00);
    expect($pabd05->total_item_dicairkan)->toBe(2);
    expect($pabd05->total_item_hangus)->toBe(0);

    // pabd05_bukti_transfer rows should exist
    $pabd05Bukti = Pabd05BuktiTransfer::where('pabd05_pengajuan_bulanan_id', $pabd05->id)->get();
    expect($pabd05Bukti)->toHaveCount(1);
    expect($pabd05Bukti->first()->file_id)->toBe($file->id);

    // pk04_anggaran should have status_pencairan finalized
    $anggaran1->refresh();
    $anggaran2->refresh();
    expect($anggaran1->status_pencairan)->toBe('dicairkan');
    expect($anggaran1->tanggal_pencairan)->not->toBeNull();
    expect($anggaran2->status_pencairan)->toBe('dicairkan');
    expect($anggaran2->tanggal_pencairan)->not->toBeNull();

    // History should have PABD05 completed entry with triggered_by
    $pabd05Entry = collect($pabdWorkflow->history)->firstWhere(fn ($e) => ($e['step'] ?? '') === 'PABD05' && ($e['action'] ?? '') === 'completed');
    expect($pabd05Entry)->not->toBeNull();
    expect($pabd05Entry['triggered_by']['step'])->toBe('PABD04');
    expect($pabd05Entry['triggered_by']['action'])->toBe('submitted');

    // Export file placeholders should exist in history
    expect($pabd05Entry['files'] ?? [])->not->toBeEmpty();

    // Flash message should mention compile
    $response->assertSessionHas('success');
});

it('compiles PABD05 with mixed dicairkan and hangus items', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.comment',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Mark first item dicairkan, second hangus
    $items = $pabd01->itemAnggaran;
    $items[0]->update(['dicairkan' => true]);
    $items[1]->update(['dicairkan' => false]);

    $engine = new WorkflowEngine;
    $sessionContext = ['role' => $role->id, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id];

    // Submit PABD01 (no changes)
    $pabd01->update(['ada_perubahan' => false]);
    $engine->recordAction(workflow: $pabdWorkflow, step: 'PABD01', action: 'submitted', userId: $user->id, sessionContext: $sessionContext, table: 'pabd01_data', dataId: $pabd01->id);
    $engine->recordAction(workflow: $pabdWorkflow, step: 'PABD02A', action: 'skipped', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $pabdWorkflow, step: 'PABD02B', action: 'skipped', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $pabdWorkflow, step: 'PABD03', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $pabdWorkflow, step: 'PABD03', action: 'approved', userId: $user->id, sessionContext: $sessionContext);

    $pabd04 = Pabd04Data::create(['pabd_workflow_id' => $pabdWorkflow->id]);
    $engine->recordAction(workflow: $pabdWorkflow, step: 'PABD04', action: 'created', userId: null, sessionContext: [], table: 'pabd04_data', dataId: $pabd04->id);

    // Add bukti transfer
    $file = \App\Models\File::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'original_filename' => 'bukti.pdf',
        'filename' => 'bukti.pdf',
        'mime_type' => 'application/pdf',
        'size' => 512,
        'disk' => 'local',
        'path' => 'files/test/bukti.pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'attachable_type' => Pabd04Data::class,
        'attachable_id' => $pabd04->id,
    ]);
    Pabd04ItemBuktiTransfer::create(['pabd04_data_id' => $pabd04->id, 'file_id' => $file->id]);
    $pabd04->touch();

    $response = $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->fresh()->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $pabd05 = Pabd05PengajuanBulanan::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd05)->not->toBeNull();
    expect($pabd05->total_item_dicairkan)->toBe(1);
    expect($pabd05->total_item_hangus)->toBe(1);
    expect((float) $pabd05->total_anggaran_dicairkan)->toBe(2500000.00);

    // Check pk04_anggaran statuses
    $anggaran1->refresh();
    $anggaran2->refresh();
    expect($anggaran1->status_pencairan)->toBe('dicairkan');
    expect($anggaran2->status_pencairan)->toBe('hangus');
    expect($anggaran2->tanggal_pencairan)->toBeNull();
});

// ── PABD05 Show ──

it('shows PABD05 page for team scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd05.show',
        'team.workflows.pabd.comment',
        'admin.workflows.pabd.pabd04.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Submit PABD04 to trigger PABD05 compile
    $file = \App\Models\File::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'original_filename' => 'bukti.pdf',
        'filename' => 'bukti.pdf',
        'mime_type' => 'application/pdf',
        'size' => 512,
        'disk' => 'local',
        'path' => 'files/test/bukti.pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'attachable_type' => Pabd04Data::class,
        'attachable_id' => $pabd04->id,
    ]);
    Pabd04ItemBuktiTransfer::create(['pabd04_data_id' => $pabd04->id, 'file_id' => $file->id]);
    $pabd04->touch();

    $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->fresh()->updated_at->toIso8601String(),
    ]);

    // Now visit PABD05 show page
    $response = $this->get(route('team.workflows.pabd.pabd05.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd05')
            ->where('scope', 'team')
            ->where('canComment', true)
            ->has('pabd05')
            ->has('items')
            ->has('buktiTransferFiles')
            ->has('exportFiles')
            ->where('pabd05.verification_code', fn ($code) => strlen($code) === 8)
        );
});

it('shows PABD05 page for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd05.show',
        'admin.workflows.pabd.comment',
        'admin.workflows.pabd.pabd04.submit',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01, $pabd04] = setupPabd04Active($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Submit PABD04
    $file = \App\Models\File::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'original_filename' => 'bukti.pdf',
        'filename' => 'bukti.pdf',
        'mime_type' => 'application/pdf',
        'size' => 512,
        'disk' => 'local',
        'path' => 'files/test/bukti.pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'attachable_type' => Pabd04Data::class,
        'attachable_id' => $pabd04->id,
    ]);
    Pabd04ItemBuktiTransfer::create(['pabd04_data_id' => $pabd04->id, 'file_id' => $file->id]);
    $pabd04->touch();

    $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->fresh()->updated_at->toIso8601String(),
    ]);

    $response = $this->get(route('admin.workflows.pabd.pabd05.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pabd/pabd05')
            ->where('scope', 'admin')
            ->has('pabd05.nama_bank')
            ->has('pabd05.nama_rekening')
            ->has('pabd05.nomor_rekening')
        );
});

it('returns 404 for PABD05 show before compile', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd05.show',
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.pabd05.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertStatus(404);
});

it('denies PABD05 show without permission', function () {
    [$user, $role, $workspace, $team] = setupPabdUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.submit',
        // Missing: team.workflows.pabd.pabd05.show
    );
    activatePabdSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPabd($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04WithAnggaran($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdWorkflow($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->get(route('team.workflows.pabd.pabd05.show', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]));

    $response->assertForbidden();
});
