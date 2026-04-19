<?php

/**
 * PP Workflow Integration Tests (Phase D)
 *
 * These test multi-user end-to-end flows, full rejection re-entry cycles,
 * multiple PP07 revisions, and lifecycle terminate → destroy sequences.
 * Complements the unit-style tests in PpWorkflowTest.php.
 */

use App\Models\Permission;
use App\Models\Pp\Pp01Data;
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

// === Helpers ===

/**
 * Create a user with a specific role group's permissions per the PP roles spec.
 * Monev: PP01, PP02, PP04, PP05, PP07, comment, terminate
 * BU: PP01, PP03, PP04, PP07, comment
 * Viewer: show/index/comment only
 */
function setupMonevUser(): array
{
    $user = User::factory()->withRole()->create(['name' => 'Monev User']);
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();

    $permissions = [
        'admin.workflows.pp.index',
        'admin.workflows.pp.create',
        'admin.workflows.pp.show',
        'admin.workflows.pp.pp01.show',
        'admin.workflows.pp.pp01.draft',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.show',
        'admin.workflows.pp.pp02.draft',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp04.show',
        'admin.workflows.pp.pp04.draft',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp05.reject',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.draft',
        'admin.workflows.pp.pp07.submit',
        'admin.workflows.pp.comment',
        'admin.workflows.pp.terminate',
        'admin.workflows.pp.destroy',
    ];

    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace];
}

function setupBuUser($organization, $workspace): array
{
    $team = Team::factory()->for($organization)->create(['name' => 'Tim BU Integration']);
    $buRole = \App\Models\Role::factory()->for($team)->create(['name' => 'Bendahara Umum']);

    $user = User::factory()->withRole($buRole, $workspace)->create(['name' => 'BU User']);
    $role = $user->roles->first();

    $permissions = [
        'admin.workflows.pp.index',
        'admin.workflows.pp.show',
        'admin.workflows.pp.pp01.show',
        'admin.workflows.pp.pp01.draft',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp03.show',
        'admin.workflows.pp.pp03.draft',
        'admin.workflows.pp.pp03.submit',
        'admin.workflows.pp.pp04.show',
        'admin.workflows.pp.pp04.draft',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.draft',
        'admin.workflows.pp.pp07.submit',
        'admin.workflows.pp.comment',
    ];

    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace, $team];
}

function setupEvaluatorUser($organization, $workspace): array
{
    $team = Team::factory()->for($organization)->create(['name' => 'Tim Monev Eval']);
    $evalRole = \App\Models\Role::factory()->for($team)->create(['name' => 'Evaluator Narasi']);

    $user = User::factory()->withRole($evalRole, $workspace)->create(['name' => 'Evaluator User']);
    $role = $user->roles->first();

    $permissions = [
        'admin.workflows.pp.index',
        'admin.workflows.pp.create',
        'admin.workflows.pp.show',
        'admin.workflows.pp.pp01.show',
        'admin.workflows.pp.pp01.draft',
        'admin.workflows.pp.pp01.submit',
        'admin.workflows.pp.pp02.show',
        'admin.workflows.pp.pp02.draft',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.show',
        'admin.workflows.pp.pp04.show',
        'admin.workflows.pp.pp04.draft',
        'admin.workflows.pp.pp04.submit',
        'admin.workflows.pp.pp05.show',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp07.create',
        'admin.workflows.pp.pp07.show',
        'admin.workflows.pp.pp07.draft',
        'admin.workflows.pp.pp07.submit',
        'admin.workflows.pp.comment',
    ];

    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace];
}

function advanceToPP05(object $test, array $monevSetup, array $buSetup): PpWorkflow
{
    [$monevUser, $monevRole, $workspace] = $monevSetup;
    [$buUser, $buRole, , $buTeam] = $buSetup;

    ppActivateSession($test, $monevUser, $monevRole, $workspace);
    $test->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::latest('id')->first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $test->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String()])
    );

    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest('id')->first();
    $test->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02->updated_at->toIso8601String()])
    );

    ppActivateSession($test, $buUser, $buRole, $workspace);
    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest('id')->first();
    $test->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]),
        array_merge(pp03SubmitData($buTeam->id), ['expected_updated_at' => $pp03->updated_at->toIso8601String()])
    );

    ppActivateSession($test, $monevUser, $monevRole, $workspace);
    $workflow->refresh();
    $pp04 = $workflow->pp04Data()->latest('id')->first();
    $test->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ]);

    return $workflow->fresh();
}

function ppActivateSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

function pp01SubmitData(int $tahun = 2027): array
{
    return [
        'tahun' => $tahun,
        'tanggal_mulai_pra_raker' => "{$tahun}-01-15",
        'tanggal_penetapan_program' => "{$tahun}-03-01",
        'kode_bidang_pelayanan' => [
            ['kode' => 'BP01', 'nama' => 'Kegiatan', 'catatan' => null],
            ['kode' => 'BP02', 'nama' => 'Pendidikan', 'catatan' => null],
        ],
        'kode_sub_bidang_pelayanan' => [
            ['kode' => 'SB01', 'nama' => 'Kegiatan Umum', 'catatan' => null],
        ],
        'kode_kategori_pelayanan' => [
            ['kode' => 'KP01', 'nama' => 'Reguler', 'catatan' => null],
        ],
        'kode_jenis_program' => [
            ['kode' => 'JP01', 'nama' => 'Rutin', 'catatan' => null],
        ],
    ];
}

function pp02SubmitData(): array
{
    return [
        'item_kuisioner' => [
            ['kode' => 'Q01', 'pertanyaan' => 'Jumlah peserta kegiatan', 'tipe' => 'Kuantitatif', 'satuan' => 'orang'],
            ['kode' => 'Q02', 'pertanyaan' => 'Tingkat kepuasan peserta', 'tipe' => 'Kualitatif', 'satuan' => null],
        ],
    ];
}

function pp03SubmitData(int $teamId): array
{
    return [
        'item_plafon_anggaran' => [[
            'team_id' => $teamId,
            'kode_team' => 'TIBU',
            'plafon_anggaran' => 75000000,
            'nama_bank' => 'BCA',
            'nama_rekening' => 'Finance Playground',
            'nomor_rekening' => '1234567890',
            'catatan' => null,
        ]],
        'rekening_organisasi' => [[
            'nama_bank' => 'BCA',
            'nama_rekening' => 'Finance Playground Pusat',
            'nomor_rekening' => '9876543210',
        ]],
    ];
}

// === D2: Multi-user full flow (Monev + BU) ===

it('completes full PP flow with separate Monev and BU users', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole, , $buTeam] = setupBuUser($org, $workspace);

    $engine = new WorkflowEngine;
    $definition = new PpWorkflowDefinition;

    // 1. Monev creates workflow → PP01
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    // 2. Monev submits PP01 → auto-creates PP02
    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String()])
    )->assertRedirect();

    $workflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP01']['status'])->toBe('completed')
        ->and($statuses['PP02']['status'])->toBe('active');

    // 3. Monev submits PP02 → auto-creates PP03
    $pp02 = $workflow->pp02Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02->updated_at->toIso8601String()])
    )->assertRedirect();

    $workflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP02']['status'])->toBe('completed')
        ->and($statuses['PP03']['status'])->toBe('active');

    // 4. Switch to BU user — BU submits PP03 → auto-creates PP04
    ppActivateSession($this, $buUser, $buRole, $workspace);
    $pp03 = $workflow->pp03Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]),
        array_merge(pp03SubmitData($buTeam->id), ['expected_updated_at' => $pp03->updated_at->toIso8601String()])
    )->assertRedirect();

    $workflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP03']['status'])->toBe('completed')
        ->and($statuses['PP04']['status'])->toBe('active');

    // 5. Switch back to Monev — Monev submits PP04
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $pp04 = $workflow->pp04Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ])->assertRedirect();

    // 6. Monev approves PP05 → auto-compiles PP06
    $workflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP05']['status'])->toBe('active');

    $this->post(route('admin.workflows.pp.pp05.approve', ['ppWorkflow' => $workflow]), [
        'notes' => 'Semua data lengkap — disetujui',
    ])->assertRedirect();

    // Verify final state
    $workflow->refresh();
    expect($engine->getWorkflowStatus($workflow->history))->toBe('completed');

    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP05']['status'])->toBe('completed')
        ->and($statuses['PP06']['status'])->toBe('completed')
        ->and($statuses['PP07']['status'])->toBe('pending'); // PP07 is pending until revision initiated

    // Verify PP06 compiled data
    $pp06 = $workflow->latestPp06();
    expect($pp06)->not->toBeNull()
        ->and($pp06->tahun)->toBe(2027)
        ->and($pp06->revision)->toBe(0)
        ->and($pp06->kodeBidangPelayanan)->toHaveCount(2)
        ->and($pp06->itemKuisioner)->toHaveCount(2)
        ->and($pp06->itemPlafonAnggaran)->toHaveCount(1);

    // Verify history records actors from both users
    $monevEntries = collect($workflow->history)->where('by', $monevUser->id);
    $buEntries = collect($workflow->history)->where('by', $buUser->id);
    expect($monevEntries->count())->toBeGreaterThanOrEqual(4)
        ->and($buEntries->count())->toBeGreaterThanOrEqual(1);
});

