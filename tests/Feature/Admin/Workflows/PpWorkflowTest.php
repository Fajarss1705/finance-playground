<?php

use App\Models\File;
use App\Models\Permission;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp02Data;
use App\Models\Pp\Pp03Data;
use App\Models\Pp\Pp04Data;
use App\Models\Pp\Pp07Data;
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

// --- PP01 Validation ---

it('rejects PP01 submit with missing required fields', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp01.submit');
    activatePpSession($this, $user, $role, $workspace);

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id]);

    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $workflow->update(['history' => $history]);

    // Submit with no data
    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
        'expected_updated_at' => $pp01->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['tahun', 'tanggal_mulai_pra_raker', 'tanggal_penetapan_program', 'kode_bidang_pelayanan']);

    // History should NOT have a submit entry
    $workflow->refresh();
    expect($workflow->history)->toHaveCount(1);
});

it('rejects PP01 submit when tanggal_penetapan is before tanggal_mulai', function () {
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
        'tanggal_mulai_pra_raker' => '2027-06-01',
        'tanggal_penetapan_program' => '2027-03-01', // before mulai
        'kode_bidang_pelayanan' => [['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '01A', 'nama' => 'Sub', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K1', 'nama' => 'Kat', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'R', 'nama' => 'Rutin', 'catatan' => null]],
        'expected_updated_at' => $pp01->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['tanggal_penetapan_program']);
});

it('rejects PP01 submit with duplicate tahun in same workspace', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.create', 'admin.workflows.pp.pp01.show', 'admin.workflows.pp.pp01.submit');
    activatePpSession($this, $user, $role, $workspace);

    // Create first PP with tahun 2027
    $this->post(route('admin.workflows.pp.create'));
    $workflow1 = PpWorkflow::first();
    $pp01First = Pp01Data::where('pp_workflow_id', $workflow1->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow1, 'pp01Data' => $pp01First]), [
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => '2027-01-15',
        'tanggal_penetapan_program' => '2027-03-01',
        'kode_bidang_pelayanan' => [['kode' => '01', 'nama' => 'Kegiatan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '01A', 'nama' => 'Sub', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K1', 'nama' => 'Kat', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'R', 'nama' => 'Rutin', 'catatan' => null]],
        'expected_updated_at' => $pp01First->fresh()->updated_at->toIso8601String(),
    ])->assertRedirect();

    // Create second PP and try to submit with same tahun
    $this->post(route('admin.workflows.pp.create'));
    $workflow2 = PpWorkflow::latest('id')->first();
    $pp01Second = Pp01Data::where('pp_workflow_id', $workflow2->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow2, 'pp01Data' => $pp01Second]), [
        'tahun' => 2027, // duplicate
        'tanggal_mulai_pra_raker' => '2027-02-01',
        'tanggal_penetapan_program' => '2027-04-01',
        'kode_bidang_pelayanan' => [['kode' => '02', 'nama' => 'Pendidikan', 'catatan' => null]],
        'kode_sub_bidang_pelayanan' => [['kode' => '02A', 'nama' => 'Sub', 'catatan' => null]],
        'kode_kategori_pelayanan' => [['kode' => 'K2', 'nama' => 'Kat', 'catatan' => null]],
        'kode_jenis_program' => [['kode' => 'S', 'nama' => 'Spesial', 'catatan' => null]],
        'expected_updated_at' => $pp01Second->fresh()->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['tahun']);
});

it('returns 409 when expected_updated_at is stale on PP01 draft', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp01.show', 'admin.workflows.pp.pp01.draft');
    activatePpSession($this, $user, $role, $workspace);

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id]);

    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $workflow->update(['history' => $history]);

    $staleTimestamp = $pp01->updated_at->toIso8601String();

    // Simulate another user updating the record after some time
    $this->travel(2)->seconds();
    $pp01->update(['tahun' => 2028]);

    $this->post(route('admin.workflows.pp.pp01.draft', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
        'tahun' => 2027,
        'expected_updated_at' => $staleTimestamp,
    ])->assertStatus(409);
});

