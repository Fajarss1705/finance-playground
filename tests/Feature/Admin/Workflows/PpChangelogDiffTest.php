<?php

/**
 * Tests for PpCompileService::computeDiff() — changelog diff between PP06 revisions.
 */

use App\Models\Pp\Pp06ItemDokumenSop;
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

function createPp06WithData(PpWorkflow $workflow, array $overrides = []): Pp06PeriodeTahunan
{
    $defaults = [
        'pp_workflow_id' => $workflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'pp01_created_by_user_name' => 'Test User',
        'pp01_created_by_role_name' => 'Koordinator MONEV',
        'pp01_created_by_organization_name' => 'Finance Playground',
        'pp01_created_by_workspace_name' => 'Workspace MONEV',
        'pp01_created_at' => now(),
        'pp02_created_by_user_name' => 'Test User',
        'pp02_created_by_role_name' => 'Koordinator MONEV',
        'pp02_created_by_organization_name' => 'Finance Playground',
        'pp02_created_by_workspace_name' => 'Workspace MONEV',
        'pp02_created_at' => now(),
        'pp03_created_by_user_name' => 'Test User',
        'pp03_created_by_role_name' => 'BU',
        'pp03_created_by_organization_name' => 'Finance Playground',
        'pp03_created_by_workspace_name' => 'Workspace MONEV',
        'pp03_created_at' => now(),
        'pp04_created_by_user_name' => 'Test User',
        'pp04_created_by_role_name' => 'Koordinator MONEV',
        'pp04_created_by_organization_name' => 'Finance Playground',
        'pp04_created_by_workspace_name' => 'Workspace MONEV',
        'pp04_created_at' => now(),
        'pp05_created_by_user_name' => 'Test User',
        'pp05_created_by_role_name' => 'Koordinator MONEV',
        'pp05_created_by_organization_name' => 'Finance Playground',
        'pp05_created_by_workspace_name' => 'Workspace MONEV',
        'pp05_created_at' => now(),
    ];

    return Pp06PeriodeTahunan::create(array_merge($defaults, $overrides));
}

function baseDraftData(): array
{
    return [
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'kode_bidang_pelayanan' => [
            ['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null],
            ['kode' => '02', 'nama' => 'Pendidikan', 'catatan' => null],
        ],
        'kode_sub_bidang_pelayanan' => [
            ['kode' => '01A', 'nama' => 'Sub Kegiatan', 'catatan' => null],
        ],
        'kode_kategori_pelayanan' => [
            ['kode' => 'K01', 'nama' => 'Anak', 'catatan' => null],
        ],
        'kode_jenis_program' => [
            ['kode' => 'J01', 'nama' => 'Rutin', 'catatan' => null],
        ],
        'item_kuisioner' => [
            ['kode' => 'Q01', 'pertanyaan' => 'Jumlah anggota?', 'tipe' => 'Kuantitatif', 'satuan' => 'orang'],
            ['kode' => 'Q02', 'pertanyaan' => 'Apakah ada kendala?', 'tipe' => 'Kualitatif', 'satuan' => null],
        ],
        'item_plafon_anggaran' => [],
        'item_dokumen_sop' => [],
    ];
}

function seedPp06ChildData(Pp06PeriodeTahunan $pp06, array $draftData): void
{
    foreach ($draftData['kode_bidang_pelayanan'] ?? [] as $item) {
        Pp06KodeBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, ...$item]);
    }
    foreach ($draftData['kode_sub_bidang_pelayanan'] ?? [] as $item) {
        Pp06KodeSubBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, ...$item]);
    }
    foreach ($draftData['kode_kategori_pelayanan'] ?? [] as $item) {
        Pp06KodeKategoriPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, ...$item]);
    }
    foreach ($draftData['kode_jenis_program'] ?? [] as $item) {
        Pp06KodeJenisProgram::create(['pp06_periode_tahunan_id' => $pp06->id, ...$item]);
    }
    foreach ($draftData['item_kuisioner'] ?? [] as $item) {
        Pp06ItemKuisioner::create(['pp06_periode_tahunan_id' => $pp06->id, ...$item]);
    }
    foreach ($draftData['item_plafon_anggaran'] ?? [] as $item) {
        Pp06ItemPlafonAnggaran::create(['pp06_periode_tahunan_id' => $pp06->id, ...$item]);
    }
    foreach ($draftData['item_dokumen_sop'] ?? [] as $item) {
        Pp06ItemDokumenSop::create(['pp06_periode_tahunan_id' => $pp06->id, ...$item]);
    }
}

