<?php

/**
 * Tests for PABD05 and PRBL05 export file generation, download routes, and verification codes.
 */

use App\Models\File;
use App\Models\Pabd\Pabd05ItemAnggaran;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\Prbl05ItemRealisasi;
use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\PrblWorkflow;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\PabdCompileService;
use App\Services\PrblCompileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// === Helpers ===

function createPabd05ForTest(): array
{
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $team = $user->roles->first()->team;

    $ppWorkflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'pp01_created_by_user_name' => 'Test User',
        'pp01_created_by_role_name' => 'Test Role',
        'pp01_created_by_organization_name' => 'Test Org',
        'pp01_created_by_workspace_name' => 'Test Workspace',
        'pp01_created_at' => now(),
        'pp02_created_by_user_name' => 'Test User',
        'pp02_created_by_role_name' => 'Test Role',
        'pp02_created_by_organization_name' => 'Test Org',
        'pp02_created_by_workspace_name' => 'Test Workspace',
        'pp02_created_at' => now(),
        'pp03_created_by_user_name' => 'Test User',
        'pp03_created_by_role_name' => 'BU',
        'pp03_created_by_organization_name' => 'Test Org',
        'pp03_created_by_workspace_name' => 'Test Workspace',
        'pp03_created_at' => now(),
        'pp04_created_by_user_name' => 'Test User',
        'pp04_created_by_role_name' => 'Test Role',
        'pp04_created_by_organization_name' => 'Test Org',
        'pp04_created_by_workspace_name' => 'Test Workspace',
        'pp04_created_at' => now(),
        'pp05_created_by_user_name' => 'Test User',
        'pp05_created_by_role_name' => 'Test Role',
        'pp05_created_by_organization_name' => 'Test Org',
        'pp05_created_by_workspace_name' => 'Test Workspace',
        'pp05_created_at' => now(),
    ]);

    $role = $user->roles->first();

    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 6,
        'tahun_anggaran' => 2026,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [],
    ]);

    // PK data for FK references
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [],
    ]);
    $pk04 = Pk04ProgramTahunan::create([
        'pk_workflow_id' => $pkWorkflow->id,
        'revision' => 0,
        'pk01_created_by_user_name' => 'Test User',
        'pk01_created_by_role_name' => 'Test Role',
        'pk01_created_by_team_name' => 'Test Team',
        'pk01_created_by_organization_name' => 'Test Org',
        'pk01_created_by_workspace_name' => 'Test Workspace',
        'pk01_created_at' => now(),
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Uji',
        'deskripsi_program' => 'Deskripsi uji',
        'tujuan_program' => 'Tujuan uji',
        'nomer_program' => 1,
    ]);
    $kegiatan = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Kegiatan Uji',
        'bulan' => 6,
        'nomer_kegiatan' => 1,
    ]);
    $ang1 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Konsumsi uji',
        'nominal_anggaran' => 500000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.001.2026.06.rev0.M0',
        'status_item' => 'active',
    ]);
    $ang2 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Transport',
        'deskripsi_pk' => 'Transport uji',
        'nominal_anggaran' => 300000,
        'nomer_anggaran' => 2,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.002.2026.06.rev0.M0',
        'status_item' => 'active',
    ]);

    $pabd05 = Pabd05PengajuanBulanan::create([
        'pabd_workflow_id' => $pabdWorkflow->id,
        'verification_code' => 'TESTPABD',
        'pabd01_created_by_user_name' => 'Test User',
        'pabd01_created_by_role_name' => 'Test Role',
        'pabd01_created_by_team_name' => 'Test Team',
        'pabd01_created_by_organization_name' => 'Test Org',
        'pabd01_created_by_workspace_name' => 'Test Workspace',
        'pabd01_created_at' => now(),
        'pabd03_approved_by_user_name' => 'Test BU',
        'pabd03_approved_by_role_name' => 'BU',
        'pabd03_approved_by_team_name' => 'Test Team',
        'pabd03_approved_by_organization_name' => 'Test Org',
        'pabd03_approved_by_workspace_name' => 'Test Workspace',
        'pabd03_approved_at' => now(),
        'pabd04_created_by_user_name' => 'Test KG',
        'pabd04_created_by_role_name' => 'Kantor Pusat',
        'pabd04_created_by_team_name' => 'Test Team',
        'pabd04_created_by_organization_name' => 'Test Org',
        'pabd04_created_by_workspace_name' => 'Test Workspace',
        'pabd04_created_at' => now(),
        'nama_bank' => 'BCA',
        'nama_rekening' => 'Demo Test',
        'nomor_rekening' => '1234567890',
        'total_anggaran_dicairkan' => 500000,
        'total_item_dicairkan' => 1,
        'total_item_hangus' => 1,
    ]);

    Pabd05ItemAnggaran::create([
        'pabd05_pengajuan_bulanan_id' => $pabd05->id,
        'pk04_anggaran_id' => $ang1->id,
        'status' => 'dicairkan',
        'nominal_anggaran' => 500000,
    ]);
    Pabd05ItemAnggaran::create([
        'pabd05_pengajuan_bulanan_id' => $pabd05->id,
        'pk04_anggaran_id' => $ang2->id,
        'status' => 'hangus',
        'nominal_anggaran' => 300000,
    ]);

    return [
        'workflow' => $pabdWorkflow,
        'pabd05' => $pabd05,
        'user' => $user,
        'workspace' => $workspace,
    ];
}

