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
        'nama_program' => 'Program Kegiatan Kepemasaranan',
        'deskripsi_program' => 'Program pembinaan operasional untuk operasional.',
        'tujuan_program' => 'Meningkatkan partisipasi operasional dalam kegiatan.',
        'kegiatan' => [
            [
                'nama_kegiatan' => 'Lokakarya Operasional',
                'bulan' => 3,
                'anggaran' => [
                    [
                        'kode_bidang' => 'B01',
                        'kode_sub_bidang' => 'SB01',
                        'kode_jenis' => 'J01',
                        'mata_anggaran' => 'Konsumsi',
                        'deskripsi_pk' => 'Konsumsi peserta lokakarya',
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
        ->and($pk01->nama_program)->toBe('Program Kegiatan Kepemasaranan');

    // Kegiatan + anggaran + kuisioner saved
    $kegiatan = $pk01->kegiatan()->with(['anggaran', 'kuisioner'])->first();
    expect($kegiatan)->not->toBeNull()
        ->and($kegiatan->nama_kegiatan)->toBe('Lokakarya Operasional')
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
    $data['nama_program'] = 'Program Kegiatan Kepemasaranan (Revisi)'; // changed
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
        ->and($programChange['old'])->toBe('Program Kegiatan Kepemasaranan')
        ->and($programChange['new'])->toBe('Program Kegiatan Kepemasaranan (Revisi)');

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
            ->has('budgetCounter')
            ->where('budgetCounter.plafon', 50000000)
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

// ══════════════════════════════════════════════════════════════
//  PK03 — Approval RAKER (Join Point)
// ══════════════════════════════════════════════════════════════

/**
 * Setup PK workflow at PK03 active state.
 * Both PK02A + PK02B approved → PK03 auto-created.
 */
function setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role): array
{
    [$pkWorkflow, $pk01] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $engine = new WorkflowEngine;
    $ctx = ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id];

    // Approve PK02A
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02A', action: 'approved', userId: $user->id, sessionContext: $ctx, extra: [
        'reviewed' => ['pk01_data' => $pk01->id],
    ]);

    // Approve PK02B
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02B', action: 'approved', userId: $user->id, sessionContext: $ctx, extra: [
        'reviewed' => ['pk01_data' => $pk01->id],
    ]);

    // Create PK03 (simulates join gate outcome)
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK03', action: 'created', userId: null, sessionContext: []);

    return [$pkWorkflow->fresh(), $pk01->fresh()];
}

// ── PK03 Show ──

it('displays PK03 page for admin user with approve permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.show',
        'admin.workflows.pk.pk03.approve',
        'admin.workflows.pk.pk03.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.pk03.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk03')
            ->where('stepStatus', 'active')
            ->where('canApprove', true)
            ->where('canReject', true)
            ->has('pk01Data')
            ->has('parallelApprovals', 2)
        );
});

it('displays PK03 page as readonly for team user', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk03.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.pk03.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk03')
            ->where('stepStatus', 'active')
            ->where('canApprove', false)
            ->where('canReject', false)
        );
});

it('displays PK03 page as readonly for admin without approve permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.pk03.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk03')
            ->where('canApprove', false)
            ->where('canReject', false)
        );
});

// ── PK03 Approve → PK04 Compiled ──

it('compiles PK04 when PK03 approves', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'RAKER approved'])
        ->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    expect($statuses['PK03']['status'])->toBe('completed')
        ->and($statuses['PK04']['status'])->toBe('completed');

    // PK03 approved entry in history
    $pk03Approved = collect($pkWorkflow->history)->where('step', 'PK03')->where('action', 'approved')->first();
    expect($pk03Approved)->not->toBeNull()
        ->and($pk03Approved['reviewed']['pk01_data'])->toBe($pk01->id);

    // PK04 completed entry in history (compiled synchronously)
    $pk04Completed = collect($pkWorkflow->history)->where('step', 'PK04')->where('action', 'completed')->first();
    expect($pk04Completed)->not->toBeNull()
        ->and($pk04Completed['revision'])->toBe(0);
});

// ── PK03 Reject → PK01 Re-entry ──

it('returns to PK01 when PK03 rejects', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.show',
        'admin.workflows.pk.pk03.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.reject', $pkWorkflow), ['notes' => 'Program perlu revisi menyeluruh'])
        ->assertRedirect();

    $pkWorkflow->refresh();
    $engine = new WorkflowEngine;
    $definition = new PkWorkflowDefinition;
    $statuses = $engine->getStepStatuses($definition, $pkWorkflow->history);

    // PK01 re-entry, PK03 completed (rejected), PK04 still pending
    expect($statuses['PK01']['status'])->toBe('active')
        ->and($statuses['PK01']['cycle'])->toBe(2)
        ->and($statuses['PK04']['status'])->toBe('pending');

    // PK03 rejected entry in history with notes
    $pk03Rejected = collect($pkWorkflow->history)->where('step', 'PK03')->where('action', 'rejected')->last();
    expect($pk03Rejected)->not->toBeNull()
        ->and($pk03Rejected['notes'])->toBe('Program perlu revisi menyeluruh')
        ->and($pk03Rejected['by'])->toBe($user->id);

    // New PK01 data created with copied data
    $allPk01 = Pk01Data::where('pk_workflow_id', $pkWorkflow->id)->orderBy('id')->get();
    expect($allPk01)->toHaveCount(2);

    $newPk01 = $allPk01->last();
    expect($newPk01->nama_program)->toBe('Program Test PK02')
        ->and($newPk01->kegiatan)->toHaveCount(1)
        ->and($newPk01->kegiatan->first()->anggaran)->toHaveCount(1)
        ->and($newPk01->kegiatan->first()->kuisioner)->toHaveCount(1);

    // PK01 created entry for re-entry
    $pk01Created = collect($pkWorkflow->history)->where('step', 'PK01')->where('action', 'created')->last();
    expect($pk01Created['id'])->toBe($newPk01->id);
});

// ── PK03 Concurrent Approve Block ──

it('blocks concurrent approve on same PK03 step', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk03.approve');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // First approve succeeds
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertRedirect();

    // Second approve should fail (step no longer active)
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'duplicate'])
        ->assertStatus(409);
});

// ── PK03 Permission Enforcement ──

it('denies PK03 approve without approve permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk03.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertForbidden();
});

