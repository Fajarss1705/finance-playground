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
use App\Models\Prbl\Prbl01FotoKegiatan;
use App\Models\Prbl\Prbl01ItemKegiatan;
use App\Models\Prbl\Prbl01ItemKuisioner;
use App\Models\Prbl\Prbl01ItemRealisasi;
use App\Models\Prbl\Prbl01NotaPengeluaran;
use App\Models\Prbl\Prbl03Bukti;
use App\Models\Prbl\Prbl03Data;
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

/**
 * Create a team-scope user with given PRBL permissions.
 */
function setupPrblUser(string ...$permissionNames): array
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

function activatePrblSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function pp06AuthorSnapshotPrbl(): array
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

function setupCompletedPpForPrbl($workspace, $team): array
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
        ...pp06AuthorSnapshotPrbl(),
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
 * Create PK workflow with PK04 final containing kegiatan + anggaran + kuisioner.
 */
function setupPk04ForPrbl($workspace, $team, $ppWorkflow, $bulan = 3, $user = null, $role = null): array
{
    $theUser = $user ?? User::first() ?? User::factory()->withRole()->create();
    $theRole = $role ?? $theUser->roles->first();
    $org = $theRole?->team?->organization;

    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) Str::uuid(),
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

    // Kuisioner for the kegiatan
    $kuisioner = Pk04Kuisioner::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_kuisioner' => 'Q01',
        'pertanyaan' => 'Berapa jumlah peserta?',
        'tipe' => 'angka',
        'satuan' => 'orang',
    ]);

    return [$pkWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2, $kuisioner];
}

/**
 * Create PABD workflow (completed through PABD05) with dicairkan anggaran,
 * then create PRBL workflow + populate PRBL01 data from dicairkan items.
 */
function setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $anggaran1, $anggaran2, $kuisioner, $bulan = 3, $user = null, $role = null): array
{
    $theUser = $user ?? User::first() ?? User::factory()->create();
    $theRole = $role;
    $org = $team->organization ?? $theRole?->team?->organization;

    // Create PABD workflow (completed)
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

    // Mark anggaran as dicairkan via this PABD workflow
    $anggaran1->update([
        'status_pencairan' => 'dicairkan',
        'pencairan_pabd_workflow_id' => $pabdWorkflow->id,
    ]);
    $anggaran2->update([
        'status_pencairan' => 'dicairkan',
        'pencairan_pabd_workflow_id' => $pabdWorkflow->id,
    ]);

    // Create PRBL workflow
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

    // Create PRBL01 data
    $prbl01 = Prbl01Data::create([
        'prbl_workflow_id' => $prblWorkflow->id,
    ]);

    // Create item kegiatan (linked to pk04_kegiatan)
    $itemKegiatan = Prbl01ItemKegiatan::create([
        'prbl01_data_id' => $prbl01->id,
        'pk04_kegiatan_id' => $kegiatan->id,
    ]);

    // Create kuisioner item
    $itemKuisioner = Prbl01ItemKuisioner::create([
        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
        'pk04_kuisioner_id' => $kuisioner->id,
    ]);

    // Create realisasi items (one per anggaran)
    $realisasi1 = Prbl01ItemRealisasi::create([
        'prbl01_data_id' => $prbl01->id,
        'pk04_anggaran_id' => $anggaran1->id,
        'nominal_realisasi' => 0,
    ]);

    $realisasi2 = Prbl01ItemRealisasi::create([
        'prbl01_data_id' => $prbl01->id,
        'pk04_anggaran_id' => $anggaran2->id,
        'nominal_realisasi' => 0,
    ]);

    // Record creation in history
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

// ── PRBL01 Show ──

it('shows PRBL01 page with kegiatan items for team scope', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.submit',
        'team.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->get(route('team.workflows.prbl.prbl01.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/prbl/prbl01')
            ->where('mode', 'edit')
            ->where('scope', 'team')
            ->where('canDraft', true)
            ->where('canSubmit', true)
            ->where('canComment', true)
            ->has('kegiatanItems', 1)
            ->where('kegiatanItems.0.program_name', 'Program Kegiatan')
            ->has('kegiatanItems.0.kegiatan', 1)
            ->where('kegiatanItems.0.kegiatan.0.nama_kegiatan', 'Retreat Remaja')
            ->has('kegiatanItems.0.kegiatan.0.realisasi', 2)
            ->has('kegiatanItems.0.kegiatan.0.kuisioner', 1)
            ->where('totalDicairkan', fn ($v) => (float) $v === 3500000.0)
            ->where('totalRealisasi', fn ($v) => (float) $v === 0.0)
            ->where('isReentry', false)
        );
});

it('shows PRBL01 as readonly for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl01.show',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->get(route('admin.workflows.prbl.prbl01.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/prbl/prbl01')
            ->where('mode', 'readonly')
            ->where('scope', 'admin')
            ->where('canDraft', false)
            ->where('canSubmit', false)
        );
});