function createPrbl05ForTest(): array
{
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $team = $user->roles->first()->team;

    $ppWorkflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'pp01_created_by_user_name' => 'Test User',
        'pp01_created_by_role_name' => 'Test Role',
        'pp01_created_by_organization_name' => 'Test Org',
        'pp01_created_by_workspace_name' => 'Test Workspace',
        'pp01_created_at' => now(),
        'pp02_created_by_user_name' => 'Test User',
        'pp02_created_by_role_name' => 'Test Role',
        'pp02_created_by_organization_name' => 'Test Org',
        'pp02_created_by_workspace_name' => 'Test Workspace',
        'pp02_created_at' => now(),
        'pp03_created_by_user_name' => 'Test User',
        'pp03_created_by_role_name' => 'BU',
        'pp03_created_by_organization_name' => 'Test Org',
        'pp03_created_by_workspace_name' => 'Test Workspace',
        'pp03_created_at' => now(),
        'pp04_created_by_user_name' => 'Test User',
        'pp04_created_by_role_name' => 'Test Role',
        'pp04_created_by_organization_name' => 'Test Org',
        'pp04_created_by_workspace_name' => 'Test Workspace',
        'pp04_created_at' => now(),
        'pp05_created_by_user_name' => 'Test User',
        'pp05_created_by_role_name' => 'Test Role',
        'pp05_created_by_organization_name' => 'Test Org',
        'pp05_created_by_workspace_name' => 'Test Workspace',
        'pp05_created_at' => now(),
    ]);

    $role = $user->roles->first();

    // PRBL requires a completed PABD workflow
    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 6,
        'tahun_anggaran' => 2026,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [
            ['step' => 'PABD05', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $prblWorkflow = PrblWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => 6,
        'tahun_laporan' => 2026,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [],
    ]);

    // PK data for FK references
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $team->organization->id,
        'history' => [],
    ]);
    $pk04 = Pk04ProgramTahunan::create([
        'pk_workflow_id' => $pkWorkflow->id,
        'revision' => 0,
        'pk01_created_by_user_name' => 'Test User',
        'pk01_created_by_role_name' => 'Test Role',
        'pk01_created_by_team_name' => 'Test Team',
        'pk01_created_by_organization_name' => 'Test Org',
        'pk01_created_by_workspace_name' => 'Test Workspace',
        'pk01_created_at' => now(),
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Uji',
        'deskripsi_program' => 'Deskripsi uji',
        'tujuan_program' => 'Tujuan uji',
        'nomer_program' => 1,
    ]);
    $kegiatan = Pk04Kegiatan::create([
        'pk04_program_tahunan_id' => $pk04->id,
        'nama_kegiatan' => 'Kegiatan Uji',
        'bulan' => 6,
        'nomer_kegiatan' => 1,
    ]);
    $ang1 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Konsumsi uji',
        'nominal_anggaran' => 500000,
        'nomer_anggaran' => 1,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.001.2026.06.rev0.M0',
        'status_item' => 'active',
    ]);
    $ang2 = Pk04Anggaran::create([
        'pk04_kegiatan_id' => $kegiatan->id,
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Transport',
        'deskripsi_pk' => 'Transport uji',
        'nominal_anggaran' => 300000,
        'nomer_anggaran' => 2,
        'revisi_terakhir' => 0,
        'kode_anggaran_baru' => 'R.01.01.01.01.01.001.001.002.2026.06.rev0.M0',
        'status_item' => 'active',
    ]);

    $prbl05 = Prbl05LaporanBulanan::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'verification_code' => 'TESTPRBL',
        'prbl01_created_by_user_name' => 'Test User',
        'prbl01_created_by_role_name' => 'Test Role',
        'prbl01_created_by_team_name' => 'Test Team',
        'prbl01_created_by_organization_name' => 'Test Org',
        'prbl01_created_by_workspace_name' => 'Test Workspace',
        'prbl01_created_at' => now(),
        'prbl02a_approved_by_user_name' => 'Test Monev',
        'prbl02a_approved_by_role_name' => 'Koordinator MONEV',
        'prbl02a_approved_by_team_name' => 'Test Team',
        'prbl02a_approved_by_organization_name' => 'Test Org',
        'prbl02a_approved_by_workspace_name' => 'Test Workspace',
        'prbl02a_approved_at' => now(),
        'prbl02b_approved_by_user_name' => 'Test BU',
        'prbl02b_approved_by_role_name' => 'BU',
        'prbl02b_approved_by_team_name' => 'Test Team',
        'prbl02b_approved_by_organization_name' => 'Test Org',
        'prbl02b_approved_by_workspace_name' => 'Test Workspace',
        'prbl02b_approved_at' => now(),
        'prbl03_created_by_user_name' => 'Test User',
        'prbl03_created_by_role_name' => 'Test Role',
        'prbl03_created_by_team_name' => 'Test Team',
        'prbl03_created_by_organization_name' => 'Test Org',
        'prbl03_created_by_workspace_name' => 'Test Workspace',
        'prbl03_created_at' => now(),
        'prbl04_approved_by_user_name' => 'Test BU',
        'prbl04_approved_by_role_name' => 'BU',
        'prbl04_approved_by_team_name' => 'Test Team',
        'prbl04_approved_by_organization_name' => 'Test Org',
        'prbl04_approved_by_workspace_name' => 'Test Workspace',
        'prbl04_approved_at' => now(),
        'keterangan' => 'Laporan bulanan Juni 2026.',
        'total_anggaran_dicairkan' => 800000,
        'total_realisasi' => 700000,
        'total_refund' => 100000,
        'total_item' => 2,
    ]);

    Prbl05ItemRealisasi::create([
        'prbl05_laporan_bulanan_id' => $prbl05->id,
        'pk04_anggaran_id' => $ang1->id,
        'nominal_anggaran' => 500000,
        'nominal_realisasi' => 450000,
        'selisih' => 50000,
        'komentar_realisasi' => 'Sesuai rencana.',
    ]);
    Prbl05ItemRealisasi::create([
        'prbl05_laporan_bulanan_id' => $prbl05->id,
        'pk04_anggaran_id' => $ang2->id,
        'nominal_anggaran' => 300000,
        'nominal_realisasi' => 250000,
        'selisih' => 50000,
        'komentar_realisasi' => 'Hemat transport.',
    ]);

    return [
        'workflow' => $prblWorkflow,
        'prbl05' => $prbl05,
        'user' => $user,
        'workspace' => $workspace,
    ];
}