it('renders PP01 in readonly mode for user without draft/submit permission', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp01.show');
    activatePpSession($this, $user, $role, $workspace);

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id]);

    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $workflow->update(['history' => $history]);

    $this->get(route('admin.workflows.pp.pp01.show', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp01')
            ->where('canDraft', false)
            ->where('canSubmit', false)
            ->where('canTerminate', false)
        );
});

it('drafts PP01 with notes and records them in history', function () {
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
        'expected_updated_at' => $pp01->updated_at->toIso8601String(),
        'notes' => 'Baru isi tahun dulu',
    ])->assertRedirect();

    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['notes'])->toBe('Baru isi tahun dulu');
});

// --- PP02 Validation ---

function setupPp02Workflow($user, $role, $workspace): array
{
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
        ['step' => 'PP01', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
        ['step' => 'PP02', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp02_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id, 'tahun' => 2027]);
    $pp02 = Pp02Data::create(['pp_workflow_id' => $workflow->id]);

    // Fix history IDs
    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $history[1]['id'] = $pp01->id;
    $history[2]['id'] = $pp02->id;
    $workflow->update(['history' => $history]);

    return [$workflow, $pp02];
}

it('rejects PP02 submit with empty items', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp02.show', 'admin.workflows.pp.pp02.submit');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp02] = setupPp02Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['item_kuisioner']);

    // History should NOT have a submit entry
    $workflow->refresh();
    expect(collect($workflow->history)->where('action', 'submitted')->where('step', 'PP02'))->toBeEmpty();
});

it('rejects PP02 submit when Kuantitatif has no satuan', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp02.show', 'admin.workflows.pp.pp02.submit');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp02] = setupPp02Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [
            ['kode' => 'Q1', 'pertanyaan' => 'Jumlah anggota?', 'tipe' => 'Kuantitatif', 'satuan' => null],
        ],
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['item_kuisioner.0.satuan']);
});

it('renders PP02 in readonly mode for user without draft/submit permission', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp02.show');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp02] = setupPp02Workflow($user, $role, $workspace);

    $this->get(route('admin.workflows.pp.pp02.show', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp02')
            ->where('canDraft', false)
            ->where('canSubmit', false)
            ->where('canTerminate', false)
            ->where('canComment', false)
        );
});

it('returns 409 when expected_updated_at is stale on PP02 draft', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp02.show', 'admin.workflows.pp.pp02.draft');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp02] = setupPp02Workflow($user, $role, $workspace);

    $staleTimestamp = $pp02->updated_at->toIso8601String();

    // Simulate another user updating the record
    $this->travel(2)->seconds();
    $pp02->touch();

    $this->post(route('admin.workflows.pp.pp02.draft', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [
            ['kode' => 'Q1', 'pertanyaan' => 'Test?', 'tipe' => 'Kualitatif', 'satuan' => null],
        ],
        'expected_updated_at' => $staleTimestamp,
    ])->assertStatus(409);
});

it('drafts PP02 with notes and records them in history', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp02.show', 'admin.workflows.pp.pp02.draft');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp02] = setupPp02Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp02.draft', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [
            ['kode' => 'Q1', 'pertanyaan' => 'Berapa jumlah anggota?', 'tipe' => 'Kuantitatif', 'satuan' => 'orang'],
        ],
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
        'notes' => 'Baru isi satu pertanyaan',
    ])->assertRedirect();

    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PP02')
        ->and($lastEntry['notes'])->toBe('Baru isi satu pertanyaan');

    // Verify item was saved
    $pp02->refresh();
    expect($pp02->itemKuisioner)->toHaveCount(1);
});

// --- PP03 Validation ---