// === D3: Full rejection → re-submit → approval cycle ===

it('completes full rejection cycle: reject → re-entry → resubmit all steps → approve', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole, , $buTeam] = setupBuUser($org, $workspace);

    $engine = new WorkflowEngine;
    $definition = new PpWorkflowDefinition;

    // === Cycle 1: create → PP01 → PP02 → PP03 → PP04 → PP05 reject ===
    ppActivateSession($this, $monevUser, $monevRole, $workspace);

    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01v1 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01v1]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01v1->fresh()->updated_at->toIso8601String()])
    );

    $workflow->refresh();
    $pp02v1 = $workflow->pp02Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02v1]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02v1->updated_at->toIso8601String()])
    );

    ppActivateSession($this, $buUser, $buRole, $workspace);
    $workflow->refresh();
    $pp03v1 = $workflow->pp03Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03v1]),
        array_merge(pp03SubmitData($buTeam->id), ['expected_updated_at' => $pp03v1->updated_at->toIso8601String()])
    );

    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $workflow->refresh();
    $pp04v1 = $workflow->pp04Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04v1]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $pp04v1->updated_at->toIso8601String(),
    ]);

    // PP05 Reject
    $workflow->refresh();
    $this->post(route('admin.workflows.pp.pp05.reject', ['ppWorkflow' => $workflow]), [
        'notes' => 'Plafon anggaran perlu ditinjau ulang',
    ])->assertRedirect();

    // Verify cycle 2 PP01 is active with prefilled data
    $workflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP01']['status'])->toBe('active')
        ->and($statuses['PP01']['cycle'])->toBe(2)
        ->and($statuses['PP02']['status'])->toBe('pending')
        ->and($statuses['PP05']['status'])->toBe('pending');

    // New PP01 should be prefilled from cycle 1
    $pp01v2 = $workflow->pp01Data()->latest('id')->first();
    expect($pp01v2->id)->not->toBe($pp01v1->id)
        ->and($pp01v2->tahun)->toBe(2027)
        ->and($pp01v2->kodeBidangPelayanan)->toHaveCount(2);

    // Verify stepper shows 2 cycles
    $stepperData = $engine->getStepperData($definition, $workflow->history, fn ($step, $id) => '#');
    expect($stepperData)->toHaveCount(2);

    // === Cycle 2: re-submit all steps → approve ===

    // Advance time so cycle 2 timestamps are strictly after the rejection
    $this->travel(1)->seconds();

    // PP01 submit (same data, cycle 2)
    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01v2]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01v2->fresh()->updated_at->toIso8601String()])
    )->assertRedirect()->assertSessionHasNoErrors();

    // PP02 submit
    $workflow->refresh();
    $pp02v2 = $workflow->pp02Data()->latest('id')->first();
    expect($pp02v2->id)->not->toBe($pp02v1->id);
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02v2]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02v2->updated_at->toIso8601String()])
    )->assertRedirect()->assertSessionHasNoErrors();

    // BU submits PP03
    ppActivateSession($this, $buUser, $buRole, $workspace);
    $workflow->refresh();
    $pp03v2 = $workflow->pp03Data()->latest('id')->first();
    expect($pp03v2->id)->not->toBe($pp03v1->id)
        ->and($pp03v2->itemPlafonAnggaran)->toHaveCount(1)
        ->and($pp03v2->rekeningOrganisasi)->toHaveCount(1);
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03v2]),
        array_merge(pp03SubmitData($buTeam->id), ['expected_updated_at' => $pp03v2->updated_at->toIso8601String()])
    )->assertRedirect();

    // Monev submits PP04
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $workflow->refresh();
    $pp04v2 = $workflow->pp04Data()->latest('id')->first();
    expect($pp04v2->id)->not->toBe($pp04v1->id);
    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04v2]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $pp04v2->updated_at->toIso8601String(),
    ])->assertRedirect();

    // PP05 Approve
    $workflow->refresh();
    $this->post(route('admin.workflows.pp.pp05.approve', ['ppWorkflow' => $workflow]), [
        'notes' => 'Revisi diterima',
    ])->assertRedirect();

    // Verify completed state
    $workflow->refresh();
    expect($engine->getWorkflowStatus($workflow->history))->toBe('completed');

    $pp06 = $workflow->latestPp06();
    expect($pp06)->not->toBeNull()
        ->and($pp06->tahun)->toBe(2027)
        ->and($pp06->revision)->toBe(0)
        ->and($pp06->kodeBidangPelayanan)->toHaveCount(2)
        ->and($pp06->itemKuisioner)->toHaveCount(2)
        ->and($pp06->itemPlafonAnggaran)->toHaveCount(1);

    // Verify stepper shows 2 cycles after completion
    $stepperData = $engine->getStepperData($definition, $workflow->history, fn ($step, $id) => '#');
    expect($stepperData)->toHaveCount(2);

    // Verify history has entries from both cycles (created + rejected + created + approved)
    $pp05Entries = collect($workflow->history)->where('step', 'PP05');
    expect($pp05Entries)->toHaveCount(4);
});

