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
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\Prbl01ItemKegiatan;
use App\Models\Prbl\Prbl01ItemKuisioner;
use App\Models\Prbl\Prbl01ItemRealisasi;
use App\Models\Prbl\PrblWorkflow;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Setup a complete PP + PK04 + completed PABD chain for PRBL auto-create testing.
 * PABD has completed status and pk04_anggaran items are marked as dicairkan.
 */
function setupPrblAutoCreateData(
    int $bulanKegiatan = 4,
    bool $withDicairkanAnggaran = true,
    bool $pabdCompleted = true,
    bool $withKuisioner = true,
): array {
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

    $pp06Snapshot = [];
    foreach (['pp01', 'pp02', 'pp03', 'pp04', 'pp05'] as $step) {
        $pp06Snapshot["{$step}_created_by_user_name"] = 'Test User';
        $pp06Snapshot["{$step}_created_by_role_name"] = 'Test Role';
        $pp06Snapshot["{$step}_created_by_organization_name"] = 'Test Org';
        $pp06Snapshot["{$step}_created_by_workspace_name"] = 'Test WS';
        $pp06Snapshot["{$step}_created_at"] = now();
    }

    Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->subDays(60)->toDateString(),
        'tanggal_penetapan_program' => now()->subDays(10)->toDateString(),
        ...$pp06Snapshot,
    ]);

    // PK workflow with PK04 final
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
        'bulan' => $bulanKegiatan,
        'nomer_kegiatan' => 1,
    ]);

    $anggaran1 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Konsumsi retreat',
        'nominal_anggaran' => 2500000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.001.2027.04.rev0.M0',
        'status_item' => 'active',
    ]);

    $anggaran2 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Transportasi',
        'deskripsi_pk' => 'Transport retreat',
        'nominal_anggaran' => 1000000,
        'nomer_anggaran' => 2,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.002.2027.04.rev0.M0',
        'status_item' => 'active',
    ]);

    $kuisioner = null;
    if ($withKuisioner) {
        $kuisioner = Pk04Kuisioner::create([
            'pk04_kegiatan_id' => $kegiatan->id,
            'kode_kuisioner' => 'KS01',
            'pertanyaan' => 'Berapa peserta?',
            'tipe' => 'Kuantitatif',
            'satuan' => 'orang',
        ]);
    }

    // Create completed PABD with dicairkan anggaran
    $pabdHistory = [];
    if ($pabdCompleted) {
        $pabdHistory = [
            ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subDays(20)->toIso8601String()],
            ['step' => 'PABD01', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => $team->id, 'org' => $team->organization->id, 'workspace' => $workspace->id, 'at' => now()->subDays(19)->toIso8601String()],
            ['step' => 'PABD03', 'action' => 'approved', 'by' => $user->id, 'role' => $role->id, 'team' => $team->id, 'org' => $team->organization->id, 'workspace' => $workspace->id, 'at' => now()->subDays(15)->toIso8601String()],
            ['step' => 'PABD04', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => $team->id, 'org' => $team->organization->id, 'workspace' => $workspace->id, 'at' => now()->subDays(10)->toIso8601String()],
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subDays(10)->toIso8601String()],
        ];
    }

    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => $bulanKegiatan,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => $pabdHistory,
    ]);

    // Mark anggaran as dicairkan via this PABD
    if ($withDicairkanAnggaran) {
        $anggaran1->update([
            'status_pencairan' => 'dicairkan',
            'pencairan_pabd_workflow_id' => $pabdWorkflow->id,
            'tanggal_pencairan' => now()->subDays(10)->toDateString(),
        ]);
        $anggaran2->update([
            'status_pencairan' => 'dicairkan',
            'pencairan_pabd_workflow_id' => $pabdWorkflow->id,
            'tanggal_pencairan' => now()->subDays(10)->toDateString(),
        ]);
    }

    return [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $pabdWorkflow, $kegiatan, $anggaran1, $anggaran2, $kuisioner];
}