it('denies PRBL01 show without permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        // Missing: team.workflows.prbl.prbl01.show
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->get(route('team.workflows.prbl.prbl01.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]));

    $response->assertForbidden();
});

it('denies PRBL01 show from another team in team scope', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
    );
    activatePrblSession($this, $user, $role, $workspace);

    // Create workflow for a different team
    $otherUser = User::factory()->withRole()->create();
    $otherRole = $otherUser->roles->first();
    $otherTeam = $otherRole->team;

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $otherTeam);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $otherTeam, $ppWorkflow, 3, $otherUser, $otherRole);
    [$prblWorkflow, $pabdWorkflow, $prbl01] = setupPrblWorkflow($workspace, $otherTeam, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $otherUser, $otherRole);

    $response = $this->get(route('team.workflows.prbl.prbl01.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]));

    $response->assertForbidden();
});

// ── PRBL01 Draft ──

it('saves PRBL01 draft with narratives, kuisioner, and realisasi', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.prbl01.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [
            [
                'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                'masalah' => 'Kurang peserta',
                'langkah_penanganan' => 'Promosi lebih gencar',
                'harapan' => 'Peserta bertambah',
                'catatan_tim' => 'Perlu koordinasi',
                'kuisioner' => [
                    [
                        'prbl01_item_kuisioner_id' => $itemKuisioner->id,
                        'jawaban' => '25',
                    ],
                ],
            ],
        ],
        'realisasi' => [
            [
                'prbl01_item_realisasi_id' => $realisasi1->id,
                'nominal_realisasi' => 2000000,
                'komentar_realisasi' => 'Sesuai rencana',
            ],
            [
                'prbl01_item_realisasi_id' => $realisasi2->id,
                'nominal_realisasi' => 500000,
            ],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify narrative fields
    $itemKegiatan->refresh();
    expect($itemKegiatan->masalah)->toBe('Kurang peserta')
        ->and($itemKegiatan->langkah_penanganan)->toBe('Promosi lebih gencar')
        ->and($itemKegiatan->harapan)->toBe('Peserta bertambah')
        ->and($itemKegiatan->catatan_tim)->toBe('Perlu koordinasi');

    // Verify kuisioner
    $itemKuisioner->refresh();
    expect($itemKuisioner->jawaban)->toBe('25');

    // Verify realisasi
    $realisasi1->refresh();
    $realisasi2->refresh();
    expect((float) $realisasi1->nominal_realisasi)->toBe(2000000.0)
        ->and($realisasi1->komentar_realisasi)->toBe('Sesuai rencana')
        ->and((float) $realisasi2->nominal_realisasi)->toBe(500000.0);

    // Verify history
    $prblWorkflow->refresh();
    $lastEntry = collect($prblWorkflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PRBL01');
});

it('denies PRBL01 draft without draft permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        // No draft permission
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.prbl01.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('rejects PRBL01 draft with stale expected_updated_at', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.prbl01.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $response->assertStatus(409);
});

// ── PRBL01 Submit ──

it('submits PRBL01 and forks to PRBL02A + PRBL02B', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.prbl01.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [
            [
                'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                'masalah' => 'Kurang peserta',
                'langkah_penanganan' => 'Promosi lebih gencar',
                'harapan' => 'Peserta bertambah',
                'catatan_tim' => 'Perlu koordinasi',
                'kuisioner' => [
                    [
                        'prbl01_item_kuisioner_id' => $itemKuisioner->id,
                        'jawaban' => '30',
                    ],
                ],
            ],
        ],
        'realisasi' => [
            [
                'prbl01_item_realisasi_id' => $realisasi1->id,
                'nominal_realisasi' => 2000000,
            ],
            [
                'prbl01_item_realisasi_id' => $realisasi2->id,
                'nominal_realisasi' => 800000,
            ],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    // Verify fork: PRBL02A + PRBL02B both active
    $prblWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);

    expect($statuses['PRBL01']['status'])->toBe('completed')
        ->and($statuses['PRBL02A']['status'])->toBe('active')
        ->and($statuses['PRBL02B']['status'])->toBe('active')
        ->and($statuses['PRBL03']['status'])->toBe('pending');

    // Verify history has: created, submitted, PRBL02A created, PRBL02B created
    $actions = collect($prblWorkflow->history)->pluck('action')->all();
    expect($actions)->toContain('submitted');

    $prbl02aEntries = collect($prblWorkflow->history)->where('step', 'PRBL02A');
    $prbl02bEntries = collect($prblWorkflow->history)->where('step', 'PRBL02B');
    expect($prbl02aEntries)->toHaveCount(1)
        ->and($prbl02bEntries)->toHaveCount(1);
});