it('denies PK03 reject without reject permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk03.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.reject', $pkWorkflow), ['notes' => 'should fail'])
        ->assertForbidden();
});

it('denies PK03 approve from team scope user', function () {
    [$user, $role, $workspace, $team] = setupPkUser('team.workflows.pk.pk03.show');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertForbidden();
});

// ── PK03 Show After Completion ──

it('shows PK03 as readonly after approval', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // Approve PK03
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    // Show should still work but canApprove/canReject = false
    $this->get(route('admin.workflows.pk.pk03.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk03')
            ->where('stepStatus', 'approved')
            ->where('canApprove', false)
            ->where('canReject', false)
        );
});

// ── PK03 Sanity Checks ──

it('rejects PK03 reject without notes', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.show',
        'admin.workflows.pk.pk03.approve',
        'admin.workflows.pk.pk03.reject',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.reject', $pkWorkflow), ['notes' => ''])
        ->assertSessionHasErrors(['notes']);
});

it('blocks approve on pending PK03 step', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Setup PK at PK02 stage (PK03 not yet created)
    [$pkWorkflow] = setupPkAtPk02($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok'])
        ->assertStatus(409);
});

it('includes pp06_revision in PK03 history entry', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    $pkWorkflow->refresh();
    $pk03Approved = collect($pkWorkflow->history)->where('step', 'PK03')->where('action', 'approved')->first();
    expect($pk03Approved)->toHaveKey('pp06_revision');
});

// ══════════════════════════════════════════════════════════════
//  PK04 — Compile / Program Tahunan
// ══════════════════════════════════════════════════════════════

it('compiles PK04 with correct data when PK03 approves', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk01] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'RAKER approved'])
        ->assertRedirect();

    // PK04 row created with correct data
    $pk04 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)->first();
    expect($pk04)->not->toBeNull()
        ->and($pk04->revision)->toBe(0)
        ->and($pk04->kode_kategori)->toBe('K01')
        ->and($pk04->nama_program)->toBe('Program Test PK02')
        ->and($pk04->nomer_program)->toBe(1)
        ->and($pk04->verification_code)->not->toBeNull()
        ->and(strlen($pk04->verification_code))->toBe(8);

    // Kegiatan copied
    $pk04->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);
    expect($pk04->kegiatan)->toHaveCount(1);

    $kegiatan = $pk04->kegiatan->first();
    expect($kegiatan->nama_kegiatan)->toBe('Kegiatan Test')
        ->and($kegiatan->bulan)->toBe(3)
        ->and($kegiatan->nomer_kegiatan)->toBe(1)
        ->and($kegiatan->source)->toBe('pk');

    // Anggaran copied with kode
    expect($kegiatan->anggaran)->toHaveCount(1);
    $anggaran = $kegiatan->anggaran->first();
    expect($anggaran->mata_anggaran)->toBe('Konsumsi')
        ->and((float) $anggaran->nominal_anggaran)->toBe(2500000.0)
        ->and($anggaran->nomer_anggaran)->toBe(1)
        ->and($anggaran->revisi_terakhir)->toBe(0)
        ->and($anggaran->status_item)->toBe('active')
        ->and($anggaran->kode_anggaran_baru)->not->toBeNull()
        ->and($anggaran->kode_anggaran_lama)->not->toBeNull();

    // Kuisioner copied
    expect($kegiatan->kuisioner)->toHaveCount(1);
    $kuisioner = $kegiatan->kuisioner->first();
    expect($kuisioner->pertanyaan)->toBe('Jumlah peserta')
        ->and($kuisioner->kode_kuisioner)->toBe('Q01');

    // Author snapshot from PK01 submitter
    expect($pk04->pk01_created_by_user_name)->toBe($user->name);

    // History contains PK04 completed entry with revision=0
    $pkWorkflow->refresh();
    $pk04Completed = collect($pkWorkflow->history)->where('step', 'PK04')->where('action', 'completed')->first();
    expect($pk04Completed)->not->toBeNull()
        ->and($pk04Completed['revision'])->toBe(0)
        ->and($pk04Completed['id'])->toBe($pk04->id);
});

it('generates correct kode anggaran format', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk03.approve');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    $pk04 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)->first();
    $anggaran = $pk04->kegiatan->first()->anggaran->first();

    // Format Baru (13 segments): T.XX.XX.XX.XX.XX.XXX.XXX.XXX.XXXX.XX.revN.MN
    // Tipe.Bidang.SubBidang.Tim.Jenis.Kategori.Program.Kegiatan.Anggaran.Tahun.Bulan.Revisi.Tarik
    $kodeBaru = $anggaran->kode_anggaran_baru;
    $segments = explode('.', $kodeBaru);
    expect($segments)->toHaveCount(13)
        ->and($segments[0])->toBe('R')     // tipe (raker)
        ->and($segments[1])->toBe('B01')   // kode_bidang
        ->and($segments[2])->toBe('SB01')  // kode_sub_bidang
        ->and($segments[4])->toBe('J01')   // kode_jenis
        ->and($segments[9])->toBe('2027')  // tahun
        ->and($segments[10])->toBe('03')   // bulan
        ->and($segments[11])->toBe('rev0') // revisi
        ->and($segments[12])->toBe('M0');  // tarik maju

    // Format Lama: XX.XX.XX.XX.XX.XXX
    $kodeLama = $anggaran->kode_anggaran_lama;
    $lamaSegments = explode('.', $kodeLama);
    expect($lamaSegments)->toHaveCount(6);
});

it('generates deterministic verification code', function () {
    [$user, $role, $workspace, $team] = setupPkUser('admin.workflows.pk.pk03.approve');
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    $pk04 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)->first();
    $originalCode = $pk04->verification_code;

    // Re-generate should produce the same code
    $compileService = app(\App\Services\PkCompileService::class);
    $regeneratedCode = $compileService->generateVerificationCode($pk04);
    expect($regeneratedCode)->toBe($originalCode);
});