it('creates PRBL when all conditions are met', function () {
    $this->travelTo(Carbon::parse('2027-04-16'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $pabdWorkflow, $kegiatan, $anggaran1, $anggaran2, $kuisioner] = setupPrblAutoCreateData();

    $this->artisan('prbl:auto-create')->assertSuccessful();

    $prbl = PrblWorkflow::query()
        ->where('workspace_id', $workspace->id)
        ->where('team_id', $team->id)
        ->where('bulan_laporan', 4)
        ->where('tahun_laporan', 2027)
        ->first();

    expect($prbl)->not->toBeNull();
    expect($prbl->pp_workflow_id)->toBe($ppWorkflow->id);
    expect($prbl->pabd_workflow_id)->toBe($pabdWorkflow->id);
    expect($prbl->created_by_user_id)->toBeNull(); // system-created

    // PRBL01 data created
    $prbl01 = Prbl01Data::where('prbl_workflow_id', $prbl->id)->first();
    expect($prbl01)->not->toBeNull();

    // Item kegiatan rows created (1 kegiatan)
    $itemKegiatan = Prbl01ItemKegiatan::where('prbl01_data_id', $prbl01->id)->get();
    expect($itemKegiatan)->toHaveCount(1);
    expect($itemKegiatan->first()->pk04_kegiatan_id)->toBe($kegiatan->id);

    // Item realisasi rows created (2 dicairkan anggaran)
    $itemRealisasi = Prbl01ItemRealisasi::where('prbl01_data_id', $prbl01->id)->get();
    expect($itemRealisasi)->toHaveCount(2);
    expect($itemRealisasi->pluck('pk04_anggaran_id')->sort()->values()->toArray())
        ->toBe([$anggaran1->id, $anggaran2->id]);
    expect($itemRealisasi->every(fn ($item) => $item->nominal_realisasi == 0))->toBeTrue();

    // Item kuisioner rows created (1 kuisioner per kegiatan)
    $itemKuisioner = Prbl01ItemKuisioner::where('prbl01_item_kegiatan_id', $itemKegiatan->first()->id)->get();
    expect($itemKuisioner)->toHaveCount(1);
    expect($itemKuisioner->first()->pk04_kuisioner_id)->toBe($kuisioner->id);
    expect($itemKuisioner->first()->jawaban)->toBeNull();

    // History entry recorded
    $prbl->refresh();
    $history = $prbl->history;
    expect($history)->toHaveCount(1);
    expect($history[0]['step'])->toBe('PRBL01');
    expect($history[0]['action'])->toBe('created');
    expect($history[0])->not->toHaveKey('by');
});

it('skips when PRBL already exists for team + month + PP (idempotent)', function () {
    $this->travelTo(Carbon::parse('2027-04-16'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $pabdWorkflow] = setupPrblAutoCreateData();

    // Pre-create PRBL
    PrblWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => 4,
        'tahun_laporan' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [],
    ]);

    $this->artisan('prbl:auto-create')->assertSuccessful();

    expect(PrblWorkflow::where('team_id', $team->id)->where('bulan_laporan', 4)->count())->toBe(1);
});

it('skips when PABD is not completed', function () {
    $this->travelTo(Carbon::parse('2027-04-16'));
    setupPrblAutoCreateData(pabdCompleted: false);

    $this->artisan('prbl:auto-create')->assertSuccessful();

    expect(PrblWorkflow::count())->toBe(0);
});

it('skips when current date is before day 15 of kegiatan month', function () {
    $this->travelTo(Carbon::parse('2027-04-14')); // day 14 — too early
    setupPrblAutoCreateData();

    $this->artisan('prbl:auto-create')->assertSuccessful();

    expect(PrblWorkflow::count())->toBe(0);
});

it('creates on exactly day 15 of kegiatan month', function () {
    $this->travelTo(Carbon::parse('2027-04-15'));
    [$workspace, $team] = setupPrblAutoCreateData();

    $this->artisan('prbl:auto-create')->assertSuccessful();

    expect(PrblWorkflow::where('team_id', $team->id)->where('bulan_laporan', 4)->count())->toBe(1);
});

