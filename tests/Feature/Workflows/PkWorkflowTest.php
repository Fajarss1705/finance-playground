<?php

use App\Models\Permission;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06ItemKuisioner;
use App\Models\Pp\Pp06KodeBidangPelayanan;
use App\Models\Pp\Pp06KodeJenisProgram;
use App\Models\Pp\Pp06KodeKategoriPelayanan;
use App\Models\Pp\Pp06KodeSubBidangPelayanan;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\WorkflowEngine;
use App\Workflows\PkWorkflowDefinition;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * Create a team-scope user with given permissions.
 * Returns [$user, $role, $workspace, $team].
 */
function setupPkUser(string ...$permissionNames): array
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

function activatePkSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

/**
 * Create a completed PP workflow with PP06 + PP01 + plafon + kode data.
 * Pra-raker window covers "now" by default.
 */
function pp06AuthorSnapshot(): array
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

function setupCompletedPp($workspace, $team): array
{
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $pp01 = Pp01Data::create([
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
        ...pp06AuthorSnapshot(),
    ]);

    // Plafon for the team
    $pp06->itemPlafonAnggaran()->create([
        'team_id' => $team->id,
        'kode_team' => 'T01',
        'plafon_anggaran' => 50000000,
        'nama_bank' => 'Bank Test',
        'nama_rekening' => 'Rek Test',
        'nomor_rekening' => '1234567890',
    ]);

    // Kode reference data
    Pp06KodeKategoriPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'K01', 'nama' => 'Pelayanan Utama']);
    Pp06KodeBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'B01', 'nama' => 'Kegiatan']);
    Pp06KodeSubBidangPelayanan::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'SB01', 'nama' => 'Kegiatan Umum']);
    Pp06KodeJenisProgram::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'J01', 'nama' => 'Rutin']);
    Pp06ItemKuisioner::create(['pp06_periode_tahunan_id' => $pp06->id, 'kode' => 'Q01', 'pertanyaan' => 'Jumlah peserta', 'tipe' => 'angka', 'satuan' => 'orang']);

    return [$ppWorkflow, $pp06];
}

/**
 * Create a PK workflow with PK01 in active state.
 */
function setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role): array
{
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $role->team->organization->id,
        'history' => [],
    ]);

    $pk01 = Pk01Data::create(['pk_workflow_id' => $pkWorkflow->id]);

    // Record creation in history
    $engine = new WorkflowEngine;
    $engine->recordAction(
        workflow: $pkWorkflow,
        step: 'PK01',
        action: 'created',
        userId: $user->id,
        sessionContext: ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id],
        table: 'pk01_data',
        dataId: $pk01->id,
    );

    return [$pkWorkflow, $pk01];
}

function validPk01SubmitData(string $expectedUpdatedAt): array
{
    return [
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Kegiatan Kepemudaan',
        'deskripsi_program' => 'Program pembinaan spiritual untuk remaja.',
        'tujuan_program' => 'Meningkatkan partisipasi remaja dalam kegiatan.',
        'kegiatan' => [
            [
                'nama_kegiatan' => 'Retreat Remaja',
                'bulan' => 3,
                'anggaran' => [
                    [
                        'kode_bidang' => 'B01',
                        'kode_sub_bidang' => 'SB01',
                        'kode_jenis' => 'J01',
                        'mata_anggaran' => 'Konsumsi',
                        'deskripsi_pk' => 'Konsumsi peserta retreat',
                        'nominal_anggaran' => 2500000,
                    ],
                ],
                'kuisioner' => [
                    [
                        'kode_kuisioner' => 'Q01',
                        'pertanyaan' => 'Jumlah peserta',
                        'tipe' => 'angka',
                        'satuan' => 'orang',
                    ],
                ],
            ],
        ],
        'expected_updated_at' => $expectedUpdatedAt,
    ];
}

// ── Create ──