// === D4: PP07 multiple revisions (rev0 → rev1 → rev2) ===

it('creates multiple PP07 revisions with incremented revision numbers', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole, , $buTeam] = setupBuUser($org, $workspace);

    $engine = new WorkflowEngine;
    $definition = new PpWorkflowDefinition;

    // Complete full flow to PP06 (rev 0)
    ppActivateSession($this, $monevUser, $monevRole, $workspace);

    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String()])
    );

    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02->updated_at->toIso8601String()])
    );

    ppActivateSession($this, $buUser, $buRole, $workspace);
    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]),
        array_merge(pp03SubmitData($buTeam->id), ['expected_updated_at' => $pp03->updated_at->toIso8601String()])
    );

    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $workflow->refresh();
    $pp04 = $workflow->pp04Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp04.submit', ['ppWorkflow' => $workflow, 'pp04Data' => $pp04]), [
        'attach_file_ids' => [],
        'expected_updated_at' => $pp04->updated_at->toIso8601String(),
    ]);

    $workflow->refresh();
    $this->post(route('admin.workflows.pp.pp05.approve', ['ppWorkflow' => $workflow]), [
        'notes' => 'Approved',
    ]);

    $workflow->refresh();
    $pp06Rev0 = $workflow->latestPp06();
    expect($pp06Rev0->revision)->toBe(0);

    // === Revision 1: Monev creates PP07, modifies plafon, submits ===
    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07v1 = Pp07Data::where('pp_workflow_id', $workflow->id)->latest('id')->first();

    $draftData1 = $pp07v1->draft_data;
    $draftData1['item_plafon_anggaran'][0]['plafon_anggaran'] = 90000000;

    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07v1]), [
        'draft_data' => $draftData1,
        'expected_updated_at' => $pp07v1->fresh()->updated_at->toIso8601String(),
        'notes' => 'Revisi 1: naikkan plafon',
    ])->assertRedirect();

    $workflow->refresh();
    $pp06Rev1 = $workflow->latestPp06();
    expect($pp06Rev1->revision)->toBe(1)
        ->and((float) $pp06Rev1->itemPlafonAnggaran->first()->plafon_anggaran)->toBe(90000000.0);

    // === Revision 2: BU creates PP07, adds kuisioner item, submits ===
    ppActivateSession($this, $buUser, $buRole, $workspace);

    $this->post(route('admin.workflows.pp.pp07.create', ['ppWorkflow' => $workflow]));
    $pp07v2 = Pp07Data::where('pp_workflow_id', $workflow->id)->latest('id')->first();
    expect($pp07v2->id)->not->toBe($pp07v1->id);

    $draftData2 = $pp07v2->draft_data;
    // Verify rev 2 draft starts from rev 1 compiled data
    expect((int) $draftData2['item_plafon_anggaran'][0]['plafon_anggaran'])->toBe(90000000);

    $draftData2['item_kuisioner'][] = [
        'kode' => 'Q03',
        'pertanyaan' => 'Jumlah kegiatan baru',
        'tipe' => 'Kuantitatif',
        'satuan' => 'kegiatan',
    ];

    $this->post(route('admin.workflows.pp.pp07.submit', ['ppWorkflow' => $workflow, 'pp07Data' => $pp07v2]), [
        'draft_data' => $draftData2,
        'expected_updated_at' => $pp07v2->fresh()->updated_at->toIso8601String(),
        'notes' => 'Revisi 2: tambah kuisioner',
    ])->assertRedirect();

    // Verify revision 2
    $workflow->refresh();
    $pp06Rev2 = $workflow->latestPp06();
    expect($pp06Rev2->revision)->toBe(2)
        ->and($pp06Rev2->itemKuisioner)->toHaveCount(3)
        ->and((float) $pp06Rev2->itemPlafonAnggaran->first()->plafon_anggaran)->toBe(90000000.0);

    // Verify all 3 revisions exist
    expect($workflow->pp06PeriodeTahunan()->count())->toBe(3);

    // Verify workflow remains completed
    expect($engine->getWorkflowStatus($workflow->history))->toBe('completed');

    // Verify author tracking: rev2 PP01-PP04 author = BU user (the PP07 submitter)
    expect($pp06Rev2->pp01_created_by_user_name)->toBe('BU User');
    // PP05 author should carry forward from rev 0
    expect($pp06Rev2->pp05_created_by_user_name)->toBe($pp06Rev0->pp05_created_by_user_name);
});

