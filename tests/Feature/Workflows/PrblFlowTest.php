<?php

use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04Kuisioner;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\Prbl01ItemKegiatan;
use App\Models\Prbl\Prbl01ItemKuisioner;
use App\Models\Prbl\Prbl01ItemRealisasi;
use App\Models\Prbl\Prbl03Data;
use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\PrblWorkflow;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\WorkflowEngine;
use App\Workflows\PrblWorkflowDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
});

// ── Helpers (prefixed to avoid collisions) ──

function prblFlowUser(string ...$permissionNames): array
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

function prblFlowSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function prblFlowPp06Snapshot(): array
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

function prblFlowSetupPp($workspace, $team): array
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
        ...prblFlowPp06Snapshot(),
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

function prblFlowSetupPk04($workspace, $team, $ppWorkflow, $bulan, $user, $role): array
{
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) Str::uuid(),
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

    $kuisioner = Pk04Kuisioner::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_kuisioner' => 'Q01',
        'pertanyaan' => 'Berapa peserta?',
        'tipe' => 'Kuantitatif',
        'satuan' => 'orang',
    ]);

    return [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner];
}

function prblFlowSetupPrbl($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, $bulan, $user, $role): array
{
    // Create completed PABD + mark anggaran as dicairkan
    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => $bulan,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $ang1->update(['status_pencairan' => 'dicairkan', 'pencairan_pabd_workflow_id' => $pabdWorkflow->id]);
    $ang2->update(['status_pencairan' => 'dicairkan', 'pencairan_pabd_workflow_id' => $pabdWorkflow->id]);

    // Create PRBL workflow + PRBL01 data
    $prblWorkflow = PrblWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => $bulan,
        'tahun_laporan' => 2027,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [],
    ]);

    $prbl01 = Prbl01Data::create(['prbl_workflow_id' => $prblWorkflow->id]);

    $itemKegiatan = Prbl01ItemKegiatan::create([
        'prbl01_data_id' => $prbl01->id,
        'pk04_kegiatan_id' => $kegiatan->id,
    ]);

    $itemKuisioner = Prbl01ItemKuisioner::create([
        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
        'pk04_kuisioner_id' => $kuisioner->id,
    ]);

    $realisasi1 = Prbl01ItemRealisasi::create([
        'prbl01_data_id' => $prbl01->id,
        'pk04_anggaran_id' => $ang1->id,
        'nominal_realisasi' => 0,
    ]);

    $realisasi2 = Prbl01ItemRealisasi::create([
        'prbl01_data_id' => $prbl01->id,
        'pk04_anggaran_id' => $ang2->id,
        'nominal_realisasi' => 0,
    ]);

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

    return [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2];
}

// ── End-to-End Flow Tests ──