it('creates a PK workflow from team scope and redirects to PK01', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.create',
        'team.workflows.pk.pk01.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    $response = $this->post(route('team.workflows.pk.create', ['ppWorkflow' => $ppWorkflow->id]));

    $pkWorkflow = PkWorkflow::first();
    expect($pkWorkflow)->not->toBeNull()
        ->and($pkWorkflow->team_id)->toBe($team->id)
        ->and($pkWorkflow->tipe)->toBe('raker');

    $pk01 = Pk01Data::where('pk_workflow_id', $pkWorkflow->id)->first();
    expect($pk01)->not->toBeNull();

    $response->assertRedirect(route('team.workflows.pk.pk01.show', [
        'pkWorkflow' => $pkWorkflow->id,
        'pk01Data' => $pk01->id,
    ]));

    $history = $pkWorkflow->fresh()->history;
    expect($history)->toHaveCount(1)
        ->and($history[0]['action'])->toBe('created')
        ->and($history[0]['step'])->toBe('PK01');
});

it('blocks PK create when PP is not complete', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.create');
    activatePkSession($this, $user, $role, $workspace);

    // PP still active (no PP06 compiled)
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $this->post(route('team.workflows.pk.create', ['ppWorkflow' => $ppWorkflow->id]))
        ->assertForbidden();
});

it('blocks PK create when team has no plafon', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.create');
    activatePkSession($this, $user, $role, $workspace);

    // PP completed but no plafon for this team
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP06', 'action' => 'compiled', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);
    Pp01Data::create(['pp_workflow_id' => $ppWorkflow->id, 'tahun' => 2027]);
    $pp06 = Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->subDays(10)->toDateString(),
        'tanggal_penetapan_program' => now()->addDays(30)->toDateString(),
        ...pp06AuthorSnapshot(),
    ]);
    // No plafon created for this team

    $this->post(route('team.workflows.pk.create', ['ppWorkflow' => $ppWorkflow->id]))
        ->assertForbidden();
});

it('blocks PK create outside pra-raker window', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.create');
    activatePkSession($this, $user, $role, $workspace);

    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [
            ['step' => 'PP06', 'action' => 'compiled', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);
    Pp01Data::create(['pp_workflow_id' => $ppWorkflow->id, 'tahun' => 2027]);
    $pp06 = Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->addDays(10)->toDateString(), // window hasn't started
        'tanggal_penetapan_program' => now()->addDays(40)->toDateString(),
        ...pp06AuthorSnapshot(),
    ]);
    $pp06->itemPlafonAnggaran()->create([
        'team_id' => $team->id,
        'kode_team' => 'T01',
        'plafon_anggaran' => 50000000,
        'nama_bank' => 'Bank Test',
        'nama_rekening' => 'Rek Test',
        'nomor_rekening' => '1234567890',
    ]);

    $this->post(route('team.workflows.pk.create', ['ppWorkflow' => $ppWorkflow->id]))
        ->assertForbidden();
});

// ── PK01 Show ──

it('displays PK01 page in edit mode for team user with permissions', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
        'team.workflows.pk.pk01.submit',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.pk01.show', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk01')
            ->where('mode', 'edit')
            ->where('canDraft', true)
            ->where('canSubmit', true)
            ->where('scope', 'team')
            ->has('kodeKategori', 1)
            ->has('kodeBidang', 1)
            ->has('budgetCounter')
        );
});

it('renders PK01 readonly for admin scope', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk01.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.pk01.show', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk01')
            ->where('mode', 'readonly')
            ->where('canDraft', false)
            ->where('canSubmit', false)
            ->where('scope', 'admin')
        );
});

it('renders PK01 readonly for team user without draft/submit permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.pk01.show', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk01')
            ->where('canDraft', false)
            ->where('canSubmit', false)
        );
});

// ── PK01 Draft ──

