<?php

use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04Kuisioner;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\PrblWorkflow;
use App\Models\User;
use App\Services\WorkflowEngine;
use Carbon\Carbon;
use Illuminate\Support\Str;

// ── Helpers ──

function chainPp06Snapshot(): array
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

/**
 * Setup PP + PK04 with kegiatan in multiple months for chain testing.
 */
function chainSetupData(array $months = [4, 5]): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;

    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    Pp01Data::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->subDays(60)->toDateString(),
        'tanggal_penetapan_program' => now()->subDays(10)->toDateString(),
    ]);

    Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->subDays(60)->toDateString(),
        'tanggal_penetapan_program' => now()->subDays(10)->toDateString(),
        ...chainPp06Snapshot(),
    ]);

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

    $kegiatanMap = [];
    $anggaranMap = [];
    $nomerKegiatan = 1;

    foreach ($months as $month) {
        $kegiatan = Pk04Kegiatan::create([
            'pk04_program_tahunan_id' => $pk04->id,
            'nama_kegiatan' => "Kegiatan Bulan {$month}",
            'bulan' => $month,
            'nomer_kegiatan' => $nomerKegiatan++,
        ]);

        $anggaran = Pk04Anggaran::create([
            'pk04_kegiatan_id' => $kegiatan->id,
            'kode_bidang' => 'B01',
            'kode_sub_bidang' => 'SB01',
            'kode_jenis' => 'J01',
            'mata_anggaran' => 'Konsumsi',
            'deskripsi_pk' => "Konsumsi bulan {$month}",
            'nominal_anggaran' => 2000000,
            'nomer_anggaran' => 1,
            'revisi_terakhir' => 0,
            'kode_anggaran_baru' => "R.01.01.01.01.01.001.00{$month}.001.2027.0{$month}.rev0.M0",
            'status_item' => 'active',
        ]);

        Pk04Kuisioner::create([
            'pk04_kegiatan_id' => $kegiatan->id,
            'kode_kuisioner' => "KS0{$month}",
            'pertanyaan' => 'Berapa peserta?',
            'tipe' => 'Kuantitatif',
            'satuan' => 'orang',
        ]);

        $kegiatanMap[$month] = $kegiatan;
        $anggaranMap[$month] = $anggaran;
    }

    return [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $kegiatanMap, $anggaranMap];
}

/**
 * Mark a PABD workflow as completed (PABD05) with anggaran dicairkan.
 */
function chainCompletePabd(PabdWorkflow $pabdWorkflow, array $anggaranIds): void
{
    $now = now();
    $wsId = $pabdWorkflow->workspace_id;

    $pabdWorkflow->update([
        'history' => [
            ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(20)->toIso8601String()],
            ['step' => 'PABD01', 'action' => 'submitted', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(19)->toIso8601String()],
            ['step' => 'PABD03', 'action' => 'approved', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(15)->toIso8601String()],
            ['step' => 'PABD04', 'action' => 'submitted', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(10)->toIso8601String()],
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(10)->toIso8601String()],
        ],
    ]);

    // Mark anggaran as dicairkan via this PABD
    foreach ($anggaranIds as $id) {
        Pk04Anggaran::where('id', $id)->update([
            'status_pencairan' => 'dicairkan',
            'pencairan_pabd_workflow_id' => $pabdWorkflow->id,
            'tanggal_pencairan' => $now->subDays(10)->toDateString(),
        ]);
    }
}

/**
 * Mark a PRBL workflow as completed (PRBL05).
 */
function chainCompletePrbl(PrblWorkflow $prblWorkflow): void
{
    $now = now();
    $wsId = $prblWorkflow->workspace_id;

    $prblWorkflow->update([
        'history' => [
            ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(20)->toIso8601String()],
            ['step' => 'PRBL01', 'action' => 'submitted', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(18)->toIso8601String()],
            ['step' => 'PRBL02A', 'action' => 'approved', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(15)->toIso8601String()],
            ['step' => 'PRBL02B', 'action' => 'approved', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(14)->toIso8601String()],
            ['step' => 'PRBL03', 'action' => 'submitted', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(10)->toIso8601String()],
            ['step' => 'PRBL04', 'action' => 'approved', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(8)->toIso8601String()],
            ['step' => 'PRBL05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $wsId, 'at' => $now->subDays(8)->toIso8601String()],
        ],
    ]);
}

// ── Tests ──

