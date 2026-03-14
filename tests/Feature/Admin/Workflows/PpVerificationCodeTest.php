<?php

/**
 * Tests for verification code generation and the public verify endpoint.
 */

use App\Models\Pp\Pp06ItemKuisioner;
use App\Models\Pp\Pp06ItemPlafonAnggaran;
use App\Models\Pp\Pp06KodeBidangPelayanan;
use App\Models\Pp\Pp06KodeJenisProgram;
use App\Models\Pp\Pp06KodeKategoriPelayanan;
use App\Models\Pp\Pp06KodeSubBidangPelayanan;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Team;
use App\Models\User;
use App\Services\PpCompileService;

// === Helpers ===

function createPp06ForVerification(PpWorkflow $workflow, int $revision = 0): Pp06PeriodeTahunan
{
    return Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $workflow->id,
        'revision' => $revision,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'pp01_created_by_user_name' => 'Budi Santoso',
        'pp01_created_by_role_name' => 'Koordinator MONEV',
        'pp01_created_by_organization_name' => 'Finance Playground',
        'pp01_created_by_workspace_name' => 'Workspace MONEV',
        'pp01_created_at' => now(),
        'pp02_created_by_user_name' => 'Budi Santoso',
        'pp02_created_by_role_name' => 'Koordinator MONEV',
        'pp02_created_by_organization_name' => 'Finance Playground',
        'pp02_created_by_workspace_name' => 'Workspace MONEV',
        'pp02_created_at' => now(),
        'pp03_created_by_user_name' => 'Budi Santoso',
        'pp03_created_by_role_name' => 'BU',
        'pp03_created_by_organization_name' => 'Finance Playground',
        'pp03_created_by_workspace_name' => 'Workspace MONEV',
        'pp03_created_at' => now(),
        'pp04_created_by_user_name' => 'Budi Santoso',
        'pp04_created_by_role_name' => 'Koordinator MONEV',
        'pp04_created_by_organization_name' => 'Finance Playground',
        'pp04_created_by_workspace_name' => 'Workspace MONEV',
        'pp04_created_at' => now(),
        'pp05_created_by_user_name' => 'Budi Santoso',
        'pp05_created_by_role_name' => 'Koordinator MONEV',
        'pp05_created_by_organization_name' => 'Finance Playground',
        'pp05_created_by_workspace_name' => 'Workspace MONEV',
        'pp05_created_at' => now(),
    ]);
}

function seedPp06VerificationData(Pp06PeriodeTahunan $pp06, ?Team $team = null): void
{
    Pp06KodeBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]);
    Pp06KodeSubBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => '01A', 'nama' => 'Sub Kegiatan', 'catatan' => null]);
    Pp06KodeKategoriPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'K01', 'nama' => 'Anak', 'catatan' => null]);
    Pp06KodeJenisProgram::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'J01', 'nama' => 'Rutin', 'catatan' => null]);
    Pp06ItemKuisioner::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'Q01', 'pertanyaan' => 'Jumlah anggota?', 'tipe' => 'Kuantitatif', 'satuan' => 'orang']);

    if ($team) {
        Pp06ItemPlafonAnggaran::create([
            'pp06_periode_tahunan_id' => $pp06->id,
            'team_id' => $team->id,
            'kode_team' => 'KA',
            'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA',
            'nama_rekening' => 'Demo KA',
            'nomor_rekening' => '1234567890',
        ]);
    }
}

// === Tests: Verification Code Generation ===

it('generates an 8-char verification code on compile', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForVerification($workflow);
    seedPp06VerificationData($pp06);

    $code = $service->generateVerificationCode($pp06);

    expect($code)
        ->toBeString()
        ->toHaveLength(8)
        ->toMatch('/^[A-Z0-9]+$/');

    $pp06->refresh();
    expect($pp06->verification_code)->toBe($code);
});

it('generates deterministic code for same data', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForVerification($workflow);
    seedPp06VerificationData($pp06);

    $code1 = $service->generateVerificationCode($pp06);
    $code2 = $service->generateVerificationCode($pp06);

    expect($code1)->toBe($code2);
});

it('generates different code when data changes', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForVerification($workflow);
    seedPp06VerificationData($pp06);

    $code1 = $service->generateVerificationCode($pp06);

    // Add another kode item — data changes — code should differ
    Pp06KodeBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => '02', 'nama' => 'Pendidikan', 'catatan' => null]);
    $pp06->unsetRelation('kodeBidangPelayanan');

    $code2 = $service->generateVerificationCode($pp06);

    expect($code1)->not->toBe($code2);
});

