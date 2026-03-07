<?php

use App\Models\Permission;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\PpWorkflow;
use App\Models\Team;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\WorkflowEngine;
use App\Workflows\PpWorkflowDefinition;

beforeEach(function () {
    $this->withoutVite();
});

function setupPpUser(string ...$permissionNames): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    foreach ($permissionNames as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace];
}

function activatePpSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

// --- Index ---

it('displays PP index page', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.index');
    activatePpSession($this, $user, $role, $workspace);

    $this->get(route('admin.workflows.pp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/index')
            ->has('workflows.data', 0)
        );
});

// --- Create ---

it('creates a PP workflow and redirects to PP01', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.create', 'admin.workflows.pp.pp01.show');
    activatePpSession($this, $user, $role, $workspace);

    $response = $this->post(route('admin.workflows.pp.create'));

    $workflow = PpWorkflow::first();
    expect($workflow)->not->toBeNull();

    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();
    expect($pp01)->not->toBeNull();

    $response->assertRedirect(route('admin.workflows.pp.pp01.show', [
        'ppWorkflow' => $workflow->id,
        'pp01Data' => $pp01->id,
    ]));

    $history = $workflow->fresh()->history;
    expect($history)->toHaveCount(1)
        ->and($history[0]['action'])->toBe('created')
        ->and($history[0]['step'])->toBe('PP01');
});

// --- PP01 Draft & Submit ---

it('drafts PP01 data', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp01.show', 'admin.workflows.pp.pp01.draft');
    activatePpSession($this, $user, $role, $workspace);

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id]);

    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $workflow->update(['history' => $history]);

    $this->post(route('admin.workflows.pp.pp01.draft', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2027-01-15',
        'tanggal_penetapan_program' => '2027-03-01',
        'kode_bidang_pelayanan' => [['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '01A', 'nama' => 'Kegiatan Umum', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K1', 'nama' => 'Pelayanan Utama', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'R', 'nama' => 'Rutin', 'catatan' => null]],
        'expected_updated_at' => $pp01->updated_at->toIso8601String(),
    ])->assertRedirect();

    $pp01->refresh();
    expect($pp01->tahun)->toBe(2027);

    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted');
});

it('submits PP01 and auto-creates PP02', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp01.submit');
    activatePpSession($this, $user, $role, $workspace);

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id]);

    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $workflow->update(['history' => $history]);

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2027-01-15',
        'tanggal_penetapan_program' => '2027-03-01',
        'kode_bidang_pelayanan' => [['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '01A', 'nama' => 'Kegiatan Umum', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K1', 'nama' => 'Pelayanan Utama', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'R', 'nama' => 'Rutin', 'catatan' => null]],
        'expected_updated_at' => $pp01->updated_at->toIso8601String(),
    ])->assertRedirect(route('admin.workflows.pp.show', $workflow));

    $workflow->refresh();
    expect($workflow->history)->toHaveCount(3);

    $engine = new WorkflowEngine;
    $definition = new PpWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $workflow->history);

    expect($statuses['PP01']['status'])->toBe('completed')
        ->and($statuses['PP02']['status'])->toBe('active');

    $pp02 = $workflow->pp02Data()->first();
    expect($pp02)->not->toBeNull();
});

// --- Full Flow ---

it('completes full PP flow through approval and compile', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.show',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.show',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.show',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.show',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp06.show',
    );
    activatePpSession($this, $user, $role, $workspace);

    $team = Team::factory()->for($role->team->organization)->create(['name' => 'Divisi Pendidikan']);

    // Create
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    // PP01 Submit
    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2027-01-15',
        'tanggal_penetapan_program' => '2027-03-01',
        'kode_bidang_pelayanan' => [['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '01A', 'nama' => 'Kegiatan Umum', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K1', 'nama' => 'Pelayanan Utama', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'R', 'nama' => 'Rutin', 'catatan' => null]],
        'expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest()->first();

    // PP02 Submit
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [
            ['kode' => 'Q1', 'pertanyaan' => 'Berapa jumlah anggota?', 'tipe' => 'Kuantitatif', 'satuan' => 'orang'],
        ],
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest()->first();

    // PP03 Submit
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id,
            'kode_team' => 'KA',
            'plafon_anggaran' => 73000000,
            'nama_bank' => 'BCA',
            'nama_rekening' => 'Divisi Pendidikan Demo',
            'nomor_rekening' => '1234567890',
            'catatan' => null,
        ]],
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp04 = $workflow->pp04Data()->latest()->first();

    // PP04 Submit
    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ]);

    // PP05 Approve
    $workflow->refresh();
    $this->post(route('admin.workflows.pp.pp05.approve', ['ppWorkflow' => $workflow]), [
        'notes' => 'Semua lengkap',
    ]);

    $workflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PpWorkflowDefinition;

    expect($engine->getWorkflowStatus($workflow->history))->toBe('completed');

    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP05']['status'])->toBe('completed')
        ->and($statuses['PP06']['status'])->toBe('completed')
        ->and($statuses['PP07']['status'])->toBe('active');

    $pp06 = $workflow->latestPp06();
    expect($pp06)->not->toBeNull()
        ->and($pp06->tahun)->toBe(2027)
        ->and($pp06->revision)->toBe(0)
        ->and($pp06->itemPlafonAnggaran)->toHaveCount(1)
        ->and($pp06->kodeBidangPelayanan)->toHaveCount(1)
        ->and($pp06->itemKuisioner)->toHaveCount(1);
});

// --- Rejection Cycle ---

it('handles PP05 rejection and re-entry', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
        'admin.workflows.pp.pp05.reject',
    );
    activatePpSession($this, $user, $role, $workspace);

    $team = Team::factory()->for($role->team->organization)->create();

    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2027-01-15',
        'tanggal_penetapan_program' => '2027-03-01',
        'kode_bidang_pelayanan' => [['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '01A', 'nama' => 'Sub', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K1', 'nama' => 'Kat', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'R', 'nama' => 'Rutin', 'catatan' => null]],
        'expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest()->first();
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [['kode' => 'Q1', 'pertanyaan' => 'Test?', 'tipe' => 'Kualitatif', 'satuan' => null]],
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest()->first();
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id, 'kode_team' => 'T1', 'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA', 'nama_rekening' => 'Test', 'nomor_rekening' => '123', 'catatan' => null,
        ]],
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp04 = $workflow->pp04Data()->latest()->first();
    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ]);

    // PP05 Reject
    $workflow->refresh();
    $this->post(route('admin.workflows.pp.pp05.reject', ['ppWorkflow' => $workflow]), [
        'notes' => 'Anggaran belum lengkap',
    ]);

    $workflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PpWorkflowDefinition;

    $statuses = $engine->getStepStatuses($definition, $workflow->history);

    expect($statuses['PP01']['status'])->toBe('active')
        ->and($statuses['PP01']['cycle'])->toBe(2)
        ->and($statuses['PP02']['status'])->toBe('pending')
        ->and($statuses['PP05']['status'])->toBe('pending');

    $newPp01 = $workflow->pp01Data()->latest('id')->first();
    expect($newPp01->id)->not->toBe($pp01->id)
        ->and($newPp01->tahun)->toBe(2027)
        ->and($newPp01->kodeBidangPelayanan)->toHaveCount(1);
});
