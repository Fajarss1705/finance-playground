<?php

namespace App\Http\Controllers\Workflows;

use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\Prbl01DraftRequest;
use App\Http\Requests\Workflows\Prbl01SubmitRequest;
use App\Http\Requests\Workflows\Prbl02aApproveRequest;
use App\Http\Requests\Workflows\Prbl02aRejectRequest;
use App\Http\Requests\Workflows\Prbl02bApproveRequest;
use App\Http\Requests\Workflows\Prbl02bRejectRequest;
use App\Http\Requests\Workflows\Prbl03DraftRequest;
use App\Http\Requests\Workflows\Prbl03SubmitRequest;
use App\Http\Requests\Workflows\Prbl04ApproveRequest;
use App\Http\Requests\Workflows\Prbl04RejectRequest;
use App\Http\Requests\Workflows\PrblAdminCreateRequest;
use App\Http\Requests\Workflows\PrblAdminResetRequest;
use App\Http\Requests\Workflows\PrblCommentRequest;
use App\Models\File;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kuisioner;
use App\Models\Pp\Pp06RekeningOrganisasi;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\Prbl01FotoKegiatan;
use App\Models\Prbl\Prbl01ItemKegiatan;
use App\Models\Prbl\Prbl01ItemKuisioner;
use App\Models\Prbl\Prbl01ItemRealisasi;
use App\Models\Prbl\Prbl01NotaPengeluaran;
use App\Models\Prbl\Prbl03Bukti;
use App\Models\Prbl\Prbl03Data;
use App\Models\Prbl\Prbl05FotoKegiatan;
use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\PrblCompileService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PrblWorkflowController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private ActiveSessionService $session,
        private HistoryFormatter $historyFormatter,
        private CommentService $commentService,
        private WorkflowNotifier $notifier,
        private PrblCompileService $prblCompileService,
    ) {}

    // ──────────────────────────────────────
    // Index & Show
    // ──────────────────────────────────────

    public function index(Request $request): Response
    {
        $scope = $this->getScope();
        $this->checkPermission("{$scope}.workflows.prbl.index");

        $workspaceId = $this->session->getActiveWorkspaceId();
        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);

        $query = PrblWorkflow::query()
            ->with(['team', 'ppWorkflow'])
            ->where('workspace_id', $workspaceId);

        // Team scope: restrict to user's team
        $roleId = $this->session->getActiveRoleId();
        $userTeamId = $roleId ? Role::find($roleId)?->team_id : null;
        if ($scope === 'team') {
            $query->where('team_id', $userTeamId);
        }

        // DB-level filter: PP period
        if ($request->filled('pp')) {
            $ppTahun = (int) $request->input('pp');
            $query->whereHas('ppWorkflow', fn ($q) => $q->whereHas('pp01Data', fn ($q2) => $q2->where('tahun', $ppTahun)));
        }

        // DB-level filter: bulan
        if ($request->filled('bulan')) {
            $query->where('bulan_laporan', (int) $request->input('bulan'));
        }

        // DB-level filter: team (admin only)
        if ($scope === 'admin' && $request->filled('team')) {
            $query->where('team_id', (int) $request->input('team'));
        }

        $statusFilter = $request->input('status');
        $userCache = [];
        $roleCache = [];

        // Status is computed — load all, transform, filter in-memory, then paginate
        if ($statusFilter) {
            $allWorkflows = $query->orderBy('tahun_laporan', 'desc')->orderBy('bulan_laporan')->get();
            $transformed = $allWorkflows->map(fn (PrblWorkflow $wf) => $this->transformPrblForIndex($wf, $definition, $scope, $userCache, $roleCache));
            $transformed = $transformed->filter(fn ($item) => $item['status'] === $statusFilter);
            $filtered = $transformed->values();
            $page = (int) $request->input('page', 1);
            $perPage = 15;
            $workflows = new LengthAwarePaginator(
                $filtered->forPage($page, $perPage)->values(),
                $filtered->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $workflows = $query
                ->orderBy('tahun_laporan', 'desc')
                ->orderBy('bulan_laporan')
                ->paginate(15)
                ->withQueryString();

            $workflows->through(fn (PrblWorkflow $wf) => $this->transformPrblForIndex($wf, $definition, $scope, $userCache, $roleCache));
        }

        // Available PP periods for filter
        $availablePpPeriods = \App\Models\Pp\Pp01Data::query()
            ->whereIn('pp_workflow_id', \App\Models\Pp\PpWorkflow::where('workspace_id', $workspaceId)->select('id'))
            ->whereNotNull('tahun')
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->map(fn ($t) => ['value' => (string) $t, 'label' => "PP-{$t}"])
            ->values();

        // Available teams for filter (admin only)
        $availableTeams = [];
        if ($scope === 'admin') {
            $teamIds = PrblWorkflow::where('workspace_id', $workspaceId)->distinct()->pluck('team_id');
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
                'bulan' => $request->input('bulan'),
                ...($scope === 'admin' ? ['team' => $request->input('team')] : []),
            ],
            'availablePpPeriods' => $availablePpPeriods,
            'scope' => $scope,
        ];

        if ($scope === 'admin') {
            $props['availableTeams'] = $availableTeams;
            $activePerms = $this->session->getActivePermissions();
            $canAdminReset = in_array('admin.workflows.prbl.admin_reset', $activePerms);
            $canAdminCreate = in_array('admin.workflows.prbl.admin_create', $activePerms);
            $props['canAdminReset'] = $canAdminReset;
            $props['canAdminCreate'] = $canAdminCreate;

            if ($canAdminCreate) {
                $props['creatablePpOptions'] = $this->computeCreatablePpOptions($workspaceId);
            }
        }

        return Inertia::render('workflows/prbl/index', $props);
    }

    public function show(PrblWorkflow $prblWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->history ?? [];
        $workflowStatus = $this->engine->getWorkflowStatus($history);
        $currentSteps = $this->engine->getCurrentSteps($definition, $history);

        // Label
        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanLabel = $this->bulanLabel($prblWorkflow->bulan_laporan);
        $label = "PRBL-{$teamName}-{$prblWorkflow->bulan_laporan}/{$prblWorkflow->tahun_laporan}";

        // Step aktif — can be parallel (PRBL02A + PRBL02B)
        $stepAktifLabel = null;
        if ($workflowStatus === 'active' && ! empty($currentSteps)) {
            $stepNames = [
                'PRBL01' => 'Laporan Kegiatan',
                'PRBL02A' => 'Review Narasi',
                'PRBL02B' => 'Review Anggaran',
                'PRBL03' => 'Refund & Bukti',
                'PRBL04' => 'Persetujuan Final',
                'PRBL05' => 'Laporan Bulanan',
            ];
            $stepLabels = array_map(
                fn ($s) => $s.': '.($stepNames[$s] ?? $s),
                $currentSteps
            );
            $stepAktifLabel = implode(', ', $stepLabels);
        }

        // Stepper cycles with URL resolver + parallelGroup
        $wfId = $prblWorkflow->id;
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($wfId, $scope): ?string {
            $base = "/{$scope}/workflows/prbl/{$wfId}";

            if ($code === 'PRBL01' && $dataId) {
                return "{$base}/prbl01/{$dataId}";
            }
            if (in_array($code, ['PRBL02A', 'PRBL02B', 'PRBL04'])) {
                return "{$base}/".strtolower($code);
            }
            if ($code === 'PRBL03' && $dataId) {
                return "{$base}/prbl03/{$dataId}";
            }
            if ($code === 'PRBL05') {
                return "{$base}/prbl05";
            }

            return null;
        });

        // Inject step roles + parallelGroup for fork/join rendering
        $stepRoleMap = $this->resolveStepRolesForShow($prblWorkflow->team_id);
        $parallelSteps = ['PRBL02A', 'PRBL02B'];
        foreach ($stepperCycles as &$cycle) {
            foreach ($cycle['steps'] as &$step) {
                $step['roles'] = $stepRoleMap[$step['code']] ?? [];
                $step['parallelGroup'] = in_array($step['code'], $parallelSteps) ? 'prbl02' : null;
            }
        }
        unset($cycle, $step);

        // PP context
        $ppWorkflow = $prblWorkflow->ppWorkflow;
        $ppTahun = $ppWorkflow?->latestPp01()?->tahun;

        // PABD reference (parent workflow)
        $pabdWorkflow = $prblWorkflow->pabdWorkflow;
        $pabdTeamName = $pabdWorkflow?->team?->name ?? $teamName;
        $pabdLabel = $pabdWorkflow
            ? "PABD-{$pabdTeamName}-{$pabdWorkflow->bulan_anggaran}/{$pabdWorkflow->tahun_anggaran}"
            : '—';

        // Data Terbaru from PRBL05
        $dataTerbaru = null;
        $prbl05 = $prblWorkflow->latestPrbl05();
        if ($prbl05) {
            $kegiatanCount = $prbl05->itemKegiatan()->count();
            $fotoCount = Prbl05FotoKegiatan::whereIn(
                'prbl05_item_kegiatan_id',
                $prbl05->itemKegiatan()->select('id')
            )->count();

            $dataTerbaru = [
                'bulan_label' => $bulanLabel,
                'tahun_laporan' => $prblWorkflow->tahun_laporan,
                'verification_code' => $prbl05->verification_code,
                'prbl05_id' => $prbl05->id,
                'total_anggaran_dicairkan' => (float) $prbl05->total_anggaran_dicairkan,
                'total_realisasi' => (float) $prbl05->total_realisasi,
                'total_refund' => (float) $prbl05->total_refund,
                'total_item' => (int) $prbl05->total_item,
                'kegiatan_count' => $kegiatanCount,
                'foto_count' => $fotoCount,
                'bukti_count' => $prbl05->bukti()->count(),
            ];
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = $scope === 'team' ? 'team' : 'admin';

        // Resolve latest PRBL04 rejection info for the show page (if any)
        $latestRejectionInfo = $workflowStatus === 'active'
            ? $this->resolvePrbl04RejectionInfo($history)
            : null;

        return Inertia::render('workflows/prbl/show', [
            'workflow' => [
                'id' => $prblWorkflow->id,
                'label' => $label,
                'status' => $workflowStatus,
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'informasi' => [
                'team_name' => $teamName,
                'bulan_laporan' => $prblWorkflow->bulan_laporan,
                'bulan_label' => $bulanLabel,
                'tahun_laporan' => $prblWorkflow->tahun_laporan,
                'pp_label' => $ppTahun ? "PP-{$ppTahun}" : '—',
                'pp_workflow_id' => $ppWorkflow?->id,
                'pabd_label' => $pabdLabel,
                'pabd_workflow_id' => $pabdWorkflow?->id,
                'dibuat_tanggal' => $prblWorkflow->created_at->format('d/m/Y'),
                'status' => $workflowStatus,
                'step_aktif' => $stepAktifLabel,
            ],
            'dataTerbaru' => $dataTerbaru,
            'latestRejectionInfo' => $latestRejectionInfo,
            'canComment' => in_array("{$permPrefix}.workflows.prbl.comment", $permissions),
            'canExportZip' => $dataTerbaru !== null
                && in_array("{$permPrefix}.workflows.prbl.prbl05.export.zip", $permissions),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
        ]);
    }

    // ──────────────────────────────────────
    // PRBL01 — Laporan Kegiatan & Realisasi
    // ──────────────────────────────────────

    public function prbl01Show(PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl01.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Resolve mode
        $mode = $this->resolveMode($statuses, 'PRBL01');
        if (($statuses['PRBL01']['dataId'] ?? null) !== null && $statuses['PRBL01']['dataId'] !== $prbl01Data->id) {
            $mode = 'readonly';
        }
        if ($scope === 'admin') {
            $mode = 'readonly';
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.prbl";
        $isEditable = in_array($mode, ['edit', 'create']) && $scope === 'team';

        // Labels
        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulanLabel = $bulanNames[$prblWorkflow->bulan_laporan] ?? (string) $prblWorkflow->bulan_laporan;
        $label = "PRBL-{$teamName}-{$bulanLabel}/{$prblWorkflow->tahun_laporan}";

        // PP reference
        $ppWorkflow = $prblWorkflow->ppWorkflow;
        $pp06 = $ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$ppWorkflow->latestPp01()?->tahun} Revisi {$pp06->revision}" : null;

        // PABD reference
        $pabdWorkflow = $prblWorkflow->pabdWorkflow;
        $pabdLabel = $pabdWorkflow
            ? "PABD-{$teamName}-{$bulanLabel}/{$prblWorkflow->tahun_laporan}"
            : null;

        $basePath = $scope === 'team'
            ? "/team/workflows/prbl/{$prblWorkflow->id}"
            : "/admin/workflows/prbl/{$prblWorkflow->id}";

        // Load kegiatan items grouped by program
        $kegiatanItems = $this->resolveKegiatanItems($prbl01Data, $prblWorkflow);

        // Totals
        $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);
        $totalRealisasi = (float) $prbl01Data->itemRealisasi()->sum('nominal_realisasi');

        // Cycle-back detection
        $cycle = $statuses['PRBL01']['cycle'] ?? 1;
        $isReentry = $cycle > 1 && ($statuses['PRBL01']['status'] ?? '') === 'active';
        $cycleBackNotes = $isReentry ? $this->resolveCycleBackNotes($history) : null;

        // Stepper
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($prblWorkflow, $scope): ?string {
            return $this->resolveStepUrl($code, $dataId, $prblWorkflow, $scope);
        });

        return Inertia::render('workflows/prbl/prbl01', [
            'workflow' => [
                'id' => $prblWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'prbl01' => [
                'id' => $prbl01Data->id,
                'updated_at' => $prbl01Data->updated_at->toIso8601String(),
            ],
            'kegiatanItems' => $kegiatanItems,
            'totalDicairkan' => $totalDicairkan,
            'totalRealisasi' => $totalRealisasi,
            'ppLabel' => $ppLabel,
            'pabdLabel' => $pabdLabel,
            'workflowMeta' => [
                'bulan_laporan' => $prblWorkflow->bulan_laporan,
                'bulan_label' => $bulanLabel,
                'tahun_laporan' => $prblWorkflow->tahun_laporan,
                'team_name' => $teamName,
                'auto_created_at' => $prblWorkflow->created_at->format('d/m/Y'),
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array("{$permPrefix}.prbl01.draft", $permissions),
            'canSubmit' => $isEditable && in_array("{$permPrefix}.prbl01.submit", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'isReentry' => $isReentry,
            'cycleBackNotes' => $cycleBackNotes,
            'actionRoles' => $this->resolveActionRoles([
                'team.workflows.prbl.prbl01.draft' => ['Simpan Draft', false],
                'team.workflows.prbl.prbl01.submit' => ['Submit', true],
                "{$permPrefix}.comment" => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function prbl01Draft(Prbl01DraftRequest $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.draft');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($prbl01Data, $validated['expected_updated_at']);

        // Sync narrative fields
        $this->syncNarrativeFields($prbl01Data, $validated['items'] ?? []);

        // Sync kuisioner answers
        $this->syncKuisionerAnswers($validated['items'] ?? []);

        // Sync realisasi amounts
        $this->syncRealisasiAmounts($prbl01Data, $validated['realisasi'] ?? []);

        $prbl01Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $prbl01Data,
            'prbl.prbl01.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL01',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'prbl01_data',
            dataId: $prbl01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
        );

        return to_route("{$this->getScope()}.workflows.prbl.prbl01.show", [$prblWorkflow, $prbl01Data])
            ->with('success', 'Draft PRBL01 berhasil disimpan.');
    }

    public function prbl01Submit(Prbl01SubmitRequest $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.submit');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($prbl01Data, $validated['expected_updated_at']);

        // Sync narrative fields
        $this->syncNarrativeFields($prbl01Data, $validated['items']);

        // Sync kuisioner answers
        $this->syncKuisionerAnswers($validated['items']);

        // Sync realisasi amounts
        $this->syncRealisasiAmounts($prbl01Data, $validated['realisasi']);

        // Validate realisasi constraint: sum(realisasi) <= sum(dicairkan)
        $this->validateRealisasiConstraint($prbl01Data, $prblWorkflow);

        $prbl01Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $prbl01Data,
            'prbl.prbl01.submit',
            $request->user()->id,
            $sessionContext,
        );

        // Record PRBL01 submitted
        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL01',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'prbl01_data',
            dataId: $prbl01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
        );

        // Fork: activate PRBL02A + PRBL02B (parallel)
        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL02A',
            action: 'created',
            userId: null,
            sessionContext: [],
        );

        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL02B',
            action: 'created',
            userId: null,
            sessionContext: [],
        );

        // Notify PRBL02A + PRBL02B approvers
        $this->notifier->notify($prblWorkflow, 'prbl01.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('team.workflows.prbl.prbl01.show', [$prblWorkflow, $prbl01Data])
            ->with('success', 'Laporan kegiatan berhasil disubmit. Menunggu review narasi (PRBL02A) dan anggaran (PRBL02B).');
    }

    // ──────────────────────────────────────
    // PRBL01 — Foto Kegiatan Upload/Delete
    // ──────────────────────────────────────

    public function prbl01FotoUpload(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.submit');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:51200'],
            'prbl01_item_kegiatan_id' => ['required', 'integer', 'exists:prbl01_item_kegiatan,id'],
        ]);

        $itemKegiatan = Prbl01ItemKegiatan::where('id', $request->input('prbl01_item_kegiatan_id'))
            ->where('prbl01_data_id', $prbl01Data->id)
            ->firstOrFail();

        $uploadedFile = $request->file('file');
        $sessionContext = $this->getSessionContext();

        // Upload and store file
        $file = $this->storeUploadedFile($uploadedFile, $prblWorkflow, $request->user()->id, $sessionContext, 'prbl.prbl01.foto_upload');

        // Auto-resize if > 10MB
        if ($file->size > 10 * 1024 * 1024) {
            $this->autoResizeImage($file);
        }

        // Create join row
        $fotoRow = Prbl01FotoKegiatan::create([
            'prbl01_item_kegiatan_id' => $itemKegiatan->id,
            'file_id' => $file->id,
        ]);

        return response()->json([
            'id' => $fotoRow->id,
            'file_id' => $file->id,
            'original_filename' => $file->original_filename,
            'thumbnail_url' => route('files.download', ['file' => $file, 'inline' => 1]),
        ]);
    }

    public function prbl01FotoDelete(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.submit');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'prbl01_foto_kegiatan_id' => ['required', 'integer', 'exists:prbl01_foto_kegiatan,id'],
        ]);

        $foto = Prbl01FotoKegiatan::where('id', $request->input('prbl01_foto_kegiatan_id'))
            ->whereHas('prbl01ItemKegiatan', fn ($q) => $q->where('prbl01_data_id', $prbl01Data->id))
            ->firstOrFail();

        $foto->delete();

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────
    // PRBL01 — Nota Pengeluaran Upload/Delete
    // ──────────────────────────────────────

    /** Blocked file extensions for nota upload. */
    private const BLOCKED_EXTENSIONS = ['exe', 'bat', 'sh', 'cmd', 'ps1', 'com', 'scr', 'msi', 'vbs', 'js', 'wsh', 'wsf'];

    public function prbl01NotaUpload(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.submit');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'file' => ['required', 'file', 'max:25600'],
            'prbl01_item_kegiatan_id' => ['required', 'integer', 'exists:prbl01_item_kegiatan,id'],
        ]);

        $uploadedFile = $request->file('file');
        $ext = strtolower($uploadedFile->getClientOriginalExtension());

        if (in_array($ext, self::BLOCKED_EXTENSIONS)) {
            abort(422, 'Tipe file ini tidak diperbolehkan.');
        }

        $itemKegiatan = Prbl01ItemKegiatan::where('id', $request->input('prbl01_item_kegiatan_id'))
            ->where('prbl01_data_id', $prbl01Data->id)
            ->firstOrFail();

        $sessionContext = $this->getSessionContext();
        $file = $this->storeUploadedFile($uploadedFile, $prblWorkflow, $request->user()->id, $sessionContext, 'prbl.prbl01.nota_upload');

        $notaRow = Prbl01NotaPengeluaran::create([
            'prbl01_item_kegiatan_id' => $itemKegiatan->id,
            'file_id' => $file->id,
        ]);

        return response()->json([
            'id' => $notaRow->id,
            'file_id' => $file->id,
            'original_filename' => $file->original_filename,
            'mime_type' => $file->mime_type,
            'download_url' => route('files.download', $file),
        ]);
    }

    public function prbl01NotaDelete(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.submit');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'prbl01_nota_pengeluaran_id' => ['required', 'integer', 'exists:prbl01_nota_pengeluaran,id'],
        ]);

        $nota = Prbl01NotaPengeluaran::where('id', $request->input('prbl01_nota_pengeluaran_id'))
            ->whereHas('prbl01ItemKegiatan', fn ($q) => $q->where('prbl01_data_id', $prbl01Data->id))
            ->firstOrFail();

        $nota->delete();

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────
    // Comment
    // ──────────────────────────────────────

    public function comment(PrblCommentRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.comment");

        $validated = $request->validated();
        $sessionContext = $this->getSessionContext();

        $this->commentService->store(
            $prblWorkflow,
            $validated['source'],
            $validated['notes'],
            $request->file('files', []),
            $request->user()->id,
            $sessionContext,
            'prbl',
        );

        return back()->with('success', 'Komentar berhasil ditambahkan.');
    }

    // ──────────────────────────────────────
    // Admin Reset
    // ──────────────────────────────────────

    public function adminReset(PrblAdminResetRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        $this->checkPermission('admin.workflows.prbl.admin_reset');
        $this->ensureWorkspaceOwnership($prblWorkflow);

        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->history ?? [];
        $workflowStatus = $this->engine->getWorkflowStatus($history);

        if ($workflowStatus === 'completed') {
            return back()->with('error', 'PRBL sudah selesai (PRBL05). Tidak dapat direset.');
        }

        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $currentStep = $currentSteps[0] ?? 'PRBL01';

        DB::transaction(function () use ($prblWorkflow, $currentStep, $request) {
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: $currentStep,
                action: 'reset',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                notes: $request->validated('notes'),
                extra: [
                    'reason' => 'admin_reset',
                    'cycleTarget' => 'PRBL01',
                ],
            );

            $freshPrbl01 = $this->createFreshPrbl01Data($prblWorkflow);

            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL01',
                action: 'created',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                table: 'prbl01_data',
                dataId: $freshPrbl01->id,
                extra: ['reason' => 'admin_reset'],
            );
        });

        $this->notifier->notify($prblWorkflow, 'prbl.admin_reset', [
            'action_verb' => 'direset oleh admin',
            'step_label' => 'Laporan Kegiatan (PRBL01)',
            'next_instruction' => 'PRBL telah direset oleh admin. Silakan review ulang laporan kegiatan.',
        ]);

        return back()->with('success', 'PRBL berhasil direset ke PRBL01.');
    }

    public function adminCreate(PrblAdminCreateRequest $request): RedirectResponse
    {
        $this->checkPermission('admin.workflows.prbl.admin_create');

        $workspaceId = $this->session->getActiveWorkspaceId();
        $ppWorkflowId = (int) $request->validated('pp_workflow_id');
        $teamId = (int) $request->validated('team_id');
        $bulan = (int) $request->validated('bulan');

        $ppWorkflow = PpWorkflow::query()
            ->where('id', $ppWorkflowId)
            ->where('workspace_id', $workspaceId)
            ->whereHas('pp06PeriodeTahunan')
            ->first();

        if (! $ppWorkflow) {
            return back()->with('error', 'Tidak ditemukan PP workflow dengan PP06 yang sudah selesai.');
        }

        $tahun = $ppWorkflow->latestPp06()?->tahun;
        if (! $tahun) {
            return back()->with('error', 'PP workflow tidak memiliki tahun.');
        }

        // Must have a completed PABD for this team + month + PP
        $pabdWorkflow = PabdWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflow->id)
            ->where('bulan_anggaran', $bulan)
            ->where('tahun_anggaran', $tahun)
            ->whereNull('deleted_at')
            ->first();

        if (! $pabdWorkflow) {
            return back()->with('error', 'Tidak ditemukan PABD untuk tim dan bulan ini.');
        }

        $pabdStatus = $this->engine->getWorkflowStatus($pabdWorkflow->history ?? []);
        if ($pabdStatus !== 'completed') {
            return back()->with('error', 'PABD untuk bulan ini belum selesai (PABD05).');
        }

        // Check no existing PRBL for this team + month + PP
        $exists = PrblWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflow->id)
            ->where('bulan_laporan', $bulan)
            ->where('tahun_laporan', $tahun)
            ->exists();

        if ($exists) {
            return back()->with('error', 'PRBL untuk tim dan bulan ini sudah ada.');
        }

        // Check dicairkan anggaran exists
        $hasDicairkan = Pk04Anggaran::query()
            ->where('pencairan_pabd_workflow_id', $pabdWorkflow->id)
            ->where('status_pencairan', 'dicairkan')
            ->exists();

        if (! $hasDicairkan) {
            return back()->with('error', 'Tidak ada anggaran yang dicairkan untuk bulan ini.');
        }

        $prblWorkflow = DB::transaction(function () use ($workspaceId, $teamId, $ppWorkflow, $pabdWorkflow, $bulan, $tahun, $request) {
            $prblWorkflow = PrblWorkflow::create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'team_id' => $teamId,
                'pabd_workflow_id' => $pabdWorkflow->id,
                'pp_workflow_id' => $ppWorkflow->id,
                'bulan_laporan' => $bulan,
                'tahun_laporan' => $tahun,
                'created_by_user_id' => $request->user()->id,
                'created_by_role_id' => $this->session->getActiveRoleId(),
                'created_by_team_id' => $teamId,
                'created_by_org_id' => null,
                'history' => [],
            ]);

            $freshPrbl01 = $this->createFreshPrbl01Data($prblWorkflow);

            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL01',
                action: 'created',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                table: 'prbl01_data',
                dataId: $freshPrbl01->id,
                extra: ['reason' => 'admin_create'],
            );

            return $prblWorkflow;
        });

        $this->notifier->notify($prblWorkflow, 'prbl.auto_created', [
            'actor_name' => $request->user()->name ?? 'Admin',
        ]);

        return back()->with('success', 'PRBL berhasil dibuat.');
    }

    // ──────────────────────────────────────
    // PRBL02A — Persetujuan Narasi (Monev)
    // ──────────────────────────────────────

    public function prbl02aShow(PrblWorkflow $prblWorkflow): Response
    {
        return $this->showParallelApproval($prblWorkflow, 'PRBL02A', 'PRBL02B');
    }

    public function prbl02aApprove(Prbl02aApproveRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        return $this->handlePrblApproval($request, $prblWorkflow, 'PRBL02A', 'prbl02a', 'PRBL02B', 'approve');
    }

    public function prbl02aReject(Prbl02aRejectRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        return $this->handlePrblApproval($request, $prblWorkflow, 'PRBL02A', 'prbl02a', 'PRBL02B', 'reject');
    }

    // ──────────────────────────────────────
    // PRBL02B — Persetujuan Anggaran (BU)
    // ──────────────────────────────────────

    public function prbl02bShow(PrblWorkflow $prblWorkflow): Response
    {
        return $this->showParallelApproval($prblWorkflow, 'PRBL02B', 'PRBL02A');
    }

    public function prbl02bApprove(Prbl02bApproveRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        return $this->handlePrblApproval($request, $prblWorkflow, 'PRBL02B', 'prbl02b', 'PRBL02A', 'approve');
    }

    public function prbl02bReject(Prbl02bRejectRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        return $this->handlePrblApproval($request, $prblWorkflow, 'PRBL02B', 'prbl02b', 'PRBL02A', 'reject');
    }

    // ──────────────────────────────────────
    // PRBL02A/02B Shared Logic
    // ──────────────────────────────────────

    /**
     * Show a parallel approval page (PRBL02A or PRBL02B).
     */
    private function showParallelApproval(PrblWorkflow $prblWorkflow, string $thisStep, string $otherStep): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.{$this->stepLower($thisStep)}.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Step status
        $stepStatus = $statuses[$thisStep]['status'] ?? 'pending';
        if ($stepStatus === 'completed') {
            $latestAction = $this->getLatestStepAction($thisStep, $history);
            if ($latestAction === 'approved' || $latestAction === 'rejected') {
                $stepStatus = $latestAction;
            }
        }

        // Permissions — approve/reject only available in admin scope
        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.prbl";
        $stepLower = $this->stepLower($thisStep);
        $isStepActive = $statuses[$thisStep]['status'] === 'active';
        $canApprove = $scope === 'admin' && $isStepActive && in_array("{$permPrefix}.{$stepLower}.approve", $permissions);
        $canReject = $scope === 'admin' && $isStepActive && in_array("{$permPrefix}.{$stepLower}.reject", $permissions);
        $mode = ($canApprove || $canReject) ? 'review' : 'readonly';

        // Other track status
        $otherStatus = $statuses[$otherStep]['status'] ?? 'pending';
        if ($otherStatus === 'completed') {
            $otherAction = $this->getLatestStepAction($otherStep, $history);
            if ($otherAction === 'approved' || $otherAction === 'rejected') {
                $otherStatus = $otherAction;
            }
        }
        $otherStepLabel = $otherStep === 'PRBL02A' ? 'Persetujuan Narasi' : 'Persetujuan Anggaran';
        $parallelTrackStatus = [
            'step' => $otherStep,
            'label' => $otherStepLabel,
            'status' => $otherStatus,
        ];

        // Labels
        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulanLabel = $bulanNames[$prblWorkflow->bulan_laporan] ?? (string) $prblWorkflow->bulan_laporan;
        $label = "PRBL-{$teamName}-{$bulanLabel}/{$prblWorkflow->tahun_laporan}";

        // PP reference
        $ppWorkflow = $prblWorkflow->ppWorkflow;
        $pp06 = $ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$ppWorkflow->latestPp01()?->tahun} Revisi {$pp06->revision}" : null;

        // Load latest PRBL01 data
        $latestPrbl01 = $prblWorkflow->latestPrbl01();
        $kegiatanItems = $latestPrbl01 ? $this->resolveKegiatanItems($latestPrbl01, $prblWorkflow) : [];
        $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);
        $totalRealisasi = $latestPrbl01 ? (float) $latestPrbl01->itemRealisasi()->sum('nominal_realisasi') : 0;

        // PRBL01 submitter info
        $submitterInfo = $this->resolveStepSubmitter($history, 'PRBL01');

        // Previous cycles
        $cycle = $statuses[$thisStep]['cycle'] ?? 1;
        $previousCycles = $this->resolvePreviousCycles($history, 'PRBL01');

        // Stepper
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($prblWorkflow, $scope): ?string {
            return $this->resolveStepUrl($code, $dataId, $prblWorkflow, $scope);
        });

        $thisStepLabel = $thisStep === 'PRBL02A' ? 'Persetujuan Narasi' : 'Persetujuan Anggaran';
        $pageName = $thisStep === 'PRBL02A' ? 'prbl02a' : 'prbl02b';

        return Inertia::render("workflows/prbl/{$pageName}", [
            'workflow' => [
                'id' => $prblWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'updated_at' => $prblWorkflow->updated_at->toIso8601String(),
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'kegiatanItems' => $kegiatanItems,
            'totalDicairkan' => $totalDicairkan,
            'totalRealisasi' => $totalRealisasi,
            'submitterInfo' => $submitterInfo,
            'parallelTrackStatus' => $parallelTrackStatus,
            'ppLabel' => $ppLabel,
            'workflowMeta' => [
                'bulan_laporan' => $prblWorkflow->bulan_laporan,
                'bulan_label' => $bulanLabel,
                'tahun_laporan' => $prblWorkflow->tahun_laporan,
                'team_name' => $teamName,
            ],
            'stepStatus' => $stepStatus,
            'cycle' => $cycle,
            'previousCycles' => $previousCycles,
            'mode' => $mode,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'actionRoles' => $this->resolveActionRoles([
                "admin.workflows.prbl.{$stepLower}.approve" => ['Setujui', true],
                "admin.workflows.prbl.{$stepLower}.reject" => ['Tolak', true],
                "{$permPrefix}.comment" => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => "/{$scope}/workflows/prbl/{$prblWorkflow->id}",
        ]);
    }

    /**
     * Handle PRBL02A/02B approve or reject action.
     */
    private function handlePrblApproval(
        mixed $request,
        PrblWorkflow $prblWorkflow,
        string $thisStep,
        string $thisStepLower,
        string $otherStep,
        string $action,
    ): RedirectResponse {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->checkPermission("admin.workflows.prbl.{$thisStepLower}.{$action}");
        $this->ensureStepActive($prblWorkflow, $thisStep);

        $validated = $request->validated();
        $this->checkOptimisticLock($prblWorkflow, $validated['expected_updated_at']);

        $historyAction = $action === 'approve' ? 'approved' : 'rejected';
        $sessionContext = $this->getSessionContext();

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $prblWorkflow,
            "prbl.{$thisStepLower}.{$action}",
            $request->user()->id,
            $sessionContext,
        );

        DB::transaction(function () use ($prblWorkflow, $thisStep, $historyAction, $request, $sessionContext, $validated, $fileIds, $otherStep) {
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: $thisStep,
                action: $historyAction,
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
            );

            // Check parallel join gate
            $this->handlePrblParallelJoin($request, $prblWorkflow, $thisStep, $otherStep);
        });

        $actionLabel = $historyAction === 'approved' ? 'disetujui' : 'ditolak';
        $stepLabel = $thisStep === 'PRBL02A' ? 'Persetujuan Narasi' : 'Persetujuan Anggaran';

        return redirect(route('admin.workflows.prbl.show', $prblWorkflow))
            ->with('success', "{$stepLabel} berhasil {$actionLabel}.");
    }

    /**
     * Handle the "wait for both" parallel join for PRBL02A+02B.
     */
    private function handlePrblParallelJoin(
        mixed $request,
        PrblWorkflow $prblWorkflow,
        string $thisStep,
        string $otherStep,
    ): void {
        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->fresh()->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        $otherStatus = $statuses[$otherStep]['status'] ?? 'pending';

        // Other track not yet completed → silent
        if ($otherStatus !== 'completed') {
            return;
        }

        // Both tracks completed — determine outcome
        $thisAction = $this->getLatestStepAction($thisStep, $history);
        $otherAction = $this->getLatestStepAction($otherStep, $history);

        if ($thisAction === 'approved' && $otherAction === 'approved') {
            // Both approved → compute refund and create PRBL03 data
            $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);
            $latestPrbl01 = $prblWorkflow->latestPrbl01();
            $totalRealisasi = $latestPrbl01 ? (float) $latestPrbl01->itemRealisasi()->sum('nominal_realisasi') : 0;
            $nominalRefund = max(0, $totalDicairkan - $totalRealisasi);

            $prbl03Data = Prbl03Data::create([
                'prbl_workflow_id' => $prblWorkflow->id,
                'nominal_refund' => $nominalRefund,
            ]);

            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL03',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'prbl03_data',
                dataId: $prbl03Data->id,
            );

            $this->notifier->notify($prblWorkflow, 'prbl02.both_approved', [
                'actor_name' => $request->user()->name,
                'actor_role' => $this->resolveSessionRoleName(),
            ], $request->user()->id);
        } else {
            // At least one rejected → compile feedback, cycle back to PRBL01
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

            // Record auto-rejection on PRBL03 to trigger invalidation cascade back to PRBL01
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL03',
                action: 'rejected',
                userId: null,
                sessionContext: [],
                notes: $compiledNotes,
                extra: ['cycleTarget' => 'PRBL01'],
            );

            // Create fresh PRBL01 for re-entry (auto-filled from latest)
            $previousPrbl01 = $prblWorkflow->fresh()->latestPrbl01();
            $newPrbl01 = $this->createPrbl01CycleBack($prblWorkflow, $previousPrbl01);

            // Record PRBL01 re-entry
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL01',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'prbl01_data',
                dataId: $newPrbl01->id,
            );

            $this->notifier->notify($prblWorkflow, 'prbl02.rejected', [
                'actor_name' => $request->user()->name,
                'actor_role' => $this->resolveSessionRoleName(),
                'team_link' => route('team.workflows.prbl.prbl01.show', [$prblWorkflow, $newPrbl01]),
            ], $request->user()->id);
        }
    }

    /**
     * Create a fresh PRBL01 data row for cycle-back, auto-filled from previous.
     */
    private function createPrbl01CycleBack(PrblWorkflow $prblWorkflow, ?Prbl01Data $previousPrbl01): Prbl01Data
    {
        $newPrbl01 = Prbl01Data::create([
            'prbl_workflow_id' => $prblWorkflow->id,
        ]);

        if (! $previousPrbl01) {
            return $newPrbl01;
        }

        $previousPrbl01->load([
            'itemKegiatan.fotoKegiatan',
            'itemKegiatan.notaPengeluaran',
            'itemKegiatan.itemKuisioner',
            'itemRealisasi',
        ]);

        // Copy kegiatan items with narratives, kuisioner, and realisasi
        foreach ($previousPrbl01->itemKegiatan as $itemKegiatan) {
            $newItemKegiatan = Prbl01ItemKegiatan::create([
                'prbl01_data_id' => $newPrbl01->id,
                'pk04_kegiatan_id' => $itemKegiatan->pk04_kegiatan_id,
                'masalah' => $itemKegiatan->masalah,
                'langkah_penanganan' => $itemKegiatan->langkah_penanganan,
                'harapan' => $itemKegiatan->harapan,
                'catatan_tim' => $itemKegiatan->catatan_tim,
            ]);

            // Copy foto references
            foreach ($itemKegiatan->fotoKegiatan as $foto) {
                Prbl01FotoKegiatan::create([
                    'prbl01_item_kegiatan_id' => $newItemKegiatan->id,
                    'file_id' => $foto->file_id,
                ]);
            }

            // Copy nota references
            foreach ($itemKegiatan->notaPengeluaran as $nota) {
                Prbl01NotaPengeluaran::create([
                    'prbl01_item_kegiatan_id' => $newItemKegiatan->id,
                    'file_id' => $nota->file_id,
                ]);
            }

            // Copy kuisioner
            foreach ($itemKegiatan->itemKuisioner as $k) {
                Prbl01ItemKuisioner::create([
                    'prbl01_item_kegiatan_id' => $newItemKegiatan->id,
                    'pk04_kuisioner_id' => $k->pk04_kuisioner_id,
                    'jawaban' => $k->jawaban,
                ]);
            }
        }

        // Copy realisasi
        foreach ($previousPrbl01->itemRealisasi as $realisasi) {
            Prbl01ItemRealisasi::create([
                'prbl01_data_id' => $newPrbl01->id,
                'pk04_anggaran_id' => $realisasi->pk04_anggaran_id,
                'nominal_realisasi' => $realisasi->nominal_realisasi,
                'komentar_realisasi' => $realisasi->komentar_realisasi,
            ]);
        }

        return $newPrbl01;
    }

    // ──────────────────────────────────────
    // PRBL03 — Refund & Bukti Transfer
    // ──────────────────────────────────────

    public function prbl03Show(PrblWorkflow $prblWorkflow, Prbl03Data $prbl03Data): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl03.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Mode resolution
        $mode = $this->resolveMode($statuses, 'PRBL03');
        if (($statuses['PRBL03']['dataId'] ?? null) !== null && $statuses['PRBL03']['dataId'] !== $prbl03Data->id) {
            $mode = 'readonly';
        }
        if ($scope === 'admin') {
            $mode = 'readonly';
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.prbl";
        $isEditable = in_array($mode, ['edit', 'create']) && $scope === 'team';

        // Labels
        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulanLabel = $bulanNames[$prblWorkflow->bulan_laporan] ?? (string) $prblWorkflow->bulan_laporan;
        $label = "PRBL-{$teamName}-{$bulanLabel}/{$prblWorkflow->tahun_laporan}";

        // PP reference
        $ppWorkflow = $prblWorkflow->ppWorkflow;
        $pp06 = $ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$ppWorkflow->latestPp01()?->tahun} Revisi {$pp06->revision}" : null;

        // Realisasi display
        $kegiatanItems = $this->resolveRealisasiDisplay($prblWorkflow);

        // Totals
        $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);
        $latestPrbl01 = $prblWorkflow->latestPrbl01();
        $totalRealisasi = $latestPrbl01 ? (float) $latestPrbl01->itemRealisasi()->sum('nominal_realisasi') : 0;

        // File lists
        $buktiTransferFiles = $prbl03Data->bukti()
            ->where('tipe', 'bukti_transfer')
            ->with('file')
            ->get()
            ->map(fn (Prbl03Bukti $item) => [
                'id' => $item->id,
                'file_id' => $item->file_id,
                'original_filename' => $item->file?->original_filename,
                'mime_type' => $item->file?->mime_type,
                'size' => $item->file?->size,
                'uuid' => $item->file?->uuid,
            ])
            ->values()
            ->all();

        $fotoNotaFiles = $prbl03Data->bukti()
            ->where('tipe', 'foto_nota')
            ->with('file')
            ->get()
            ->map(fn (Prbl03Bukti $item) => [
                'id' => $item->id,
                'file_id' => $item->file_id,
                'original_filename' => $item->file?->original_filename,
                'mime_type' => $item->file?->mime_type,
                'size' => $item->file?->size,
                'uuid' => $item->file?->uuid,
            ])
            ->values()
            ->all();

        // Rekening organisasi
        $rekeningOrganisasi = [];
        if ($pp06) {
            $rekeningOrganisasi = Pp06RekeningOrganisasi::where('pp06_periode_tahunan_id', $pp06->id)
                ->get()
                ->map(fn ($r) => [
                    'nama_bank' => $r->nama_bank,
                    'nama_rekening' => $r->nama_rekening,
                    'nomor_rekening' => $r->nomor_rekening,
                ])
                ->values()
                ->all();
        }

        // Submitter info (PRBL01)
        $submitterInfo = $this->resolveStepSubmitter($history, 'PRBL01');

        // Approval info (PRBL02A + PRBL02B)
        $approvalInfo = $this->resolveApprovalInfo($history);

        // Cycle-back detection
        $cycle = $statuses['PRBL03']['cycle'] ?? 1;
        $isReentry = $cycle > 1 && ($statuses['PRBL03']['status'] ?? '') === 'active';
        $cycleBackNotes = $isReentry ? $this->resolvePrbl03CycleBackNotes($history) : null;

        // Previous cycles
        $previousCycles = $this->resolvePreviousCycles($history, 'PRBL03');

        // Stepper
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($prblWorkflow, $scope): ?string {
            return $this->resolveStepUrl($code, $dataId, $prblWorkflow, $scope);
        });

        $basePath = $scope === 'team'
            ? "/team/workflows/prbl/{$prblWorkflow->id}"
            : "/admin/workflows/prbl/{$prblWorkflow->id}";

        return Inertia::render('workflows/prbl/prbl03', [
            'workflow' => [
                'id' => $prblWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'prbl03' => [
                'id' => $prbl03Data->id,
                'nominal_refund' => (float) $prbl03Data->nominal_refund,
                'keterangan' => $prbl03Data->keterangan,
                'updated_at' => $prbl03Data->updated_at->toIso8601String(),
            ],
            'kegiatanItems' => $kegiatanItems,
            'totalDicairkan' => $totalDicairkan,
            'totalRealisasi' => $totalRealisasi,
            'nominalRefund' => (float) $prbl03Data->nominal_refund,
            'buktiTransferFiles' => $buktiTransferFiles,
            'fotoNotaFiles' => $fotoNotaFiles,
            'rekeningOrganisasi' => $rekeningOrganisasi,
            'ppLabel' => $ppLabel,
            'submitterInfo' => $submitterInfo,
            'approvalInfo' => $approvalInfo,
            'cycleBackNotes' => $cycleBackNotes,
            'previousCycles' => $previousCycles,
            'workflowMeta' => [
                'bulan_laporan' => $prblWorkflow->bulan_laporan,
                'bulan_label' => $bulanLabel,
                'tahun_laporan' => $prblWorkflow->tahun_laporan,
                'team_name' => $teamName,
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array("{$permPrefix}.prbl03.draft", $permissions),
            'canSubmit' => $isEditable && in_array("{$permPrefix}.prbl03.submit", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'expectedUpdatedAt' => $prbl03Data->updated_at->toIso8601String(),
            'actionRoles' => $this->resolveActionRoles([
                'team.workflows.prbl.prbl03.draft' => ['Simpan Draft', false],
                'team.workflows.prbl.prbl03.submit' => ['Submit', true],
                "{$permPrefix}.comment" => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function prbl03Draft(Prbl03DraftRequest $request, PrblWorkflow $prblWorkflow, Prbl03Data $prbl03Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl03.draft');
        $this->ensureStepActive($prblWorkflow, 'PRBL03');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL03', $prbl03Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($prbl03Data, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        DB::transaction(function () use ($prbl03Data, $validated, $request, $prblWorkflow, $sessionContext) {
            // Remove files
            $this->removePrbl03Files($prbl03Data, $validated['remove_bukti_transfer_ids'] ?? [], 'bukti_transfer');
            $this->removePrbl03Files($prbl03Data, $validated['remove_foto_nota_ids'] ?? [], 'foto_nota');

            // Upload new files
            $this->uploadPrbl03Files($prbl03Data, $request->file('bukti_transfer_files', []), 'bukti_transfer', $prblWorkflow, $request->user()->id, $sessionContext);
            $this->uploadPrbl03Files($prbl03Data, $request->file('foto_nota_files', []), 'foto_nota', $prblWorkflow, $request->user()->id, $sessionContext);

            // Update keterangan
            $prbl03Data->update(['keterangan' => $validated['keterangan'] ?? null]);

            // Action-level files
            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $prbl03Data,
                'prbl.prbl03.draft',
                $request->user()->id,
                $sessionContext,
            );

            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL03',
                action: 'drafted',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                table: 'prbl03_data',
                dataId: $prbl03Data->id,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
            );
        });

        return to_route("{$this->getScope()}.workflows.prbl.prbl03.show", [$prblWorkflow, $prbl03Data])
            ->with('success', 'Draft PRBL03 berhasil disimpan.');
    }

    public function prbl03Submit(Prbl03SubmitRequest $request, PrblWorkflow $prblWorkflow, Prbl03Data $prbl03Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl03.submit');
        $this->ensureStepActive($prblWorkflow, 'PRBL03');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL03', $prbl03Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($prbl03Data, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        DB::transaction(function () use ($prbl03Data, $validated, $request, $prblWorkflow, $sessionContext) {
            // Remove files
            $this->removePrbl03Files($prbl03Data, $validated['remove_bukti_transfer_ids'] ?? [], 'bukti_transfer');
            $this->removePrbl03Files($prbl03Data, $validated['remove_foto_nota_ids'] ?? [], 'foto_nota');

            // Upload new files
            $this->uploadPrbl03Files($prbl03Data, $request->file('bukti_transfer_files', []), 'bukti_transfer', $prblWorkflow, $request->user()->id, $sessionContext);
            $this->uploadPrbl03Files($prbl03Data, $request->file('foto_nota_files', []), 'foto_nota', $prblWorkflow, $request->user()->id, $sessionContext);

            // Update keterangan
            $prbl03Data->update(['keterangan' => $validated['keterangan'] ?? null]);

            // Validate file counts
            $fotoNotaCount = $prbl03Data->bukti()->where('tipe', 'foto_nota')->count();
            if ($fotoNotaCount === 0) {
                abort(422, 'Minimal 1 foto nota harus diupload.');
            }

            $nominalRefund = (float) $prbl03Data->nominal_refund;
            if ($nominalRefund > 0) {
                $buktiTransferCount = $prbl03Data->bukti()->where('tipe', 'bukti_transfer')->count();
                if ($buktiTransferCount === 0) {
                    abort(422, 'Minimal 1 bukti transfer harus diupload karena ada refund.');
                }
            }

            // Action-level files
            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $prbl03Data,
                'prbl.prbl03.submit',
                $request->user()->id,
                $sessionContext,
            );

            // Record PRBL03 submitted
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL03',
                action: 'submitted',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                table: 'prbl03_data',
                dataId: $prbl03Data->id,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
            );

            // Activate PRBL04
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL04',
                action: 'created',
                userId: null,
                sessionContext: [],
            );
        });

        // Notify PRBL04 approvers (BU)
        $this->notifier->notify($prblWorkflow, 'prbl03.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('team.workflows.prbl.prbl03.show', [$prblWorkflow, $prbl03Data])
            ->with('success', 'Bukti refund berhasil disubmit. Menunggu review BU (PRBL04).');
    }

    // ──────────────────────────────────────
    // PRBL04 — Review Final (Tim BU)
    // ──────────────────────────────────────

    public function prbl04Show(PrblWorkflow $prblWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl04.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Step status
        $stepStatus = $statuses['PRBL04']['status'] ?? 'pending';
        if ($stepStatus === 'completed') {
            $latestAction = $this->getLatestStepAction('PRBL04', $history);
            if ($latestAction === 'approved' || $latestAction === 'rejected') {
                $stepStatus = $latestAction;
            }
        }

        // Permissions — admin scope only for approve/reject
        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.prbl";
        $isStepActive = $statuses['PRBL04']['status'] === 'active';
        $canApprove = $scope === 'admin' && $isStepActive && in_array("{$permPrefix}.prbl04.approve", $permissions);
        $canReject = $scope === 'admin' && $isStepActive && in_array("{$permPrefix}.prbl04.reject", $permissions);
        $mode = ($canApprove || $canReject) ? 'review' : 'readonly';

        // Labels
        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulanLabel = $bulanNames[$prblWorkflow->bulan_laporan] ?? (string) $prblWorkflow->bulan_laporan;
        $label = "PRBL-{$teamName}-{$bulanLabel}/{$prblWorkflow->tahun_laporan}";

        // PP reference
        $ppWorkflow = $prblWorkflow->ppWorkflow;
        $pp06 = $ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$ppWorkflow->latestPp01()?->tahun} Revisi {$pp06->revision}" : null;

        // Load PRBL01 data (kegiatan items with full detail)
        $latestPrbl01 = $prblWorkflow->latestPrbl01();
        $kegiatanItems = $latestPrbl01 ? $this->resolveKegiatanItems($latestPrbl01, $prblWorkflow) : [];
        $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);
        $totalRealisasi = $latestPrbl01 ? (float) $latestPrbl01->itemRealisasi()->sum('nominal_realisasi') : 0;

        // Load PRBL03 data
        $latestPrbl03 = $prblWorkflow->latestPrbl03();
        $prbl03Data = null;
        $buktiTransferFiles = [];
        $fotoNotaFiles = [];
        $nominalRefund = 0;

        if ($latestPrbl03) {
            $prbl03Data = [
                'id' => $latestPrbl03->id,
                'nominal_refund' => (float) $latestPrbl03->nominal_refund,
                'keterangan' => $latestPrbl03->keterangan,
            ];
            $nominalRefund = (float) $latestPrbl03->nominal_refund;

            $buktiTransferFiles = $latestPrbl03->bukti()
                ->where('tipe', 'bukti_transfer')
                ->with('file')
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'file_id' => $item->file_id,
                    'original_filename' => $item->file?->original_filename,
                    'mime_type' => $item->file?->mime_type,
                    'size' => $item->file?->size,
                    'uuid' => $item->file?->uuid,
                ])
                ->values()
                ->all();

            $fotoNotaFiles = $latestPrbl03->bukti()
                ->where('tipe', 'foto_nota')
                ->with('file')
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'file_id' => $item->file_id,
                    'original_filename' => $item->file?->original_filename,
                    'mime_type' => $item->file?->mime_type,
                    'size' => $item->file?->size,
                    'uuid' => $item->file?->uuid,
                ])
                ->values()
                ->all();
        }

        // Rekening organisasi
        $rekeningOrganisasi = [];
        if ($pp06) {
            $rekeningOrganisasi = Pp06RekeningOrganisasi::where('pp06_periode_tahunan_id', $pp06->id)
                ->get()
                ->map(fn ($r) => [
                    'nama_bank' => $r->nama_bank,
                    'nama_rekening' => $r->nama_rekening,
                    'nomor_rekening' => $r->nomor_rekening,
                ])
                ->values()
                ->all();
        }

        // Submitter info (PRBL01)
        $submitterInfo = $this->resolveStepSubmitter($history, 'PRBL01');

        // Approval chain: PRBL02A approver + PRBL02B approver + PRBL03 submitter
        $approvalChain = [
            ...$this->resolveApprovalInfo($history),
            'prbl03' => $this->resolveStepSubmitter($history, 'PRBL03'),
        ];

        // Previous cycles for PRBL01 and PRBL03
        $previousPrbl01Cycles = $this->resolvePreviousCycles($history, 'PRBL01');
        $previousPrbl03Cycles = $this->resolvePreviousCycles($history, 'PRBL03');

        // PRBL01 cycle
        $prbl01Cycle = $statuses['PRBL01']['cycle'] ?? 1;
        $prbl03Cycle = $statuses['PRBL03']['cycle'] ?? 1;

        // Rejection info for banners
        $rejectionInfo = null;
        if ($stepStatus === 'rejected' || ($stepStatus === 'completed' && $this->getLatestStepAction('PRBL04', $history) === 'rejected')) {
            $rejectionInfo = $this->resolvePrbl04RejectionInfo($history);
        }

        // Stepper
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($prblWorkflow, $scope): ?string {
            return $this->resolveStepUrl($code, $dataId, $prblWorkflow, $scope);
        });

        $basePath = "/{$scope}/workflows/prbl/{$prblWorkflow->id}";

        return Inertia::render('workflows/prbl/prbl04', [
            'workflow' => [
                'id' => $prblWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'updated_at' => $prblWorkflow->updated_at->toIso8601String(),
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'kegiatanItems' => $kegiatanItems,
            'totalDicairkan' => $totalDicairkan,
            'totalRealisasi' => $totalRealisasi,
            'prbl03Data' => $prbl03Data,
            'buktiTransferFiles' => $buktiTransferFiles,
            'fotoNotaFiles' => $fotoNotaFiles,
            'nominalRefund' => $nominalRefund,
            'rekeningOrganisasi' => $rekeningOrganisasi,
            'submitterInfo' => $submitterInfo,
            'approvalChain' => $approvalChain,
            'ppLabel' => $ppLabel,
            'workflowMeta' => [
                'bulan_laporan' => $prblWorkflow->bulan_laporan,
                'bulan_label' => $bulanLabel,
                'tahun_laporan' => $prblWorkflow->tahun_laporan,
                'team_name' => $teamName,
            ],
            'stepStatus' => $stepStatus,
            'prbl01Cycle' => $prbl01Cycle,
            'prbl03Cycle' => $prbl03Cycle,
            'previousPrbl01Cycles' => $previousPrbl01Cycles,
            'previousPrbl03Cycles' => $previousPrbl03Cycles,
            'rejectionInfo' => $rejectionInfo,
            'mode' => $mode,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.prbl.prbl04.approve' => ['Setujui', true],
                'admin.workflows.prbl.prbl04.reject' => ['Tolak', true],
                "{$permPrefix}.comment" => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function prbl04Approve(Prbl04ApproveRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->checkPermission('admin.workflows.prbl.prbl04.approve');
        $this->ensureStepActive($prblWorkflow, 'PRBL04');

        $validated = $request->validated();
        $this->checkOptimisticLock($prblWorkflow, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $prblWorkflow,
            'prbl.prbl04.approve',
            $request->user()->id,
            $sessionContext,
        );

        $prbl05 = DB::transaction(function () use ($prblWorkflow, $request, $sessionContext, $validated, $fileIds) {
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL04',
                action: 'approved',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
            );

            // Compile PRBL05 — snapshot all 8 tables
            $prbl05 = $this->prblCompileService->compile($prblWorkflow);

            // Record PRBL05 completed in history
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL05',
                action: 'completed',
                userId: null,
                sessionContext: [],
                table: 'prbl05_laporan_bulanan',
                dataId: $prbl05->id,
                extra: [
                    'triggered_by' => [
                        'user_id' => $request->user()->id,
                        'step' => 'PRBL04',
                        'action' => 'approved',
                    ],
                    'revision' => 0,
                    'pp06_revision' => $this->getLatestPp06Revision($prblWorkflow),
                ],
            );

            return $prbl05;
        });

        // Generate export files (outside transaction)
        $exportResult = $this->prblCompileService->generateExportFiles(
            $prbl05,
            $request->user()->id,
            $prblWorkflow->workspace_id,
        );
        $this->prblCompileService->appendExportFilesToHistory($prblWorkflow, $exportResult);

        $this->notifier->notify($prblWorkflow, 'prbl04.approved', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return redirect(route('admin.workflows.prbl.prbl04.show', $prblWorkflow))
            ->with('success', 'Laporan bulanan telah disetujui. Laporan final telah dikompilasi.');
    }

    public function prbl04Reject(Prbl04RejectRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->checkPermission('admin.workflows.prbl.prbl04.reject');
        $this->ensureStepActive($prblWorkflow, 'PRBL04');

        $validated = $request->validated();
        $this->checkOptimisticLock($prblWorkflow, $validated['expected_updated_at']);

        $rejectionTarget = $validated['rejection_target']; // 'PRBL03' or 'PRBL01'
        $sessionContext = $this->getSessionContext();

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $prblWorkflow,
            'prbl.prbl04.reject',
            $request->user()->id,
            $sessionContext,
        );

        DB::transaction(function () use ($prblWorkflow, $request, $sessionContext, $validated, $fileIds, $rejectionTarget) {
            // Record PRBL04 rejected with cycleTarget
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL04',
                action: 'rejected',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'],
                files: ! empty($fileIds) ? $fileIds : null,
                extra: [
                    'pp06_revision' => $this->getLatestPp06Revision($prblWorkflow),
                    'cycleTarget' => $rejectionTarget,
                    'rejection_target' => $rejectionTarget,
                ],
            );

            if ($rejectionTarget === 'PRBL03') {
                // Minor rejection — cycle back to PRBL03 only
                $previousPrbl03 = $prblWorkflow->fresh()->latestPrbl03();
                $newPrbl03 = $this->createPrbl03CycleBack($prblWorkflow, $previousPrbl03);

                $this->engine->recordAction(
                    workflow: $prblWorkflow,
                    step: 'PRBL03',
                    action: 'created',
                    userId: null,
                    sessionContext: [],
                    table: 'prbl03_data',
                    dataId: $newPrbl03->id,
                );
            } else {
                // Major rejection — cycle back to PRBL01
                $previousPrbl01 = $prblWorkflow->fresh()->latestPrbl01();
                $newPrbl01 = $this->createPrbl01CycleBack($prblWorkflow, $previousPrbl01);

                $this->engine->recordAction(
                    workflow: $prblWorkflow,
                    step: 'PRBL01',
                    action: 'created',
                    userId: null,
                    sessionContext: [],
                    table: 'prbl01_data',
                    dataId: $newPrbl01->id,
                );
            }
        });

        $eventKey = $rejectionTarget === 'PRBL03' ? 'prbl04.reject_to_prbl03' : 'prbl04.reject_to_prbl01';
        $this->notifier->notify($prblWorkflow, $eventKey, [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        $targetLabel = $rejectionTarget === 'PRBL03' ? 'PRBL03 (perbaiki bukti)' : 'PRBL01 (perbaiki laporan)';

        return redirect(route('admin.workflows.prbl.prbl04.show', $prblWorkflow))
            ->with('success', "Laporan ditolak. Dikembalikan ke {$targetLabel}.");
    }

    // ──────────────────────────────────────
    // PRBL04 Helper Methods
    // ──────────────────────────────────────

    /**
     * Create a fresh PRBL03 data row for cycle-back from PRBL04 reject_to_prbl03.
     */
    private function createPrbl03CycleBack(PrblWorkflow $prblWorkflow, ?Prbl03Data $previousPrbl03): Prbl03Data
    {
        $newPrbl03 = Prbl03Data::create([
            'prbl_workflow_id' => $prblWorkflow->id,
            'nominal_refund' => $previousPrbl03?->nominal_refund ?? 0,
            'keterangan' => $previousPrbl03?->keterangan,
        ]);

        // Copy bukti files from previous PRBL03
        if ($previousPrbl03) {
            foreach ($previousPrbl03->bukti as $bukti) {
                Prbl03Bukti::create([
                    'prbl03_data_id' => $newPrbl03->id,
                    'tipe' => $bukti->tipe,
                    'file_id' => $bukti->file_id,
                ]);
            }
        }

        return $newPrbl03;
    }

    /**
     * Resolve PRBL04 rejection info for banner display.
     *
     * @return array{target: string, by_name: string|null, role_name: string|null, team_name: string|null, at: string|null, notes: string|null}|null
     */
    private function resolvePrbl04RejectionInfo(array $history): ?array
    {
        $formattedHistory = $this->historyFormatter->format($history);

        for ($i = count($formattedHistory) - 1; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            if (($entry['step'] ?? '') === 'PRBL04' && ($entry['action'] ?? '') === 'rejected') {
                return [
                    'target' => $entry['rejection_target'] ?? $entry['cycleTarget'] ?? 'PRBL01',
                    'by_name' => $entry['by_name'] ?? null,
                    'role_name' => $entry['role_name'] ?? null,
                    'team_name' => $entry['team_name'] ?? null,
                    'at' => $entry['at'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                ];
            }
        }

        return null;
    }

    // ──────────────────────────────────────
    // PRBL03 Helper Methods
    // ──────────────────────────────────────

    /**
     * Resolve realisasi display data grouped by program → kegiatan → anggaran.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveRealisasiDisplay(PrblWorkflow $prblWorkflow): array
    {
        $latestPrbl01 = $prblWorkflow->latestPrbl01();

        // Index realisasi by pk04_anggaran_id
        $realisasiByAnggaran = [];
        if ($latestPrbl01) {
            $latestPrbl01->load('itemRealisasi');
            foreach ($latestPrbl01->itemRealisasi as $r) {
                $realisasiByAnggaran[$r->pk04_anggaran_id] = (float) $r->nominal_realisasi;
            }
        }

        $bulanNames = $this->bulanNames();

        // Get all dicairkan anggaran for this PABD workflow
        $dicairkanAnggaran = Pk04Anggaran::where('pencairan_pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
            ->where('status_pencairan', 'dicairkan')
            ->whereHas('pk04Kegiatan', fn ($q) => $q->where('bulan', $prblWorkflow->bulan_laporan))
            ->with(['pk04Kegiatan.pk04ProgramTahunan'])
            ->get();

        $programMap = [];

        foreach ($dicairkanAnggaran as $anggaran) {
            $kegiatan = $anggaran->pk04Kegiatan;
            if (! $kegiatan) {
                continue;
            }

            $program = $kegiatan->pk04ProgramTahunan;
            if (! $program) {
                continue;
            }

            $programId = $program->id;
            if (! isset($programMap[$programId])) {
                $programMap[$programId] = [
                    'program_id' => $program->id,
                    'program_name' => $program->nama_program,
                    'kode_kategori' => $program->kode_kategori,
                    'kegiatan' => [],
                ];
            }

            $kegiatanId = $kegiatan->id;
            if (! isset($programMap[$programId]['kegiatan'][$kegiatanId])) {
                $programMap[$programId]['kegiatan'][$kegiatanId] = [
                    'kegiatan_id' => $kegiatan->id,
                    'nama_kegiatan' => $kegiatan->nama_kegiatan,
                    'bulan' => $kegiatan->bulan,
                    'bulan_label' => $bulanNames[$kegiatan->bulan] ?? (string) $kegiatan->bulan,
                    'anggaran' => [],
                ];
            }

            $nominalDicairkan = (float) $anggaran->nominal_anggaran;
            $nominalRealisasi = $realisasiByAnggaran[$anggaran->id] ?? 0;

            $programMap[$programId]['kegiatan'][$kegiatanId]['anggaran'][] = [
                'pk04_anggaran_id' => $anggaran->id,
                'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
                'mata_anggaran' => $anggaran->mata_anggaran,
                'nominal_dicairkan' => $nominalDicairkan,
                'nominal_realisasi' => $nominalRealisasi,
                'selisih' => $nominalDicairkan - $nominalRealisasi,
            ];
        }

        // Flatten kegiatan arrays
        foreach ($programMap as &$program) {
            $program['kegiatan'] = array_values($program['kegiatan']);
        }

        return array_values($programMap);
    }

    /**
     * Resolve PRBL02A + PRBL02B approval info from history.
     *
     * @return array{prbl02a: ?array<string, mixed>, prbl02b: ?array<string, mixed>}
     */
    private function resolveApprovalInfo(array $history): array
    {
        $formattedHistory = $this->historyFormatter->format($history);
        $prbl02a = null;
        $prbl02b = null;

        for ($i = count($formattedHistory) - 1; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            $step = $entry['step'] ?? '';
            $action = $entry['action'] ?? '';

            if ($step === 'PRBL02A' && $action === 'approved' && ! $prbl02a) {
                $prbl02a = [
                    'name' => $entry['by_name'] ?? 'Unknown',
                    'role' => $entry['role_name'] ?? '',
                    'team' => $entry['team_name'] ?? null,
                    'date' => $entry['at'] ?? '',
                    'notes' => $entry['notes'] ?? null,
                ];
            }

            if ($step === 'PRBL02B' && $action === 'approved' && ! $prbl02b) {
                $prbl02b = [
                    'name' => $entry['by_name'] ?? 'Unknown',
                    'role' => $entry['role_name'] ?? '',
                    'team' => $entry['team_name'] ?? null,
                    'date' => $entry['at'] ?? '',
                    'notes' => $entry['notes'] ?? null,
                ];
            }

            if ($prbl02a && $prbl02b) {
                break;
            }
        }

        return [
            'prbl02a' => $prbl02a,
            'prbl02b' => $prbl02b,
        ];
    }

    /**
     * Resolve cycle-back notes for PRBL03 from PRBL04 reject_to_prbl03.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePrbl03CycleBackNotes(array $history): ?array
    {
        $formattedHistory = $this->historyFormatter->format($history);

        for ($i = count($formattedHistory) - 1; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            if (($entry['step'] ?? '') === 'PRBL04' && ($entry['action'] ?? '') === 'rejected') {
                $cycleTarget = $entry['cycleTarget'] ?? null;
                if ($cycleTarget === 'PRBL03') {
                    return [
                        'by_name' => $entry['by_name'] ?? null,
                        'role_name' => $entry['role_name'] ?? null,
                        'team_name' => $entry['team_name'] ?? null,
                        'at' => $entry['at'] ?? null,
                        'notes' => $entry['notes'] ?? null,
                        'files' => $entry['files'] ?? null,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Upload files to prbl03_bukti.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $files
     */
    private function uploadPrbl03Files(Prbl03Data $prbl03Data, array $files, string $tipe, PrblWorkflow $prblWorkflow, int $userId, array $sessionContext): void
    {
        foreach ($files as $uploadedFile) {
            $file = $this->storeUploadedFile($uploadedFile, $prblWorkflow, $userId, $sessionContext, "prbl.prbl03.{$tipe}");

            Prbl03Bukti::create([
                'prbl03_data_id' => $prbl03Data->id,
                'tipe' => $tipe,
                'file_id' => $file->id,
            ]);
        }
    }

    /**
     * Remove files from prbl03_bukti.
     *
     * @param  list<int>  $removeIds
     */
    private function removePrbl03Files(Prbl03Data $prbl03Data, array $removeIds, string $tipe): void
    {
        if (empty($removeIds)) {
            return;
        }

        Prbl03Bukti::where('prbl03_data_id', $prbl03Data->id)
            ->where('tipe', $tipe)
            ->whereIn('id', $removeIds)
            ->delete();
    }

    // ──────────────────────────────────────
    // PRBL02A/02B Helper Methods
    // ──────────────────────────────────────

    /**
     * Get the latest approve/reject action for a step from history.
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

    /**
     * Resolve step submitter info from history.
     *
     * @return array{name: string, role: string, team: string|null, date: string}|null
     */
    private function resolveStepSubmitter(array $history, string $step): ?array
    {
        $formattedHistory = $this->historyFormatter->format($history);

        for ($i = count($formattedHistory) - 1; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            if (($entry['step'] ?? '') === $step && ($entry['action'] ?? '') === 'submitted') {
                return [
                    'name' => $entry['by_name'] ?? 'Unknown',
                    'role' => $entry['role_name'] ?? '',
                    'team' => $entry['team_name'] ?? null,
                    'date' => $entry['at'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Resolve previous PRBL01 submission cycles for collapsed display.
     *
     * @return list<array{cycle: int, dataId: int}>
     */
    private function resolvePreviousCycles(array $history, string $step): array
    {
        $cycles = [];
        $currentCycle = 0;

        foreach ($history as $entry) {
            if (($entry['step'] ?? '') === $step && ($entry['action'] ?? '') === 'created') {
                $currentCycle++;
                if (isset($entry['id'])) {
                    $cycles[] = ['cycle' => $currentCycle, 'dataId' => $entry['id']];
                }
            }
        }

        // Remove the last one (current cycle)
        if (! empty($cycles)) {
            array_pop($cycles);
        }

        return $cycles;
    }

    /**
     * Resolve step URL for the stepper.
     */
    private function resolveStepUrl(string $code, ?int $dataId, PrblWorkflow $prblWorkflow, string $scope): ?string
    {
        $base = "/{$scope}/workflows/prbl/{$prblWorkflow->id}";

        return match ($code) {
            'PRBL01' => $dataId ? "{$base}/prbl01/{$dataId}" : null,
            'PRBL02A' => "{$base}/prbl02a",
            'PRBL02B' => "{$base}/prbl02b",
            'PRBL03' => $dataId ? "{$base}/prbl03/{$dataId}" : null,
            'PRBL04' => "{$base}/prbl04",
            default => null,
        };
    }

    /**
     * Get lowercase step code for route/permission names.
     */
    private function stepLower(string $step): string
    {
        return strtolower($step);
    }

    // ──────────────────────────────────────
    // PRBL01 Data Helpers
    // ──────────────────────────────────────

    /**
     * Resolve kegiatan items grouped by program for the PRBL01 page.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveKegiatanItems(Prbl01Data $prbl01Data, PrblWorkflow $prblWorkflow): array
    {
        $prbl01Data->load([
            'itemKegiatan.pk04Kegiatan.pk04ProgramTahunan',
            'itemKegiatan.fotoKegiatan.file',
            'itemKegiatan.notaPengeluaran.file',
            'itemKegiatan.itemKuisioner.pk04Kuisioner',
            'itemRealisasi.pk04Anggaran',
        ]);

        $bulanNames = $this->bulanNames();

        // Index realisasi by pk04_anggaran_id for nesting under kegiatan
        $realisasiByAnggaran = [];
        foreach ($prbl01Data->itemRealisasi as $realisasi) {
            $realisasiByAnggaran[$realisasi->pk04_anggaran_id] = $realisasi;
        }

        // Group kegiatan by program
        $programMap = [];

        foreach ($prbl01Data->itemKegiatan as $itemKegiatan) {
            $pk04Kegiatan = $itemKegiatan->pk04Kegiatan;
            if (! $pk04Kegiatan) {
                continue;
            }

            $program = $pk04Kegiatan->pk04ProgramTahunan;
            if (! $program) {
                continue;
            }

            $programId = $program->id;

            if (! isset($programMap[$programId])) {
                $programMap[$programId] = [
                    'program_id' => $program->id,
                    'program_name' => $program->nama_program,
                    'kode_kategori' => $program->kode_kategori,
                    'kegiatan' => [],
                ];
            }

            // Resolve dicairkan anggaran for this kegiatan
            $dicairkanAnggaran = Pk04Anggaran::where('pk04_kegiatan_id', $pk04Kegiatan->id)
                ->where('pencairan_pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
                ->where('status_pencairan', 'dicairkan')
                ->get();

            $realisasiItems = [];
            foreach ($dicairkanAnggaran as $anggaran) {
                $realisasi = $realisasiByAnggaran[$anggaran->id] ?? null;
                $realisasiItems[] = [
                    'prbl01_item_realisasi_id' => $realisasi?->id,
                    'pk04_anggaran_id' => $anggaran->id,
                    'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
                    'mata_anggaran' => $anggaran->mata_anggaran,
                    'nominal_anggaran' => (float) $anggaran->nominal_anggaran,
                    'nominal_realisasi' => $realisasi ? (float) $realisasi->nominal_realisasi : 0,
                    'komentar_realisasi' => $realisasi?->komentar_realisasi,
                ];
            }

            // Fotos
            $fotos = $itemKegiatan->fotoKegiatan->map(fn ($foto) => [
                'id' => $foto->id,
                'file_id' => $foto->file_id,
                'original_filename' => $foto->file?->original_filename ?? '',
                'thumbnail_url' => $foto->file ? route('files.download', ['file' => $foto->file, 'inline' => 1]) : null,
            ])->values()->all();

            // Nota
            $nota = $itemKegiatan->notaPengeluaran->map(fn ($n) => [
                'id' => $n->id,
                'file_id' => $n->file_id,
                'original_filename' => $n->file?->original_filename ?? '',
                'mime_type' => $n->file?->mime_type ?? '',
                'download_url' => $n->file ? route('files.download', $n->file) : null,
            ])->values()->all();

            // Kuisioner
            $kuisioner = $itemKegiatan->itemKuisioner->map(fn ($k) => [
                'prbl01_item_kuisioner_id' => $k->id,
                'pk04_kuisioner_id' => $k->pk04_kuisioner_id,
                'pertanyaan' => $k->pk04Kuisioner?->pertanyaan ?? '',
                'tipe' => $k->pk04Kuisioner?->tipe ?? '',
                'satuan' => $k->pk04Kuisioner?->satuan,
                'jawaban' => $k->jawaban,
            ])->values()->all();

            $programMap[$programId]['kegiatan'][] = [
                'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                'pk04_kegiatan_id' => $pk04Kegiatan->id,
                'nama_kegiatan' => $pk04Kegiatan->nama_kegiatan,
                'bulan' => $pk04Kegiatan->bulan,
                'bulan_label' => $bulanNames[$pk04Kegiatan->bulan] ?? (string) $pk04Kegiatan->bulan,
                'masalah' => $itemKegiatan->masalah,
                'langkah_penanganan' => $itemKegiatan->langkah_penanganan,
                'harapan' => $itemKegiatan->harapan,
                'catatan_tim' => $itemKegiatan->catatan_tim,
                'fotos' => $fotos,
                'nota' => $nota,
                'kuisioner' => $kuisioner,
                'realisasi' => $realisasiItems,
            ];
        }

        return array_values($programMap);
    }

    /**
     * Calculate total dicairkan amount for this PRBL workflow.
     */
    private function calculateTotalDicairkan(PrblWorkflow $prblWorkflow): float
    {
        return (float) Pk04Anggaran::where('pencairan_pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
            ->where('status_pencairan', 'dicairkan')
            ->whereHas('pk04Kegiatan', fn ($q) => $q->where('bulan', $prblWorkflow->bulan_laporan))
            ->sum('nominal_anggaran');
    }

    /**
     * Sync narrative fields on prbl01_item_kegiatan rows.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncNarrativeFields(Prbl01Data $prbl01Data, array $items): void
    {
        foreach ($items as $item) {
            $id = $item['prbl01_item_kegiatan_id'] ?? null;
            if (! $id) {
                continue;
            }

            Prbl01ItemKegiatan::where('id', $id)
                ->where('prbl01_data_id', $prbl01Data->id)
                ->update([
                    'masalah' => $item['masalah'] ?? null,
                    'langkah_penanganan' => $item['langkah_penanganan'] ?? null,
                    'harapan' => $item['harapan'] ?? null,
                    'catatan_tim' => $item['catatan_tim'] ?? null,
                ]);
        }
    }

    /**
     * Sync kuisioner answers on prbl01_item_kuisioner rows.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncKuisionerAnswers(array $items): void
    {
        foreach ($items as $item) {
            foreach ($item['kuisioner'] ?? [] as $k) {
                $id = $k['prbl01_item_kuisioner_id'] ?? null;
                if (! $id) {
                    continue;
                }

                Prbl01ItemKuisioner::where('id', $id)
                    ->update(['jawaban' => $k['jawaban'] ?? null]);
            }
        }
    }

    /**
     * Sync realisasi amounts on prbl01_item_realisasi rows.
     *
     * @param  list<array<string, mixed>>  $realisasi
     */
    private function syncRealisasiAmounts(Prbl01Data $prbl01Data, array $realisasi): void
    {
        foreach ($realisasi as $r) {
            $id = $r['prbl01_item_realisasi_id'] ?? null;
            if (! $id) {
                continue;
            }

            Prbl01ItemRealisasi::where('id', $id)
                ->where('prbl01_data_id', $prbl01Data->id)
                ->update([
                    'nominal_realisasi' => $r['nominal_realisasi'] ?? 0,
                    'komentar_realisasi' => $r['komentar_realisasi'] ?? null,
                ]);
        }
    }

    /**
     * Validate that sum(realisasi) <= sum(dicairkan) for this month.
     */
    private function validateRealisasiConstraint(Prbl01Data $prbl01Data, PrblWorkflow $prblWorkflow): void
    {
        $totalRealisasi = (float) $prbl01Data->itemRealisasi()->sum('nominal_realisasi');
        $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);

        if ($totalRealisasi > $totalDicairkan) {
            $selisih = $totalRealisasi - $totalDicairkan;
            abort(422, 'Total realisasi (Rp '.number_format($totalRealisasi, 0, ',', '.').') melebihi total anggaran dicairkan (Rp '.number_format($totalDicairkan, 0, ',', '.').'). Selisih: Rp '.number_format($selisih, 0, ',', '.').'.');
        }
    }

    /**
     * Resolve cycle-back rejection notes from history.
     *
     * Handles two sources:
     * 1. PRBL02A+02B compiled rejection (wait-for-both)
     * 2. PRBL04 reject_to_prbl01 (major rejection)
     *
     * @return array<string, mixed>|null
     */
    private function resolveCycleBackNotes(array $history): ?array
    {
        $formattedHistory = $this->historyFormatter->format($history);

        // Check from most recent backwards
        for ($i = count($formattedHistory) - 1; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            $step = $entry['step'] ?? '';
            $action = $entry['action'] ?? '';

            // PRBL04 reject_to_prbl01
            if ($step === 'PRBL04' && $action === 'rejected') {
                return [
                    'source' => 'prbl04',
                    'prbl04' => [
                        'step' => 'PRBL04',
                        'step_label' => 'Review Final',
                        'by_name' => $entry['by_name'] ?? null,
                        'role_name' => $entry['role_name'] ?? null,
                        'team_name' => $entry['team_name'] ?? null,
                        'at' => $entry['at'] ?? null,
                        'notes' => $entry['notes'] ?? null,
                        'files' => $entry['files'] ?? null,
                    ],
                ];
            }

            // PRBL02A+02B compiled rejection
            if (in_array($step, ['PRBL02A', 'PRBL02B']) && in_array($action, ['approved', 'rejected'])) {
                return $this->compilePrbl02Feedback($formattedHistory, $i);
            }
        }

        return null;
    }

    /**
     * Compile PRBL02A+02B feedback from both tracks.
     *
     * @return array<string, mixed>
     */
    private function compilePrbl02Feedback(array $formattedHistory, int $startIndex): array
    {
        $prbl02a = null;
        $prbl02b = null;

        // Scan backwards from startIndex to find the latest PRBL02A and PRBL02B entries
        for ($i = $startIndex; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            $step = $entry['step'] ?? '';
            $action = $entry['action'] ?? '';

            if ($step === 'PRBL02A' && in_array($action, ['approved', 'rejected']) && ! $prbl02a) {
                $prbl02a = [
                    'status' => $action === 'approved' ? 'Disetujui' : 'Ditolak',
                    'by_name' => $entry['by_name'] ?? null,
                    'role_name' => $entry['role_name'] ?? null,
                    'team_name' => $entry['team_name'] ?? null,
                    'at' => $entry['at'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                    'files' => $entry['files'] ?? null,
                ];
            }

            if ($step === 'PRBL02B' && in_array($action, ['approved', 'rejected']) && ! $prbl02b) {
                $prbl02b = [
                    'status' => $action === 'approved' ? 'Disetujui' : 'Ditolak',
                    'by_name' => $entry['by_name'] ?? null,
                    'role_name' => $entry['role_name'] ?? null,
                    'team_name' => $entry['team_name'] ?? null,
                    'at' => $entry['at'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                    'files' => $entry['files'] ?? null,
                ];
            }

            if ($prbl02a && $prbl02b) {
                break;
            }

            // Stop scanning if we hit PRBL01 submitted (previous cycle boundary)
            if ($step === 'PRBL01' && $action === 'submitted') {
                break;
            }
        }

        return [
            'source' => 'prbl02',
            'prbl02a' => $prbl02a,
            'prbl02b' => $prbl02b,
        ];
    }

    // ──────────────────────────────────────
    // File Upload Helpers
    // ──────────────────────────────────────

    /**
     * Store an uploaded file to disk and create a File model.
     */
    private function storeUploadedFile(
        \Illuminate\Http\UploadedFile $uploadedFile,
        PrblWorkflow $prblWorkflow,
        int $userId,
        array $sessionContext,
        string $sourceRoute,
    ): File {
        $uuid = (string) Str::uuid();
        $ext = $uploadedFile->getClientOriginalExtension();
        $filename = "{$uuid}.{$ext}";
        $workspaceId = $sessionContext['workspace'] ?? $prblWorkflow->workspace_id;
        $path = "files/{$workspaceId}/".now()->format('Y/m')."/{$filename}";

        Storage::disk('local')->putFileAs(
            dirname($path),
            $uploadedFile,
            basename($path),
        );

        $roleId = $sessionContext['role'] ?? null;
        $role = $roleId ? Role::with('team')->find($roleId) : null;

        return File::create([
            'uuid' => $uuid,
            'original_filename' => $uploadedFile->getClientOriginalName(),
            'filename' => $filename,
            'mime_type' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
            'disk' => 'local',
            'path' => $path,
            'user_id' => $userId,
            'role_id' => $roleId,
            'team_id' => $role?->team_id,
            'organization_id' => $role?->team?->organization_id,
            'workspace_id' => $workspaceId,
            'source_route' => $sourceRoute,
            'attachable_type' => Prbl01Data::class,
            'attachable_id' => 0, // Not polymorphic — tracked via join tables
        ]);
    }

    /**
     * Auto-resize an image file if it exceeds 10MB.
     */
    private function autoResizeImage(File $file): void
    {
        $fullPath = Storage::disk('local')->path($file->path);

        if (! file_exists($fullPath)) {
            return;
        }

        $maxBytes = 10 * 1024 * 1024;
        $currentSize = filesize($fullPath);

        if ($currentSize <= $maxBytes) {
            return;
        }

        $imageInfo = @getimagesize($fullPath);
        if (! $imageInfo) {
            return;
        }

        $mime = $imageInfo['mime'];
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($fullPath),
            'image/png' => @imagecreatefrompng($fullPath),
            'image/webp' => @imagecreatefromwebp($fullPath),
            default => null,
        };

        if (! $image) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Reduce dimensions iteratively until under 10MB
        for ($scale = 0.8; $scale >= 0.2; $scale -= 0.1) {
            $newWidth = (int) ($width * $scale);
            $newHeight = (int) ($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            match ($mime) {
                'image/jpeg' => imagejpeg($resized, $fullPath, 85),
                'image/png' => imagepng($resized, $fullPath, 6),
                'image/webp' => imagewebp($resized, $fullPath, 85),
                default => null,
            };

            imagedestroy($resized);

            $newSize = filesize($fullPath);
            if ($newSize <= $maxBytes) {
                $file->update(['size' => $newSize]);

                break;
            }
        }

        imagedestroy($image);
    }

    // ──────────────────────────────────────
    // PRBL05 — Laporan Bulanan Final (Read-Only)
    // ──────────────────────────────────────

    public function prbl05Show(PrblWorkflow $prblWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl05.show");

        $prbl05 = $prblWorkflow->latestPrbl05();
        if (! $prbl05) {
            abort(404, 'PRBL05 belum dikompilasi.');
        }

        $prbl05->load([
            'itemKegiatan.fotoKegiatan.file',
            'itemKegiatan.notaPengeluaran.file',
            'itemKegiatan.itemKuisioner.pk04Kuisioner',
            'itemKegiatan.pk04Kegiatan.pk04ProgramTahunan.pkWorkflow',
            'itemRealisasi.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan',
            'rekeningOrganisasi',
            'bukti.file',
        ]);

        $bulanNames = $this->bulanNames();
        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanLabel = $bulanNames[$prblWorkflow->bulan_laporan] ?? (string) $prblWorkflow->bulan_laporan;
        $tahun = $prblWorkflow->tahun_laporan;
        $label = "PRBL-{$teamName}-{$bulanLabel}/{$tahun}";

        // PP reference label
        $pp06 = $prblWorkflow->ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$tahun} Revisi {$pp06->revision}" : null;

        // PABD reference label
        $pabdWorkflow = $prblWorkflow->pabdWorkflow;
        $pabdLabel = $pabdWorkflow
            ? "PABD-{$teamName}-{$bulanLabel}/{$tahun}"
            : null;

        // Build grouped kegiatan items (program → kegiatan → narratives + foto + nota + kuisioner)
        $kegiatanItems = $this->buildPrbl05KegiatanItems($prbl05, $bulanNames);

        // Build grouped realisasi items (program → kegiatan → anggaran with realisasi)
        $realisasiItems = $this->buildPrbl05RealisasiItems($prbl05, $bulanNames);

        // Rekening organisasi
        $rekeningOrganisasi = $prbl05->rekeningOrganisasi->map(fn ($r) => [
            'id' => $r->id,
            'nama_bank' => $r->nama_bank,
            'nama_rekening' => $r->nama_rekening,
            'nomor_rekening' => $r->nomor_rekening,
        ])->values();

        // Bukti transfer + foto nota files
        $buktiTransferFiles = $prbl05->bukti->where('tipe', 'bukti_transfer')->map(fn ($b) => [
            'id' => $b->id,
            'file_id' => $b->file_id,
            'filename' => $b->file?->original_filename ?? 'Unknown',
            'mime_type' => $b->file?->mime_type,
            'size' => $b->file?->size,
            'path' => $b->file?->path,
            'download_url' => $b->file?->path ? route('files.download', $b->file) : null,
        ])->values();

        $fotoNotaFiles = $prbl05->bukti->where('tipe', 'foto_nota')->map(fn ($b) => [
            'id' => $b->id,
            'file_id' => $b->file_id,
            'filename' => $b->file?->original_filename ?? 'Unknown',
            'mime_type' => $b->file?->mime_type,
            'size' => $b->file?->size,
            'path' => $b->file?->path,
            'download_url' => $b->file?->path ? route('files.download', $b->file) : null,
        ])->values();

        // Export files from file records
        $exportFiles = $this->resolvePrbl05ExportFiles($prbl05);

        // History + formatting
        $history = $prblWorkflow->history ?? [];
        $workflowStatus = $this->engine->getWorkflowStatus($history);

        $permPrefix = "{$scope}.workflows.prbl";
        $permissions = $this->session->getActivePermissions();
        $basePath = $scope === 'team'
            ? "/team/workflows/prbl/{$prblWorkflow->id}"
            : "/admin/workflows/prbl/{$prblWorkflow->id}";

        // Stepper data
        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $stepperData = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($prblWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/prbl/{$prblWorkflow->id}";
            if ($dataId) {
                $stepLower = strtolower($code);

                return "{$base}/{$stepLower}/{$dataId}";
            }

            return null;
        });

        // Action roles
        $actionRoles = $this->resolveActionRoles([
            "{$scope}.workflows.prbl.prbl05.show" => ['Lihat', false],
            "{$scope}.workflows.prbl.prbl05.export.pdf" => ['Unduh PDF', false],
            "{$scope}.workflows.prbl.prbl05.export.excel" => ['Unduh Excel', false],
            "{$scope}.workflows.prbl.prbl05.export.zip" => ['Unduh ZIP', false],
            "{$scope}.workflows.prbl.comment" => ['Komentar', false],
        ]);

        return Inertia::render('workflows/prbl/prbl05', [
            'workflow' => [
                'id' => $prblWorkflow->id,
                'label' => $label,
                'status' => $workflowStatus,
                'bulan_laporan' => $prblWorkflow->bulan_laporan,
                'tahun_laporan' => $tahun,
                'bulan_label' => $bulanLabel,
            ],
            'prbl05' => [
                'id' => $prbl05->id,
                'verification_code' => $prbl05->verification_code,
                'prbl01_created_by_user_name' => $prbl05->prbl01_created_by_user_name,
                'prbl01_created_by_role_name' => $prbl05->prbl01_created_by_role_name,
                'prbl01_created_by_team_name' => $prbl05->prbl01_created_by_team_name,
                'prbl01_created_at' => $prbl05->prbl01_created_at?->toIso8601String(),
                'prbl02a_approved_by_user_name' => $prbl05->prbl02a_approved_by_user_name,
                'prbl02a_approved_by_role_name' => $prbl05->prbl02a_approved_by_role_name,
                'prbl02a_approved_by_team_name' => $prbl05->prbl02a_approved_by_team_name,
                'prbl02a_approved_at' => $prbl05->prbl02a_approved_at?->toIso8601String(),
                'prbl02b_approved_by_user_name' => $prbl05->prbl02b_approved_by_user_name,
                'prbl02b_approved_by_role_name' => $prbl05->prbl02b_approved_by_role_name,
                'prbl02b_approved_by_team_name' => $prbl05->prbl02b_approved_by_team_name,
                'prbl02b_approved_at' => $prbl05->prbl02b_approved_at?->toIso8601String(),
                'prbl03_created_by_user_name' => $prbl05->prbl03_created_by_user_name,
                'prbl03_created_by_role_name' => $prbl05->prbl03_created_by_role_name,
                'prbl03_created_by_team_name' => $prbl05->prbl03_created_by_team_name,
                'prbl03_created_at' => $prbl05->prbl03_created_at?->toIso8601String(),
                'prbl04_approved_by_user_name' => $prbl05->prbl04_approved_by_user_name,
                'prbl04_approved_by_role_name' => $prbl05->prbl04_approved_by_role_name,
                'prbl04_approved_by_team_name' => $prbl05->prbl04_approved_by_team_name,
                'prbl04_approved_at' => $prbl05->prbl04_approved_at?->toIso8601String(),
                'keterangan' => $prbl05->keterangan,
                'total_anggaran_dicairkan' => (float) $prbl05->total_anggaran_dicairkan,
                'total_realisasi' => (float) $prbl05->total_realisasi,
                'total_refund' => (float) $prbl05->total_refund,
                'total_item' => $prbl05->total_item,
                'created_at' => $prbl05->created_at?->toIso8601String(),
            ],
            'kegiatanItems' => $kegiatanItems,
            'realisasiItems' => $realisasiItems,
            'rekeningOrganisasi' => $rekeningOrganisasi,
            'buktiTransferFiles' => $buktiTransferFiles,
            'fotoNotaFiles' => $fotoNotaFiles,
            'exportFiles' => $exportFiles,
            'ppLabel' => $ppLabel,
            'pabdLabel' => $pabdLabel,
            'verifyUrl' => url('/verify'),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'commentUrl' => "{$basePath}/comment",
            'scope' => $scope,
            'history' => $this->historyFormatter->format($history),
            'actionRoles' => $actionRoles,
            'activeRoleName' => $this->getActiveRoleName(),
            'stepperData' => $stepperData,
            'teamName' => $teamName,
        ]);
    }

    /**
     * Build grouped kegiatan items: program → kegiatan → narratives + foto + nota + kuisioner.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPrbl05KegiatanItems(Prbl05LaporanBulanan $prbl05, array $bulanNames): array
    {
        $grouped = [];

        foreach ($prbl05->itemKegiatan as $item) {
            $kegiatan = $item->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;
            $pkWorkflow = $program?->pkWorkflow;

            $programKey = $program?->id ?? 0;

            if (! isset($grouped[$programKey])) {
                $grouped[$programKey] = [
                    'program_id' => $program?->id,
                    'program_name' => $program?->nama_program ?? 'Unknown',
                    'kode_kategori' => $program?->kode_kategori ?? '',
                    'tipe' => $pkWorkflow?->tipe ?? 'raker',
                    'kegiatan' => [],
                ];
            }

            $grouped[$programKey]['kegiatan'][] = [
                'prbl05_item_kegiatan_id' => $item->id,
                'kegiatan_id' => $kegiatan?->id,
                'nama_kegiatan' => $kegiatan?->nama_kegiatan ?? 'Unknown',
                'bulan' => $kegiatan?->bulan,
                'bulan_label' => $bulanNames[$kegiatan?->bulan ?? 0] ?? '',
                'masalah' => $item->masalah,
                'langkah_penanganan' => $item->langkah_penanganan,
                'harapan' => $item->harapan,
                'catatan_tim' => $item->catatan_tim,
                'foto' => $item->fotoKegiatan->map(fn ($f) => [
                    'id' => $f->id,
                    'file_id' => $f->file_id,
                    'filename' => $f->file?->original_filename ?? 'Unknown',
                    'mime_type' => $f->file?->mime_type,
                    'size' => $f->file?->size,
                    'path' => $f->file?->path,
                    'download_url' => $f->file?->path ? route('files.download', $f->file) : null,
                    'thumbnail_url' => $f->file?->path ? route('files.download', ['file' => $f->file, 'inline' => 1]) : null,
                ])->values()->all(),
                'nota' => $item->notaPengeluaran->map(fn ($n) => [
                    'id' => $n->id,
                    'file_id' => $n->file_id,
                    'filename' => $n->file?->original_filename ?? 'Unknown',
                    'mime_type' => $n->file?->mime_type,
                    'size' => $n->file?->size,
                    'path' => $n->file?->path,
                    'download_url' => $n->file?->path ? route('files.download', $n->file) : null,
                ])->values()->all(),
                'kuisioner' => $item->itemKuisioner->map(fn ($q) => [
                    'id' => $q->id,
                    'pk04_kuisioner_id' => $q->pk04_kuisioner_id,
                    'pertanyaan' => $q->pk04Kuisioner?->pertanyaan ?? '-',
                    'tipe' => $q->pk04Kuisioner?->tipe ?? '-',
                    'satuan' => $q->pk04Kuisioner?->satuan ?? '-',
                    'jawaban' => $q->jawaban,
                ])->values()->all(),
            ];
        }

        return collect($grouped)->values()->all();
    }

    /**
     * Build grouped realisasi items: program → kegiatan → anggaran with realisasi.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPrbl05RealisasiItems(Prbl05LaporanBulanan $prbl05, array $bulanNames): array
    {
        $grouped = [];

        foreach ($prbl05->itemRealisasi as $item) {
            $pk04Anggaran = $item->pk04Anggaran;
            if (! $pk04Anggaran) {
                continue;
            }

            $kegiatan = $pk04Anggaran->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;

            $programKey = $program?->id ?? 0;
            $kegiatanKey = $kegiatan?->id ?? 0;

            if (! isset($grouped[$programKey])) {
                $grouped[$programKey] = [
                    'program_id' => $program?->id,
                    'program_name' => $program?->nama_program ?? 'Unknown',
                    'kode_kategori' => $program?->kode_kategori ?? '',
                    'kegiatan' => [],
                ];
            }

            if (! isset($grouped[$programKey]['kegiatan'][$kegiatanKey])) {
                $grouped[$programKey]['kegiatan'][$kegiatanKey] = [
                    'kegiatan_id' => $kegiatan?->id,
                    'nama_kegiatan' => $kegiatan?->nama_kegiatan ?? 'Unknown',
                    'bulan' => $kegiatan?->bulan,
                    'bulan_label' => $bulanNames[$kegiatan?->bulan ?? 0] ?? '',
                    'anggaran' => [],
                ];
            }

            $grouped[$programKey]['kegiatan'][$kegiatanKey]['anggaran'][] = [
                'prbl05_item_realisasi_id' => $item->id,
                'pk04_anggaran_id' => $pk04Anggaran->id,
                'kode_anggaran_baru' => $pk04Anggaran->kode_anggaran_baru,
                'mata_anggaran' => $pk04Anggaran->mata_anggaran,
                'nominal_anggaran' => (float) $item->nominal_anggaran,
                'nominal_realisasi' => (float) $item->nominal_realisasi,
                'selisih' => (float) $item->selisih,
                'komentar_realisasi' => $item->komentar_realisasi,
            ];
        }

        // Convert nested kegiatan to indexed arrays
        return collect($grouped)->map(function ($program) {
            $program['kegiatan'] = array_values($program['kegiatan']);

            return $program;
        })->values()->all();
    }

    /**
     * Resolve export files (PDF + Excel) for PRBL05.
     *
     * @return array{pdf: array|null, excel: array|null}
     */
    private function resolvePrbl05ExportFiles(Prbl05LaporanBulanan $prbl05): array
    {
        $files = File::where('attachable_type', Prbl05LaporanBulanan::class)
            ->where('attachable_id', $prbl05->id)
            ->whereIn('source_route', ['prbl05.export.pdf', 'prbl05.export.excel'])
            ->get();

        $pdf = $files->firstWhere('source_route', 'prbl05.export.pdf');
        $excel = $files->firstWhere('source_route', 'prbl05.export.excel');

        $map = fn (?File $f) => $f ? [
            'id' => $f->id,
            'filename' => $f->original_filename,
            'path' => $f->path,
            'available' => $f->path !== null,
        ] : null;

        return [
            'pdf' => $map($pdf),
            'excel' => $map($excel),
        ];
    }

    // ──────────────────────────────────────
    // PRBL05 Exports
    // ──────────────────────────────────────

    public function prbl05ExportPdf(PrblWorkflow $prblWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl05.export.pdf");

        $prbl05 = $prblWorkflow->latestPrbl05();
        if (! $prbl05) {
            return back()->withErrors(['export' => 'PRBL05 belum dikompilasi.']);
        }

        $file = File::where('attachable_type', Prbl05LaporanBulanan::class)
            ->where('attachable_id', $prbl05->id)
            ->where('source_route', 'prbl05.export.pdf')
            ->first();

        if ($file && $file->path && Storage::disk($file->disk)->exists($file->path)) {
            return response()->download(
                Storage::disk($file->disk)->path($file->path),
                $file->original_filename,
                ['Content-Type' => $file->mime_type],
            );
        }

        return back()->withErrors(['export' => 'File PDF belum tersedia. Silakan coba lagi nanti.']);
    }

    public function prbl05ExportExcel(PrblWorkflow $prblWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl05.export.excel");

        $prbl05 = $prblWorkflow->latestPrbl05();
        if (! $prbl05) {
            return back()->withErrors(['export' => 'PRBL05 belum dikompilasi.']);
        }

        $file = File::where('attachable_type', Prbl05LaporanBulanan::class)
            ->where('attachable_id', $prbl05->id)
            ->where('source_route', 'prbl05.export.excel')
            ->first();

        if ($file && $file->path && Storage::disk($file->disk)->exists($file->path)) {
            return response()->download(
                Storage::disk($file->disk)->path($file->path),
                $file->original_filename,
                ['Content-Type' => $file->mime_type],
            );
        }

        return back()->withErrors(['export' => 'File Excel belum tersedia. Silakan coba lagi nanti.']);
    }

    public function prbl05ExportZip(PrblWorkflow $prblWorkflow): \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl05.export.zip");

        $prbl05 = $prblWorkflow->latestPrbl05();
        if (! $prbl05) {
            return back()->withErrors(['export' => 'PRBL05 belum dikompilasi.']);
        }

        $prbl05->load([
            'itemKegiatan.fotoKegiatan.file',
            'itemKegiatan.notaPengeluaran.file',
            'itemKegiatan.pk04Kegiatan',
            'bukti.file',
        ]);

        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulan = $bulanNames[$prblWorkflow->bulan_laporan] ?? (string) $prblWorkflow->bulan_laporan;
        $tahun = $prblWorkflow->tahun_laporan;
        $zipFilename = "PRBL-{$teamName}-{$bulan}-{$tahun}-Laporan-Bulanan.zip";
        $zipFilename = preg_replace('/[^\w\-. ]/', '', $zipFilename);

        // Export files (PDF + Excel)
        $exportFiles = File::where('attachable_type', Prbl05LaporanBulanan::class)
            ->where('attachable_id', $prbl05->id)
            ->whereNotNull('path')
            ->get();

        // Comment attachment files
        $historyFileIds = collect($prblWorkflow->history ?? [])
            ->pluck('files')
            ->filter()
            ->flatten()
            ->unique()
            ->all();
        $commentFiles = ! empty($historyFileIds)
            ? File::whereIn('id', $historyFileIds)->whereNotNull('path')->get()
            : collect();

        return response()->streamDownload(function () use ($prbl05, $exportFiles, $commentFiles) {
            $zip = new \ZipStream\ZipStream(
                outputStream: fopen('php://output', 'wb'),
                sendHttpHeaders: false,
            );

            $addedFiles = [];
            $addFile = function ($zip, string $folder, File $file) use (&$addedFiles) {
                $name = $file->original_filename;
                $key = $folder ? "{$folder}/{$name}" : $name;
                $counter = 1;
                while (isset($addedFiles[$key])) {
                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                    $base = pathinfo($name, PATHINFO_FILENAME);
                    $key = $folder ? "{$folder}/{$base} ({$counter}).{$ext}" : "{$base} ({$counter}).{$ext}";
                    $counter++;
                }
                $addedFiles[$key] = true;

                $path = Storage::disk($file->disk)->path($file->path);
                if (file_exists($path)) {
                    $zip->addFileFromPath($key, $path);
                }
            };

            // PDF + Excel at root
            foreach ($exportFiles as $file) {
                $addFile($zip, '', $file);
            }

            // Foto Kegiatan + Nota per kegiatan subdirectory
            foreach ($prbl05->itemKegiatan as $item) {
                $kegiatanName = $item->pk04Kegiatan?->nama_kegiatan ?? "Kegiatan-{$item->id}";
                $safeKegiatanName = preg_replace('/[^\w\-. ]/', '', $kegiatanName);

                foreach ($item->fotoKegiatan as $foto) {
                    if ($foto->file && $foto->file->path) {
                        $addFile($zip, "Foto Kegiatan/{$safeKegiatanName}", $foto->file);
                    }
                }

                foreach ($item->notaPengeluaran as $nota) {
                    if ($nota->file && $nota->file->path) {
                        $addFile($zip, "Nota Pengeluaran/{$safeKegiatanName}", $nota->file);
                    }
                }
            }

            // Bukti transfer + foto nota
            foreach ($prbl05->bukti as $bukti) {
                if ($bukti->file && $bukti->file->path) {
                    $folder = $bukti->tipe === 'bukti_transfer' ? 'Bukti Transfer' : 'Foto Nota Kantor Pusat';
                    $addFile($zip, $folder, $bukti->file);
                }
            }

            // Comment attachments
            foreach ($commentFiles as $file) {
                $addFile($zip, 'Lampiran Komentar', $file);
            }

            $zip->finish();
        }, $zipFilename, ['Content-Type' => 'application/zip']);
    }

    // ──────────────────────────────────────
    // Shared Helpers (same pattern as PABD)
    // ──────────────────────────────────────

    private function getLatestPp06Revision(PrblWorkflow $prblWorkflow): int
    {
        return $prblWorkflow->ppWorkflow?->latestPp06()?->revision ?? 0;
    }

    private function getScope(): string
    {
        $routeName = request()->route()?->getName() ?? '';

        return str_starts_with($routeName, 'team.') ? 'team' : 'admin';
    }

    private function ensureWorkspaceOwnership(PrblWorkflow $prblWorkflow): void
    {
        if ($prblWorkflow->workspace_id !== $this->session->getActiveWorkspaceId()) {
            abort(403, 'Workflow bukan milik workspace aktif.');
        }
    }

    private function ensureTeamOwnership(PrblWorkflow $prblWorkflow): void
    {
        $roleId = $this->session->getActiveRoleId();
        $userTeamId = $roleId ? Role::find($roleId)?->team_id : null;

        if ($userTeamId === null || $prblWorkflow->team_id !== $userTeamId) {
            abort(403, 'Workflow bukan milik tim Anda.');
        }
    }

    private function checkPermission(string $permission): void
    {
        if (! in_array($permission, $this->session->getActivePermissions())) {
            abort(403);
        }
    }

    private function ensureStepActive(PrblWorkflow $prblWorkflow, string $step): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);

        if (! in_array($step, $this->engine->getCurrentSteps($definition, $prblWorkflow->history ?? []))) {
            abort(409, 'Step ini sudah tidak aktif.');
        }
    }

    private function ensureCurrentRecord(PrblWorkflow $prblWorkflow, string $step, int $recordId): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $statuses = $this->engine->getStepStatuses($definition, $prblWorkflow->history ?? []);
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
        $permissions = \App\Models\Permission::whereIn('name', array_keys($permissionLabels))
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

    private function resolveSessionRoleName(): string
    {
        $roleId = $this->session->getActiveRoleId();
        if (! $roleId) {
            return 'System';
        }

        return Role::find($roleId)?->name ?? 'Unknown';
    }

    /** @return array<int, string> */
    private function bulanNames(): array
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
    }

    private function bulanLabel(int $bulan): string
    {
        return $this->bulanNames()[$bulan] ?? (string) $bulan;
    }

    /** @return array<string, mixed> */
    private function transformPrblForIndex(PrblWorkflow $wf, \App\Contracts\WorkflowDefinition $definition, string $scope, array &$userCache, array &$roleCache): array
    {
        $history = $wf->history ?? [];
        $engineStatus = $this->engine->getWorkflowStatus($history);
        $status = $engineStatus === 'completed' ? 'completed' : 'active';

        // Step aktif — can be multiple (parallel PRBL02A + PRBL02B)
        $stepAktif = null;
        if ($status === 'active') {
            $currentSteps = $this->engine->getCurrentSteps($definition, $history);
            if (! empty($currentSteps)) {
                $stepAktif = implode(', ', $currentSteps);
            }
        }

        // PP label
        $ppTahun = $wf->ppWorkflow?->latestPp01()?->tahun;
        $ppLabel = $ppTahun ? "PP-{$ppTahun}" : '—';

        // Total realisasi + refund (only after PRBL05)
        $prbl05 = $wf->latestPrbl05();
        $totalRealisasi = $prbl05 ? (float) $prbl05->total_realisasi : null;
        $totalRefund = $prbl05 ? (float) $prbl05->total_refund : null;

        // Terakhir
        [$lastActorName, $lastActorRole] = $this->resolveLastActor($history, $userCache, $roleCache);

        $row = [
            'id' => $wf->id,
            'bulan_laporan' => $wf->bulan_laporan,
            'bulan_label' => $this->bulanLabel($wf->bulan_laporan),
            'tahun_laporan' => $wf->tahun_laporan,
            'status' => $status,
            'step_aktif' => $stepAktif,
            'pp_label' => $ppLabel,
            'total_realisasi' => $totalRealisasi,
            'total_refund' => $totalRefund,
            'terakhir_name' => $lastActorName,
            'terakhir_role' => $lastActorRole,
            'tanggal' => $wf->created_at->format('d/m/Y'),
        ];

        if ($scope === 'admin') {
            $row['team_name'] = $wf->team?->name ?? 'Unknown';
        }

        return $row;
    }

    /** @return array{0: string|null, 1: string|null} */
    private function resolveLastActor(array $history, array &$userCache, array &$roleCache): array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i];
            $action = $entry['action'] ?? '';
            if ($action === 'commented') {
                continue;
            }

            if (! isset($entry['by']) || $entry['by'] === null) {
                return ['Sistem', null];
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
     * Create fresh PRBL01 data from the completed PABD's dicairkan anggaran.
     */
    private function createFreshPrbl01Data(PrblWorkflow $prblWorkflow): Prbl01Data
    {
        $prbl01 = Prbl01Data::create([
            'prbl_workflow_id' => $prblWorkflow->id,
        ]);

        $dicairkanAnggaran = Pk04Anggaran::query()
            ->where('pencairan_pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
            ->where('status_pencairan', 'dicairkan')
            ->get();

        $anggaranByKegiatan = $dicairkanAnggaran->groupBy(fn ($anggaran) => $anggaran->pk04_kegiatan_id);

        foreach ($anggaranByKegiatan as $kegiatanId => $anggaranItems) {
            $itemKegiatan = Prbl01ItemKegiatan::create([
                'prbl01_data_id' => $prbl01->id,
                'pk04_kegiatan_id' => $kegiatanId,
            ]);

            $kuisioners = Pk04Kuisioner::query()
                ->where('pk04_kegiatan_id', $kegiatanId)
                ->get();

            foreach ($kuisioners as $kuisioner) {
                Prbl01ItemKuisioner::create([
                    'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                    'pk04_kuisioner_id' => $kuisioner->id,
                ]);
            }
        }

        foreach ($dicairkanAnggaran as $anggaran) {
            Prbl01ItemRealisasi::create([
                'prbl01_data_id' => $prbl01->id,
                'pk04_anggaran_id' => $anggaran->id,
            ]);
        }

        return $prbl01;
    }

    /**
     * Return all PP workflows with completed PP06, each with their eligible teams/months for PRBL creation.
     *
     * @return array<int, array{pp_workflow_id: int, pp_label: string, teams: array}>
     */
    private function computeCreatablePpOptions(int $workspaceId): array
    {
        $ppWorkflows = PpWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('pp06PeriodeTahunan')
            ->get();

        $result = [];

        foreach ($ppWorkflows as $ppWorkflow) {
            $tahun = $ppWorkflow->latestPp06()?->tahun;
            if (! $tahun) {
                continue;
            }

            $teams = $this->computeCreatableTeamMonths($workspaceId, $ppWorkflow->id, $tahun);
            if (! empty($teams)) {
                $result[] = [
                    'pp_workflow_id' => $ppWorkflow->id,
                    'pp_label' => "PP-{$tahun}",
                    'teams' => $teams,
                ];
            }
        }

        return $result;
    }

    /**
     * For a given PP workflow, compute which teams have eligible months for PRBL creation.
     *
     * A month is eligible when:
     * - PABD for that team+month is completed (PABD05)
     * - No existing PRBL for that team+month
     * - Previous month's PRBL (if any) is completed
     *
     * @return array<int, array{team_id: int, team_name: string, months: int[]}>
     */
    private function computeCreatableTeamMonths(int $workspaceId, int $ppWorkflowId, int $tahun): array
    {
        // Find all completed PABDs
        $pabdWorkflows = PabdWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->where('tahun_anggaran', $tahun)
            ->whereNull('deleted_at')
            ->get();

        $completedPabdByTeam = [];
        foreach ($pabdWorkflows as $pabd) {
            if ($this->engine->getWorkflowStatus($pabd->history ?? []) === 'completed') {
                $completedPabdByTeam[$pabd->team_id][] = $pabd->bulan_anggaran;
            }
        }

        if (empty($completedPabdByTeam)) {
            return [];
        }

        $result = [];

        foreach ($completedPabdByTeam as $teamId => $completedMonths) {
            // Find months that already have a PRBL
            $existingPrblMonths = PrblWorkflow::query()
                ->where('workspace_id', $workspaceId)
                ->where('team_id', $teamId)
                ->where('pp_workflow_id', $ppWorkflowId)
                ->where('tahun_laporan', $tahun)
                ->pluck('bulan_laporan')
                ->toArray();

            // Find the last completed PRBL month for chain logic
            $lastCompletedPrblMonth = 0;
            $prbls = PrblWorkflow::query()
                ->where('workspace_id', $workspaceId)
                ->where('team_id', $teamId)
                ->where('pp_workflow_id', $ppWorkflowId)
                ->where('tahun_laporan', $tahun)
                ->get();

            foreach ($prbls as $prbl) {
                if ($this->engine->getWorkflowStatus($prbl->history ?? []) === 'completed') {
                    $lastCompletedPrblMonth = max($lastCompletedPrblMonth, $prbl->bulan_laporan);
                }
            }

            $hasAnyPrbl = ! empty($existingPrblMonths);

            $eligible = [];
            sort($completedMonths);
            foreach ($completedMonths as $month) {
                if (in_array($month, $existingPrblMonths)) {
                    continue;
                }

                if ($hasAnyPrbl && $month <= $lastCompletedPrblMonth) {
                    continue;
                }

                if ($hasAnyPrbl && $lastCompletedPrblMonth === 0) {
                    continue;
                }

                $eligible[] = $month;
            }

            if (! empty($eligible)) {
                $team = \App\Models\Team::find($teamId);
                $result[] = [
                    'team_id' => $teamId,
                    'team_name' => $team?->name ?? 'Unknown',
                    'months' => array_values($eligible),
                ];
            }
        }

        return $result;
    }

    /** @return array<string, array<array{name: string, users: string[]}>> */
    private function resolveStepRolesForShow(?int $teamId = null): array
    {
        $stepPermissions = [
            'PRBL01' => 'team.workflows.prbl.prbl01.submit',
            'PRBL02A' => 'admin.workflows.prbl.prbl02a.approve',
            'PRBL02B' => 'admin.workflows.prbl.prbl02b.approve',
            'PRBL03' => 'team.workflows.prbl.prbl03.submit',
            'PRBL04' => 'admin.workflows.prbl.prbl04.approve',
            'PRBL05' => null,
        ];

        $teamScopedSteps = ['PRBL01', 'PRBL03'];

        $permNames = array_filter(array_values($stepPermissions));
        $permissions = Permission::whereIn('name', $permNames)
            ->with(['roles.team', 'roles.users'])
            ->get()
            ->keyBy('name');

        $result = [];
        foreach ($stepPermissions as $stepCode => $permName) {
            if (! $permName) {
                $result[$stepCode] = [];

                continue;
            }

            $perm = $permissions->get($permName);
            $roles = [];

            if ($perm) {
                foreach ($perm->roles->sortBy(fn (Role $r) => $r->team ? "{$r->name} ({$r->team->name})" : $r->name) as $role) {
                    if (in_array($stepCode, $teamScopedSteps) && $teamId && $role->team_id !== $teamId) {
                        continue;
                    }
                    $roles[] = [
                        'name' => $role->team ? "{$role->name} ({$role->team->name})" : $role->name,
                        'users' => $role->users->pluck('name')->sort()->values()->all(),
                    ];
                }
            }

            $result[$stepCode] = $roles;
        }

        return $result;
    }
}