it('drafts PK01 data with partial fields', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.pk01.draft', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Test',
        'kegiatan' => [
            [
                'nama_kegiatan' => 'Kegiatan A',
                'bulan' => 1,
                'anggaran' => [
                    [
                        'kode_bidang' => 'B01',
                        'kode_sub_bidang' => 'SB01',
                        'kode_jenis' => 'J01',
                        'mata_anggaran' => 'Konsumsi',
                        'nominal_anggaran' => 1000000,
                    ],
                ],
            ],
        ],
        'expected_updated_at' => $pk01->updated_at->toIso8601String(),
    ])->assertRedirect();

    $pk01->refresh();
    expect($pk01->kode_kategori)->toBe('K01')
        ->and($pk01->nama_program)->toBe('Program Test');

    // Kegiatan saved
    $kegiatan = $pk01->kegiatan()->first();
    expect($kegiatan)->not->toBeNull()
        ->and($kegiatan->nama_kegiatan)->toBe('Kegiatan A')
        ->and($kegiatan->bulan)->toBe(1);

    // Anggaran saved
    $anggaran = $kegiatan->anggaran()->first();
    expect($anggaran)->not->toBeNull()
        ->and($anggaran->mata_anggaran)->toBe('Konsumsi')
        ->and((float) $anggaran->nominal_anggaran)->toBe(1000000.0);

    // History entry
    $pkWorkflow->refresh();
    $lastEntry = collect($pkWorkflow->history)->last();
    expect($lastEntry['action'])->toBe('drafted')
        ->and($lastEntry['step'])->toBe('PK01');
});

it('drafts PK01 with notes in history', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.pk01.draft', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'expected_updated_at' => $pk01->updated_at->toIso8601String(),
        'notes' => 'Menyimpan draft awal',
    ])->assertRedirect();

    $pkWorkflow->refresh();
    $lastEntry = collect($pkWorkflow->history)->last();
    expect($lastEntry['notes'])->toBe('Menyimpan draft awal');
});

it('filters empty kegiatan during draft save', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.pk01.draft', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'kegiatan' => [
            // Valid kegiatan
            [
                'nama_kegiatan' => 'Kegiatan Valid',
                'bulan' => 5,
                'anggaran' => [['kode_bidang' => 'B01', 'kode_sub_bidang' => 'SB01', 'kode_jenis' => 'J01', 'mata_anggaran' => 'Item', 'nominal_anggaran' => 100000]],
            ],
            // Empty kegiatan (should be filtered)
            [
                'nama_kegiatan' => null,
                'bulan' => null,
                'anggaran' => [],
            ],
        ],
        'expected_updated_at' => $pk01->updated_at->toIso8601String(),
    ])->assertRedirect();

    $pk01->refresh();
    expect($pk01->kegiatan)->toHaveCount(1)
        ->and($pk01->kegiatan->first()->nama_kegiatan)->toBe('Kegiatan Valid');
});

// ── PK01 Submit ──

it('submits PK01 and auto-creates PK02A + PK02B', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        validPk01SubmitData($pk01->updated_at->toIso8601String()),
    )->assertRedirect(route('team.workflows.pk.show', $pkWorkflow));

    $pk01->refresh();
    expect($pk01->kode_kategori)->toBe('K01')
        ->and($pk01->nama_program)->toBe('Program Kegiatan Kepemudaan');

    // Kegiatan + anggaran + kuisioner saved
    $kegiatan = $pk01->kegiatan()->with(['anggaran', 'kuisioner'])->first();
    expect($kegiatan)->not->toBeNull()
        ->and($kegiatan->nama_kegiatan)->toBe('Retreat Remaja')
        ->and($kegiatan->anggaran)->toHaveCount(1)
        ->and($kegiatan->kuisioner)->toHaveCount(1);

    // History: created + submitted + PK02A created + PK02B created
    $pkWorkflow->refresh();
    expect($pkWorkflow->history)->toHaveCount(4);

    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    expect($statuses['PK01']['status'])->toBe('completed')
        ->and($statuses['PK02A']['status'])->toBe('active')
        ->and($statuses['PK02B']['status'])->toBe('active');
});

it('rejects PK01 submit with missing required fields', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'expected_updated_at' => $pk01->updated_at->toIso8601String(),
    ])->assertSessionHasErrors([
        'kode_kategori',
        'nama_program',
        'deskripsi_program',
        'tujuan_program',
        'kegiatan',
    ]);

    // No submit in history
    $pkWorkflow->refresh();
    expect($pkWorkflow->history)->toHaveCount(1);
});

