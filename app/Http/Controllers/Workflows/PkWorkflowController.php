<?php

namespace App\Http\Controllers\Workflows;

use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\Pk01DraftRequest;
use App\Http\Requests\Workflows\Pk01SubmitRequest;
use App\Http\Requests\Workflows\PkCommentRequest;
use App\Http\Requests\Workflows\PkTerminateRequest;
use App\Models\Permission;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Role;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PkWorkflowController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private ActiveSessionService $session,
        private HistoryFormatter $historyFormatter,
        private CommentService $commentService,
        private WorkflowNotifier $notifier,
    ) {}

    // ──────────────────────────────────────────────────────────
    //  Workflow-level actions
    // ──────────────────────────────────────────────────────────

    public function create(Request $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $this->checkPermission('team.workflows.pk.create');

        $workspaceId = $this->session->getActiveWorkspaceId();
        $role = $this->getActiveRole();
        $team = $role?->team;
        $org = $team?->organization;

        if (! $team) {
            abort(403, 'Anda tidak memiliki tim aktif.');
        }

        if ($ppWorkflow->workspace_id !== $workspaceId) {
            abort(403, 'PP workflow bukan milik workspace aktif.');
        }

        // PP must be complete
        $ppStatus = $this->engine->getWorkflowStatus($ppWorkflow->history ?? []);
        if ($ppStatus !== 'completed') {
            abort(403, 'PP belum selesai dikompilasi.');
        }

        $pp06 = $ppWorkflow->latestPp06();
        if (! $pp06) {
            abort(403, 'PP06 tidak ditemukan.');
        }

        // Team must have plafon
        if (! $pp06->itemPlafonAnggaran()->where('team_id', $team->id)->exists()) {
            abort(403, 'Tim Anda tidak memiliki plafon dalam PP ini.');
        }

        // Pra-raker window
        $now = now();
        if ($pp06->tanggal_mulai_pra_raker && $now->lt($pp06->tanggal_mulai_pra_raker)) {
            abort(403, 'Diluar periode pra-raker.');
        }
        if ($pp06->tanggal_penetapan_program && $now->gt($pp06->tanggal_penetapan_program)) {
            abort(403, 'Diluar periode pra-raker.');
        }

        $workflow = PkWorkflow::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'team_id' => $team->id,
            'pp_workflow_id' => $ppWorkflow->id,
            'tipe' => 'raker',
            'created_by_user_id' => $request->user()->id,
            'created_by_role_id' => $role->id,
            'created_by_team_id' => $team->id,
            'created_by_org_id' => $org->id,
            'history' => [],
        ]);

        $pk01 = Pk01Data::create([
            'pk_workflow_id' => $workflow->id,
        ]);

        $this->engine->recordAction(
            workflow: $workflow,
            step: 'PK01',
            action: 'created',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pk01_data',
            dataId: $pk01->id,
        );

        return to_route('team.workflows.pk.pk01.show', [
            'pkWorkflow' => $workflow->id,
            'pk01Data' => $pk01->id,
        ])->with('success', 'PK berhasil dibuat.');
    }

    public function comment(PkCommentRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.comment");

        $validated = $request->validated();

        $this->commentService->store(
            workflow: $pkWorkflow,
            source: $validated['source'],
            notes: $validated['notes'],
            uploadedFiles: $request->file('files', []),
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            workflowPrefix: 'pk',
        );

        return back()->with('success', 'Komentar berhasil ditambahkan.');
    }

    public function terminate(PkTerminateRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.terminate");

        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $history = $pkWorkflow->history ?? [];

        if ($this->engine->getWorkflowStatus($history) !== 'active') {
            return back()->withErrors(['terminate' => 'Workflow tidak dalam status aktif.']);
        }

        // Blocked after PK04 compiled
        $statuses = $this->engine->getStepStatuses($definition, $history);
        if (($statuses['PK04']['status'] ?? 'pending') === 'completed') {
            return back()->withErrors(['terminate' => 'PK tidak dapat dibatalkan setelah PK04 selesai.']);
        }

        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $activeStep = $currentSteps[0] ?? 'PK01';

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pkWorkflow,
            "pk.{$activeStep}.terminate",
            $request->user()->id,
            $this->getSessionContext(),
        );

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: $activeStep,
            action: 'terminated',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            notes: $request->input('notes'),
            files: ! empty($fileIds) ? $fileIds : null,
        );

        $this->notifier->notify($pkWorkflow, "{$activeStep}.terminated", [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        $showRoute = $scope === 'team'
            ? route('team.workflows.pk.show', $pkWorkflow)
            : route('admin.workflows.pk.show', $pkWorkflow);

        return redirect($showRoute)->with('success', 'Workflow berhasil dibatalkan.');
    }

    public function destroy(PkWorkflow $pkWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission('admin.workflows.pk.destroy');

        if ($this->engine->getWorkflowStatus($pkWorkflow->history ?? []) !== 'terminated') {
            return back()->withErrors(['delete' => 'Hanya workflow yang sudah dibatalkan yang bisa dihapus.']);
        }

        $pkWorkflow->delete();

        return to_route('admin.workflows.pk.index')->with('success', 'Workflow berhasil dihapus.');
    }

    // ──────────────────────────────────────────────────────────
    //  PK01 — Program Kegiatan
    // ──────────────────────────────────────────────────────────

    public function pk01Show(PkWorkflow $pkWorkflow, Pk01Data $pk01Data): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.pk01.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $history = $pkWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Resolve mode
        $mode = $this->resolveMode($statuses, 'PK01');
        if (($statuses['PK01']['dataId'] ?? null) !== null && $statuses['PK01']['dataId'] !== $pk01Data->id) {
            $mode = 'readonly';
        }
        if ($scope === 'admin') {
            $mode = 'readonly';
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.pk";
        $isEditable = in_array($mode, ['edit', 'create']) && $scope === 'team';
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';
        $isPp07Active = $this->isPp07Active($pkWorkflow);

        // Load PP06 reference data
        $pp06 = $this->getLatestPp06($pkWorkflow);
        $kodeRefs = $this->loadKodeReferences($pp06);

        // Budget counter
        $budgetCounter = $this->getBudgetCounters($pkWorkflow);

        // Kegiatan with nested relations
        $kegiatan = $pk01Data->kegiatan()
            ->with(['anggaran', 'kuisioner'])
            ->orderBy('id')
            ->get();

        // Labels
        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun;
        $label = "PK-{$teamName}-{$tahun}";

        $basePath = $scope === 'team'
            ? "/team/workflows/pk/{$pkWorkflow->id}"
            : "/admin/workflows/pk/{$pkWorkflow->id}";

        // Rejection re-entry check
        $isRejection = ($statuses['PK01']['cycle'] ?? 1) > 1
            && ($statuses['PK01']['status'] ?? '') === 'active';

        return Inertia::render('workflows/pk/pk01', [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'tipe' => $pkWorkflow->tipe,
            ],
            'stepData' => [
                'id' => $pk01Data->id,
                'kode_kategori' => $pk01Data->kode_kategori,
                'nama_program' => $pk01Data->nama_program,
                'deskripsi_program' => $pk01Data->deskripsi_program,
                'tujuan_program' => $pk01Data->tujuan_program,
                'kegiatan' => $kegiatan->map(fn ($k) => [
                    'id' => $k->id,
                    'nama_kegiatan' => $k->nama_kegiatan,
                    'bulan' => $k->bulan,
                    'anggaran' => $k->anggaran->map(fn ($a) => [
                        'id' => $a->id,
                        'kode_bidang' => $a->kode_bidang,
                        'kode_sub_bidang' => $a->kode_sub_bidang,
                        'kode_jenis' => $a->kode_jenis,
                        'mata_anggaran' => $a->mata_anggaran,
                        'deskripsi_pk' => $a->deskripsi_pk,
                        'nominal_anggaran' => (float) $a->nominal_anggaran,
                    ])->values(),
                    'kuisioner' => $k->kuisioner->map(fn ($q) => [
                        'id' => $q->id,
                        'kode_kuisioner' => $q->kode_kuisioner,
                        'pertanyaan' => $q->pertanyaan,
                        'tipe' => $q->tipe,
                        'satuan' => $q->satuan,
                    ])->values(),
                ])->values(),
                'updated_at' => $pk01Data->updated_at->toIso8601String(),
            ],
            'kodeKategori' => $kodeRefs['kategori'],
            'kodeBidang' => $kodeRefs['bidang'],
            'kodeSubBidang' => $kodeRefs['subBidang'],
            'kodeJenis' => $kodeRefs['jenis'],
            'kuisionerTemplates' => $kodeRefs['kuisioner'],
            'budgetCounter' => $budgetCounter,
            'mode' => $mode,
            'canDraft' => $isEditable && in_array("{$permPrefix}.pk01.draft", $permissions),
            'canSubmit' => $isEditable && in_array("{$permPrefix}.pk01.submit", $permissions) && ! $isPp07Active,
            'canTerminate' => $isWorkflowActive && in_array("{$permPrefix}.terminate", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'isRejectionReentry' => $isRejection,
            'rejectionNotes' => $isRejection ? $this->getPkRejectionNotes($history) : null,
            'isPp07Active' => $isPp07Active,
            'actionRoles' => $this->resolveActionRoles([
                'team.workflows.pk.pk01.draft' => ['Simpan Draft', false],
                'team.workflows.pk.pk01.submit' => ['Submit', true],
                "{$permPrefix}.comment" => ['Komentar', false],
                "{$permPrefix}.terminate" => ['Batalkan Workflow', true],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function pk01Draft(Pk01DraftRequest $request, PkWorkflow $pkWorkflow, Pk01Data $pk01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->ensureTeamOwnership($pkWorkflow);
        $this->checkPermission('team.workflows.pk.pk01.draft');
        $this->ensureStepActive($pkWorkflow, 'PK01');
        $this->ensureCurrentRecord($pkWorkflow, 'PK01', $pk01Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($pk01Data, $validated['expected_updated_at']);

        $pk01Data->update([
            'kode_kategori' => $validated['kode_kategori'] ?? null,
            'nama_program' => $validated['nama_program'] ?? null,
            'deskripsi_program' => $validated['deskripsi_program'] ?? null,
            'tujuan_program' => $validated['tujuan_program'] ?? null,
        ]);

        $this->syncKegiatan($pk01Data, $validated['kegiatan'] ?? [], isDraft: true);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pk01Data,
            'pk.pk01.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: 'PK01',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pk01_data',
            dataId: $pk01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pkWorkflow)],
        );

        return to_route('team.workflows.pk.show', $pkWorkflow)->with('success', 'Draft PK01 berhasil disimpan.');
    }

    public function pk01Submit(Pk01SubmitRequest $request, PkWorkflow $pkWorkflow, Pk01Data $pk01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->ensureTeamOwnership($pkWorkflow);
        $this->checkPermission('team.workflows.pk.pk01.submit');

        $validated = $request->validated();

        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $history = $pkWorkflow->history ?? [];
        $currentSteps = $this->engine->getCurrentSteps($definition, $history);

        if (! in_array('PK01', $currentSteps)) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->ensureCurrentRecord($pkWorkflow, 'PK01', $pk01Data->id);
        $this->checkOptimisticLock($pk01Data, $validated['expected_updated_at']);

        // PP07 active → submit blocked
        if ($this->isPp07Active($pkWorkflow)) {
            return back()->withErrors(['submit' => 'PP sedang dalam proses revisi. Anda tidak dapat submit sampai revisi PP selesai.']);
        }

        $pk01Data->update([
            'kode_kategori' => $validated['kode_kategori'],
            'nama_program' => $validated['nama_program'],
            'deskripsi_program' => $validated['deskripsi_program'],
            'tujuan_program' => $validated['tujuan_program'],
        ]);

        $this->syncKegiatan($pk01Data, $validated['kegiatan'], isDraft: false);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pk01Data,
            'pk.pk01.submit',
            $request->user()->id,
            $sessionContext,
        );

        // Compute changelog for re-submit (cycle 2+)
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $cycle = $statuses['PK01']['cycle'] ?? 1;

        $extra = ['pp06_revision' => $this->getLatestPp06Revision($pkWorkflow)];
        if ($cycle > 1) {
            $extra['changes'] = $this->computePk01Diff($pkWorkflow, $pk01Data);
        }

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: 'PK01',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pk01_data',
            dataId: $pk01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: $extra,
        );

        // Auto-create PK02A + PK02B (parallel tracks — no data tables)
        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: 'PK02A',
            action: 'created',
            userId: null,
            sessionContext: [],
        );

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: 'PK02B',
            action: 'created',
            userId: null,
            sessionContext: [],
        );

        // Notify PK02A + PK02B approvers (parallel fork)
        $this->notifier->notify($pkWorkflow, 'pk01.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('team.workflows.pk.show', $pkWorkflow)->with('success', 'PK01 berhasil disubmit.');
    }

    // ──────────────────────────────────────────────────────────
    //  Stubs — will be built per PK-index / PK-show step reviews
    // ──────────────────────────────────────────────────────────

    public function index(): Response
    {
        $scope = $this->getScope();
        $this->checkPermission("{$scope}.workflows.pk.index");

        return Inertia::render('workflows/pk/index', [
            'scope' => $scope,
        ]);
    }

    public function show(PkWorkflow $pkWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.show");

        $history = $pkWorkflow->history ?? [];
        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();

        return Inertia::render('workflows/pk/show', [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => "PK-{$teamName}-{$pp01?->tahun}",
                'status' => $this->engine->getWorkflowStatus($history),
            ],
            'scope' => $scope,
        ]);
    }

    // ──────────────────────────────────────────────────────────
    //  Helper Methods
    // ──────────────────────────────────────────────────────────

    private function getScope(): string
    {
        $routeName = request()->route()?->getName() ?? '';

        return str_starts_with($routeName, 'team.') ? 'team' : 'admin';
    }

    private function getActiveRole(): ?Role
    {
        $roleId = $this->session->getActiveRoleId();

        return $roleId ? Role::with('team.organization')->find($roleId) : null;
    }

    private function getSessionContext(): array
    {
        $roleId = $this->session->getActiveRoleId();
        $teamId = $roleId ? Role::find($roleId)?->team_id : null;

        return [
            'role' => $roleId,
            'team' => $teamId,
            'org' => null,
            'workspace' => $this->session->getActiveWorkspaceId(),
        ];
    }

    private function resolveSessionRoleName(): string
    {
        $roleId = $this->session->getActiveRoleId();
        if (! $roleId) {
            return 'System';
        }

        return Role::find($roleId)?->name ?? 'Unknown';
    }

    private function ensureWorkspaceOwnership(PkWorkflow $pkWorkflow): void
    {
        if ($pkWorkflow->workspace_id !== $this->session->getActiveWorkspaceId()) {
            abort(403, 'Workflow bukan milik workspace aktif.');
        }
    }

    private function ensureTeamOwnership(PkWorkflow $pkWorkflow): void
    {
        $roleId = $this->session->getActiveRoleId();
        $userTeamId = $roleId ? Role::find($roleId)?->team_id : null;

        if ($userTeamId === null || $pkWorkflow->team_id !== $userTeamId) {
            abort(403, 'Workflow bukan milik tim Anda.');
        }
    }

    private function checkPermission(string $permission): void
    {
        if (! in_array($permission, $this->session->getActivePermissions())) {
            abort(403);
        }
    }

    private function ensureStepActive(PkWorkflow $pkWorkflow, string $step): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PK);

        if (! in_array($step, $this->engine->getCurrentSteps($definition, $pkWorkflow->history ?? []))) {
            abort(409, 'Step ini sudah tidak aktif.');
        }
    }

    private function ensureCurrentRecord(PkWorkflow $pkWorkflow, string $step, int $recordId): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $statuses = $this->engine->getStepStatuses($definition, $pkWorkflow->history ?? []);
        $expectedId = $statuses[$step]['dataId'] ?? null;

        if ($expectedId !== null && $expectedId !== $recordId) {
            abort(409, 'Data ini bukan versi terkini untuk step ini.');
        }
    }

    private function checkOptimisticLock($model, string $expectedUpdatedAt): void
    {
        if ($model->updated_at->toIso8601String() !== $expectedUpdatedAt) {
            abort(409, 'Data telah diubah oleh pengguna lain. Refresh halaman.');
        }
    }

    private function resolveMode(array $statuses, string $step): string
    {
        $status = $statuses[$step]['status'] ?? 'pending';

        if ($status === 'active' && ($statuses[$step]['cycle'] ?? 1) > 1) {
            return 'edit';
        }

        if ($status === 'active' && ($statuses[$step]['dataId'] ?? null) !== null) {
            return 'edit';
        }

        if ($status === 'active') {
            return 'create';
        }

        return 'readonly';
    }

    /** @return list<array{action: string, label: string, roles: list<array{name: string, users: list<string>}>, highlight: bool}> */
    private function resolveActionRoles(array $permissionLabels): array
    {
        $permissions = Permission::whereIn('name', array_keys($permissionLabels))
            ->with(['roles.team', 'roles.users'])
            ->get()
            ->keyBy('name');

        $result = [];

        foreach ($permissionLabels as $permName => [$label, $highlight]) {
            $perm = $permissions->get($permName);
            $roles = [];

            if ($perm) {
                foreach ($perm->roles->sortBy(fn (Role $r) => $r->team ? "{$r->name} ({$r->team->name})" : $r->name) as $role) {
                    $roles[] = [
                        'name' => $role->team ? "{$role->name} ({$role->team->name})" : $role->name,
                        'users' => $role->users->pluck('name')->sort()->values()->all(),
                    ];
                }
            }

            $result[] = [
                'action' => $permName,
                'label' => $label,
                'roles' => $roles,
                'highlight' => $highlight,
            ];
        }

        return $result;
    }

    private function getActiveRoleName(): ?string
    {
        $roleId = $this->session->getActiveRoleId();
        if (! $roleId) {
            return null;
        }

        $role = Role::with('team')->find($roleId);

        return $role
            ? ($role->team ? "{$role->name} ({$role->team->name})" : $role->name)
            : null;
    }

    // ──────────────────────────────────────────────────────────
    //  PK01 Data Helpers
    // ──────────────────────────────────────────────────────────

    private function syncKegiatan(Pk01Data $pk01Data, array $kegiatanData, bool $isDraft): void
    {
        // Delete all existing (cascade deletes anggaran + kuisioner)
        $pk01Data->kegiatan()->delete();

        foreach ($kegiatanData as $kData) {
            // Draft filtering: skip kegiatan where nama AND anggaran are all empty
            if ($isDraft) {
                $hasNama = ! empty($kData['nama_kegiatan']);
                $hasAnggaran = collect($kData['anggaran'] ?? [])->contains(
                    fn ($a) => ! empty($a['mata_anggaran']) || ! empty($a['kode_bidang']) || ($a['nominal_anggaran'] ?? 0) > 0
                );
                if (! $hasNama && ! $hasAnggaran) {
                    continue;
                }
            }

            $kegiatan = $pk01Data->kegiatan()->create([
                'nama_kegiatan' => $kData['nama_kegiatan'] ?? null,
                'bulan' => $kData['bulan'] ?? null,
            ]);

            foreach ($kData['anggaran'] ?? [] as $aData) {
                if ($isDraft && empty($aData['mata_anggaran']) && empty($aData['kode_bidang']) && ($aData['nominal_anggaran'] ?? 0) == 0) {
                    continue;
                }

                $kegiatan->anggaran()->create([
                    'kode_bidang' => $aData['kode_bidang'] ?? null,
                    'kode_sub_bidang' => $aData['kode_sub_bidang'] ?? null,
                    'kode_jenis' => $aData['kode_jenis'] ?? null,
                    'mata_anggaran' => $aData['mata_anggaran'] ?? null,
                    'deskripsi_pk' => $aData['deskripsi_pk'] ?? null,
                    'nominal_anggaran' => $aData['nominal_anggaran'] ?? 0,
                ]);
            }

            foreach ($kData['kuisioner'] ?? [] as $qData) {
                if ($isDraft && empty($qData['pertanyaan'])) {
                    continue;
                }
                if (empty($qData['pertanyaan'])) {
                    continue;
                }

                $kegiatan->kuisioner()->create([
                    'kode_kuisioner' => $qData['kode_kuisioner'] ?? null,
                    'pertanyaan' => $qData['pertanyaan'],
                    'tipe' => $qData['tipe'] ?? 'Kualitatif',
                    'satuan' => $qData['satuan'] ?? null,
                ]);
            }
        }
    }

    private function getLatestPp06(PkWorkflow $pkWorkflow): ?Pp06PeriodeTahunan
    {
        return $pkWorkflow->ppWorkflow?->latestPp06();
    }

    private function getLatestPp06Revision(PkWorkflow $pkWorkflow): int
    {
        return $this->getLatestPp06($pkWorkflow)?->revision ?? 0;
    }

    /** @return array{kategori: list<array{kode: string, nama: string}>, bidang: list<array{kode: string, nama: string}>, subBidang: list<array{kode: string, nama: string}>, jenis: list<array{kode: string, nama: string}>, kuisioner: list<array{kode: string, pertanyaan: string, tipe: string, satuan: ?string}>} */
    private function loadKodeReferences(?Pp06PeriodeTahunan $pp06): array
    {
        if (! $pp06) {
            return ['kategori' => [], 'bidang' => [], 'subBidang' => [], 'jenis' => [], 'kuisioner' => []];
        }

        return [
            'kategori' => $pp06->kodeKategoriPelayanan()
                ->orderBy('kode')
                ->get(['kode', 'nama'])
                ->toArray(),
            'bidang' => $pp06->kodeBidangPelayanan()
                ->orderBy('kode')
                ->get(['kode', 'nama'])
                ->toArray(),
            'subBidang' => $pp06->kodeSubBidangPelayanan()
                ->orderBy('kode')
                ->get(['kode', 'nama'])
                ->toArray(),
            'jenis' => $pp06->kodeJenisProgram()
                ->orderBy('kode')
                ->get(['kode', 'nama'])
                ->toArray(),
            'kuisioner' => $pp06->itemKuisioner()
                ->orderBy('kode')
                ->get(['kode', 'pertanyaan', 'tipe', 'satuan'])
                ->toArray(),
        ];
    }

    /** @return array{ppLabel: ?string, plafon: float, accepted: float, planned: float, sisa: float} */
    private function getBudgetCounters(PkWorkflow $pkWorkflow): array
    {
        $pp06 = $this->getLatestPp06($pkWorkflow);
        if (! $pp06) {
            return ['ppLabel' => null, 'plafon' => 0, 'accepted' => 0, 'planned' => 0, 'sisa' => 0];
        }

        $teamId = $pkWorkflow->team_id;
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();

        // Plafon for this team
        $plafon = (float) ($pp06->itemPlafonAnggaran()
            ->where('team_id', $teamId)
            ->value('plafon_anggaran') ?? 0);

        // Accepted: SUM from completed PK04 active anggaran items for this team
        $accepted = (float) Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan.pk04ProgramTahunan.pkWorkflow', fn ($q) => $q
                ->where('team_id', $teamId)
                ->where('workspace_id', $pkWorkflow->workspace_id)
                ->where('pp_workflow_id', $pkWorkflow->pp_workflow_id)
                ->where('tipe', 'raker')
                ->whereNull('deleted_at')
            )
            ->where('status_item', 'active')
            ->sum('nominal_anggaran');

        // Planned: SUM from in-progress raker PKs (not completed/terminated, excluding this one)
        $planned = 0.0;
        $otherPkWorkflows = PkWorkflow::query()
            ->where('team_id', $teamId)
            ->where('workspace_id', $pkWorkflow->workspace_id)
            ->where('pp_workflow_id', $pkWorkflow->pp_workflow_id)
            ->where('tipe', 'raker')
            ->where('id', '!=', $pkWorkflow->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($otherPkWorkflows as $wf) {
            $status = $this->engine->getWorkflowStatus($wf->history ?? []);
            if (in_array($status, ['completed', 'terminated', 'deleted'])) {
                continue;
            }
            $latestPk01 = $wf->latestPk01();
            if ($latestPk01) {
                $planned += (float) $latestPk01->kegiatan()
                    ->with('anggaran')
                    ->get()
                    ->flatMap(fn ($k) => $k->anggaran)
                    ->sum('nominal_anggaran');
            }
        }

        return [
            'ppLabel' => "PP-{$pp01?->tahun} Revisi {$pp06->revision}",
            'plafon' => $plafon,
            'accepted' => $accepted,
            'planned' => $planned,
            'sisa' => $plafon - $accepted,
        ];
    }

    private function isPp07Active(PkWorkflow $pkWorkflow): bool
    {
        $ppWorkflow = $pkWorkflow->ppWorkflow;
        if (! $ppWorkflow) {
            return false;
        }

        return $ppWorkflow->pp07Data()->whereNull('submitted_at')->exists();
    }

    /** @return array<string, mixed>|null */
    private function getPkRejectionNotes(array $history): ?array
    {
        // Find latest rejection from PK02A, PK02B, or PK03
        $latestRejection = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i];
            if (
                ($entry['action'] ?? '') === 'rejected'
                && in_array($entry['step'] ?? '', ['PK02A', 'PK02B', 'PK03'])
            ) {
                $latestRejection = $entry;

                break;
            }
        }

        if (! $latestRejection) {
            return null;
        }

        $rejectingStep = $latestRejection['step'];

        // For parallel tracks, find the other track's entry
        $parallelTrackEntry = null;
        if (in_array($rejectingStep, ['PK02A', 'PK02B'])) {
            $otherStep = $rejectingStep === 'PK02A' ? 'PK02B' : 'PK02A';
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (
                    ($history[$i]['step'] ?? '') === $otherStep
                    && in_array($history[$i]['action'] ?? '', ['approved', 'rejected'])
                ) {
                    $parallelTrackEntry = $history[$i];

                    break;
                }
            }
        }

        // Resolve names
        $byName = isset($latestRejection['by'])
            ? User::withTrashed()->find($latestRejection['by'])?->name
            : null;
        $roleName = isset($latestRejection['role'])
            ? Role::withTrashed()->find($latestRejection['role'])?->name
            : null;

        $result = [
            'step' => $rejectingStep,
            'step_label' => $this->getStepLabel($rejectingStep),
            'notes' => $latestRejection['notes'] ?? null,
            'by_name' => $byName,
            'role_name' => $roleName,
            'at' => $latestRejection['at'] ?? null,
            'files' => $latestRejection['files'] ?? [],
            'parallelTrackNotes' => null,
        ];

        if ($parallelTrackEntry && ! empty($parallelTrackEntry['notes'])) {
            $ptByName = isset($parallelTrackEntry['by'])
                ? User::withTrashed()->find($parallelTrackEntry['by'])?->name
                : null;
            $ptRoleName = isset($parallelTrackEntry['role'])
                ? Role::withTrashed()->find($parallelTrackEntry['role'])?->name
                : null;

            $result['parallelTrackNotes'] = [
                'step' => $parallelTrackEntry['step'],
                'step_label' => $this->getStepLabel($parallelTrackEntry['step']),
                'action' => $parallelTrackEntry['action'],
                'notes' => $parallelTrackEntry['notes'],
                'by_name' => $ptByName,
                'role_name' => $ptRoleName,
                'at' => $parallelTrackEntry['at'] ?? null,
                'files' => $parallelTrackEntry['files'] ?? [],
            ];
        }

        return $result;
    }

    /** @return list<array{type: string, field?: string, kegiatan?: string, bulan?: int, mata_anggaran?: string, pertanyaan?: string, nama_kegiatan?: string, old?: mixed, new?: mixed}> */
    private function computePk01Diff(PkWorkflow $pkWorkflow, Pk01Data $currentPk01): array
    {
        $previousPk01 = $pkWorkflow->pk01Data()
            ->where('id', '!=', $currentPk01->id)
            ->latest('id')
            ->with(['kegiatan.anggaran', 'kegiatan.kuisioner'])
            ->first();

        if (! $previousPk01) {
            return [];
        }

        $changes = [];

        // Program-level fields
        $fields = [
            'kode_kategori' => 'Kategori Pelayanan',
            'nama_program' => 'Nama Program',
            'deskripsi_program' => 'Deskripsi Program',
            'tujuan_program' => 'Tujuan Program',
        ];

        foreach ($fields as $field => $label) {
            if ($previousPk01->$field !== $currentPk01->$field) {
                $changes[] = [
                    'type' => 'program_changed',
                    'field' => $field,
                    'old' => $previousPk01->$field,
                    'new' => $currentPk01->$field,
                ];
            }
        }

        // Kegiatan matching by nama+bulan
        $currentKegiatan = $currentPk01->kegiatan()->with(['anggaran', 'kuisioner'])->get();
        $prevKegiatan = $previousPk01->kegiatan;

        $prevMap = [];
        foreach ($prevKegiatan as $k) {
            $key = ($k->nama_kegiatan ?? '').'|'.($k->bulan ?? '');
            $prevMap[$key] = $k;
        }

        $currentMap = [];
        foreach ($currentKegiatan as $k) {
            $key = ($k->nama_kegiatan ?? '').'|'.($k->bulan ?? '');
            $currentMap[$key] = $k;
        }

        foreach ($currentMap as $key => $k) {
            if (! isset($prevMap[$key])) {
                $changes[] = ['type' => 'kegiatan_added', 'nama_kegiatan' => $k->nama_kegiatan, 'bulan' => $k->bulan];
            }
        }

        foreach ($prevMap as $key => $k) {
            if (! isset($currentMap[$key])) {
                $changes[] = ['type' => 'kegiatan_removed', 'nama_kegiatan' => $k->nama_kegiatan, 'bulan' => $k->bulan];
            }
        }

        // Anggaran + kuisioner diffs within matched kegiatan
        foreach ($currentMap as $key => $currentK) {
            if (! isset($prevMap[$key])) {
                continue;
            }
            $prevK = $prevMap[$key];

            // Anggaran matching by mata_anggaran
            $prevAnggaranMap = collect($prevK->anggaran)->keyBy('mata_anggaran');

            foreach ($currentK->anggaran as $a) {
                $aKey = $a->mata_anggaran ?? '';
                if (! $prevAnggaranMap->has($aKey)) {
                    $changes[] = ['type' => 'anggaran_added', 'kegiatan' => $currentK->nama_kegiatan, 'bulan' => $currentK->bulan, 'mata_anggaran' => $a->mata_anggaran];

                    continue;
                }

                $prevA = $prevAnggaranMap->get($aKey);
                foreach (['kode_bidang', 'kode_sub_bidang', 'kode_jenis', 'deskripsi_pk', 'nominal_anggaran'] as $f) {
                    $oldVal = $f === 'nominal_anggaran' ? (float) $prevA->$f : $prevA->$f;
                    $newVal = $f === 'nominal_anggaran' ? (float) $a->$f : $a->$f;
                    if ($oldVal != $newVal) {
                        $changes[] = ['type' => 'anggaran_changed', 'kegiatan' => $currentK->nama_kegiatan, 'bulan' => $currentK->bulan, 'mata_anggaran' => $a->mata_anggaran, 'field' => $f, 'old' => $oldVal, 'new' => $newVal];
                    }
                }

                $prevAnggaranMap->forget($aKey);
            }

            foreach ($prevAnggaranMap as $a) {
                $changes[] = ['type' => 'anggaran_removed', 'kegiatan' => $currentK->nama_kegiatan, 'bulan' => $currentK->bulan, 'mata_anggaran' => $a->mata_anggaran];
            }

            // Kuisioner matching by kode_kuisioner or pertanyaan
            $prevKuisionerMap = [];
            foreach ($prevK->kuisioner as $q) {
                $qKey = $q->kode_kuisioner ?? $q->pertanyaan ?? '';
                $prevKuisionerMap[$qKey] = $q;
            }

            foreach ($currentK->kuisioner as $q) {
                $qKey = $q->kode_kuisioner ?? $q->pertanyaan ?? '';
                if (! isset($prevKuisionerMap[$qKey])) {
                    $changes[] = ['type' => 'kuisioner_added', 'kegiatan' => $currentK->nama_kegiatan, 'bulan' => $currentK->bulan, 'pertanyaan' => $q->pertanyaan];
                }
                unset($prevKuisionerMap[$qKey]);
            }

            foreach ($prevKuisionerMap as $q) {
                $changes[] = ['type' => 'kuisioner_removed', 'kegiatan' => $currentK->nama_kegiatan, 'bulan' => $currentK->bulan, 'pertanyaan' => $q->pertanyaan];
            }
        }

        return $changes;
    }

    private function getStepLabel(string $step): string
    {
        return match ($step) {
            'PK01' => 'Program Kegiatan',
            'PK02A' => 'Approval Narasi',
            'PK02B' => 'Approval Anggaran',
            'PK03' => 'RAKER',
            'PK04' => 'Program Tahunan',
            'PK05' => 'Revisi',
            default => $step,
        };
    }
}