it('blocks compile when budget exceeds plafon for raker PK', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
        'team.workflows.pk.create',
        'team.workflows.pk.pk01.submit',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow, $pp06] = setupCompletedPp($workspace, $team);

    // Reduce plafon to a small amount
    $pp06->itemPlafonAnggaran()->update(['plafon_anggaran' => 100000]);

    [$pkWorkflow, $pk01] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // PK01 has 2,500,000 anggaran (from setupPkAtPk02) but plafon is only 100,000
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'approve'])
        ->assertSessionHasErrors(['approve']);

    // PK04 should NOT be created (transaction rolled back)
    $pk04Count = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)->count();
    expect($pk04Count)->toBe(0);

    // PK03 approved should also be rolled back
    $pkWorkflow->refresh();
    $pk03Approved = collect($pkWorkflow->history)->where('step', 'PK03')->where('action', 'approved')->first();
    expect($pk03Approved)->toBeNull();
});

it('assigns sequential nomer_program per team', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // First PK workflow
    [$pkWorkflow1] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow1), ['notes' => 'ok']);

    // Second PK workflow
    [$pkWorkflow2] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow2), ['notes' => 'ok']);

    $pk04_1 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow1->id)->first();
    $pk04_2 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow2->id)->first();

    expect($pk04_1->nomer_program)->toBe(1)
        ->and($pk04_2->nomer_program)->toBe(2);
});

// ── PK04 Show ──

it('displays PK04 page for admin user', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.comment',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // Compile PK04
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    $this->get(route('admin.workflows.pk.pk04.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk04')
            ->has('pk04Data')
            ->where('pk04Data.revision', 0)
            ->where('pk04Data.nama_program', 'Program Test PK02')
            ->has('pk04Data.kegiatan', 1)
            ->has('pk04Data.kegiatan.0.anggaran', 1)
            ->has('pk04Data.kegiatan.0.kuisioner', 1)
            ->where('pk04Data.verification_code', fn ($v) => is_string($v) && strlen($v) === 8)
            ->where('scope', 'admin')
            ->where('canComment', true)
        );
});

it('displays PK04 page for team user (readonly)', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
        'team.workflows.pk.pk04.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // Compile PK04
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    $this->get(route('team.workflows.pk.pk04.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk04')
            ->has('pk04Data')
            ->where('scope', 'team')
        );
});

it('shows empty state when PK04 not yet compiled', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk04.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // Don't approve PK03 — PK04 not yet compiled
    $this->get(route('admin.workflows.pk.pk04.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/pk04')
            ->where('pk04Data', null)
        );
});

it('displays budget context for raker PK04', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
        'admin.workflows.pk.pk04.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // Compile PK04
    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    $this->get(route('admin.workflows.pk.pk04.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('budgetContext')
            ->where('budgetContext.plafon', 50000000)
        );
});

// ── PK04 Show Permission ──

it('denies PK04 show without permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    // No pk04.show permission
    $this->get(route('admin.workflows.pk.pk04.show', $pkWorkflow))
        ->assertForbidden();
});

// ── PK04 Export ──

it('denies PK04 PDF export without permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
        'admin.workflows.pk.pk04.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    // No pk04.export.pdf permission
    $this->get(route('admin.workflows.pk.pk04.export.pdf', $pkWorkflow))
        ->assertForbidden();
});

it('denies PK04 Excel export without permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
        'admin.workflows.pk.pk04.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    // No pk04.export.excel permission
    $this->get(route('admin.workflows.pk.pk04.export.excel', $pkWorkflow))
        ->assertForbidden();
});

it('denies PK04 ZIP export without permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk03.approve',
        'admin.workflows.pk.pk04.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk03.approve', $pkWorkflow), ['notes' => 'ok']);

    // No pk04.export.zip permission
    $this->get(route('admin.workflows.pk.pk04.export.zip', $pkWorkflow))
        ->assertForbidden();
});

// ════════════════════════════════════════════════════════════
// PK05: Revisi Program Tahunan
// ════════════════════════════════════════════════════════════

/**
 * Setup PK at PK04 compiled state (revision 0).
 * Returns [$pkWorkflow, $pk04].
 */
function setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role): array
{
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    $engine = new WorkflowEngine;
    $ctx = ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id];

    // Approve PK03 → triggers PK04 compile
    $compileService = app(\App\Services\PkCompileService::class);
    $pk04 = $compileService->compile($pkWorkflow);

    $engine->recordAction(workflow: $pkWorkflow, step: 'PK03', action: 'approved', userId: $user->id, sessionContext: $ctx, notes: 'RAKER approved');
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK04', action: 'completed', userId: null, sessionContext: [], table: 'pk04_program_tahunan', dataId: $pk04->id, extra: ['revision' => 0]);

    $pk04->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);

    return [$pkWorkflow->fresh(), $pk04];
}

function validPk05DraftData(\App\Models\Pk\Pk04ProgramTahunan $pk04, string $expectedUpdatedAt, ?array $overrides = []): array
{
    $pk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner']);
    $kegiatan = $pk04->kegiatan->first();

    $draftData = [
        'kode_kategori' => $pk04->kode_kategori,
        'nama_program' => $overrides['nama_program'] ?? $pk04->nama_program,
        'deskripsi_program' => $pk04->deskripsi_program ?? '',
        'tujuan_program' => $pk04->tujuan_program ?? '',
        'kegiatan' => [
            [
                'pk04_kegiatan_id' => $kegiatan->id,
                'nama_kegiatan' => $kegiatan->nama_kegiatan,
                'bulan' => $kegiatan->bulan,
                'anggaran' => $kegiatan->anggaran->map(fn ($a) => [
                    'pk04_anggaran_id' => $a->id,
                    'kode_bidang' => $a->kode_bidang,
                    'kode_sub_bidang' => $a->kode_sub_bidang,
                    'kode_jenis' => $a->kode_jenis,
                    'mata_anggaran' => $a->mata_anggaran,
                    'deskripsi_pk' => $a->deskripsi_pk ?? '',
                    'nominal_anggaran' => $overrides['nominal_anggaran'] ?? (float) $a->nominal_anggaran,
                    'is_locked' => false,
                ])->all(),
                'kuisioner' => $kegiatan->kuisioner->map(fn ($q) => [
                    'pk04_kuisioner_id' => $q->id,
                    'kode_kuisioner' => $q->kode_kuisioner,
                    'pertanyaan' => $q->pertanyaan,
                    'tipe' => $q->tipe,
                    'satuan' => $q->satuan,
                ])->all(),
            ],
        ],
    ];

    return [
        'draft_data' => $draftData,
        'expected_updated_at' => $expectedUpdatedAt,
    ];
}

// ── PK05 Create ──