it('rejects PK01 submit with empty kegiatan anggaran', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Test',
        'deskripsi_program' => 'Deskripsi test',
        'tujuan_program' => 'Tujuan test',
        'kegiatan' => [
            [
                'nama_kegiatan' => 'Kegiatan Tanpa Anggaran',
                'bulan' => 1,
                'anggaran' => [], // empty — should fail
            ],
        ],
        'expected_updated_at' => $pk01->updated_at->toIso8601String(),
    ])->assertSessionHasErrors(['kegiatan.0.anggaran']);
});

it('allows PK01 submit with no kuisioner', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $data = validPk01SubmitData($pk01->updated_at->toIso8601String());
    unset($data['kegiatan'][0]['kuisioner']); // no kuisioner — should still pass

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        $data,
    )->assertRedirect();
});

// ── PP07 Active Block ──

it('blocks PK01 submit when PP07 draft is active', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Create a PP07 draft (no submitted_at = active revision)
    $ppWorkflow->pp07Data()->create([
        'revision' => 1,
        'submitted_at' => null,
    ]);

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        validPk01SubmitData($pk01->updated_at->toIso8601String()),
    )->assertSessionHasErrors(['submit']);

    // History unchanged
    $pkWorkflow->refresh();
    expect($pkWorkflow->history)->toHaveCount(1);
});

it('allows PK01 draft even when PP07 is active', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // PP07 draft active
    $ppWorkflow->pp07Data()->create(['revision' => 1, 'submitted_at' => null]);

    $this->post(route('team.workflows.pk.pk01.draft', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'nama_program' => 'Draft During PP07',
        'expected_updated_at' => $pk01->updated_at->toIso8601String(),
    ])->assertRedirect();

    $pk01->refresh();
    expect($pk01->nama_program)->toBe('Draft During PP07');
});

// ── Optimistic Locking ──

it('returns 409 when expected_updated_at is stale on PK01 draft', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $staleTimestamp = $pk01->updated_at->toIso8601String();

    // Simulate concurrent edit
    $this->travel(2)->seconds();
    $pk01->update(['nama_program' => 'Changed by someone else']);

    $this->post(route('team.workflows.pk.pk01.draft', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'nama_program' => 'My attempt',
        'expected_updated_at' => $staleTimestamp,
    ])->assertStatus(409);
});

// ── Permission Enforcement ──

it('denies PK01 draft without draft permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.pk01.draft', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]), [
        'expected_updated_at' => $pk01->updated_at->toIso8601String(),
    ])->assertForbidden();
});

it('denies PK01 submit without submit permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        validPk01SubmitData($pk01->updated_at->toIso8601String()),
    )->assertForbidden();
});

// ── Comment ──

it('adds a comment to PK workflow from team scope', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.comment');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.comment', $pkWorkflow), [
        'notes' => 'Catatan dari tim',
        'source' => 'pk01',
    ])->assertRedirect();

    $pkWorkflow->refresh();
    $lastEntry = collect($pkWorkflow->history)->last();
    expect($lastEntry['action'])->toBe('commented')
        ->and($lastEntry['notes'])->toBe('Catatan dari tim');
});

// ── Terminate ──

it('terminates a PK workflow', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.terminate');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('team.workflows.pk.terminate', $pkWorkflow), [
        'notes' => 'Program dibatalkan',
    ])->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    expect($engine->getWorkflowStatus($pkWorkflow->history))->toBe('terminated');
});

// ── Destroy (admin only) ──

it('soft-deletes a terminated PK workflow from admin scope', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.destroy', 'team.workflows.pk.terminate');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Terminate first
    $engine = new WorkflowEngine;
    $engine->recordAction(
        workflow: $pkWorkflow,
        step: 'PK01',
        action: 'terminated',
        userId: $user->id,
        sessionContext: ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id],
        notes: 'Cancel',
    );

    $this->delete(route('admin.workflows.pk.destroy', $pkWorkflow))
        ->assertRedirect();

    expect($pkWorkflow->fresh()->trashed())->toBeTrue();
});