function activatePabdExportSession(object $test, $user, $role, $workspace): void
{
    $permissions = [
        'admin.workflows.pabd.pabd05.show',
        'admin.workflows.pabd.pabd05.export.pdf',
        'admin.workflows.pabd.pabd05.export.excel',
        'admin.workflows.pabd.pabd05.export.zip',
    ];

    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function activatePrblExportSession(object $test, $user, $role, $workspace): void
{
    $permissions = [
        'admin.workflows.prbl.prbl05.show',
        'admin.workflows.prbl.prbl05.export.pdf',
        'admin.workflows.prbl.prbl05.export.excel',
        'admin.workflows.prbl.prbl05.export.zip',
    ];

    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// === Tests: PABD05 Verification Code Generation ===

it('PABD05: generates 8-char verification code', function () {
    $service = new PabdCompileService;
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];

    $code = $service->generateVerificationCode($pabd05);

    expect($code)
        ->toBeString()
        ->toHaveLength(8)
        ->toMatch('/^[A-Z0-9]+$/');

    $pabd05->refresh();
    expect($pabd05->verification_code)->toBe($code);
});

it('PABD05: deterministic code for same data', function () {
    $service = new PabdCompileService;
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];

    $code1 = $service->generateVerificationCode($pabd05);
    $code2 = $service->generateVerificationCode($pabd05);

    expect($code1)->toBe($code2);
});

// === Tests: PRBL05 Verification Code Generation ===

it('PRBL05: generates 8-char verification code', function () {
    $service = new PrblCompileService;
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];

    $code = $service->generateVerificationCode($prbl05);

    expect($code)
        ->toBeString()
        ->toHaveLength(8)
        ->toMatch('/^[A-Z0-9]+$/');

    $prbl05->refresh();
    expect($prbl05->verification_code)->toBe($code);
});