// === D5: Terminate at various states + lifecycle ===

it('terminates workflow at mid-progress (PP03 active) and verifies state', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;

    ppActivateSession($this, $monevUser, $monevRole, $workspace);

    $engine = new WorkflowEngine;
    $definition = new PpWorkflowDefinition;

    // Create and advance to PP03
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String()])
    );

    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02->updated_at->toIso8601String()])
    );

    // PP03 is now active — terminate here
    $workflow->refresh();
    $statuses = $engine->getStepStatuses($definition, $workflow->history);
    expect($statuses['PP03']['status'])->toBe('active');

    $this->post(route('admin.workflows.pp.terminate', $workflow), [
        'notes' => 'Dibatalkan di tengah proses — perubahan kebijakan',
    ])->assertRedirect(route('admin.workflows.pp.show', $workflow));

    $workflow->refresh();
    expect($engine->getWorkflowStatus($workflow->history))->toBe('terminated');

    // Verify terminate entry references active step
    $terminateEntry = collect($workflow->history)->last(fn ($e) => ($e['action'] ?? '') === 'terminated');
    expect($terminateEntry)->not->toBeNull()
        ->and($terminateEntry['notes'])->toBe('Dibatalkan di tengah proses — perubahan kebijakan');

    // Show page should reflect terminated state
    $this->get(route('admin.workflows.pp.show', $workflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canTerminate', false)
            ->where('canRevise', false)
            ->where('canDelete', true)
        );

    // Can destroy terminated workflow
    $this->delete(route('admin.workflows.pp.destroy', $workflow))
        ->assertRedirect(route('admin.workflows.pp.index'));

    expect(PpWorkflow::find($workflow->id))->toBeNull();
    expect(PpWorkflow::withTrashed()->find($workflow->id))->not->toBeNull();
});