it('rejects PRBL01 submit when realisasi exceeds dicairkan', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.prbl01.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [
            [
                'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                'masalah' => 'test',
                'langkah_penanganan' => 'test',
                'harapan' => 'test',
                'catatan_tim' => 'test',
                'kuisioner' => [
                    ['prbl01_item_kuisioner_id' => $itemKuisioner->id, 'jawaban' => '10'],
                ],
            ],
        ],
        'realisasi' => [
            [
                'prbl01_item_realisasi_id' => $realisasi1->id,
                'nominal_realisasi' => 3000000, // 3M > 2.5M dicairkan
            ],
            [
                'prbl01_item_realisasi_id' => $realisasi2->id,
                'nominal_realisasi' => 1000000, // 1M = 1M dicairkan — total 4M > 3.5M
            ],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ]);

    // Should abort 422 because 4M > 3.5M total dicairkan
    $response->assertStatus(422);

    // Workflow should NOT have advanced
    $prblWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL01']['status'])->toBe('active');
});

it('denies PRBL01 submit without submit permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        // No submit permission
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.prbl01.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [
            [
                'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                'masalah' => 'test',
                'langkah_penanganan' => 'test',
                'harapan' => 'test',
                'catatan_tim' => 'test',
                'kuisioner' => [
                    ['prbl01_item_kuisioner_id' => $itemKuisioner->id, 'jawaban' => '10'],
                ],
            ],
        ],
        'realisasi' => [
            ['prbl01_item_realisasi_id' => $realisasi1->id, 'nominal_realisasi' => 1000000],
            ['prbl01_item_realisasi_id' => $realisasi2->id, 'nominal_realisasi' => 500000],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('rejects PRBL01 submit when step is no longer active', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    // Manually advance PRBL01 to submitted, so step is no longer active
    $engine = new WorkflowEngine;
    $engine->recordAction(
        workflow: $prblWorkflow,
        step: 'PRBL01',
        action: 'submitted',
        userId: $user->id,
        sessionContext: [],
        table: 'prbl01_data',
        dataId: $prbl01->id,
    );

    $response = $this->post(route('team.workflows.prbl.prbl01.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'items' => [
            [
                'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                'masalah' => 'test',
                'langkah_penanganan' => 'test',
                'harapan' => 'test',
                'catatan_tim' => 'test',
                'kuisioner' => [
                    ['prbl01_item_kuisioner_id' => $itemKuisioner->id, 'jawaban' => '10'],
                ],
            ],
        ],
        'realisasi' => [
            ['prbl01_item_realisasi_id' => $realisasi1->id, 'nominal_realisasi' => 1000000],
            ['prbl01_item_realisasi_id' => $realisasi2->id, 'nominal_realisasi' => 500000],
        ],
        'expected_updated_at' => $prbl01->updated_at->toIso8601String(),
    ]);

    $response->assertStatus(409);
});

// ── Foto Upload / Delete ──

it('uploads a foto for a kegiatan item', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.foto.upload',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $file = UploadedFile::fake()->image('foto-kegiatan.jpg', 800, 600)->size(500);

    $response = $this->postJson(route('team.workflows.prbl.prbl01.foto.upload', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'file' => $file,
        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure(['id', 'file_id', 'original_filename', 'thumbnail_url']);

    expect(Prbl01FotoKegiatan::where('prbl01_item_kegiatan_id', $itemKegiatan->id)->count())->toBe(1);
});

it('deletes a foto for a kegiatan item', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.foto.upload',
        'team.workflows.prbl.prbl01.foto.delete',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    // Upload first
    $file = UploadedFile::fake()->image('foto.jpg', 400, 300)->size(100);
    $uploadResponse = $this->postJson(route('team.workflows.prbl.prbl01.foto.upload', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'file' => $file,
        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
    ]);
    $fotoId = $uploadResponse->json('id');

    // Delete
    $response = $this->postJson(route('team.workflows.prbl.prbl01.foto.delete', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'prbl01_foto_kegiatan_id' => $fotoId,
    ]);

    $response->assertSuccessful()
        ->assertJson(['success' => true]);

    expect(Prbl01FotoKegiatan::find($fotoId))->toBeNull();
});

// ── Nota Upload / Delete ──

it('uploads a nota pengeluaran for a kegiatan item', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.nota.upload',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $file = UploadedFile::fake()->create('nota-001.pdf', 1024, 'application/pdf');

    $response = $this->postJson(route('team.workflows.prbl.prbl01.nota.upload', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'file' => $file,
        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure(['id', 'file_id', 'original_filename', 'mime_type', 'download_url']);

    expect(Prbl01NotaPengeluaran::where('prbl01_item_kegiatan_id', $itemKegiatan->id)->count())->toBe(1);
});

it('rejects nota upload with blocked extension', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.nota.upload',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/octet-stream');

    $response = $this->postJson(route('team.workflows.prbl.prbl01.nota.upload', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'file' => $file,
        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
    ]);

    $response->assertStatus(422);
});