function setupPp03Workflow($user, $role, $workspace): array
{
    $team = Team::factory()->for($role->team->organization)->create(['name' => 'Divisi Pendidikan']);

    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
        ['step' => 'PP01', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
        ['step' => 'PP02', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp02_data', 'id' => 1],
        ['step' => 'PP02', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp02_data', 'id' => 1],
        ['step' => 'PP03', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp03_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id, 'tahun' => 2027]);
    $pp02 = Pp02Data::create(['pp_workflow_id' => $workflow->id]);
    $pp03 = Pp03Data::create(['pp_workflow_id' => $workflow->id]);

    // Fix history IDs
    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $history[1]['id'] = $pp01->id;
    $history[2]['id'] = $pp02->id;
    $history[3]['id'] = $pp02->id;
    $history[4]['id'] = $pp03->id;
    $workflow->update(['history' => $history]);

    return [$workflow, $pp03, $team];
}

it('rejects PP03 submit with empty items', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp03.show', 'admin.workflows.pp.pp03.submit');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp03] = setupPp03Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['item_plafon_anggaran']);

    // History should NOT have a submit entry
    $workflow->refresh();
    expect(collect($workflow->history)->where('action', 'submitted')->where('step', 'PP03'))->toBeEmpty();
});

it('rejects PP03 submit with negative plafon_anggaran', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp03.show', 'admin.workflows.pp.pp03.submit');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp03, $team] = setupPp03Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id, 'kode_team' => 'KA', 'plafon_anggaran' => -100000,
            'nama_bank' => 'BCA', 'nama_rekening' => 'Test', 'nomor_rekening' => '123', 'catatan' => null,
        ]],
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['item_plafon_anggaran.0.plafon_anggaran']);
});

it('renders PP03 in readonly mode for user without draft/submit permission', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp03.show');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp03] = setupPp03Workflow($user, $role, $workspace);

    $this->get(route('admin.workflows.pp.pp03.show', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp03')
            ->where('canDraft', false)
            ->where('canSubmit', false)
            ->where('canTerminate', false)
            ->where('canComment', false)
        );
});

it('returns 409 when expected_updated_at is stale on PP03 draft', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp03.show', 'admin.workflows.pp.pp03.draft');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp03, $team] = setupPp03Workflow($user, $role, $workspace);

    $staleTimestamp = $pp03->updated_at->toIso8601String();

    // Simulate another user updating the record
    $this->travel(2)->seconds();
    $pp03->touch();

    $this->post(route('admin.workflows.pp.pp03.draft', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id, 'kode_team' => 'KA', 'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA', 'nama_rekening' => 'Test', 'nomor_rekening' => '123', 'catatan' => null,
        ]],
        'expected_updated_at' => $staleTimestamp,
    ])->assertStatus(409);
});

it('drafts PP03 with notes and records them in history', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp03.show', 'admin.workflows.pp.pp03.draft');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp03, $team] = setupPp03Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp03.draft', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id, 'kode_team' => 'KA', 'plafon_anggaran' => 73000000,
            'nama_bank' => 'BCA', 'nama_rekening' => 'Divisi Pendidikan Demo', 'nomor_rekening' => '1234567890', 'catatan' => null,
        ]],
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
        'notes' => 'Baru isi satu tim',
    ])->assertRedirect();

    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PP03')
        ->and($lastEntry['notes'])->toBe('Baru isi satu tim');

    // Verify item was saved
    $pp03->refresh();
    expect($pp03->itemPlafonAnggaran)->toHaveCount(1);
});

// --- PP04 Validation ---

