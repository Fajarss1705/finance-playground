<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\Pabd04Data;
use App\Models\Pabd\Pabd05ItemAnggaran;
use App\Models\Pabd\Pabd05PengajuanBulanan;
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

// ── Helpers (prefixed to avoid collisions with PabdWorkflowTest) ──

function flowUser(string ...$permissionNames): array
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

function flowSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function flowPp06Snapshot(): array
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

function flowSetupPp($workspace, $team): array
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
        ...flowPp06Snapshot(),
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

function flowSetupPk04($workspace, $team, $ppWorkflow, $bulan, $user, $role): array
{
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [
            ['step' => 'PK04', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $pk04 = Pk04ProgramTahunan::create([
        'pk_workflow_id' => $pkWorkflow->id,
        'revision' => 0,
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Kegiatan',
        'deskripsi_program' => 'Deskripsi',
        'tujuan_program' => 'Tujuan',
        'nomer_program' => 1,
        'pk01_created_by_user_name' => $user->name,
        'pk01_created_by_role_name' => 'Bapel',
        'pk01_created_by_team_name' => $team->name,
        'pk01_created_by_organization_name' => $team->organization->name,
        'pk01_created_by_workspace_name' => $workspace->name,
        'pk01_created_at' => now(),
    ]);

    $kegiatan = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Retreat Remaja',
        'bulan' => $bulan,
        'nomer_kegiatan' => 1,
    ]);

    $ang1 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Konsumsi retreat',
        'nominal_anggaran' => 2500000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => "R.01.01.01.01.01.001.001.001.2027.0{$bulan}.rev0.M0",
        'status_item' => 'active',
    ]);

    $ang2 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Transportasi',
        'deskripsi_pk' => 'Transport retreat',
        'nominal_anggaran' => 1000000,
        'nomer_anggaran' => 2,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => "R.01.01.01.01.01.001.001.002.2027.0{$bulan}.rev0.M0",
        'status_item' => 'active',
    ]);

    return [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2];
}

function flowSetupPabd($workspace, $team, $ppWorkflow, $pk04, $bulan, $user, $role): array
{
    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => $bulan,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
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

// ── End-to-End Flow Tests ──

it('completes full PABD flow without changes (happy path)', function () {
    // User with all PABD team + admin permissions (simulates multi-role access)
    [$user, $role, $workspace, $team] = flowUser(
        'team.workflows.pabd.index',
        'team.workflows.pabd.show',
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.draft',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.pabd05.show',
        'admin.workflows.pabd.comment',
    );
    flowSession($this, $user, $role, $workspace);

    [$ppWorkflow] = flowSetupPp($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2] = flowSetupPk04($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = flowSetupPabd($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $items = $pabd01->itemAnggaran;
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;

    // ── Step 1: Submit PABD01 (no changes → skip 02A/02B → activate PABD03) ──
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
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);
    expect($statuses['PABD01']['status'])->toBe('completed')
        ->and($statuses['PABD02A']['status'])->toBe('completed') // skipped
        ->and($statuses['PABD02B']['status'])->toBe('completed') // skipped
        ->and($statuses['PABD03']['status'])->toBe('active');

    // ── Step 2: Approve PABD03 ──
    $response = $this->post(route('admin.workflows.pabd.pabd03.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
        'notes' => 'Transfer disetujui.',
    ]);
    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);
    expect($statuses['PABD03']['status'])->toBe('completed')
        ->and($statuses['PABD04']['status'])->toBe('active');

    // Verify Tier 2 lock applied
    $ang1->refresh();
    $ang2->refresh();
    expect($ang1->status_pencairan)->toBe('menunggu_pencairan')
        ->and($ang2->status_pencairan)->toBe('menunggu_pencairan');

    // ── Step 3: Submit PABD04 (bukti transfer) ──
    $pabd04 = Pabd04Data::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd04)->not->toBeNull();

    $fakeFile = \Illuminate\Http\UploadedFile::fake()->create('bukti-transfer.pdf', 100);

    $response = $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->updated_at->toIso8601String(),
        'bukti_transfer_files' => [$fakeFile],
    ]);
    $response->assertRedirect();

    // ── Verify PABD05 compiled ──
    $pabdWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);
    expect($statuses['PABD04']['status'])->toBe('completed')
        ->and($statuses['PABD05']['status'])->toBe('completed');

    // Workflow completed
    expect($engine->getWorkflowStatus($pabdWorkflow->history))->toBe('completed');

    // PABD05 data exists with verification code
    $pabd05 = Pabd05PengajuanBulanan::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd05)->not->toBeNull()
        ->and($pabd05->verification_code)->not->toBeNull()
        ->and(strlen($pabd05->verification_code))->toBe(8);

    // Snapshot items created
    $snapshotItems = Pabd05ItemAnggaran::where('pabd05_pengajuan_bulanan_id', $pabd05->id)->get();
    expect($snapshotItems)->toHaveCount(2)
        ->and($snapshotItems->where('status', 'dicairkan')->count())->toBe(2);

    // PK04 anggaran finalized
    $ang1->refresh();
    $ang2->refresh();
    expect($ang1->status_pencairan)->toBe('dicairkan')
        ->and($ang1->tanggal_pencairan)->not->toBeNull()
        ->and($ang2->status_pencairan)->toBe('dicairkan');

    // History has complete chain
    $actions = collect($pabdWorkflow->history)->pluck('action')->all();
    expect($actions)->toContain('created', 'submitted', 'skipped', 'approved', 'completed');
});