it('deletes a nota pengeluaran', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.nota.upload',
        'team.workflows.prbl.prbl01.nota.delete',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    // Upload first
    $file = UploadedFile::fake()->create('nota.pdf', 500, 'application/pdf');
    $uploadResponse = $this->postJson(route('team.workflows.prbl.prbl01.nota.upload', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'file' => $file,
        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
    ]);
    $notaId = $uploadResponse->json('id');

    // Delete
    $response = $this->postJson(route('team.workflows.prbl.prbl01.nota.delete', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01->id,
    ]), [
        'prbl01_nota_pengeluaran_id' => $notaId,
    ]);

    $response->assertSuccessful()
        ->assertJson(['success' => true]);

    expect(Prbl01NotaPengeluaran::find($notaId))->toBeNull();
});

// ── Comment ──

it('stores a comment on PRBL workflow (team scope)', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.comment', [
        'prblWorkflow' => $prblWorkflow->id,
    ]), [
        'source' => 'PRBL01',
        'notes' => 'Mohon segera dilengkapi laporan kegiatannya.',
    ]);

    $response->assertRedirect();
});

it('stores a comment on PRBL workflow (admin scope)', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl01.show',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('admin.workflows.prbl.comment', [
        'prblWorkflow' => $prblWorkflow->id,
    ]), [
        'source' => 'PRBL01',
        'notes' => 'Perlu revisi narasi kegiatan.',
    ]);

    $response->assertRedirect();
});

it('denies comment without comment permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        // No comment permission
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    $response = $this->post(route('team.workflows.prbl.comment', [
        'prblWorkflow' => $prblWorkflow->id,
    ]), [
        'source' => 'PRBL01',
        'notes' => 'Test comment',
    ]);

    $response->assertForbidden();
});

// ── Cycle-back re-entry ──

it('shows PRBL01 with cycle-back notes from PRBL04 rejection', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl01.show',
        'team.workflows.prbl.prbl01.draft',
        'team.workflows.prbl.prbl01.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    // Simulate full cycle: PRBL01 submitted → PRBL02A approved → PRBL02B approved → PRBL03 submitted → PRBL04 reject_to_prbl01
    $engine = new WorkflowEngine;
    $sessionCtx = ['role' => $role->id, 'team' => $team->id, 'org' => null, 'workspace' => $workspace->id];

    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL01', action: 'submitted', userId: $user->id, sessionContext: $sessionCtx, table: 'prbl01_data', dataId: $prbl01->id);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02A', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02B', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02A', action: 'approved', userId: $user->id, sessionContext: $sessionCtx, notes: 'Narasi OK');
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02B', action: 'approved', userId: $user->id, sessionContext: $sessionCtx, notes: 'Anggaran OK');
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL03', action: 'created', userId: null, sessionContext: [], table: 'prbl03_data', dataId: 1);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL03', action: 'submitted', userId: $user->id, sessionContext: $sessionCtx, table: 'prbl03_data', dataId: 1);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL04', action: 'rejected', userId: $user->id, sessionContext: $sessionCtx, notes: 'Harus revisi total', extra: ['cycleTarget' => 'PRBL01']);

    // Create new PRBL01 data for re-entry
    $prbl01v2 = Prbl01Data::create(['prbl_workflow_id' => $prblWorkflow->id]);
    $itemKegiatanV2 = Prbl01ItemKegiatan::create(['prbl01_data_id' => $prbl01v2->id, 'pk04_kegiatan_id' => $kegiatan->id]);
    Prbl01ItemRealisasi::create(['prbl01_data_id' => $prbl01v2->id, 'pk04_anggaran_id' => $ang1->id, 'nominal_realisasi' => 0]);
    Prbl01ItemRealisasi::create(['prbl01_data_id' => $prbl01v2->id, 'pk04_anggaran_id' => $ang2->id, 'nominal_realisasi' => 0]);
    Prbl01ItemKuisioner::create(['prbl01_item_kegiatan_id' => $itemKegiatanV2->id, 'pk04_kuisioner_id' => $kuisioner->id]);

    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL01', action: 'created', userId: null, sessionContext: [], table: 'prbl01_data', dataId: $prbl01v2->id);

    $response = $this->get(route('team.workflows.prbl.prbl01.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl01Data' => $prbl01v2->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/prbl/prbl01')
            ->where('mode', 'edit')
            ->where('isReentry', true)
            ->where('cycleBackNotes.source', 'prbl04')
            ->where('cycleBackNotes.prbl04.notes', 'Harus revisi total')
        );
});

// ──────────────────────────────────────
// PRBL02A / PRBL02B — Parallel Approvals
// ──────────────────────────────────────

/**
 * Helper: advance a PRBL workflow to PRBL02A+02B active (submit PRBL01).
 */