it('completes full PRBL flow with parallel approval (happy path)', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = prblFlowUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.submit',
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.approve',
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
        'admin.workflows.prbl.prbl04.show',
        'admin.workflows.prbl.prbl04.approve',
        'admin.workflows.prbl.prbl05.show',
        'admin.workflows.prbl.comment',
        'team.workflows.prbl.comment',
    );
    prblFlowSession($this, $user, $role, $workspace);

    [$ppWorkflow] = prblFlowSetupPp($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = prblFlowSetupPk04($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = prblFlowSetupPrbl($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;

    // ── Step 1: Submit PRBL01 ──
    $response = $this->post(route('team.workflows.prbl.prbl01.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [[
            'prbl01_item_kegiatan_id' => $itemKegiatan->id,
            'masalah' => 'Kurang peserta',
            'langkah_penanganan' => 'Promosi lebih gencar',
            'harapan' => 'Peserta bertambah',
            'catatan_tim' => 'Perlu koordinasi',
            'kuisioner' => [[
                'prbl01_item_kuisioner_id' => $itemKuisioner->id,
                'jawaban' => '30',
            ]],
        ]],
        'realisasi' => [
            ['prbl01_item_realisasi_id' => $realisasi1->id, 'nominal_realisasi' => 2000000],
            ['prbl01_item_realisasi_id' => $realisasi2->id, 'nominal_realisasi' => 800000],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ]);
    $response->assertRedirect();

    $prblWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL01']['status'])->toBe('completed')
        ->and($statuses['PRBL02A']['status'])->toBe('active')
        ->and($statuses['PRBL02B']['status'])->toBe('active');

    // ── Step 2: Approve PRBL02A (first track — waits) ──
    $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Narasi sesuai.',
    ])->assertRedirect();

    $prblWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('active')
        ->and($statuses['PRBL03']['status'])->toBe('pending'); // still waiting

    // ── Step 3: Approve PRBL02B (join → creates PRBL03) ──
    $this->post(route('admin.workflows.prbl.prbl02b.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Anggaran sesuai.',
    ])->assertRedirect();

    $prblWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('completed')
        ->and($statuses['PRBL03']['status'])->toBe('active');

    $prbl03 = Prbl03Data::where('prbl_workflow_id', $prblWorkflow->id)->latest('id')->first();
    expect($prbl03)->not->toBeNull();

    // ── Step 4: Submit PRBL03 (refund) ──
    $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03->id,
    ]), [
        'expected_updated_at' => $prbl03->updated_at->toIso8601String(),
        'keterangan' => 'Refund ditransfer.',
        'bukti_transfer_files' => [UploadedFile::fake()->image('bukti.jpg')],
        'foto_nota_files' => [UploadedFile::fake()->image('nota.jpg')],
    ])->assertRedirect();

    $prblWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL03']['status'])->toBe('completed')
        ->and($statuses['PRBL04']['status'])->toBe('active');

    // ── Step 5: Approve PRBL04 → auto-compile PRBL05 ──
    $this->post(route('admin.workflows.prbl.prbl04.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Semua lengkap.',
    ])->assertRedirect();

    $prblWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL04']['status'])->toBe('completed')
        ->and($statuses['PRBL05']['status'])->toBe('completed');

    // Workflow completed
    expect($engine->getWorkflowStatus($prblWorkflow->history))->toBe('completed');

    // PRBL05 compiled
    $prbl05 = Prbl05LaporanBulanan::where('prbl_workflow_id', $prblWorkflow->id)->first();
    expect($prbl05)->not->toBeNull()
        ->and($prbl05->verification_code)->not->toBeNull()
        ->and(strlen($prbl05->verification_code))->toBe(8);

    // History chain complete
    $steps = collect($prblWorkflow->history)->pluck('step')->unique()->values()->all();
    expect($steps)->toContain('PRBL01', 'PRBL02A', 'PRBL02B', 'PRBL03', 'PRBL04', 'PRBL05');
});