function setupPp04Workflow($user, $role, $workspace): array
{
    $workflow = PpWorkflow::create(['workspace_id' => $workspace->id, 'history' => [
        ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
        ['step' => 'PP01', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp01_data', 'id' => 1],
        ['step' => 'PP02', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp02_data', 'id' => 1],
        ['step' => 'PP02', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp02_data', 'id' => 1],
        ['step' => 'PP03', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp03_data', 'id' => 1],
        ['step' => 'PP03', 'action' => 'submitted', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp03_data', 'id' => 1],
        ['step' => 'PP04', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pp04_data', 'id' => 1],
    ]]);
    $pp01 = Pp01Data::create(['pp_workflow_id' => $workflow->id, 'tahun' => 2027]);
    $pp02 = Pp02Data::create(['pp_workflow_id' => $workflow->id]);
    $pp03 = Pp03Data::create(['pp_workflow_id' => $workflow->id]);
    $pp04 = Pp04Data::create(['pp_workflow_id' => $workflow->id]);

    // Fix history IDs
    $history = $workflow->history;
    $history[0]['id'] = $pp01->id;
    $history[1]['id'] = $pp01->id;
    $history[2]['id'] = $pp02->id;
    $history[3]['id'] = $pp02->id;
    $history[4]['id'] = $pp03->id;
    $history[5]['id'] = $pp03->id;
    $history[6]['id'] = $pp04->id;
    $workflow->update(['history' => $history]);

    return [$workflow, $pp04];
}

it('renders PP04 in readonly mode for user without draft/submit permission', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp04.show');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp04] = setupPp04Workflow($user, $role, $workspace);

    $this->get(route('admin.workflows.pp.pp04.show', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp04')
            ->where('canDraft', false)
            ->where('canSubmit', false)
            ->where('canTerminate', false)
            ->where('canComment', false)
        );
});

it('returns 409 when expected_updated_at is stale on PP04 draft', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.draft');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp04] = setupPp04Workflow($user, $role, $workspace);

    $staleTimestamp = $pp04->updated_at->toIso8601String();

    $this->travel(2)->seconds();
    $pp04->touch();

    $this->post(route('admin.workflows.pp.pp04.draft', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $staleTimestamp,
    ])->assertStatus(409);
});

it('submits PP04 with notes and records them in history', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.submit');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp04] = setupPp04Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
        'notes' => 'Tidak ada dokumen SOP',
    ])->assertRedirect();

    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('submitted')
        ->and($lastEntry['step'])->toBe('PP04')
        ->and($lastEntry['notes'])->toBe('Tidak ada dokumen SOP');
});

it('submits PP04 with 0 files successfully', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.submit');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp04] = setupPp04Workflow($user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ])->assertRedirect();

    expect($pp04->fresh()->itemDokumen)->toHaveCount(0);
});

it('sets is_workspace_public on attached files when PP04 is submitted', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.submit');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp04] = setupPp04Workflow($user, $role, $workspace);

    $file = File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'is_workspace_public' => false,
    ]);

    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [$file->id],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ])->assertRedirect();

    expect($file->fresh()->is_workspace_public)->toBeTrue();
    expect($pp04->fresh()->itemDokumen)->toHaveCount(1);
});

it('drafts PP04 with file attachments and records history', function () {
    [$user, $role, $workspace] = setupPpUser('admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.draft');
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $pp04] = setupPp04Workflow($user, $role, $workspace);

    $file = File::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'is_workspace_public' => false,
    ]);

    $this->post(route('admin.workflows.pp.pp04.draft', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [$file->id],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
        'notes' => 'Draft awal',
    ])->assertRedirect();

    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PP04')
        ->and($lastEntry['notes'])->toBe('Draft awal');

    // File attached but NOT marked public (only on submit)
    expect($file->fresh()->is_workspace_public)->toBeFalse();
    expect($pp04->fresh()->itemDokumen)->toHaveCount(1);
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

// --- PP05 Approval Step ---

function runFlowToPp05(object $test, $user, $role, $workspace): PpWorkflow
{
    $team = Team::factory()->for($role->team->organization)->create();

    $test->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $test->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
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
    $test->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [['kode' => 'Q1', 'pertanyaan' => 'Test?', 'tipe' => 'Kualitatif', 'satuan' => null]],
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest()->first();
    $test->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id, 'kode_team' => 'T1', 'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA', 'nama_rekening' => 'Test', 'nomor_rekening' => '123', 'catatan' => null,
        ]],
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp04 = $workflow->pp04Data()->latest()->first();
    $test->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ]);

    return $workflow->fresh();
}

it('shows PP05 as readonly when user lacks approve/reject permissions', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
    );
    activatePpSession($this, $user, $role, $workspace);

    $workflow = runFlowToPp05($this, $user, $role, $workspace);

    $this->get(route('admin.workflows.pp.pp05.show', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp05')
            ->where('canApprove', false)
            ->where('canReject', false)
            ->where('canTerminate', false)
            ->where('canComment', false)
        );
});