function advanceToPrbl02(object $prblWorkflow, object $prbl01, object $itemKegiatan, object $itemKuisioner, object $realisasi1, object $realisasi2, int $userId): void
{
    // Fill narrative fields
    $itemKegiatan->update(['masalah' => 'Test masalah', 'langkah_penanganan' => 'Test langkah', 'harapan' => 'Test harapan', 'catatan_tim' => 'Test catatan']);
    $itemKuisioner->update(['jawaban' => '30']);
    $realisasi1->update(['nominal_realisasi' => 2000000]);
    $realisasi2->update(['nominal_realisasi' => 800000]);

    $engine = new WorkflowEngine;
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL01', action: 'submitted', userId: $userId, sessionContext: [], table: 'prbl01_data', dataId: $prbl01->id);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02A', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02B', action: 'created', userId: null, sessionContext: []);
}

// ── PRBL02A Show ──

it('shows PRBL02A page with PRBL01 data for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.prbl02a.reject',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->get(route('admin.workflows.prbl.prbl02a.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/prbl/prbl02a')
            ->where('mode', 'review')
            ->where('canApprove', true)
            ->where('canReject', true)
            ->where('canComment', true)
            ->where('stepStatus', 'active')
            ->has('kegiatanItems', 1)
            ->where('kegiatanItems.0.program_name', 'Program Kegiatan')
            ->has('kegiatanItems.0.kegiatan', 1)
            ->where('kegiatanItems.0.kegiatan.0.masalah', 'Test masalah')
            ->where('totalDicairkan', fn ($v) => (float) $v === 3500000.0)
            ->where('totalRealisasi', fn ($v) => (float) $v === 2800000.0)
            ->where('parallelTrackStatus.step', 'PRBL02B')
            ->where('parallelTrackStatus.status', 'active')
        );
});

it('shows PRBL02A as readonly without approve permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->get(route('admin.workflows.prbl.prbl02a.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mode', 'readonly')
            ->where('canApprove', false)
            ->where('canReject', false)
        );
});

it('denies PRBL02A show without show permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser();
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->get(route('admin.workflows.prbl.prbl02a.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertForbidden();
});

// ── PRBL02A Approve ──

it('approves PRBL02A and waits for PRBL02B (silent)', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Narasi baik',
    ]);

    $response->assertRedirect();

    $prblWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);

    // PRBL02A completed, PRBL02B still active, PRBL03 still pending (waiting)
    expect($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('active')
        ->and($statuses['PRBL03']['status'])->toBe('pending');

    // History should have approved entry
    $lastApproval = collect($prblWorkflow->history)->where('step', 'PRBL02A')->where('action', 'approved')->first();
    expect($lastApproval)->not->toBeNull()
        ->and($lastApproval['notes'])->toBe('Narasi baik');
});

// ── PRBL02A + 02B Both Approve → PRBL03 created ──

it('creates PRBL03 when both PRBL02A and PRBL02B approve', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.approve',
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.approve',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    // Approve PRBL02A
    $prblWorkflow->refresh();
    $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ]);

    // Approve PRBL02B (last track → triggers join)
    $prblWorkflow->refresh();
    $this->post(route('admin.workflows.prbl.prbl02b.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ]);

    $prblWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);

    expect($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('completed')
        ->and($statuses['PRBL03']['status'])->toBe('active');

    // PRBL03 created entry in history
    $prbl03Created = collect($prblWorkflow->history)->where('step', 'PRBL03')->where('action', 'created')->first();
    expect($prbl03Created)->not->toBeNull();
});

// ── PRBL02A Reject ──

it('rejects PRBL02A with required notes', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.reject',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02a.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Narasi kurang detail dan tidak mencantumkan langkah',
    ]);

    $response->assertRedirect();

    $prblWorkflow->refresh();
    $lastRejection = collect($prblWorkflow->history)->where('step', 'PRBL02A')->where('action', 'rejected')->first();
    expect($lastRejection)->not->toBeNull()
        ->and($lastRejection['notes'])->toBe('Narasi kurang detail dan tidak mencantumkan langkah');
});

it('rejects PRBL02A validation when notes missing', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.reject',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02a.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        // notes missing
    ]);

    $response->assertSessionHasErrors('notes');
});

// ── PRBL02A Reject + PRBL02B Approve → Cycle back to PRBL01 ──

it('cycles back to PRBL01 when PRBL02A rejects and PRBL02B approves', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.reject',
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.approve',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    // PRBL02A rejects
    $prblWorkflow->refresh();
    $this->post(route('admin.workflows.prbl.prbl02a.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Narasi perlu perbaikan signifikan',
    ]);

    // PRBL02B approves (last track → triggers join → any rejected → cycle back)
    $prblWorkflow->refresh();
    $this->post(route('admin.workflows.prbl.prbl02b.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ]);

    $prblWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);

    // PRBL01 should be active again (new cycle)
    expect($statuses['PRBL01']['status'])->toBe('active')
        ->and($statuses['PRBL01']['cycle'])->toBe(2);

    // A new PRBL01 data row should exist
    $newPrbl01 = $prblWorkflow->latestPrbl01();
    expect($newPrbl01->id)->not->toBe($prbl01->id);

    // Verify cycle-back data copied from previous
    $newItemKegiatan = Prbl01ItemKegiatan::where('prbl01_data_id', $newPrbl01->id)->first();
    expect($newItemKegiatan)->not->toBeNull()
        ->and($newItemKegiatan->masalah)->toBe('Test masalah');
});