it('PRBL05: deterministic code for same data', function () {
    $service = new PrblCompileService;
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];

    $code1 = $service->generateVerificationCode($prbl05);
    $code2 = $service->generateVerificationCode($prbl05);

    expect($code1)->toBe($code2);
});

// === Tests: Public Verify Endpoint ===

it('verifies PABD05 code via /verify endpoint', function () {
    $service = new PabdCompileService;
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];

    $code = $service->generateVerificationCode($pabd05);

    $this->actingAs($data['user']);
    $response = $this->getJson("/verify/{$code}");

    $response->assertOk()
        ->assertJson([
            'status' => 'valid',
            'document_type' => 'Pengajuan Anggaran Bulanan (PABD)',
        ]);
});

it('verifies PRBL05 code via /verify endpoint', function () {
    $service = new PrblCompileService;
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];

    $code = $service->generateVerificationCode($prbl05);

    $this->actingAs($data['user']);
    $response = $this->getJson("/verify/{$code}");

    $response->assertOk()
        ->assertJson([
            'status' => 'valid',
            'document_type' => 'Laporan Bulanan (PRBL)',
        ]);
});

it('returns 404 for unknown verification code', function () {
    $user = User::factory()->withRole()->create();
    $this->actingAs($user);

    $response = $this->getJson('/verify/ZZZZZZZZ');

    $response->assertNotFound()
        ->assertJson(['status' => 'not_found']);
});

it('detects tampered PABD05 data', function () {
    $service = new PabdCompileService;
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];

    $originalCode = $service->generateVerificationCode($pabd05);

    // Tamper: change total_anggaran_dicairkan directly in DB
    $pabd05->update(['total_anggaran_dicairkan' => 9999999]);

    $this->actingAs($data['user']);
    $response = $this->getJson("/verify/{$originalCode}");

    $response->assertOk()
        ->assertJson([
            'status' => 'tampered',
            'message' => 'Data telah berubah sejak dokumen ini dikompilasi.',
        ]);

    // Ensure original code is preserved
    $pabd05->refresh();
    expect($pabd05->verification_code)->toBe($originalCode);
});

it('detects tampered PRBL05 data', function () {
    $service = new PrblCompileService;
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];

    $originalCode = $service->generateVerificationCode($prbl05);

    // Tamper: change total_realisasi directly in DB
    $prbl05->update(['total_realisasi' => 9999999]);

    $this->actingAs($data['user']);
    $response = $this->getJson("/verify/{$originalCode}");

    $response->assertOk()
        ->assertJson([
            'status' => 'tampered',
            'message' => 'Data telah berubah sejak dokumen ini dikompilasi.',
        ]);

    // Ensure original code is preserved
    $prbl05->refresh();
    expect($prbl05->verification_code)->toBe($originalCode);
});

// === Tests: Export Service File Generation ===

it('PABD05 PDF export service generates a file', function () {
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];

    $service = app(\App\Services\Pabd05PdfExportService::class);
    $tempPath = $service->generate($pabd05);

    expect(file_exists($tempPath))->toBeTrue();
    expect(filesize($tempPath))->toBeGreaterThan(0);

    @unlink($tempPath);
});

it('PABD05 Excel export service generates a file', function () {
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];

    $service = app(\App\Services\Pabd05ExcelExportService::class);
    $tempPath = $service->generate($pabd05);

    expect(file_exists($tempPath))->toBeTrue();
    expect(filesize($tempPath))->toBeGreaterThan(0);
    expect(str_ends_with($tempPath, '.xlsx'))->toBeTrue();

    @unlink($tempPath);
});

it('PRBL05 PDF export service generates a file', function () {
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];

    $service = app(\App\Services\Prbl05PdfExportService::class);
    $tempPath = $service->generate($prbl05);

    expect(file_exists($tempPath))->toBeTrue();
    expect(filesize($tempPath))->toBeGreaterThan(0);

    @unlink($tempPath);
});

it('PRBL05 Excel export service generates a file', function () {
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];

    $service = app(\App\Services\Prbl05ExcelExportService::class);
    $tempPath = $service->generate($prbl05);

    expect(file_exists($tempPath))->toBeTrue();
    expect(filesize($tempPath))->toBeGreaterThan(0);
    expect(str_ends_with($tempPath, '.xlsx'))->toBeTrue();

    @unlink($tempPath);
});

// === Tests: Export via CompileService (PDF + Excel + File records) ===

