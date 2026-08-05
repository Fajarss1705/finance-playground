<?php

namespace App\Http\Controllers\Workflows;

use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\Pabd01DraftRequest;
use App\Http\Requests\Workflows\Pabd01SubmitRequest;
use App\Http\Requests\Workflows\Pabd02aDraftRequest;
use App\Http\Requests\Workflows\Pabd02aSubmitRequest;
use App\Http\Requests\Workflows\Pabd02bApproveRequest;
use App\Http\Requests\Workflows\Pabd02bDraftRequest;
use App\Http\Requests\Workflows\Pabd02bRejectRequest;
use App\Http\Requests\Workflows\Pabd03ApproveRequest;
use App\Http\Requests\Workflows\Pabd03RejectRequest;
use App\Http\Requests\Workflows\Pabd04DraftRequest;
use App\Http\Requests\Workflows\Pabd04SubmitRequest;
use App\Http\Requests\Workflows\PabdAdminCreateRequest;
use App\Http\Requests\Workflows\PabdAdminResetRequest;
use App\Http\Requests\Workflows\PabdCommentRequest;
use App\Models\File;
use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd02aData;
use App\Models\Pabd\Pabd02aItemPerubahan;
use App\Models\Pabd\Pabd02bData;
use App\Models\Pabd\Pabd04Data;
use App\Models\Pabd\Pabd04ItemBuktiTransfer;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04AnggaranCatatanPerubahan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\User;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\PabdCompileService;
use App\Services\PkCompileService;
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

class PabdWorkflowController extends Controller
{
    /**
     * Change types that move an existing pk04_anggaran to a different month.
     *
     * Both run through the same machinery — same source anggaran, same
     * bulan_awal/bulan_tujuan pair, same PK04 revisioned recompile. Only the
     * permitted direction and the labels differ. `proposal_baru` is not one of
     * these: it creates a new item rather than moving an existing one.
     *
     * @var list<string>
     */
    private const TIPE_PEMINDAHAN_BULAN = ['tarik_maju', 'tarik_mundur'];

    public function __construct(
        private WorkflowEngine $engine,
        private ActiveSessionService $session,
        private HistoryFormatter $historyFormatter,
        private CommentService $commentService,
        private WorkflowNotifier $notifier,
        private PkCompileService $compileService,
        private PabdCompileService $pabdCompileService,
    ) {}

    // ──────────────────────────────────────
    // Index & Show
    // ──────────────────────────────────────

    public function index(Request $request): Response
    {
        $scope = $this->getScope();
        $this->checkPermission("{$scope}.workflows.pabd.index");

        $workspaceId = $this->session->getActiveWorkspaceId();
        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);

        $query = PabdWorkflow::query()
            ->with(['team', 'ppWorkflow.pp01Data'])
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
            $query->where('bulan_anggaran', (int) $request->input('bulan'));
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
            $allWorkflows = $query->orderBy('tahun_anggaran', 'desc')->orderBy('bulan_anggaran')->get();
            $transformed = $allWorkflows->map(fn (PabdWorkflow $wf) => $this->transformPabdForIndex($wf, $definition, $scope, $userCache, $roleCache));
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
                ->orderBy('tahun_anggaran', 'desc')
                ->orderBy('bulan_anggaran')
                ->paginate(15)
                ->withQueryString();