it('chains PABD → PRBL auto-create → complete PRBL → next month PABD', function () {
    // Setup: PP + PK04 with kegiatan in months 4 and 5
    $this->travelTo(Carbon::parse('2027-03-15'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $kegiatanMap, $anggaranMap] = chainSetupData([4, 5]);

    $engine = app(WorkflowEngine::class);

    // ── Phase 1: pabd:auto-create → PABD for month 4 ──
    $this->artisan('pabd:auto-create')->assertSuccessful();

    $pabd4 = PabdWorkflow::query()
        ->where('team_id', $team->id)
        ->where('bulan_anggaran', 4)
        ->first();
    expect($pabd4)->not->toBeNull();

    // ── Phase 2: Complete PABD month 4 ──
    chainCompletePabd($pabd4, [$anggaranMap[4]->id]);

    $pabd4->refresh();
    expect($engine->getWorkflowStatus($pabd4->history))->toBe('completed');

    // ── Phase 3: prbl:auto-create → PRBL for month 4 ──
    $this->travelTo(Carbon::parse('2027-04-16'));
    $this->artisan('prbl:auto-create')->assertSuccessful();

    $prbl4 = PrblWorkflow::query()
        ->where('team_id', $team->id)
        ->where('bulan_laporan', 4)
        ->first();
    expect($prbl4)->not->toBeNull()
        ->and($prbl4->pabd_workflow_id)->toBe($pabd4->id);

    // ── Phase 4: Month 5 PABD should NOT be created yet (PRBL4 not complete) ──
    $this->artisan('pabd:auto-create')->assertSuccessful();

    $pabd5Before = PabdWorkflow::query()
        ->where('team_id', $team->id)
        ->where('bulan_anggaran', 5)
        ->first();
    expect($pabd5Before)->toBeNull();

    // ── Phase 5: Complete PRBL month 4 → unblocks next PABD ──
    chainCompletePrbl($prbl4);

    $prbl4->refresh();
    expect($engine->getWorkflowStatus($prbl4->history))->toBe('completed');

    // ── Phase 6: pabd:auto-create → PABD for month 5 (now unblocked) ──
    $this->artisan('pabd:auto-create')->assertSuccessful();

    $pabd5 = PabdWorkflow::query()
        ->where('team_id', $team->id)
        ->where('bulan_anggaran', 5)
        ->first();
    expect($pabd5)->not->toBeNull()
        ->and($pabd5->pp_workflow_id)->toBe($ppWorkflow->id);

    // ── Phase 7: Complete PABD month 5 → PRBL month 5 ──
    chainCompletePabd($pabd5, [$anggaranMap[5]->id]);

    $this->travelTo(Carbon::parse('2027-05-16'));
    $this->artisan('prbl:auto-create')->assertSuccessful();

    $prbl5 = PrblWorkflow::query()
        ->where('team_id', $team->id)
        ->where('bulan_laporan', 5)
        ->first();
    expect($prbl5)->not->toBeNull()
        ->and($prbl5->pabd_workflow_id)->toBe($pabd5->id);
});

it('blocks PRBL auto-create until PABD05 is completed', function () {
    $this->travelTo(Carbon::parse('2027-03-15'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $kegiatanMap, $anggaranMap] = chainSetupData([4]);

    // Create PABD for month 4 via auto-create
    $this->artisan('pabd:auto-create')->assertSuccessful();

    $pabd4 = PabdWorkflow::query()
        ->where('team_id', $team->id)
        ->where('bulan_anggaran', 4)
        ->first();
    expect($pabd4)->not->toBeNull();

    // PABD is NOT completed yet (still at PABD01 created)
    $engine = app(WorkflowEngine::class);
    expect($engine->getWorkflowStatus($pabd4->history))->not->toBe('completed');

    // Try PRBL auto-create → should NOT create PRBL (PABD not completed)
    $this->travelTo(Carbon::parse('2027-04-16'));
    $this->artisan('prbl:auto-create')->assertSuccessful();

    expect(PrblWorkflow::where('team_id', $team->id)->count())->toBe(0);

    // Now complete PABD → PRBL should be created
    chainCompletePabd($pabd4, [$anggaranMap[4]->id]);

    $this->artisan('prbl:auto-create')->assertSuccessful();

    expect(PrblWorkflow::where('team_id', $team->id)->where('bulan_laporan', 4)->count())->toBe(1);
});