it('creates PK05 draft from PK04 compiled data', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow))
        ->assertRedirect();

    // PK05 row created
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();
    expect($pk05)->not->toBeNull()
        ->and($pk05->submitted_at)->toBeNull()
        ->and($pk05->draft_data)->toBeArray()
        ->and($pk05->draft_data['kode_kategori'])->toBe('K01')
        ->and($pk05->draft_data['kegiatan'])->toHaveCount(1)
        ->and($pk05->draft_data['kegiatan'][0]['anggaran'])->toHaveCount(1)
        ->and($pk05->draft_data['kegiatan'][0]['anggaran'][0]['pk04_anggaran_id'])->toBe($pk04->kegiatan->first()->anggaran->first()->id);

    // History entry
    $pkWorkflow->refresh();
    $pk05Entry = collect($pkWorkflow->history)->where('step', 'PK05')->where('action', 'created')->first();
    expect($pk05Entry)->not->toBeNull()
        ->and($pk05Entry['id'])->toBe($pk05->id);
});

it('blocks creating PK05 when no PK04 exists', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk03($workspace, $team, $ppWorkflow, $user, $role);

    // PK03 is active but PK04 not compiled yet
    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow))
        ->assertRedirect()
        ->assertSessionHasErrors('pk05');
});

it('blocks creating duplicate PK05 draft', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    // First PK05 → success
    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow))
        ->assertRedirect();

    // Second PK05 → redirects to existing draft
    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow))
        ->assertRedirect();

    // Still only one PK05
    expect(\App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->count())->toBe(1);
});

// ── PK05 Show ──

it('displays PK05 page in edit mode for admin with permissions', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    $this->get(route('admin.workflows.pk.pk05.show', [$pkWorkflow, $pk05]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/workflows/pk/pk05')
            ->where('mode', 'edit')
            ->where('canDraft', true)
            ->where('canSubmit', true)
            ->has('stepData')
            ->has('pp06Kodes')
            ->has('pp06Kodes.bidang')
            ->has('pp06Kodes.kategori')
            ->has('budgetContext')
            ->where('pkType', 'raker')
            ->has('actionRoles')
        );
});

it('displays PK05 readonly after submission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    // Submit it
    $submitData = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String());
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05]), $submitData);

    $pk05->refresh();
    $this->get(route('admin.workflows.pk.pk05.show', [$pkWorkflow, $pk05]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mode', 'readonly')
            ->where('canDraft', false)
            ->where('canSubmit', false)
        );
});

// ── PK05 Draft ──

it('saves PK05 draft data', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    $draftPayload = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String(), ['nama_program' => 'Revisi Program Kegiatan']);

    $this->post(route('admin.workflows.pk.pk05.draft', [$pkWorkflow, $pk05]), $draftPayload)
        ->assertRedirect();

    $pk05->refresh();
    expect($pk05->draft_data['nama_program'])->toBe('Revisi Program Kegiatan')
        ->and($pk05->submitted_at)->toBeNull();
});

it('returns 409 on stale PK05 draft', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    $draftPayload = validPk05DraftData($pk04, '2020-01-01T00:00:00+00:00');

    $this->post(route('admin.workflows.pk.pk05.draft', [$pkWorkflow, $pk05]), $draftPayload)
        ->assertStatus(409);
});

// ── PK05 Submit ──

it('submits PK05 and recompiles PK04 with new revision', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    // Submit with changed nama_program
    $submitData = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String(), ['nama_program' => 'Program Kegiatan Revisi']);
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05]), $submitData)
        ->assertRedirect();

    // PK05 marked as submitted
    $pk05->refresh();
    expect($pk05->submitted_at)->not->toBeNull();

    // New PK04 revision created
    $newPk04 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)
        ->orderByDesc('revision')
        ->first();
    expect($newPk04->revision)->toBe(1)
        ->and($newPk04->nama_program)->toBe('Program Kegiatan Revisi')
        ->and($newPk04->verification_code)->not->toBeNull();

    // Old PK04 snapshot preserved
    $oldPk04 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)
        ->where('revision', 0)
        ->first();
    expect($oldPk04)->not->toBeNull()
        ->and($oldPk04->nama_program)->toBe('Program Test PK02');

    // History: PK05 submitted + PK04 completed with revision=1
    $pkWorkflow->refresh();
    $pk05Submitted = collect($pkWorkflow->history)->where('step', 'PK05')->where('action', 'submitted')->first();
    expect($pk05Submitted)->not->toBeNull();

    $pk04Completed = collect($pkWorkflow->history)->where('step', 'PK04')->where('action', 'completed')->last();
    expect($pk04Completed['revision'])->toBe(1);
});

it('blocks PK05 submit after already submitted', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    $submitData = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String());
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05]), $submitData)
        ->assertRedirect();

    // Second submit → 409
    $pk05->refresh();
    $submitData2 = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String());
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05]), $submitData2)
        ->assertStatus(409);
});

it('blocks PK05 submit when budget exceeds plafon for raker PK', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow, $pp06] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    // Reduce plafon AFTER PK04 compile (so initial compile passes)
    $pp06->itemPlafonAnggaran()->update(['plafon_anggaran' => 100]);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    // Try submit with existing nominal (2,500,000 > 100)
    $submitData = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String());
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05]), $submitData)
        ->assertRedirect()
        ->assertSessionHasErrors('submit');
});

// ── PK05 Permissions ──

it('denies PK05 create without permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    // No pk05.create permission
    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow))
        ->assertForbidden();
});

it('denies PK05 draft without draft permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    // No pk05.draft permission
    $draftPayload = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String());
    $this->post(route('admin.workflows.pk.pk05.draft', [$pkWorkflow, $pk05]), $draftPayload)
        ->assertForbidden();
});

it('denies PK05 submit without submit permission', function () {
    // Has create+draft but NOT submit
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    // No pk05.submit permission
    $submitData = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String());
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05]), $submitData)
        ->assertForbidden();
});

// ── PK05 Recompile correctness ──

