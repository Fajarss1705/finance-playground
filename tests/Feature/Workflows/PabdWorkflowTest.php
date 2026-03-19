<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\Pabd02aData;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk04Anggaran;
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
