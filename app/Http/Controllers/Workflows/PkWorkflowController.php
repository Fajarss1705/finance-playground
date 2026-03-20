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
use App\Http\Requests\Workflows\Pk03ApproveRequest;
use App\Http\Requests\Workflows\Pk03RejectRequest;
use App\Http\Requests\Workflows\Pk05DraftRequest;
use App\Http\Requests\Workflows\Pk05SubmitRequest;
use App\Http\Requests\Workflows\PkCommentRequest;
use App\Http\Requests\Workflows\PkTerminateRequest;
use App\Models\File;
use App\Models\Permission;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\Pk05Data;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Role;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\PkCompileService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'admin_link' => route('admin.workflows.pk.show', $pkWorkflow),
            'team_link' => route('team.workflows.pk.show', $pkWorkflow),
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

        // Kode anggaran context for preview
        $kodeTeam = $pp06?->itemPlafonAnggaran()
            ->where('team_id', $pkWorkflow->team_id)
            ->value('kode_team');

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
            'kodeAnggaranContext' => [
                'kode_team' => $kodeTeam,
                'tim_nama' => $teamName,
                'tahun' => $tahun ? (int) $tahun : null,
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
            'teamName' => $teamName,
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

        // Notify PK02A + PK02B approvers (parallel fork — each gets their step link)
        $this->notifier->notify($pkWorkflow, 'pk01.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'permission_links' => [
                'admin.workflows.pk.pk02a.approve' => route('admin.workflows.pk.pk02a.show', $pkWorkflow),
                'admin.workflows.pk.pk02b.approve' => route('admin.workflows.pk.pk02b.show', $pkWorkflow),
            ],
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
    //  PK03 — Approval RAKER (Join Point)
    // ──────────────────────────────────────────────────────────

    public function pk03Show(PkWorkflow $pkWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.pk03.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $history = $pkWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $stepStatus = $statuses['PK03']['status'] ?? 'pending';

        // For completed approval steps, resolve the actual decision (approved/rejected)
        if ($stepStatus === 'completed') {
            $latestAction = $this->getLatestStepAction('PK03', $history);
            if ($latestAction === 'approved' || $latestAction === 'rejected') {
                $stepStatus = $latestAction;
            }
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.pk";
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';
        $isStepActive = $statuses['PK03']['status'] === 'active';

        // Resolve PK01 data for read-only display
        $pk01Data = $pkWorkflow->latestPk01();
        $pk01Display = $this->buildPk01ReadonlyData($pkWorkflow, $pk01Data);

        // Previous cycles
        $previousCycles = $this->buildPreviousCycles($pkWorkflow, $pk01Data);

        // Changelog (cycle 2+)
        $pk01Changes = ($statuses['PK01']['cycle'] ?? 1) > 1
            ? $this->computePk01Diff($pkWorkflow, $pk01Data)
            : null;

        // Parallel approval status — extract PK02A + PK02B approval info from history
        $parallelApprovals = $this->resolveParallelApprovals($history);

        // Labels
        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun;
        $label = "PK-{$teamName}-{$tahun}";

        $pp06 = $this->getLatestPp06($pkWorkflow);
        $pp06RevisionLabel = $pp06
            ? "PP-{$tahun} Revisi {$pp06->revision}"
            : null;

        // Kode anggaran context for preview
        $kodeTeam = $pp06?->itemPlafonAnggaran()
            ->where('team_id', $pkWorkflow->team_id)
            ->value('kode_team');

        $basePath = $scope === 'team'
            ? "/team/workflows/pk/{$pkWorkflow->id}"
            : "/admin/workflows/pk/{$pkWorkflow->id}";

        // Build action roles — PK03: BU 1 + BU 2 approve/reject
        $actionRolesMap = [
            'admin.workflows.pk.pk03.approve' => ['Setujui', true],
            'admin.workflows.pk.pk03.reject' => ['Tolak', true],
            "{$permPrefix}.comment" => ['Komentar', false],
            "{$permPrefix}.terminate" => ['Batalkan Workflow', true],
        ];

        // Budget counter (same format as PK02A/PK02B)
        $budgetCounter = $this->getBudgetCounters($pkWorkflow);
        $thisTotal = $pk01Data
            ? (float) $pk01Data->kegiatan()->with('anggaran')->get()
                ->flatMap(fn ($k) => $k->anggaran)->sum('nominal_anggaran')
            : 0.0;

        return Inertia::render('workflows/pk/pk03', [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'tipe' => $pkWorkflow->tipe,
            ],
            'teamName' => $teamName,
            'pk01Data' => $pk01Display,
            'previousCycles' => $previousCycles,
            'pk01Changes' => $pk01Changes,
            'pp06RevisionLabel' => $pp06RevisionLabel,
            'kodeAnggaranContext' => [
                'kode_team' => $kodeTeam,
                'tim_nama' => $teamName,
                'tahun' => $tahun ? (int) $tahun : null,
                'tipe' => $pkWorkflow->tipe,
            ],
            'parallelApprovals' => $parallelApprovals,
            'stepStatus' => $stepStatus,
            'canApprove' => $isStepActive && $scope === 'admin'
                && in_array('admin.workflows.pk.pk03.approve', $permissions),
            'canReject' => $isStepActive && $scope === 'admin'
                && in_array('admin.workflows.pk.pk03.reject', $permissions),
            'canTerminate' => $isWorkflowActive && in_array("{$permPrefix}.terminate", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'budgetCounter' => [...$budgetCounter, 'pkIni' => $thisTotal],
            'actionRoles' => $this->resolveActionRoles($actionRolesMap),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function pk03Approve(Pk03ApproveRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission('admin.workflows.pk.pk03.approve');
        $this->ensureStepActive($pkWorkflow, 'PK03');

        $validated = $request->validated();
        $sessionContext = $this->getSessionContext();

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pkWorkflow,
            'pk.pk03.approve',
            $request->user()->id,
            $sessionContext,
        );

        $pk01Data = $pkWorkflow->latestPk01();
        $compileService = app(PkCompileService::class);

        try {
            $pk04 = DB::transaction(function () use ($request, $pkWorkflow, $pk01Data, $validated, $sessionContext, $fileIds, $compileService) {
                // Record PK03 approved
                $this->engine->recordAction(
                    workflow: $pkWorkflow,
                    step: 'PK03',
                    action: 'approved',
                    userId: $request->user()->id,
                    sessionContext: $sessionContext,
                    notes: $validated['notes'] ?? null,
                    files: ! empty($fileIds) ? $fileIds : null,
                    extra: [
                        'reviewed' => ['pk01_data' => $pk01Data?->id],
                        'pp06_revision' => $this->getLatestPp06Revision($pkWorkflow),
                    ],
                );

                // Compile PK04 (may throw RuntimeException on budget hard block)
                $pk04 = $compileService->compile($pkWorkflow);

                // Record PK04 completed
                $this->engine->recordAction(
                    workflow: $pkWorkflow,
                    step: 'PK04',
                    action: 'completed',
                    userId: null,
                    sessionContext: [],
                    table: 'pk04_program_tahunan',
                    dataId: $pk04->id,
                    extra: [
                        'revision' => 0,
                        'pp06_revision' => $this->getLatestPp06Revision($pkWorkflow),
                        'triggered_by' => [
                            'user_id' => $request->user()->id,
                            'step' => 'PK03',
                            'action' => 'approved',
                        ],
                    ],
                );

                return $pk04;
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        // Generate export files (outside transaction — failures are non-blocking)
        $exportResult = $compileService->generateExportFiles($pk04, $request->user()->id, $pkWorkflow->workspace_id);
        $compileService->appendExportFilesToHistory($pkWorkflow->fresh(), $exportResult, 0);

        $this->notifier->notify($pkWorkflow, 'pk03.approved', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'admin_link' => route('admin.workflows.pk.pk04.show', $pkWorkflow),
            'team_link' => route('team.workflows.pk.pk04.show', $pkWorkflow),
        ], $request->user()->id);

        $showRoute = route('admin.workflows.pk.show', $pkWorkflow);

        return redirect($showRoute)
            ->with('success', 'RAKER berhasil disetujui. Program Tahunan (PK04) telah dikompilasi.');
    }

    public function pk03Reject(Pk03RejectRequest $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission('admin.workflows.pk.pk03.reject');
        $this->ensureStepActive($pkWorkflow, 'PK03');

        $validated = $request->validated();
        $sessionContext = $this->getSessionContext();

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pkWorkflow,
            'pk.pk03.reject',
            $request->user()->id,
            $sessionContext,
        );

        $pk01Data = $pkWorkflow->latestPk01();

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: 'PK03',
            action: 'rejected',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: [
                'reviewed' => ['pk01_data' => $pk01Data?->id],
                'pp06_revision' => $this->getLatestPp06Revision($pkWorkflow),
            ],
        );

        // PK01 re-entry — direct, single rejection path
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

        $this->notifier->notify($pkWorkflow, 'pk03.rejected', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'team_link' => route('team.workflows.pk.pk01.show', [$pkWorkflow, $newPk01]),
        ], $request->user()->id);

        $showRoute = route('admin.workflows.pk.show', $pkWorkflow);

        return redirect($showRoute)
            ->with('success', 'RAKER ditolak. PK01 dikembalikan ke tim untuk perbaikan.');
    }

    // ──────────────────────────────────────────────────────────
    //  PK04 — Program Tahunan (Final Compile)
    // ──────────────────────────────────────────────────────────

    public function pk04Show(PkWorkflow $pkWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.pk04.show");

        $history = $pkWorkflow->history ?? [];

        // Resolve requested revision or latest
        $revisionParam = request()->query('revision');
        if ($revisionParam !== null) {
            $pk04 = $pkWorkflow->pk04ProgramTahunan()->where('revision', (int) $revisionParam)->first();
        }
        $pk04 ??= $pkWorkflow->latestPk04();
        $pk04?->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        // All revisions for revision history navigation
        $allRevisions = $pkWorkflow->pk04ProgramTahunan()
            ->orderBy('revision')
            ->get(['id', 'revision', 'nomer_program', 'created_at', 'verification_code']);

        // Extract changelog diffs from history
        $changelogByRevision = [];
        foreach ($history as $entry) {
            // PK05 submitted entries (admin revision)
            if (($entry['step'] ?? '') === 'PK05'
                && ($entry['action'] ?? '') === 'submitted'
                && isset($entry['revision'], $entry['changes'])) {
                $changelogByRevision[$entry['revision']] = $entry['changes'];
            }
            // PK04 completed entries triggered by PABD02B (tarik maju)
            if (($entry['step'] ?? '') === 'PK04'
                && ($entry['action'] ?? '') === 'completed'
                && isset($entry['revision'], $entry['changes'])
                && ($entry['revision'] ?? 0) > 0) {
                $changelogByRevision[$entry['revision']] = $entry['changes'];
            }
        }

        // Budget context (raker only)
        $budgetContext = null;
        if ($pk04 && $pkWorkflow->tipe === 'raker') {
            $budgetCounter = $this->getBudgetCounters($pkWorkflow);
            $thisTotal = (float) $pk04->kegiatan->flatMap(fn ($k) => $k->anggaran)
                ->where('status_item', 'active')
                ->sum('nominal_anggaran');

            $budgetContext = [
                ...$budgetCounter,
                'pkIni' => $thisTotal,
            ];
        }

        // Labels
        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun;
        $label = "PK-{$teamName}-{$tahun}";
        $pp06 = $this->getLatestPp06($pkWorkflow);
        $pp06RevisionLabel = $pp06 ? "PP-{$tahun} Revisi {$pp06->revision}" : null;

        // Approver info (PK03)
        $approverInfo = $this->resolvePk03ApproverInfo($history);

        // Revision button logic
        $permissions = $this->session->getActivePermissions();
        $workflowStatus = $this->engine->getWorkflowStatus($history);
        $activeDraft = $pkWorkflow->pk05Data()->whereNull('submitted_at')->first();

        $canRevise = $pk04 !== null
            && $workflowStatus === 'completed'
            && $scope === 'admin'
            && in_array('admin.workflows.pk.pk05.create', $permissions);

        $permPrefix = "{$scope}.workflows.pk";
        $basePath = $scope === 'team'
            ? "/team/workflows/pk/{$pkWorkflow->id}"
            : "/admin/workflows/pk/{$pkWorkflow->id}";

        // Map pk04 data for frontend
        $pk04Data = null;
        if ($pk04) {
            $bulanLabels = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
            ];

            $pk04Data = [
                'id' => $pk04->id,
                'revision' => $pk04->revision,
                'kode_kategori' => $pk04->kode_kategori,
                'nama_program' => $pk04->nama_program,
                'deskripsi_program' => $pk04->deskripsi_program,
                'tujuan_program' => $pk04->tujuan_program,
                'nomer_program' => $pk04->nomer_program,
                'verification_code' => $pk04->verification_code,
                'created_at' => $pk04->created_at?->toIso8601String(),
                'pk01_created_by_user_name' => $pk04->pk01_created_by_user_name,
                'pk01_created_by_role_name' => $pk04->pk01_created_by_role_name,
                'pk01_created_by_team_name' => $pk04->pk01_created_by_team_name,
                'pk01_created_at' => $pk04->pk01_created_at?->toIso8601String(),
                'kegiatan' => $pk04->kegiatan->sortBy('nomer_kegiatan')->map(fn ($k) => [
                    'id' => $k->id,
                    'nama_kegiatan' => $k->nama_kegiatan,
                    'bulan' => $k->bulan,
                    'bulan_label' => $bulanLabels[$k->bulan] ?? null,
                    'nomer_kegiatan' => $k->nomer_kegiatan,
                    'source' => $k->source,
                    'anggaran' => $k->anggaran->sortBy('nomer_anggaran')->map(fn ($a) => [
                        'id' => $a->id,
                        'kode_anggaran_baru' => $a->kode_anggaran_baru,
                        'kode_anggaran_lama' => $a->kode_anggaran_lama,
                        'kode_bidang' => $a->kode_bidang,
                        'kode_sub_bidang' => $a->kode_sub_bidang,
                        'kode_jenis' => $a->kode_jenis,
                        'mata_anggaran' => $a->mata_anggaran,
                        'deskripsi_pk' => $a->deskripsi_pk,
                        'nominal_anggaran' => (float) $a->nominal_anggaran,
                        'nomer_anggaran' => $a->nomer_anggaran,
                        'revisi_terakhir' => $a->revisi_terakhir,
                        'status_item' => $a->status_item,
                        'source' => $a->source,
                    ])->values(),
                    'kuisioner' => $k->kuisioner->map(fn ($q) => [
                        'id' => $q->id,
                        'kode_kuisioner' => $q->kode_kuisioner,
                        'pertanyaan' => $q->pertanyaan,
                        'tipe' => $q->tipe,
                        'satuan' => $q->satuan,
                    ])->values(),
                ])->values(),
            ];
        }

        // Kode references for segment tooltips
        $kodeRefs = $this->loadKodeReferences($pp06);
        $kodeRefMap = [
            'bidang' => collect($kodeRefs['bidang'])->pluck('nama', 'kode')->toArray(),
            'subBidang' => collect($kodeRefs['subBidang'])->pluck('nama', 'kode')->toArray(),
            'jenis' => collect($kodeRefs['jenis'])->pluck('nama', 'kode')->toArray(),
            'kategori' => collect($kodeRefs['kategori'])->pluck('nama', 'kode')->toArray(),
        ];

        return Inertia::render('workflows/pk/pk04', [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => $label,
                'status' => $workflowStatus,
                'history' => $this->historyFormatter->format($history),
                'tipe' => $pkWorkflow->tipe,
            ],
            'pk04Data' => $pk04Data,
            'allRevisions' => $allRevisions,
            'changelogByRevision' => $changelogByRevision,
            'budgetContext' => $budgetContext,
            'pkType' => $pkWorkflow->tipe,
            'pp06RevisionLabel' => $pp06RevisionLabel,
            'approverInfo' => $approverInfo,
            'canRevise' => $canRevise,
            'activeDraftId' => $activeDraft?->id,
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'commentUrl' => "{$basePath}/comment",
            'scope' => $scope,
            'kodeRefMap' => $kodeRefMap,
            'teamName' => $teamName,
        ]);
    }

    public function pk04ExportPdf(PkWorkflow $pkWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.pk04.export.pdf");

        $revision = request()->query('revision');
        $pk04 = $revision !== null
            ? $pkWorkflow->pk04ProgramTahunan()->where('revision', (int) $revision)->first()
            : $pkWorkflow->latestPk04();

        if (! $pk04) {
            return back()->withErrors(['export' => 'PK04 belum dikompilasi.']);
        }

        // Find existing export file
        $file = File::where('attachable_type', Pk04ProgramTahunan::class)
            ->where('attachable_id', $pk04->id)
            ->where('source_route', 'pk04.export.pdf')
            ->first();

        if ($file && $file->path && \Illuminate\Support\Facades\Storage::disk($file->disk)->exists($file->path)) {
            return response()->download(
                \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
                $file->original_filename,
                ['Content-Type' => $file->mime_type],
            );
        }

        // Regenerate on-demand
        $compileService = app(PkCompileService::class);
        $exportResult = $compileService->generateExportFiles($pk04, auth()->id(), $pkWorkflow->workspace_id);
        $compileService->appendExportFilesToHistory($pkWorkflow, $exportResult, $pk04->revision);

        if (! $exportResult['pdf_file_id']) {
            return back()->withErrors(['export' => 'Gagal membuat file PDF.']);
        }

        $file = File::find($exportResult['pdf_file_id']);

        return response()->download(
            \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
            $file->original_filename,
            ['Content-Type' => $file->mime_type],
        );
    }

    public function pk04ExportExcel(PkWorkflow $pkWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.pk04.export.excel");

        $revision = request()->query('revision');
        $pk04 = $revision !== null
            ? $pkWorkflow->pk04ProgramTahunan()->where('revision', (int) $revision)->first()
            : $pkWorkflow->latestPk04();

        if (! $pk04) {
            return back()->withErrors(['export' => 'PK04 belum dikompilasi.']);
        }

        // Find existing export file
        $file = File::where('attachable_type', Pk04ProgramTahunan::class)
            ->where('attachable_id', $pk04->id)
            ->where('source_route', 'pk04.export.excel')
            ->first();

        if ($file && $file->path && \Illuminate\Support\Facades\Storage::disk($file->disk)->exists($file->path)) {
            return response()->download(
                \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
                $file->original_filename,
                ['Content-Type' => $file->mime_type],
            );
        }

        // Regenerate on-demand
        $compileService = app(PkCompileService::class);
        $exportResult = $compileService->generateExportFiles($pk04, auth()->id(), $pkWorkflow->workspace_id);
        $compileService->appendExportFilesToHistory($pkWorkflow, $exportResult, $pk04->revision);

        if (! $exportResult['excel_file_id']) {
            return back()->withErrors(['export' => 'Gagal membuat file Excel.']);
        }

        $file = File::find($exportResult['excel_file_id']);

        return response()->download(
            \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
            $file->original_filename,
            ['Content-Type' => $file->mime_type],
        );
    }

    public function pk04ExportZip(PkWorkflow $pkWorkflow): \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.pk04.export.zip");

        $revisions = $pkWorkflow->pk04ProgramTahunan()->orderBy('revision')->get();

        if ($revisions->isEmpty()) {
            return back()->withErrors(['export' => 'PK04 belum dikompilasi.']);
        }

        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun ?? now()->year;
        $programName = $revisions->last()->nama_program ?? 'Program';
        $zipFilename = "PK-{$teamName}-{$tahun}-{$programName}.zip";
        $zipFilename = preg_replace('/[^\w\-. ]/', '', $zipFilename);

        // Collect per-revision export files
        $revisionData = [];
        foreach ($revisions as $pk04) {
            $exportFiles = File::where('attachable_type', Pk04ProgramTahunan::class)
                ->where('attachable_id', $pk04->id)
                ->whereNotNull('path')
                ->get();

            $revisionData[$pk04->revision] = ['exports' => $exportFiles];
        }

        // Comment attachment files
        $historyFileIds = collect($pkWorkflow->history ?? [])
            ->pluck('files')
            ->filter()
            ->flatten()
            ->unique()
            ->all();
        $commentFiles = ! empty($historyFileIds)
            ? File::whereIn('id', $historyFileIds)->whereNotNull('path')->get()
            : collect();

        return response()->streamDownload(function () use ($revisionData, $commentFiles) {
            $zip = new \ZipStream\ZipStream(
                outputStream: fopen('php://output', 'wb'),
                sendHttpHeaders: false,
            );

            $addedFiles = [];
            $addFile = function ($zip, string $folder, File $file) use (&$addedFiles) {
                $name = $file->original_filename;
                $key = "{$folder}/{$name}";
                $counter = 1;
                while (isset($addedFiles[$key])) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $base = pathinfo($name, PATHINFO_FILENAME);
                    $key = "{$folder}/{$base} ({$counter}).{$ext}";
                    $counter++;
                }
                $addedFiles[$key] = true;

                $path = \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path);
                if (file_exists($path)) {
                    $zip->addFileFromPath($key, $path);
                }
            };

            foreach ($revisionData as $revision => $data) {
                $folder = "Revisi-{$revision}";
                foreach ($data['exports'] as $file) {
                    $addFile($zip, $folder, $file);
                }
            }

            if ($commentFiles->isNotEmpty()) {
                foreach ($commentFiles as $file) {
                    $addFile($zip, 'Lampiran Komentar', $file);
                }
            }

            $zip->finish();
        }, $zipFilename, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipFilename.'"',
        ]);
    }

    // ──────────────────────────────────────────────────────────
    //  PK05 — Revisi Program Tahunan (Admin Only)
    // ──────────────────────────────────────────────────────────

    public function pk05Create(Request $request, PkWorkflow $pkWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission('admin.workflows.pk.pk05.create');

        // Must have at least one PK04 revision
        $latestPk04 = $pkWorkflow->latestPk04();
        if (! $latestPk04) {
            return back()->withErrors(['pk05' => 'PK04 belum dikompilasi.']);
        }

        // Only one active PK05 draft at a time
        $activeDraft = $pkWorkflow->pk05Data()->whereNull('submitted_at')->first();
        if ($activeDraft) {
            return to_route('admin.workflows.pk.pk05.show', [
                'pkWorkflow' => $pkWorkflow->id,
                'pk05Data' => $activeDraft->id,
            ]);
        }

        // Prefill from latest PK04 revision
        $latestPk04->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        $draftData = [
            'kode_kategori' => $latestPk04->kode_kategori,
            'nama_program' => $latestPk04->nama_program,
            'deskripsi_program' => $latestPk04->deskripsi_program,
            'tujuan_program' => $latestPk04->tujuan_program,
            'kegiatan' => $latestPk04->kegiatan->sortBy('nomer_kegiatan')->map(function ($k) {
                return [
                    'pk04_kegiatan_id' => $k->id,
                    'nama_kegiatan' => $k->nama_kegiatan,
                    'bulan' => $k->bulan,
                    'source' => $k->source,
                    'anggaran' => $k->anggaran->sortBy('nomer_anggaran')->map(function ($a) {
                        $isLocked = $a->status_pencairan !== null || $a->status_item !== 'active';

                        $lockReason = null;
                        if ($a->status_pencairan !== null) {
                            $lockReason = $a->status_pencairan === 'hangus' ? 'hangus' : 'sudah_dicairkan';
                        } elseif ($a->status_item !== 'active') {
                            $lockReason = 'ditarik_maju';
                        }

                        return [
                            'pk04_anggaran_id' => $a->id,
                            'kode_bidang' => $a->kode_bidang,
                            'kode_sub_bidang' => $a->kode_sub_bidang,
                            'kode_jenis' => $a->kode_jenis,
                            'mata_anggaran' => $a->mata_anggaran,
                            'deskripsi_pk' => $a->deskripsi_pk,
                            'nominal_anggaran' => (float) $a->nominal_anggaran,
                            'is_locked' => $isLocked,
                            'lock_reason' => $lockReason,
                        ];
                    })->values()->all(),
                    'kuisioner' => $k->kuisioner->map(fn ($q) => [
                        'pk04_kuisioner_id' => $q->id,
                        'kode_kuisioner' => $q->kode_kuisioner,
                        'pertanyaan' => $q->pertanyaan,
                        'tipe' => $q->tipe,
                        'satuan' => $q->satuan,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];

        $pk05 = Pk05Data::create([
            'pk_workflow_id' => $pkWorkflow->id,
            'draft_data' => $draftData,
        ]);

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: 'PK05',
            action: 'created',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pk05_data',
            dataId: $pk05->id,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pkWorkflow)],
        );

        // TODO: PABD freeze — build during PABD workflow phase

        return to_route('admin.workflows.pk.pk05.show', [
            'pkWorkflow' => $pkWorkflow->id,
            'pk05Data' => $pk05->id,
        ]);
    }

    public function pk05Show(PkWorkflow $pkWorkflow, Pk05Data $pk05Data): Response
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission('admin.workflows.pk.pk05.show');

        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $isSubmitted = $pk05Data->submitted_at !== null;
        $mode = $isSubmitted ? 'readonly' : 'edit';
        $permissions = $this->session->getActivePermissions();

        // PP06 kodes for dropdowns
        $pp06 = $this->getLatestPp06($pkWorkflow);
        $pp06Kodes = $this->loadKodeReferences($pp06);

        // Budget context (raker only)
        $budgetContext = null;
        if ($pkWorkflow->tipe === 'raker') {
            $budgetCounter = $this->getBudgetCounters($pkWorkflow);
            // For PK05, "sudahDitetapkanLain" excludes THIS PK
            $latestPk04 = $pkWorkflow->latestPk04();
            $thisCurrentTotal = 0.0;
            if ($latestPk04) {
                $latestPk04->loadMissing(['kegiatan.anggaran']);
                $thisCurrentTotal = (float) $latestPk04->kegiatan->flatMap(fn ($k) => $k->anggaran)
                    ->where('status_item', 'active')
                    ->sum('nominal_anggaran');
            }

            $budgetContext = [
                'plafon' => $budgetCounter['plafon'],
                'sudah_ditetapkan_lain' => $budgetCounter['accepted'] - $thisCurrentTotal,
                'sisa' => $budgetCounter['plafon'] - ($budgetCounter['accepted'] - $thisCurrentTotal),
            ];
        }

        // Labels
        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun;
        $label = "PK-{$teamName}-{$tahun}";

        return Inertia::render('admin/workflows/pk/pk05', [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($pkWorkflow->history ?? []),
                'history' => $this->historyFormatter->format($pkWorkflow->history ?? []),
                'tipe' => $pkWorkflow->tipe,
            ],
            'stepData' => [
                'id' => $pk05Data->id,
                'draft_data' => $pk05Data->draft_data,
                'submitted_at' => $pk05Data->submitted_at?->toIso8601String(),
                'updated_at' => $pk05Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => ! $isSubmitted && in_array('admin.workflows.pk.pk05.draft', $permissions),
            'canSubmit' => ! $isSubmitted && in_array('admin.workflows.pk.pk05.submit', $permissions),
            'canComment' => in_array('admin.workflows.pk.comment', $permissions),
            'pp06Kodes' => $pp06Kodes,
            'kuisionerTemplates' => $pp06Kodes['kuisioner'],
            'budgetContext' => $budgetContext,
            'pkType' => $pkWorkflow->tipe,
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.pk.pk05.draft' => ['Simpan Draft', false],
                'admin.workflows.pk.pk05.submit' => ['Submit Revisi', true],
                'admin.workflows.pk.comment' => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pk05Draft(Pk05DraftRequest $request, PkWorkflow $pkWorkflow, Pk05Data $pk05Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission('admin.workflows.pk.pk05.draft');

        if ($pk05Data->submitted_at !== null) {
            abort(409, 'Revisi ini sudah disubmit.');
        }

        $validated = $request->validated();
        $this->checkOptimisticLock($pk05Data, $validated['expected_updated_at']);
        $sessionContext = $this->getSessionContext();

        $pk05Data->update([
            'draft_data' => $validated['draft_data'],
        ]);

        // Comment attachment files
        $commentFileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pk05Data,
            'pk.pk05.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $pkWorkflow,
            step: 'PK05',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pk05_data',
            dataId: $pk05Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($commentFileIds) ? $commentFileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pkWorkflow)],
        );

        return to_route('admin.workflows.pk.show', $pkWorkflow)->with('success', 'Draft PK05 berhasil disimpan.');
    }

    public function pk05Submit(Pk05SubmitRequest $request, PkWorkflow $pkWorkflow, Pk05Data $pk05Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pkWorkflow);
        $this->checkPermission('admin.workflows.pk.pk05.submit');

        if ($pk05Data->submitted_at !== null) {
            abort(409, 'Revisi ini sudah disubmit.');
        }

        $validated = $request->validated();
        $this->checkOptimisticLock($pk05Data, $validated['expected_updated_at']);
        $draftData = $validated['draft_data'];
        $sessionContext = $this->getSessionContext();

        // Validate locked items unchanged (server-side enforcement)
        $currentPk04 = $pkWorkflow->latestPk04();
        if (! $currentPk04) {
            return back()->withErrors(['submit' => 'PK04 tidak ditemukan.']);
        }

        $compileService = app(PkCompileService::class);
        $lockErrors = $compileService->validateLockedItems($currentPk04, $draftData);
        if (! empty($lockErrors)) {
            return back()->withErrors(['submit' => $lockErrors[0]]);
        }

        // Budget hard block (raker only, BEFORE compile — fail fast)
        if ($pkWorkflow->tipe === 'raker') {
            try {
                $compileService->checkBudgetHardBlockForRevision($pkWorkflow, $draftData);
            } catch (\RuntimeException $e) {
                return back()->withErrors(['submit' => $e->getMessage()]);
            }
        }

        // Compute new revision number
        $latestRevision = $pkWorkflow->pk04ProgramTahunan()->max('revision') ?? 0;
        $newRevision = $latestRevision + 1;

        // Compute changelog diff
        $changelogDiff = $compileService->computePk05Diff($currentPk04, $draftData);

        // Comment attachment files
        $commentFileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pk05Data,
            'pk.pk05.submit',
            $request->user()->id,
            $sessionContext,
        );

        try {
            $newPk04 = DB::transaction(function () use ($request, $pkWorkflow, $pk05Data, $draftData, $validated, $sessionContext, $commentFileIds, $compileService, $changelogDiff, $newRevision) {
                // Save final draft_data and mark as submitted
                $pk05Data->update([
                    'draft_data' => $draftData,
                    'submitted_at' => now(),
                ]);

                // Record PK05 submitted (with changelog diff)
                $this->engine->recordAction(
                    workflow: $pkWorkflow,
                    step: 'PK05',
                    action: 'submitted',
                    userId: $request->user()->id,
                    sessionContext: $sessionContext,
                    table: 'pk05_data',
                    dataId: $pk05Data->id,
                    notes: $validated['notes'] ?? null,
                    files: ! empty($commentFileIds) ? $commentFileIds : null,
                    extra: [
                        'revision' => $newRevision,
                        'changes' => $changelogDiff,
                        'pp06_revision' => $this->getLatestPp06Revision($pkWorkflow),
                    ],
                );

                // Recompile PK04 using C + Carbon Copy strategy
                $newPk04 = $compileService->recompileFromRevision($pkWorkflow, $draftData, $newRevision);

                // Record PK04 completed
                $this->engine->recordAction(
                    workflow: $pkWorkflow,
                    step: 'PK04',
                    action: 'completed',
                    userId: null,
                    sessionContext: [],
                    table: 'pk04_program_tahunan',
                    dataId: $newPk04->id,
                    extra: [
                        'revision' => $newRevision,
                        'pp06_revision' => $this->getLatestPp06Revision($pkWorkflow),
                        'triggered_by' => [
                            'user_id' => $request->user()->id,
                            'step' => 'PK05',
                            'action' => 'submitted',
                        ],
                    ],
                );

                return $newPk04;
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        // Generate export files (outside transaction — failures are non-blocking)
        $exportResult = $compileService->generateExportFiles($newPk04, $request->user()->id, $pkWorkflow->workspace_id);
        $compileService->appendExportFilesToHistory($pkWorkflow->fresh(), $exportResult, $newRevision);

        // TODO: PABD freeze release + reset — build during PABD workflow phase

        $this->notifier->notify($pkWorkflow, 'pk05.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pk.pk04.show', $pkWorkflow),
        ], $request->user()->id);

        return to_route('admin.workflows.pk.pk04.show', [
            'pkWorkflow' => $pkWorkflow->id,
        ])->with('success', 'Revisi berhasil disubmit. PK04 telah dikompilasi ulang.');
    }

    // ──────────────────────────────────────────────────────────
    //  Index & Show
    // ──────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $scope = $this->getScope();
        $this->checkPermission("{$scope}.workflows.pk.index");

        $workspaceId = $this->session->getActiveWorkspaceId();
        $definition = $this->engine->resolveDefinition(WorkflowType::PK);

        $query = PkWorkflow::query()
            ->with(['team', 'ppWorkflow.pp01Data'])
            ->where('workspace_id', $workspaceId);

        // Team scope: restrict to user's team
        $roleId = $this->session->getActiveRoleId();
        $userTeamId = $roleId ? Role::find($roleId)?->team_id : null;
        if ($scope === 'team') {
            $query->where('team_id', $userTeamId);
        }

        // Trash toggle
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }

        // DB-level filters: PP period
        if ($request->filled('pp')) {
            $ppTahun = (int) $request->input('pp');
            $query->whereHas('ppWorkflow', fn ($q) => $q->whereHas('pp01Data', fn ($q2) => $q2->where('tahun', $ppTahun)));
        }

        // DB-level: tipe
        if ($request->filled('tipe')) {
            $query->where('tipe', $request->input('tipe'));
        }

        // DB-level: team (admin only)
        if ($scope === 'admin' && $request->filled('team')) {
            $query->where('team_id', (int) $request->input('team'));
        }

        $statusFilter = $request->input('status');
        $userCache = [];
        $roleCache = [];

        // Status is a computed filter — load all, transform, filter in-memory, then paginate
        if ($statusFilter) {
            $allWorkflows = $query->orderByDesc('created_at')->get();
            $transformed = $allWorkflows->map(fn (PkWorkflow $wf) => $this->transformPkForIndex($wf, $definition, $scope, $userCache, $roleCache));

            $transformed = $transformed->filter(fn ($item) => $item['status'] === $statusFilter);

            $filtered = $transformed->values();
            $page = (int) $request->input('page', 1);
            $perPage = 15;
            $workflows = new \Illuminate\Pagination\LengthAwarePaginator(
                $filtered->forPage($page, $perPage)->values(),
                $filtered->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $workflows = $query
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString();

            $workflows->through(fn (PkWorkflow $wf) => $this->transformPkForIndex($wf, $definition, $scope, $userCache, $roleCache));
        }

        // Available PP periods for filter
        $availablePpPeriods = \App\Models\Pp\Pp01Data::query()
            ->whereIn('pp_workflow_id', PpWorkflow::where('workspace_id', $workspaceId)->select('id'))
            ->whereNotNull('tahun')
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->map(fn ($t) => ['value' => (string) $t, 'label' => "PP-{$t}"])
            ->values();

        // Available teams for filter (admin only)
        $availableTeams = [];
        if ($scope === 'admin') {
            $teamIds = PkWorkflow::where('workspace_id', $workspaceId)->distinct()->pluck('team_id');
            $availableTeams = \App\Models\Team::whereIn('id', $teamIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($t) => ['value' => (string) $t->id, 'label' => $t->name])
                ->values();
        }

        $props = [
            'workflows' => $workflows,
            'filters' => [
                'status' => $request->input('status'),
                'pp' => $request->input('pp'),
                'tipe' => $request->input('tipe'),
                'trash' => $request->boolean('trash'),
                ...($scope === 'admin' ? ['team' => $request->input('team')] : []),
            ],
            'availablePpPeriods' => $availablePpPeriods,
            'scope' => $scope,
        ];

        if ($scope === 'admin') {
            $props['availableTeams'] = $availableTeams;
        }

        // Team scope: create prerequisites + team name
        if ($scope === 'team') {
            $createData = $this->resolveCreatePrerequisites($workspaceId, $userTeamId);
            $props['canCreate'] = $createData['canCreate'];
            $props['eligiblePpWorkflows'] = $createData['eligiblePpWorkflows'];
            $props['createMessage'] = $createData['createMessage'];
            $props['teamName'] = \App\Models\Team::find($userTeamId)?->name;
        }

        return Inertia::render('workflows/pk/index', $props);
    }

    public function show(PkWorkflow $pkWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pkWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pkWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pk.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PK);
        $history = $pkWorkflow->history ?? [];
        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $workflowStatus = $this->engine->getWorkflowStatus($history);

        // Status override: completed → revising if PK05 draft exists
        if ($workflowStatus === 'completed') {
            if ($pkWorkflow->pk05Data()->whereNull('submitted_at')->exists()) {
                $workflowStatus = 'revising';
            }
        }

        // Label: prefer pk04 program name, fallback to pk01, then "PK Baru"
        $latestPk04 = $pkWorkflow->latestPk04();
        $latestPk01 = $pkWorkflow->latestPk01();
        $label = $latestPk04?->nama_program ?? $latestPk01?->nama_program ?? 'PK Baru';

        // Creator info from first history entry
        $creatorEntry = $history[0] ?? null;
        $creatorName = 'Sistem';
        $creatorRole = null;
        $creatorTeam = null;
        $creatorDate = $pkWorkflow->created_at->format('d/m/Y');
        if ($creatorEntry && isset($creatorEntry['by'])) {
            $creatorName = User::find($creatorEntry['by'])?->name ?? 'Unknown';
            if (isset($creatorEntry['role'])) {
                $role = Role::with('team')->find($creatorEntry['role']);
                $creatorRole = $role?->name;
                $creatorTeam = $role?->team?->name;
            }
        }

        // Step aktif label — with parallel step support
        $stepAktifLabel = null;
        if ($workflowStatus === 'active' && ! empty($currentSteps)) {
            $stepNames = [
                'PK01' => 'Program Kegiatan',
                'PK02A' => 'Approval Narasi',
                'PK02B' => 'Approval Anggaran',
                'PK03' => 'RAKER',
                'PK04' => 'Program Tahunan',
                'PK05' => 'Revisi',
            ];
            $stepLabels = array_map(
                fn ($s) => $s.': '.($stepNames[$s] ?? $s),
                $currentSteps
            );
            $stepAktifLabel = implode(', ', $stepLabels);
        }

        // Stepper cycles with scope-aware URL resolver
        $wfId = $pkWorkflow->id;
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($wfId, $scope): ?string {
            $base = "/{$scope}/workflows/pk/{$wfId}";

            if ($code === 'PK01' && $dataId) {
                return "{$base}/pk01/{$dataId}";
            }
            if (in_array($code, ['PK02A', 'PK02B', 'PK03'])) {
                return "{$base}/".strtolower($code);
            }
            if ($code === 'PK04') {
                return "{$base}/pk04";
            }
            if ($code === 'PK05' && $dataId) {
                return "{$base}/pk05/{$dataId}";
            }

            return null;
        });

        // Inject step roles + parallel group info for tooltips and fork/join rendering
        $stepRoleMap = $this->resolveStepRolesForShow($pkWorkflow->team_id);
        $parallelSteps = ['PK02A', 'PK02B']; // Steps that run in parallel
        foreach ($stepperCycles as &$cycle) {
            foreach ($cycle['steps'] as &$step) {
                $step['roles'] = $stepRoleMap[$step['code']] ?? [];
                $step['parallelGroup'] = in_array($step['code'], $parallelSteps) ? 'pk02' : null;
            }
        }
        unset($cycle, $step);

        // PP context
        $ppWorkflow = $pkWorkflow->ppWorkflow;
        $ppTahun = $ppWorkflow?->latestPp01()?->tahun;

        // Budget counter (raker only)
        $budgetCounter = null;
        if ($pkWorkflow->tipe === 'raker') {
            $counters = $this->getBudgetCounters($pkWorkflow);
            $pkIni = $this->computePkIniAnggaran($pkWorkflow, $latestPk04, $latestPk01);
            $budgetCounter = [...$counters, 'pkIni' => $pkIni];
        }

        // Data Terbaru from PK04
        $dataTerbaru = null;
        if ($latestPk04) {
            $kegiatan = $latestPk04->kegiatan()
                ->with(['anggaran' => fn ($q) => $q->where('status_item', 'active')])
                ->withCount('kuisioner')
                ->orderBy('nomer_kegiatan')
                ->get();

            $kodeKategori = $latestPk04->kode_kategori;
            $kategoriLabel = $kodeKategori;
            if ($ppWorkflow) {
                $pp06 = $ppWorkflow->latestPp06();
                if ($pp06) {
                    $kat = $pp06->kodeKategoriPelayanan()->where('kode', $kodeKategori)->first();
                    if ($kat) {
                        $kategoriLabel = "{$kat->kode} - {$kat->nama}";
                    }
                }
            }

            $bulanNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

            $dataTerbaru = [
                'program' => $latestPk04->nama_program,
                'kategori_label' => $kategoriLabel,
                'revision' => $latestPk04->revision,
                'verification_code' => $latestPk04->verification_code ?? null,
                'pk04_id' => $latestPk04->id,
                'kegiatan' => $kegiatan->map(fn ($k) => [
                    'nomer' => $k->nomer_kegiatan,
                    'nama' => $k->nama_kegiatan,
                    'bulan_label' => $bulanNames[$k->bulan] ?? (string) $k->bulan,
                    'total_anggaran' => (float) $k->anggaran->sum('nominal_anggaran'),
                ])->values(),
                'total_anggaran' => (float) $kegiatan->sum(fn ($k) => $k->anggaran->sum('nominal_anggaran')),
                'kuisioner_count' => (int) $kegiatan->sum('kuisioner_count'),
            ];
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = $scope === 'team' ? 'team' : 'admin';

        return Inertia::render('workflows/pk/show', [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => $label,
                'status' => $workflowStatus,
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'informasi' => [
                'team_name' => $pkWorkflow->team?->name ?? 'Unknown',
                'pp_label' => $ppTahun ? "PP-{$ppTahun}" : '—',
                'pp_workflow_id' => $ppWorkflow?->id,
                'tipe' => $pkWorkflow->tipe,
                'dibuat_oleh' => $creatorName,
                'dibuat_oleh_role' => $creatorRole,
                'dibuat_oleh_team' => $creatorTeam,
                'dibuat_tanggal' => $creatorDate,
                'status' => $workflowStatus,
                'step_aktif' => $stepAktifLabel,
            ],
            'budgetCounter' => $budgetCounter,
            'dataTerbaru' => $dataTerbaru,
            'canTerminate' => $workflowStatus === 'active'
                && in_array("{$permPrefix}.workflows.pk.terminate", $permissions),
            'canRevise' => $workflowStatus === 'completed'
                && in_array('admin.workflows.pk.pk05.create', $permissions),
            'canDelete' => $workflowStatus === 'terminated'
                && in_array('admin.workflows.pk.destroy', $permissions),
            'canComment' => in_array("{$permPrefix}.workflows.pk.comment", $permissions),
            'canExportZip' => $dataTerbaru !== null
                && in_array("{$permPrefix}.workflows.pk.pk04.export.zip", $permissions),
            'activeRoleName' => $this->getActiveRoleName(),
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

        // Kode anggaran context for preview
        $kodeTeam = $pp06?->itemPlafonAnggaran()
            ->where('team_id', $pkWorkflow->team_id)
            ->value('kode_team');

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

        // Budget counter (same format as PK01)
        $budgetCounter = $this->getBudgetCounters($pkWorkflow);
        $thisTotal = $pk01Data
            ? (float) $pk01Data->kegiatan()->with('anggaran')->get()
                ->flatMap(fn ($k) => $k->anggaran)->sum('nominal_anggaran')
            : 0.0;

        return Inertia::render("workflows/pk/{$thisStepLower}", [
            'workflow' => [
                'id' => $pkWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'tipe' => $pkWorkflow->tipe,
            ],
            'teamName' => $teamName,
            'pk01Data' => $pk01Display,
            'previousCycles' => $previousCycles,
            'pk01Changes' => $pk01Changes,
            'pp06RevisionLabel' => $pp06RevisionLabel,
            'kodeAnggaranContext' => [
                'kode_team' => $kodeTeam,
                'tim_nama' => $teamName,
                'tahun' => $tahun ? (int) $tahun : null,
                'tipe' => $pkWorkflow->tipe,
            ],
            'parallelTrackStatus' => $parallelTrackStatus,
            'stepStatus' => $stepStatus,
            'canApprove' => $isStepActive && $scope === 'admin'
                && in_array("admin.workflows.pk.{$thisStepLower}.approve", $permissions),
            'canReject' => $isStepActive && $scope === 'admin'
                && in_array("admin.workflows.pk.{$thisStepLower}.reject", $permissions),
            'canTerminate' => $isWorkflowActive && in_array("{$permPrefix}.terminate", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'budgetCounter' => [...$budgetCounter, 'pkIni' => $thisTotal],
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
                'link' => route('admin.workflows.pk.pk03.show', $pkWorkflow),
            ], $request->user()->id);
        } else {
            // At least one rejected → record PK03 rejection to trigger invalidation cascade,
            // then PK01 re-entry with compiled feedback.
            // PK03 has rejectionTarget='PK01', so engine invalidates PK01→PK02A→PK02B.
            // Collect rejection notes from each track
            $rejectingSteps = collect([$thisStep => $thisAction, $otherStep => $otherAction])
                ->filter(fn ($a) => $a === 'rejected')
                ->keys();

            $rejectingSummary = $rejectingSteps->join(' & ');

            $trackNotes = [];
            foreach ($rejectingSteps as $rejStep) {
                for ($i = count($history) - 1; $i >= 0; $i--) {
                    if (($history[$i]['step'] ?? '') === $rejStep && ($history[$i]['action'] ?? '') === 'rejected') {
                        $note = $history[$i]['notes'] ?? null;
                        if ($note) {
                            $trackNotes[] = "{$rejStep}: {$note}";
                        }

                        break;
                    }
                }
            }

            $compiledNotes = "Ditolak otomatis — {$rejectingSummary} menolak.";
            if (! empty($trackNotes)) {
                $compiledNotes .= "\n\n".implode("\n\n", $trackNotes);
            }

            $this->engine->recordAction(
                workflow: $pkWorkflow,
                step: 'PK03',
                action: 'rejected',
                userId: null,
                sessionContext: [],
                notes: $compiledNotes,
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
                'team_link' => route('team.workflows.pk.pk01.show', [$pkWorkflow, $newPk01]),
            ], $request->user()->id);
        }
    }

    /**
     * Extract PK02A + PK02B approval info from history for PK03 display.
     *
     * @return list<array{step: string, label: string, by_name: ?string, role_name: ?string, at: ?string}>
     */
    private function resolveParallelApprovals(array $history): array
    {
        $approvals = [];

        foreach (['PK02A' => 'Approval Narasi', 'PK02B' => 'Approval Anggaran'] as $step => $label) {
            $entry = null;
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (($history[$i]['step'] ?? '') === $step && ($history[$i]['action'] ?? '') === 'approved') {
                    $entry = $history[$i];

                    break;
                }
            }

            $byName = isset($entry['by']) ? User::withTrashed()->find($entry['by'])?->name : null;
            $roleName = isset($entry['role']) ? Role::withTrashed()->find($entry['role'])?->name : null;

            $approvals[] = [
                'step' => $step,
                'label' => $label,
                'by_name' => $byName,
                'role_name' => $roleName,
                'at' => $entry['at'] ?? null,
            ];
        }

        return $approvals;
    }

    /**
     * Resolve PK03 approver info from history for PK04 display.
     *
     * @return array{by_name: ?string, role_name: ?string, at: ?string}|null
     */
    private function resolvePk03ApproverInfo(array $history): ?array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['step'] ?? '') === 'PK03' && ($history[$i]['action'] ?? '') === 'approved') {
                $entry = $history[$i];
                $byName = isset($entry['by']) ? User::withTrashed()->find($entry['by'])?->name : null;
                $roleName = isset($entry['role']) ? Role::withTrashed()->find($entry['role'])?->name : null;

                return [
                    'by_name' => $byName,
                    'role_name' => $roleName,
                    'at' => $entry['at'] ?? null,
                ];
            }
        }

        return null;
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

    /**
     * Get budget counters split by workflow step: draft (PK01), review (PK02A/PK02B), pendingRaker (PK03).
     *
     * @return array{ppLabel: ?string, tahun: ?int, teamName: string, plafon: float, accepted: float, review: float, pendingRaker: float, draft: float, proposalAccepted: float, proposalReview: float, proposalDraft: float}
     */
    private function getBudgetCounters(PkWorkflow $pkWorkflow): array
    {
        $teamName = $pkWorkflow->team?->name ?? 'Unknown';
        $pp06 = $this->getLatestPp06($pkWorkflow);
        if (! $pp06) {
            return [
                'ppLabel' => null, 'tahun' => null, 'teamName' => $teamName,
                'plafon' => 0, 'accepted' => 0, 'review' => 0, 'pendingRaker' => 0, 'draft' => 0,
                'proposalAccepted' => 0, 'proposalReview' => 0, 'proposalDraft' => 0,
            ];
        }

        $teamId = $pkWorkflow->team_id;
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun ? (int) $pp01->tahun : null;

        // Plafon for this team
        $plafon = (float) ($pp06->itemPlafonAnggaran()
            ->where('team_id', $teamId)
            ->value('plafon_anggaran') ?? 0);

        // Accepted: SUM from completed PK04 active anggaran items for this team (raker)
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

        // In-progress raker PKs split by current step
        $draft = 0.0;
        $review = 0.0;
        $pendingRaker = 0.0;
        $pkDefinition = new \App\Workflows\PkWorkflowDefinition;

        $activeRakerPkWorkflows = PkWorkflow::query()
            ->where('team_id', $teamId)
            ->where('workspace_id', $pkWorkflow->workspace_id)
            ->where('pp_workflow_id', $pkWorkflow->pp_workflow_id)
            ->where('tipe', 'raker')
            ->whereNull('deleted_at')
            ->get();

        foreach ($activeRakerPkWorkflows as $wf) {
            $status = $this->engine->getWorkflowStatus($wf->history ?? []);
            if (in_array($status, ['completed', 'terminated', 'deleted'])) {
                continue;
            }
            $latestPk01 = $wf->latestPk01();
            if (! $latestPk01) {
                continue;
            }

            $total = (float) $latestPk01->kegiatan()
                ->with('anggaran')
                ->get()
                ->flatMap(fn ($k) => $k->anggaran)
                ->sum('nominal_anggaran');

            $currentSteps = $this->engine->getCurrentSteps($pkDefinition, $wf->history ?? []);

            if (in_array('PK01', $currentSteps)) {
                $draft += $total;
            } elseif (in_array('PK03', $currentSteps)) {
                $pendingRaker += $total;
            } elseif (array_intersect(['PK02A', 'PK02B'], $currentSteps)) {
                $review += $total;
            }
        }

        // Proposal totals (outside plafon) — accepted from PK04, review/draft from in-progress proposal PKs
        $proposalAccepted = (float) Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan.pk04ProgramTahunan.pkWorkflow', fn ($q) => $q
                ->where('team_id', $teamId)
                ->where('workspace_id', $pkWorkflow->workspace_id)
                ->where('pp_workflow_id', $pkWorkflow->pp_workflow_id)
                ->where('tipe', 'proposal')
                ->whereNull('deleted_at')
            )
            ->where('status_item', 'active')
            ->sum('nominal_anggaran');

        $proposalDraft = 0.0;
        $proposalReview = 0.0;

        $activeProposalPkWorkflows = PkWorkflow::query()
            ->where('team_id', $teamId)
            ->where('workspace_id', $pkWorkflow->workspace_id)
            ->where('pp_workflow_id', $pkWorkflow->pp_workflow_id)
            ->where('tipe', 'proposal')
            ->whereNull('deleted_at')
            ->get();

        foreach ($activeProposalPkWorkflows as $wf) {
            $status = $this->engine->getWorkflowStatus($wf->history ?? []);
            if (in_array($status, ['completed', 'terminated', 'deleted'])) {
                continue;
            }
            $latestPk01 = $wf->latestPk01();
            if (! $latestPk01) {
                continue;
            }

            $total = (float) $latestPk01->kegiatan()
                ->with('anggaran')
                ->get()
                ->flatMap(fn ($k) => $k->anggaran)
                ->sum('nominal_anggaran');

            $currentSteps = $this->engine->getCurrentSteps($pkDefinition, $wf->history ?? []);

            if (in_array('PK01', $currentSteps)) {
                $proposalDraft += $total;
            } elseif (array_intersect(['PK02A', 'PK02B'], $currentSteps)) {
                $proposalReview += $total;
            }
        }

        return [
            'ppLabel' => "PP-{$pp01?->tahun} Revisi {$pp06->revision}",
            'tahun' => $tahun,
            'teamName' => $teamName,
            'plafon' => $plafon,
            'accepted' => $accepted,
            'review' => $review,
            'pendingRaker' => $pendingRaker,
            'draft' => $draft,
            'proposalAccepted' => $proposalAccepted,
            'proposalReview' => $proposalReview,
            'proposalDraft' => $proposalDraft,
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

    // ──────────────────────────────────────────────────────────
    //  Index & Show Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Transform a PkWorkflow into a row for the index page.
     *
     * @param  array<int, string>  $userCache
     * @param  array<int, string|null>  $roleCache
     * @return array<string, mixed>
     */
    private function transformPkForIndex(PkWorkflow $wf, \App\Contracts\WorkflowDefinition $definition, string $scope, array &$userCache, array &$roleCache): array
    {
        $history = $wf->history ?? [];
        $workflowStatus = $this->engine->getWorkflowStatus($history);

        // Status override: completed → revising if PK05 draft exists
        if ($workflowStatus === 'completed') {
            if ($wf->pk05Data()->whereNull('submitted_at')->exists()) {
                $workflowStatus = 'revising';
            }
        }

        // Step aktif — parallel steps joined with comma
        $stepAktif = null;
        if ($workflowStatus === 'active') {
            $currentSteps = $this->engine->getCurrentSteps($definition, $history);
            if (! empty($currentSteps)) {
                $stepAktif = implode(', ', $currentSteps);
            }
        }

        // Program name: prefer pk04, fallback to pk01
        $latestPk04 = $wf->latestPk04();
        $latestPk01 = $wf->latestPk01();
        $program = $latestPk04?->nama_program ?? $latestPk01?->nama_program;

        // Total anggaran
        $totalAnggaran = null;
        if ($latestPk04) {
            $totalAnggaran = (float) $latestPk04->kegiatan()
                ->join('pk04_anggaran', 'pk04_kegiatan.id', '=', 'pk04_anggaran.pk04_kegiatan_id')
                ->where('pk04_anggaran.status_item', 'active')
                ->sum('pk04_anggaran.nominal_anggaran');
        } elseif ($latestPk01) {
            $totalAnggaran = (float) $latestPk01->kegiatan()
                ->join('pk01_anggaran', 'pk01_kegiatan.id', '=', 'pk01_anggaran.pk01_kegiatan_id')
                ->sum('pk01_anggaran.nominal_anggaran');
            if ($totalAnggaran == 0) {
                $totalAnggaran = null;
            }
        }

        // PP label
        $ppTahun = $wf->ppWorkflow?->latestPp01()?->tahun;
        $ppLabel = $ppTahun ? "PP-{$ppTahun}" : '—';

        // Creator from first history entry
        $creatorEntry = $history[0] ?? null;
        $creatorName = 'Sistem';
        $creatorRoleName = null;
        if ($creatorEntry && isset($creatorEntry['by'])) {
            $uid = $creatorEntry['by'];
            if (! isset($userCache[$uid])) {
                $userCache[$uid] = User::find($uid)?->name ?? 'Unknown';
            }
            $creatorName = $userCache[$uid];
            if (isset($creatorEntry['role'])) {
                $rid = $creatorEntry['role'];
                if (! isset($roleCache[$rid])) {
                    $roleCache[$rid] = Role::find($rid)?->name;
                }
                $creatorRoleName = $roleCache[$rid];
            }
        }

        // "Terakhir" — scan history in reverse for last non-comment action
        [$lastActorName, $lastActorRole] = $this->resolveLastActor($history, $userCache, $roleCache);

        $row = [
            'id' => $wf->id,
            'program' => $program,
            'tipe' => $wf->tipe,
            'status' => $workflowStatus,
            'step_aktif' => $stepAktif,
            'pp_label' => $ppLabel,
            'total_anggaran' => $totalAnggaran,
            'revision' => $latestPk04?->revision,
            'terakhir_name' => $lastActorName,
            'terakhir_role' => $lastActorRole,
            'tanggal' => $wf->created_at->format('d/m/Y'),
        ];

        // Admin-only fields
        if ($scope === 'admin') {
            $row['team_name'] = $wf->team?->name ?? 'Unknown';
            $row['dibuat_oleh_name'] = $creatorName;
            $row['dibuat_oleh_role'] = $creatorRoleName;
        }

        return $row;
    }

    /**
     * Resolve last non-comment, non-system actor from history.
     *
     * @param  array<int, string>  $userCache
     * @param  array<int, string|null>  $roleCache
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveLastActor(array $history, array &$userCache, array &$roleCache): array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i];
            $action = $entry['action'] ?? '';
            if ($action === 'commented') {
                continue;
            }

            if (! isset($entry['by']) || $entry['by'] === null) {
                // System-triggered action
                if (! empty($entry['triggered_by_text'])) {
                    return ['Sistem', null];
                }

                continue;
            }

            $uid = $entry['by'];
            if (! isset($userCache[$uid])) {
                $userCache[$uid] = User::find($uid)?->name ?? 'Unknown';
            }
            $name = $userCache[$uid];
            $roleName = null;
            if (isset($entry['role'])) {
                $rid = $entry['role'];
                if (! isset($roleCache[$rid])) {
                    $roleCache[$rid] = Role::find($rid)?->name;
                }
                $roleName = $roleCache[$rid];
            }

            return [$name, $roleName];
        }

        return [null, null];
    }

    /**
     * Resolve create prerequisites for team scope index.
     *
     * @return array{canCreate: bool, eligiblePpWorkflows: list<array{id: int, label: string, tahun: int}>, createMessage: string|null}
     */
    private function resolveCreatePrerequisites(int $workspaceId, ?int $teamId): array
    {
        if (! $teamId) {
            return ['canCreate' => false, 'eligiblePpWorkflows' => [], 'createMessage' => 'Anda tidak memiliki tim aktif.'];
        }

        $permissions = $this->session->getActivePermissions();
        if (! in_array('team.workflows.pk.create', $permissions)) {
            return ['canCreate' => false, 'eligiblePpWorkflows' => [], 'createMessage' => null];
        }

        // Find PP workflows where the team has plafon
        $ppWorkflows = PpWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('pp06PeriodeTahunan', function ($q) use ($teamId) {
                $q->whereHas('itemPlafonAnggaran', fn ($q2) => $q2->where('team_id', $teamId));
            })
            ->with(['pp01Data', 'pp06PeriodeTahunan'])
            ->get();

        if ($ppWorkflows->isEmpty()) {
            // Check if any PP is complete at all
            $anyComplete = PpWorkflow::where('workspace_id', $workspaceId)
                ->whereHas('pp06PeriodeTahunan')
                ->exists();

            if (! $anyComplete) {
                return ['canCreate' => false, 'eligiblePpWorkflows' => [], 'createMessage' => 'Menunggu PP selesai untuk membuat program kegiatan.'];
            }

            return ['canCreate' => false, 'eligiblePpWorkflows' => [], 'createMessage' => 'Tim Anda belum memiliki plafon di PP periode aktif.'];
        }

        // Filter by pra-raker window
        $eligible = $ppWorkflows->filter(function ($pp) {
            $pp06 = $pp->pp06PeriodeTahunan()->latest('revision')->first();

            return $pp06
                && $pp06->tanggal_mulai_pra_raker
                && $pp06->tanggal_penetapan_program
                && now()->between($pp06->tanggal_mulai_pra_raker, $pp06->tanggal_penetapan_program);
        });

        if ($eligible->isEmpty()) {
            // Show window info from latest PP
            $latestPp = $ppWorkflows->first();
            $pp06 = $latestPp?->pp06PeriodeTahunan()->latest('revision')->first();
            $start = $pp06?->tanggal_mulai_pra_raker?->format('d/m/Y') ?? '—';
            $end = $pp06?->tanggal_penetapan_program?->format('d/m/Y') ?? '—';

            return ['canCreate' => false, 'eligiblePpWorkflows' => [], 'createMessage' => "Di luar periode pra-raker ({$start} — {$end})."];
        }

        $eligibleList = $eligible->map(function ($pp) {
            $tahun = $pp->latestPp01()?->tahun ?? 0;

            return ['id' => $pp->id, 'label' => "PP-{$tahun}", 'tahun' => $tahun];
        })->sortByDesc('tahun')->values()->all();

        return ['canCreate' => true, 'eligiblePpWorkflows' => $eligibleList, 'createMessage' => null];
    }

    /**
     * Resolve step roles for show page stepper tooltips.
     *
     * @return array<string, list<string>>
     */
    private function resolveStepRolesForShow(?int $teamId = null): array
    {
        $stepPermissions = [
            'PK01' => 'team.workflows.pk.pk01.submit',
            'PK02A' => 'admin.workflows.pk.pk02a.approve',
            'PK02B' => 'admin.workflows.pk.pk02b.approve',
            'PK03' => 'admin.workflows.pk.pk03.approve',
            'PK04' => null,
            'PK05' => 'admin.workflows.pk.pk05.submit',
        ];

        // Team-scoped steps: only show roles from the PK's owning team
        $teamScopedSteps = ['PK01'];

        $permNames = array_filter(array_values($stepPermissions));
        $permissions = Permission::whereIn('name', $permNames)
            ->with('roles.team')
            ->get()
            ->keyBy('name');

        $map = [];

        foreach ($stepPermissions as $step => $permName) {
            if ($permName === null) {
                $map[$step] = ['Kompilasi Otomatis'];

                continue;
            }

            $perm = $permissions->get($permName);
            $roles = [];

            if ($perm) {
                $filteredRoles = $perm->roles;

                // For team-scoped steps, only show roles from the PK's owning team
                if (in_array($step, $teamScopedSteps) && $teamId) {
                    $filteredRoles = $filteredRoles->filter(fn (Role $r) => $r->team_id === $teamId);
                }

                foreach ($filteredRoles->sortBy('name') as $role) {
                    $roles[] = $role->team ? "{$role->name} ({$role->team->name})" : $role->name;
                }
            }

            $map[$step] = $roles;
        }

        return $map;
    }

    /**
     * Compute "PK Ini" anggaran total for budget counter on show page.
     */
    private function computePkIniAnggaran(PkWorkflow $pkWorkflow, ?Pk04ProgramTahunan $latestPk04, ?Pk01Data $latestPk01): float
    {
        if ($latestPk04) {
            return (float) $latestPk04->kegiatan()
                ->join('pk04_anggaran', 'pk04_kegiatan.id', '=', 'pk04_anggaran.pk04_kegiatan_id')
                ->where('pk04_anggaran.status_item', 'active')
                ->sum('pk04_anggaran.nominal_anggaran');
        }

        if ($latestPk01) {
            return (float) $latestPk01->kegiatan()
                ->join('pk01_anggaran', 'pk01_kegiatan.id', '=', 'pk01_anggaran.pk01_kegiatan_id')
                ->sum('pk01_anggaran.nominal_anggaran');
        }

        return 0.0;
    }
}