it('preserves snapshot of old PK04 after PK05 recompile', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);
    $originalAnggaranId = $pk04->kegiatan->first()->anggaran->first()->id;

    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05 = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    $submitData = validPk05DraftData($pk04, $pk05->updated_at->toIso8601String(), ['nominal_anggaran' => 3000000]);
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05]), $submitData);

    // Old pk04 (rev 0) still has snapshot kegiatan
    $oldPk04 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)
        ->where('revision', 0)->first();
    $oldPk04->load(['kegiatan.anggaran']);
    expect($oldPk04->kegiatan)->toHaveCount(1)
        ->and((float) $oldPk04->kegiatan->first()->anggaran->first()->nominal_anggaran)->toBe(2500000.0);

    // New pk04 (rev 1) has updated nominal
    $newPk04 = \App\Models\Pk\Pk04ProgramTahunan::where('pk_workflow_id', $pkWorkflow->id)
        ->where('revision', 1)->first();
    $newPk04->load(['kegiatan.anggaran']);
    expect((float) $newPk04->kegiatan->first()->anggaran->first()->nominal_anggaran)->toBe(3000000.0);

    // Live anggaran row ID preserved (C+CC: update in place)
    expect($newPk04->kegiatan->first()->anggaran->first()->id)->toBe($originalAnggaranId);
});

// ════════════════════════════════════════════════════════════
// Index Page
// ════════════════════════════════════════════════════════════

it('renders team index for user with permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.index',
        'team.workflows.pk.create',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/index')
            ->where('scope', 'team')
            ->has('workflows.data', 1)
            ->has('filters')
            ->has('availablePpPeriods')
            ->has('canCreate')
            ->has('eligiblePpWorkflows')
        );
});

it('renders admin index for user with permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.index',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/index')
            ->where('scope', 'admin')
            ->has('workflows.data', 1)
            ->has('availableTeams')
            ->has('filters.team')
        );
});

it('denies index without permission', function () {
    [$user, $role, $workspace] = setupPkUser();
    activatePkSession($this, $user, $role, $workspace);

    $this->get(route('team.workflows.pk.index'))->assertForbidden();
    $this->get(route('admin.workflows.pk.index'))->assertForbidden();
});

it('filters index by status', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.index',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Active PK → status=active should include it
    $this->get(route('team.workflows.pk.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflows.data', 1)
        );

    // Completed filter → should not include the active PK
    $this->get(route('team.workflows.pk.index', ['status' => 'completed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflows.data', 0)
        );
});

it('filters index by tipe', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.index',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Default is raker → tipe=raker should include it
    $this->get(route('team.workflows.pk.index', ['tipe' => 'raker']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // proposal → should not include raker PK
    $this->get(route('team.workflows.pk.index', ['tipe' => 'proposal']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('filters index by PP period', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.index',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // PP is tahun 2027 by default
    $this->get(route('team.workflows.pk.index', ['pp' => '2027']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    $this->get(route('team.workflows.pk.index', ['pp' => '9999']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('filters admin index by team', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.index',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Filter by correct team
    $this->get(route('admin.workflows.pk.index', ['team' => $team->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 1));

    // Filter by non-existent team
    $this->get(route('admin.workflows.pk.index', ['team' => '99999']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 0));
});

it('shows trash on index when toggled', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.index',
        'admin.workflows.pk.destroy',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $pkWorkflow->delete(); // Soft delete

    // Normal index should not show it
    $this->get(route('admin.workflows.pk.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 0));

    // Trash toggle shows it
    $this->get(route('admin.workflows.pk.index', ['trash' => '1']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 1));
});

it('team index only shows own team PKs', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.index',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create a PK for a different team
    $otherTeam = \App\Models\Team::factory()->create([
        'organization_id' => $team->organization_id,
    ]);
    PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $otherTeam->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $otherTeam->id,
        'created_by_org_id' => $role->team->organization->id,
        'history' => [['step' => 'PK01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => $otherTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()]],
    ]);

    // Create a PK for own team
    setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Team index should only show own PK
    $this->get(route('team.workflows.pk.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workflows.data', 1));
});

it('resolves create prerequisites when PP is complete and within pra-raker', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.index',
        'team.workflows.pk.create',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    $this->get(route('team.workflows.pk.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canCreate', true)
            ->has('eligiblePpWorkflows', 1)
            ->where('createMessage', null)
        );
});

it('shows createMessage when outside pra-raker window', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.index',
        'team.workflows.pk.create',
    );
    activatePkSession($this, $user, $role, $workspace);

    // PP with expired pra-raker window
    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [['step' => 'PP06', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()]],
    ]);
    Pp01Data::create(['pp_workflow_id' => $ppWorkflow->id, 'tahun' => 2027]);
    $pp06 = Pp06PeriodeTahunan::create([
        'pp_workflow_id' => $ppWorkflow->id,
        'revision' => 0,
        'tahun' => 2027,
        'tanggal_mulai_pra_raker' => now()->subDays(60)->toDateString(),
        'tanggal_penetapan_program' => now()->subDays(30)->toDateString(),
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

    $this->get(route('team.workflows.pk.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canCreate', false)
            ->where('eligiblePpWorkflows', [])
            ->where('createMessage', fn ($msg) => str_contains($msg, 'pra-raker'))
        );
});

// ════════════════════════════════════════════════════════════
// Show Page
// ════════════════════════════════════════════════════════════

it('renders team show page for active PK', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.show',
        'team.workflows.pk.comment',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/show')
            ->where('scope', 'team')
            ->where('workflow.status', 'active')
            ->has('informasi')
            ->where('informasi.tipe', 'raker')
            ->has('budgetCounter')
            ->where('dataTerbaru', null)
            ->where('canComment', true)
        );
});

it('renders admin show page for active PK', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workflows/pk/show')
            ->where('scope', 'admin')
            ->where('workflow.status', 'active')
            ->has('informasi')
            ->has('budgetCounter')
        );
});

it('denies show without permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser();
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.show', $pkWorkflow))->assertForbidden();
    $this->get(route('admin.workflows.pk.show', $pkWorkflow))->assertForbidden();
});

it('denies team show for another team PK', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create PK for different team
    $otherTeam = \App\Models\Team::factory()->create([
        'organization_id' => $team->organization_id,
    ]);
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $otherTeam->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'raker',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $otherTeam->id,
        'created_by_org_id' => $role->team->organization->id,
        'history' => [['step' => 'PK01', 'action' => 'created', 'by' => $user->id, 'role' => $role->id, 'team' => $otherTeam->id, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()]],
    ]);

    $this->get(route('team.workflows.pk.show', $pkWorkflow))->assertForbidden();
});

it('shows dataTerbaru after PK04 compile', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflow.status', 'completed')
            ->has('dataTerbaru')
            ->where('dataTerbaru.revision', 0)
            ->has('dataTerbaru.kegiatan')
        );
});