it('excludes author snapshots from hash — same substantive data produces same code', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();

    $workflow1 = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $pp06a = createPp06ForVerification($workflow1);
    seedPp06VerificationData($pp06a);
    $codeA = $service->generateVerificationCode($pp06a);

    // Create second PP06 with different author names but same substantive data
    $workflow2 = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $pp06b = Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $workflow2->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'pp01_created_by_user_name' => 'Different Author',
        'pp01_created_by_role_name' => 'Different Role',
        'pp01_created_by_organization_name' => 'Different Org',
        'pp01_created_by_workspace_name' => 'Different WS',
        'pp01_created_at' => now()->subDay(),
        'pp02_created_by_user_name' => 'Another Author',
        'pp02_created_by_role_name' => 'Another Role',
        'pp02_created_by_organization_name' => 'Another Org',
        'pp02_created_by_workspace_name' => 'Another WS',
        'pp02_created_at' => now()->subDay(),
        'pp03_created_by_user_name' => 'Yet Another',
        'pp03_created_by_role_name' => 'Yet Role',
        'pp03_created_by_organization_name' => 'Yet Org',
        'pp03_created_by_workspace_name' => 'Yet WS',
        'pp03_created_at' => now()->subDay(),
        'pp04_created_by_user_name' => 'Fourth Author',
        'pp04_created_by_role_name' => 'Fourth Role',
        'pp04_created_by_organization_name' => 'Fourth Org',
        'pp04_created_by_workspace_name' => 'Fourth WS',
        'pp04_created_at' => now()->subDay(),
        'pp05_created_by_user_name' => 'Fifth Author',
        'pp05_created_by_role_name' => 'Fifth Role',
        'pp05_created_by_organization_name' => 'Fifth Org',
        'pp05_created_by_workspace_name' => 'Fifth WS',
        'pp05_created_at' => now()->subDay(),
    ]);
    seedPp06VerificationData($pp06b);
    $codeB = $service->generateVerificationCode($pp06b);

    expect($codeA)->toBe($codeB);
});

it('builds canonical data with sorted collections', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForVerification($workflow);
    // Add kode items in reverse order — canonical data should sort them
    Pp06KodeBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => '02', 'nama' => 'Pendidikan', 'catatan' => null]);
    Pp06KodeBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]);

    $canonical = $service->buildCanonicalData($pp06);

    expect($canonical['tahun'])->toBe(2027);
    expect($canonical['kode_bidang_pelayanan'][0]['kode'])->toBe('01');
    expect($canonical['kode_bidang_pelayanan'][1]['kode'])->toBe('02');
});

// === Tests: Public Verify Endpoint ===

it('returns valid status for a legitimate verification code', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForVerification($workflow);
    seedPp06VerificationData($pp06);
    $code = $service->generateVerificationCode($pp06);

    $response = $this->getJson("/verify/{$code}");

    $response->assertOk()
        ->assertJson([
            'status' => 'valid',
            'document_type' => 'Periode Tahunan',
            'revision' => 0,
        ]);
});

it('returns 404 for unknown verification code', function () {
    $response = $this->getJson('/verify/ZZZZZZZZ');

    $response->assertNotFound()
        ->assertJson(['status' => 'not_found']);
});

it('detects tampered data via recomputed hash', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForVerification($workflow);
    seedPp06VerificationData($pp06);
    $originalCode = $service->generateVerificationCode($pp06);

    // Tamper: change tahun directly in DB
    $pp06->update(['tahun' => 9999]);

    $response = $this->getJson("/verify/{$originalCode}");

    $response->assertOk()
        ->assertJson([
            'status' => 'tampered',
            'message' => 'Data telah berubah sejak dokumen ini dikompilasi.',
        ]);

    // Ensure original code is preserved (not overwritten with recomputed one)
    $pp06->refresh();
    expect($pp06->verification_code)->toBe($originalCode);
});

it('does not require authentication for verify endpoint', function () {
    $service = new PpCompileService;
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForVerification($workflow);
    seedPp06VerificationData($pp06);
    $code = $service->generateVerificationCode($pp06);

    // Request without auth — should still work
    $response = $this->getJson("/verify/{$code}");

    $response->assertOk()
        ->assertJson(['status' => 'valid']);
});
