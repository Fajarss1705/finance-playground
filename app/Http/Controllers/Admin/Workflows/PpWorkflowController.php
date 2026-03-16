<?php

namespace App\Http\Controllers\Admin\Workflows;

use App\Contracts\WorkflowDefinition;
use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Workflows\Pp01DraftRequest;
use App\Http\Requests\Admin\Workflows\Pp01SubmitRequest;
use App\Http\Requests\Admin\Workflows\Pp02DraftRequest;
use App\Http\Requests\Admin\Workflows\Pp02SubmitRequest;
use App\Http\Requests\Admin\Workflows\Pp03DraftRequest;
use App\Http\Requests\Admin\Workflows\Pp03SubmitRequest;
use App\Http\Requests\Admin\Workflows\Pp04DraftRequest;
use App\Http\Requests\Admin\Workflows\Pp04SubmitRequest;
use App\Http\Requests\Admin\Workflows\Pp05ApproveRequest;
use App\Http\Requests\Admin\Workflows\Pp05RejectRequest;
use App\Http\Requests\Admin\Workflows\Pp07DraftRequest;
use App\Http\Requests\Admin\Workflows\Pp07SubmitRequest;
use App\Http\Requests\Admin\Workflows\PpCommentRequest;
use App\Http\Requests\Admin\Workflows\PpTerminateRequest;
use App\Models\File;
use App\Models\Permission;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp02Data;
use App\Models\Pp\Pp03Data;
use App\Models\Pp\Pp04Data;
use App\Models\Pp\Pp07Data;
use App\Models\Pp\PpWorkflow;
use App\Models\Role;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\PpCompileService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PpWorkflowController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private ActiveSessionService $session,
        private HistoryFormatter $historyFormatter,
        private CommentService $commentService,
        private WorkflowNotifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $this->checkPermission('admin.workflows.pp.index');
        $workspaceId = $this->session->getActiveWorkspaceId();
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        $query = PpWorkflow::query()
            ->where('workspace_id', $workspaceId);

        // Tong Sampah toggle
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }

        // Tahun filter — join via pp01_data
        if ($request->filled('tahun')) {
            $query->whereHas('pp01Data', fn ($q) => $q->where('tahun', (int) $request->input('tahun')));
        }

        $statusFilter = $request->input('status');
        $anggaranFilter = $request->input('anggaran');
        $userCache = [];
        $roleCache = [];

        // When computed filters are active, we must load all then filter (status/anggaran are computed from JSON/relations).
        // PP workflows per workspace are small (a few per year), so this is fine.
        if ($statusFilter || $anggaranFilter) {
            $allWorkflows = $query->orderByDesc('created_at')->get();
            $transformed = $allWorkflows->map(fn (PpWorkflow $wf) => $this->transformWorkflowForIndex($wf, $definition, $userCache, $roleCache));

            if ($statusFilter) {
                $transformed = $transformed->filter(fn ($item) => $item['status'] === $statusFilter);
            }

            if ($anggaranFilter) {
                $transformed = $this->applyAnggaranFilter($transformed, $anggaranFilter);
            }

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

            $workflows->through(fn (PpWorkflow $wf) => $this->transformWorkflowForIndex($wf, $definition, $userCache, $roleCache));
        }

        // Available tahun values for filter
        $availableTahun = Pp01Data::query()
            ->whereIn('pp_workflow_id', PpWorkflow::where('workspace_id', $workspaceId)->select('id'))
            ->whereNotNull('tahun')
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->values();

        $permissions = $this->session->getActivePermissions();

        return Inertia::render('admin/workflows/pp/index', [
            'workflows' => $workflows,
            'filters' => [
                'status' => $request->input('status'),
                'tahun' => $request->input('tahun'),
                'anggaran' => $request->input('anggaran'),
                'trash' => $request->boolean('trash'),
            ],
            'availableTahun' => $availableTahun,
            'canCreate' => in_array('admin.workflows.pp.create', $permissions),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $this->checkPermission('admin.workflows.pp.create');

        $workspaceId = $this->session->getActiveWorkspaceId();

        $workflow = PpWorkflow::create([
            'workspace_id' => $workspaceId,
            'history' => [],
        ]);

        $pp01 = Pp01Data::create([
            'pp_workflow_id' => $workflow->id,
        ]);

        $this->engine->recordAction(
            workflow: $workflow,
            step: 'PP01',
            action: 'created',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp01_data',
            dataId: $pp01->id,
        );

        return to_route('admin.workflows.pp.pp01.show', [
            'ppWorkflow' => $workflow->id,
            'pp01Data' => $pp01->id,
        ])->with('success', 'Workflow berhasil dibuat.');
    }

    public function show(PpWorkflow $ppWorkflow): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $workflowStatus = $this->engine->getWorkflowStatus($history);
        $pp01 = $ppWorkflow->latestPp01();
        $pp06 = $ppWorkflow->latestPp06();
        $label = $pp01?->tahun ? "PP-{$pp01->tahun}" : 'PP-Baru';

        // Detect "Dalam Revisi"
        if ($workflowStatus === 'completed') {
            $hasPp07Draft = $ppWorkflow->pp07Data()->whereNull('submitted_at')->exists();
            if ($hasPp07Draft) {
                $workflowStatus = 'revising';
            }
        }

        // Creator info from first history entry
        $creatorEntry = $history[0] ?? null;
        $creatorName = 'System';
        $creatorRole = null;
        $creatorDate = $ppWorkflow->created_at->format('d/m/Y');
        if ($creatorEntry && isset($creatorEntry['by'])) {
            $creatorName = \App\Models\User::find($creatorEntry['by'])?->name ?? 'Unknown';
            if (isset($creatorEntry['role'])) {
                $creatorRole = \App\Models\Role::find($creatorEntry['role'])?->name;
            }
        }

        // Step aktif label
        $stepAktifLabel = null;
        if ($workflowStatus === 'active' && ! empty($currentSteps)) {
            $stepNames = [
                'PP01' => 'Rencana Periode',
                'PP02' => 'Pertanyaan Kuisioner',
                'PP03' => 'Plafon Anggaran',
                'PP04' => 'Dokumen SOP',
                'PP05' => 'Persetujuan',
                'PP06' => 'Periode Tahunan',
                'PP07' => 'Revisi',
            ];
            $step = $currentSteps[0];
            $stepAktifLabel = $step.': '.($stepNames[$step] ?? $step);
        }

        // Stepper cycles with URL resolver
        $wfId = $ppWorkflow->id;
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($wfId): ?string {
            if ($dataId === null && ! in_array($code, ['PP05', 'PP06'])) {
                return null;
            }

            $base = "/admin/workflows/pp/{$wfId}";

            if ($code === 'PP05') {
                return "{$base}/pp05";
            }

            if ($code === 'PP06') {
                return "{$base}/pp06?revision=0";
            }

            if ($dataId) {
                return "{$base}/".strtolower($code)."/{$dataId}";
            }

            return null;
        });

        // Inject step roles into stepper for tooltips
        $stepRoleMap = $this->resolveStepRoles();

        foreach ($stepperCycles as &$cycle) {
            foreach ($cycle['steps'] as &$step) {
                $step['roles'] = $stepRoleMap[$step['code']] ?? [];
            }
        }
        unset($cycle, $step);

        // Data Terbaru from PP06
        $dataTerbaru = null;
        if ($pp06) {
            $plafon = $pp06->itemPlafonAnggaran()->with('team:id,name')->get();
            $dataTerbaru = [
                'tahun' => $pp01?->tahun,
                'pra_raker' => $pp06->tanggal_mulai_pra_raker?->format('d/m/Y'),
                'penetapan' => $pp06->tanggal_penetapan_program?->format('d/m/Y'),
                'revision' => $pp06->revision,
                'pp06_id' => $pp06->id,
                'plafon' => $plafon->map(fn ($p) => [
                    'kode' => $p->kode_team,
                    'tim' => $p->team?->name ?? 'Unknown',
                    'plafon_anggaran' => $p->plafon_anggaran,
                ])->values(),
                'total_anggaran' => $plafon->sum('plafon_anggaran'),
                'kuisioner_count' => $pp06->itemKuisioner()->count(),
                'kode_count' => $pp06->kodeBidangPelayanan()->count()
                    + $pp06->kodeSubBidangPelayanan()->count()
                    + $pp06->kodeKategoriPelayanan()->count()
                    + $pp06->kodeJenisProgram()->count(),
                'dokumen_count' => $pp06->itemDokumenSop()->count(),
            ];
        }

        $permissions = $this->session->getActivePermissions();

        return Inertia::render('admin/workflows/pp/show', [
            'workflow' => [
                'id' => $ppWorkflow->id,
                'label' => $label,
                'status' => $workflowStatus,
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'informasi' => [
                'tahun' => $pp01?->tahun,
                'dibuat_oleh' => $creatorName,
                'dibuat_oleh_role' => $creatorRole,
                'dibuat_tanggal' => $creatorDate,
                'status' => $workflowStatus,
                'step_aktif' => $stepAktifLabel,
            ],
            'dataTerbaru' => $dataTerbaru,
            'canTerminate' => $workflowStatus === 'active' && in_array('admin.workflows.pp.terminate', $permissions),
            'canRevise' => $workflowStatus === 'completed' && in_array('admin.workflows.pp.pp07.draft', $permissions),
            'canDelete' => $workflowStatus === 'terminated' && in_array('admin.workflows.pp.destroy', $permissions),
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
            'canExportZip' => $pp06 !== null && in_array('admin.workflows.pp.pp06.export.zip', $permissions),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pp01Show(PpWorkflow $ppWorkflow, Pp01Data $pp01Data): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp01.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        $mode = $this->resolveMode($statuses, 'PP01');
        if (($statuses['PP01']['dataId'] ?? null) !== null && $statuses['PP01']['dataId'] !== $pp01Data->id) {
            $mode = 'readonly';
        }
        $permissions = $this->session->getActivePermissions();
        $isEditable = $mode === 'edit' || $mode === 'create';
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';

        return Inertia::render('admin/workflows/pp/pp01', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp01Data->id,
                'tahun' => $pp01Data->tahun,
                'tanggal_mulai_pra_raker' => $pp01Data->tanggal_mulai_pra_raker?->format('Y-m-d'),
                'tanggal_penetapan_program' => $pp01Data->tanggal_penetapan_program?->format('Y-m-d'),
                'kode_bidang_pelayanan' => $pp01Data->kodeBidangPelayanan,
                'kode_sub_bidang_pelayanan' => $pp01Data->kodeSubBidangPelayanan,
                'kode_kategori_pelayanan' => $pp01Data->kodeKategoriPelayanan,
                'kode_jenis_program' => $pp01Data->kodeJenisProgram,
                'updated_at' => $pp01Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array('admin.workflows.pp.pp01.draft', $permissions),
            'canSubmit' => $isEditable && in_array('admin.workflows.pp.pp01.submit', $permissions),
            'canTerminate' => $isWorkflowActive && in_array('admin.workflows.pp.terminate', $permissions),
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
            'isRejectionReentry' => $isRejection = $statuses['PP01']['cycle'] > 1 && $statuses['PP01']['status'] === 'active',
            'rejectionNotes' => $isRejection ? $this->getLatestRejectionNotes($history) : null,
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.pp.pp01.draft' => ['Simpan Draft', false],
                'admin.workflows.pp.pp01.submit' => ['Submit', true],
                'admin.workflows.pp.comment' => ['Komentar', false],
                'admin.workflows.pp.terminate' => ['Batalkan Workflow', true],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pp01Draft(Pp01DraftRequest $request, PpWorkflow $ppWorkflow, Pp01Data $pp01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp01.draft');
        $this->ensureStepActive($ppWorkflow, 'PP01');
        $this->ensureCurrentRecord($ppWorkflow, 'PP01', $pp01Data->id);

        $validated = $request->validated();

        $this->checkOptimisticLock($pp01Data, $validated['expected_updated_at']);

        $pp01Data->update([
            'tahun' => $validated['tahun'] ?? null,
            'tanggal_mulai_pra_raker' => $validated['tanggal_mulai_pra_raker'] ?? null,
            'tanggal_penetapan_program' => $validated['tanggal_penetapan_program'] ?? null,
        ]);

        $this->syncKodeItems($pp01Data, $validated);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp01Data,
            'pp.pp01.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP01',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp01_data',
            dataId: $pp01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Draft PP01 berhasil disimpan.');
    }

    public function pp01Submit(Pp01SubmitRequest $request, PpWorkflow $ppWorkflow, Pp01Data $pp01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp01.submit');
        $validated = $request->validated();

        // Check engine state
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $currentSteps = $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []);

        if (! in_array('PP01', $currentSteps)) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->ensureCurrentRecord($ppWorkflow, 'PP01', $pp01Data->id);
        $this->checkOptimisticLock($pp01Data, $validated['expected_updated_at']);

        // Check unique PP per tahun per workspace
        $existingPp = PpWorkflow::query()
            ->where('workspace_id', $ppWorkflow->workspace_id)
            ->where('id', '!=', $ppWorkflow->id)
            ->get()
            ->filter(function (PpWorkflow $wf) use ($validated) {
                $status = $this->engine->getWorkflowStatus($wf->history ?? []);

                if (in_array($status, ['terminated', 'deleted'])) {
                    return false;
                }

                $pp01 = $wf->latestPp01();

                return $pp01 && $pp01->tahun === (int) $validated['tahun'];
            });

        if ($existingPp->isNotEmpty()) {
            return back()->withErrors(['tahun' => "PP untuk tahun {$validated['tahun']} sudah ada di workspace ini."]);
        }

        $pp01Data->update([
            'tahun' => $validated['tahun'],
            'tanggal_mulai_pra_raker' => $validated['tanggal_mulai_pra_raker'],
            'tanggal_penetapan_program' => $validated['tanggal_penetapan_program'],
        ]);

        $this->syncKodeItems($pp01Data, $validated);

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp01Data,
            'pp.pp01.submit',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP01',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp01_data',
            dataId: $pp01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
        );

        // Auto-create PP02 (prefill from previous cycle if rejection re-entry)
        $pp02 = Pp02Data::create(['pp_workflow_id' => $ppWorkflow->id]);

        $previousPp02 = $ppWorkflow->pp02Data()
            ->where('id', '!=', $pp02->id)
            ->latest('id')
            ->first();

        if ($previousPp02) {
            foreach ($previousPp02->itemKuisioner as $item) {
                $pp02->itemKuisioner()->create($item->only(['kode', 'pertanyaan', 'tipe', 'satuan']));
            }
        }

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP02',
            action: 'created',
            userId: null,
            sessionContext: [],
            table: 'pp02_data',
            dataId: $pp02->id,
        );

        $this->notifier->notify($ppWorkflow, 'pp01.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.pp02.show', [$ppWorkflow, $pp02]),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'PP01 berhasil disubmit.');
    }

    public function pp02Show(PpWorkflow $ppWorkflow, Pp02Data $pp02Data): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp02.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        $mode = $this->resolveMode($statuses, 'PP02');
        if (($statuses['PP02']['dataId'] ?? null) !== null && $statuses['PP02']['dataId'] !== $pp02Data->id) {
            $mode = 'readonly';
        }
        $permissions = $this->session->getActivePermissions();
        $isEditable = $mode === 'edit' || $mode === 'create';
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';

        return Inertia::render('admin/workflows/pp/pp02', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp02Data->id,
                'item_kuisioner' => $pp02Data->itemKuisioner,
                'updated_at' => $pp02Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array('admin.workflows.pp.pp02.draft', $permissions),
            'canSubmit' => $isEditable && in_array('admin.workflows.pp.pp02.submit', $permissions),
            'canTerminate' => $isWorkflowActive && in_array('admin.workflows.pp.terminate', $permissions),
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
            'isRejectionReentry' => $isRejection = $statuses['PP02']['cycle'] > 1 && $statuses['PP02']['status'] === 'active',
            'rejectionNotes' => $isRejection ? $this->getLatestRejectionNotes($history) : null,
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.pp.pp02.draft' => ['Simpan Draft', false],
                'admin.workflows.pp.pp02.submit' => ['Submit', true],
                'admin.workflows.pp.comment' => ['Komentar', false],
                'admin.workflows.pp.terminate' => ['Batalkan Workflow', true],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pp02Draft(Pp02DraftRequest $request, PpWorkflow $ppWorkflow, Pp02Data $pp02Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp02.draft');
        $this->ensureStepActive($ppWorkflow, 'PP02');
        $this->ensureCurrentRecord($ppWorkflow, 'PP02', $pp02Data->id);

        $validated = $request->validated();

        $this->checkOptimisticLock($pp02Data, $validated['expected_updated_at']);

        $pp02Data->itemKuisioner()->delete();

        foreach ($validated['item_kuisioner'] ?? [] as $item) {
            if (empty($item['kode']) || empty($item['pertanyaan']) || empty($item['tipe'])) {
                continue;
            }

            $pp02Data->itemKuisioner()->create($item);
        }

        $pp02Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp02Data,
            'pp.pp02.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP02',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp02_data',
            dataId: $pp02Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Draft PP02 berhasil disimpan.');
    }

    public function pp02Submit(Pp02SubmitRequest $request, PpWorkflow $ppWorkflow, Pp02Data $pp02Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp02.submit');
        $validated = $request->validated();

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP02', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->ensureCurrentRecord($ppWorkflow, 'PP02', $pp02Data->id);
        $this->checkOptimisticLock($pp02Data, $validated['expected_updated_at']);

        $pp02Data->itemKuisioner()->delete();

        foreach ($validated['item_kuisioner'] as $item) {
            $pp02Data->itemKuisioner()->create($item);
        }

        $pp02Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp02Data,
            'pp.pp02.submit',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP02',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp02_data',
            dataId: $pp02Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
        );

        // Auto-create PP03 (prefill from previous cycle if rejection re-entry)
        $pp03 = Pp03Data::create(['pp_workflow_id' => $ppWorkflow->id]);

        $previousPp03 = $ppWorkflow->pp03Data()
            ->where('id', '!=', $pp03->id)
            ->latest('id')
            ->first();

        if ($previousPp03) {
            foreach ($previousPp03->itemPlafonAnggaran as $item) {
                $pp03->itemPlafonAnggaran()->create($item->only([
                    'team_id', 'kode_team', 'plafon_anggaran',
                    'nama_bank', 'nama_rekening', 'nomor_rekening', 'catatan',
                ]));
            }
        }

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP03',
            action: 'created',
            userId: null,
            sessionContext: [],
            table: 'pp03_data',
            dataId: $pp03->id,
        );

        $this->notifier->notify($ppWorkflow, 'pp02.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.pp03.show', [$ppWorkflow, $pp03]),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'PP02 berhasil disubmit.');
    }

    public function pp03Show(PpWorkflow $ppWorkflow, Pp03Data $pp03Data): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp03.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        $mode = $this->resolveMode($statuses, 'PP03');
        if (($statuses['PP03']['dataId'] ?? null) !== null && $statuses['PP03']['dataId'] !== $pp03Data->id) {
            $mode = 'readonly';
        }
        $permissions = $this->session->getActivePermissions();
        $isEditable = $mode === 'edit' || $mode === 'create';
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';

        $pp03Data->load('itemPlafonAnggaran.team');

        return Inertia::render('admin/workflows/pp/pp03', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp03Data->id,
                'item_plafon_anggaran' => $pp03Data->itemPlafonAnggaran,
                'updated_at' => $pp03Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array('admin.workflows.pp.pp03.draft', $permissions),
            'canSubmit' => $isEditable && in_array('admin.workflows.pp.pp03.submit', $permissions),
            'canTerminate' => $isWorkflowActive && in_array('admin.workflows.pp.terminate', $permissions),
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
            'isRejectionReentry' => $isRejection = $statuses['PP03']['cycle'] > 1 && $statuses['PP03']['status'] === 'active',
            'rejectionNotes' => $isRejection ? $this->getLatestRejectionNotes($history) : null,
            'teams' => $this->getWorkspaceTeams($ppWorkflow->workspace_id),
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.pp.pp03.draft' => ['Simpan Draft', false],
                'admin.workflows.pp.pp03.submit' => ['Submit', true],
                'admin.workflows.pp.comment' => ['Komentar', false],
                'admin.workflows.pp.terminate' => ['Batalkan Workflow', true],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pp03Draft(Pp03DraftRequest $request, PpWorkflow $ppWorkflow, Pp03Data $pp03Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp03.draft');
        $this->ensureStepActive($ppWorkflow, 'PP03');
        $this->ensureCurrentRecord($ppWorkflow, 'PP03', $pp03Data->id);

        $validated = $request->validated();

        $this->checkOptimisticLock($pp03Data, $validated['expected_updated_at']);

        $pp03Data->itemPlafonAnggaran()->delete();

        foreach ($validated['item_plafon_anggaran'] ?? [] as $item) {
            if (empty($item['kode_team'])) {
                continue;
            }

            $pp03Data->itemPlafonAnggaran()->create([
                ...$item,
                'team_id' => ! empty($item['team_id']) ? $item['team_id'] : null,
                'plafon_anggaran' => $item['plafon_anggaran'] ?? 0,
                'nama_bank' => $item['nama_bank'] ?? '',
                'nama_rekening' => $item['nama_rekening'] ?? '',
                'nomor_rekening' => $item['nomor_rekening'] ?? '',
            ]);
        }

        $pp03Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp03Data,
            'pp.pp03.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP03',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp03_data',
            dataId: $pp03Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Draft PP03 berhasil disimpan.');
    }

    public function pp03Submit(Pp03SubmitRequest $request, PpWorkflow $ppWorkflow, Pp03Data $pp03Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp03.submit');
        $validated = $request->validated();

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP03', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->ensureCurrentRecord($ppWorkflow, 'PP03', $pp03Data->id);
        $this->checkOptimisticLock($pp03Data, $validated['expected_updated_at']);

        $pp03Data->itemPlafonAnggaran()->delete();

        foreach ($validated['item_plafon_anggaran'] as $item) {
            $pp03Data->itemPlafonAnggaran()->create($item);
        }

        $pp03Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp03Data,
            'pp.pp03.submit',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP03',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp03_data',
            dataId: $pp03Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
        );

        // Auto-create PP04 (prefill from previous cycle if rejection re-entry)
        $pp04 = Pp04Data::create(['pp_workflow_id' => $ppWorkflow->id]);

        $previousPp04 = $ppWorkflow->pp04Data()
            ->where('id', '!=', $pp04->id)
            ->latest('id')
            ->first();

        if ($previousPp04) {
            foreach ($previousPp04->itemDokumen as $item) {
                $pp04->itemDokumen()->create(['file_id' => $item->file_id]);
            }
        }

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP04',
            action: 'created',
            userId: null,
            sessionContext: [],
            table: 'pp04_data',
            dataId: $pp04->id,
        );

        $this->notifier->notify($ppWorkflow, 'pp03.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.pp04.show', [$ppWorkflow, $pp04]),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'PP03 berhasil disubmit.');
    }

    public function pp04Show(PpWorkflow $ppWorkflow, Pp04Data $pp04Data): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp04.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        $mode = $this->resolveMode($statuses, 'PP04');
        if (($statuses['PP04']['dataId'] ?? null) !== null && $statuses['PP04']['dataId'] !== $pp04Data->id) {
            $mode = 'readonly';
        }
        $permissions = $this->session->getActivePermissions();
        $isEditable = $mode === 'edit' || $mode === 'create';
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';

        $pp04Data->load('itemDokumen.file');

        return Inertia::render('admin/workflows/pp/pp04', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp04Data->id,
                'item_dokumen' => $pp04Data->itemDokumen,
                'updated_at' => $pp04Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array('admin.workflows.pp.pp04.draft', $permissions),
            'canSubmit' => $isEditable && in_array('admin.workflows.pp.pp04.submit', $permissions),
            'canTerminate' => $isWorkflowActive && in_array('admin.workflows.pp.terminate', $permissions),
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
            'isRejectionReentry' => $isRejection = $statuses['PP04']['cycle'] > 1 && $statuses['PP04']['status'] === 'active',
            'rejectionNotes' => $isRejection ? $this->getLatestRejectionNotes($history) : null,
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.pp.pp04.draft' => ['Simpan Draft', false],
                'admin.workflows.pp.pp04.submit' => ['Submit', true],
                'admin.workflows.pp.comment' => ['Komentar', false],
                'admin.workflows.pp.terminate' => ['Batalkan Workflow', true],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pp04Draft(Pp04DraftRequest $request, PpWorkflow $ppWorkflow, Pp04Data $pp04Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp04.draft');
        $this->ensureStepActive($ppWorkflow, 'PP04');
        $this->ensureCurrentRecord($ppWorkflow, 'PP04', $pp04Data->id);

        $validated = $request->validated();

        $this->checkOptimisticLock($pp04Data, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        // Upload new dokumen files
        $newFileIds = $this->commentService->storeFiles(
            $request->file('dokumen_files', []),
            $pp04Data,
            'pp.pp04.dokumen',
            $request->user()->id,
            $sessionContext,
        );

        // Keep existing + new uploaded file IDs
        $keepFileIds = $validated['keep_file_ids'] ?? [];
        $allFileIds = array_merge($keepFileIds, $newFileIds);

        $pp04Data->itemDokumen()->delete();

        foreach ($allFileIds as $fileId) {
            $pp04Data->itemDokumen()->create(['file_id' => $fileId]);
        }

        $pp04Data->touch();

        // Comment attachment files (separate from dokumen files)
        $commentFileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp04Data,
            'pp.pp04.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP04',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp04_data',
            dataId: $pp04Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($commentFileIds) ? $commentFileIds : null,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Draft PP04 berhasil disimpan.');
    }

    public function pp04Submit(Pp04SubmitRequest $request, PpWorkflow $ppWorkflow, Pp04Data $pp04Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp04.submit');
        $validated = $request->validated();

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP04', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->ensureCurrentRecord($ppWorkflow, 'PP04', $pp04Data->id);
        $this->checkOptimisticLock($pp04Data, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        // Upload new dokumen files
        $newFileIds = $this->commentService->storeFiles(
            $request->file('dokumen_files', []),
            $pp04Data,
            'pp.pp04.dokumen',
            $request->user()->id,
            $sessionContext,
        );

        // Keep existing + new uploaded file IDs
        $keepFileIds = $validated['keep_file_ids'] ?? [];
        $allFileIds = array_merge($keepFileIds, $newFileIds);

        $pp04Data->itemDokumen()->delete();

        foreach ($allFileIds as $fileId) {
            $pp04Data->itemDokumen()->create(['file_id' => $fileId]);
        }

        // Set is_workspace_public = true on all attached files
        if (! empty($allFileIds)) {
            File::whereIn('id', $allFileIds)->update(['is_workspace_public' => true]);
        }

        $pp04Data->touch();

        // Comment attachment files (separate from dokumen files)
        $commentFileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp04Data,
            'pp.pp04.submit',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP04',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp04_data',
            dataId: $pp04Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($commentFileIds) ? $commentFileIds : null,
        );

        // Record PP05 activation in history (stateless approval step)
        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP05',
            action: 'created',
            userId: null,
            sessionContext: [],
        );

        $this->notifier->notify($ppWorkflow, 'pp04.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.pp05.show', $ppWorkflow),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'PP04 berhasil disubmit.');
    }

    public function pp05Show(PpWorkflow $ppWorkflow): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp05.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $permissions = $this->session->getActivePermissions();

        // Load all step data for review
        /** @var Pp01Data|null $pp01 */
        $pp01 = $ppWorkflow->pp01Data()->latest('id')->first();
        /** @var Pp02Data|null $pp02 */
        $pp02 = $ppWorkflow->pp02Data()->latest('id')->first();
        /** @var Pp03Data|null $pp03 */
        $pp03 = $ppWorkflow->pp03Data()->latest('id')->first();
        /** @var Pp04Data|null $pp04 */
        $pp04 = $ppWorkflow->pp04Data()->latest('id')->first();

        $pp01?->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram']);
        $pp02?->load('itemKuisioner');
        $pp03?->load('itemPlafonAnggaran.team');
        $pp04?->load('itemDokumen.file');

        $isActive = $statuses['PP05']['status'] === 'active';
        $isWorkflowActive = $this->engine->getWorkflowStatus($history) === 'active';

        $submitters = $this->resolveStepSubmitters($history, ['PP01', 'PP02', 'PP03', 'PP04']);

        return Inertia::render('admin/workflows/pp/pp05', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'reviewData' => [
                'pp01' => $pp01,
                'pp02' => $pp02,
                'pp03' => $pp03,
                'pp04' => $pp04,
            ],
            'submitters' => $submitters,
            'stepStatus' => $statuses['PP05']['status'],
            'canApprove' => $isActive && in_array('admin.workflows.pp.pp05.approve', $permissions),
            'canReject' => $isActive && in_array('admin.workflows.pp.pp05.reject', $permissions),
            'canTerminate' => $isWorkflowActive && in_array('admin.workflows.pp.terminate', $permissions),
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.pp.pp05.approve' => ['Setujui', true],
                'admin.workflows.pp.pp05.reject' => ['Tolak', true],
                'admin.workflows.pp.comment' => ['Komentar', false],
                'admin.workflows.pp.terminate' => ['Batalkan Workflow', true],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pp05Approve(Pp05ApproveRequest $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp05.approve');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP05', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['approve' => 'Step ini sudah tidak aktif.']);
        }

        $validated = $request->validated();

        $pp01 = $ppWorkflow->pp01Data()->latest('id')->first();
        $pp02 = $ppWorkflow->pp02Data()->latest('id')->first();
        $pp03 = $ppWorkflow->pp03Data()->latest('id')->first();
        $pp04 = $ppWorkflow->pp04Data()->latest('id')->first();

        $reviewed = [
            'pp01_data_id' => $pp01?->id,
            'pp02_data_id' => $pp02?->id,
            'pp03_data_id' => $pp03?->id,
            'pp04_data_id' => $pp04?->id,
        ];

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $ppWorkflow,
            'pp.pp05.approve',
            $request->user()->id,
            $sessionContext,
        );

        $compileService = app(PpCompileService::class);

        $pp06 = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $ppWorkflow, $reviewed, $validated, $sessionContext, $fileIds, $compileService) {
            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP05',
                action: 'approved',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                notes: $validated['notes'] ?? null,
                files: ! empty($fileIds) ? $fileIds : null,
                extra: ['reviewed' => $reviewed],
            );

            // Compile PP06
            $pp06 = $compileService->compile($ppWorkflow);

            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP06',
                action: 'completed',
                userId: null,
                sessionContext: [],
                table: 'pp06_periode_tahunan',
                dataId: $pp06->id,
                extra: ['revision' => 0],
            );

            return $pp06;
        });

        // Generate export files (outside transaction — failures are non-blocking)
        $exportResult = $compileService->generateExportFiles($pp06, $request->user()->id, $ppWorkflow->workspace_id);
        $this->appendExportFilesToHistory($ppWorkflow, $exportResult, 0);

        $this->notifier->notify($ppWorkflow, 'pp05.approved', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.pp06.show', $ppWorkflow),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Workflow berhasil disetujui.');
    }

    public function pp05Reject(Pp05RejectRequest $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp05.reject');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP05', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['reject' => 'Step ini sudah tidak aktif.']);
        }

        $validated = $request->validated();

        $pp01 = $ppWorkflow->pp01Data()->latest('id')->first();
        $pp02 = $ppWorkflow->pp02Data()->latest('id')->first();
        $pp03 = $ppWorkflow->pp03Data()->latest('id')->first();
        $pp04 = $ppWorkflow->pp04Data()->latest('id')->first();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $ppWorkflow,
            'pp.pp05.reject',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP05',
            action: 'rejected',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            notes: $validated['notes'],
            files: ! empty($fileIds) ? $fileIds : null,
            extra: [
                'reviewed' => [
                    'pp01_data_id' => $pp01?->id,
                    'pp02_data_id' => $pp02?->id,
                    'pp03_data_id' => $pp03?->id,
                    'pp04_data_id' => $pp04?->id,
                ],
            ],
        );

        // Auto-create new PP01 for re-entry (prefilled from latest)
        $latestPp01 = $pp01;

        $newPp01 = Pp01Data::create([
            'pp_workflow_id' => $ppWorkflow->id,
            'tahun' => $latestPp01?->tahun,
            'tanggal_mulai_pra_raker' => $latestPp01?->tanggal_mulai_pra_raker,
            'tanggal_penetapan_program' => $latestPp01?->tanggal_penetapan_program,
        ]);

        // Copy kode items from latest
        if ($latestPp01) {
            foreach ($latestPp01->kodeBidangPelayanan as $item) {
                $newPp01->kodeBidangPelayanan()->create($item->only(['kode', 'nama', 'catatan']));
            }

            foreach ($latestPp01->kodeSubBidangPelayanan as $item) {
                $newPp01->kodeSubBidangPelayanan()->create($item->only(['kode', 'nama', 'catatan']));
            }

            foreach ($latestPp01->kodeKategoriPelayanan as $item) {
                $newPp01->kodeKategoriPelayanan()->create($item->only(['kode', 'nama', 'catatan']));
            }

            foreach ($latestPp01->kodeJenisProgram as $item) {
                $newPp01->kodeJenisProgram()->create($item->only(['kode', 'nama', 'catatan']));
            }
        }

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP01',
            action: 'created',
            userId: null,
            sessionContext: [],
            table: 'pp01_data',
            dataId: $newPp01->id,
        );

        $this->notifier->notify($ppWorkflow, 'pp05.rejected', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.pp01.show', [$ppWorkflow, $newPp01]),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Workflow berhasil ditolak. Step dikembalikan untuk perbaikan.');
    }

    public function pp06Show(PpWorkflow $ppWorkflow): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp06.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        $revisionParam = request()->query('revision');
        if ($revisionParam !== null) {
            $pp06 = $ppWorkflow->pp06PeriodeTahunan()->where('revision', (int) $revisionParam)->first();
        }
        $pp06 ??= $ppWorkflow->latestPp06();
        $pp06?->load([
            'itemPlafonAnggaran.team',
            'kodeBidangPelayanan',
            'kodeSubBidangPelayanan',
            'kodeKategoriPelayanan',
            'kodeJenisProgram',
            'itemKuisioner',
            'itemDokumenSop.file',
        ]);

        $activeDraft = $ppWorkflow->pp07Data()->whereNull('submitted_at')->first();
        $workflowStatus = $this->engine->getWorkflowStatus($ppWorkflow->history ?? []);
        $permissions = $this->session->getActivePermissions();

        // Extract changelog diffs from history (PP07 submitted entries)
        $changelogByRevision = [];
        foreach ($ppWorkflow->history ?? [] as $entry) {
            if (($entry['step'] ?? '') === 'PP07'
                && ($entry['action'] ?? '') === 'submitted'
                && isset($entry['revision'], $entry['changes'])) {
                $changelogByRevision[$entry['revision']] = $entry['changes'];
            }
        }

        return Inertia::render('admin/workflows/pp/pp06', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'pp06' => $pp06,
            'allRevisions' => $ppWorkflow->pp06PeriodeTahunan()
                ->orderBy('revision')
                ->get(['id', 'revision', 'tahun', 'created_at']),
            'changelogByRevision' => $changelogByRevision,
            'canRevise' => $pp06 !== null && $workflowStatus === 'completed',
            'activeDraftId' => $activeDraft?->id,
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
        ]);
    }

    public function pp06ExportPdf(PpWorkflow $ppWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp06.export.pdf');

        $revision = request()->query('revision');
        $pp06 = $revision !== null
            ? $ppWorkflow->pp06PeriodeTahunan()->where('revision', (int) $revision)->first()
            : $ppWorkflow->latestPp06();

        if (! $pp06) {
            return back()->withErrors(['export' => 'PP06 belum dikompilasi.']);
        }

        // Find existing export file
        $file = \App\Models\File::where('attachable_type', \App\Models\Pp\Pp06PeriodeTahunan::class)
            ->where('attachable_id', $pp06->id)
            ->where('source_route', 'pp06.export.pdf')
            ->first();

        if ($file && $file->path && \Illuminate\Support\Facades\Storage::disk($file->disk)->exists($file->path)) {
            return response()->download(
                \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
                $file->original_filename,
                ['Content-Type' => $file->mime_type],
            );
        }

        // Regenerate on-demand if file doesn't exist
        $compileService = app(PpCompileService::class);
        $exportResult = $compileService->generateExportFiles($pp06, auth()->id(), $ppWorkflow->workspace_id);
        $this->appendExportFilesToHistory($ppWorkflow, $exportResult, $pp06->revision);

        if (! $exportResult['pdf_file_id']) {
            return back()->withErrors(['export' => 'Gagal membuat file PDF.']);
        }

        $file = \App\Models\File::find($exportResult['pdf_file_id']);

        return response()->download(
            \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
            $file->original_filename,
            ['Content-Type' => $file->mime_type],
        );
    }

    public function pp06ExportExcel(PpWorkflow $ppWorkflow): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp06.export.excel');

        $revision = request()->query('revision');
        $pp06 = $revision !== null
            ? $ppWorkflow->pp06PeriodeTahunan()->where('revision', (int) $revision)->first()
            : $ppWorkflow->latestPp06();

        if (! $pp06) {
            return back()->withErrors(['export' => 'PP06 belum dikompilasi.']);
        }

        // Find existing export file
        $file = \App\Models\File::where('attachable_type', \App\Models\Pp\Pp06PeriodeTahunan::class)
            ->where('attachable_id', $pp06->id)
            ->where('source_route', 'pp06.export.excel')
            ->first();

        if ($file && $file->path && \Illuminate\Support\Facades\Storage::disk($file->disk)->exists($file->path)) {
            return response()->download(
                \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
                $file->original_filename,
                ['Content-Type' => $file->mime_type],
            );
        }

        // Regenerate on-demand if file doesn't exist
        $compileService = app(PpCompileService::class);
        $exportResult = $compileService->generateExportFiles($pp06, auth()->id(), $ppWorkflow->workspace_id);
        $this->appendExportFilesToHistory($ppWorkflow, $exportResult, $pp06->revision);

        if (! $exportResult['excel_file_id']) {
            return back()->withErrors(['export' => 'Gagal membuat file Excel.']);
        }

        $file = \App\Models\File::find($exportResult['excel_file_id']);

        return response()->download(
            \Illuminate\Support\Facades\Storage::disk($file->disk)->path($file->path),
            $file->original_filename,
            ['Content-Type' => $file->mime_type],
        );
    }

    public function pp06ExportZip(PpWorkflow $ppWorkflow): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp06.export.zip');

        $revisions = $ppWorkflow->pp06PeriodeTahunan()->with('itemDokumenSop')->orderBy('revision')->get();

        if ($revisions->isEmpty()) {
            return back()->withErrors(['export' => 'PP06 belum dikompilasi.']);
        }

        // 1. Build per-revision data: export files + dokumen SOP from PP06 compiled data
        $revisionData = [];
        $allPp06DokumenFileIds = [];

        foreach ($revisions as $pp06) {
            $exportFiles = File::where('attachable_type', \App\Models\Pp\Pp06PeriodeTahunan::class)
                ->where('attachable_id', $pp06->id)
                ->whereNotNull('path')
                ->get();

            $dokumenFileIds = $pp06->itemDokumenSop->pluck('file_id')->all();
            $dokumenFiles = ! empty($dokumenFileIds)
                ? File::whereIn('id', $dokumenFileIds)->whereNotNull('path')->get()
                : collect();

            $allPp06DokumenFileIds = array_merge($allPp06DokumenFileIds, $dokumenFileIds);

            $revisionData[$pp06->revision] = [
                'exports' => $exportFiles,
                'dokumen' => $dokumenFiles,
            ];
        }

        // 2. Find rejected cycle PP04 files (not referenced by any PP06 revision)
        $allPp06DokumenFileIds = array_unique($allPp06DokumenFileIds);
        $pp04Records = $ppWorkflow->pp04Data()->orderBy('id')->get();
        $rejectedCycles = [];
        $rejectedCycleNum = 0;

        foreach ($pp04Records as $pp04) {
            $fileIds = $pp04->itemDokumen()->pluck('file_id')->all();
            if (empty($fileIds)) {
                continue;
            }

            // If none of this PP04's files are in any PP06, it's from a rejected cycle
            $inPp06 = ! empty(array_intersect($fileIds, $allPp06DokumenFileIds));
            if (! $inPp06) {
                $rejectedCycleNum++;
                $files = File::whereIn('id', $fileIds)->whereNotNull('path')->get();
                if ($files->isNotEmpty()) {
                    $rejectedCycles[$rejectedCycleNum] = $files;
                }
            }
        }

        // 3. Comment/history attachment files (global)
        $historyFileIds = collect($ppWorkflow->history ?? [])
            ->pluck('files')
            ->filter()
            ->flatten()
            ->unique()
            ->all();
        $commentFiles = ! empty($historyFileIds)
            ? File::whereIn('id', $historyFileIds)->whereNotNull('path')->get()
            : collect();

        $hasFiles = collect($revisionData)->contains(fn ($d) => $d['exports']->isNotEmpty() || $d['dokumen']->isNotEmpty())
            || ! empty($rejectedCycles)
            || $commentFiles->isNotEmpty();

        if (! $hasFiles) {
            return back()->withErrors(['export' => 'Tidak ada file yang tersedia untuk diunduh.']);
        }

        $zipFilename = "PP06-PeriodeTahunan-{$ppWorkflow->id}.zip";

        return response()->streamDownload(function () use ($revisionData, $rejectedCycles, $commentFiles) {
            $zip = new \ZipStream\ZipStream(
                outputStream: fopen('php://output', 'wb'),
                sendHttpHeaders: false,
            );

            $usedNames = [];

            $addFile = function (File $file, string $folder) use ($zip, &$usedNames) {
                $disk = \Illuminate\Support\Facades\Storage::disk($file->disk);
                if (! $disk->exists($file->path)) {
                    return;
                }

                $name = $folder.'/'.$file->original_filename;

                if (isset($usedNames[$name])) {
                    $usedNames[$name]++;
                    $ext = pathinfo($file->original_filename, PATHINFO_EXTENSION);
                    $base = pathinfo($file->original_filename, PATHINFO_FILENAME);
                    $name = $folder.'/'.$base.' ('.$usedNames[$name].').'.$ext;
                } else {
                    $usedNames[$name] = 1;
                }

                $zip->addFileFromPath($name, $disk->path($file->path));
            };

            // Revisi N/ folders
            foreach ($revisionData as $revision => $data) {
                $revFolder = "Revisi {$revision}";

                foreach ($data['exports'] as $file) {
                    $addFile($file, $revFolder);
                }

                foreach ($data['dokumen'] as $file) {
                    $addFile($file, "{$revFolder}/Dokumen SOP");
                }
            }

            // Siklus Ditolak/ folders (only if any exist)
            foreach ($rejectedCycles as $cycle => $files) {
                foreach ($files as $file) {
                    $addFile($file, "Siklus Ditolak/Siklus {$cycle}/Dokumen SOP");
                }
            }

            // Lampiran Komentar/
            foreach ($commentFiles as $file) {
                $addFile($file, 'Lampiran Komentar');
            }

            $zip->finish();
        }, $zipFilename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function pp07Create(Request $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp07.create');

        // Must have at least one PP06 revision
        $latestPp06 = $ppWorkflow->latestPp06();

        if (! $latestPp06) {
            return back()->withErrors(['pp07' => 'PP06 belum dikompilasi.']);
        }

        // Only one active PP07 draft at a time
        $activeDraft = $ppWorkflow->pp07Data()->whereNull('submitted_at')->first();

        if ($activeDraft) {
            return to_route('admin.workflows.pp.pp07.show', [
                'ppWorkflow' => $ppWorkflow->id,
                'pp07Data' => $activeDraft->id,
            ]);
        }

        // Prefill from latest PP06 revision
        $latestPp06->load([
            'kodeBidangPelayanan',
            'kodeSubBidangPelayanan',
            'kodeKategoriPelayanan',
            'kodeJenisProgram',
            'itemKuisioner',
            'itemPlafonAnggaran',
            'itemDokumenSop',
        ]);

        $draftData = [
            'tahun' => $latestPp06->tahun,
            'tanggal_mulai_pra_raker' => $latestPp06->tanggal_mulai_pra_raker?->format('Y-m-d'),
            'tanggal_penetapan_program' => $latestPp06->tanggal_penetapan_program?->format('Y-m-d'),
            'kode_bidang_pelayanan' => $latestPp06->kodeBidangPelayanan->map->only(['kode', 'nama', 'catatan'])->values()->toArray(),
            'kode_sub_bidang_pelayanan' => $latestPp06->kodeSubBidangPelayanan->map->only(['kode', 'nama', 'catatan'])->values()->toArray(),
            'kode_kategori_pelayanan' => $latestPp06->kodeKategoriPelayanan->map->only(['kode', 'nama', 'catatan'])->values()->toArray(),
            'kode_jenis_program' => $latestPp06->kodeJenisProgram->map->only(['kode', 'nama', 'catatan'])->values()->toArray(),
            'item_kuisioner' => $latestPp06->itemKuisioner->map->only(['kode', 'pertanyaan', 'tipe', 'satuan'])->values()->toArray(),
            'item_plafon_anggaran' => $latestPp06->itemPlafonAnggaran->map->only(['team_id', 'kode_team', 'plafon_anggaran', 'nama_bank', 'nama_rekening', 'nomor_rekening', 'catatan'])->values()->toArray(),
            'item_dokumen_sop' => $latestPp06->itemDokumenSop->map->only(['file_id'])->values()->toArray(),
        ];

        $pp07 = Pp07Data::create([
            'pp_workflow_id' => $ppWorkflow->id,
            'draft_data' => $draftData,
        ]);

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP07',
            action: 'created',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp07_data',
            dataId: $pp07->id,
        );

        return to_route('admin.workflows.pp.pp07.show', [
            'ppWorkflow' => $ppWorkflow->id,
            'pp07Data' => $pp07->id,
        ]);
    }

    public function pp07Show(PpWorkflow $ppWorkflow, Pp07Data $pp07Data): Response
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp07.show');
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $isSubmitted = $pp07Data->submitted_at !== null;
        $mode = $isSubmitted ? 'readonly' : 'edit';
        $permissions = $this->session->getActivePermissions();

        // Resolve file metadata for dokumen_sop items
        $draftData = $pp07Data->draft_data ?? [];
        $dokumenFileIds = collect($draftData['item_dokumen_sop'] ?? [])->pluck('file_id')->filter()->all();
        $fileMap = File::whereIn('id', $dokumenFileIds)->get()->keyBy('id');
        $dokumenFiles = collect($dokumenFileIds)->map(fn (int $id) => $fileMap->get($id))->filter()->map(fn (File $f) => [
            'file_id' => $f->id,
            'uuid' => $f->uuid,
            'original_filename' => $f->original_filename,
            'size' => $f->size,
        ])->values()->all();

        // Existing kode values from latest PP06 — immutable in PP07
        $latestPp06 = $ppWorkflow->latestPp06();
        $existingKodes = [];
        if ($latestPp06) {
            $latestPp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran']);
            $existingKodes = [
                'kode_bidang_pelayanan' => $latestPp06->kodeBidangPelayanan->pluck('kode')->values()->all(),
                'kode_sub_bidang_pelayanan' => $latestPp06->kodeSubBidangPelayanan->pluck('kode')->values()->all(),
                'kode_kategori_pelayanan' => $latestPp06->kodeKategoriPelayanan->pluck('kode')->values()->all(),
                'kode_jenis_program' => $latestPp06->kodeJenisProgram->pluck('kode')->values()->all(),
                'item_kuisioner' => $latestPp06->itemKuisioner->pluck('kode')->values()->all(),
                'item_plafon_anggaran' => $latestPp06->itemPlafonAnggaran->pluck('kode_team')->values()->all(),
            ];
        }

        return Inertia::render('admin/workflows/pp/pp07', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp07Data->id,
                'draft_data' => $draftData,
                'submitted_at' => $pp07Data->submitted_at?->toIso8601String(),
                'updated_at' => $pp07Data->updated_at->toIso8601String(),
            ],
            'dokumenFiles' => $dokumenFiles,
            'existingKodes' => $existingKodes,
            'mode' => $mode,
            'canDraft' => ! $isSubmitted && in_array('admin.workflows.pp.pp07.draft', $permissions),
            'canSubmit' => ! $isSubmitted && in_array('admin.workflows.pp.pp07.submit', $permissions),
            'canComment' => in_array('admin.workflows.pp.comment', $permissions),
            'teams' => $this->getWorkspaceTeams($ppWorkflow->workspace_id),
            'actionRoles' => $this->resolveActionRoles([
                'admin.workflows.pp.pp07.draft' => ['Simpan Draft', false],
                'admin.workflows.pp.pp07.submit' => ['Submit Revisi', true],
                'admin.workflows.pp.comment' => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
        ]);
    }

    public function pp07Draft(Pp07DraftRequest $request, PpWorkflow $ppWorkflow, Pp07Data $pp07Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp07.draft');
        if ($pp07Data->submitted_at !== null) {
            abort(409, 'Revisi ini sudah disubmit.');
        }

        $validated = $request->validated();

        $this->checkOptimisticLock($pp07Data, $validated['expected_updated_at']);

        $sessionContext = $this->getSessionContext();

        // Upload new dokumen files
        $newFileIds = $this->commentService->storeFiles(
            $request->file('dokumen_files', []),
            $ppWorkflow,
            'pp.pp07.dokumen',
            $request->user()->id,
            $sessionContext,
        );

        // Merge kept + new file IDs into draft_data
        $keepFileIds = $validated['keep_file_ids'] ?? [];
        $allFileIds = array_merge($keepFileIds, $newFileIds);
        $draftData = $validated['draft_data'];
        $draftData['item_dokumen_sop'] = array_map(fn (int $id) => ['file_id' => $id], $allFileIds);

        // Set is_workspace_public = true on all attached files
        if (! empty($allFileIds)) {
            File::whereIn('id', $allFileIds)->update(['is_workspace_public' => true]);
        }

        $pp07Data->update([
            'draft_data' => $draftData,
        ]);

        // Comment attachment files (separate from dokumen files)
        $commentFileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp07Data,
            'pp.pp07.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP07',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'pp07_data',
            dataId: $pp07Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($commentFileIds) ? $commentFileIds : null,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Draft PP07 berhasil disimpan.');
    }

    public function pp07Submit(Pp07SubmitRequest $request, PpWorkflow $ppWorkflow, Pp07Data $pp07Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.pp07.submit');
        if ($pp07Data->submitted_at !== null) {
            abort(409, 'Revisi ini sudah disubmit.');
        }

        $validated = $request->validated();

        $this->checkOptimisticLock($pp07Data, $validated['expected_updated_at']);

        $draftData = $validated['draft_data'];

        // Enforce PP06 immutability rules — existing kode values cannot be changed or deleted
        $latestPp06 = $ppWorkflow->latestPp06();
        if ($latestPp06) {
            // Tahun is immutable
            if ((int) $draftData['tahun'] !== $latestPp06->tahun) {
                return back()->withErrors(['draft_data.tahun' => 'Tahun tidak boleh diubah dalam revisi.']);
            }

            $latestPp06->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran']);

            // Plafon team_id is immutable — existing teams cannot be reassigned
            $existingPlafonMap = $latestPp06->itemPlafonAnggaran->pluck('team_id', 'kode_team')->all();
            foreach ($draftData['item_plafon_anggaran'] ?? [] as $item) {
                $kodeTeam = $item['kode_team'] ?? '';
                if (isset($existingPlafonMap[$kodeTeam]) && (int) ($item['team_id'] ?? 0) !== $existingPlafonMap[$kodeTeam]) {
                    return back()->withErrors(['draft_data.item_plafon_anggaran' => "Tim untuk plafon {$kodeTeam} tidak boleh diubah."]);
                }
            }

            $immutableChecks = [
                ['kode_bidang_pelayanan', $latestPp06->kodeBidangPelayanan->pluck('kode')->all(), 'kode', 'Kode Bidang Pelayanan'],
                ['kode_sub_bidang_pelayanan', $latestPp06->kodeSubBidangPelayanan->pluck('kode')->all(), 'kode', 'Kode Sub Bidang Pelayanan'],
                ['kode_kategori_pelayanan', $latestPp06->kodeKategoriPelayanan->pluck('kode')->all(), 'kode', 'Kode Kategori Pelayanan'],
                ['kode_jenis_program', $latestPp06->kodeJenisProgram->pluck('kode')->all(), 'kode', 'Kode Jenis Program'],
                ['item_kuisioner', $latestPp06->itemKuisioner->pluck('kode')->all(), 'kode', 'Kuisioner'],
                ['item_plafon_anggaran', $latestPp06->itemPlafonAnggaran->pluck('kode_team')->all(), 'kode_team', 'Plafon Anggaran'],
            ];

            $errors = [];
            foreach ($immutableChecks as [$field, $existingKodes, $kodeKey, $label]) {
                $submittedKodes = collect($draftData[$field] ?? [])->pluck($kodeKey)->all();
                $missing = array_diff($existingKodes, $submittedKodes);
                if (! empty($missing)) {
                    $errors["draft_data.{$field}"] = "{$label} yang sudah ada tidak boleh dihapus: ".implode(', ', $missing);
                }
            }

            if (! empty($errors)) {
                return back()->withErrors($errors);
            }
        }

        // Unique tahun check (PP07 may change tahun)
        $existingPp = PpWorkflow::query()
            ->where('workspace_id', $ppWorkflow->workspace_id)
            ->where('id', '!=', $ppWorkflow->id)
            ->get()
            ->filter(function (PpWorkflow $wf) use ($draftData) {
                $status = $this->engine->getWorkflowStatus($wf->history ?? []);

                if (in_array($status, ['terminated', 'deleted'])) {
                    return false;
                }

                $pp01 = $wf->latestPp01();

                return $pp01 && $pp01->tahun === (int) $draftData['tahun'];
            });

        if ($existingPp->isNotEmpty()) {
            return back()->withErrors(['tahun' => "PP untuk tahun {$draftData['tahun']} sudah ada di workspace ini."]);
        }

        // Compute new revision number
        $latestRevision = $ppWorkflow->pp06PeriodeTahunan()->max('revision') ?? 0;
        $newRevision = $latestRevision + 1;

        // Build author overrides per design
        $previousPp06 = $ppWorkflow->latestPp06();
        $now = now()->toIso8601String();
        $user = $request->user();
        $roleName = $this->resolveSessionRoleName();

        $authorOverrides = [];
        $workspace = \App\Models\Workspace::find($this->session->getActiveWorkspaceId());
        $orgName = $workspace?->organizations()->first()?->name ?? 'System';

        foreach (['pp01', 'pp02', 'pp03', 'pp04'] as $prefix) {
            $authorOverrides["{$prefix}_created_by_user_name"] = $user->name;
            $authorOverrides["{$prefix}_created_by_role_name"] = $roleName;
            $authorOverrides["{$prefix}_created_by_team_name"] = null;
            $authorOverrides["{$prefix}_created_by_organization_name"] = $orgName;
            $authorOverrides["{$prefix}_created_by_workspace_name"] = $workspace?->name ?? 'System';
            $authorOverrides["{$prefix}_created_at"] = $now;
        }

        // PP05 author from previous revision
        if ($previousPp06) {
            $authorOverrides['pp05_created_by_user_name'] = $previousPp06->pp05_created_by_user_name;
            $authorOverrides['pp05_created_by_role_name'] = $previousPp06->pp05_created_by_role_name;
            $authorOverrides['pp05_created_by_team_name'] = $previousPp06->pp05_created_by_team_name;
            $authorOverrides['pp05_created_by_organization_name'] = $previousPp06->pp05_created_by_organization_name;
            $authorOverrides['pp05_created_by_workspace_name'] = $previousPp06->pp05_created_by_workspace_name;
            $authorOverrides['pp05_created_at'] = $previousPp06->pp05_created_at;
        }

        $sessionContext = $this->getSessionContext();

        // Upload new dokumen files
        $newDokumenFileIds = $this->commentService->storeFiles(
            $request->file('dokumen_files', []),
            $ppWorkflow,
            'pp.pp07.dokumen',
            $request->user()->id,
            $sessionContext,
        );

        // Merge kept + new file IDs into draft_data
        $keepFileIds = $validated['keep_file_ids'] ?? [];
        $allDokumenFileIds = array_merge($keepFileIds, $newDokumenFileIds);
        $draftData['item_dokumen_sop'] = array_map(fn (int $id) => ['file_id' => $id], $allDokumenFileIds);

        // Set is_workspace_public = true on all attached files
        if (! empty($allDokumenFileIds)) {
            File::whereIn('id', $allDokumenFileIds)->update(['is_workspace_public' => true]);
        }

        // Comment attachment files (separate from dokumen files)
        $commentFileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $pp07Data,
            'pp.pp07.submit',
            $request->user()->id,
            $sessionContext,
        );

        // Compute changelog diff against previous PP06 revision
        $compileService = app(PpCompileService::class);
        $changelogDiff = $previousPp06
            ? $compileService->computeDiff($previousPp06->load([
                'kodeBidangPelayanan', 'kodeSubBidangPelayanan',
                'kodeKategoriPelayanan', 'kodeJenisProgram',
                'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop',
            ]), $draftData)
            : [];

        $pp06 = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $ppWorkflow, $pp07Data, $draftData, $newRevision, $authorOverrides, $validated, $sessionContext, $commentFileIds, $compileService, $changelogDiff) {
            // Save final draft_data and mark as submitted
            $pp07Data->update([
                'draft_data' => $draftData,
                'submitted_at' => now(),
            ]);

            // Record PP07 submitted (with changelog diff)
            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP07',
                action: 'submitted',
                userId: $request->user()->id,
                sessionContext: $sessionContext,
                table: 'pp07_data',
                dataId: $pp07Data->id,
                notes: $validated['notes'] ?? null,
                files: ! empty($commentFileIds) ? $commentFileIds : null,
                extra: ['revision' => $newRevision, 'changes' => $changelogDiff],
            );

            // Compile new PP06 revision
            $pp06 = $compileService->compileFromDraft($ppWorkflow, $draftData, $newRevision, $authorOverrides);

            // Record PP06 completed
            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP06',
                action: 'completed',
                userId: null,
                sessionContext: [],
                table: 'pp06_periode_tahunan',
                dataId: $pp06->id,
                extra: [
                    'revision' => $newRevision,
                    'triggered_by' => [
                        'user_id' => $request->user()->id,
                        'step' => 'PP07',
                        'action' => 'submitted',
                    ],
                ],
            );

            return $pp06;
        });

        // Generate export files (outside transaction — failures are non-blocking)
        $exportResult = $compileService->generateExportFiles($pp06, $request->user()->id, $ppWorkflow->workspace_id);
        $this->appendExportFilesToHistory($ppWorkflow, $exportResult, $newRevision);

        $this->notifier->notify($ppWorkflow, 'pp07.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.pp06.show', $ppWorkflow),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.pp06.show', $ppWorkflow)->with('success', 'Revisi berhasil disubmit.');
    }

    public function comment(PpCommentRequest $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.comment');

        $validated = $request->validated();

        $this->commentService->store(
            workflow: $ppWorkflow,
            source: $validated['source'],
            notes: $validated['notes'],
            uploadedFiles: $request->file('files', []),
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            workflowPrefix: 'pp',
        );

        return back()->with('success', 'Komentar berhasil ditambahkan.');
    }

    public function terminate(PpTerminateRequest $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.terminate');

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];

        if ($this->engine->getWorkflowStatus($history) !== 'active') {
            return back()->withErrors(['terminate' => 'Workflow tidak dalam status aktif.']);
        }

        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $activeStep = $currentSteps[0] ?? 'PP01';

        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $ppWorkflow,
            "pp.{$activeStep}.terminate",
            $request->user()->id,
            $this->getSessionContext(),
        );

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: $activeStep,
            action: 'terminated',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            notes: $request->input('notes'),
            files: ! empty($fileIds) ? $fileIds : null,
        );

        $this->notifier->notify($ppWorkflow, "{$activeStep}.terminated", [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
            'link' => route('admin.workflows.pp.show', $ppWorkflow),
        ], $request->user()->id);

        return to_route('admin.workflows.pp.show', $ppWorkflow)->with('success', 'Workflow berhasil dibatalkan.');
    }

    public function destroy(PpWorkflow $ppWorkflow): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($ppWorkflow);
        $this->checkPermission('admin.workflows.pp.destroy');

        $history = $ppWorkflow->history ?? [];

        if ($this->engine->getWorkflowStatus($history) !== 'terminated') {
            return back()->withErrors(['delete' => 'Hanya workflow yang sudah dibatalkan yang bisa dihapus.']);
        }

        $ppWorkflow->delete();

        return to_route('admin.workflows.pp.index')->with('success', 'Workflow berhasil dihapus.');
    }

    // --- Helper Methods ---

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function workflowProps(PpWorkflow $ppWorkflow, WorkflowDefinition $definition): array
    {
        $history = $ppWorkflow->history ?? [];
        $pp01 = $ppWorkflow->latestPp01();

        return [
            'id' => $ppWorkflow->id,
            'label' => $pp01?->tahun ? "PP-{$pp01->tahun}" : 'PP-Baru',
            'status' => $this->engine->getWorkflowStatus($history),
            'history' => $this->historyFormatter->format($history),
            'step_statuses' => $this->engine->getStepStatuses($definition, $history),
            'current_steps' => $this->engine->getCurrentSteps($definition, $history),
        ];
    }

    private function resolveMode(array $statuses, string $step): string
    {
        $status = $statuses[$step]['status'] ?? 'pending';

        if ($status === 'active' && $statuses[$step]['cycle'] > 1) {
            return 'edit'; // Re-entry after rejection
        }

        if ($status === 'active' && $statuses[$step]['dataId'] !== null) {
            return 'edit'; // Has data (draft)
        }

        if ($status === 'active') {
            return 'create';
        }

        return 'readonly';
    }

    private function ensureStepActive(PpWorkflow $ppWorkflow, string $step): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array($step, $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            abort(409, 'Step ini sudah tidak aktif.');
        }
    }

    private function ensureWorkspaceOwnership(PpWorkflow $ppWorkflow): void
    {
        if ($ppWorkflow->workspace_id !== $this->session->getActiveWorkspaceId()) {
            abort(403, 'Workflow bukan milik workspace aktif.');
        }
    }

    private function checkPermission(string $permission): void
    {
        if (! in_array($permission, $this->session->getActivePermissions())) {
            abort(403);
        }
    }

    private function ensureCurrentRecord(PpWorkflow $ppWorkflow, string $step, int $recordId): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
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

    private function syncKodeItems(Pp01Data $pp01Data, array $validated): void
    {
        $kodeMapping = [
            'kode_bidang_pelayanan' => 'kodeBidangPelayanan',
            'kode_sub_bidang_pelayanan' => 'kodeSubBidangPelayanan',
            'kode_kategori_pelayanan' => 'kodeKategoriPelayanan',
            'kode_jenis_program' => 'kodeJenisProgram',
        ];

        foreach ($kodeMapping as $key => $relation) {
            $pp01Data->$relation()->delete();

            foreach ($validated[$key] ?? [] as $item) {
                if (empty($item['kode']) || empty($item['nama'])) {
                    continue;
                }

                $pp01Data->$relation()->create($item);
            }
        }
    }

    /**
     * Append export file IDs to the matching "PP06 completed" history entry.
     *
     * @param  array{pdf_file_id: int|null, excel_file_id: int|null}  $exportResult
     */
    private function appendExportFilesToHistory(PpWorkflow $ppWorkflow, array $exportResult, int $revision): void
    {
        $newFileIds = array_values(array_filter([
            $exportResult['pdf_file_id'],
            $exportResult['excel_file_id'],
        ]));

        if (empty($newFileIds)) {
            return;
        }

        $history = $ppWorkflow->history ?? [];

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (
                ($history[$i]['step'] ?? '') === 'PP06'
                && ($history[$i]['action'] ?? '') === 'completed'
                && ($history[$i]['revision'] ?? null) === $revision
            ) {
                $existing = $history[$i]['files'] ?? [];
                $history[$i]['files'] = array_values(array_unique(array_merge($existing, $newFileIds)));
                break;
            }
        }

        $ppWorkflow->update(['history' => $history]);
    }

    /**
     * Resolve the latest submitter info for each given step from workflow history.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  list<string>  $steps
     * @return array<string, array{name: string, role: string|null, team: string|null, at: string}>
     */
    private function resolveStepSubmitters(array $history, array $steps): array
    {
        $submitters = [];

        foreach ($history as $entry) {
            $step = $entry['step'] ?? '';
            $action = $entry['action'] ?? '';

            if ($action === 'submitted' && in_array($step, $steps, true)) {
                $submitters[$step] = [
                    'by' => $entry['by'] ?? null,
                    'role' => $entry['role'] ?? null,
                    'team' => $entry['team'] ?? null,
                    'at' => $entry['at'] ?? null,
                ];
            }
        }

        // Batch resolve names
        $userIds = array_filter(array_column($submitters, 'by'));
        $roleIds = array_filter(array_column($submitters, 'role'));
        $teamIds = array_filter(array_column($submitters, 'team'));

        $users = ! empty($userIds) ? \App\Models\User::withTrashed()->whereIn('id', $userIds)->pluck('name', 'id') : collect();
        $roles = ! empty($roleIds) ? Role::withTrashed()->whereIn('id', $roleIds)->pluck('name', 'id') : collect();
        $teams = ! empty($teamIds) ? \App\Models\Team::withTrashed()->whereIn('id', $teamIds)->pluck('name', 'id') : collect();

        $result = [];

        foreach ($submitters as $step => $data) {
            $result[$step] = [
                'name' => $data['by'] ? ($users[$data['by']] ?? 'Unknown') : 'System',
                'role' => $data['role'] ? ($roles[$data['role']] ?? null) : null,
                'team' => $data['team'] ? ($teams[$data['team']] ?? null) : null,
                'at' => $data['at'] ? \Carbon\Carbon::parse($data['at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : '-',
            ];
        }

        return $result;
    }

    private function resolveSessionRoleName(): string
    {
        $roleId = $this->session->getActiveRoleId();

        if (! $roleId) {
            return 'System';
        }

        return \App\Models\Role::find($roleId)?->name ?? 'Unknown';
    }

    /**
     * Resolve action-role mapping for a step's permissions.
     *
     * @param  array<string, array{string, bool}>  $permissionLabels  ['permission.name' => ['Label', highlight]]
     * @return list<array{action: string, label: string, roles: list<array{name: string, users: list<string>}>, highlight: bool}>
     */
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

    /**
     * Get the active role's formatted name (with team) for frontend highlighting.
     */
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

    /**
     * Resolve which roles are responsible for each PP step's key action.
     *
     * @return array<string, list<string>> step code => role names
     */
    private function resolveStepRoles(): array
    {
        $stepPermissions = [
            'PP01' => 'admin.workflows.pp.pp01.submit',
            'PP02' => 'admin.workflows.pp.pp02.submit',
            'PP03' => 'admin.workflows.pp.pp03.submit',
            'PP04' => 'admin.workflows.pp.pp04.submit',
            'PP05' => 'admin.workflows.pp.pp05.approve',
            'PP06' => null,
            'PP07' => 'admin.workflows.pp.pp07.submit',
        ];

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
                foreach ($perm->roles->sortBy(fn (Role $r) => $r->team ? "{$r->name} ({$r->team->name})" : $r->name) as $role) {
                    $roles[] = $role->team ? "{$role->name} ({$role->team->name})" : $role->name;
                }
            }

            $map[$step] = $roles;
        }

        return $map;
    }

    /**
     * Filter transformed workflow rows by anggaran bracket.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function applyAnggaranFilter(\Illuminate\Support\Collection $items, string $bracket): \Illuminate\Support\Collection
    {
        return match ($bracket) {
            'none' => $items->filter(fn ($i) => $i['total_anggaran'] === null),
            'lt100' => $items->filter(fn ($i) => $i['total_anggaran'] !== null && $i['total_anggaran'] < 100_000_000),
            '100-500' => $items->filter(fn ($i) => $i['total_anggaran'] !== null && $i['total_anggaran'] >= 100_000_000 && $i['total_anggaran'] < 500_000_000),
            '500-1000' => $items->filter(fn ($i) => $i['total_anggaran'] !== null && $i['total_anggaran'] >= 500_000_000 && $i['total_anggaran'] < 1_000_000_000),
            'gt1000' => $items->filter(fn ($i) => $i['total_anggaran'] !== null && $i['total_anggaran'] >= 1_000_000_000),
            default => $items,
        };
    }

    /**
     * Extract the latest PP05 rejection notes from workflow history.
     *
     * @param  list<array<string, mixed>>  $history
     * @return array{notes: string, by: string|null, at: string|null}|null
     */
    private function getLatestRejectionNotes(array $history): ?array
    {
        $rejection = collect($history)
            ->last(fn ($e) => ($e['step'] ?? '') === 'PP05' && ($e['action'] ?? '') === 'rejected');

        if (! $rejection || empty($rejection['notes'])) {
            return null;
        }

        $byName = null;
        if (isset($rejection['by'])) {
            $byName = \App\Models\User::find($rejection['by'])?->name;
        }

        return [
            'notes' => $rejection['notes'],
            'by' => $byName,
            'at' => $rejection['at'] ?? null,
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function getWorkspaceTeams(int $workspaceId): array
    {
        return \App\Models\Team::query()
            ->whereHas('organization.workspaces', fn ($q) => $q->where('workspaces.id', $workspaceId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }

    /**
     * Transform a PpWorkflow model into the index page row data.
     *
     * @param  array<int, string>  $userCache
     * @param  array<int, string|null>  $roleCache
     * @return array<string, mixed>
     */
    private function transformWorkflowForIndex(PpWorkflow $wf, WorkflowDefinition $definition, array &$userCache, array &$roleCache): array
    {
        $history = $wf->history ?? [];
        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $workflowStatus = $this->engine->getWorkflowStatus($history);
        $pp01 = $wf->latestPp01();
        $pp06 = $wf->latestPp06();

        // Detect "Dalam Revisi" — completed workflow with unsubmitted PP07 draft
        if ($workflowStatus === 'completed') {
            $hasPp07Draft = $wf->pp07Data()->whereNull('submitted_at')->exists();
            if ($hasPp07Draft) {
                $workflowStatus = 'revising';
            }
        }

        // Creator from first history entry
        $creatorEntry = $history[0] ?? null;
        $creatorUserId = $creatorEntry['by'] ?? null;
        $creatorName = 'System';
        if ($creatorUserId) {
            if (! isset($userCache[$creatorUserId])) {
                $userCache[$creatorUserId] = \App\Models\User::find($creatorUserId)?->name ?? 'Unknown';
            }
            $creatorName = $userCache[$creatorUserId];
        }
        $creatorRoleName = null;
        if ($creatorEntry && isset($creatorEntry['role'])) {
            $roleId = $creatorEntry['role'];
            if (! isset($roleCache[$roleId])) {
                $roleCache[$roleId] = \App\Models\Role::find($roleId)?->name;
            }
            $creatorRoleName = $roleCache[$roleId];
        }

        // "Terakhir" — PP05 approver (rev 0) or PP07 submitter (rev 1+)
        $lastActorName = null;
        $lastActorRole = null;
        if ($pp06) {
            if ($pp06->revision > 0) {
                $pp07Entry = collect($history)->last(fn ($e) => ($e['step'] ?? '') === 'PP07' && ($e['action'] ?? '') === 'submitted');
                if ($pp07Entry && isset($pp07Entry['by'])) {
                    if (! isset($userCache[$pp07Entry['by']])) {
                        $userCache[$pp07Entry['by']] = \App\Models\User::find($pp07Entry['by'])?->name ?? 'Unknown';
                    }
                    $lastActorName = $userCache[$pp07Entry['by']];
                    if (isset($pp07Entry['role'])) {
                        $rId = $pp07Entry['role'];
                        if (! isset($roleCache[$rId])) {
                            $roleCache[$rId] = \App\Models\Role::find($rId)?->name;
                        }
                        $lastActorRole = $roleCache[$rId];
                    }
                }
            } else {
                $lastActorName = $pp06->pp05_created_by_user_name;
                $lastActorRole = $pp06->pp05_created_by_role_name;
            }
        }

        // Tim count and total plafon from PP06
        $timCount = null;
        $totalAnggaran = null;
        if ($pp06) {
            $plafon = $pp06->itemPlafonAnggaran;
            $timCount = $plafon->count();
            $totalAnggaran = $plafon->sum('plafon_anggaran');
        }

        // Step aktif text
        $stepAktif = null;
        if ($workflowStatus === 'active' && ! empty($currentSteps)) {
            $stepAktif = $currentSteps[0];
        }

        return [
            'id' => $wf->id,
            'label' => $pp01?->tahun ? "PP-{$pp01->tahun}" : 'PP-Baru',
            'status' => $workflowStatus,
            'step_aktif' => $stepAktif,
            'pra_raker' => $pp06?->tanggal_mulai_pra_raker?->format('d/m/Y'),
            'penetapan' => $pp06?->tanggal_penetapan_program?->format('d/m/Y'),
            'tim_count' => $timCount,
            'total_anggaran' => $totalAnggaran,
            'terakhir_name' => $lastActorName,
            'terakhir_role' => $lastActorRole,
            'revision' => $pp06?->revision,
            'dibuat_oleh_name' => $creatorName,
            'dibuat_oleh_role' => $creatorRoleName,
            'tanggal' => $wf->created_at->format('d/m/Y'),
        ];
    }
}