it('completes PABD flow with cycle-back from PABD03 rejection', function () {
    [$user, $role, $workspace, $team] = flowUser(
        'team.workflows.pabd.pabd01.show',
        'team.workflows.pabd.pabd01.draft',
        'team.workflows.pabd.pabd01.submit',
        'admin.workflows.pabd.pabd03.show',
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.pabd03.reject',
        'admin.workflows.pabd.pabd04.show',
        'admin.workflows.pabd.pabd04.draft',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.comment',
    );
    flowSession($this, $user, $role, $workspace);

    [$ppWorkflow] = flowSetupPp($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2] = flowSetupPk04($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$pabdWorkflow, $pabd01] = flowSetupPabd($workspace, $team, $ppWorkflow, $pk04, 3, $user, $role);

    $items = $pabd01->itemAnggaran;
    $engine = new WorkflowEngine;
    $definition = new PabdWorkflowDefinition;

    // ── Cycle 1: Submit PABD01 → PABD03 reject ──
    // Travel between controller calls to ensure unique timestamps in history
    // (workflow engine uses second-precision ISO 8601 for cycle-back detection)
    $this->travel(1)->seconds();

    $this->post(route('team.workflows.pabd.pabd01.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $pabd01->id,
    ]), [
        'ada_perubahan' => false,
        'items' => [
            ['pabd01_item_anggaran_id' => $items[0]->id, 'dicairkan' => true],
            ['pabd01_item_anggaran_id' => $items[1]->id, 'dicairkan' => true],
        ],
        'expected_updated_at' => $pabd01->updated_at->toIso8601String(),
    ])->assertRedirect();

    $this->travel(1)->seconds();
    $pabdWorkflow->refresh();

    $response = $this->post(route('admin.workflows.pabd.pabd03.reject', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
        'notes' => 'Checklist pencairan perlu direvisi.',
    ]);
    $response->assertRedirect();

    $pabdWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $pabdWorkflow->history);
    expect($statuses['PABD01']['status'])->toBe('active')
        ->and($statuses['PABD01']['cycle'])->toBe(2);

    // Fresh PABD01 data created
    $freshPabd01 = Pabd01Data::where('pabd_workflow_id', $pabdWorkflow->id)
        ->latest('id')
        ->first();
    expect($freshPabd01->id)->not->toBe($pabd01->id);

    // ── Cycle 2: Submit fresh PABD01 → PABD03 approve → PABD04 → PABD05 ──
    $this->travel(1)->seconds();
    $freshItems = $freshPabd01->itemAnggaran;

    $this->post(route('team.workflows.pabd.pabd01.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd01Data' => $freshPabd01->id,
    ]), [
        'ada_perubahan' => false,
        'items' => $freshItems->map(fn ($item) => [
            'pabd01_item_anggaran_id' => $item->id,
            'dicairkan' => true,
        ])->all(),
        'expected_updated_at' => $freshPabd01->updated_at->toIso8601String(),
    ])->assertRedirect();

    $this->travel(1)->seconds();
    $pabdWorkflow->refresh();

    $this->post(route('admin.workflows.pabd.pabd03.approve', [
        'pabdWorkflow' => $pabdWorkflow->id,
    ]), [
        'expected_updated_at' => $pabdWorkflow->updated_at->toIso8601String(),
        'notes' => 'Disetujui setelah revisi.',
    ])->assertRedirect();

    $this->travel(1)->seconds();
    $pabdWorkflow->refresh();

    $pabd04 = Pabd04Data::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd04)->not->toBeNull();

    $fakeFile = \Illuminate\Http\UploadedFile::fake()->create('bukti.pdf', 100);

    $this->post(route('admin.workflows.pabd.pabd04.submit', [
        'pabdWorkflow' => $pabdWorkflow->id,
        'pabd04Data' => $pabd04->id,
    ]), [
        'expected_updated_at' => $pabd04->updated_at->toIso8601String(),
        'bukti_transfer_files' => [$fakeFile],
    ])->assertRedirect();

    // ── Verify complete ──
    $pabdWorkflow->refresh();
    expect($engine->getWorkflowStatus($pabdWorkflow->history))->toBe('completed');

    $pabd05 = Pabd05PengajuanBulanan::where('pabd_workflow_id', $pabdWorkflow->id)->first();
    expect($pabd05)->not->toBeNull()
        ->and($pabd05->verification_code)->not->toBeNull();

    // History shows rejection cycle
    $rejectedEntry = collect($pabdWorkflow->history)->first(fn ($e) => ($e['step'] ?? '') === 'PABD03' && ($e['action'] ?? '') === 'rejected');
    expect($rejectedEntry)->not->toBeNull()
        ->and($rejectedEntry['notes'])->toBe('Checklist pencairan perlu direvisi.');
});