it('shows PP05 with approve/reject permissions for authorized user', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp05.reject',
        'admin.workflows.pp.terminate',
        'admin.workflows.pp.comment',
    );
    activatePpSession($this, $user, $role, $workspace);

    $workflow = runFlowToPp05($this, $user, $role, $workspace);

    $this->get(route('admin.workflows.pp.pp05.show', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp05')
            ->where('canApprove', true)
            ->where('canReject', true)
            ->where('canTerminate', true)
            ->where('canComment', true)
            ->has('reviewData.pp01')
            ->has('reviewData.pp02')
            ->has('reviewData.pp03')
            ->has('reviewData.pp04')
        );
});

it('records PP05 approve with notes in history', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
        'admin.workflows.pp.pp05.approve',
    );
    activatePpSession($this, $user, $role, $workspace);

    $workflow = runFlowToPp05($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp05.approve', $workflow), [
        'notes' => 'Semua data lengkap',
    ])->assertRedirect(route('admin.workflows.pp.show', $workflow));

    $workflow->refresh();
    $approveEntry = collect($workflow->history)
        ->where('step', 'PP05')
        ->where('action', 'approved')
        ->first();

    expect($approveEntry)->not->toBeNull()
        ->and($approveEntry['notes'])->toBe('Semua data lengkap')
        ->and($approveEntry['reviewed'])->toHaveKeys(['pp01_data_id', 'pp02_data_id', 'pp03_data_id', 'pp04_data_id']);
});

it('rejects PP05 reject without notes', function () {
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

    $workflow = runFlowToPp05($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp05.reject', $workflow), [])
        ->assertSessionHasErrors('notes');

    // History should NOT have a rejected entry
    $workflow->refresh();
    $rejectEntry = collect($workflow->history)
        ->where('step', 'PP05')
        ->where('action', 'rejected')
        ->first();

    expect($rejectEntry)->toBeNull();
});

it('prevents PP05 approve when step is no longer active', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
        'admin.workflows.pp.pp05.approve',
    );
    activatePpSession($this, $user, $role, $workspace);

    $workflow = runFlowToPp05($this, $user, $role, $workspace);

    // Approve first time
    $this->post(route('admin.workflows.pp.pp05.approve', $workflow), [
        'notes' => 'Approved',
    ])->assertRedirect();

    // Try approve again — step no longer active
    $this->post(route('admin.workflows.pp.pp05.approve', $workflow), [
        'notes' => 'Double approve',
    ])->assertSessionHasErrors('approve');
});

// --- PP07 Revision ---

function runFullPpFlowToCompletion(object $test, $user, $role, $workspace): array
{
    $team = Team::factory()->for($role->team->organization)->create(['name' => 'Divisi Test']);

    $test->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $test->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]), [
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
    $test->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]), [
        'item_kuisioner' => [['kode' => 'Q1', 'pertanyaan' => 'Jumlah?', 'tipe' => 'Kuantitatif', 'satuan' => 'orang']],
        'expected_updated_at' => $pp02->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest()->first();
    $test->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]), [
        'item_plafon_anggaran' => [[
            'team_id' => $team->id, 'kode_team' => 'KT', 'plafon_anggaran' => 50000000,
            'nama_bank' => 'BCA', 'nama_rekening' => 'Test', 'nomor_rekening' => '123', 'catatan' => null,
        ]],
        'expected_updated_at' => $pp03->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $pp04 = $workflow->pp04Data()->latest()->first();
    $test->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $test->post(route('admin.workflows.pp.pp05.approve', ['ppWorkflow' => $workflow]), [
        'notes' => 'Approved',
    ]);

    return [$workflow->fresh(), $team];
}

// --- PP06 Show ---

it('shows PP06 with compiled data and canComment false when lacking comment permission', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp06.show',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->get(route('admin.workflows.pp.pp06.show', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp06')
            ->has('pp06')
            ->where('pp06.tahun', 2027)
            ->where('pp06.revision', 0)
            ->has('pp06.kode_bidang_pelayanan', 1)
            ->has('pp06.item_kuisioner', 1)
            ->has('pp06.item_plafon_anggaran', 1)
            ->has('allRevisions', 1)
            ->where('canRevise', true)
            ->where('canComment', false)
        );
});