it('skips subsequent PRBL when previous month PRBL is not complete', function () {
    $this->travelTo(Carbon::parse('2027-05-16'));

    // Setup data for month 4 (first PABD + completed PRBL)
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $pabdWorkflow4] = setupPrblAutoCreateData(bulanKegiatan: 4);

    // Create PRBL for month 4 but NOT completed (still active)
    PrblWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow4->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => 4,
        'tahun_laporan' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [
            ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subDays(10)->toIso8601String()],
        ],
    ]);

    // Add month 5 kegiatan + completed PABD
    $kegiatan5 = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Kegiatan Mei',
        'bulan' => 5,
        'nomer_kegiatan' => 2,
    ]);

    $anggaran5 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan5->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Konsumsi mei',
        'nominal_anggaran' => 1000000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.002.001.2027.05.rev0.M0',
        'status_item' => 'active',
    ]);

    $pabdWorkflow5 = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 5,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subDays(5)->toIso8601String()],
        ],
    ]);

    $anggaran5->update([
        'status_pencairan' => 'dicairkan',
        'pencairan_pabd_workflow_id' => $pabdWorkflow5->id,
        'tanggal_pencairan' => now()->subDays(5)->toDateString(),
    ]);

    $this->artisan('prbl:auto-create')->assertSuccessful();

    // PRBL for month 5 should NOT be created because month 4 PRBL is not complete
    expect(PrblWorkflow::where('team_id', $team->id)->where('bulan_laporan', 5)->count())->toBe(0);
});

it('creates subsequent PRBL when previous month PRBL is complete', function () {
    $this->travelTo(Carbon::parse('2027-05-16'));

    // Setup data for month 4 (first PABD + completed PRBL)
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $pabdWorkflow4] = setupPrblAutoCreateData(bulanKegiatan: 4);

    // Create completed PRBL for month 4
    PrblWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow4->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => 4,
        'tahun_laporan' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [
            ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subDays(20)->toIso8601String()],
            ['step' => 'PRBL05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subDays(10)->toIso8601String()],
        ],
    ]);

    // Add month 5 kegiatan + completed PABD
    $kegiatan5 = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Kegiatan Mei',
        'bulan' => 5,
        'nomer_kegiatan' => 2,
    ]);

    $anggaran5 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan5->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Konsumsi mei',
        'nominal_anggaran' => 1000000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.002.001.2027.05.rev0.M0',
        'status_item' => 'active',
    ]);

    $pabdWorkflow5 = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 5,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->subDays(5)->toIso8601String()],
        ],
    ]);

    $anggaran5->update([
        'status_pencairan' => 'dicairkan',
        'pencairan_pabd_workflow_id' => $pabdWorkflow5->id,
        'tanggal_pencairan' => now()->subDays(5)->toDateString(),
    ]);

    $this->artisan('prbl:auto-create')->assertSuccessful();

    // PRBL for month 5 should be created because month 4 PRBL is complete
    $prbl5 = PrblWorkflow::where('team_id', $team->id)->where('bulan_laporan', 5)->first();
    expect($prbl5)->not->toBeNull();
    expect($prbl5->pabd_workflow_id)->toBe($pabdWorkflow5->id);
});

it('is idempotent — running twice creates no duplicates', function () {
    $this->travelTo(Carbon::parse('2027-04-16'));
    [$workspace, $team] = setupPrblAutoCreateData();

    $this->artisan('prbl:auto-create')->assertSuccessful();
    $this->artisan('prbl:auto-create')->assertSuccessful();

    expect(PrblWorkflow::where('team_id', $team->id)->where('bulan_laporan', 4)->count())->toBe(1);
});

it('skips anggaran not marked as dicairkan', function () {
    $this->travelTo(Carbon::parse('2027-04-16'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04, $pabdWorkflow] = setupPrblAutoCreateData(withDicairkanAnggaran: false);

    // Mark only anggaran with status_pencairan = 'menunggu_pencairan' (not dicairkan)
    Pk04Anggaran::query()
        ->whereHas('pk04Kegiatan', fn ($q) => $q->where('pk04_program_tahunan_id', $pk04->id))
        ->update([
            'status_pencairan' => 'menunggu_pencairan',
            'pencairan_pabd_workflow_id' => $pabdWorkflow->id,
        ]);

    $this->artisan('prbl:auto-create')->assertSuccessful();

    // PRBL still created (workflow exists), but no item_realisasi or item_kegiatan
    $prbl = PrblWorkflow::where('team_id', $team->id)->first();
    expect($prbl)->not->toBeNull();

    $prbl01 = Prbl01Data::where('prbl_workflow_id', $prbl->id)->first();
    expect(Prbl01ItemKegiatan::where('prbl01_data_id', $prbl01->id)->count())->toBe(0);
    expect(Prbl01ItemRealisasi::where('prbl01_data_id', $prbl01->id)->count())->toBe(0);
});