it('completes PRBL flow with dual rejection to PRBL03 then approve', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = prblFlowUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.submit',
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.prbl02b.approve',
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
        'admin.workflows.prbl.prbl04.show',
        'admin.workflows.prbl.prbl04.approve',
        'admin.workflows.prbl.prbl04.reject',
        'admin.workflows.prbl.prbl05.show',
        'admin.workflows.prbl.comment',
        'team.workflows.prbl.comment',
    );
    prblFlowSession($this, $user, $role, $workspace);

    [$ppWorkflow] = prblFlowSetupPp($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = prblFlowSetupPk04($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = prblFlowSetupPrbl($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;

    // ── Advance to PRBL04 via engine records (not controller) ──
    // Note: travel(1)->seconds() between calls ensures unique ISO 8601 timestamps
    // for WorkflowEngine::hasValidAction() strict > comparison in cycle-back detection.

    // Submit PRBL01 via controller
    $this->travel(1)->seconds();
    $this->post(route('team.workflows.prbl.prbl01.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [[
            'prbl01_item_kegiatan_id' => $itemKegiatan->id,
            'masalah' => 'Kurang peserta',
            'langkah_penanganan' => 'Promosi gencar',
            'harapan' => 'Peserta bertambah',
            'catatan_tim' => 'Catatan',
            'kuisioner' => [[
                'prbl01_item_kuisioner_id' => $itemKuisioner->id,
                'jawaban' => '25',
            ]],
        ]],
        'realisasi' => [
            ['prbl01_item_realisasi_id' => $realisasi1->id, 'nominal_realisasi' => 2000000],
            ['prbl01_item_realisasi_id' => $realisasi2->id, 'nominal_realisasi' => 800000],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ])->assertRedirect();

    // Approve both parallel tracks
    $prblWorkflow->refresh();
    $this->travel(1)->seconds();
    $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ])->assertRedirect();

    $prblWorkflow->refresh();
    $this->travel(1)->seconds();
    $this->post(route('admin.workflows.prbl.prbl02b.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ])->assertRedirect();

    // Submit PRBL03
    $prblWorkflow->refresh();
    $prbl03 = Prbl03Data::where('prbl_workflow_id', $prblWorkflow->id)->latest('id')->first();

    $this->travel(1)->seconds();
    $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03->id,
    ]), [
        'expected_updated_at' => $prbl03->updated_at->toIso8601String(),
        'keterangan' => 'Refund pertama.',
        'bukti_transfer_files' => [UploadedFile::fake()->image('bukti1.jpg')],
        'foto_nota_files' => [UploadedFile::fake()->image('nota1.jpg')],
    ])->assertRedirect();

    // ── Reject PRBL04 to PRBL03 (minor) ──
    $prblWorkflow->refresh();
    $this->travel(1)->seconds();
    $this->post(route('admin.workflows.prbl.prbl04.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'rejection_target' => 'PRBL03',
        'notes' => 'Bukti transfer tidak jelas.',
    ])->assertRedirect();

    $prblWorkflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL03']['status'])->toBe('active')
        ->and($statuses['PRBL01']['status'])->toBe('completed')
        ->and($statuses['PRBL02A']['status'])->toBe('completed');

    // ── Re-submit PRBL03 ──
    $freshPrbl03 = Prbl03Data::where('prbl_workflow_id', $prblWorkflow->id)->latest('id')->first();
    expect($freshPrbl03->id)->not->toBe($prbl03->id);

    $this->travel(1)->seconds();
    $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $freshPrbl03->id,
    ]), [
        'expected_updated_at' => $freshPrbl03->updated_at->toIso8601String(),
        'keterangan' => 'Refund kedua, bukti lebih jelas.',
        'bukti_transfer_files' => [UploadedFile::fake()->image('bukti2.jpg')],
        'foto_nota_files' => [UploadedFile::fake()->image('nota2.jpg')],
    ])->assertRedirect();

    // ── Approve PRBL04 → PRBL05 compile ──
    $prblWorkflow->refresh();
    $this->travel(1)->seconds();
    $this->post(route('admin.workflows.prbl.prbl04.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Lengkap setelah revisi.',
    ])->assertRedirect();

    $prblWorkflow->refresh();
    expect($engine->getWorkflowStatus($prblWorkflow->history))->toBe('completed');

    $prbl05 = Prbl05LaporanBulanan::where('prbl_workflow_id', $prblWorkflow->id)->first();
    expect($prbl05)->not->toBeNull()
        ->and($prbl05->verification_code)->not->toBeNull();

    // Verify rejection is in history
    $rejectedEntry = collect($prblWorkflow->history)->first(fn ($e) => ($e['step'] ?? '') === 'PRBL04' && ($e['action'] ?? '') === 'rejected');
    expect($rejectedEntry)->not->toBeNull()
        ->and($rejectedEntry['cycleTarget'])->toBe('PRBL03')
        ->and($rejectedEntry['notes'])->toBe('Bukti transfer tidak jelas.');
});

