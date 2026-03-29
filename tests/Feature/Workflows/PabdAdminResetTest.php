<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\WorkflowEngine;

beforeEach(function () {
    $this->withoutVite();
});

function setupAdminResetUser(string ...$permissionNames): array
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

function activateAdminResetSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function pp06AuthorSnapshotForReset(): array
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

function setupPpForReset($workspace, $team): array
{
    $ppWorkflow = \App\Models\Pp\PpWorkflow::create([
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
        ...pp06AuthorSnapshotForReset(),
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

function setupPk04ForReset($workspace, $team, $ppWorkflow, $bulan = 3, $user = null, $role = null): array
{
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

function setupPabdForReset($workspace, $team, $ppWorkflow, $pk04, $bulan = 3, $user = null, $role = null): array
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

// ── Scenario 3: Reset active in-progress PABD ──

it('admin resets active PABD back to PABD01 with fresh data', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.show',
        'admin.workflows.pabd.admin_reset',
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupPpForReset($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2] = setupPk04ForReset($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdForReset($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Advance to PABD01 submitted
    $engine = new WorkflowEngine;
    $pabd01->update(['ada_perubahan' => false]);
    $pabd01->itemAnggaran()->update(['dicairkan' => true]);
    $engine->recordAction(
        workflow: $pabdWorkflow,
        step: 'PABD01',
        action: 'submitted',
        userId: $user->id,
        sessionContext: ['role' => $role->id, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id],
        table: 'pabd01_data',
        dataId: $pabd01->id,
    );

    $pabdWorkflow->refresh();

    $response = $this->post(route('admin.workflows.pabd.admin_reset', $pabdWorkflow), [
        'notes' => 'Admin reset for testing',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $pabdWorkflow->refresh();
    $history = $pabdWorkflow->history;

    // History should have the reset entry
    $resetEntry = collect($history)->first(fn ($e) => ($e['action'] ?? '') === 'reset' && ($e['reason'] ?? '') === 'admin_reset');
    expect($resetEntry)->not->toBeNull();
    expect($resetEntry['by'])->toBe($user->id);
    expect($resetEntry['notes'])->toBe('Admin reset for testing');

    // History should have a new PABD01 created entry after reset
    $createdEntries = collect($history)->filter(fn ($e) => ($e['step'] ?? '') === 'PABD01' && ($e['action'] ?? '') === 'created');
    expect($createdEntries)->toHaveCount(2); // Original + fresh

    $freshCreated = $createdEntries->last();
    expect($freshCreated['reason'])->toBe('admin_reset');

    // Fresh PABD01 data should exist
    $freshPabd01 = Pabd01Data::find($freshCreated['id']);
    expect($freshPabd01)->not->toBeNull();
    expect($freshPabd01->pabd_workflow_id)->toBe($pabdWorkflow->id);
    expect($freshPabd01->ada_perubahan)->toBeFalse();

    // Items should exist for the new PABD01
    expect($freshPabd01->itemAnggaran()->count())->toBe(2);

    // Workflow should be active at PABD01
    $definition = $engine->resolveDefinition(\App\Enums\WorkflowType::PABD);
    $currentSteps = $engine->getCurrentSteps($definition, $pabdWorkflow->history);
    expect($currentSteps)->toBe(['PABD01']);
});

// ── Scenario 2: Already completed — reject reset ──

it('admin cannot reset completed PABD', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.show',
        'admin.workflows.pabd.admin_reset',
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupPpForReset($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04ForReset($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = setupPabdForReset($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Mark as completed via PABD05
    $pabd05 = Pabd05PengajuanBulanan::create([
        'pabd_workflow_id' => $pabdWorkflow->id,
        'verification_code' => 'TEST1234',
        'total_anggaran_dicairkan' => 2500000,
        'total_item_dicairkan' => 1,
        'total_item_hangus' => 1,
        'pabd01_created_by_user_name' => 'Test',
        'pabd01_created_by_role_name' => 'Bapel',
        'pabd01_created_by_team_name' => $team->name,
        'pabd01_created_by_organization_name' => 'Org',
        'pabd01_created_by_workspace_name' => $workspace->name,
        'pabd01_created_at' => now(),
        'pabd03_approved_by_user_name' => 'Test',
        'pabd03_approved_by_role_name' => 'BU',
        'pabd03_approved_by_team_name' => 'Tim BU',
        'pabd03_approved_by_organization_name' => 'Org',
        'pabd03_approved_by_workspace_name' => $workspace->name,
        'pabd03_approved_at' => now(),
        'pabd04_created_by_user_name' => 'Test',
        'pabd04_created_by_role_name' => 'KG',
        'pabd04_created_by_team_name' => 'Tim KG',
        'pabd04_created_by_organization_name' => 'Org',
        'pabd04_created_by_workspace_name' => $workspace->name,
        'pabd04_created_at' => now(),
        'nama_bank' => 'Bank Mandiri',
        'nama_rekening' => 'Demo Test',
        'nomor_rekening' => '1234567890',
    ]);

    $pabdWorkflow->update(['history' => [
        ...$pabdWorkflow->history,
        ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pabd05_pengajuan_bulanan', 'id' => $pabd05->id],
    ]]);

    $response = $this->post(route('admin.workflows.pabd.admin_reset', $pabdWorkflow));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'PABD sudah selesai (PABD05). Tidak dapat direset.');

    // History should NOT have a reset entry
    $pabdWorkflow->refresh();
    $resetEntry = collect($pabdWorkflow->history)->first(fn ($e) => ($e['action'] ?? '') === 'reset' && ($e['reason'] ?? '') === 'admin_reset');
    expect($resetEntry)->toBeNull();
});

// ── Permission denied ──

it('denies admin reset without permission', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.show',
        // Missing: admin.workflows.pabd.admin_reset
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupPpForReset($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04ForReset($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow] = setupPabdForReset($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.admin_reset', $pabdWorkflow));

    $response->assertForbidden();
});

// ── canAdminReset prop on index ──

it('passes canAdminReset prop to admin index page', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.admin_reset',
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.workflows.pabd.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/pabd/index')
        ->where('canAdminReset', true)
        ->where('scope', 'admin')
    );
});

it('does not pass canAdminReset when user lacks permission', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        // Missing: admin.workflows.pabd.admin_reset
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.workflows.pabd.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workflows/pabd/index')
        ->where('canAdminReset', false)
    );
});

// ── Admin Create — Scenario 1: Create new PABD ──

it('admin creates new PABD for team+month that has no PABD', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.admin_reset',
        'admin.workflows.pabd.admin_create',
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupPpForReset($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2] = setupPk04ForReset($workspace, $team, $ppWorkflow, 3, $user, $role);

    // No PABD exists yet — admin creates one
    $response = $this->post(route('admin.workflows.pabd.admin_create'), [
        'team_id' => $team->id,
        'bulan' => 3,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'PABD berhasil dibuat.');

    // Verify PABD was created
    $pabdWorkflow = PabdWorkflow::where('team_id', $team->id)
        ->where('bulan_anggaran', 3)
        ->first();
    expect($pabdWorkflow)->not->toBeNull();
    expect($pabdWorkflow->workspace_id)->toBe($workspace->id);
    expect($pabdWorkflow->pp_workflow_id)->toBe($ppWorkflow->id);
    expect($pabdWorkflow->tahun_anggaran)->toBe(2027);

    // PABD01 data should exist
    $history = $pabdWorkflow->history;
    $createdEntry = collect($history)->first(fn ($e) => ($e['step'] ?? '') === 'PABD01' && ($e['action'] ?? '') === 'created');
    expect($createdEntry)->not->toBeNull();
    expect($createdEntry['reason'])->toBe('admin_create');

    $pabd01 = Pabd01Data::find($createdEntry['id']);
    expect($pabd01)->not->toBeNull();
    expect($pabd01->itemAnggaran()->count())->toBe(2);

    // Workflow should be active at PABD01
    $engine = new WorkflowEngine;
    $definition = $engine->resolveDefinition(\App\Enums\WorkflowType::PABD);
    $currentSteps = $engine->getCurrentSteps($definition, $pabdWorkflow->history);
    expect($currentSteps)->toBe(['PABD01']);
});

it('admin cannot create PABD that already exists', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.admin_reset',
        'admin.workflows.pabd.admin_create',
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupPpForReset($workspace, $team);
    [$pkWorkflow, $pk04] = setupPk04ForReset($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow] = setupPabdForReset($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    // Try to create duplicate PABD for same team+month
    $response = $this->post(route('admin.workflows.pabd.admin_create'), [
        'team_id' => $team->id,
        'bulan' => 3,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'PABD untuk tim dan bulan ini sudah ada.');
});

it('admin cannot create PABD for month without active anggaran', function () {
    [$user, $role, $workspace, $team] = setupAdminResetUser(
        'admin.workflows.pabd.index',
        'admin.workflows.pabd.admin_reset',
        'admin.workflows.pabd.admin_create',
    );
    activateAdminResetSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupPpForReset($workspace, $team);
    // Anggaran is in month 3, but we try month 6
    setupPk04ForReset($workspace, $team, $ppWorkflow, 3, $user, $role);

    $response = $this->post(route('admin.workflows.pabd.admin_create'), [
        'team_id' => $team->id,
        'bulan' => 6,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Tidak ada anggaran aktif untuk bulan ini.');
});