// ── Both PRBL02A + PRBL02B Reject → Cycle back to PRBL01 ──

it('cycles back to PRBL01 with compiled feedback when both reject', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.reject',
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.reject',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    // Both reject
    $prblWorkflow->refresh();
    $this->post(route('admin.workflows.prbl.prbl02a.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Narasi tidak lengkap',
    ]);

    $prblWorkflow->refresh();
    $this->post(route('admin.workflows.prbl.prbl02b.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Realisasi tidak sesuai nota',
    ]);

    $prblWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);

    expect($statuses['PRBL01']['status'])->toBe('active')
        ->and($statuses['PRBL01']['cycle'])->toBe(2);

    // PRBL03 rejection entry should contain compiled notes
    $prbl03Rejection = collect($prblWorkflow->history)->where('step', 'PRBL03')->where('action', 'rejected')->first();
    expect($prbl03Rejection)->not->toBeNull()
        ->and($prbl03Rejection['notes'])->toContain('PRBL02A')
        ->and($prbl03Rejection['notes'])->toContain('PRBL02B')
        ->and($prbl03Rejection['notes'])->toContain('Narasi tidak lengkap')
        ->and($prbl03Rejection['notes'])->toContain('Realisasi tidak sesuai nota');
});

// ── PRBL02A Denies ──

it('denies PRBL02A approve without permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        // No approve permission
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('denies PRBL02A approve when step not active', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.approve',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    // NOT advancing to PRBL02 — PRBL01 still active

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ]);

    $response->assertStatus(409);
});

it('denies PRBL02A approve with stale optimistic lock', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02a.show',
        'admin.workflows.prbl.prbl02a.approve',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->post(route('admin.workflows.prbl.prbl02a.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $response->assertStatus(409);
});

// ── PRBL02B Show ──

it('shows PRBL02B page for admin scope with realisasi data', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.approve',
        'admin.workflows.prbl.prbl02b.reject',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->get(route('admin.workflows.prbl.prbl02b.show', ['prblWorkflow' => $prblWorkflow->id]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/prbl/prbl02b')
            ->where('mode', 'review')
            ->where('canApprove', true)
            ->where('canReject', true)
            ->where('parallelTrackStatus.step', 'PRBL02A')
            ->where('parallelTrackStatus.status', 'active')
        );
});

// ── PRBL02B Approve ──

it('approves PRBL02B and waits for PRBL02A (silent)', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.approve',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02b.approve', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
    ]);

    $response->assertRedirect();

    $prblWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PrblWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);

    expect($statuses['PRBL02B']['status'])->toBe('completed')
        ->and($statuses['PRBL02A']['status'])->toBe('active')
        ->and($statuses['PRBL03']['status'])->toBe('pending');
});

// ── PRBL02B Reject ──

it('rejects PRBL02B validation when notes too short', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02b.show',
        'admin.workflows.prbl.prbl02b.reject',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02b.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'short',  // < 10 chars
    ]);

    $response->assertSessionHasErrors('notes');
});

it('denies PRBL02B reject without permission', function () {
    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl02b.show',
        // No reject permission
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $prblWorkflow->refresh();
    $response = $this->post(route('admin.workflows.prbl.prbl02b.reject', ['prblWorkflow' => $prblWorkflow->id]), [
        'expected_updated_at' => $prblWorkflow->updated_at->toIso8601String(),
        'notes' => 'Realisasi tidak sesuai bukti pengeluaran',
    ]);

    $response->assertForbidden();
});

// ────────────────────────────────────────────────
// PRBL03 — Refund & Bukti Transfer
// ────────────────────────────────────────────────

/**
 * Advance workflow through PRBL01 submit + PRBL02A/02B approve, creating PRBL03 data.
 */
function advanceToPrbl03(object $prblWorkflow, object $prbl01, object $itemKegiatan, object $itemKuisioner, object $realisasi1, object $realisasi2, int $userId): Prbl03Data
{
    advanceToPrbl02($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $userId);

    $engine = new WorkflowEngine;
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02A', action: 'approved', userId: $userId, sessionContext: []);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02B', action: 'approved', userId: $userId, sessionContext: []);

    // Compute refund (same logic as join handler)
    $totalDicairkan = (float) Pk04Anggaran::where('pencairan_pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
        ->where('status_pencairan', 'dicairkan')
        ->sum('nominal_anggaran');
    $latestPrbl01 = $prblWorkflow->fresh()->latestPrbl01();
    $totalRealisasi = $latestPrbl01 ? (float) $latestPrbl01->itemRealisasi()->sum('nominal_realisasi') : 0;
    $nominalRefund = max(0, $totalDicairkan - $totalRealisasi);

    $prbl03Data = Prbl03Data::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'nominal_refund' => $nominalRefund,
    ]);

    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL03', action: 'created', userId: null, sessionContext: [], table: 'prbl03_data', dataId: $prbl03Data->id);

    return $prbl03Data;
}