// ── Team Scoping ──

it('denies PK01 access when workflow belongs to different team', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
    );
    activatePkSession($this, $user, $role, $workspace);

    // Create a second user/team in the same workspace
    [$user2, $role2, $workspace2, $team2] = setupPkUser('team.workflows.pk.pk01.show');

    // Create PP + PK under the OTHER team
    [$ppWorkflow] = setupCompletedPp($workspace, $team2);
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team2->id, // different team!
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $user2->id,
        'created_by_role_id' => $role2->id,
        'created_by_team_id' => $team2->id,
        'created_by_org_id' => $role2->team->organization->id,
        'history' => [
            ['step' => 'PK01', 'action' => 'created', 'by' => $user2->id, 'role' => $role2->id, 'team' => $team2->id, 'org' => $role2->team->organization->id, 'workspace' => $workspace->id, 'at' => now()->toIso8601String(), 'table' => 'pk01_data', 'id' => 1],
        ],
    ]);
    $pk01 = Pk01Data::create(['pk_workflow_id' => $pkWorkflow->id]);
    $history = $pkWorkflow->history;
    $history[0]['id'] = $pk01->id;
    $pkWorkflow->update(['history' => $history]);

    // First user (different team) should be forbidden
    $this->get(route('team.workflows.pk.pk01.show', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]))
        ->assertForbidden();
});

// ── Kode Validation ──

it('rejects PK01 submit with invalid kode_kategori', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $data = validPk01SubmitData($pk01->updated_at->toIso8601String());
    $data['kode_kategori'] = 'INVALID';

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        $data,
    )->assertSessionHasErrors(['kode_kategori']);
});

it('rejects PK01 submit with invalid kode_bidang in anggaran', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $data = validPk01SubmitData($pk01->updated_at->toIso8601String());
    $data['kegiatan'][0]['anggaran'][0]['kode_bidang'] = 'INVALID';

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        $data,
    )->assertSessionHasErrors(['kegiatan.0.anggaran.0.kode_bidang']);
});

// ── Same Bulan ──

it('allows multiple kegiatan with the same bulan', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk01.submit');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $data = validPk01SubmitData($pk01->updated_at->toIso8601String());
    // Add second kegiatan with same bulan
    $data['kegiatan'][] = [
        'nama_kegiatan' => 'Kegiatan Kedua',
        'bulan' => 3, // same as first
        'anggaran' => [[
            'kode_bidang' => 'B01',
            'kode_sub_bidang' => 'SB01',
            'kode_jenis' => 'J01',
            'mata_anggaran' => 'Transportasi',
            'nominal_anggaran' => 1000000,
        ]],
    ];

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        $data,
    )->assertRedirect();

    $pk01->refresh();
    expect($pk01->kegiatan)->toHaveCount(2);
});

// ── Rejection Cycle + Changelog Diff ──