it('prevents BU user from terminating workflow (no terminate permission)', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole] = setupBuUser($org, $workspace);

    // Monev creates workflow
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();

    // BU tries to terminate — should be denied
    ppActivateSession($this, $buUser, $buRole, $workspace);
    $this->post(route('admin.workflows.pp.terminate', $workflow), [
        'notes' => 'BU trying to terminate',
    ])->assertForbidden();
});

it('prevents BU user from submitting PP02 (Monev-only step)', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole] = setupBuUser($org, $workspace);

    // Monev creates workflow and submits PP01
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String()])
    );

    // BU tries to submit PP02 — should fail (no pp02.submit permission)
    ppActivateSession($this, $buUser, $buRole, $workspace);
    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest('id')->first();

    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02->updated_at->toIso8601String()])
    )->assertForbidden();
});

it('prevents Monev user from submitting PP03 (BU-only step)', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole, , $buTeam] = setupBuUser($org, $workspace);

    // Monev creates and submits PP01 → PP02
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();
    $pp01 = Pp01Data::where('pp_workflow_id', $workflow->id)->first();

    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $pp01]),
        array_merge(pp01SubmitData(), ['expected_updated_at' => $pp01->fresh()->updated_at->toIso8601String()])
    );

    $workflow->refresh();
    $pp02 = $workflow->pp02Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp02.submit', ['ppWorkflow' => $workflow, 'pp02Data' => $pp02]),
        array_merge(pp02SubmitData(), ['expected_updated_at' => $pp02->updated_at->toIso8601String()])
    );

    // Monev tries to submit PP03 — should fail (no pp03.submit permission)
    $workflow->refresh();
    $pp03 = $workflow->pp03Data()->latest('id')->first();
    $this->post(route('admin.workflows.pp.pp03.submit', ['ppWorkflow' => $workflow, 'pp03Data' => $pp03]),
        array_merge(pp03SubmitData($buTeam->id), ['expected_updated_at' => $pp03->updated_at->toIso8601String()])
    )->assertForbidden();
});

// === D5e–D5h: Role-differentiated permission boundary tests ===

it('prevents Evaluator Narasi from approving PP05 (Koordinator-only)', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole, , $buTeam] = setupBuUser($org, $workspace);
    [$evalUser, $evalRole] = setupEvaluatorUser($org, $workspace);

    // Advance to PP05
    $workflow = advanceToPP05($this, [$monevUser, $monevRole, $workspace], [$buUser, $buRole, $workspace, $buTeam]);

    // Evaluator tries to approve — should be denied
    ppActivateSession($this, $evalUser, $evalRole, $workspace);
    $this->post(route('admin.workflows.pp.pp05.approve', ['ppWorkflow' => $workflow]), [
        'notes' => 'Evaluator trying to approve',
    ])->assertForbidden();
});

it('prevents Evaluator Anggaran from terminating workflow (Koordinator-only)', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$evalUser, $evalRole] = setupEvaluatorUser($org, $workspace);

    // Monev creates workflow
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();

    // Evaluator tries to terminate — should be denied
    ppActivateSession($this, $evalUser, $evalRole, $workspace);
    $this->post(route('admin.workflows.pp.terminate', $workflow), [
        'notes' => 'Evaluator trying to terminate',
    ])->assertForbidden();
});

it('prevents BU user from creating workflow (Monev-only)', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole] = setupBuUser($org, $workspace);

    // BU tries to create workflow — should be denied
    ppActivateSession($this, $buUser, $buRole, $workspace);
    $this->post(route('admin.workflows.pp.create'))->assertForbidden();
});

