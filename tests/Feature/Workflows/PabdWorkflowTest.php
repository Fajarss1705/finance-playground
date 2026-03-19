<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\Pabd02aData;
use App\Models\Pabd\Pabd02aItemPerubahan;
use App\Models\Pabd\Pabd02bData;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk01Data;
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