it('shows PP06 with canComment true when user has comment permission', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.comment',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->get(route('admin.workflows.pp.pp06.show', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp06')
            ->has('pp06')
            ->where('canComment', true)
        );
});

it('shows PP06 with empty state when not yet compiled', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp06.show',
    );
    activatePpSession($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();

    $this->get(route('admin.workflows.pp.pp06.show', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp06')
            ->where('pp06', null)
            ->has('allRevisions', 0)
            ->where('canRevise', false)
            ->where('canComment', false)
        );
});

// --- PP07 ---

it('creates PP07 draft from PP06 data', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    // Create PP07 revision
    $response = $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));

    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();
    expect($pp07)->not->toBeNull()
        ->and($pp07->submitted_at)->toBeNull();

    $response->assertRedirect(route('admin.workflows.pp.pp07.show', [
        'ppWorkflow' => $workflow->id,
        'pp07Data' => $pp07->id,
    ]));

    // Draft data should be prefilled from PP06
    $draftData = $pp07->draft_data;
    expect($draftData['tahun'])->toBe(2027)
        ->and($draftData['kode_bidang_pelayanan'])->toHaveCount(1)
        ->and($draftData['item_kuisioner'])->toHaveCount(1)
        ->and($draftData['item_plafon_anggaran'])->toHaveCount(1);
});

it('redirects to existing PP07 draft if one exists', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    // Create first draft
    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    // Try create again — should redirect to existing
    $response = $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $response->assertRedirect(route('admin.workflows.pp.pp07.show', [
        'ppWorkflow' => $workflow->id,
        'pp07Data' => $pp07->id,
    ]));

    // Still only one PP07
    expect(Pp07Data::where('pp_workflow_id', $workflow->id)->count())->toBe(1);
});

it('saves PP07 draft data', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.draft',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    // Modify draft data
    $modifiedDraft = $pp07->draft_data;
    $modifiedDraft['tahun'] = 2028;

    $this->post(route('admin.workflows.pp.pp07.draft', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => $modifiedDraft,
        'expected_updated_at' => $pp07->updated_at->toIso8601String(),
    ])->assertRedirect();

    $pp07->refresh();
    expect($pp07->draft_data['tahun'])->toBe(2028)
        ->and($pp07->submitted_at)->toBeNull();

    // History should have drafted entry
    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PP07');
});

it('submits PP07 and creates new PP06 revision', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.submit',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $team] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    // Verify PP06 revision 0 exists
    $pp06Rev0 = $workflow->latestPp06();
    expect($pp06Rev0->revision)->toBe(0);

    // Create PP07
    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    // Modify the draft (change plafon)
    $draftData = $pp07->draft_data;
    $draftData['item_plafon_anggaran'][0]['plafon_anggaran'] = 90000000;

    // Submit PP07
    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => $draftData,
        'expected_updated_at' => $pp07->fresh()->updated_at->toIso8601String(),
    ])->assertRedirect(route('admin.workflows.pp.pp06.show', $workflow));

    // PP07 should be marked as submitted
    $pp07->refresh();
    expect($pp07->submitted_at)->not->toBeNull();

    // New PP06 revision should exist
    $workflow->refresh();
    $pp06Rev1 = $workflow->latestPp06();
    expect($pp06Rev1->revision)->toBe(1)
        ->and($pp06Rev1->tahun)->toBe(2027)
        ->and($pp06Rev1->itemPlafonAnggaran)->toHaveCount(1)
        ->and((float) $pp06Rev1->itemPlafonAnggaran->first()->plafon_anggaran)->toBe(90000000.0)
        ->and($pp06Rev1->kodeBidangPelayanan)->toHaveCount(1)
        ->and($pp06Rev1->itemKuisioner)->toHaveCount(1);

    // Author overrides: PP01-PP04 should be PP07 submitter, PP05 from previous revision
    expect($pp06Rev1->pp01_created_by_user_name)->toBe($user->name)
        ->and($pp06Rev1->pp05_created_by_user_name)->toBe($pp06Rev0->pp05_created_by_user_name);

    // History should have PP07 submitted + PP06 completed entries
    $historyActions = collect($workflow->history)->pluck('action')->toArray();
    expect($historyActions)->toContain('submitted')
        ->and(collect($workflow->history)->where('step', 'PP07')->where('action', 'submitted')->first())->not->toBeNull()
        ->and(collect($workflow->history)->where('step', 'PP06')->where('action', 'completed')->last()['revision'] ?? null)->toBe(1);

    // Total revisions should be 2
    expect($workflow->pp06PeriodeTahunan()->count())->toBe(2);
});

