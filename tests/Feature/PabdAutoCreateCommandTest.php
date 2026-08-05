<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\User;
use Carbon\Carbon;

/**
 * Setup a complete PP + PK04 chain for auto-create testing.
 * tanggal_penetapan_program defaults to past (conditions met).
 */
function setupAutoCreateData(
    ?string $tanggalPenetapan = null,
    int $bulanKegiatan = 4,
    int $tahunProgram = 2027,
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
        'tahun' => $tahunProgram,
        'tanggal_mulai_pra_raker' => now()->subDays(60)->toDateString(),
        'tanggal_penetapan_program' => $tanggalPenetapan ?? now()->subDays(10)->toDateString(),
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
        'tahun' => $tahunProgram,
        'tanggal_mulai_pra_raker' => now()->subDays(60)->toDateString(),
        'tanggal_penetapan_program' => $tanggalPenetapan ?? now()->subDays(10)->toDateString(),
        ...$pp06Snapshot,
    ]);

    // PK workflow with PK04 final
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
        'bulan' => $bulanKegiatan,
        'nomer_kegiatan' => 1,
    ]);

    Pk04Anggaran::create([
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

    Pk04Anggaran::create([
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

    return [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04];
}

it('creates PABD when all conditions are met', function () {
    // Kegiatan in month 4 (April), command runs in month 3 (March) → target = April
    $this->travelTo(Carbon::parse('2027-03-15'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04] = setupAutoCreateData(
        tanggalPenetapan: '2027-02-01',
        bulanKegiatan: 4,
    );

    $this->artisan('pabd:auto-create')->assertSuccessful();

    $pabd = PabdWorkflow::query()
        ->where('workspace_id', $workspace->id)
        ->where('team_id', $team->id)
        ->where('bulan_anggaran', 4)
        ->where('tahun_anggaran', 2027)
        ->first();

    expect($pabd)->not->toBeNull();
    expect($pabd->pp_workflow_id)->toBe($ppWorkflow->id);
    expect($pabd->created_by_user_id)->toBeNull(); // system-created

    // PABD01 data created
    $pabd01 = Pabd01Data::where('pabd_workflow_id', $pabd->id)->first();
    expect($pabd01)->not->toBeNull();
    expect($pabd01->ada_perubahan)->toBeFalse();
    expect($pabd01->pk04_revisions_snapshot)->toBe([$pk04->id => 0]);

    // Item anggaran rows created (2 anggaran items for April kegiatan)
    $items = Pabd01ItemAnggaran::where('pabd01_data_id', $pabd01->id)->get();
    expect($items)->toHaveCount(2);
    expect($items->every(fn ($item) => $item->dicairkan === false))->toBeTrue();

    // History entry recorded
    $pabd->refresh();
    $history = $pabd->history;
    expect($history)->toHaveCount(1);
    expect($history[0]['step'])->toBe('PABD01');
    expect($history[0]['action'])->toBe('created');
    expect($history[0])->not->toHaveKey('by');
});

it('skips a programme whose year is not the target year', function () {
    // A stale programme was left in production from testing (tahun 2025, still
    // "established") and kept matching calendar months, stamping workflows with
    // its own year. It must generate nothing at all.
    $this->travelTo(Carbon::parse('2027-03-15'));
    [$workspace, $team] = setupAutoCreateData(
        tanggalPenetapan: '2026-02-01',
        bulanKegiatan: 4,
        tahunProgram: 2026,
    );

    $this->artisan('pabd:auto-create')->assertSuccessful();

    expect(PabdWorkflow::where('team_id', $team->id)->count())->toBe(0);
});

it('does not stamp a January target with the outgoing year', function () {
    // Running in December, the target is January of the *next* year. Taking the
    // year from PP06 instead of the target stamped it with the year just ending,
    // which the duplicate guard then matched against that January — skipping every
    // team and stopping the cycle without saying why.
    $this->travelTo(Carbon::parse('2027-12-05'));
    [$workspace, $team] = setupAutoCreateData(
        tanggalPenetapan: '2027-02-01',
        bulanKegiatan: 1,
        tahunProgram: 2027,
    );

    $this->artisan('pabd:auto-create')->assertSuccessful();

    // Nothing at all — and in particular nothing mis-stamped as January 2027.
    expect(PabdWorkflow::where('team_id', $team->id)->count())->toBe(0);
    expect(
        PabdWorkflow::where('team_id', $team->id)
            ->where('bulan_anggaran', 1)
            ->where('tahun_anggaran', 2027)
            ->exists()
    )->toBeFalse();
});

it('skips when PABD already exists for team + month + PP', function () {
    $this->travelTo(Carbon::parse('2027-03-15'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04] = setupAutoCreateData(
        tanggalPenetapan: '2027-02-01',
        bulanKegiatan: 4,
    );

    // Pre-create PABD for this team + month
    PabdWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 4,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [],
    ]);

    $this->artisan('pabd:auto-create')->assertSuccessful();

    // Should still be only 1
    expect(PabdWorkflow::where('team_id', $team->id)->where('bulan_anggaran', 4)->count())->toBe(1);
});

it('skips when outside M-1 window', function () {
    // Command runs in January → target = February, but kegiatan is in April
    $this->travelTo(Carbon::parse('2027-01-15'));
    [$workspace, $team] = setupAutoCreateData(
        tanggalPenetapan: '2027-01-01',
        bulanKegiatan: 4,
    );

    $this->artisan('pabd:auto-create')->assertSuccessful();

    expect(PabdWorkflow::where('team_id', $team->id)->count())->toBe(0);
});

it('skips when PK04 final does not exist', function () {
    $this->travelTo(Carbon::parse('2027-03-15'));

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
        'tanggal_mulai_pra_raker' => '2027-01-01',
        'tanggal_penetapan_program' => '2027-02-01',
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
        'tanggal_mulai_pra_raker' => '2027-01-01',
        'tanggal_penetapan_program' => '2027-02-01',
        ...$pp06Snapshot,
    ]);

    // PK workflow WITHOUT PK04 (no compiled final)
    PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [],
    ]);

    $this->artisan('pabd:auto-create')->assertSuccessful();

    expect(PabdWorkflow::where('team_id', $team->id)->count())->toBe(0);
});