// === Tests ===

it('returns empty changes when draft data is identical to previous PP06', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $draftData);

    expect($changes)->toBeEmpty();
});

it('detects periode changes (tahun, dates)', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    // Change tahun and dates
    $newDraft = $draftData;
    $newDraft['tahun'] = 2028;
    $newDraft['tanggal_mulai_pra_raker'] = '2027-10-20';

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $newDraft);

    $types = array_column($changes, 'type');
    expect($types)->each->toBe('periode_changed')
        ->and($changes)->toHaveCount(2);

    $descriptions = array_column($changes, 'description');
    expect($descriptions[0])->toContain('2027', '2028')
        ->and($descriptions[1])->toContain('15/10/2026', '20/10/2027');
});

it('detects kode table additions and removals', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    // Remove '02' bidang, add '03' bidang
    $newDraft = $draftData;
    $newDraft['kode_bidang_pelayanan'] = [
        ['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null],
        ['kode' => '03', 'nama' => 'Pelayanan Sosial', 'catatan' => null],
    ];

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $newDraft);

    $added = collect($changes)->where('type', 'kode_added');
    $removed = collect($changes)->where('type', 'kode_removed');

    expect($added)->toHaveCount(1)
        ->and($added->first()['description'])->toContain('03', 'Pelayanan Sosial', 'ditambahkan')
        ->and($removed)->toHaveCount(1)
        ->and($removed->first()['description'])->toContain('02', 'Pendidikan', 'dihapus');
});

it('detects kode table field changes (nama, catatan)', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    // Change nama of kode '01'
    $newDraft = $draftData;
    $newDraft['kode_bidang_pelayanan'][0]['nama'] = 'Kegiatan Utama';
    $newDraft['kode_bidang_pelayanan'][0]['catatan'] = 'Catatan baru';

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $newDraft);

    $kodeChanges = collect($changes)->where('type', 'kode_changed');
    expect($kodeChanges)->toHaveCount(2);

    $descriptions = $kodeChanges->pluck('description')->all();
    expect($descriptions[0])->toContain('01', 'Kegiatan', 'Kegiatan Utama')
        ->and($descriptions[1])->toContain('01', 'catatan diubah');
});

it('detects kuisioner additions, removals, and changes', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    $newDraft = $draftData;
    // Remove Q02, change Q01 tipe, add Q03
    $newDraft['item_kuisioner'] = [
        ['kode' => 'Q01', 'pertanyaan' => 'Jumlah anggota?', 'tipe' => 'Kualitatif', 'satuan' => null],
        ['kode' => 'Q03', 'pertanyaan' => 'Jumlah kegiatan?', 'tipe' => 'Kuantitatif', 'satuan' => 'kegiatan'],
    ];

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $newDraft);

    $added = collect($changes)->where('type', 'kuisioner_added');
    $removed = collect($changes)->where('type', 'kuisioner_removed');
    $changed = collect($changes)->where('type', 'kuisioner_changed');

    expect($added)->toHaveCount(1)
        ->and($added->first()['description'])->toContain('Jumlah kegiatan?')
        ->and($removed)->toHaveCount(1)
        ->and($removed->first()['description'])->toContain('Apakah ada kendala?')
        ->and($changed)->toHaveCount(1)
        ->and($changed->first()['description'])->toContain('Kuantitatif', 'Kualitatif');
});