it('handles PK01 re-submission after rejection with changelog diff', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.submit',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $engine = new WorkflowEngine;
    $ctx = ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id];

    // --- Cycle 1: initial submit ---
    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]),
        validPk01SubmitData($pk01->updated_at->toIso8601String()),
    )->assertRedirect();

    // Simulate PK02A approve + PK02B reject → join gate rejects PK03 → PK01 re-entry
    $pkWorkflow->refresh();
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02A', action: 'approved', userId: $user->id, sessionContext: $ctx, notes: 'Narasi ok');
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02B', action: 'rejected', userId: $user->id, sessionContext: $ctx, notes: 'Nominal terlalu tinggi');
    // Join gate: record PK03 rejected to trigger invalidation cascade (PK03 has rejectionTarget='PK01')
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK03', action: 'rejected', userId: null, sessionContext: [], notes: 'Otomatis: PK02B menolak.');
    // PK01 re-entry: create new pk01_data
    $pk01Cycle2 = Pk01Data::create([
        'pk_workflow_id' => $pkWorkflow->id,
        'kode_kategori' => $pk01->fresh()->kode_kategori,
        'nama_program' => $pk01->fresh()->nama_program,
        'deskripsi_program' => $pk01->fresh()->deskripsi_program,
        'tujuan_program' => $pk01->fresh()->tujuan_program,
    ]);
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK01', action: 'created', userId: null, sessionContext: [], table: 'pk01_data', dataId: $pk01Cycle2->id);

    // --- Cycle 2: re-submit with changes ---
    $data = validPk01SubmitData($pk01Cycle2->updated_at->toIso8601String());
    $data['nama_program'] = 'Program Kegiatan Kepemudaan (Revisi)'; // changed
    $data['kegiatan'][0]['anggaran'][0]['nominal_anggaran'] = 2000000; // changed from 2500000

    $this->post(
        route('team.workflows.pk.pk01.submit', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01Cycle2]),
        $data,
    )->assertRedirect();

    // Verify changelog in history
    $pkWorkflow->refresh();
    $submitEntries = collect($pkWorkflow->history)->where('action', 'submitted')->values();
    expect($submitEntries)->toHaveCount(2);

    $cycle2Submit = $submitEntries->last();
    expect($cycle2Submit)->toHaveKey('changes');

    $changes = $cycle2Submit['changes'];
    $programChange = collect($changes)->firstWhere('type', 'program_changed');
    expect($programChange)->not->toBeNull()
        ->and($programChange['field'])->toBe('nama_program')
        ->and($programChange['old'])->toBe('Program Kegiatan Kepemudaan')
        ->and($programChange['new'])->toBe('Program Kegiatan Kepemudaan (Revisi)');

    $anggaranChange = collect($changes)->firstWhere('type', 'anggaran_changed');
    expect($anggaranChange)->not->toBeNull()
        ->and($anggaranChange['field'])->toBe('nominal_anggaran');
});

// ══════════════════════════════════════════════════════════════
//  PK02A / PK02B — Parallel Approval
// ══════════════════════════════════════════════════════════════

/**
 * Setup a PK workflow at the PK02A+PK02B stage (PK01 submitted, both tracks active).
 */
function setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role): array
{
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Populate PK01 data
    $pk01->update([
        'kode_kategori' => 'K01',
        'nama_program' => 'Program Test PK02',
        'deskripsi_program' => 'Deskripsi program test',
        'tujuan_program' => 'Tujuan program test',
    ]);
    $kegiatan = $pk01->kegiatan()->create(['nama_kegiatan' => 'Kegiatan Test', 'bulan' => 3]);
    $kegiatan->anggaran()->create([
        'kode_bidang' => 'B01',
        'kode_sub_bidang' => 'SB01',
        'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi',
        'deskripsi_pk' => 'Test anggaran',
        'nominal_anggaran' => 2500000,
    ]);
    $kegiatan->kuisioner()->create([
        'kode_kuisioner' => 'Q01',
        'pertanyaan' => 'Jumlah peserta',
        'tipe' => 'angka',
        'satuan' => 'orang',
    ]);

    // Submit PK01 → auto-creates PK02A + PK02B
    $engine = new WorkflowEngine;
    $ctx = ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id];

    $engine->recordAction(workflow: $pkWorkflow, step: 'PK01', action: 'submitted', userId: $user->id, sessionContext: $ctx, table: 'pk01_data', dataId: $pk01->id);
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02A', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02B', action: 'created', userId: null, sessionContext: []);

    return [$pkWorkflow->fresh(), $pk01->fresh()];
}

// ── PK02A Show ──

it('displays PK02A page for admin user with approve permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.show',
        'admin.workflows.pk.pk02a.approve',
        'admin.workflows.pk.pk02a.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.pk02a.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk02a')
            ->where('stepStatus', 'active')
            ->where('canApprove', true)
            ->where('canReject', true)
            ->where('scope', 'admin')
            ->has('pk01Data')
            ->where('pk01Data.nama_program', 'Program Test PK02')
            ->has('pk01Data.kegiatan', 1)
            ->has('pk01Data.kegiatan.0.anggaran', 1)
            ->has('parallelTrackStatus')
            ->where('parallelTrackStatus.step', 'PK02B')
            ->where('parallelTrackStatus.status', 'active')
        );
});