it('skips when tanggal_penetapan_program is in the future', function () {
    $this->travelTo(Carbon::parse('2027-03-15'));
    [$workspace, $team] = setupAutoCreateData(
        tanggalPenetapan: '2027-06-01', // future
        bulanKegiatan: 4,
    );

    $this->artisan('pabd:auto-create')->assertSuccessful();

    expect(PabdWorkflow::where('team_id', $team->id)->count())->toBe(0);
});

it('creates subsequent PABD when only the grace month PRBL is outstanding', function () {
    $this->travelTo(Carbon::parse('2027-04-15'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04] = setupAutoCreateData(
        tanggalPenetapan: '2027-02-01',
        bulanKegiatan: 5,
    );

    // Add a kegiatan in month 5 so target month matches
    $kegiatan5 = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Kegiatan Mei',
        'bulan' => 5,
        'nomer_kegiatan' => 2,
    ]);

    Pk04Anggaran::create([
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

    // Pre-existing PABD for month 4 with no completed PRBL. Month 4 is the grace
    // month for target month 5, so it must NOT block.
    PabdWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 4,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [],
    ]);

    $this->artisan('pabd:auto-create')->assertSuccessful();

    expect(PabdWorkflow::where('team_id', $team->id)->where('bulan_anggaran', 5)->count())->toBe(1);
});

it('skips subsequent PABD when a month older than the grace month has no completed PRBL', function () {
    $this->travelTo(Carbon::parse('2027-04-15'));
    [$workspace, $team, $ppWorkflow, $pkWorkflow, $pk04] = setupAutoCreateData(
        tanggalPenetapan: '2027-02-01',
        bulanKegiatan: 5,
    );

    $kegiatan5 = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Kegiatan Mei',
        'bulan' => 5,
        'nomer_kegiatan' => 2,
    ]);

    Pk04Anggaran::create([
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

    // Month 3 is two months back — outside the grace window, so it still gates.
    foreach ([3, 4] as $bulan) {
        PabdWorkflow::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'pp_workflow_id' => $ppWorkflow->id,
            'bulan_anggaran' => $bulan,
            'tahun_anggaran' => 2027,
            'created_by_user_id' => null,
            'created_by_role_id' => null,
            'created_by_team_id' => null,
            'created_by_org_id' => null,
            'history' => [],
        ]);
    }

    $this->artisan('pabd:auto-create')->assertSuccessful();

    expect(PabdWorkflow::where('team_id', $team->id)->where('bulan_anggaran', 5)->count())->toBe(0);
});