it('prevents non-Koordinator from deleting terminated workflow (Koordinator-only)', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$evalUser, $evalRole] = setupEvaluatorUser($org, $workspace);

    // Monev creates and terminates workflow
    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.create'));
    $workflow = PpWorkflow::first();

    $this->post(route('admin.workflows.pp.terminate', $workflow), [
        'notes' => 'Terminated for testing',
    ])->assertRedirect();

    // Evaluator tries to delete terminated workflow — should be denied
    ppActivateSession($this, $evalUser, $evalRole, $workspace);
    $this->delete(route('admin.workflows.pp.destroy', $workflow))->assertForbidden();
});

// === D12: Stale record guard prevents writing to old PP01-PP04 records after rejection ===

it('rejects draft/submit to old PP01 record after rejection creates a new one', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole, , $buTeam] = setupBuUser($org, $workspace);

    // Advance to PP05 (submits PP01-PP04), then reject
    $workflow = advanceToPP05($this, [$monevUser, $monevRole, $workspace], [$buUser, $buRole, $workspace, $buTeam]);

    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.pp05.reject', ['ppWorkflow' => $workflow]), [
        'notes' => 'Needs revision',
    ])->assertRedirect();

    $workflow->refresh();

    // Get both old (cycle 1) and new (cycle 2) PP01 records
    $allPp01 = $workflow->pp01Data()->orderBy('id')->get();
    expect($allPp01)->toHaveCount(2);
    $oldPp01 = $allPp01->first();
    $newPp01 = $allPp01->last();

    // Drafting to the OLD PP01 should be rejected with 409
    $this->post(route('admin.workflows.pp.pp01.draft', ['ppWorkflow' => $workflow, 'pp01Data' => $oldPp01]),
        array_merge(pp01SubmitData(2028), ['expected_updated_at' => $oldPp01->updated_at->toIso8601String()])
    )->assertStatus(409);

    // Submitting to the OLD PP01 should be rejected with 409
    $this->post(route('admin.workflows.pp.pp01.submit', ['ppWorkflow' => $workflow, 'pp01Data' => $oldPp01]),
        array_merge(pp01SubmitData(2028), ['expected_updated_at' => $oldPp01->updated_at->toIso8601String()])
    )->assertStatus(409);

    // Verify old PP01 data is unchanged
    $oldPp01->refresh();
    expect($oldPp01->tahun)->toBe(2027);

    // Drafting to the NEW PP01 should succeed
    $this->post(route('admin.workflows.pp.pp01.draft', ['ppWorkflow' => $workflow, 'pp01Data' => $newPp01]),
        array_merge(pp01SubmitData(2028), ['expected_updated_at' => $newPp01->fresh()->updated_at->toIso8601String()])
    )->assertRedirect();

    // Verify new PP01 was updated
    $newPp01->refresh();
    expect($newPp01->tahun)->toBe(2028);
});

it('shows old PP01 record as readonly when viewing during cycle 2', function () {
    [$monevUser, $monevRole, $workspace] = setupMonevUser();
    $org = $monevRole->team->organization;
    [$buUser, $buRole, , $buTeam] = setupBuUser($org, $workspace);

    $workflow = advanceToPP05($this, [$monevUser, $monevRole, $workspace], [$buUser, $buRole, $workspace, $buTeam]);

    ppActivateSession($this, $monevUser, $monevRole, $workspace);
    $this->post(route('admin.workflows.pp.pp05.reject', ['ppWorkflow' => $workflow]), [
        'notes' => 'Needs revision',
    ]);

    $workflow->refresh();
    $oldPp01 = $workflow->pp01Data()->orderBy('id')->first();

    // Viewing the old PP01 should render as readonly
    $response = $this->get(route('admin.workflows.pp.pp01.show', ['ppWorkflow' => $workflow, 'pp01Data' => $oldPp01]));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/workflows/pp/pp01')
        ->where('mode', 'readonly')
        ->where('canDraft', false)
        ->where('canSubmit', false)
    );
});