it('prevents PP07 submit on already submitted draft', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.submit',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow, $team] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    // Submit first time
    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => $pp07->draft_data,
        'expected_updated_at' => $pp07->fresh()->updated_at->toIso8601String(),
    ])->assertRedirect();

    // Try submit again
    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => $pp07->draft_data,
        'expected_updated_at' => $pp07->fresh()->updated_at->toIso8601String(),
    ])->assertStatus(409);
});

it('shows PP07 with canComment false when lacking comment permission', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    $this->get(route('admin.workflows.pp.pp07.show', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp07')
            ->where('canComment', false)
            ->where('mode', 'edit')
        );
});

it('shows PP07 with canComment true when user has comment permission', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.comment',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    $this->get(route('admin.workflows.pp.pp07.show', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pp/pp07')
            ->where('canComment', true)
        );
});

it('records notes in PP07 draft history', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.draft',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp07.draft', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => $pp07->draft_data,
        'expected_updated_at' => $pp07->updated_at->toIso8601String(),
        'notes' => 'Revisi plafon Divisi Pendidikan',
    ])->assertRedirect();

    $workflow->refresh();
    $lastEntry = collect($workflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PP07')
        ->and($lastEntry['notes'])->toBe('Revisi plafon Divisi Pendidikan');
});

it('records notes in PP07 submit history', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.submit',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => $pp07->draft_data,
        'expected_updated_at' => $pp07->fresh()->updated_at->toIso8601String(),
        'notes' => 'Revisi plafon dan tambah kuisioner',
    ])->assertRedirect();

    $workflow->refresh();
    $pp07Entry = collect($workflow->history)->where('step', 'PP07')->where('action', 'submitted')->first();
    expect($pp07Entry)->not->toBeNull()
        ->and($pp07Entry['notes'])->toBe('Revisi plafon dan tambah kuisioner');
});

it('validates PP07 submit rejects empty draft data', function () {
    [$user, $role, $workspace] = setupPpUser(
        'admin.workflows.pp.create',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.submit',
    );
    activatePpSession($this, $user, $role, $workspace);

    [$workflow] = runFullPpFlowToCompletion($this, $user, $role, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07 = Pp07Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07]), [
        'draft_data' => [
            'tahun' => null,
            'tanggal_mulai_pra_raker' => null,
            'tanggal_penetapan_program' => null,
            'kode_bidang_pelayanan' => [],
            'kode_sub_bidang_pelayanan' => [],
            'kode_kategori_pelayanan' => [],
            'kode_jenis_program' => [],
            'item_kuisioner' => [],
            'item_plafon_anggaran' => [],
        ],
        'expected_updated_at' => $pp07->fresh()->updated_at->toIso8601String(),
    ])->assertSessionHasErrors([
        'draft_data.tahun',
        'draft_data.tanggal_mulai_pra_raker',
        'draft_data.tanggal_penetapan_program',
        'draft_data.kode_bidang_pelayanan',
        'draft_data.kode_sub_bidang_pelayanan',
        'draft_data.kode_kategori_pelayanan',
        'draft_data.kode_jenis_program',
        'draft_data.item_kuisioner',
        'draft_data.item_plafon_anggaran',
    ]);

    // PP07 should remain un-submitted
    $pp07->refresh();
    expect($pp07->submitted_at)->toBeNull();
});