it('detects plafon amount changes and rekening changes', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $team = $user->roles->first()->team;

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();
    $draftData['item_plafon_anggaran'] = [
        [
            'team_id' => $team->id,
            'kode_team' => '10',
            'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA',
            'nama_rekening' => 'Rina W.',
            'nomor_rekening' => '1234567890',
            'catatan' => null,
        ],
    ];

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    // Change plafon + rekening
    $newDraft = $draftData;
    $newDraft['item_plafon_anggaran'][0]['plafon_anggaran'] = 75000000;
    $newDraft['item_plafon_anggaran'][0]['nomor_rekening'] = '9876543210';

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $newDraft);

    $plafonChanges = collect($changes)->where('type', 'plafon_changed');
    $rekeningChanges = collect($changes)->where('type', 'rekening_changed');

    expect($plafonChanges)->toHaveCount(1)
        ->and($plafonChanges->first()['description'])->toContain('Rp 50.000.000', 'Rp 75.000.000')
        ->and($rekeningChanges)->toHaveCount(1)
        ->and($rekeningChanges->first()['description'])->toContain('nomor rekening');
});

it('detects plafon team additions and removals', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();
    $team1 = $user->roles->first()->team;
    $team2 = Team::factory()->create(['organization_id' => $team1->organization_id]);

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();
    $draftData['item_plafon_anggaran'] = [
        [
            'team_id' => $team1->id,
            'kode_team' => '10',
            'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA',
            'nama_rekening' => 'Rina',
            'nomor_rekening' => '123',
            'catatan' => null,
        ],
    ];

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    // Remove team1, add team2
    $newDraft = $draftData;
    $newDraft['item_plafon_anggaran'] = [
        [
            'team_id' => $team2->id,
            'kode_team' => '20',
            'plafon_anggaran' => 30000000,
            'nama_bank' => 'Mandiri',
            'nama_rekening' => 'Budi',
            'nomor_rekening' => '456',
            'catatan' => null,
        ],
    ];

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $newDraft);

    $plafonChanges = collect($changes)->where('type', 'plafon_changed');
    expect($plafonChanges)->toHaveCount(2);

    $descriptions = $plafonChanges->pluck('description')->all();
    $addedDesc = collect($descriptions)->first(fn ($d) => str_contains($d, 'ditambahkan'));
    $removedDesc = collect($descriptions)->first(fn ($d) => str_contains($d, 'dihapus'));

    expect($addedDesc)->toContain($team2->name)
        ->and($removedDesc)->toContain($team1->name);
});

it('detects dokumen additions and removals', function () {
    $user = User::factory()->withRole()->create();
    $workspace = $user->roles->first()->team->organization->workspaces->first();

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => []]);
    $draftData = baseDraftData();

    // Create files
    $file1 = \App\Models\File::create([
        'uuid' => \Illuminate\Support\Str::uuid(),
        'original_filename' => 'sop-kegiatan.pdf',
        'filename' => 'sop-kegiatan.pdf',
        'path' => 'files/sop-kegiatan.pdf',
        'size' => 1024,
        'mime_type' => 'application/pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'source_route' => 'pp.pp04.dokumen',
        'attachable_type' => PpWorkflow::class,
        'attachable_id' => $workflow->id,
    ]);
    $file2 = \App\Models\File::create([
        'uuid' => \Illuminate\Support\Str::uuid(),
        'original_filename' => 'sop-pendidikan.pdf',
        'filename' => 'sop-pendidikan.pdf',
        'path' => 'files/sop-pendidikan.pdf',
        'size' => 2048,
        'mime_type' => 'application/pdf',
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'source_route' => 'pp.pp04.dokumen',
        'attachable_type' => PpWorkflow::class,
        'attachable_id' => $workflow->id,
    ]);

    $draftData['item_dokumen_sop'] = [['file_id' => $file1->id]];

    $pp06 = createPp06WithData($workflow);
    seedPp06ChildData($pp06, $draftData);
    $pp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop']);

    // Remove file1, add file2
    $newDraft = $draftData;
    $newDraft['item_dokumen_sop'] = [['file_id' => $file2->id]];

    $service = new PpCompileService;
    $changes = $service->computeDiff($pp06, $newDraft);

    $added = collect($changes)->where('type', 'dokumen_added');
    $removed = collect($changes)->where('type', 'dokumen_removed');

    expect($added)->toHaveCount(1)
        ->and($added->first()['description'])->toContain('sop-pendidikan.pdf')
        ->and($removed)->toHaveCount(1)
        ->and($removed->first()['description'])->toContain('sop-kegiatan.pdf');
});