it('shows revising status when PK05 draft exists', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.show',
        'admin.workflows.pk.pk05.create',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    // Create PK05 draft (unsubmitted)
    \App\Models\Pk\Pk05Data::create([
        'pk_workflow_id' => $pkWorkflow->id,
        'pk04_program_tahunan_id' => $pk04->id,
    ]);

    $this->get(route('admin.workflows.pk.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workflow.status', 'revising')
            ->where('canRevise', false)
        );
});

it('hides budget counter for proposal PK', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create proposal PK (created by system, but model needs non-null created_by fields)
    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'proposal',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $role->team->organization->id,
        'history' => [['step' => 'PK01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()]],
    ]);
    Pk01Data::create(['pk_workflow_id' => $pkWorkflow->id]);

    $this->get(route('team.workflows.pk.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('budgetCounter', null)
            ->where('informasi.tipe', 'proposal')
        );
});

it('shows canTerminate for active PK with permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.show',
        'team.workflows.pk.terminate',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('team.workflows.pk.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canTerminate', true)
            ->where('canRevise', false)
            ->where('canDelete', false)
        );
});

it('shows canRevise for completed PK with admin pk05.create permission', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.show',
        'admin.workflows.pk.pk05.create',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $this->get(route('admin.workflows.pk.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canRevise', true)
            ->where('canTerminate', false)
        );
});

// ── Budget Counter: proposalReview & proposalDraft ──

function createProposalPkAtPk01($workspace, $team, $ppWorkflow, $user, $role, float $nominal = 1000000): PkWorkflow
{
    $engine = new WorkflowEngine;
    $ctx = ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id];

    $pkWorkflow = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'proposal',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $role->team->organization->id,
        'history' => [],
    ]);

    $pk01 = Pk01Data::create(['pk_workflow_id' => $pkWorkflow->id, 'kode_kategori' => 'K01', 'nama_program' => 'Proposal Draft']);
    $kegiatan = $pk01->kegiatan()->create(['nama_kegiatan' => 'Kegiatan Proposal', 'bulan' => 6]);
    $kegiatan->anggaran()->create([
        'kode_bidang' => 'B01', 'kode_sub_bidang' => 'SB01', 'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi', 'deskripsi_pk' => 'Anggaran proposal', 'nominal_anggaran' => $nominal,
    ]);

    $engine->recordAction(workflow: $pkWorkflow, step: 'PK01', action: 'created', userId: $user->id, sessionContext: $ctx, table: 'pk01_data', dataId: $pk01->id);

    return $pkWorkflow->fresh();
}

function createProposalPkAtPk02($workspace, $team, $ppWorkflow, $user, $role, float $nominal = 2000000): PkWorkflow
{
    $pkWorkflow = createProposalPkAtPk01($workspace, $team, $ppWorkflow, $user, $role, $nominal);
    $pk01 = $pkWorkflow->latestPk01();

    $engine = new WorkflowEngine;
    $ctx = ['role' => $role->id, 'team' => $team->id, 'org' => $role->team->organization->id, 'workspace' => $workspace->id];

    $engine->recordAction(workflow: $pkWorkflow, step: 'PK01', action: 'submitted', userId: $user->id, sessionContext: $ctx, table: 'pk01_data', dataId: $pk01->id);
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02A', action: 'created', userId: null, sessionContext: []);
    $engine->recordAction(workflow: $pkWorkflow, step: 'PK02B', action: 'created', userId: null, sessionContext: []);

    return $pkWorkflow->fresh();
}

it('returns zero proposalReview and proposalDraft when no proposals exist', function () {
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
            ->where('budgetCounter.proposalAccepted', 0)
            ->where('budgetCounter.proposalReview', 0)
            ->where('budgetCounter.proposalDraft', 0)
        );
});