it('PABD05 generates PDF and Excel files via PabdCompileService', function () {
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];
    $user = $data['user'];
    $workspace = $data['workspace'];

    $service = new PabdCompileService;
    $result = $service->generateExportFiles($pabd05, $user->id, $workspace->id);

    expect($result['pdf_file_id'])->not->toBeNull();
    expect($result['excel_file_id'])->not->toBeNull();

    $pdfFile = File::find($result['pdf_file_id']);
    expect($pdfFile->mime_type)->toBe('application/pdf');
    expect($pdfFile->source_route)->toBe('pabd05.export.pdf');
    expect($pdfFile->attachable_id)->toBe($pabd05->id);

    $excelFile = File::find($result['excel_file_id']);
    expect($excelFile->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($excelFile->source_route)->toBe('pabd05.export.excel');

    expect(Storage::disk('local')->exists($pdfFile->path))->toBeTrue();
    expect(Storage::disk('local')->exists($excelFile->path))->toBeTrue();

    // Cleanup
    Storage::disk('local')->delete($pdfFile->path);
    Storage::disk('local')->delete($excelFile->path);
});

it('PRBL05 generates PDF and Excel files via PrblCompileService', function () {
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];
    $user = $data['user'];
    $workspace = $data['workspace'];

    $service = new PrblCompileService;
    $result = $service->generateExportFiles($prbl05, $user->id, $workspace->id);

    expect($result['pdf_file_id'])->not->toBeNull();
    expect($result['excel_file_id'])->not->toBeNull();

    $pdfFile = File::find($result['pdf_file_id']);
    expect($pdfFile->mime_type)->toBe('application/pdf');
    expect($pdfFile->source_route)->toBe('prbl05.export.pdf');
    expect($pdfFile->attachable_id)->toBe($prbl05->id);

    $excelFile = File::find($result['excel_file_id']);
    expect($excelFile->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($excelFile->source_route)->toBe('prbl05.export.excel');

    expect(Storage::disk('local')->exists($pdfFile->path))->toBeTrue();
    expect(Storage::disk('local')->exists($excelFile->path))->toBeTrue();

    // Cleanup
    Storage::disk('local')->delete($pdfFile->path);
    Storage::disk('local')->delete($excelFile->path);
});

// === Tests: Export Download Routes ===

it('PABD05 PDF download returns file when available', function () {
    $data = createPabd05ForTest();
    $pabd05 = $data['pabd05'];
    $user = $data['user'];
    $workspace = $data['workspace'];
    $role = $user->roles->first();

    // Generate export files
    $service = new PabdCompileService;
    $service->generateExportFiles($pabd05, $user->id, $workspace->id);

    activatePabdExportSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.workflows.pabd.pabd05.export.pdf', [
        'pabdWorkflow' => $data['workflow']->id,
    ]));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');

    // Cleanup
    File::where('attachable_id', $pabd05->id)->get()->each(function ($file) {
        Storage::disk('local')->delete($file->path);
    });
});

it('PABD05 returns error when file not available', function () {
    $data = createPabd05ForTest();
    $user = $data['user'];
    $workspace = $data['workspace'];
    $role = $user->roles->first();

    // No export files generated — should redirect with error
    activatePabdExportSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.workflows.pabd.pabd05.export.pdf', [
        'pabdWorkflow' => $data['workflow']->id,
    ]));

    $response->assertRedirect();
    $response->assertSessionHasErrors('export');
});

it('PRBL05 PDF download returns file when available', function () {
    $data = createPrbl05ForTest();
    $prbl05 = $data['prbl05'];
    $user = $data['user'];
    $workspace = $data['workspace'];
    $role = $user->roles->first();

    // Generate export files
    $service = new PrblCompileService;
    $service->generateExportFiles($prbl05, $user->id, $workspace->id);

    activatePrblExportSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.workflows.prbl.prbl05.export.pdf', [
        'prblWorkflow' => $data['workflow']->id,
    ]));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');

    // Cleanup
    File::where('attachable_id', $prbl05->id)->get()->each(function ($file) {
        Storage::disk('local')->delete($file->path);
    });
});

it('PRBL05 returns error when file not available', function () {
    $data = createPrbl05ForTest();
    $user = $data['user'];
    $workspace = $data['workspace'];
    $role = $user->roles->first();

    // No export files generated — should redirect with error
    activatePrblExportSession($this, $user, $role, $workspace);

    $response = $this->get(route('admin.workflows.prbl.prbl05.export.pdf', [
        'prblWorkflow' => $data['workflow']->id,
    ]));

    $response->assertRedirect();
    $response->assertSessionHasErrors('export');
});
