<?php

namespace App\Http\Controllers\Workflows;

use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\Pk01DraftRequest;
use App\Http\Requests\Workflows\Pk01SubmitRequest;
use App\Http\Requests\Workflows\Pk02aApproveRequest;
use App\Http\Requests\Workflows\Pk02aRejectRequest;
use App\Http\Requests\Workflows\Pk02bApproveRequest;
use App\Http\Requests\Workflows\Pk02bRejectRequest;
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

        // Validate kode references exist in PP06
        $pp06 = $this->getLatestPp06($pkWorkflow);
        if ($pp06) {
            $kodeErrors = $this->validateKodeReferences($pp06, $validated);
            if (! empty($kodeErrors)) {
                return back()->withErrors($kodeErrors)->withInput();
            }
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
    //  PK02A — Approval Narasi (Monev)
    // ──────────────────────────────────────────────────────────

    public function pk02aShow(PkWorkflow $pkWorkflow): Response
    {
        return $this->renderApprovalStep($pkWorkflow, 'PK02A', 'pk02a', 'PK02B', 'Approval Anggaran');
    }

    public function pk02aApprove(Pk02aApproveRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        return $this->handleApproval($request, $pkWorkflow, 'PK02A', 'pk02a', 'PK02B', 'approve');
    }

    public function pk02aReject(Pk02aRejectRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        return $this->handleApproval($request, $pkWorkflow, 'PK02A', 'pk02a', 'PK02B', 'reject');
    }

    // ──────────────────────────────────────────────────────────
    //  PK02B — Approval Anggaran (BU)
    // ──────────────────────────────────────────────────────────

    public function pk02bShow(PkWorkflow $pkWorkflow): Response
    {
        return $this->renderApprovalStep($pkWorkflow, 'PK02B', 'pk02b', 'PK02A', 'Approval Narasi');
    }

    public function pk02bApprove(Pk02bApproveRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        return $this->handleApproval($request, $pkWorkflow, 'PK02B', 'pk02b', 'PK02A', 'approve');
    }

    public function pk02bReject(Pk02bRejectRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        return $this->handleApproval($request, $pkWorkflow, 'PK02B', 'pk02b', 'PK02A', 'reject');
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
    //  PK02A / PK02B Shared Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Render the approval step page (shared by PK02A and PK02B).
     */
    private function renderApprovalStep(
        PkWorkflow $pkWorkflow,
        string $thisStep,
        string $thisStepLower,
        string $otherStep,
        string $otherStepLabel,
    ): Response {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.{$thisStepLower}.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $history = $pkWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $stepStatus = $statuses[$thisStep]['status'] ?? 'pending';

        // For completed approval steps, resolve the actual decision (approved/rejected)
        if ($stepStatus === 'completed') {
            $latestAction = $this->getLatestStepAction($thisStep, $history);
            if ($latestAction === 'approved' || $latestAction === 'rejected') {
                $stepStatus = $latestAction;
            }
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.pk";
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';
        $isStepActive = $statuses[$thisStep]['status'] === 'active';

        // Resolve PK01 data for read-only display
        $pk01Data = $pkWorkflow->latestPk01();
        $pk01Display = $this->buildPk01ReadonlyData($pkWorkflow, $pk01Data);

        // Previous cycles
        $previousCycles = $this->buildPreviousCycles($pkWorkflow, $pk01Data);

        // Changelog (cycle 2+)
        $pk01Changes = ($statuses['PK01']['cycle'] ?? 1) > 1
            ? $this->computePk01Diff($pkWorkflow, $pk01Data)
            : null;

        // Parallel track status — resolve decision for completed tracks
        $otherStatus = $statuses[$otherStep]['status'] ?? 'pending';
        if ($otherStatus === 'completed') {
            $otherAction = $this->getLatestStepAction($otherStep, $history);
            if ($otherAction === 'approved' || $otherAction === 'rejected') {
                $otherStatus = $otherAction;
            }
        }
        $parallelTrackStatus = [
            'step' => $otherStep,
            'label' => $otherStepLabel,
            'status' => $otherStatus,
        ];

        // Labels
        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun;
        $label = "PK-{$teamName}-{$tahun}";

        $pp06 = $this->getLatestPp06($pkWorkflow);
        $pp06RevisionLabel = $pp06
            ? "PP-{$tahun} Revisi {$pp06->revision}"
            : null;

        $basePath = $scope === 'team'
            ? "/team/workflows/pk/{$pkWorkflow->id}"
            : "/admin/workflows/pk/{$pkWorkflow->id}";

        // Build action roles — PK02A: Monev approve/reject, PK02B: BU approve/reject
        $actionRolesMap = [
            "admin.workflows.pk.{$thisStepLower}.approve" => ['Setujui', true],
            "admin.workflows.pk.{$thisStepLower}.reject" => ['Tolak', true],
            "{$permPrefix}.comment" => ['Komentar', false],
            "{$permPrefix}.terminate" => ['Batalkan Workflow', true],
        ];

        // Budget context (PK02B only)
        $budgetContext = null;
        if ($thisStep === 'PK02B') {
            $budgetCounter = $this->getBudgetCounters($pkWorkflow);
            $thisTotal = $pk01Data
                ? (float) $pk01Data->kegiatan()->with('anggaran')->get()
                    ->flatMap(fn ($k) => $k->anggaran)->sum('nominal_anggaran')
                : 0.0;
            $isProposal = $pkWorkflow->tipe === 'proposal';

            $budgetContext = [
                'pp_label' => $budgetCounter['ppLabel'],
                'plafon' => $budgetCounter['plafon'],
                'sudah_ditetapkan' => $budgetCounter['accepted'],
                'sedang_diajukan' => $budgetCounter['planned'],
                'sisa' => $budgetCounter['sisa'],
                'pk_ini' => $thisTotal,
                'is_over_budget' => ! $isProposal && ($thisTotal + $budgetCounter['accepted']) > $budgetCounter['plafon'],
                'is_proposal' => $isProposal,
            ];
        }

        return Inertia::render("workflows/pk/{$thisStepLower}", [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'tipe' => $pkWorkflow->tipe,
            ],
            'pk01Data' => $pk01Display,
            'previousCycles' => $previousCycles,
            'pk01Changes' => $pk01Changes,
            'pp06RevisionLabel' => $pp06RevisionLabel,
            'parallelTrackStatus' => $parallelTrackStatus,
            'stepStatus' => $stepStatus,
            'canApprove' => $isStepActive && $scope === 'admin'
                && in_array("admin.workflows.pk.{$thisStepLower}.approve", $permissions),
            'canReject' => $isStepActive && $scope === 'admin'
                && in_array("admin.workflows.pk.{$thisStepLower}.reject", $permissions),
            'canTerminate' => $isWorkflowActive && in_array("{$permPrefix}.terminate", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'budgetContext' => $budgetContext,
            'actionRoles' => $this->resolveActionRoles($actionRolesMap),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    /**
     * Handle approve or reject action for parallel approval steps (PK02A / PK02B).
     */
    private function handleApproval(
        mixed $request,
        PkWorkflow $pkWorkflow,
        string $thisStep,
        string $thisStepLower,
        string $otherStep,
        string $action,
    ): RedirectResponse {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission("admin.workflows.pk.{$thisStepLower}.{$action}");
        $this->ensureStepActive($pkWorkflow, $thisStep);

        $historyAction = $action === 'approve' ? 'approved' : 'rejected';
        $validated = $request->validated();
        $sessionContext = $this->getSessionContext();

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pkWorkflow,
            "pk.{$thisStepLower}.{$action}",
            $request->user()->id,
            $sessionContext,
        );

        // Find the pk01_data being reviewed
        $pk01Data = $pkWorkflow->latestPk01();

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: $thisStep,
            action: $historyAction,
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: [
                'reviewed' => ['pk01_data' => $pk01Data?->id],
                'pp06_revision' => $this->getLatestPp06Revision($pkWorkflow),
            ],
        );

        // Check parallel join gate
        $this->handleParallelJoin($request, $pkWorkflow, $thisStep, $otherStep);

        $showRoute = route('admin.workflows.pk.show', $pkWorkflow);
        $actionLabel = $historyAction === 'approved' ? 'disetujui' : 'ditolak';

        return redirect($showRoute)
            ->with('success', "{$this->getStepLabel($thisStep)} berhasil {$actionLabel}.");
    }

    /**
     * Check the parallel track status and trigger side effects at the join gate.
     *
     * Wait-for-both model:
     * - Both approved → create PK03 + notify PK03 approvers
     * - Both completed, any rejected → PK01 re-entry + compiled feedback
     * - Other track pending → silent (wait)
     */
    private function handleParallelJoin(
        mixed $request,
        PkWorkflow $pkWorkflow,
        string $thisStep,
        string $otherStep,
    ): void {
        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $history = $pkWorkflow->fresh()->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        $thisStatus = $statuses[$thisStep]['status'] ?? 'pending';
        $otherStatus = $statuses[$otherStep]['status'] ?? 'pending';

        // Other track not yet completed → silent
        if ($otherStatus !== 'completed') {
            return;
        }

        // Both tracks completed — determine outcome
        $thisAction = $this->getLatestStepAction($thisStep, $history);
        $otherAction = $this->getLatestStepAction($otherStep, $history);

        if ($thisAction === 'approved' && $otherAction === 'approved') {
            // Both approved → create PK03
            $this->engine->recordAction(
                workflow: $pkWorkflow,
                step: 'PK03',
                action: 'created',
                userId: null,
                sessionContext: [],
            );

            $this->notifier->notify($pkWorkflow, 'pk02.both_approved', [
                'actor_name' => $request->user()->name,
                'actor_role' => $this->resolveSessionRoleName(),
            ], $request->user()->id);
        } else {
            // At least one rejected → record PK03 rejection to trigger invalidation cascade,
            // then PK01 re-entry with compiled feedback.
            // PK03 has rejectionTarget='PK01', so engine invalidates PK01→PK02A→PK02B.
            $rejectingSummary = collect([$thisStep => $thisAction, $otherStep => $otherAction])
                ->filter(fn ($a) => $a === 'rejected')
                ->keys()
                ->join(' & ');

            $this->engine->recordAction(
                workflow: $pkWorkflow,
                step: 'PK03',
                action: 'rejected',
                userId: null,
                sessionContext: [],
                notes: "Otomatis: {$rejectingSummary} menolak.",
            );

            $previousPk01 = $pkWorkflow->fresh()->latestPk01();
            $newPk01 = Pk01Data::create([
                'pk_workflow_id' => $pkWorkflow->id,
                'kode_kategori' => $previousPk01?->kode_kategori,
                'nama_program' => $previousPk01?->nama_program,
                'deskripsi_program' => $previousPk01?->deskripsi_program,
                'tujuan_program' => $previousPk01?->tujuan_program,
            ]);

            // Copy kegiatan with anggaran + kuisioner
            if ($previousPk01) {
                foreach ($previousPk01->kegiatan()->with(['anggaran', 'kuisioner'])->get() as $kegiatan) {
                    $newKegiatan = $newPk01->kegiatan()->create([
                        'nama_kegiatan' => $kegiatan->nama_kegiatan,
                        'bulan' => $kegiatan->bulan,
                    ]);
                    foreach ($kegiatan->anggaran as $a) {
                        $newKegiatan->anggaran()->create([
                            'kode_bidang' => $a->kode_bidang,
                            'kode_sub_bidang' => $a->kode_sub_bidang,
                            'kode_jenis' => $a->kode_jenis,
                            'mata_anggaran' => $a->mata_anggaran,
                            'deskripsi_pk' => $a->deskripsi_pk,
                            'nominal_anggaran' => $a->nominal_anggaran,
                        ]);
                    }
                    foreach ($kegiatan->kuisioner as $q) {
                        $newKegiatan->kuisioner()->create([
                            'kode_kuisioner' => $q->kode_kuisioner,
                            'pertanyaan' => $q->pertanyaan,
                            'tipe' => $q->tipe,
                            'satuan' => $q->satuan,
                        ]);
                    }
                }
            }

            // Record PK01 re-entry
            $this->engine->recordAction(
                workflow: $pkWorkflow,
                step: 'PK01',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'pk01_data',
                dataId: $newPk01->id,
            );

            $this->notifier->notify($pkWorkflow, 'pk02.rejected', [
                'actor_name' => $request->user()->name,
                'actor_role' => $this->resolveSessionRoleName(),
            ], $request->user()->id);
        }
    }

    /**
     * Build read-only PK01 data for approval pages.
     */
    private function buildPk01ReadonlyData(PkWorkflow $pkWorkflow, ?Pk01Data $pk01Data): ?array
    {
        if (! $pk01Data) {
            return null;
        }

        $pp06 = $this->getLatestPp06($pkWorkflow);
        $kodeRefs = $this->loadKodeReferences($pp06);

        // Build lookup maps for display names
        $kategoriMap = collect($kodeRefs['kategori'])->keyBy('kode');
        $bidangMap = collect($kodeRefs['bidang'])->keyBy('kode');
        $subBidangMap = collect($kodeRefs['subBidang'])->keyBy('kode');
        $jenisMap = collect($kodeRefs['jenis'])->keyBy('kode');

        $kegiatan = $pk01Data->kegiatan()
            ->with(['anggaran', 'kuisioner'])
            ->orderBy('id')
            ->get();

        $totalAnggaran = $kegiatan->flatMap(fn ($k) => $k->anggaran)->sum('nominal_anggaran');

        // Resolve submitter info from history
        $history = $pkWorkflow->history ?? [];
        $submitter = $this->resolveSubmitterInfo($history, 'PK01');

        $bulanLabels = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return [
            'id' => $pk01Data->id,
            'kode_kategori' => $pk01Data->kode_kategori,
            'kategori_nama' => $kategoriMap->get($pk01Data->kode_kategori)['nama'] ?? null,
            'nama_program' => $pk01Data->nama_program,
            'deskripsi_program' => $pk01Data->deskripsi_program,
            'tujuan_program' => $pk01Data->tujuan_program,
            'kegiatan' => $kegiatan->map(fn ($k) => [
                'id' => $k->id,
                'nama_kegiatan' => $k->nama_kegiatan,
                'bulan' => $k->bulan,
                'bulan_label' => $bulanLabels[$k->bulan] ?? null,
                'anggaran' => $k->anggaran->map(fn ($a) => [
                    'id' => $a->id,
                    'kode_bidang' => $a->kode_bidang,
                    'bidang_nama' => $bidangMap->get($a->kode_bidang)['nama'] ?? null,
                    'kode_sub_bidang' => $a->kode_sub_bidang,
                    'sub_bidang_nama' => $subBidangMap->get($a->kode_sub_bidang)['nama'] ?? null,
                    'kode_jenis' => $a->kode_jenis,
                    'jenis_nama' => $jenisMap->get($a->kode_jenis)['nama'] ?? null,
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
            'total_anggaran' => (float) $totalAnggaran,
            'created_by' => $submitter,
        ];
    }

    /**
     * Build previous rejection cycles data for approval pages.
     *
     * @return list<array{cycle_number: int, pk01_data: ?array, rejection: ?array}>
     */
    private function buildPreviousCycles(PkWorkflow $pkWorkflow, ?Pk01Data $currentPk01): array
    {
        if (! $currentPk01) {
            return [];
        }

        $allPk01 = $pkWorkflow->pk01Data()->orderBy('id')->get();
        if ($allPk01->count() <= 1) {
            return [];
        }

        $history = $pkWorkflow->history ?? [];
        $cycles = [];
        $cycleNumber = 1;

        foreach ($allPk01 as $pk01) {
            if ($pk01->id === $currentPk01->id) {
                break; // Don't include current
            }

            // Find rejection for this cycle
            $rejection = $this->findRejectionForPk01($history, $pk01->id);

            $cycles[] = [
                'cycle_number' => $cycleNumber,
                'pk01_data' => $this->buildPk01ReadonlyData($pkWorkflow, $pk01),
                'rejection' => $rejection,
            ];
            $cycleNumber++;
        }

        return $cycles;
    }

    /**
     * Find rejection details from PK02A/PK02B/PK03 that targeted a specific PK01 cycle.
     */
    private function findRejectionForPk01(array $history, int $pk01DataId): ?array
    {
        // Find entries referencing this pk01_data
        foreach (array_reverse($history) as $entry) {
            if (
                ($entry['action'] ?? '') === 'rejected'
                && in_array($entry['step'] ?? '', ['PK02A', 'PK02B', 'PK03'])
                && ($entry['reviewed']['pk01_data'] ?? null) === $pk01DataId
            ) {
                $byName = isset($entry['by'])
                    ? User::withTrashed()->find($entry['by'])?->name
                    : null;
                $roleName = isset($entry['role'])
                    ? Role::withTrashed()->find($entry['role'])?->name
                    : null;

                // Also find the parallel track entry
                $otherStep = match ($entry['step']) {
                    'PK02A' => 'PK02B',
                    'PK02B' => 'PK02A',
                    default => null,
                };

                $parallelNotes = null;
                if ($otherStep) {
                    foreach (array_reverse($history) as $other) {
                        if (
                            ($other['step'] ?? '') === $otherStep
                            && in_array($other['action'] ?? '', ['approved', 'rejected'])
                            && ($other['reviewed']['pk01_data'] ?? null) === $pk01DataId
                        ) {
                            $ptByName = isset($other['by'])
                                ? User::withTrashed()->find($other['by'])?->name
                                : null;

                            $parallelNotes = [
                                'step' => $otherStep,
                                'step_label' => $this->getStepLabel($otherStep),
                                'action' => $other['action'],
                                'notes' => $other['notes'] ?? null,
                                'by_name' => $ptByName,
                                'at' => $other['at'] ?? null,
                            ];

                            break;
                        }
                    }
                }

                return [
                    'step' => $entry['step'],
                    'step_label' => $this->getStepLabel($entry['step']),
                    'notes' => $entry['notes'] ?? null,
                    'by_name' => $byName,
                    'role_name' => $roleName,
                    'at' => $entry['at'] ?? null,
                    'files' => $entry['files'] ?? [],
                    'parallelTrackNotes' => $parallelNotes,
                ];
            }
        }

        return null;
    }

    /**
     * Resolve submitter info from workflow history.
     */
    private function resolveSubmitterInfo(array $history, string $step): ?array
    {
        // Find latest 'submitted' action for this step
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['step'] ?? '') === $step && ($history[$i]['action'] ?? '') === 'submitted') {
                $userId = $history[$i]['by'] ?? null;
                $roleId = $history[$i]['role'] ?? null;
                $teamId = $history[$i]['team'] ?? null;

                $userName = $userId ? User::withTrashed()->find($userId)?->name : null;
                $roleName = $roleId ? Role::withTrashed()->find($roleId)?->name : null;
                $teamName = $teamId ? \App\Models\Team::withTrashed()->find($teamId)?->name : null;

                return [
                    'user_name' => $userName,
                    'role_name' => $roleName,
                    'team_name' => $teamName,
                    'submitted_at' => $history[$i]['at'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Get the latest action for a step from history (post-rejection aware).
     */
    private function getLatestStepAction(string $step, array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['step'] ?? '') === $step && in_array($history[$i]['action'] ?? '', ['approved', 'rejected'])) {
                return $history[$i]['action'];
            }
        }

        return null;
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

    /** @return array<string, string> */
    private function validateKodeReferences(Pp06PeriodeTahunan $pp06, array $validated): array
    {
        $errors = [];

        $validKategori = $pp06->kodeKategoriPelayanan()->pluck('kode')->toArray();
        if (! in_array($validated['kode_kategori'], $validKategori)) {
            $errors['kode_kategori'] = 'Kategori pelayanan tidak valid untuk periode PP ini.';
        }

        $validBidang = $pp06->kodeBidangPelayanan()->pluck('kode')->toArray();
        $validSubBidang = $pp06->kodeSubBidangPelayanan()->pluck('kode')->toArray();
        $validJenis = $pp06->kodeJenisProgram()->pluck('kode')->toArray();

        foreach ($validated['kegiatan'] as $kIdx => $kegiatan) {
            foreach ($kegiatan['anggaran'] as $aIdx => $anggaran) {
                if (! in_array($anggaran['kode_bidang'], $validBidang)) {
                    $errors["kegiatan.{$kIdx}.anggaran.{$aIdx}.kode_bidang"] = 'Bidang pelayanan tidak valid.';
                }
                if (! in_array($anggaran['kode_sub_bidang'], $validSubBidang)) {
                    $errors["kegiatan.{$kIdx}.anggaran.{$aIdx}.kode_sub_bidang"] = 'Sub bidang pelayanan tidak valid.';
                }
                if (! in_array($anggaran['kode_jenis'], $validJenis)) {
                    $errors["kegiatan.{$kIdx}.anggaran.{$aIdx}.kode_jenis"] = 'Jenis program tidak valid.';
                }
            }
        }

        return $errors;
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