it('counts proposalDraft from proposal PKs at PK01 step', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
        'team.workflows.pk.pk01.submit',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create a raker PK (the one we'll view)
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Create two proposal PKs at PK01 (draft stage)
    createProposalPkAtPk01($workspace, $team, $ppWorkflow, $user, $role, 1500000);
    createProposalPkAtPk01($workspace, $team, $ppWorkflow, $user, $role, 500000);

    $this->get(route('team.workflows.pk.pk01.show', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('budgetCounter.proposalDraft', 2000000)
            ->where('budgetCounter.proposalReview', 0)
        );
});

it('counts proposalReview from proposal PKs at PK02A/PK02B step', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
        'team.workflows.pk.pk01.submit',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create a raker PK (the one we'll view)
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Create proposal PKs: one at PK02 (review), one at PK01 (draft)
    createProposalPkAtPk02($workspace, $team, $ppWorkflow, $user, $role, 3000000);
    createProposalPkAtPk01($workspace, $team, $ppWorkflow, $user, $role, 750000);

    $this->get(route('team.workflows.pk.pk01.show', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('budgetCounter.proposalReview', 3000000)
            ->where('budgetCounter.proposalDraft', 750000)
            ->where('budgetCounter.proposalAccepted', 0)
        );
});

it('keeps proposalAccepted correct alongside proposalReview and proposalDraft', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'team.workflows.pk.pk01.show',
        'team.workflows.pk.pk01.draft',
        'team.workflows.pk.pk01.submit',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);

    // Create a raker PK (the one we'll view)
    [$pkWorkflow, $pk01] = setupPkWorkflow($workspace, $team, $ppWorkflow, $user, $role);

    // Create a completed proposal PK (at PK04) — manually create PK04 anggaran
    $completedProposal = PkWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'tipe' => 'proposal',
        'created_by_user_id' => $user->id,
        'created_by_role_id' => $role->id,
        'created_by_team_id' => $team->id,
        'created_by_org_id' => $role->team->organization->id,
        'history' => [
            ['step' => 'PK04', 'action' => 'completed', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    $pk04Pt = \App\Models\Pk\Pk04ProgramTahunan::create([
        'pk_workflow_id' => $completedProposal->id,
        'kode_kategori' => 'K01',
        'nama_program' => 'Proposal Completed',
        'deskripsi_program' => 'Deskripsi proposal selesai',
        'tujuan_program' => 'Tujuan proposal selesai',
        'nomer_program' => 1,
        'revision' => 0,
        'pk01_created_by_user_name' => 'Test User',
        'pk01_created_by_role_name' => 'Test Role',
        'pk01_created_by_team_name' => 'Test Team',
        'pk01_created_by_organization_name' => 'Test Org',
        'pk01_created_by_workspace_name' => 'Test WS',
        'pk01_created_at' => now(),
    ]);
    $pk04Keg = $pk04Pt->kegiatan()->create(['nama_kegiatan' => 'Kegiatan Selesai', 'bulan' => 4, 'nomer_kegiatan' => 1]);
    $pk04Keg->anggaran()->create([
        'kode_bidang' => 'B01', 'kode_sub_bidang' => 'SB01', 'kode_jenis' => 'J01',
        'mata_anggaran' => 'Konsumsi', 'deskripsi_pk' => 'Anggaran proposal selesai',
        'nominal_anggaran' => 5000000, 'nomer_anggaran' => 1, 'status_item' => 'active',
    ]);

    // Create in-progress proposal PKs
    createProposalPkAtPk02($workspace, $team, $ppWorkflow, $user, $role, 2000000);
    createProposalPkAtPk01($workspace, $team, $ppWorkflow, $user, $role, 1000000);

    $this->get(route('team.workflows.pk.pk01.show', ['pkWorkflow' => $pkWorkflow, 'pk01Data' => $pk01]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('budgetCounter.proposalAccepted', 5000000)
            ->where('budgetCounter.proposalReview', 2000000)
            ->where('budgetCounter.proposalDraft', 1000000)
        );
});

// ════════════════════════════════════════════════════════════
// PK05: Tier 3 Realisasi Lock (PRBL05 compiled items frozen)
// ════════════════════════════════════════════════════════════

it('treats anggaran with prbl05_item_realisasi as Tier 3 locked in PK05', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $anggaran = $pk04->kegiatan->first()->anggaran->first();

    // Create dummy PABD + PRBL workflows for the FK
    $pabdWorkflow = \App\Models\Pabd\PabdWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 3,
        'tahun_anggaran' => now()->year,
        'history' => [],
    ]);
    $prblWorkflow = \App\Models\Prbl\PrblWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => 3,
        'tahun_laporan' => now()->year,
        'history' => [],
    ]);

    // Simulate PRBL05 compile having snapshotted this anggaran
    $prbl05 = \App\Models\Prbl\Prbl05LaporanBulanan::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'prbl01_created_by_user_name' => 'Test',
        'prbl01_created_by_role_name' => 'Test',
        'prbl01_created_by_team_name' => 'Test',
        'prbl01_created_by_organization_name' => 'Test',
        'prbl01_created_by_workspace_name' => 'Test',
        'prbl01_created_at' => now(),
        'prbl02a_approved_by_user_name' => 'Test',
        'prbl02a_approved_by_role_name' => 'Test',
        'prbl02a_approved_by_team_name' => 'Test',
        'prbl02a_approved_by_organization_name' => 'Test',
        'prbl02a_approved_by_workspace_name' => 'Test',
        'prbl02a_approved_at' => now(),
        'prbl02b_approved_by_user_name' => 'Test',
        'prbl02b_approved_by_role_name' => 'Test',
        'prbl02b_approved_by_team_name' => 'Test',
        'prbl02b_approved_by_organization_name' => 'Test',
        'prbl02b_approved_by_workspace_name' => 'Test',
        'prbl02b_approved_at' => now(),
        'prbl03_created_by_user_name' => 'Test',
        'prbl03_created_by_role_name' => 'Test',
        'prbl03_created_by_team_name' => 'Test',
        'prbl03_created_by_organization_name' => 'Test',
        'prbl03_created_by_workspace_name' => 'Test',
        'prbl03_created_at' => now(),
        'prbl04_approved_by_user_name' => 'Test',
        'prbl04_approved_by_role_name' => 'Test',
        'prbl04_approved_by_team_name' => 'Test',
        'prbl04_approved_by_organization_name' => 'Test',
        'prbl04_approved_by_workspace_name' => 'Test',
        'prbl04_approved_at' => now(),
        'total_anggaran_dicairkan' => 5000000,
        'total_realisasi' => 4500000,
        'total_refund' => 500000,
        'total_item' => 1,
    ]);

    \App\Models\Prbl\Prbl05ItemRealisasi::create([
        'prbl05_laporan_bulanan_id' => $prbl05->id,
        'pk04_anggaran_id' => $anggaran->id,
        'nominal_anggaran' => $anggaran->nominal_anggaran,
        'nominal_realisasi' => 4500000,
        'selisih' => (float) $anggaran->nominal_anggaran - 4500000,
    ]);

    // hasRealisasiLock() should return true
    expect($anggaran->hasRealisasiLock())->toBeTrue();

    // PK05 show should report this anggaran as locked with 'sudah_dilaporkan'
    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05Data = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    $this->get(route('admin.workflows.pk.pk05.show', [$pkWorkflow, $pk05Data]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stepData.draft_data.kegiatan.0.anggaran.0.is_locked', true)
            ->where('stepData.draft_data.kegiatan.0.anggaran.0.lock_reason', 'sudah_dilaporkan')
        );
});