// ── PRBL03 Show ──

it('shows PRBL03 page in edit mode for team scope', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
        'team.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->get(route('team.workflows.prbl.prbl03.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/prbl/prbl03')
            ->where('mode', 'edit')
            ->where('scope', 'team')
            ->where('canDraft', true)
            ->where('canSubmit', true)
            ->where('canComment', true)
            ->where('nominalRefund', fn ($v) => (float) $v === 700000.0)
            ->where('totalDicairkan', fn ($v) => (float) $v === 3500000.0)
            ->where('totalRealisasi', fn ($v) => (float) $v === 2800000.0)
            ->has('kegiatanItems', 1)
            ->where('kegiatanItems.0.program_name', 'Program Kegiatan')
            ->has('kegiatanItems.0.kegiatan', 1)
            ->where('prbl03.nominal_refund', fn ($v) => (float) $v === 700000.0)
        );
});

it('shows PRBL03 as readonly for admin scope', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'admin.workflows.prbl.prbl03.show',
        'admin.workflows.prbl.comment',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->get(route('admin.workflows.prbl.prbl03.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/prbl/prbl03')
            ->where('mode', 'readonly')
            ->where('scope', 'admin')
            ->where('canDraft', false)
            ->where('canSubmit', false)
        );
});

it('denies PRBL03 show without permission', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        // Missing: team.workflows.prbl.prbl03.show
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->get(route('team.workflows.prbl.prbl03.show', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]));

    $response->assertForbidden();
});

// ── PRBL03 Draft ──

it('saves PRBL03 draft with files and keterangan', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->post(route('team.workflows.prbl.prbl03.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->updated_at->toIso8601String(),
        'keterangan' => 'Refund sudah ditransfer ke rekening organisasi.',
        'bukti_transfer_files' => [UploadedFile::fake()->image('bukti1.jpg')],
        'foto_nota_files' => [UploadedFile::fake()->image('nota1.jpg'), UploadedFile::fake()->image('nota2.jpg')],
    ]);

    $response->assertRedirect();

    $prbl03Data->refresh();
    expect($prbl03Data->keterangan)->toBe('Refund sudah ditransfer ke rekening organisasi.');

    // Verify files created
    expect($prbl03Data->bukti()->where('tipe', 'bukti_transfer')->count())->toBe(1);
    expect($prbl03Data->bukti()->where('tipe', 'foto_nota')->count())->toBe(2);

    // Verify history
    $prblWorkflow->refresh();
    $lastEntry = collect($prblWorkflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PRBL03');
});

it('denies PRBL03 draft without permission', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        // No draft permission
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->post(route('team.workflows.prbl.prbl03.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->updated_at->toIso8601String(),
    ]);

    $response->assertForbidden();
});

it('rejects PRBL03 draft with stale expected_updated_at', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    $response = $this->post(route('team.workflows.prbl.prbl03.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $response->assertStatus(409);
});

// ── PRBL03 Submit ──

it('submits PRBL03 with bukti transfer and foto nota and activates PRBL04', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    // nominal_refund = 700000 > 0, so bukti_transfer required
    expect((float) $prbl03Data->nominal_refund)->toBe(700000.0);

    $response = $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->updated_at->toIso8601String(),
        'keterangan' => 'Refund telah ditransfer.',
        'bukti_transfer_files' => [UploadedFile::fake()->image('bukti1.jpg')],
        'foto_nota_files' => [UploadedFile::fake()->image('nota1.jpg')],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Verify keterangan saved
    $prbl03Data->refresh();
    expect($prbl03Data->keterangan)->toBe('Refund telah ditransfer.');

    // Verify files
    expect($prbl03Data->bukti()->where('tipe', 'bukti_transfer')->count())->toBe(1);
    expect($prbl03Data->bukti()->where('tipe', 'foto_nota')->count())->toBe(1);

    // Verify PRBL03 submitted + PRBL04 created in history
    $prblWorkflow->refresh();
    $history = $prblWorkflow->history;
    $steps = collect($history)->pluck('step', 'action')->flip()->all();
    $lastTwo = collect($history)->slice(-2)->values();
    expect($lastTwo[0]['step'])->toBe('PRBL03')
        ->and($lastTwo[0]['action'])->toBe('submitted')
        ->and($lastTwo[1]['step'])->toBe('PRBL04')
        ->and($lastTwo[1]['action'])->toBe('created');

    // PRBL03 should be completed, PRBL04 should be active
    $definition = app(PrblWorkflowDefinition::class);
    $engine = new WorkflowEngine;
    $statuses = $engine->getStepStatuses($definition, $prblWorkflow->history);
    expect($statuses['PRBL03']['status'])->toBe('completed');
    expect($statuses['PRBL04']['status'])->toBe('active');
});