it('passes latestRejectionInfo to PRBL show page after PRBL04 rejection', function () {
    [$user, $role, $workspace, $team] = prblFlowUser(
        'team.workflows.prbl.show',
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.submit',
        'admin.workflows.prbl.show',
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.approve',
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
        'admin.workflows.prbl.prbl04.show',
        'admin.workflows.prbl.prbl04.approve',
        'admin.workflows.prbl.prbl04.reject',
        'admin.workflows.prbl.comment',
        'team.workflows.prbl.comment',
    );
    prblFlowSession($this, $user, $role, $workspace);

    [$ppWorkflow] = prblFlowSetupPp($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = prblFlowSetupPk04($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = prblFlowSetupPrbl($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    // Submit PRBL01
    $this->travel(1)->seconds();
    $this->post(route('team.workflows.prbl.prbl01.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [[
            'prbl01_item_kegiatan_id' => $itemKegiatan->id,
            'masalah' => 'Kendala',
            'langkah_penanganan' => 'Penanganan',
            'harapan' => 'Harapan',
            'catatan_tim' => 'Catatan',
            'kuisioner' => [[
                'prbl01_item_kuisioner_id' => $itemKuisioner->id,
                'jawaban' => '30',
            ]],
        ]],
        'realisasi' => [
            ['prbl01_item_realisasi_id' => $realisasi1->id, 'nominal_realisasi' => 2000000],
            ['prbl01_item_realisasi_id' => $realisasi2->id, 'nominal_realisasi' => 800000],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ])->assertRedirect();

    // Approve PRBL02A + PRBL02B
    $prblWorkflow->refresh();
    $this->travel(1)->seconds();
    $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ])->assertRedirect();

    $prblWorkflow->refresh();
    $this->travel(1)->seconds();
    $this->post(route('admin.workflows.prbl.prbl02b.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ])->assertRedirect();

    // Submit PRBL03
    $prblWorkflow->refresh();
    $prbl03 = Prbl03Data::where('prbl_workflow_id', $prblWorkflow->id)->latest('id')->first();

    $this->travel(1)->seconds();
    $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03->id,
    ]), [
        'expected_updated_at' => $prbl03->updated_at->toIso8601String(),
        'keterangan' => 'Refund.',
        'bukti_transfer_files' => [UploadedFile::fake()->image('bukti.jpg')],
        'foto_nota_files' => [UploadedFile::fake()->image('nota.jpg')],
    ])->assertRedirect();

    // Reject PRBL04 to PRBL01 (major)
    $prblWorkflow->refresh();
    $this->travel(1)->seconds();
    $this->post(route('admin.workflows.prbl.prbl04.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'rejection_target' => 'PRBL01',
        'notes' => 'Realisasi anggaran tidak sesuai bukti nota.',
    ])->assertRedirect();

    // Visit PRBL show page — verify latestRejectionInfo
    $prblWorkflow->refresh();
    $response = $this->get(route('team.workflows.prbl.show', ['prblWorkflow' => $prblWorkflow->id]));
    $response->assertSuccessful();

    $props = $response->viewData('page')['props'];

    expect($props['latestRejectionInfo'])->not->toBeNull()
        ->and($props['latestRejectionInfo']['target'])->toBe('PRBL01')
        ->and($props['latestRejectionInfo']['notes'])->toBe('Realisasi anggaran tidak sesuai bukti nota.')
        ->and($props['latestRejectionInfo']['by_name'])->not->toBeNull()
        ->and($props['latestRejectionInfo']['role_name'])->not->toBeNull();

    // Also verify PRBL01 re-entry gets cycleBackNotes
    $freshPrbl01 = Prbl01Data::where('prbl_workflow_id', $prblWorkflow->id)
        ->latest('id')
        ->first();

    $response2 = $this->get(route('team.workflows.prbl.prbl01.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $freshPrbl01->id,
    ]));
    $response2->assertSuccessful();

    $props2 = $response2->viewData('page')['props'];
    expect($props2['isReentry'])->toBeTrue()
        ->and($props2['cycleBackNotes'])->not->toBeNull()
        ->and($props2['cycleBackNotes']['source'])->toBe('prbl04')
        ->and($props2['cycleBackNotes']['prbl04']['notes'])->toBe('Realisasi anggaran tidak sesuai bukti nota.');
});