it('shows Tier 3 realisasi lock indicators on PK04 show page', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk04.show',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $anggaran = $pk04->kegiatan->first()->anggaran->first();

    // Create dummy PABD + PRBL + PRBL05 with item referencing this anggaran
    $pabdWorkflow = \App\Models\Pabd\PabdWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 3,
        'tahun_anggaran' => now()->year,
        'history' => [],
    ]);
    $prblWorkflow = \App\Models\Prbl\PrblWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => 3,
        'tahun_laporan' => now()->year,
        'history' => [],
    ]);
    $prbl05 = \App\Models\Prbl\Prbl05LaporanBulanan::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'prbl01_created_by_user_name' => 'Test',
        'prbl01_created_by_role_name' => 'Test',
        'prbl01_created_by_team_name' => 'Test',
        'prbl01_created_by_organization_name' => 'Test',
        'prbl01_created_by_workspace_name' => 'Test',
        'prbl01_created_at' => now(),
        'prbl02a_approved_by_user_name' => 'Test',
        'prbl02a_approved_by_role_name' => 'Test',
        'prbl02a_approved_by_team_name' => 'Test',
        'prbl02a_approved_by_organization_name' => 'Test',
        'prbl02a_approved_by_workspace_name' => 'Test',
        'prbl02a_approved_at' => now(),
        'prbl02b_approved_by_user_name' => 'Test',
        'prbl02b_approved_by_role_name' => 'Test',
        'prbl02b_approved_by_team_name' => 'Test',
        'prbl02b_approved_by_organization_name' => 'Test',
        'prbl02b_approved_by_workspace_name' => 'Test',
        'prbl02b_approved_at' => now(),
        'prbl03_created_by_user_name' => 'Test',
        'prbl03_created_by_role_name' => 'Test',
        'prbl03_created_by_team_name' => 'Test',
        'prbl03_created_by_organization_name' => 'Test',
        'prbl03_created_by_workspace_name' => 'Test',
        'prbl03_created_at' => now(),
        'prbl04_approved_by_user_name' => 'Test',
        'prbl04_approved_by_role_name' => 'Test',
        'prbl04_approved_by_team_name' => 'Test',
        'prbl04_approved_by_organization_name' => 'Test',
        'prbl04_approved_by_workspace_name' => 'Test',
        'prbl04_approved_at' => now(),
        'total_anggaran_dicairkan' => 5000000,
        'total_realisasi' => 4500000,
        'total_refund' => 500000,
        'total_item' => 1,
    ]);
    \App\Models\Prbl\Prbl05ItemRealisasi::create([
        'prbl05_laporan_bulanan_id' => $prbl05->id,
        'pk04_anggaran_id' => $anggaran->id,
        'nominal_anggaran' => $anggaran->nominal_anggaran,
        'nominal_realisasi' => 4500000,
        'selisih' => (float) $anggaran->nominal_anggaran - 4500000,
    ]);

    // PK04 show should report this anggaran as locked with 'sudah_dilaporkan'
    $this->get(route('admin.workflows.pk.pk04.show', $pkWorkflow))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pk04Data.kegiatan.0.anggaran.0.is_locked', true)
            ->where('pk04Data.kegiatan.0.anggaran.0.lock_reason', 'sudah_dilaporkan')
        );
});

it('blocks nominal changes on Tier 3 locked anggaran during PK05 recompile', function () {
    [$user, $role, $workspace, $team] = setupPkUser(
        'admin.workflows.pk.pk05.show',
        'admin.workflows.pk.pk05.create',
        'admin.workflows.pk.pk05.draft',
        'admin.workflows.pk.pk05.submit',
        'admin.workflows.pk.pk04.show',
        'admin.workflows.pk.pk03.approve',
    );
    activatePkSession($this, $user, $role, $workspace);
    [$ppWorkflow] = setupCompletedPp($workspace, $team);
    [$pkWorkflow, $pk04] = setupPkAtPk04($workspace, $team, $ppWorkflow, $user, $role);

    $anggaran = $pk04->kegiatan->first()->anggaran->first();
    $originalNominal = (float) $anggaran->nominal_anggaran;

    // Create dummy PABD + PRBL workflows for the FK
    $pabdWorkflow2 = \App\Models\Pabd\PabdWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 3,
        'tahun_anggaran' => now()->year,
        'history' => [],
    ]);
    $prblWorkflow = \App\Models\Prbl\PrblWorkflow::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pabd_workflow_id' => $pabdWorkflow2->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_laporan' => 3,
        'tahun_laporan' => now()->year,
        'history' => [],
    ]);

    // Simulate PRBL05 compile
    $prbl05 = \App\Models\Prbl\Prbl05LaporanBulanan::create([
        'prbl_workflow_id' => $prblWorkflow->id,
        'prbl01_created_by_user_name' => 'Test', 'prbl01_created_by_role_name' => 'Test',
        'prbl01_created_by_team_name' => 'Test', 'prbl01_created_by_organization_name' => 'Test',
        'prbl01_created_by_workspace_name' => 'Test', 'prbl01_created_at' => now(),
        'prbl02a_approved_by_user_name' => 'Test', 'prbl02a_approved_by_role_name' => 'Test',
        'prbl02a_approved_by_team_name' => 'Test', 'prbl02a_approved_by_organization_name' => 'Test',
        'prbl02a_approved_by_workspace_name' => 'Test', 'prbl02a_approved_at' => now(),
        'prbl02b_approved_by_user_name' => 'Test', 'prbl02b_approved_by_role_name' => 'Test',
        'prbl02b_approved_by_team_name' => 'Test', 'prbl02b_approved_by_organization_name' => 'Test',
        'prbl02b_approved_by_workspace_name' => 'Test', 'prbl02b_approved_at' => now(),
        'prbl03_created_by_user_name' => 'Test', 'prbl03_created_by_role_name' => 'Test',
        'prbl03_created_by_team_name' => 'Test', 'prbl03_created_by_organization_name' => 'Test',
        'prbl03_created_by_workspace_name' => 'Test', 'prbl03_created_at' => now(),
        'prbl04_approved_by_user_name' => 'Test', 'prbl04_approved_by_role_name' => 'Test',
        'prbl04_approved_by_team_name' => 'Test', 'prbl04_approved_by_organization_name' => 'Test',
        'prbl04_approved_by_workspace_name' => 'Test', 'prbl04_approved_at' => now(),
        'total_anggaran_dicairkan' => 5000000, 'total_realisasi' => 4500000,
        'total_refund' => 500000, 'total_item' => 1,
    ]);

    \App\Models\Prbl\Prbl05ItemRealisasi::create([
        'prbl05_laporan_bulanan_id' => $prbl05->id,
        'pk04_anggaran_id' => $anggaran->id,
        'nominal_anggaran' => $originalNominal,
        'nominal_realisasi' => 4500000,
        'selisih' => $originalNominal - 4500000,
    ]);

    // Create PK05 draft
    $this->post(route('admin.workflows.pk.pk05.create', $pkWorkflow));
    $pk05Data = \App\Models\Pk\Pk05Data::where('pk_workflow_id', $pkWorkflow->id)->first();

    // Attempt to change nominal on locked anggaran
    $submitData = validPk05DraftData($pk04, $pk05Data->updated_at->toIso8601String(), ['nominal_anggaran' => 9999999]);
    $this->post(route('admin.workflows.pk.pk05.submit', [$pkWorkflow, $pk05Data]), $submitData)
        ->assertRedirect();

    // Nominal should NOT have changed — Tier 3 lock prevents edits
    $anggaran->refresh();
    expect((float) $anggaran->nominal_anggaran)->toBe($originalNominal);
});