            $workflows->through(fn (PabdWorkflow $wf) => $this->transformPabdForIndex($wf, $definition, $scope, $userCache, $roleCache));
        }

        // Available PP periods for filter
        $availablePpPeriods = Pp01Data::query()
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
            $teamIds = PabdWorkflow::where('workspace_id', $workspaceId)->distinct()->pluck('team_id');
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
            $canAdminReset = in_array('admin.workflows.pabd.admin_reset', $activePerms);
            $canAdminCreate = in_array('admin.workflows.pabd.admin_create', $activePerms);
            $props['canAdminReset'] = $canAdminReset;
            $props['canAdminCreate'] = $canAdminCreate;

            if ($canAdminCreate) {
                $props['creatablePpOptions'] = $this->computeCreatablePpOptions($workspaceId);
            }
        }

        return Inertia::render('workflows/pabd/index', $props);
    }

    public function show(PabdWorkflow $pabdWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $history = $pabdWorkflow->history ?? [];
        $workflowStatus = $this->engine->getWorkflowStatus($history);
        $currentSteps = $this->engine->getCurrentSteps($definition, $history);

        // Label
        $teamName = $pabdWorkflow->team?->name ?? 'Unknown';
        $bulanLabel = $this->bulanLabel($pabdWorkflow->bulan_anggaran);
        $label = "PABD-{$teamName}-{$pabdWorkflow->bulan_anggaran}/{$pabdWorkflow->tahun_anggaran}";

        // Step aktif — always single step (linear)
        $stepAktifLabel = null;
        if ($workflowStatus === 'active' && ! empty($currentSteps)) {
            $stepNames = [
                'PABD01' => 'Checklist Pencairan',
                'PABD02A' => 'Form Perubahan',
                'PABD02B' => 'Persetujuan Perubahan',
                'PABD03' => 'Persetujuan Transfer',
                'PABD04' => 'Bukti Transfer',
                'PABD05' => 'Pengajuan Bulanan',
            ];
            $stepLabels = array_map(
                fn ($s) => $s.': '.($stepNames[$s] ?? $s),
                $currentSteps
            );
            $stepAktifLabel = implode(', ', $stepLabels);
        }

        // Stepper cycles with scope-aware URL resolver
        $wfId = $pabdWorkflow->id;
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($wfId, $scope): ?string {
            $base = "/{$scope}/workflows/pabd/{$wfId}";

            if ($code === 'PABD01' && $dataId) {
                return "{$base}/pabd01/{$dataId}";
            }
            if ($code === 'PABD02A' && $dataId) {
                return "{$base}/pabd02a/{$dataId}";
            }
            if ($code === 'PABD02B') {
                return "{$base}/pabd02b";
            }
            if ($code === 'PABD03') {
                return "{$base}/pabd03";
            }
            if ($code === 'PABD04' && $dataId) {
                return "{$base}/pabd04/{$dataId}";
            }
            if ($code === 'PABD05') {
                return "{$base}/pabd05";
            }

            return null;
        });

        // Inject step roles + branch group for stepper rendering
        $stepRoleMap = $this->resolveStepRolesForShow($pabdWorkflow->team_id);
        $this->injectStepperMeta($stepperCycles, $stepRoleMap);

        // PP context
        $ppWorkflow = $pabdWorkflow->ppWorkflow;
        $ppTahun = $ppWorkflow?->latestPp01()?->tahun;

        // Data Terbaru from PABD05
        $dataTerbaru = null;
        $pabd05 = $pabdWorkflow->latestPabd05();
        if ($pabd05) {
            $totalAnggaranDicairkan = (float) $pabd05->itemAnggaran()
                ->where('status', 'dicairkan')
                ->sum('nominal_anggaran');

            $totalAnggaranHangus = (float) $pabd05->itemAnggaran()
                ->where('status', 'hangus')
                ->sum('nominal_anggaran');

            $totalItemDicairkan = (int) ($pabd05->total_item_dicairkan ?? $pabd05->itemAnggaran()->where('status', 'dicairkan')->count());
            $totalItemHangus = (int) ($pabd05->total_item_hangus ?? $pabd05->itemAnggaran()->where('status', 'hangus')->count());

            $dataTerbaru = [
                'bulan_label' => $bulanLabel,
                'tahun_anggaran' => $pabdWorkflow->tahun_anggaran,
                'verification_code' => $pabd05->verification_code,
                'pabd05_id' => $pabd05->id,
                'total_item_dicairkan' => $totalItemDicairkan,
                'total_anggaran_dicairkan' => $totalAnggaranDicairkan,
                'total_item_hangus' => $totalItemHangus,
                'total_anggaran_hangus' => $totalAnggaranHangus,
                'grand_total' => $totalAnggaranDicairkan + $totalAnggaranHangus,
                'total_items' => $totalItemDicairkan + $totalItemHangus,
                'bukti_transfer_count' => $pabd05->buktiTransfer()->count(),
            ];
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = $scope === 'team' ? 'team' : 'admin';

        return Inertia::render('workflows/pabd/show', [
            'workflow' => [
                'id' => $pabdWorkflow->id,
                'label' => $label,
                'status' => $workflowStatus,
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'informasi' => [
                'team_name' => $teamName,
                'bulan_anggaran' => $pabdWorkflow->bulan_anggaran,
                'bulan_label' => $bulanLabel,
                'tahun_anggaran' => $pabdWorkflow->tahun_anggaran,
                'pp_label' => $ppTahun ? "PP-{$ppTahun}" : '—',
                'pp_workflow_id' => $ppWorkflow?->id,
                'dibuat_tanggal' => $pabdWorkflow->created_at->format('d/m/Y'),
                'status' => $workflowStatus,
                'step_aktif' => $stepAktifLabel,
            ],
            'dataTerbaru' => $dataTerbaru,
            'canComment' => in_array("{$permPrefix}.workflows.pabd.comment", $permissions),
            'canExportZip' => $dataTerbaru !== null
                && in_array("{$permPrefix}.workflows.pabd.pabd05.export.zip", $permissions),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
        ]);
    }

    public function comment(PabdCommentRequest $request, PabdWorkflow $pabdWorkflow): RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.comment");

        $validated = $request->validated();

        $this->commentService->store(
            workflow: $pabdWorkflow,
            source: $validated['source'],
            notes: $validated['notes'],
            uploadedFiles: $request->file('files', []),
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            workflowPrefix: 'pabd',
        );

        return back()->with('success', 'Komentar berhasil ditambahkan.');
    }

    // ──────────────────────────────────────
    // Admin Reset
    // ──────────────────────────────────────

    public function adminReset(PabdAdminResetRequest $request, PabdWorkflow $pabdWorkflow): RedirectResponse
    {
        $this->checkPermission('admin.workflows.pabd.admin_reset');
        $this->ensureWorkspaceOwnership($pabdWorkflow);

        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $history = $pabdWorkflow->history ?? [];
        $workflowStatus = $this->engine->getWorkflowStatus($history);

        // Scenario 2: Already completed — do nothing
        if ($workflowStatus === 'completed') {
            return back()->with('error', 'PABD sudah selesai (PABD05). Tidak dapat direset.');
        }

        // Scenario 3: Active workflow — invalidate current step and reset to fresh PABD01
        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $currentStep = $currentSteps[0] ?? 'PABD01';

        DB::transaction(function () use ($pabdWorkflow, $currentStep, $request) {
            // Record admin_reset on current step
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: $currentStep,
                action: 'reset',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                notes: $request->validated('notes'),
                extra: [
                    'reason' => 'admin_reset',
                    'cycleTarget' => 'PABD01',
                ],
            );

            // Create fresh PABD01 data from latest PK04
            $freshPabd01 = $this->createFreshPabd01Data($pabdWorkflow);

            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD01',
                action: 'created',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                table: 'pabd01_data',
                dataId: $freshPabd01->id,
                extra: ['reason' => 'admin_reset'],
            );
        });

        $this->notifier->notify($pabdWorkflow, 'pabd.admin_reset', [
            'action_verb' => 'direset oleh admin',
            'step_label' => 'Checklist Pencairan (PABD01)',
            'next_instruction' => 'PABD telah direset oleh admin. Silakan review ulang checklist pencairan.',
        ]);

        return back()->with('success', 'PABD berhasil direset ke PABD01.');
    }

    public function adminCreate(PabdAdminCreateRequest $request): RedirectResponse
    {
        $this->checkPermission('admin.workflows.pabd.admin_create');

        $workspaceId = $this->session->getActiveWorkspaceId();
        $ppWorkflowId = (int) $request->validated('pp_workflow_id');
        $teamId = (int) $request->validated('team_id');
        $bulan = (int) $request->validated('bulan');

        // Validate the PP workflow belongs to this workspace and has completed PP06
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

        // Check no existing PABD for this team + month + PP
        $exists = PabdWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflow->id)
            ->where('bulan_anggaran', $bulan)
            ->where('tahun_anggaran', $tahun)
            ->exists();

        if ($exists) {
            return back()->with('error', 'PABD untuk tim dan bulan ini sudah ada.');
        }

        // Find PK04 finals for this team
        $pkWorkflows = PkWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflow->id)
            ->whereNull('deleted_at')
            ->get();

        $pk04Finals = Pk04ProgramTahunan::query()
            ->whereIn('pk_workflow_id', $pkWorkflows->pluck('id'))
            ->get()
            ->groupBy('pk_workflow_id')
            ->map(fn ($group) => $group->sortByDesc('revision')->first());

        if ($pk04Finals->isEmpty()) {
            return back()->with('error', 'Tidak ditemukan PK04 final untuk tim ini.');
        }

        // Check for active anggaran in target month
        $anggaranInMonth = Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan', function ($q) use ($pk04Finals, $bulan) {
                $q->whereIn('pk04_program_tahunan_id', $pk04Finals->pluck('id'))
                    ->where('bulan', $bulan);
            })
            ->where('nominal_anggaran', '>', 0)
            ->where('status_item', 'active')
            ->exists();

        if (! $anggaranInMonth) {
            return back()->with('error', 'Tidak ada anggaran aktif untuk bulan ini.');
        }

        // Create PABD workflow + PABD01 data
        $pabdWorkflow = DB::transaction(function () use ($workspaceId, $teamId, $ppWorkflow, $bulan, $tahun, $request) {
            $pabdWorkflow = PabdWorkflow::create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'team_id' => $teamId,
                'pp_workflow_id' => $ppWorkflow->id,
                'bulan_anggaran' => $bulan,
                'tahun_anggaran' => $tahun,
                'created_by_user_id' => $request->user()->id,
                'created_by_role_id' => $this->session->getActiveRoleId(),
                'created_by_team_id' => $teamId,
                'created_by_org_id' => null,
                'history' => [],
            ]);

            $freshPabd01 = $this->createFreshPabd01Data($pabdWorkflow);

            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD01',
                action: 'created',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                table: 'pabd01_data',
                dataId: $freshPabd01->id,
                extra: ['reason' => 'admin_create'],
            );

            return $pabdWorkflow;
        });

        $this->notifier->notify($pabdWorkflow, 'pabd.auto_created', [
            'actor_name' => $request->user()->name ?? 'Admin',
        ]);

        return back()->with('success', 'PABD berhasil dibuat.');
    }

    // ──────────────────────────────────────
    // PABD01 — Checklist Pencairan
    // ──────────────────────────────────────

    public function pabd01Show(PabdWorkflow $pabdWorkflow, Pabd01Data $pabd01Data): Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd01.show");

        // Staleness check — may redirect
        $stalenessResult = $this->detectPk04Staleness($pabdWorkflow);
        if ($stalenessResult['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $stalenessResult['changed_pks']);

            return Inertia::location(route("{$scope}.workflows.pabd.show", $pabdWorkflow));
        }

        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $history = $pabdWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Resolve mode
        $mode = $this->resolveMode($statuses, 'PABD01');
        if (($statuses['PABD01']['dataId'] ?? null) !== null && $statuses['PABD01']['dataId'] !== $pabd01Data->id) {
            $mode = 'readonly';
        }
        if ($scope === 'admin') {
            $mode = 'readonly';
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.pabd";
        $isEditable = in_array($mode, ['edit', 'create']) && $scope === 'team';

        // Load anggaran items grouped by program → kegiatan
        $anggaranItems = $this->resolveAnggaranItems($pabd01Data, $pabdWorkflow);

        // Budget counter
        $budgetCounter = $this->getBudgetCounters($pabdWorkflow);

        // Labels
        $teamName = $pabdWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulanLabel = $bulanNames[$pabdWorkflow->bulan_anggaran] ?? (string) $pabdWorkflow->bulan_anggaran;
        $label = "PABD-{$teamName}-{$bulanLabel}/{$pabdWorkflow->tahun_anggaran}";

        // PP reference
        $ppWorkflow = $pabdWorkflow->ppWorkflow;
        $pp06 = $ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$ppWorkflow->latestPp01()?->tahun} Revisi {$pp06->revision}" : null;

        $basePath = $scope === 'team'
            ? "/team/workflows/pabd/{$pabdWorkflow->id}"
            : "/admin/workflows/pabd/{$pabdWorkflow->id}";

        // Cycle-back detection
        $cycle = $statuses['PABD01']['cycle'] ?? 1;
        $isReentry = $cycle > 1 && ($statuses['PABD01']['status'] ?? '') === 'active';
        $cycleBackNotes = $isReentry ? $this->resolveCycleBackNotes($history) : null;

        // Stepper
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($pabdWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/pabd/{$pabdWorkflow->id}";
            if ($code === 'PABD01' && $dataId) {
                return "{$base}/pabd01/{$dataId}";
            }

            return null;
        });
        $stepRoleMap = $this->resolveStepRolesForShow($pabdWorkflow->team_id);
        $this->injectStepperMeta($stepperCycles, $stepRoleMap);

        return Inertia::render('workflows/pabd/pabd01', [
            'workflow' => [
                'id' => $pabdWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'stepData' => [
                'id' => $pabd01Data->id,
                'ada_perubahan' => $pabd01Data->ada_perubahan,
                'items' => $pabd01Data->itemAnggaran->map(fn ($item) => [
                    'id' => $item->id,
                    'pk04_anggaran_id' => $item->pk04_anggaran_id,
                    'dicairkan' => $item->dicairkan,
                ])->values(),
                'updated_at' => $pabd01Data->updated_at->toIso8601String(),
            ],
            'anggaranItems' => $anggaranItems,
            'budgetCounter' => $budgetCounter,
            'ppLabel' => $ppLabel,
            'workflowMeta' => [
                'bulan_anggaran' => $pabdWorkflow->bulan_anggaran,
                'bulan_label' => $bulanLabel,
                'tahun_anggaran' => $pabdWorkflow->tahun_anggaran,
                'team_name' => $teamName,
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array("{$permPrefix}.pabd01.draft", $permissions),
            'canSubmit' => $isEditable && in_array("{$permPrefix}.pabd01.submit", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'isReentry' => $isReentry,
            'cycleBackNotes' => $cycleBackNotes,
            'actionRoles' => $this->resolveActionRoles([
                'team.workflows.pabd.pabd01.draft' => ['Simpan Draft', false],
                'team.workflows.pabd.pabd01.submit' => ['Submit', true],
                "{$permPrefix}.comment" => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'teamName' => $teamName,
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function pabd01Draft(Pabd01DraftRequest $request, PabdWorkflow $pabdWorkflow, Pabd01Data $pabd01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->ensureTeamOwnership($pabdWorkflow);
        $this->checkPermission('team.workflows.pabd.pabd01.draft');
        $this->ensureStepActive($pabdWorkflow, 'PABD01');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD01', $pabd01Data->id);

        // Staleness guard
        $stalenessResult = $this->detectPk04Staleness($pabdWorkflow);
        if ($stalenessResult['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $stalenessResult['changed_pks']);

            return to_route('team.workflows.pabd.show', $pabdWorkflow)
                ->with('warning', 'PK04 telah direvisi. Checklist pencairan dikembalikan ke PABD01 dengan data terbaru.');
        }

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd01Data, $validated['expected_updated_at']);

        // Update ada_perubahan
        $pabd01Data->update([
            'ada_perubahan' => $validated['ada_perubahan'] ?? $pabd01Data->ada_perubahan,
        ]);

        // Sync item checkboxes
        if (! empty($validated['items'])) {
            $this->syncItemCheckboxes($pabd01Data, $validated['items']);
        }

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pabd01Data,
            'pabd.pabd01.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: 'PABD01',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pabd01_data',
            dataId: $pabd01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
        );

        return to_route('team.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Draft PABD01 berhasil disimpan.');
    }

    public function pabd01Submit(Pabd01SubmitRequest $request, PabdWorkflow $pabdWorkflow, Pabd01Data $pabd01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->ensureTeamOwnership($pabdWorkflow);
        $this->checkPermission('team.workflows.pabd.pabd01.submit');
        $this->ensureStepActive($pabdWorkflow, 'PABD01');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD01', $pabd01Data->id);

        // Staleness guard
        $stalenessResult = $this->detectPk04Staleness($pabdWorkflow);
        if ($stalenessResult['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $stalenessResult['changed_pks']);

            return to_route('team.workflows.pabd.show', $pabdWorkflow)
                ->with('warning', 'PK04 telah direvisi. Checklist pencairan dikembalikan ke PABD01 dengan data terbaru.');
        }

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd01Data, $validated['expected_updated_at']);

        // Update ada_perubahan + sync items
        $pabd01Data->update([
            'ada_perubahan' => $validated['ada_perubahan'],
        ]);
        $this->syncItemCheckboxes($pabd01Data, $validated['items']);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pabd01Data,
            'pabd.pabd01.submit',
            $request->user()->id,
            $sessionContext,
        );

        // Record PABD01 submitted
        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: 'PABD01',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pabd01_data',
            dataId: $pabd01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
        );

        if ($validated['ada_perubahan']) {
            // ada_perubahan = true → activate PABD02A
            $pabd02aData = Pabd02aData::create([
                'pabd_workflow_id' => $pabdWorkflow->id,
            ]);

            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD02A',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'pabd02a_data',
                dataId: $pabd02aData->id,
            );

            $this->notifier->notify($pabdWorkflow, 'pabd01.submitted_change', [
                'actor_name' => $request->user()->name,
                'actor_role' => $this->resolveSessionRoleName(),
            ], $request->user()->id);
        } else {
            // ada_perubahan = false → skip PABD02A + PABD02B → activate PABD03
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD02A',
                action: 'skipped',
                userId: null,
                sessionContext: [],
            );

            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD02B',
                action: 'skipped',
                userId: null,
                sessionContext: [],
            );

            // PABD03 is an approval step with no data table — just record created
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD03',
                action: 'created',
                userId: null,
                sessionContext: [],
            );

            $this->notifier->notify($pabdWorkflow, 'pabd01.submitted_skip', [
                'actor_name' => $request->user()->name,
                'actor_role' => $this->resolveSessionRoleName(),
            ], $request->user()->id);
        }

        return to_route('team.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'PABD01 berhasil disubmit.');
    }

    // ──────────────────────────────────────
    // PABD02A — Perubahan Anggaran
    // ──────────────────────────────────────

    public function pabd02aShow(PabdWorkflow $pabdWorkflow, Pabd02aData $pabd02aData): Response|RedirectResponse|\Illuminate\Http\Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd02a.show");

        // Staleness check
        $stalenessResult = $this->detectPk04Staleness($pabdWorkflow);
        if ($stalenessResult['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $stalenessResult['changed_pks']);

            return Inertia::location(route("{$scope}.workflows.pabd.show", $pabdWorkflow));
        }

        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $history = $pabdWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Resolve mode
        $mode = $this->resolveMode($statuses, 'PABD02A');
        if (($statuses['PABD02A']['dataId'] ?? null) !== null && $statuses['PABD02A']['dataId'] !== $pabd02aData->id) {
            $mode = 'readonly';
        }
        if ($scope === 'admin') {
            $mode = 'readonly';
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.pabd";
        $isEditable = in_array($mode, ['edit', 'create']) && $scope === 'team';

        // Load existing items with relations
        $existingItems = $pabd02aData->itemPerubahan()
            ->with([
                'pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan',
                'pkWorkflow.pk01Data' => fn ($q) => $q->latest('id'),
                'files',
            ])
            ->get();

        $formattedItems = $existingItems->map(fn (Pabd02aItemPerubahan $item) => $this->formatPabd02aItem($item))->values();

        // PABD01 checklist (readonly)
        $latestPabd01 = $pabdWorkflow->latestPabd01();
        $pabd01ChecklistData = $latestPabd01 ? $this->resolveAnggaranItems($latestPabd01, $pabdWorkflow) : [];
        $pabd01Submitter = $this->resolvePabd01Submitter($history);

        // Future anggaran for tarik maju picker
        $futureAnggaranItems = $this->resolveFutureAnggaran(
            $pabdWorkflow,
            $existingItems->whereIn('tipe_perubahan', self::TIPE_PEMINDAHAN_BULAN)->pluck('pk04_anggaran_id')->filter()->all(),
        );

        // PP06 kode references for proposal baru
        $pp06 = $pabdWorkflow->ppWorkflow?->latestPp06();
        $kodeRefs = $this->loadPp06KodeReferences($pp06);

        // Budget counter
        $budgetCounter = $this->getBudgetCounters($pabdWorkflow);

        // Labels
        $teamName = $pabdWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulanLabel = $bulanNames[$pabdWorkflow->bulan_anggaran] ?? (string) $pabdWorkflow->bulan_anggaran;
        $label = "PABD-{$teamName}-{$bulanLabel}/{$pabdWorkflow->tahun_anggaran}";

        $basePath = $scope === 'team'
            ? "/team/workflows/pabd/{$pabdWorkflow->id}"
            : "/admin/workflows/pabd/{$pabdWorkflow->id}";

        // Stepper
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($pabdWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/pabd/{$pabdWorkflow->id}";
            if ($dataId) {
                $stepLower = strtolower($code);

                return "{$base}/{$stepLower}/{$dataId}";
            }

            return null;
        });
        $stepRoleMap = $this->resolveStepRolesForShow($pabdWorkflow->team_id);
        $this->injectStepperMeta($stepperCycles, $stepRoleMap);

        return Inertia::render('workflows/pabd/pabd02a', [
            'workflow' => [
                'id' => $pabdWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'stepData' => [
                'id' => $pabd02aData->id,
                'items' => $formattedItems,
                'updated_at' => $pabd02aData->updated_at->toIso8601String(),
            ],
            'pabd01ChecklistData' => $pabd01ChecklistData,
            'pabd01Submitter' => $pabd01Submitter,
            'futureAnggaranItems' => $futureAnggaranItems,
            'kodeRefs' => $kodeRefs,
            'budgetCounter' => $budgetCounter,
            'workflowMeta' => [
                'bulan_anggaran' => $pabdWorkflow->bulan_anggaran,
                'bulan_label' => $bulanLabel,
                'tahun_anggaran' => $pabdWorkflow->tahun_anggaran,
                'team_name' => $teamName,
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array("{$permPrefix}.pabd02a.draft", $permissions),
            'canSubmit' => $isEditable && in_array("{$permPrefix}.pabd02a.submit", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'actionRoles' => $this->resolveActionRoles([
                'team.workflows.pabd.pabd02a.draft' => ['Simpan Draft', false],
                'team.workflows.pabd.pabd02a.submit' => ['Submit', true],
                "{$permPrefix}.comment" => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'teamName' => $teamName,
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function pabd02aDraft(Pabd02aDraftRequest $request, PabdWorkflow $pabdWorkflow, Pabd02aData $pabd02aData): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->ensureTeamOwnership($pabdWorkflow);
        $this->checkPermission('team.workflows.pabd.pabd02a.draft');
        $this->ensureStepActive($pabdWorkflow, 'PABD02A');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD02A', $pabd02aData->id);

        // Staleness guard
        $stalenessResult = $this->detectPk04Staleness($pabdWorkflow);
        if ($stalenessResult['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $stalenessResult['changed_pks']);

            return to_route('team.workflows.pabd.show', $pabdWorkflow)
                ->with('warning', 'PK04 telah direvisi. Checklist pencairan dikembalikan ke PABD01 dengan data terbaru.');
        }

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd02aData, $validated['expected_updated_at']);

        // Sync items
        $this->syncPabd02aItems($pabd02aData, $validated['items'] ?? [], $pabdWorkflow, $request, isDraft: true);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pabd02aData,
            'pabd.pabd02a.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: 'PABD02A',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pabd02a_data',
            dataId: $pabd02aData->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
        );

        return to_route('team.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Draft PABD02A berhasil disimpan.');
    }

    public function pabd02aSubmit(Pabd02aSubmitRequest $request, PabdWorkflow $pabdWorkflow, Pabd02aData $pabd02aData): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->ensureTeamOwnership($pabdWorkflow);
        $this->checkPermission('team.workflows.pabd.pabd02a.submit');
        $this->ensureStepActive($pabdWorkflow, 'PABD02A');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD02A', $pabd02aData->id);

        // Staleness guard
        $stalenessResult = $this->detectPk04Staleness($pabdWorkflow);
        if ($stalenessResult['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $stalenessResult['changed_pks']);

            return to_route('team.workflows.pabd.show', $pabdWorkflow)
                ->with('warning', 'PK04 telah direvisi. Checklist pencairan dikembalikan ke PABD01 dengan data terbaru.');
        }

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd02aData, $validated['expected_updated_at']);

        // Additional validation: unique pk04_anggaran_id per submission
        $tarikMajuIds = collect($validated['items'])
            ->whereIn('tipe_perubahan', self::TIPE_PEMINDAHAN_BULAN)
            ->pluck('pk04_anggaran_id')
            ->filter();
        if ($tarikMajuIds->count() !== $tarikMajuIds->unique()->count()) {
            abort(422, 'Setiap anggaran hanya dapat ditarik satu kali per pengajuan.');
        }

        // Validate bulan_tujuan constraints for pemindahan bulan items.
        // Tarik maju pulls an item EARLIER; tarik mundur pushes it LATER. Floor for
        // maju is bulan_anggaran (PABD target month), not the current calendar month,
        // so a January PABD processed in April can still pull items to January.
        foreach ($validated['items'] as $idx => $item) {
            if (! in_array($item['tipe_perubahan'], self::TIPE_PEMINDAHAN_BULAN, true)) {
                continue;
            }

            $bulanAwal = $item['bulan_awal'] ?? 0;
            $bulanTujuan = $item['bulan_tujuan'] ?? 0;

            if ($item['tipe_perubahan'] === 'tarik_maju') {
                if ($bulanTujuan >= $bulanAwal) {
                    abort(422, "Bulan tujuan harus lebih kecil dari bulan asal (item #{$idx}).");
                }
                if ($bulanTujuan < $pabdWorkflow->bulan_anggaran) {
                    abort(422, "Bulan tujuan tidak boleh sebelum bulan PABD ini (item #{$idx}).");
                }
            } else {
                if ($bulanTujuan <= $bulanAwal) {
                    abort(422, "Bulan tujuan harus lebih besar dari bulan asal (item #{$idx}).");
                }
                if ($bulanAwal < $pabdWorkflow->bulan_anggaran) {
                    abort(422, "Hanya item bulan ini atau setelahnya yang bisa ditarik mundur (item #{$idx}).");
                }
            }
        }

        // Validate proposal_baru items have files
        foreach ($validated['items'] as $idx => $item) {
            if ($item['tipe_perubahan'] === 'proposal_baru') {
                $itemFiles = $request->file("items.{$idx}.item_files", []);
                // Check existing files from previously drafted items
                $hasExistingFiles = false;
                $existingItem = $pabd02aData->itemPerubahan()
                    ->where('tipe_perubahan', 'proposal_baru')
                    ->skip($idx)
                    ->first();
                if ($existingItem && $existingItem->files()->count() > 0) {
                    $hasExistingFiles = true;
                }
                if (empty($itemFiles) && ! $hasExistingFiles) {
                    abort(422, "Dokumen proposal wajib dilampirkan (item #{$idx}).");
                }
            }
        }

        // Sync items
        $this->syncPabd02aItems($pabd02aData, $validated['items'], $pabdWorkflow, $request, isDraft: false);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pabd02aData,
            'pabd.pabd02a.submit',
            $request->user()->id,
            $sessionContext,
        );

        // Record PABD02A submitted
        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: 'PABD02A',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pabd02a_data',
            dataId: $pabd02aData->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
        );

        // Activate PABD02B
        $pabd02bData = \App\Models\Pabd\Pabd02bData::create([
            'pabd_workflow_id' => $pabdWorkflow->id,
        ]);

        // Create pabd02b_item_review rows (1:1 with PABD02A items)
        foreach ($pabd02aData->itemPerubahan as $perubahanItem) {
            $pabd02bData->itemReview()->create([
                'pabd02a_item_perubahan_id' => $perubahanItem->id,
            ]);
        }

        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: 'PABD02B',
            action: 'created',
            userId: null,
            sessionContext: [],
            table: 'pabd02b_data',
            dataId: $pabd02bData->id,
        );

        $this->notifier->notify($pabdWorkflow, 'pabd02a.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('team.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'PABD02A berhasil disubmit. Menunggu approval BU.');
    }

    // ──────────────────────────────────────
    // PABD02A Helpers
    // ──────────────────────────────────────

    /**
     * Sync pabd02a_item_perubahan rows from form data.
     * For proposal_baru items, create/update draft PK Proposals.
     */
    private function syncPabd02aItems(Pabd02aData $pabd02aData, array $items, PabdWorkflow $pabdWorkflow, $request, bool $isDraft): void
    {
        // Collect existing items for cleanup
        $existingItemIds = $pabd02aData->itemPerubahan()->pluck('id')->all();
        $processedIds = [];

        foreach ($items as $idx => $itemData) {
            $tipe = $itemData['tipe_perubahan'] ?? null;

            // Draft: skip items without tipe
            if ($isDraft && empty($tipe)) {
                continue;
            }

            $isPemindahan = in_array($tipe, self::TIPE_PEMINDAHAN_BULAN, true);

            $itemAttrs = [
                'pabd02a_data_id' => $pabd02aData->id,
                'tipe_perubahan' => $tipe,
                'pk04_anggaran_id' => $isPemindahan ? ($itemData['pk04_anggaran_id'] ?? null) : null,
                'bulan_awal' => $isPemindahan ? ($itemData['bulan_awal'] ?? null) : null,
                'bulan_tujuan' => $isPemindahan ? ($itemData['bulan_tujuan'] ?? null) : null,
                'nominal_awal' => null,
                'komentar' => $itemData['komentar'] ?? null,
            ];

            // Snapshot nominal at creation time so it survives PK04 recompile zeroing.
            if ($isPemindahan && ! empty($itemAttrs['pk04_anggaran_id'])) {
                $itemAttrs['nominal_awal'] = \App\Models\Pk\Pk04Anggaran::where('id', $itemAttrs['pk04_anggaran_id'])
                    ->value('nominal_anggaran');
            }

            // Handle proposal_baru: create/update PK Proposal
            if ($tipe === 'proposal_baru' && ! empty($itemData['proposal'])) {
                $pkWorkflowId = $this->createOrUpdateDraftProposal(
                    $pabdWorkflow,
                    $itemData['proposal'],
                    $itemData['_existing_pk_workflow_id'] ?? null,
                    $isDraft,
                );
                $itemAttrs['pk_workflow_id'] = $pkWorkflowId;
            }

            // Create the item row (always re-create — simpler than diffing)
            $perubahanItem = $pabd02aData->itemPerubahan()->create($itemAttrs);
            $processedIds[] = $perubahanItem->id;

            // Store per-item files
            $itemFiles = $request->file("items.{$idx}.item_files", []);
            if (! empty($itemFiles)) {
                $sessionContext = $this->getSessionContext();
                $this->commentService->storeFiles(
                    $itemFiles,
                    $perubahanItem,
                    'pabd.pabd02a.item',
                    $request->user()->id,
                    $sessionContext,
                );
            }
        }

        // Delete items not in current submission (and soft-delete orphaned proposals)
        $toDelete = array_diff($existingItemIds, $processedIds);
        if (! empty($toDelete)) {
            $orphanedProposals = $pabd02aData->itemPerubahan()
                ->whereIn('id', $toDelete)
                ->whereNotNull('pk_workflow_id')
                ->pluck('pk_workflow_id');

            foreach ($orphanedProposals as $pkWorkflowId) {
                PkWorkflow::find($pkWorkflowId)?->delete();
            }

            $pabd02aData->itemPerubahan()->whereIn('id', $toDelete)->delete();
        }

        $pabd02aData->touch();
    }

    /**
     * Create or update a draft PK Proposal for a proposal_baru item.
     */
    private function createOrUpdateDraftProposal(PabdWorkflow $pabdWorkflow, array $proposalData, ?int $existingPkWorkflowId, bool $isDraft): int
    {
        $pkWorkflow = $existingPkWorkflowId ? PkWorkflow::find($existingPkWorkflowId) : null;

        if (! $pkWorkflow) {
            $sessionContext = $this->getSessionContext();
            $pkWorkflow = PkWorkflow::create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $pabdWorkflow->workspace_id,
                'team_id' => $pabdWorkflow->team_id,
                'pp_workflow_id' => $pabdWorkflow->pp_workflow_id,
                'tipe' => 'proposal',
                'created_by_user_id' => request()->user()?->id,
                'created_by_role_id' => $sessionContext['role'] ?? null,
                'created_by_team_id' => $pabdWorkflow->team_id,
                'created_by_org_id' => $sessionContext['org'] ?? $pabdWorkflow->team?->organization_id,
                'history' => [],
            ]);
        }

        // Upsert pk01_data
        $pk01Data = $pkWorkflow->pk01Data()->latest('id')->first();
        if (! $pk01Data) {
            $pk01Data = Pk01Data::create([
                'pk_workflow_id' => $pkWorkflow->id,
                'kode_kategori' => $proposalData['kode_kategori'] ?? null,
                'nama_program' => $proposalData['nama_program'] ?? null,
                'deskripsi_program' => $proposalData['deskripsi_program'] ?? null,
                'tujuan_program' => $proposalData['tujuan_program'] ?? null,
            ]);
        } else {
            $pk01Data->update([
                'kode_kategori' => $proposalData['kode_kategori'] ?? $pk01Data->kode_kategori,
                'nama_program' => $proposalData['nama_program'] ?? $pk01Data->nama_program,
                'deskripsi_program' => $proposalData['deskripsi_program'] ?? $pk01Data->deskripsi_program,
                'tujuan_program' => $proposalData['tujuan_program'] ?? $pk01Data->tujuan_program,
            ]);
        }

        // Sync kegiatan (delete-recreate pattern from PK)
        $pk01Data->kegiatan()->delete();

        foreach ($proposalData['kegiatan'] ?? [] as $kData) {
            if ($isDraft && empty($kData['nama_kegiatan']) && empty($kData['anggaran'] ?? [])) {
                continue;
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

                $kegiatan->kuisioner()->create([
                    'kode_kuisioner' => $qData['kode_kuisioner'] ?? null,
                    'pertanyaan' => $qData['pertanyaan'] ?? null,
                    'tipe' => $qData['tipe'] ?? 'Kualitatif',
                    'satuan' => $qData['satuan'] ?? null,
                ]);
            }
        }

        return $pkWorkflow->id;
    }

    /**
     * Format a single pabd02a_item_perubahan for frontend display.
     *
     * @return array<string, mixed>
     */
    private function formatPabd02aItem(Pabd02aItemPerubahan $item): array
    {
        $bulanNames = $this->bulanNames();
        $result = [
            'id' => $item->id,
            'tipe_perubahan' => $item->tipe_perubahan,
            'pk04_anggaran_id' => $item->pk04_anggaran_id,
            'bulan_awal' => $item->bulan_awal,
            'bulan_tujuan' => $item->bulan_tujuan,
            'pk_workflow_id' => $item->pk_workflow_id,
            'komentar' => $item->komentar,
            'files' => $item->files->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->original_filename ?? $f->filename,
                'url' => $f->path ? route('files.download', $f) : null,
            ])->values(),
        ];

        // Tarik maju: enrich with anggaran details
        if (in_array($item->tipe_perubahan, self::TIPE_PEMINDAHAN_BULAN, true) && $item->pk04Anggaran) {
            $anggaran = $item->pk04Anggaran;
            $kegiatan = $anggaran->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;

            $result['anggaran_detail'] = [
                'program_name' => $program?->nama_program,
                'kode_kategori' => $program?->kode_kategori,
                'kegiatan_name' => $kegiatan?->nama_kegiatan,
                'bulan' => $kegiatan?->bulan,
                'bulan_label' => $bulanNames[$kegiatan?->bulan ?? 0] ?? null,
                'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
                'mata_anggaran' => $anggaran->mata_anggaran,
                'nominal' => (float) ($item->nominal_awal ?? $anggaran->nominal_anggaran),
            ];
            $result['bulan_awal_label'] = $bulanNames[$item->bulan_awal ?? 0] ?? null;
            $result['bulan_tujuan_label'] = $bulanNames[$item->bulan_tujuan ?? 0] ?? null;
        }

        // Proposal baru: enrich with PK01 data
        if ($item->tipe_perubahan === 'proposal_baru' && $item->pkWorkflow) {
            $pk01 = $item->pkWorkflow->pk01Data?->sortByDesc('id')->first();
            if ($pk01) {
                $pk01->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);
                $result['proposal'] = [
                    'kode_kategori' => $pk01->kode_kategori,
                    'nama_program' => $pk01->nama_program,
                    'deskripsi_program' => $pk01->deskripsi_program,
                    'tujuan_program' => $pk01->tujuan_program,
                    'kegiatan' => $pk01->kegiatan->map(fn ($k) => [
                        'nama_kegiatan' => $k->nama_kegiatan,
                        'bulan' => $k->bulan,
                        'anggaran' => $k->anggaran->map(fn ($a) => [
                            'kode_bidang' => $a->kode_bidang,
                            'kode_sub_bidang' => $a->kode_sub_bidang,
                            'kode_jenis' => $a->kode_jenis,
                            'mata_anggaran' => $a->mata_anggaran,
                            'deskripsi_pk' => $a->deskripsi_pk,
                            'nominal_anggaran' => (float) $a->nominal_anggaran,
                        ])->values(),
                        'kuisioner' => $k->kuisioner->map(fn ($q) => [
                            'kode_kuisioner' => $q->kode_kuisioner,
                            'pertanyaan' => $q->pertanyaan,
                            'tipe' => $q->tipe,
                            'satuan' => $q->satuan,
                        ])->values(),
                    ])->values(),
                ];
            }
        }

        return $result;
    }

    /**
     * Resolve future-month PK04 anggaran for tarik maju picker.
     *
     * @param  list<int>  $excludeAnggaranIds  Already-selected anggaran IDs
     * @return list<array>
     */
    private function resolveFutureAnggaran(PabdWorkflow $pabdWorkflow, array $excludeAnggaranIds): array
    {
        $bulan = $pabdWorkflow->bulan_anggaran;
        $teamId = $pabdWorkflow->team_id;
        $ppWorkflowId = $pabdWorkflow->pp_workflow_id;
        $workspaceId = $pabdWorkflow->workspace_id;

        // Get latest PK04 finals for this team
        $pk04Finals = Pk04ProgramTahunan::query()
            ->whereHas('pkWorkflow', fn ($q) => $q
                ->where('team_id', $teamId)
                ->where('pp_workflow_id', $ppWorkflowId)
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
            )
            ->get()
            ->groupBy('pk_workflow_id')
            ->map(fn ($group) => $group->sortByDesc('revision')->first());

        $bulanNames = $this->bulanNames();
        $grouped = [];

        foreach ($pk04Finals as $pk04) {
            $anggaranItems = Pk04Anggaran::query()
                ->whereHas('pk04Kegiatan', fn ($q) => $q
                    ->where('pk04_program_tahunan_id', $pk04->id)
                    ->where('bulan', '>', $bulan)  // Future months only
                )
                ->where('status_item', 'active')
                ->where('nominal_anggaran', '>', 0)
                ->with('pk04Kegiatan')
                ->get();

            foreach ($anggaranItems as $anggaran) {
                $kegiatan = $anggaran->pk04Kegiatan;
                $programKey = $pk04->id;

                if (! isset($grouped[$programKey])) {
                    $grouped[$programKey] = [
                        'program_id' => $pk04->id,
                        'program_name' => $pk04->nama_program,
                        'kode_kategori' => $pk04->kode_kategori,
                        'kegiatan' => [],
                    ];
                }

                $kegiatanKey = $kegiatan->id;
                if (! isset($grouped[$programKey]['kegiatan'][$kegiatanKey])) {
                    $grouped[$programKey]['kegiatan'][$kegiatanKey] = [
                        'kegiatan_id' => $kegiatan->id,
                        'nama_kegiatan' => $kegiatan->nama_kegiatan,
                        'bulan' => $kegiatan->bulan,
                        'bulan_label' => $bulanNames[$kegiatan->bulan] ?? (string) $kegiatan->bulan,
                        'anggaran' => [],
                    ];
                }

                $grouped[$programKey]['kegiatan'][$kegiatanKey]['anggaran'][] = [
                    'pk04_anggaran_id' => $anggaran->id,
                    'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
                    'mata_anggaran' => $anggaran->mata_anggaran,
                    'nominal' => (float) $anggaran->nominal_anggaran,
                    'disabled' => in_array($anggaran->id, $excludeAnggaranIds),
                ];
            }
        }

        // Convert keyed maps to arrays
        $result = [];
        foreach ($grouped as $programData) {
            $programData['kegiatan'] = array_values($programData['kegiatan']);
            $result[] = $programData;
        }

        return $result;
    }

    /**
     * Load PP06 kode reference data for proposal baru dropdowns.
     *
     * @return array{kategori: list<array>, bidang: list<array>, subBidang: list<array>, jenis: list<array>, kuisioner: list<array>}
     */
    private function loadPp06KodeReferences(?Pp06PeriodeTahunan $pp06): array
    {
        if (! $pp06) {
            return ['kategori' => [], 'bidang' => [], 'subBidang' => [], 'jenis' => [], 'kuisioner' => []];
        }

        return [
            'kategori' => $pp06->kodeKategoriPelayanan()->orderBy('kode')->get(['kode', 'nama'])->toArray(),
            'bidang' => $pp06->kodeBidangPelayanan()->orderBy('kode')->get(['kode', 'nama'])->toArray(),
            'subBidang' => $pp06->kodeSubBidangPelayanan()->orderBy('kode')->get(['kode', 'nama'])->toArray(),
            'jenis' => $pp06->kodeJenisProgram()->orderBy('kode')->get(['kode', 'nama'])->toArray(),
            'kuisioner' => $pp06->itemKuisioner()->orderBy('kode')->get(['kode', 'pertanyaan', 'tipe', 'satuan'])->toArray(),
        ];
    }

    /**
     * Resolve PABD01 submitter info from history.
     *
     * @return array{name: string, role: string, at: string}|null
     */
    private function resolvePabd01Submitter(array $history): ?array
    {
        $formatted = $this->historyFormatter->format($history);
        foreach (array_reverse($formatted) as $entry) {
            if (($entry['step'] ?? '') === 'PABD01' && ($entry['action'] ?? '') === 'submitted') {
                return [
                    'name' => $entry['by_name'] ?? 'Unknown',
                    'role' => $entry['role_name'] ?? '',
                    'team' => $entry['team_name'] ?? '',
                    'at' => $entry['at'] ?? '',
                ];
            }
        }

        return null;
    }

    // ──────────────────────────────────────
    // PABD02B — Approval Perubahan
    // ──────────────────────────────────────

    public function pabd02bShow(PabdWorkflow $pabdWorkflow, Pabd02bData $pabd02bData): Response|\Illuminate\Http\Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd02b.show");

        // Staleness detection (runs on show)
        $staleness = $this->detectPk04Staleness($pabdWorkflow);
        if ($staleness['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $staleness['changed_pks']);
            $newPabd01 = $pabdWorkflow->latestPabd01();

            return Inertia::location(route(
                $scope === 'team' ? 'team.workflows.pabd.pabd01.show' : 'admin.workflows.pabd.pabd01.show',
                ['pabdWorkflow' => $pabdWorkflow->id, 'pabd01Data' => $newPabd01->id],
            ));
        }

        $this->ensureCurrentRecord($pabdWorkflow, 'PABD02B', $pabd02bData->id);

        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $statuses = $this->engine->getStepStatuses($definition, $pabdWorkflow->history ?? []);
        $permissions = $this->session->getActivePermissions();

        $isActive = ($statuses['PABD02B']['status'] ?? '') === 'active';
        $canDraft = $isActive && $scope === 'admin' && in_array('admin.workflows.pabd.pabd02b.draft', $permissions);
        $canApprove = $isActive && $scope === 'admin' && in_array('admin.workflows.pabd.pabd02b.approve', $permissions);
        $canReject = $isActive && $scope === 'admin' && in_array('admin.workflows.pabd.pabd02b.reject', $permissions);
        $canComment = in_array("{$scope}.workflows.pabd.comment", $permissions);
        $mode = ($canDraft || $canApprove || $canReject) ? 'edit' : 'readonly';

        // Load PABD02B review items with related PABD02A items
        $pabd02bData->load(['itemReview.pabd02aItemPerubahan.files', 'itemReview.pabd02aItemPerubahan.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan', 'itemReview.pabd02aItemPerubahan.pkWorkflow.pk01Data.kegiatan.anggaran', 'itemReview.pabd02aItemPerubahan.pkWorkflow.pk01Data.kegiatan.kuisioner']);

        $reviewItems = $pabd02bData->itemReview->map(function ($review) {
            $perubahanItem = $review->pabd02aItemPerubahan;
            $formatted = $this->formatPabd02aItem($perubahanItem);
            $formatted['pabd02b_item_review_id'] = $review->id;
            $formatted['komentar_approval'] = $review->komentar_approval;

            // Kode diff preview for tarik_maju items
            if (in_array($perubahanItem->tipe_perubahan, self::TIPE_PEMINDAHAN_BULAN, true) && $perubahanItem->pk04Anggaran) {
                $formatted['kode_preview'] = $this->computeKodePreview($perubahanItem);
            }

            return $formatted;
        })->values();

        // PABD01 checklist data
        $latestPabd01 = $pabdWorkflow->latestPabd01();
        $pabd01ChecklistData = $latestPabd01 ? $this->resolveAnggaranItems($latestPabd01, $pabdWorkflow) : [];
        $pabd01Submitter = $this->resolvePabd01Submitter($pabdWorkflow->history ?? []);

        // PABD02A submitter
        $pabd02aSubmitter = $this->resolvePabd02aSubmitter($pabdWorkflow->history ?? []);

        // Stepper + history
        $stepperData = $this->engine->getStepperData($definition, $pabdWorkflow->history ?? [], function (string $code, ?int $dataId) use ($pabdWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/pabd/{$pabdWorkflow->id}";
            if ($dataId) {
                $stepLower = strtolower($code);

                return "{$base}/{$stepLower}/{$dataId}";
            }

            return null;
        });
        $formattedHistory = $this->historyFormatter->format($pabdWorkflow->history ?? []);

        // Action roles
        $actionRoles = $this->resolveActionRoles([
            'admin.workflows.pabd.pabd02b.draft' => ['Simpan Draft', false],
            'admin.workflows.pabd.pabd02b.approve' => ['Setujui', true],
            'admin.workflows.pabd.pabd02b.reject' => ['Tolak', false],
        ]);

        // Budget counters
        $budgetCounter = $this->getBudgetCounters($pabdWorkflow);

        // Cycle info
        $cycle = $statuses['PABD02B']['cycle'] ?? 1;

        // Previous cycles data (pattern F)
        $previousCycles = $this->resolvePreviousCycles($pabdWorkflow, 'PABD02B', $pabd02bData->id);

        // PABD01 previous cycles
        $pabd01PreviousCycles = $this->resolvePreviousCycles($pabdWorkflow, 'PABD01', $latestPabd01?->id);

        // PABD02A previous cycles
        $latestPabd02a = Pabd02aData::where('pabd_workflow_id', $pabdWorkflow->id)->latest('id')->first();
        $pabd02aPreviousCycles = $this->resolvePreviousCycles($pabdWorkflow, 'PABD02A', $latestPabd02a?->id);

        // Change summary counts
        $tarikMajuCount = $reviewItems->where('tipe_perubahan', 'tarik_maju')->count();
        $tarikMundurCount = $reviewItems->where('tipe_perubahan', 'tarik_mundur')->count();
        $proposalBaruCount = $reviewItems->where('tipe_perubahan', 'proposal_baru')->count();

        return Inertia::render('workflows/pabd/pabd02b', [
            'scope' => $scope,
            'mode' => $mode,
            'canDraft' => $canDraft,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'canComment' => $canComment,
            'canTerminate' => false,

            'workflow' => [
                'id' => $pabdWorkflow->id,
                'uuid' => $pabdWorkflow->uuid,
                'team_name' => $pabdWorkflow->team?->name,
                'bulan_anggaran' => $pabdWorkflow->bulan_anggaran,
                'bulan_label' => $this->bulanNames()[$pabdWorkflow->bulan_anggaran] ?? (string) $pabdWorkflow->bulan_anggaran,
                'tahun_anggaran' => $pabdWorkflow->tahun_anggaran,
            ],
            'stepData' => [
                'id' => $pabd02bData->id,
                'updated_at' => $pabd02bData->updated_at?->toIso8601String(),
                'items' => $reviewItems,
            ],
            'cycle' => $cycle,
            'previousCycles' => $previousCycles,

            'pabd01ChecklistData' => $pabd01ChecklistData,
            'pabd01Submitter' => $pabd01Submitter,
            'pabd01Cycle' => $statuses['PABD01']['cycle'] ?? 1,
            'pabd01PreviousCycles' => $pabd01PreviousCycles,

            'pabd02aSubmitter' => $pabd02aSubmitter,
            'pabd02aCycle' => $statuses['PABD02A']['cycle'] ?? 1,
            'pabd02aPreviousCycles' => $pabd02aPreviousCycles,

            'tarikMajuCount' => $tarikMajuCount,
            'tarikMundurCount' => $tarikMundurCount,
            'proposalBaruCount' => $proposalBaruCount,

            'budgetCounter' => $budgetCounter,
            'stepStatuses' => $statuses,
            'stepperData' => $stepperData,
            'history' => $formattedHistory,
            'actionRoles' => $actionRoles,
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pabd02bDraft(Pabd02bDraftRequest $request, PabdWorkflow $pabdWorkflow, Pabd02bData $pabd02bData): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->checkPermission('admin.workflows.pabd.pabd02b.draft');

        // Staleness detection
        $staleness = $this->detectPk04Staleness($pabdWorkflow);
        if ($staleness['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $staleness['changed_pks']);

            return to_route('admin.workflows.pabd.show', $pabdWorkflow)
                ->with('error', 'PK04 telah direvisi. PABD direset ke PABD01.');
        }

        $this->ensureStepActive($pabdWorkflow, 'PABD02B');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD02B', $pabd02bData->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd02bData, $validated['expected_updated_at']);

        // Save per-item komentar_approval
        $this->saveItemReviews($pabd02bData, $validated['items'] ?? []);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pabd02bData,
            'pabd.pabd02b.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: 'PABD02B',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pabd02b_data',
            dataId: $pabd02bData->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
        );

        return to_route('admin.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Draft PABD02B berhasil disimpan.');
    }

    public function pabd02bApprove(Pabd02bApproveRequest $request, PabdWorkflow $pabdWorkflow, Pabd02bData $pabd02bData): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->checkPermission('admin.workflows.pabd.pabd02b.approve');

        // Staleness detection
        $staleness = $this->detectPk04Staleness($pabdWorkflow);
        if ($staleness['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $staleness['changed_pks']);

            return to_route('admin.workflows.pabd.show', $pabdWorkflow)
                ->with('error', 'PK04 telah direvisi. PABD direset ke PABD01.');
        }

        $this->ensureStepActive($pabdWorkflow, 'PABD02B');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD02B', $pabd02bData->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd02bData, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        DB::transaction(function () use ($pabdWorkflow, $pabd02bData, $validated, $request, $sessionContext) {
            // 1. Save per-item komentar_approval
            $this->saveItemReviews($pabd02bData, $validated['items'] ?? []);

            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $pabd02bData,
                'pabd.pabd02b.approve',
                $request->user()->id,
                $sessionContext,
            );

            // 2. Record approved
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD02B',
                action: 'approved',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: [
                    'reviewed' => ['pabd02b_data_id' => $pabd02bData->id],
                    'pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow),
                ],
            );

            // 3. Process pemindahan bulan items — tarik maju and tarik mundur (grouped by PK)
            $pabd02bData->load(['itemReview.pabd02aItemPerubahan.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan.pkWorkflow']);
            $tarikMajuByPk = [];
            $allPerubahanItems = [];

            foreach ($pabd02bData->itemReview as $review) {
                $perubahan = $review->pabd02aItemPerubahan;
                $allPerubahanItems[] = ['review' => $review, 'perubahan' => $perubahan];

                if (in_array($perubahan->tipe_perubahan, self::TIPE_PEMINDAHAN_BULAN, true) && $perubahan->pk04Anggaran) {
                    $pkWorkflow = $perubahan->pk04Anggaran->pk04Kegiatan?->pk04ProgramTahunan?->pkWorkflow;
                    if ($pkWorkflow) {
                        $tarikMajuByPk[$pkWorkflow->id] ??= ['workflow' => $pkWorkflow, 'items' => []];
                        $tarikMajuByPk[$pkWorkflow->id]['items'][] = [
                            'pk04_anggaran_id' => $perubahan->pk04_anggaran_id,
                            'bulan_tujuan' => $perubahan->bulan_tujuan,
                            'tipe_perubahan' => $perubahan->tipe_perubahan,
                            'pabd_workflow_id' => $pabdWorkflow->id,
                        ];
                    }
                }
            }

            foreach ($tarikMajuByPk as $pkData) {
                $this->compileService->recompileFromPemindahanBulan($pkData['workflow'], $pkData['items']);
            }

            // 4. Process proposal_baru items
            foreach ($allPerubahanItems as $itemData) {
                $perubahan = $itemData['perubahan'];
                if ($perubahan->tipe_perubahan === 'proposal_baru' && $perubahan->pk_workflow_id) {
                    $proposalWorkflow = PkWorkflow::find($perubahan->pk_workflow_id);
                    if ($proposalWorkflow) {
                        $this->compileService->compileProposalToPk04($proposalWorkflow, $pabdWorkflow->id);
                    }
                }
            }

            // 5. Insert pk04_anggaran_catatan_perubahan per item
            $this->compileCatatanPerubahan($allPerubahanItems, $pabdWorkflow->id, 'approved');

            // 6. Cycle back to PABD01 (engine handles via cycleTarget)
            $freshPabd01 = $this->createFreshPabd01Data($pabdWorkflow);
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD01',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'pabd01_data',
                dataId: $freshPabd01->id,
                extra: ['reason' => 'pabd02b_approved'],
            );
        });

        // Notify team
        $this->notifier->notify($pabdWorkflow, 'pabd02b.approved', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('admin.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Perubahan anggaran disetujui. PK04 telah di-recompile. Checklist pencairan baru telah dibuat.');
    }

    public function pabd02bReject(Pabd02bRejectRequest $request, PabdWorkflow $pabdWorkflow, Pabd02bData $pabd02bData): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->checkPermission('admin.workflows.pabd.pabd02b.reject');

        // Staleness detection
        $staleness = $this->detectPk04Staleness($pabdWorkflow);
        if ($staleness['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $staleness['changed_pks']);

            return to_route('admin.workflows.pabd.show', $pabdWorkflow)
                ->with('error', 'PK04 telah direvisi. PABD direset ke PABD01.');
        }

        $this->ensureStepActive($pabdWorkflow, 'PABD02B');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD02B', $pabd02bData->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd02bData, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        DB::transaction(function () use ($pabdWorkflow, $pabd02bData, $validated, $request, $sessionContext) {
            // 1. Save per-item komentar_approval
            $this->saveItemReviews($pabd02bData, $validated['items'] ?? []);

            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $pabd02bData,
                'pabd.pabd02b.reject',
                $request->user()->id,
                $sessionContext,
            );

            // 2. Record rejected
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD02B',
                action: 'rejected',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'],
                files: ! empty($fileIds) ? $fileIds : null,
                extra: [
                    'reviewed' => ['pabd02b_data_id' => $pabd02bData->id],
                    'pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow),
                ],
            );

            // 3. Soft-delete draft PK Proposals from proposal_baru items
            $pabd02bData->load(['itemReview.pabd02aItemPerubahan']);
            $allPerubahanItems = [];

            foreach ($pabd02bData->itemReview as $review) {
                $perubahan = $review->pabd02aItemPerubahan;
                $allPerubahanItems[] = ['review' => $review, 'perubahan' => $perubahan];

                if ($perubahan->tipe_perubahan === 'proposal_baru' && $perubahan->pk_workflow_id) {
                    PkWorkflow::find($perubahan->pk_workflow_id)?->delete();
                }
            }

            // 4. Insert pk04_anggaran_catatan_perubahan for tarik_maju items (audit)
            $this->compileCatatanPerubahan($allPerubahanItems, $pabdWorkflow->id, 'rejected');

            // 5. Cycle back to PABD01
            $freshPabd01 = $this->createFreshPabd01Data($pabdWorkflow);
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD01',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'pabd01_data',
                dataId: $freshPabd01->id,
                extra: ['reason' => 'pabd02b_rejected'],
            );
        });

        // Notify team
        $this->notifier->notify($pabdWorkflow, 'pabd02b.rejected', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('admin.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Perubahan anggaran ditolak. Checklist pencairan baru telah dibuat.');
    }

    // ──────────────────────────────────────
    // PABD03 — Approval Transfer
    // ──────────────────────────────────────

    public function pabd03Show(PabdWorkflow $pabdWorkflow): Response|\Illuminate\Http\Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd03.show");

        // Staleness detection (runs on show)
        $staleness = $this->detectPk04Staleness($pabdWorkflow);
        if ($staleness['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $staleness['changed_pks']);
            $newPabd01 = $pabdWorkflow->latestPabd01();

            return Inertia::location(route(
                $scope === 'team' ? 'team.workflows.pabd.pabd01.show' : 'admin.workflows.pabd.pabd01.show',
                ['pabdWorkflow' => $pabdWorkflow->id, 'pabd01Data' => $newPabd01->id],
            ));
        }

        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $statuses = $this->engine->getStepStatuses($definition, $pabdWorkflow->history ?? []);
        $permissions = $this->session->getActivePermissions();

        $isActive = ($statuses['PABD03']['status'] ?? '') === 'active';
        $canApprove = $isActive && $scope === 'admin' && in_array('admin.workflows.pabd.pabd03.approve', $permissions);
        $canReject = $isActive && $scope === 'admin' && in_array('admin.workflows.pabd.pabd03.reject', $permissions);
        $canComment = in_array("{$scope}.workflows.pabd.comment", $permissions);
        $mode = ($canApprove || $canReject) ? 'edit' : 'readonly';

        // PABD01 checklist data
        $latestPabd01 = $pabdWorkflow->latestPabd01();
        $pabd01ChecklistData = $latestPabd01 ? $this->resolveAnggaranItems($latestPabd01, $pabdWorkflow) : [];
        $pabd01Submitter = $this->resolvePabd01Submitter($pabdWorkflow->history ?? []);

        // Summary totals from checklist
        $summaryTotals = $this->computeChecklistSummary($pabd01ChecklistData);

        // Bank details from PP06
        $bankDetails = $this->resolveBankDetails($pabdWorkflow);

        // Stepper + history
        $stepperData = $this->engine->getStepperData($definition, $pabdWorkflow->history ?? [], function (string $code, ?int $dataId) use ($pabdWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/pabd/{$pabdWorkflow->id}";
            if ($dataId) {
                $stepLower = strtolower($code);

                return "{$base}/{$stepLower}/{$dataId}";
            }
            // PABD03 has no data table — link goes to workflow-level PABD03 route
            if ($code === 'PABD03') {
                return "{$base}/pabd03";
            }

            return null;
        });
        $formattedHistory = $this->historyFormatter->format($pabdWorkflow->history ?? []);

        // Action roles
        $actionRoles = $this->resolveActionRoles([
            'admin.workflows.pabd.pabd03.approve' => ['Setujui', true],
            'admin.workflows.pabd.pabd03.reject' => ['Tolak', false],
        ]);

        // Budget counters
        $budgetCounter = $this->getBudgetCounters($pabdWorkflow);

        // PABD01 previous cycles
        $pabd01PreviousCycles = $this->resolvePreviousCycles($pabdWorkflow, 'PABD01', $latestPabd01?->id);

        return Inertia::render('workflows/pabd/pabd03', [
            'scope' => $scope,
            'mode' => $mode,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'canComment' => $canComment,
            'canTerminate' => false,

            'workflow' => [
                'id' => $pabdWorkflow->id,
                'uuid' => $pabdWorkflow->uuid,
                'team_name' => $pabdWorkflow->team?->name,
                'bulan_anggaran' => $pabdWorkflow->bulan_anggaran,
                'bulan_label' => $this->bulanNames()[$pabdWorkflow->bulan_anggaran] ?? (string) $pabdWorkflow->bulan_anggaran,
                'tahun_anggaran' => $pabdWorkflow->tahun_anggaran,
                'updated_at' => $pabdWorkflow->updated_at?->toIso8601String(),
            ],

            'pabd01ChecklistData' => $pabd01ChecklistData,
            'pabd01Submitter' => $pabd01Submitter,
            'pabd01Cycle' => $statuses['PABD01']['cycle'] ?? 1,
            'pabd01PreviousCycles' => $pabd01PreviousCycles,
            'summaryTotals' => $summaryTotals,
            'bankDetails' => $bankDetails,

            'budgetCounter' => $budgetCounter,
            'stepStatuses' => $statuses,
            'stepperData' => $stepperData,
            'history' => $formattedHistory,
            'actionRoles' => $actionRoles,
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pabd03Approve(Pabd03ApproveRequest $request, PabdWorkflow $pabdWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->checkPermission('admin.workflows.pabd.pabd03.approve');

        // Staleness detection
        $staleness = $this->detectPk04Staleness($pabdWorkflow);
        if ($staleness['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $staleness['changed_pks']);

            return to_route('admin.workflows.pabd.show', $pabdWorkflow)
                ->with('error', 'PK04 telah direvisi. PABD direset ke PABD01.');
        }

        $this->ensureStepActive($pabdWorkflow, 'PABD03');

        $validated = $request->validated();
        $this->checkOptimisticLock($pabdWorkflow, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        DB::transaction(function () use ($pabdWorkflow, $validated, $request, $sessionContext) {
            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $pabdWorkflow,
                'pabd.pabd03.approve',
                $request->user()->id,
                $sessionContext,
            );

            // 1. Record approved
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD03',
                action: 'approved',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
            );

            // 2. Lock pk04_anggaran (Tier 2 — intermediate pencairan lock)
            $latestPabd01 = $pabdWorkflow->latestPabd01();
            if ($latestPabd01) {
                $pk04AnggaranIds = $latestPabd01->itemAnggaran()->pluck('pk04_anggaran_id');
                Pk04Anggaran::whereIn('id', $pk04AnggaranIds)
                    ->update([
                        'status_pencairan' => 'menunggu_pencairan',
                        'pencairan_pabd_workflow_id' => $pabdWorkflow->id,
                    ]);
            }

            // 3. Auto-create PABD04
            $pabd04 = Pabd04Data::create([
                'pabd_workflow_id' => $pabdWorkflow->id,
            ]);

            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD04',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'pabd04_data',
                dataId: $pabd04->id,
                extra: [
                    'triggered_by' => [
                        'user_id' => $request->user()->id,
                        'step' => 'PABD03',
                        'action' => 'approved',
                    ],
                ],
            );
        });

        // Notify Kantor Pusat
        $this->notifier->notify($pabdWorkflow, 'pabd03.approved', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('admin.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Transfer disetujui. Silakan tunggu upload bukti transfer dari Kantor Pusat.');
    }

    public function pabd03Reject(Pabd03RejectRequest $request, PabdWorkflow $pabdWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->checkPermission('admin.workflows.pabd.pabd03.reject');

        // Staleness detection
        $staleness = $this->detectPk04Staleness($pabdWorkflow);
        if ($staleness['stale']) {
            $this->resetToPabd01FromStaleness($pabdWorkflow, $staleness['changed_pks']);

            return to_route('admin.workflows.pabd.show', $pabdWorkflow)
                ->with('error', 'PK04 telah direvisi. PABD direset ke PABD01.');
        }

        $this->ensureStepActive($pabdWorkflow, 'PABD03');

        $validated = $request->validated();
        $this->checkOptimisticLock($pabdWorkflow, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        DB::transaction(function () use ($pabdWorkflow, $validated, $request, $sessionContext) {
            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $pabdWorkflow,
                'pabd.pabd03.reject',
                $request->user()->id,
                $sessionContext,
            );

            // 1. Record rejected
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD03',
                action: 'rejected',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'],
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
            );

            // 2. Cycle back to PABD01
            $freshPabd01 = $this->createFreshPabd01Data($pabdWorkflow);
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD01',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'pabd01_data',
                dataId: $freshPabd01->id,
                extra: ['reason' => 'pabd03_rejected'],
            );
        });

        // Notify team
        $this->notifier->notify($pabdWorkflow, 'pabd03.rejected', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('admin.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Transfer ditolak. Checklist pencairan baru telah dibuat.');
    }

    // ──────────────────────────────────────
    // PABD04 — Upload Bukti Transfer
    // ──────────────────────────────────────

    public function pabd04Show(PabdWorkflow $pabdWorkflow, Pabd04Data $pabd04Data): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd04.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $statuses = $this->engine->getStepStatuses($definition, $pabdWorkflow->history ?? []);
        $permissions = $this->session->getActivePermissions();

        $isActive = ($statuses['PABD04']['status'] ?? '') === 'active';
        $canDraft = $isActive && $scope === 'admin' && in_array('admin.workflows.pabd.pabd04.draft', $permissions);
        $canSubmit = $isActive && $scope === 'admin' && in_array('admin.workflows.pabd.pabd04.submit', $permissions);
        $canComment = in_array("{$scope}.workflows.pabd.comment", $permissions);
        $mode = ($canDraft || $canSubmit) ? 'edit' : 'readonly';

        // PABD01 checklist data
        $latestPabd01 = $pabdWorkflow->latestPabd01();
        $pabd01ChecklistData = $latestPabd01 ? $this->resolveAnggaranItems($latestPabd01, $pabdWorkflow) : [];
        $pabd01Submitter = $this->resolvePabd01Submitter($pabdWorkflow->history ?? []);

        // Summary totals from checklist
        $summaryTotals = $this->computeChecklistSummary($pabd01ChecklistData);

        // PABD03 approval info
        $pabd03ApprovalInfo = $this->resolvePabd03ApprovalInfo($pabdWorkflow->history ?? []);

        // Bank details from PP06
        $bankDetails = $this->resolveBankDetails($pabdWorkflow);

        // Bukti transfer files
        $buktiTransferFiles = $pabd04Data->itemBuktiTransfer()
            ->with('file')
            ->get()
            ->map(fn (Pabd04ItemBuktiTransfer $item) => [
                'id' => $item->id,
                'file_id' => $item->file_id,
                'original_filename' => $item->file?->original_filename,
                'mime_type' => $item->file?->mime_type,
                'size' => $item->file?->size,
                'uuid' => $item->file?->uuid,
                'download_url' => $item->file?->path ? route('files.download', $item->file) : null,
            ])
            ->values()
            ->all();

        // Stepper + history
        $stepperData = $this->engine->getStepperData($definition, $pabdWorkflow->history ?? [], function (string $code, ?int $dataId) use ($pabdWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/pabd/{$pabdWorkflow->id}";
            if ($dataId) {
                $stepLower = strtolower($code);

                return "{$base}/{$stepLower}/{$dataId}";
            }
            if ($code === 'PABD03') {
                return "{$base}/pabd03";
            }

            return null;
        });
        $formattedHistory = $this->historyFormatter->format($pabdWorkflow->history ?? []);

        // Action roles
        $actionRoles = $this->resolveActionRoles([
            'admin.workflows.pabd.pabd04.draft' => ['Simpan Draft', false],
            'admin.workflows.pabd.pabd04.submit' => ['Submit', true],
        ]);

        // Budget counters
        $budgetCounter = $this->getBudgetCounters($pabdWorkflow);

        // PABD01 previous cycles
        $pabd01PreviousCycles = $this->resolvePreviousCycles($pabdWorkflow, 'PABD01', $latestPabd01?->id);

        return Inertia::render('workflows/pabd/pabd04', [
            'scope' => $scope,
            'mode' => $mode,
            'canDraft' => $canDraft,
            'canSubmit' => $canSubmit,
            'canComment' => $canComment,
            'canTerminate' => false,

            'workflow' => [
                'id' => $pabdWorkflow->id,
                'uuid' => $pabdWorkflow->uuid,
                'team_name' => $pabdWorkflow->team?->name,
                'bulan_anggaran' => $pabdWorkflow->bulan_anggaran,
                'bulan_label' => $this->bulanNames()[$pabdWorkflow->bulan_anggaran] ?? (string) $pabdWorkflow->bulan_anggaran,
                'tahun_anggaran' => $pabdWorkflow->tahun_anggaran,
                'updated_at' => $pabdWorkflow->updated_at?->toIso8601String(),
            ],

            'pabd04Data' => [
                'id' => $pabd04Data->id,
                'updated_at' => $pabd04Data->updated_at?->toIso8601String(),
            ],

            'pabd01ChecklistData' => $pabd01ChecklistData,
            'pabd01Submitter' => $pabd01Submitter,
            'pabd01Cycle' => $statuses['PABD01']['cycle'] ?? 1,
            'pabd01PreviousCycles' => $pabd01PreviousCycles,
            'summaryTotals' => $summaryTotals,
            'pabd03ApprovalInfo' => $pabd03ApprovalInfo,
            'bankDetails' => $bankDetails,
            'buktiTransferFiles' => $buktiTransferFiles,

            'budgetCounter' => $budgetCounter,
            'expectedUpdatedAt' => $pabd04Data->updated_at?->toIso8601String(),
            'stepStatuses' => $statuses,
            'stepperData' => $stepperData,
            'history' => $formattedHistory,
            'actionRoles' => $actionRoles,
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pabd04Draft(Pabd04DraftRequest $request, PabdWorkflow $pabdWorkflow, Pabd04Data $pabd04Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->checkPermission('admin.workflows.pabd.pabd04.draft');
        $this->ensureStepActive($pabdWorkflow, 'PABD04');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD04', $pabd04Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd04Data, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        DB::transaction(function () use ($pabdWorkflow, $pabd04Data, $validated, $request, $sessionContext) {
            // Remove files
            $this->removeBuktiTransferFiles($pabd04Data, $validated['remove_file_ids'] ?? []);

            // Upload new files
            $this->uploadBuktiTransferFiles($pabd04Data, $request->file('bukti_transfer_files', []), $pabdWorkflow, $request->user()->id, $sessionContext);

            $pabd04Data->touch();

            // Action-level files
            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $pabd04Data,
                'pabd.pabd04.draft',
                $request->user()->id,
                $sessionContext,
            );

            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD04',
                action: 'drafted',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                table: 'pabd04_data',
                dataId: $pabd04Data->id,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow)],
            );
        });

        return to_route('admin.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Draft bukti transfer berhasil disimpan.');
    }

    public function pabd04Submit(Pabd04SubmitRequest $request, PabdWorkflow $pabdWorkflow, Pabd04Data $pabd04Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        $this->checkPermission('admin.workflows.pabd.pabd04.submit');
        $this->ensureStepActive($pabdWorkflow, 'PABD04');
        $this->ensureCurrentRecord($pabdWorkflow, 'PABD04', $pabd04Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($pabd04Data, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        $pabd05 = DB::transaction(function () use ($pabdWorkflow, $pabd04Data, $validated, $request, $sessionContext) {
            // Remove files
            $this->removeBuktiTransferFiles($pabd04Data, $validated['remove_file_ids'] ?? []);

            // Upload new files
            $this->uploadBuktiTransferFiles($pabd04Data, $request->file('bukti_transfer_files', []), $pabdWorkflow, $request->user()->id, $sessionContext);

            // Validate at least 1 bukti transfer file. Waived when nothing is
            // being disbursed this month — there is no transfer to evidence.
            // Same rule PRBL03 applies to refunds.
            $totalDicairkan = $this->resolveTotalDicairkan($pabdWorkflow);
            $fileCount = $pabd04Data->itemBuktiTransfer()->count();
            if ($fileCount === 0 && $totalDicairkan > 0) {
                abort(422, 'Minimal 1 bukti transfer harus diupload.');
            }

            $pabd04Data->touch();

            // Action-level files
            $fileIds = $this->commentService->storeFiles(
                $request->file('files', []),
                $pabd04Data,
                'pabd.pabd04.submit',
                $request->user()->id,
                $sessionContext,
            );

            // 1. Record PABD04 submitted
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD04',
                action: 'submitted',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                table: 'pabd04_data',
                dataId: $pabd04Data->id,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: [
                    'pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow),
                    'tanpa_transfer' => (bool) ($validated['tanpa_transfer'] ?? false),
                ],
            );

            // 2. Compile PABD05 (auto-compile within same transaction)
            $pabd05 = $this->pabdCompileService->compile($pabdWorkflow);

            // 3. Record PABD05 completed
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD05',
                action: 'completed',
                userId: null,
                sessionContext: [],
                table: 'pabd05_pengajuan_bulanan',
                dataId: $pabd05->id,
                extra: [
                    'triggered_by' => [
                        'user_id' => $request->user()->id,
                        'step' => 'PABD04',
                        'action' => 'submitted',
                    ],
                    'revision' => 0,
                    'pp06_revision' => $this->getLatestPp06Revision($pabdWorkflow),
                ],
            );

            return $pabd05;
        });

        // 4. Generate export files (outside transaction)
        $exportResult = $this->pabdCompileService->generateExportFiles(
            $pabd05,
            $request->user()->id,
            $pabdWorkflow->workspace_id,
        );
        $this->pabdCompileService->appendExportFilesToHistory($pabdWorkflow, $exportResult);

        // 5. Notify team — workflow complete
        $this->notifier->notify($pabdWorkflow, 'pabd04.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('admin.workflows.pabd.show', $pabdWorkflow)
            ->with('success', 'Bukti transfer berhasil disubmit. Pengajuan anggaran bulanan telah dikompilasi.');
    }

    // ──────────────────────────────────────
    // PABD04 Helpers
    // ──────────────────────────────────────

    /**
     * Upload bukti transfer files and create pabd04_item_bukti_transfer rows.
     *
     * @param  array<\Illuminate\Http\UploadedFile>  $uploadedFiles
     */
    private function uploadBuktiTransferFiles(Pabd04Data $pabd04Data, array $uploadedFiles, PabdWorkflow $pabdWorkflow, int $userId, array $sessionContext): void
    {
        foreach ($uploadedFiles as $uploadedFile) {
            $uuid = (string) Str::uuid();
            $ext = $uploadedFile->getClientOriginalExtension();
            $filename = "{$uuid}.{$ext}";
            $workspaceId = $sessionContext['workspace'] ?? $pabdWorkflow->workspace_id;
            $path = "files/{$workspaceId}/".now()->format('Y/m')."/{$filename}";

            Storage::disk('local')->putFileAs(
                dirname($path),
                $uploadedFile,
                basename($path),
            );

            $roleId = $sessionContext['role'] ?? null;
            $role = $roleId ? Role::with('team')->find($roleId) : null;

            $file = File::create([
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
                'source_route' => 'pabd.pabd04.upload',
                'attachable_type' => Pabd04Data::class,
                'attachable_id' => $pabd04Data->id,
            ]);

            Pabd04ItemBuktiTransfer::create([
                'pabd04_data_id' => $pabd04Data->id,
                'file_id' => $file->id,
            ]);
        }
    }

    /**
     * Remove bukti transfer files by item IDs.
     *
     * @param  list<int>  $removeFileIds  pabd04_item_bukti_transfer IDs to remove
     */
    private function removeBuktiTransferFiles(Pabd04Data $pabd04Data, array $removeFileIds): void
    {
        if (empty($removeFileIds)) {
            return;
        }

        $pabd04Data->itemBuktiTransfer()
            ->whereIn('id', $removeFileIds)
            ->delete();
    }

    /**
     * Total rupiah the team ticked for disbursement in the live PABD01.
     *
     * Mirrors what PabdCompileService will sum at compile, so a month that
     * compiles to zero is a month with nothing to transfer.
     */
    private function resolveTotalDicairkan(PabdWorkflow $pabdWorkflow): float
    {
        $pabd01 = $pabdWorkflow->latestPabd01();

        if (! $pabd01) {
            return 0.0;
        }

        return (float) $pabd01->itemAnggaran()
            ->where('dicairkan', true)
            ->with('pk04Anggaran')
            ->get()
            ->sum(fn ($item) => (float) ($item->pk04Anggaran?->nominal_anggaran ?? 0));
    }

    /**
     * Resolve PABD03 approval info from history.
     *
     * @return array{name: string, role: string, team: string, at: string, notes: string|null}|null
     */
    private function resolvePabd03ApprovalInfo(array $history): ?array
    {
        $formatted = $this->historyFormatter->format($history);
        foreach (array_reverse($formatted) as $entry) {
            if (($entry['step'] ?? '') === 'PABD03' && ($entry['action'] ?? '') === 'approved') {
                return [
                    'name' => $entry['by_name'] ?? 'Unknown',
                    'role' => $entry['role_name'] ?? '',
                    'team' => $entry['team_name'] ?? '',
                    'at' => $entry['at'] ?? '',
                    'notes' => $entry['notes'] ?? null,
                ];
            }
        }

        return null;
    }

    // ──────────────────────────────────────
    // PABD05 — Show + Export (readonly)
    // ──────────────────────────────────────

    public function pabd05Show(PabdWorkflow $pabdWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd05.show");

        $pabd05 = $pabdWorkflow->latestPabd05();
        if (! $pabd05) {
            abort(404, 'PABD05 belum dikompilasi.');
        }

        $pabd05->load(['itemAnggaran.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan.pkWorkflow', 'buktiTransfer.file']);

        $bulanNames = $this->bulanNames();
        $teamName = $pabdWorkflow->team?->name ?? 'Unknown';
        $bulanLabel = $bulanNames[$pabdWorkflow->bulan_anggaran] ?? (string) $pabdWorkflow->bulan_anggaran;
        $tahun = $pabdWorkflow->tahun_anggaran;
        $label = "PABD-{$teamName}-{$bulanLabel}/{$tahun}";

        // PP reference label
        $pp06 = $pabdWorkflow->ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$tahun} Revisi {$pp06->revision}" : null;

        // Build grouped items: program → kegiatan → anggaran
        $items = $this->buildPabd05GroupedItems($pabd05);

        // Bukti transfer files
        $buktiTransferFiles = $pabd05->buktiTransfer->map(fn ($bt) => [
            'id' => $bt->id,
            'file_id' => $bt->file_id,
            'filename' => $bt->file?->original_filename ?? 'Unknown',
            'mime_type' => $bt->file?->mime_type,
            'size' => $bt->file?->size,
            'path' => $bt->file?->path,
            'download_url' => $bt->file?->path ? route('files.download', $bt->file) : null,
        ])->values();

        // Export files from history
        $exportFiles = $this->resolveExportFiles($pabd05);

        // History + formatting
        $history = $pabdWorkflow->history ?? [];
        $workflowStatus = $this->engine->getWorkflowStatus($history);

        $permPrefix = "{$scope}.workflows.pabd";
        $permissions = $this->session->getActivePermissions();
        $basePath = $scope === 'team'
            ? "/team/workflows/pabd/{$pabdWorkflow->id}"
            : "/admin/workflows/pabd/{$pabdWorkflow->id}";

        // Stepper data
        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $stepperData = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($pabdWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/pabd/{$pabdWorkflow->id}";
            if ($dataId) {
                $stepLower = strtolower($code);

                return "{$base}/{$stepLower}/{$dataId}";
            }

            return null;
        });

        // Action roles
        $actionRoles = $this->resolveActionRoles([
            "{$scope}.workflows.pabd.pabd05.show" => ['Lihat', false],
            "{$scope}.workflows.pabd.pabd05.export.pdf" => ['Unduh PDF', false],
            "{$scope}.workflows.pabd.pabd05.export.excel" => ['Unduh Excel', false],
            "{$scope}.workflows.pabd.pabd05.export.zip" => ['Unduh ZIP', false],
            "{$scope}.workflows.pabd.comment" => ['Komentar', false],
        ]);

        return Inertia::render('workflows/pabd/pabd05', [
            'workflow' => [
                'id' => $pabdWorkflow->id,
                'label' => $label,
                'status' => $workflowStatus,
                'bulan_anggaran' => $pabdWorkflow->bulan_anggaran,
                'tahun_anggaran' => $tahun,
                'bulan_label' => $bulanLabel,
            ],
            'pabd05' => [
                'id' => $pabd05->id,
                'verification_code' => $pabd05->verification_code,
                'pabd01_created_by_user_name' => $pabd05->pabd01_created_by_user_name,
                'pabd01_created_by_role_name' => $pabd05->pabd01_created_by_role_name,
                'pabd01_created_by_team_name' => $pabd05->pabd01_created_by_team_name,
                'pabd01_created_at' => $pabd05->pabd01_created_at?->toIso8601String(),
                'pabd03_approved_by_user_name' => $pabd05->pabd03_approved_by_user_name,
                'pabd03_approved_by_role_name' => $pabd05->pabd03_approved_by_role_name,
                'pabd03_approved_by_team_name' => $pabd05->pabd03_approved_by_team_name,
                'pabd03_approved_at' => $pabd05->pabd03_approved_at?->toIso8601String(),
                'pabd04_created_by_user_name' => $pabd05->pabd04_created_by_user_name,
                'pabd04_created_by_role_name' => $pabd05->pabd04_created_by_role_name,
                'pabd04_created_by_team_name' => $pabd05->pabd04_created_by_team_name,
                'pabd04_created_at' => $pabd05->pabd04_created_at?->toIso8601String(),
                'nama_bank' => $pabd05->nama_bank,
                'nama_rekening' => $pabd05->nama_rekening,
                'nomor_rekening' => $pabd05->nomor_rekening,
                'total_anggaran_dicairkan' => (float) $pabd05->total_anggaran_dicairkan,
                'total_item_dicairkan' => $pabd05->total_item_dicairkan,
                'total_item_hangus' => $pabd05->total_item_hangus,
                'created_at' => $pabd05->created_at?->toIso8601String(),
            ],
            'items' => $items,
            'buktiTransferFiles' => $buktiTransferFiles,
            'exportFiles' => $exportFiles,
            'ppLabel' => $ppLabel,
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

    public function pabd05ExportPdf(PabdWorkflow $pabdWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd05.export.pdf");

        $pabd05 = $pabdWorkflow->latestPabd05();
        if (! $pabd05) {
            return back()->withErrors(['export' => 'PABD05 belum dikompilasi.']);
        }

        $file = File::where('attachable_type', Pabd05PengajuanBulanan::class)
            ->where('attachable_id', $pabd05->id)
            ->where('source_route', 'pabd05.export.pdf')
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

    public function pabd05ExportExcel(PabdWorkflow $pabdWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd05.export.excel");

        $pabd05 = $pabdWorkflow->latestPabd05();
        if (! $pabd05) {
            return back()->withErrors(['export' => 'PABD05 belum dikompilasi.']);
        }

        $file = File::where('attachable_type', Pabd05PengajuanBulanan::class)
            ->where('attachable_id', $pabd05->id)
            ->where('source_route', 'pabd05.export.excel')
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

    public function pabd05ExportZip(PabdWorkflow $pabdWorkflow): \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.pabd05.export.zip");

        $pabd05 = $pabdWorkflow->latestPabd05();
        if (! $pabd05) {
            return back()->withErrors(['export' => 'PABD05 belum dikompilasi.']);
        }

        $pabd05->load(['buktiTransfer.file']);

        $teamName = $pabdWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulan = $bulanNames[$pabdWorkflow->bulan_anggaran] ?? (string) $pabdWorkflow->bulan_anggaran;
        $tahun = $pabdWorkflow->tahun_anggaran;
        $zipFilename = "PABD-{$teamName}-{$bulan}-{$tahun}-Pengajuan-Bulanan.zip";
        $zipFilename = preg_replace('/[^\w\-. ]/', '', $zipFilename);

        // Export files (PDF + Excel)
        $exportFiles = File::where('attachable_type', Pabd05PengajuanBulanan::class)
            ->where('attachable_id', $pabd05->id)
            ->whereNotNull('path')
            ->get();

        // Bukti transfer files
        $buktiFiles = $pabd05->buktiTransfer
            ->map(fn ($bt) => $bt->file)
            ->filter(fn ($f) => $f && $f->path);

        // Comment attachment files
        $historyFileIds = collect($pabdWorkflow->history ?? [])
            ->pluck('files')
            ->filter()
            ->flatten()
            ->unique()
            ->all();
        $commentFiles = ! empty($historyFileIds)
            ? File::whereIn('id', $historyFileIds)->whereNotNull('path')->get()
            : collect();

        return response()->streamDownload(function () use ($exportFiles, $buktiFiles, $commentFiles) {
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

                $path = Storage::disk($file->disk)->path($file->path);
                if (file_exists($path)) {
                    $zip->addFileFromPath($key, $path);
                }
            };

            foreach ($exportFiles as $file) {
                $addFile($zip, '.', $file);
            }

            foreach ($buktiFiles as $file) {
                $addFile($zip, 'Bukti Transfer', $file);
            }

            foreach ($commentFiles as $file) {
                $addFile($zip, 'Lampiran Komentar', $file);
            }

            $zip->finish();
        }, $zipFilename, ['Content-Type' => 'application/zip']);
    }

    /**
     * Build grouped items for PABD05 show: program → kegiatan → anggaran.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPabd05GroupedItems(Pabd05PengajuanBulanan $pabd05): array
    {
        $bulanNames = $this->bulanNames();
        $grouped = [];

        foreach ($pabd05->itemAnggaran as $item) {
            $pk04Anggaran = $item->pk04Anggaran;
            if (! $pk04Anggaran) {
                continue;
            }

            $kegiatan = $pk04Anggaran->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;
            $pkWorkflow = $program?->pkWorkflow;

            $programKey = $program?->id ?? 0;
            $kegiatanKey = $kegiatan?->id ?? 0;

            if (! isset($grouped[$programKey])) {
                $grouped[$programKey] = [
                    'program_id' => $program?->id,
                    'program_name' => $program?->nama_program ?? 'Unknown',
                    'kode_kategori' => $program?->kode_kategori ?? '',
                    'tipe' => $pkWorkflow?->tipe ?? 'raker',
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
                'pabd05_item_id' => $item->id,
                'pk04_anggaran_id' => $pk04Anggaran->id,
                'kode_anggaran_baru' => $pk04Anggaran->kode_anggaran_baru,
                'kode_anggaran_lama' => $pk04Anggaran->kode_anggaran_lama,
                'mata_anggaran' => $pk04Anggaran->mata_anggaran,
                'nominal_anggaran' => (float) $item->nominal_anggaran,
                'status' => $item->status,
            ];
        }

        // Convert nested associative arrays to indexed arrays
        return collect($grouped)->map(function ($program) {
            $program['kegiatan'] = array_values($program['kegiatan']);

            return $program;
        })->values()->all();
    }

    /**
     * Resolve export files (PDF + Excel) for PABD05.
     *
     * @return array{pdf: array|null, excel: array|null}
     */
    private function resolveExportFiles(Pabd05PengajuanBulanan $pabd05): array
    {
        $files = File::where('attachable_type', Pabd05PengajuanBulanan::class)
            ->where('attachable_id', $pabd05->id)
            ->whereIn('source_route', ['pabd05.export.pdf', 'pabd05.export.excel'])
            ->get();

        $pdf = $files->firstWhere('source_route', 'pabd05.export.pdf');
        $excel = $files->firstWhere('source_route', 'pabd05.export.excel');

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
    // PABD03 Helpers
    // ──────────────────────────────────────

    /**
     * Compute summary totals from checklist data.
     *
     * @return array{total: float, totalDicairkan: float, totalTidakDicairkan: float, countAll: int, countDicairkan: int, countTidakDicairkan: int}
     */
    private function computeChecklistSummary(array $pabd01ChecklistData): array
    {
        $total = 0;
        $totalDicairkan = 0;
        $totalTidakDicairkan = 0;
        $countAll = 0;
        $countDicairkan = 0;
        $countTidakDicairkan = 0;

        foreach ($pabd01ChecklistData as $program) {
            foreach ($program['kegiatan'] as $kegiatan) {
                foreach ($kegiatan['anggaran'] as $anggaran) {
                    $nominal = (float) $anggaran['nominal'];
                    $total += $nominal;
                    $countAll++;

                    if ($anggaran['dicairkan']) {
                        $totalDicairkan += $nominal;
                        $countDicairkan++;
                    } else {
                        $totalTidakDicairkan += $nominal;
                        $countTidakDicairkan++;
                    }
                }
            }
        }

        return compact('total', 'totalDicairkan', 'totalTidakDicairkan', 'countAll', 'countDicairkan', 'countTidakDicairkan');
    }

    /**
     * Resolve bank details from PP06 item_plafon_anggaran for this team.
     *
     * @return array{nama_rekening: string|null, nomor_rekening: string|null, nama_bank: string|null, pp_label: string|null, pp_revision: int}
     */
    private function resolveBankDetails(PabdWorkflow $pabdWorkflow): array
    {
        $pp06 = $pabdWorkflow->ppWorkflow?->latestPp06();
        if (! $pp06) {
            return ['nama_rekening' => null, 'nomor_rekening' => null, 'nama_bank' => null, 'pp_label' => null, 'pp_revision' => 0];
        }

        $plafon = $pp06->itemPlafonAnggaran()
            ->where('team_id', $pabdWorkflow->team_id)
            ->first();

        $pp01 = $pabdWorkflow->ppWorkflow?->latestPp01();

        return [
            'nama_rekening' => $plafon?->nama_rekening,
            'nomor_rekening' => $plafon?->nomor_rekening,
            'nama_bank' => $plafon?->nama_bank,
            'pp_label' => $pp01 ? "PP-{$pp01->tahun}" : null,
            'pp_revision' => $pp06->revision,
        ];
    }

    // ──────────────────────────────────────
    // PABD02B Helpers
    // ──────────────────────────────────────

    /**
     * Save per-item komentar_approval on pabd02b_item_review rows.
     */
    private function saveItemReviews(Pabd02bData $pabd02bData, array $items): void
    {
        foreach ($items as $itemData) {
            $reviewId = $itemData['pabd02b_item_review_id'] ?? null;
            if (! $reviewId) {
                continue;
            }

            $pabd02bData->itemReview()
                ->where('id', $reviewId)
                ->update(['komentar_approval' => $itemData['komentar_approval'] ?? null]);
        }

        $pabd02bData->touch();
    }

    /**
     * Insert pk04_anggaran_catatan_perubahan per change item.
     *
     * @param  list<array{review: mixed, perubahan: Pabd02aItemPerubahan}>  $allItems
     */
    private function compileCatatanPerubahan(array $allItems, int $pabdWorkflowId, string $outcome): void
    {
        foreach ($allItems as $itemData) {
            $perubahan = $itemData['perubahan'];
            $review = $itemData['review'];

            // Only create catatan for tarik_maju items (they have pk04_anggaran_id)
            if (! in_array($perubahan->tipe_perubahan, self::TIPE_PEMINDAHAN_BULAN, true) || ! $perubahan->pk04_anggaran_id) {
                continue;
            }

            Pk04AnggaranCatatanPerubahan::create([
                'pk04_anggaran_id' => $perubahan->pk04_anggaran_id,
                'pabd_workflow_id' => $pabdWorkflowId,
                'tipe_perubahan' => $perubahan->tipe_perubahan,
                'catatan_pemohon' => $perubahan->komentar,
                'catatan_approval' => $review->komentar_approval,
            ]);
        }
    }

    /**
     * Compute kode anggaran preview for a pemindahan bulan item.
     *
     * Shows what the new kode will look like after recompile:
     * - bulan segment changes from bulan_awal → bulan_tujuan
     * - tarik depth increments by 1
     * - revisi uses current revision + 1
     *
     * @return array{current_kode: string, preview_kode: string}
     */
    private function computeKodePreview(Pabd02aItemPerubahan $item): array
    {
        $anggaran = $item->pk04Anggaran;
        $currentKode = $anggaran->kode_anggaran_baru ?? '';

        if (empty($currentKode)) {
            return ['current_kode' => '', 'preview_kode' => ''];
        }

        $segments = explode('.', $currentKode);
        if (count($segments) < 13) {
            return ['current_kode' => $currentKode, 'preview_kode' => $currentKode];
        }

        // Bulan segment (index 10): change to bulan_tujuan
        $segments[10] = str_pad((string) $item->bulan_tujuan, 2, '0', STR_PAD_LEFT);

        // Revision segment (index 11): increment
        $currentRev = (int) str_replace('rev', '', $segments[11]);
        $segments[11] = 'rev'.($currentRev + 1);

        // Tarik depth segment (index 12): increment
        $currentDepth = (int) str_replace('M', '', $segments[12]);
        $segments[12] = 'M'.($currentDepth + 1);

        return [
            'current_kode' => $currentKode,
            'preview_kode' => implode('.', $segments),
        ];
    }

    /**
     * Resolve PABD02A submitter info from history.
     *
     * @return array{name: string, role: string, team: string, at: string}|null
     */
    private function resolvePabd02aSubmitter(array $history): ?array
    {
        $formatted = $this->historyFormatter->format($history);
        foreach (array_reverse($formatted) as $entry) {
            if (($entry['step'] ?? '') === 'PABD02A' && ($entry['action'] ?? '') === 'submitted') {
                return [
                    'name' => $entry['by_name'] ?? 'Unknown',
                    'role' => $entry['role_name'] ?? '',
                    'team' => $entry['team_name'] ?? '',
                    'at' => $entry['at'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Resolve previous cycles for a step (pattern F — collapsed previous data).
     *
     * @return list<array{cycle: int, dataId: int|null}>
     */
    private function resolvePreviousCycles(PabdWorkflow $pabdWorkflow, string $step, ?int $currentDataId): array
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $stepDef = $definition->steps()[$step] ?? null;
        if (! $stepDef) {
            return [];
        }

        $table = $stepDef['table'] ?? null;
        if (! $table) {
            return [];
        }

        // Find all data rows for this step, excluding current
        $modelClass = match ($table) {
            'pabd01_data' => Pabd01Data::class,
            'pabd02a_data' => Pabd02aData::class,
            'pabd02b_data' => Pabd02bData::class,
            default => null,
        };

        if (! $modelClass) {
            return [];
        }

        $allDataIds = $modelClass::where('pabd_workflow_id', $pabdWorkflow->id)
            ->orderBy('id')
            ->pluck('id');

        $cycles = [];
        $cycleNum = 0;
        foreach ($allDataIds as $dataId) {
            $cycleNum++;
            if ($currentDataId !== null && $dataId === $currentDataId) {
                continue;
            }
            $cycles[] = ['cycle' => $cycleNum, 'dataId' => $dataId];
        }

        return $cycles;
    }

    // ──────────────────────────────────────
    // Staleness Detection
    // ──────────────────────────────────────

    /**
     * Check if any PK04 contributing to this PABD has been revised since PABD01 items were populated.
     *
     * @return array{stale: bool, changed_pks: list<int>}
     */
    private function detectPk04Staleness(PabdWorkflow $pabdWorkflow): array
    {
        $latestPabd01 = $pabdWorkflow->latestPabd01();
        if (! $latestPabd01) {
            return ['stale' => false, 'changed_pks' => []];
        }

        $snapshot = $latestPabd01->pk04_revisions_snapshot ?? [];
        if (empty($snapshot)) {
            return ['stale' => false, 'changed_pks' => []];
        }

        $changedPks = [];
        $pk04Ids = array_keys($snapshot);

        $liveRevisions = Pk04ProgramTahunan::whereIn('id', $pk04Ids)
            ->pluck('revision', 'id');

        foreach ($snapshot as $pk04Id => $snapshotRevision) {
            $liveRevision = $liveRevisions->get((int) $pk04Id, 0);
            if ($liveRevision > $snapshotRevision) {
                $changedPks[] = (int) $pk04Id;
            }
        }

        return ['stale' => ! empty($changedPks), 'changed_pks' => $changedPks];
    }

    /**
     * Reset PABD to fresh PABD01 when PK04 staleness is detected.
     *
     * @param  list<int>  $changedPkIds  PK04 program tahunan IDs that changed
     */
    private function resetToPabd01FromStaleness(PabdWorkflow $pabdWorkflow, array $changedPkIds): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $currentSteps = $this->engine->getCurrentSteps($definition, $pabdWorkflow->history ?? []);
        $currentStep = $currentSteps[0] ?? 'PABD01';

        // Record reset
        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: $currentStep,
            action: 'reset',
            userId: null,
            sessionContext: [],
            extra: [
                'reason' => 'pk04_revised',
                'cycleTarget' => 'PABD01',
                'changed_pks' => $changedPkIds,
            ],
        );

        // Create fresh PABD01 data + items
        $freshPabd01 = $this->createFreshPabd01Data($pabdWorkflow);

        $this->engine->recordAction(
            workflow: $pabdWorkflow,
            step: 'PABD01',
            action: 'created',
            userId: null,
            sessionContext: [],
            table: 'pabd01_data',
            dataId: $freshPabd01->id,
            extra: ['reason' => 'pk04_staleness_reset'],
        );

        $this->notifier->notify($pabdWorkflow, 'pabd.pk04_staleness_reset', [
            'action_verb' => 'direset',
            'step_label' => 'Checklist Pencairan (PABD01)',
            'next_instruction' => 'PK04 telah direvisi. Silakan review ulang checklist pencairan.',
        ]);
    }

    // ──────────────────────────────────────
    // Data Resolution Helpers
    // ──────────────────────────────────────

    /**
     * Create fresh pabd01_data + pabd01_item_anggaran rows from PK04 data.
     */
    private function createFreshPabd01Data(PabdWorkflow $pabdWorkflow): Pabd01Data
    {
        $bulan = $pabdWorkflow->bulan_anggaran;
        $teamId = $pabdWorkflow->team_id;
        $ppWorkflowId = $pabdWorkflow->pp_workflow_id;
        $workspaceId = $pabdWorkflow->workspace_id;

        // Find ALL PK04 finals for this team + PP period
        $pk04Finals = Pk04ProgramTahunan::query()
            ->whereHas('pkWorkflow', fn ($q) => $q
                ->where('team_id', $teamId)
                ->where('pp_workflow_id', $ppWorkflowId)
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
            )
            ->get()
            ->groupBy('pk_workflow_id')
            ->map(fn ($group) => $group->sortByDesc('revision')->first());

        // Build snapshot
        $snapshot = [];
        foreach ($pk04Finals as $pk04) {
            $snapshot[$pk04->id] = $pk04->revision;
        }

        $pabd01 = Pabd01Data::create([
            'pabd_workflow_id' => $pabdWorkflow->id,
            'ada_perubahan' => false,
            'pk04_revisions_snapshot' => $snapshot,
        ]);

        // Collect pk04_anggaran IDs for items in target month
        foreach ($pk04Finals as $pk04) {
            $anggaranIds = Pk04Anggaran::query()
                ->whereHas('pk04Kegiatan', fn ($q) => $q
                    ->where('pk04_program_tahunan_id', $pk04->id)
                    ->where('bulan', $bulan)
                )
                ->pluck('id');

            foreach ($anggaranIds as $anggaranId) {
                $pabd01->itemAnggaran()->create([
                    'pk04_anggaran_id' => $anggaranId,
                    'dicairkan' => false,
                ]);
            }
        }

        return $pabd01;
    }

    /**
     * Compute which months each team can have a new PABD created for.
     *
     * Logic: for each team with PK04 finals, find months where:
     * 1. Active anggaran exists (nominal > 0, status_item = active)
     * 2. No PABD already exists for that month
     * 3. The month follows the PRBL chain (after last completed PRBL, or first if no PABD exists)
     *
     * @return list<array{team_id: int, team_name: string, months: list<int>}>
     */
    /**
     * Return all PP workflows with completed PP06, each with their eligible teams/months.
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
     * For a given PP workflow, compute which teams have eligible months for PABD creation.
     *
     * @return array<int, array{team_id: int, team_name: string, months: int[]}>
     */
    private function computeCreatableTeamMonths(int $workspaceId, int $ppWorkflowId, int $tahun): array
    {
        // Find all teams that have PK workflows with PK04 finals
        $pkWorkflows = PkWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->whereNull('deleted_at')
            ->get();

        $pkByTeam = $pkWorkflows->groupBy('team_id');
        $result = [];

        foreach ($pkByTeam as $teamId => $teamPkWorkflows) {
            $pk04Finals = Pk04ProgramTahunan::query()
                ->whereIn('pk_workflow_id', $teamPkWorkflows->pluck('id'))
                ->get()
                ->groupBy('pk_workflow_id')
                ->map(fn ($group) => $group->sortByDesc('revision')->first());

            if ($pk04Finals->isEmpty()) {
                continue;
            }

            // Find all months with active anggaran
            $monthsWithAnggaran = Pk04Anggaran::query()
                ->whereHas('pk04Kegiatan', fn ($q) => $q
                    ->whereIn('pk04_program_tahunan_id', $pk04Finals->pluck('id'))
                )
                ->where('nominal_anggaran', '>', 0)
                ->where('status_item', 'active')
                ->join('pk04_kegiatan', 'pk04_anggaran.pk04_kegiatan_id', '=', 'pk04_kegiatan.id')
                ->distinct()
                ->pluck('pk04_kegiatan.bulan')
                ->sort()
                ->values()
                ->toArray();

            if (empty($monthsWithAnggaran)) {
                continue;
            }

            // Find months that already have a PABD
            $existingPabdMonths = PabdWorkflow::query()
                ->where('workspace_id', $workspaceId)
                ->where('team_id', $teamId)
                ->where('pp_workflow_id', $ppWorkflowId)
                ->where('tahun_anggaran', $tahun)
                ->pluck('bulan_anggaran')
                ->toArray();

            // Find the last completed PRBL month
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

            // Also check if any PABD exists at all — if none, first month is eligible
            $hasAnyPabd = ! empty($existingPabdMonths);

            $eligible = [];
            foreach ($monthsWithAnggaran as $month) {
                // Already has PABD
                if (in_array($month, $existingPabdMonths)) {
                    continue;
                }

                // Chain check: must be after last completed PRBL month
                if ($hasAnyPabd && $month <= $lastCompletedPrblMonth) {
                    continue;
                }

                // If there's an existing PABD but no completed PRBL for it yet,
                // only allow months after that PABD's month once its PRBL completes
                if ($hasAnyPabd && $lastCompletedPrblMonth === 0) {
                    // No PRBL completed yet — can't create next month
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

    /**
     * Resolve anggaran items grouped by program → kegiatan → anggaran for display.
     *
     * @return list<array{program_id: int, program_name: string, kode_kategori: string, kegiatan: list<array>}>
     */
    private function resolveAnggaranItems(Pabd01Data $pabd01Data, PabdWorkflow $pabdWorkflow): array
    {
        $items = $pabd01Data->itemAnggaran()
            ->with([
                'pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan.pkWorkflow',
            ])
            ->get();

        $bulanNames = $this->bulanNames();
        $grouped = [];

        foreach ($items as $item) {
            $anggaran = $item->pk04Anggaran;
            if (! $anggaran) {
                continue;
            }

            $kegiatan = $anggaran->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;
            if (! $program) {
                continue;
            }

            $programKey = $program->id;

            if (! isset($grouped[$programKey])) {
                $tipe = $program->pkWorkflow?->tipe ?? 'raker';
                $grouped[$programKey] = [
                    'program_id' => $program->id,
                    'program_name' => $program->nama_program,
                    'kode_kategori' => $program->kode_kategori,
                    'tipe' => $tipe,
                    'kegiatan' => [],
                ];
            }

            $kegiatanKey = $kegiatan->id;
            if (! isset($grouped[$programKey]['kegiatan'][$kegiatanKey])) {
                $grouped[$programKey]['kegiatan'][$kegiatanKey] = [
                    'kegiatan_id' => $kegiatan->id,
                    'nama_kegiatan' => $kegiatan->nama_kegiatan,
                    'bulan' => $kegiatan->bulan,
                    'bulan_label' => $bulanNames[$kegiatan->bulan] ?? (string) $kegiatan->bulan,
                    'anggaran' => [],
                ];
            }

            // Determine status badge
            if (in_array($anggaran->status_item, ['ditarik_maju', 'ditarik_mundur'], true)) {
                // Source item that was moved to another month
                $arah = $anggaran->status_item === 'ditarik_maju' ? 'Maju' : 'Mundur';
                $targetKegiatan = Pk04Anggaran::where('previous_anggaran_id', $anggaran->id)
                    ->first()?->pk04Kegiatan;
                $targetBulan = $targetKegiatan?->bulan;
                $statusLabel = $targetBulan
                    ? "Ditarik {$arah} ke Bln {$targetBulan}"
                    : "Ditarik {$arah}";
            } elseif (in_array($anggaran->source, ['tarik_maju', 'tarik_mundur'], true)) {
                // Item that was moved INTO this month from another month
                $arah = $anggaran->source === 'tarik_maju' ? 'Maju' : 'Mundur';
                $sourceBulan = Pk04Anggaran::find($anggaran->previous_anggaran_id)?->pk04Kegiatan?->bulan;
                $statusLabel = $sourceBulan
                    ? "Tarik {$arah} dari Bln {$sourceBulan}"
                    : "Tarik {$arah}";
            } elseif ($grouped[$programKey]['tipe'] === 'proposal') {
                $statusLabel = 'Di Luar Plafon';
            } else {
                $statusLabel = 'Normal';
            }

            $grouped[$programKey]['kegiatan'][$kegiatanKey]['anggaran'][] = [
                'pabd01_item_id' => $item->id,
                'pk04_anggaran_id' => $anggaran->id,
                'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
                'kode_anggaran_lama' => $anggaran->kode_anggaran_lama,
                'mata_anggaran' => $anggaran->mata_anggaran,
                'nominal' => (float) $anggaran->nominal_anggaran,
                'status_item' => $anggaran->status_item,
                'status_label' => $statusLabel,
                'dicairkan' => $item->dicairkan,
            ];
        }

        // Convert kegiatan from keyed map to array
        $result = [];
        foreach ($grouped as $programData) {
            $programData['kegiatan'] = array_values($programData['kegiatan']);
            $result[] = $programData;
        }

        return $result;
    }

    // ──────────────────────────────────────
    // Shared helpers (same pattern as PK)
    // ──────────────────────────────────────

    private function syncItemCheckboxes(Pabd01Data $pabd01Data, array $items): void
    {
        foreach ($items as $itemData) {
            $pabd01Data->itemAnggaran()
                ->where('id', $itemData['pabd01_item_anggaran_id'])
                ->update(['dicairkan' => $itemData['dicairkan']]);
        }
        // Touch parent to update the updated_at for optimistic locking
        $pabd01Data->touch();
    }

    private function resolveCycleBackNotes(array $history): ?array
    {
        $formattedHistory = $this->historyFormatter->format($history);

        // Walk history backward to find the most recent action that triggered a cycle-back to PABD01
        $cycleBackActions = ['approved', 'rejected'];
        $cycleBackSteps = ['PABD02B', 'PABD03'];

        for ($i = count($formattedHistory) - 1; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            if (in_array($entry['action'] ?? '', $cycleBackActions)
                && in_array($entry['step'] ?? '', $cycleBackSteps)) {
                return [
                    'step' => $entry['step'],
                    'step_label' => $this->engine->resolveDefinition(WorkflowType::PABD)->stepLabel($entry['step']),
                    'action' => $entry['action'],
                    'notes' => $entry['notes'] ?? null,
                    'by_name' => $entry['by_name'] ?? null,
                    'role_name' => $entry['role_name'] ?? null,
                    'team_name' => $entry['team_name'] ?? null,
                    'at' => $entry['at'] ?? null,
                    'files' => $entry['files'] ?? null,
                ];
            }
        }

        return null;
    }

    private function getBudgetCounters(PabdWorkflow $pabdWorkflow): array
    {
        $teamName = $pabdWorkflow->team?->name ?? 'Unknown';
        $pp06 = $pabdWorkflow->ppWorkflow?->latestPp06();
        if (! $pp06) {
            return [
                'ppLabel' => null, 'tahun' => null, 'teamName' => $teamName,
                'plafon' => 0, 'accepted' => 0, 'review' => 0, 'pendingRaker' => 0, 'draft' => 0,
                'proposalAccepted' => 0, 'proposalReview' => 0, 'proposalDraft' => 0,
            ];
        }

        $teamId = $pabdWorkflow->team_id;
        $pp01 = $pabdWorkflow->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun ? (int) $pp01->tahun : null;

        $plafon = (float) ($pp06->itemPlafonAnggaran()
            ->where('team_id', $teamId)
            ->value('plafon_anggaran') ?? 0);

        $accepted = (float) Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan.pk04ProgramTahunan', fn ($q) => $q
                ->whereHas('pkWorkflow', fn ($q2) => $q2
                    ->where('team_id', $teamId)
                    ->where('workspace_id', $pabdWorkflow->workspace_id)
                    ->where('pp_workflow_id', $pabdWorkflow->pp_workflow_id)
                    ->where('tipe', 'raker')
                    ->whereNull('deleted_at')
                )
                ->whereRaw('pk04_program_tahunan.revision = (
                    SELECT MAX(latest.revision)
                    FROM pk04_program_tahunan latest
                    WHERE latest.pk_workflow_id = pk04_program_tahunan.pk_workflow_id
                )')
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
            ->where('workspace_id', $pabdWorkflow->workspace_id)
            ->where('pp_workflow_id', $pabdWorkflow->pp_workflow_id)
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

        // Proposal totals
        $proposalAccepted = (float) Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan.pk04ProgramTahunan.pkWorkflow', fn ($q) => $q
                ->where('team_id', $teamId)
                ->where('workspace_id', $pabdWorkflow->workspace_id)
                ->where('pp_workflow_id', $pabdWorkflow->pp_workflow_id)
                ->where('tipe', 'proposal')
                ->whereNull('deleted_at')
            )
            ->where('status_item', 'active')
            ->sum('nominal_anggaran');

        $proposalDraft = 0.0;
        $proposalReview = 0.0;
        $pabdDefinition = new \App\Workflows\PabdWorkflowDefinition;

        $activeProposalPkWorkflows = PkWorkflow::query()
            ->where('team_id', $teamId)
            ->where('workspace_id', $pabdWorkflow->workspace_id)
            ->where('pp_workflow_id', $pabdWorkflow->pp_workflow_id)
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

            // Proposal PKs are auto-compiled to PK04 on PABD02B approve — they never run their own
            // PK01/PK02A/PK02B review. Classify by the linking PABD's current step instead:
            // PABD02A active => Bapel still drafting => proposalDraft.
            // PABD02B (or later) active => BU reviewing => proposalReview.
            $linkedItem = Pabd02aItemPerubahan::where('pk_workflow_id', $wf->id)->latest('id')->first();
            $linkedPabd = $linkedItem ? PabdWorkflow::find($linkedItem->pabd_workflow_id) : null;
            $pabdSteps = $linkedPabd
                ? $this->engine->getCurrentSteps($pabdDefinition, $linkedPabd->history ?? [])
                : [];

            if (in_array('PABD02A', $pabdSteps)) {
                $proposalDraft += $total;
            } else {
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

    private function getLatestPp06Revision(PabdWorkflow $pabdWorkflow): int
    {
        return $pabdWorkflow->ppWorkflow?->latestPp06()?->revision ?? 0;
    }

    private function getScope(): string
    {
        $routeName = request()->route()?->getName() ?? '';

        return str_starts_with($routeName, 'team.') ? 'team' : 'admin';
    }

    private function ensureWorkspaceOwnership(PabdWorkflow $pabdWorkflow): void
    {
        if ($pabdWorkflow->workspace_id !== $this->session->getActiveWorkspaceId()) {
            abort(403, 'Workflow bukan milik workspace aktif.');
        }
    }

    private function ensureTeamOwnership(PabdWorkflow $pabdWorkflow): void
    {
        $roleId = $this->session->getActiveRoleId();
        $userTeamId = $roleId ? Role::find($roleId)?->team_id : null;

        if ($userTeamId === null || $pabdWorkflow->team_id !== $userTeamId) {
            abort(403, 'Workflow bukan milik tim Anda.');
        }
    }

    private function checkPermission(string $permission): void
    {
        if (! in_array($permission, $this->session->getActivePermissions())) {
            abort(403);
        }
    }

    private function ensureStepActive(PabdWorkflow $pabdWorkflow, string $step): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);

        if (! in_array($step, $this->engine->getCurrentSteps($definition, $pabdWorkflow->history ?? []))) {
            abort(409, 'Step ini sudah tidak aktif.');
        }
    }

    private function ensureCurrentRecord(PabdWorkflow $pabdWorkflow, string $step, int $recordId): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PABD);
        $statuses = $this->engine->getStepStatuses($definition, $pabdWorkflow->history ?? []);
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

    /**
     * @param  array<int, string>  $userCache
     * @param  array<int, string|null>  $roleCache
     */
    private function transformPabdForIndex(PabdWorkflow $wf, \App\Contracts\WorkflowDefinition $definition, string $scope, array &$userCache, array &$roleCache): array
    {
        $history = $wf->history ?? [];
        $engineStatus = $this->engine->getWorkflowStatus($history);
        $status = $engineStatus === 'completed' ? 'completed' : 'active';

        // Step aktif
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

        // Total anggaran
        $pabd05 = $wf->latestPabd05();
        if ($pabd05) {
            $totalAnggaran = (float) $pabd05->itemAnggaran()->sum('nominal_anggaran');
        } else {
            $latestPabd01 = $wf->latestPabd01();
            $totalAnggaran = $latestPabd01
                ? (float) $latestPabd01->itemAnggaran()
                    ->join('pk04_anggaran', 'pabd01_item_anggaran.pk04_anggaran_id', '=', 'pk04_anggaran.id')
                    ->sum('pk04_anggaran.nominal_anggaran')
                : null;
            if ($totalAnggaran !== null && $totalAnggaran == 0) {
                $totalAnggaran = null;
            }
        }

        // Terakhir
        [$lastActorName, $lastActorRole] = $this->resolveLastActor($history, $userCache, $roleCache);

        $row = [
            'id' => $wf->id,
            'bulan_anggaran' => $wf->bulan_anggaran,
            'bulan_label' => $this->bulanLabel($wf->bulan_anggaran),
            'tahun_anggaran' => $wf->tahun_anggaran,
            'status' => $status,
            'step_aktif' => $stepAktif,
            'pp_label' => $ppLabel,
            'total_anggaran' => $totalAnggaran,
            'terakhir_name' => $lastActorName,
            'terakhir_role' => $lastActorRole,
            'tanggal' => $wf->created_at->format('d/m/Y'),
        ];

        if ($scope === 'admin') {
            $row['team_name'] = $wf->team?->name ?? 'Unknown';
        }

        return $row;
    }

    /**
     * Resolve last non-comment actor from history.
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
     * Inject roles and branch group metadata into stepper cycles.
     *
     * PABD02A/02B form a conditional sub-loop off PABD01 (always cycles back to PABD01).
     *
     * @param  array<int, array{steps: array<int, array<string, mixed>>}>  $stepperCycles
     * @param  array<string, list<array{name: string, users: list<string>}>>  $stepRoleMap
     */
    private function injectStepperMeta(array &$stepperCycles, array $stepRoleMap): void
    {
        $branchSteps = ['PABD02A', 'PABD02B'];

        foreach ($stepperCycles as &$cycle) {
            // Check if PABD03+ has progressed — if so, branch steps that are still
            // "active" (never used) should be marked as "skipped" instead.
            $mainPathProgressed = false;
            foreach ($cycle['steps'] as $step) {
                if (in_array($step['code'], ['PABD03', 'PABD04', 'PABD05'])
                    && in_array($step['status'], ['completed', 'active'])) {
                    $mainPathProgressed = true;
                    break;
                }
            }

            foreach ($cycle['steps'] as &$step) {
                $step['roles'] = $stepRoleMap[$step['code']] ?? [];
                $step['branchGroup'] = in_array($step['code'], $branchSteps) ? 'pabd02' : null;
                $step['branchTarget'] = in_array($step['code'], $branchSteps) ? 'PABD01' : null;

                // Override branch steps to "skipped" when main path already progressed past them
                if ($mainPathProgressed && in_array($step['code'], $branchSteps) && in_array($step['status'], ['active', 'pending'])) {
                    $step['status'] = 'skipped';
                }
            }
        }
        unset($cycle, $step);
    }

    /**
     * Resolve step roles for show page stepper tooltips.
     *
     * @return array<string, list<array{name: string, users: list<string>}>>
     */
    private function resolveStepRolesForShow(?int $teamId = null): array
    {
        $stepPermissions = [
            'PABD01' => 'team.workflows.pabd.pabd01.submit',
            'PABD02A' => 'team.workflows.pabd.pabd02a.submit',
            'PABD02B' => 'admin.workflows.pabd.pabd02b.approve',
            'PABD03' => 'admin.workflows.pabd.pabd03.approve',
            'PABD04' => 'admin.workflows.pabd.pabd04.submit',
            'PABD05' => null,
        ];

        $teamScopedSteps = ['PABD01', 'PABD02A'];

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