it('displays PK02A readonly for team scope', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk02a.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.pk02a.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk02a')
            ->where('canApprove', false)
            ->where('canReject', false)
            ->where('scope', 'team')
        );
});

// ── PK02A Approve ──

it('approves PK02A and waits for PK02B (silent)', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk02a.approve');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), [
        'notes' => 'Narasi sudah baik',
    ])->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    // PK02A completed, PK02B still active, PK03 not yet created
    expect($statuses['PK02A']['status'])->toBe('completed')
        ->and($statuses['PK02B']['status'])->toBe('active')
        ->and($statuses['PK03']['status'])->toBe('pending');

    // History has the approved entry with reviewed reference
    $approvedEntry = collect($pkWorkflow->history)->firstWhere('action', 'approved');
    expect($approvedEntry)->not->toBeNull()
        ->and($approvedEntry['step'])->toBe('PK02A')
        ->and($approvedEntry['reviewed']['pk01_data'])->toBeInt();
});

// ── PK02A Reject ──

it('rejects PK02A and waits for PK02B (silent)', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk02a.reject');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02a.reject', $pkWorkflow), [
        'notes' => 'Narasi perlu perbaikan',
    ])->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    // PK02A rejected but PK02B still active — no PK01 re-entry yet
    expect($statuses['PK02B']['status'])->toBe('active')
        ->and($statuses['PK01']['status'])->not->toBe('active');
});

it('requires notes on PK02A reject', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk02a.reject');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02a.reject', $pkWorkflow), [])
        ->assertSessionHasErrors(['notes']);
});

// ── PK02B Show ──

it('displays PK02B page with budget context for admin', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02b.show',
        'admin.workflows.pk.pk02b.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.pk02b.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk02b')
            ->where('stepStatus', 'active')
            ->where('canApprove', true)
            ->has('budgetContext')
            ->where('budgetContext.plafon', 50000000)
            ->where('parallelTrackStatus.step', 'PK02A')
            ->where('parallelTrackStatus.status', 'active')
        );
});

// ── PK02B Approve ──

it('approves PK02B and waits for PK02A (silent)', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk02b.approve');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02b.approve', $pkWorkflow), [
        'notes' => 'Anggaran wajar',
    ])->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    expect($statuses['PK02B']['status'])->toBe('completed')
        ->and($statuses['PK02A']['status'])->toBe('active')
        ->and($statuses['PK03']['status'])->toBe('pending');
});

// ── Fork/Join: Both Approved → PK03 ──

it('creates PK03 when both PK02A and PK02B approve', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.approve',
        'admin.workflows.pk.pk02b.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    // Approve PK02A first
    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'ok']);

    // Approve PK02B second → triggers join
    $this->post(route('admin.workflows.pk.pk02b.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    expect($statuses['PK02A']['status'])->toBe('completed')
        ->and($statuses['PK02B']['status'])->toBe('completed')
        ->and($statuses['PK03']['status'])->toBe('active');

    // PK03 created entry in history
    $pk03Created = collect($pkWorkflow->history)->where('step', 'PK03')->where('action', 'created')->first();
    expect($pk03Created)->not->toBeNull();
});

// ── Fork/Join: One Rejected, Other Approved → PK01 Re-entry ──

