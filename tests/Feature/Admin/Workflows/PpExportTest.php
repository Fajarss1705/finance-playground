<?php

/**
 * Tests for PP06 export (PDF and Excel) generation and download routes.
 */

use App\Models\File;
use App\Models\Permission;
use App\Models\Pp\Pp06ItemKuisioner;
use App\Models\Pp\Pp06ItemPlafonAnggaran;
use App\Models\Pp\Pp06KodeBidangPelayanan;
use App\Models\Pp\Pp06KodeJenisProgram;
use App\Models\Pp\Pp06KodeKategoriPelayanan;
use App\Models\Pp\Pp06KodeSubBidangPelayanan;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\PpCompileService;

// === Helpers ===

function createPp06ForExport(PpWorkflow $workflow, int $revision = 0): Pp06PeriodeTahunan
{
    return Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $workflow->id,
        'revision' => $revision,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'verification_code' => 'TEST1234',
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

function seedPp06ExportData(Pp06PeriodeTahunan $pp06, $team = null): void
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

function activateExportSession(object $test, $user, $role, $workspace): void
{
    $permissions = [
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp06.export.pdf',
        'admin.workflows.pp.pp06.export.excel',
        'admin.workflows.pp.pp06.export.zip',
    ];

    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// === Tests: Export File Generation ===

it('generates PDF and Excel files via PpCompileService', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $team = $user->roles->first()->team;
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForExport($workflow);
    seedPp06ExportData($pp06, $team);

    $service = new PpCompileService;
    $result = $service->generateExportFiles($pp06, $user->id, $workspace->id);

    expect($result['pdf_file_id'])->not->toBeNull();
    expect($result['excel_file_id'])->not->toBeNull();

    // Verify File records were created
    $pdfFile = File::find($result['pdf_file_id']);
    expect($pdfFile->mime_type)->toBe('application/pdf');
    expect($pdfFile->source_route)->toBe('pp06.export.pdf');
    expect($pdfFile->attachable_id)->toBe($pp06->id);

    $excelFile = File::find($result['excel_file_id']);
    expect($excelFile->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($excelFile->source_route)->toBe('pp06.export.excel');

    // Verify files exist on disk
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists($pdfFile->path))->toBeTrue();
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists($excelFile->path))->toBeTrue();

    // Cleanup
    \Illuminate\Support\Facades\Storage::disk('local')->delete($pdfFile->path);
    \Illuminate\Support\Facades\Storage::disk('local')->delete($excelFile->path);
});

// === Tests: Download Routes ===

it('downloads PDF export for a PP06 revision', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForExport($workflow);
    seedPp06ExportData($pp06, $team);

    // Generate export files
    $service = new PpCompileService;
    $service->generateExportFiles($pp06, $user->id, $workspace->id);

    activateExportSession($this, $user, $role, $workspace);

    $response = $this->get("/admin/workflows/pp/{$workflow->id}/pp06/export/pdf?revision=0");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');

    // Cleanup
    File::where('attachable_id', $pp06->id)->get()->each(function ($file) {
        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->path);
    });
});

it('downloads Excel export for a PP06 revision', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForExport($workflow);
    seedPp06ExportData($pp06, $team);

    $service = new PpCompileService;
    $service->generateExportFiles($pp06, $user->id, $workspace->id);

    activateExportSession($this, $user, $role, $workspace);

    $response = $this->get("/admin/workflows/pp/{$workflow->id}/pp06/export/excel?revision=0");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Cleanup
    File::where('attachable_id', $pp06->id)->get()->each(function ($file) {
        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->path);
    });
});

it('regenerates export on-demand if file is missing', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForExport($workflow);
    seedPp06ExportData($pp06, $team);

    // No pre-generated files — should regenerate on demand
    activateExportSession($this, $user, $role, $workspace);

    $response = $this->get("/admin/workflows/pp/{$workflow->id}/pp06/export/pdf?revision=0");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');

    // Cleanup
    File::where('attachable_id', $pp06->id)->get()->each(function ($file) {
        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->path);
    });
});

it('blocks cross-workspace export download', function () {
    $user1 = User::factory()->withRole()->create();
    $role1 = $user1->roles->first();
    $workspace1 = $role1->team->organization->workspaces->first();

    $user2 = User::factory()->withRole()->create();
    $role2 = $user2->roles->first();
    $workspace2 = $role2->team->organization->workspaces->first();

    $workflow = PpWorkflow::create(['workspace_id' => $workspace1->id, 'history' => []]);
    $pp06 = createPp06ForExport($workflow);

    // User 2 tries to access User 1's workflow export
    activateExportSession($this, $user2, $role2, $workspace2);

    $response = $this->get("/admin/workflows/pp/{$workflow->id}/pp06/export/pdf?revision=0");

    $response->assertForbidden();
});

it('downloads ZIP archive of all export files', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    $pp06 = createPp06ForExport($workflow);
    seedPp06ExportData($pp06, $team);

    $service = new PpCompileService;
    $service->generateExportFiles($pp06, $user->id, $workspace->id);

    activateExportSession($this, $user, $role, $workspace);

    $response = $this->get("/admin/workflows/pp/{$workflow->id}/pp06/export/zip");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/zip');

    // Cleanup
    File::where('attachable_id', $pp06->id)->get()->each(function ($file) {
        \Illuminate\Support\Facades\Storage::disk('local')->delete($file->path);
    });
});

it('returns error when PP06 does not exist', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);

    activateExportSession($this, $user, $role, $workspace);

    $response = $this->get("/admin/workflows/pp/{$workflow->id}/pp06/export/pdf");

    $response->assertRedirect();
});