it('stores changelog diff in PP07 submitted history entry during integration flow', function () {
    // Use the integration test helpers
    $user = User::factory()->withRole()->create(['name' => 'Monev User']);
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;

    // Give necessary permissions
    $permNames = [
        'admin.workflows.pp.index', 'admin.workflows.pp.create', 'admin.workflows.pp.show',
        'admin.workflows.pp.pp01.show', 'admin.workflows.pp.pp01.draft', 'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.show', 'admin.workflows.pp.pp02.draft', 'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.show', 'admin.workflows.pp.pp03.draft', 'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.draft', 'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show', 'admin.workflows.pp.pp05.approve', 'admin.workflows.pp.pp05.reject',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp07.create', 'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.draft', 'admin.workflows.pp.pp07.submit',
        'admin.workflows.pp.comment',
    ];

    foreach ($permNames as $permName) {
        $perm = \App\Models\Permission::firstOrCreate(['name' => $permName]);
        $role->permissions()->syncWithoutDetaching($perm->id);
    }

    // Activate session
    $this->actingAs($user);
    app(\App\Services\ActiveSessionService::class)->switchTo($role, $workspace);

    // Quick flow: create → PP01 submit → PP02 submit → PP03 submit → PP04 submit → PP05 approve
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = \App\Models\Pp\Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $pp01SubmitData = [
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2026-10-15',
        'tanggal_penetapan_program' => '2026-11-30',
        'kode_bidang_pelayanan' => [['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '01A', 'nama' => 'Sub', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K01', 'nama' => 'Kat', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'J01', 'nama' => 'Rutin', 'catatan' => null]],
        'expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String(),
    ];

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), $pp01SubmitData);

    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [
            ['kode' => 'Q01', 'pertanyaan' => 'Jumlah anggota?', 'tipe' => 'Kuantitatif', 'satuan' => 'orang'],
        ],
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id,
            'kode_team' => '10',
            'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA',
            'nama_rekening' => 'Rina',
            'nomor_rekening' => '123',
            'catatan' => null,
        ]],
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp04 = $workflow->pp04Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'keep_file_ids' => [],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $this->post(route('admin.workflows.pp.pp05.approve', ['ppWorkflow' => $workflow]), ['notes' => 'Approved']);

    // Now PP06 rev 0 exists — create PP07 revision with plafon change
    $workflow->refresh();
    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = \App\Models\Pp\Pp07Data::where('pp_workflow_id', $workflow->id)->latest('id')->first();

    $draftData = $pp07->draft_data;
    $draftData['item_plafon_anggaran'][0]['plafon_anggaran'] = 75000000;
    $draftData['item_kuisioner'][] = [
        'kode' => 'Q02',
        'pertanyaan' => 'Jumlah kegiatan baru?',
        'tipe' => 'Kuantitatif',
        'satuan' => 'kegiatan',
    ];

    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => $draftData,
        'expected_updated_at' => $pp07->fresh()->updated_at->toIso8601String(),
        'notes' => 'Revisi plafon + kuisioner',
    ])->assertRedirect();

    // Verify changes in PP07 submitted history entry
    $workflow->refresh();
    $pp07Entry = collect($workflow->history)
        ->where('step', 'PP07')
        ->where('action', 'submitted')
        ->first();

    expect($pp07Entry)->not->toBeNull()
        ->and($pp07Entry['changes'])->toBeArray()
        ->and($pp07Entry['changes'])->not->toBeEmpty();

    $changeDescriptions = array_column($pp07Entry['changes'], 'description');
    $changeTypes = array_column($pp07Entry['changes'], 'type');

    // Should have plafon_changed and kuisioner_added
    expect($changeTypes)->toContain('plafon_changed')
        ->and($changeTypes)->toContain('kuisioner_added');

    // Verify descriptions are human-readable
    $plafonDesc = collect($changeDescriptions)->first(fn ($d) => str_contains($d, 'Plafon'));
    $kuisionerDesc = collect($changeDescriptions)->first(fn ($d) => str_contains($d, 'Kuisioner'));

    expect($plafonDesc)->toContain('Rp 50.000.000', 'Rp 75.000.000')
        ->and($kuisionerDesc)->toContain('Jumlah kegiatan baru?');
});