it('returns to PK01 when PK02A approves + PK02B rejects', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.approve',
        'admin.workflows.pk.pk02b.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    // Approve PK02A
    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'Narasi ok']);

    // Reject PK02B → both done, rejection wins → PK01 re-entry
    $this->post(route('admin.workflows.pk.pk02b.reject', $pkWorkflow), ['notes' => 'Nominal terlalu tinggi'])
        ->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    // PK01 re-entry: new PK01 created, PK03 not created
    expect($statuses['PK01']['status'])->toBe('active')
        ->and($statuses['PK01']['cycle'])->toBe(2)
        ->and($statuses['PK03']['status'])->toBe('pending');

    // New PK01 data created with copied data
    $allPk01 = \App\Models\Pk\Pk01Data::where('pk_workflow_id', $pkWorkflow->id)->orderBy('id')->get();
    expect($allPk01)->toHaveCount(2);

    $newPk01 = $allPk01->last();
    expect($newPk01->nama_program)->toBe('Program Test PK02')
        ->and($newPk01->kegiatan)->toHaveCount(1)
        ->and($newPk01->kegiatan->first()->anggaran)->toHaveCount(1)
        ->and($newPk01->kegiatan->first()->kuisioner)->toHaveCount(1);
});

it('returns to PK01 when both PK02A and PK02B reject', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.reject',
        'admin.workflows.pk.pk02b.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    // Reject PK02A
    $this->post(route('admin.workflows.pk.pk02a.reject', $pkWorkflow), ['notes' => 'Narasi buruk']);

    // Reject PK02B → both done, both rejected → PK01 re-entry
    $this->post(route('admin.workflows.pk.pk02b.reject', $pkWorkflow), ['notes' => 'Anggaran buruk'])
        ->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    expect($statuses['PK01']['status'])->toBe('active')
        ->and($statuses['PK01']['cycle'])->toBe(2);
});

it('returns to PK01 when PK02A rejects + PK02B approves', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.reject',
        'admin.workflows.pk.pk02b.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    // Reject PK02A first
    $this->post(route('admin.workflows.pk.pk02a.reject', $pkWorkflow), ['notes' => 'Narasi tidak memenuhi standar']);

    // Approve PK02B → both done, rejection wins
    $this->post(route('admin.workflows.pk.pk02b.approve', $pkWorkflow), ['notes' => 'Anggaran ok'])
        ->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    expect($statuses['PK01']['status'])->toBe('active')
        ->and($statuses['PK01']['cycle'])->toBe(2);
});

// ── Concurrent Approve ──

it('blocks concurrent approve on same PK02A step', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk02a.approve');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    // First approve succeeds
    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertRedirect();

    // Second approve should fail (step no longer active)
    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'duplicate'])
        ->assertStatus(409);
});

// ── Permission Enforcement ──

it('denies PK02A approve without approve permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk02a.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertForbidden();
});

it('denies PK02B reject without reject permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk02b.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02b.reject', $pkWorkflow), ['notes' => 'should fail'])
        ->assertForbidden();
});

it('denies PK02A approve from team scope', function () {
    // Team scope has no approve routes — posting to admin route without admin permission
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk02a.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertForbidden();
});

// ── PK02 show after completion ──

it('shows PK02A as readonly after approval', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.show',
        'admin.workflows.pk.pk02a.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    // Approve PK02A
    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'ok']);

    // Show should still work but canApprove/canReject = false
    $this->get(route('admin.workflows.pk.pk02a.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk02a')
            ->where('stepStatus', 'approved')
            ->where('canApprove', false)
            ->where('canReject', false)
        );
});

// ── Sanity Checks ──

it('rejects PK02A reject without notes', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.show',
        'admin.workflows.pk.pk02a.approve',
        'admin.workflows.pk.pk02a.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02a.reject', $pkWorkflow), ['notes' => ''])
        ->assertSessionHasErrors(['notes']);
});

it('rejects PK02B reject without notes', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02b.show',
        'admin.workflows.pk.pk02b.approve',
        'admin.workflows.pk.pk02b.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02b.reject', $pkWorkflow), ['notes' => ''])
        ->assertSessionHasErrors(['notes']);
});

it('blocks approve on pending PK02A step', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02a.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create PK workflow but do NOT submit PK01 — PK02A is still pending
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02a.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertStatus(409);
});

it('blocks approve on pending PK02B step', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk02b.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create PK workflow but do NOT submit PK01 — PK02B is still pending
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk02b.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertStatus(409);
});