it('rejects PRBL03 submit without foto nota', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    // Submit without any foto_nota files
    $response = $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->updated_at->toIso8601String(),
        'bukti_transfer_files' => [UploadedFile::fake()->image('bukti1.jpg')],
    ]);

    $response->assertStatus(422);
});

it('rejects PRBL03 submit without bukti transfer when refund > 0', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    // refund = 700000 > 0, submit with foto_nota but without bukti_transfer
    expect((float) $prbl03Data->nominal_refund)->toBeGreaterThan(0);

    $response = $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->updated_at->toIso8601String(),
        'foto_nota_files' => [UploadedFile::fake()->image('nota1.jpg')],
    ]);

    $response->assertStatus(422);
});

it('allows PRBL03 submit without bukti transfer when refund is zero', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    // Make realisasi equal to dicairkan so refund = 0
    $realisasi1->update(['nominal_realisasi' => 2500000]);
    $realisasi2->update(['nominal_realisasi' => 1000000]);

    // Manually advance (since advanceToPrbl03 would use old realisasi values)
    $engine = new WorkflowEngine;
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL01', action: 'submitted', userId: $user->id, sessionContext: [], table: 'prbl01_data', dataId: $prbl01->id);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02A', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02B', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02A', action: 'approved', userId: $user->id, sessionContext: []);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL02B', action: 'approved', userId: $user->id, sessionContext: []);

    $prbl03Data = Prbl03Data::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'nominal_refund' => 0,
    ]);
    $engine->recordAction(workflow: $prblWorkflow, step: 'PRBL03', action: 'created', userId: null, sessionContext: [], table: 'prbl03_data', dataId: $prbl03Data->id);

    expect((float) $prbl03Data->nominal_refund)->toBe(0.0);

    // Submit with only foto_nota (no bukti_transfer needed)
    $response = $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->updated_at->toIso8601String(),
        'foto_nota_files' => [UploadedFile::fake()->image('nota1.jpg')],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // PRBL04 should be created
    $prblWorkflow->refresh();
    $lastEntry = collect($prblWorkflow->history)->last();
    expect($lastEntry['step'])->toBe('PRBL04')
        ->and($lastEntry['action'])->toBe('created');
});

it('rejects PRBL03 submit when step is not active', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);

    // Create PRBL03 data but don't advance through PRBL02A/02B (step not active)
    $prbl03Data = Prbl03Data::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'nominal_refund' => 0,
    ]);

    $response = $this->post(route('team.workflows.prbl.prbl03.submit', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->updated_at->toIso8601String(),
        'foto_nota_files' => [UploadedFile::fake()->image('nota1.jpg')],
    ]);

    $response->assertStatus(409);
});

it('removes previously uploaded files during PRBL03 draft', function () {
    Storage::fake('local');

    [$user, $role, $workspace, $team] = setupPrblUser(
        'team.workflows.prbl.prbl03.show',
        'team.workflows.prbl.prbl03.draft',
        'team.workflows.prbl.prbl03.submit',
    );
    activatePrblSession($this, $user, $role, $workspace);

    [$ppWorkflow] = setupCompletedPpForPrbl($workspace, $team);
    [$pkWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner] = setupPk04ForPrbl($workspace, $team, $ppWorkflow, 3, $user, $role);
    [$prblWorkflow, $pabdWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2] = setupPrblWorkflow($workspace, $team, $ppWorkflow, $pk04, $kegiatan, $ang1, $ang2, $kuisioner, 3, $user, $role);
    $prbl03Data = advanceToPrbl03($prblWorkflow, $prbl01, $itemKegiatan, $itemKuisioner, $realisasi1, $realisasi2, $user->id);

    // First draft: upload files
    $this->post(route('team.workflows.prbl.prbl03.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->fresh()->updated_at->toIso8601String(),
        'foto_nota_files' => [UploadedFile::fake()->image('nota1.jpg'), UploadedFile::fake()->image('nota2.jpg')],
    ]);

    expect($prbl03Data->bukti()->where('tipe', 'foto_nota')->count())->toBe(2);
    $firstBuktiId = $prbl03Data->bukti()->where('tipe', 'foto_nota')->first()->id;

    // Second draft: remove one file
    $response = $this->post(route('team.workflows.prbl.prbl03.draft', [
        'prblWorkflow' => $prblWorkflow->id,
        'prbl03Data' => $prbl03Data->id,
    ]), [
        'expected_updated_at' => $prbl03Data->fresh()->updated_at->toIso8601String(),
        'remove_foto_nota_ids' => [$firstBuktiId],
    ]);

    $response->assertRedirect();
    expect($prbl03Data->bukti()->where('tipe', 'foto_nota')->count())->toBe(1);
    expect(Prbl03Bukti::find($firstBuktiId))->toBeNull();
});
