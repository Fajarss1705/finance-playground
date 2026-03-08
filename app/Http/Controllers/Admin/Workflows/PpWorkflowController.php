<?php

namespace App\Http\Controllers\Admin\Workflows;

use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp02Data;
use App\Models\Pp\Pp03Data;
use App\Models\Pp\Pp04Data;
use App\Models\Pp\Pp07Data;
use App\Models\Pp\PpWorkflow;
use App\Services\ActiveSessionService;
use App\Services\PpCompileService;
use App\Services\WorkflowEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PpWorkflowController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private ActiveSessionService $session,
    ) {}

    public function index(): Response
    {
        $workspaceId = $this->session->getActiveWorkspaceId();
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        $workflows = PpWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        // Compute status and label for each workflow
        $workflows->through(function (PpWorkflow $wf) use ($definition) {
            $statuses = $this->engine->getStepStatuses($definition, $wf->history ?? []);
            $pp01 = $wf->latestPp01();
            $pp06 = $wf->latestPp06();

            return [
                'id' => $wf->id,
                'label' => $pp01?->tahun ? "PP-{$pp01->tahun}" : 'PP-Baru',
                'tahun' => $pp01?->tahun,
                'status' => $this->engine->getWorkflowStatus($wf->history ?? []),
                'step_statuses' => $statuses,
                'current_steps' => $this->engine->getCurrentSteps($definition, $wf->history ?? []),
                'pp06' => $pp06 ? [
                    'revision' => $pp06->revision,
                    'tahun' => $pp06->tahun,
                    'tanggal_mulai_pra_raker' => $pp06->tanggal_mulai_pra_raker?->format('Y-m-d'),
                    'tanggal_penetapan_program' => $pp06->tanggal_penetapan_program?->format('Y-m-d'),
                ] : null,
                'created_at' => $wf->created_at->toIso8601String(),
                'updated_at' => $wf->updated_at->toIso8601String(),
            ];
        });

        return Inertia::render('admin/workflows/pp/index', [
            'workflows' => $workflows,
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $workspaceId = $this->session->getActiveWorkspaceId();
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

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
        ]);
    }

    public function show(PpWorkflow $ppWorkflow): Response
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $pp01 = $ppWorkflow->latestPp01();

        return Inertia::render('admin/workflows/pp/show', [
            'workflow' => [
                'id' => $ppWorkflow->id,
                'label' => $pp01?->tahun ? "PP-{$pp01->tahun}" : 'PP-Baru',
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->formatHistoryForFrontend($history),
                'step_statuses' => $statuses,
                'current_steps' => $this->engine->getCurrentSteps($definition, $history),
            ],
        ]);
    }

    public function pp01Show(PpWorkflow $ppWorkflow, Pp01Data $pp01Data): Response
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $pp01 = $ppWorkflow->latestPp01();

        $mode = $this->resolveMode($statuses, 'PP01');

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
            'canDraft' => $mode === 'edit' || $mode === 'create',
            'canSubmit' => $mode === 'edit' || $mode === 'create',
            'canTerminate' => $this->engine->getWorkflowStatus($history) === 'active',
            'isRejectionReentry' => $statuses['PP01']['cycle'] > 1 && $statuses['PP01']['status'] === 'active',
        ]);
    }

    public function pp01Draft(Request $request, PpWorkflow $ppWorkflow, Pp01Data $pp01Data): RedirectResponse
    {
        $this->ensureStepActive($ppWorkflow, 'PP01');

        $validated = $request->validate([
            'tahun' => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'tanggal_mulai_pra_raker' => ['nullable', 'date'],
            'tanggal_penetapan_program' => ['nullable', 'date'],
            'kode_bidang_pelayanan' => ['nullable', 'array'],
            'kode_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_sub_bidang_pelayanan' => ['nullable', 'array'],
            'kode_sub_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_sub_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_sub_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_kategori_pelayanan' => ['nullable', 'array'],
            'kode_kategori_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_kategori_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_kategori_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_jenis_program' => ['nullable', 'array'],
            'kode_jenis_program.*.kode' => ['required', 'string', 'max:10'],
            'kode_jenis_program.*.nama' => ['required', 'string', 'max:255'],
            'kode_jenis_program.*.catatan' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
        ]);

        $this->checkOptimisticLock($pp01Data, $validated['expected_updated_at']);

        $pp01Data->update([
            'tahun' => $validated['tahun'] ?? null,
            'tanggal_mulai_pra_raker' => $validated['tanggal_mulai_pra_raker'] ?? null,
            'tanggal_penetapan_program' => $validated['tanggal_penetapan_program'] ?? null,
        ]);

        $this->syncKodeItems($pp01Data, $validated);

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP01',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp01_data',
            dataId: $pp01Data->id,
        );

        return back();
    }

    public function pp01Submit(Request $request, PpWorkflow $ppWorkflow, Pp01Data $pp01Data): RedirectResponse
    {
        $validated = $request->validate([
            'tahun' => ['required', 'integer', 'min:2020', 'max:2099'],
            'tanggal_mulai_pra_raker' => ['required', 'date'],
            'tanggal_penetapan_program' => ['required', 'date', 'after:tanggal_mulai_pra_raker'],
            'kode_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'kode_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_sub_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'kode_sub_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_sub_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_sub_bidang_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_kategori_pelayanan' => ['required', 'array', 'min:1'],
            'kode_kategori_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_kategori_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_kategori_pelayanan.*.catatan' => ['nullable', 'string'],
            'kode_jenis_program' => ['required', 'array', 'min:1'],
            'kode_jenis_program.*.kode' => ['required', 'string', 'max:10'],
            'kode_jenis_program.*.nama' => ['required', 'string', 'max:255'],
            'kode_jenis_program.*.catatan' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        // Check engine state
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $currentSteps = $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []);

        if (! in_array('PP01', $currentSteps)) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

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

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP01',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp01_data',
            dataId: $pp01Data->id,
            notes: $validated['notes'] ?? null,
        );

        // Auto-create PP02
        $pp02 = Pp02Data::create(['pp_workflow_id' => $ppWorkflow->id]);

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP02',
            action: 'created',
            userId: null,
            sessionContext: $this->getSessionContext(),
            table: 'pp02_data',
            dataId: $pp02->id,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow);
    }

    public function pp02Show(PpWorkflow $ppWorkflow, Pp02Data $pp02Data): Response
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $mode = $this->resolveMode($statuses, 'PP02');

        return Inertia::render('admin/workflows/pp/pp02', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp02Data->id,
                'item_kuisioner' => $pp02Data->itemKuisioner,
                'updated_at' => $pp02Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => $mode !== 'readonly',
            'canSubmit' => $mode !== 'readonly',
            'canTerminate' => $this->engine->getWorkflowStatus($history) === 'active',
            'isRejectionReentry' => $statuses['PP02']['cycle'] > 1 && $statuses['PP02']['status'] === 'active',
        ]);
    }

    public function pp02Draft(Request $request, PpWorkflow $ppWorkflow, Pp02Data $pp02Data): RedirectResponse
    {
        $this->ensureStepActive($ppWorkflow, 'PP02');

        $validated = $request->validate([
            'item_kuisioner' => ['nullable', 'array'],
            'item_kuisioner.*.kode' => ['required', 'string', 'max:10'],
            'item_kuisioner.*.pertanyaan' => ['required', 'string', 'max:255'],
            'item_kuisioner.*.tipe' => ['required', 'string', 'max:50'],
            'item_kuisioner.*.satuan' => ['nullable', 'string', 'max:100'],
            'expected_updated_at' => ['required', 'string'],
        ]);

        $this->checkOptimisticLock($pp02Data, $validated['expected_updated_at']);

        $pp02Data->itemKuisioner()->delete();

        foreach ($validated['item_kuisioner'] ?? [] as $item) {
            $pp02Data->itemKuisioner()->create($item);
        }

        $pp02Data->touch();

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP02',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp02_data',
            dataId: $pp02Data->id,
        );

        return back();
    }

    public function pp02Submit(Request $request, PpWorkflow $ppWorkflow, Pp02Data $pp02Data): RedirectResponse
    {
        $validated = $request->validate([
            'item_kuisioner' => ['required', 'array', 'min:1'],
            'item_kuisioner.*.kode' => ['required', 'string', 'max:10'],
            'item_kuisioner.*.pertanyaan' => ['required', 'string', 'max:255'],
            'item_kuisioner.*.tipe' => ['required', 'string', 'max:50'],
            'item_kuisioner.*.satuan' => ['required_unless:item_kuisioner.*.tipe,Kualitatif', 'nullable', 'string', 'max:100'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP02', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->checkOptimisticLock($pp02Data, $validated['expected_updated_at']);

        $pp02Data->itemKuisioner()->delete();

        foreach ($validated['item_kuisioner'] as $item) {
            $pp02Data->itemKuisioner()->create($item);
        }

        $pp02Data->touch();

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP02',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp02_data',
            dataId: $pp02Data->id,
            notes: $validated['notes'] ?? null,
        );

        // Auto-create PP03
        $pp03 = Pp03Data::create(['pp_workflow_id' => $ppWorkflow->id]);

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP03',
            action: 'created',
            userId: null,
            sessionContext: $this->getSessionContext(),
            table: 'pp03_data',
            dataId: $pp03->id,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow);
    }

    public function pp03Show(PpWorkflow $ppWorkflow, Pp03Data $pp03Data): Response
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $mode = $this->resolveMode($statuses, 'PP03');

        $pp03Data->load('itemPlafonAnggaran.team');

        return Inertia::render('admin/workflows/pp/pp03', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp03Data->id,
                'item_plafon_anggaran' => $pp03Data->itemPlafonAnggaran,
                'updated_at' => $pp03Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => $mode !== 'readonly',
            'canSubmit' => $mode !== 'readonly',
            'canTerminate' => $this->engine->getWorkflowStatus($history) === 'active',
            'isRejectionReentry' => $statuses['PP03']['cycle'] > 1 && $statuses['PP03']['status'] === 'active',
            'teams' => $this->getWorkspaceTeams($ppWorkflow->workspace_id),
        ]);
    }

    public function pp03Draft(Request $request, PpWorkflow $ppWorkflow, Pp03Data $pp03Data): RedirectResponse
    {
        $this->ensureStepActive($ppWorkflow, 'PP03');

        $validated = $request->validate([
            'item_plafon_anggaran' => ['nullable', 'array'],
            'item_plafon_anggaran.*.team_id' => ['required', 'integer', 'exists:teams,id'],
            'item_plafon_anggaran.*.kode_team' => ['required', 'string', 'max:10'],
            'item_plafon_anggaran.*.plafon_anggaran' => ['required', 'numeric', 'min:0'],
            'item_plafon_anggaran.*.nama_bank' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nama_rekening' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nomor_rekening' => ['required', 'string', 'max:50'],
            'item_plafon_anggaran.*.catatan' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
        ]);

        $this->checkOptimisticLock($pp03Data, $validated['expected_updated_at']);

        $pp03Data->itemPlafonAnggaran()->delete();

        foreach ($validated['item_plafon_anggaran'] ?? [] as $item) {
            $pp03Data->itemPlafonAnggaran()->create($item);
        }

        $pp03Data->touch();

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP03',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp03_data',
            dataId: $pp03Data->id,
        );

        return back();
    }

    public function pp03Submit(Request $request, PpWorkflow $ppWorkflow, Pp03Data $pp03Data): RedirectResponse
    {
        $validated = $request->validate([
            'item_plafon_anggaran' => ['required', 'array', 'min:1'],
            'item_plafon_anggaran.*.team_id' => ['required', 'integer', 'exists:teams,id'],
            'item_plafon_anggaran.*.kode_team' => ['required', 'string', 'max:10'],
            'item_plafon_anggaran.*.plafon_anggaran' => ['required', 'numeric', 'min:0'],
            'item_plafon_anggaran.*.nama_bank' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nama_rekening' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nomor_rekening' => ['required', 'string', 'max:50'],
            'item_plafon_anggaran.*.catatan' => ['nullable', 'string'],
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP03', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->checkOptimisticLock($pp03Data, $validated['expected_updated_at']);

        $pp03Data->itemPlafonAnggaran()->delete();

        foreach ($validated['item_plafon_anggaran'] as $item) {
            $pp03Data->itemPlafonAnggaran()->create($item);
        }

        $pp03Data->touch();

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP03',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp03_data',
            dataId: $pp03Data->id,
            notes: $validated['notes'] ?? null,
        );

        // Auto-create PP04
        $pp04 = Pp04Data::create(['pp_workflow_id' => $ppWorkflow->id]);

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP04',
            action: 'created',
            userId: null,
            sessionContext: $this->getSessionContext(),
            table: 'pp04_data',
            dataId: $pp04->id,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow);
    }

    public function pp04Show(PpWorkflow $ppWorkflow, Pp04Data $pp04Data): Response
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);
        $mode = $this->resolveMode($statuses, 'PP04');

        return Inertia::render('admin/workflows/pp/pp04', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp04Data->id,
                'item_dokumen' => $pp04Data->itemDokumen()->with('file')->get(),
                'updated_at' => $pp04Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => $mode !== 'readonly',
            'canSubmit' => $mode !== 'readonly',
            'canTerminate' => $this->engine->getWorkflowStatus($history) === 'active',
            'isRejectionReentry' => $statuses['PP04']['cycle'] > 1 && $statuses['PP04']['status'] === 'active',
        ]);
    }

    public function pp04Submit(Request $request, PpWorkflow $ppWorkflow, Pp04Data $pp04Data): RedirectResponse
    {
        $request->validate([
            'expected_updated_at' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP04', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['submit' => 'Step ini sudah tidak aktif.']);
        }

        $this->checkOptimisticLock($pp04Data, $request->input('expected_updated_at'));

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP04',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp04_data',
            dataId: $pp04Data->id,
            notes: $request->input('notes'),
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow);
    }

    public function pp05Show(PpWorkflow $ppWorkflow): Response
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Load all step data for review
        $pp01 = $ppWorkflow->pp01Data()->latest('id')->first();
        $pp02 = $ppWorkflow->pp02Data()->latest('id')->first();
        $pp03 = $ppWorkflow->pp03Data()->latest('id')->first();
        $pp04 = $ppWorkflow->pp04Data()->latest('id')->first();

        $pp01?->load(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram']);
        $pp02?->load('itemKuisioner');
        $pp03?->load('itemPlafonAnggaran.team');
        $pp04?->load('itemDokumen.file');

        $isActive = $statuses['PP05']['status'] === 'active';

        return Inertia::render('admin/workflows/pp/pp05', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'reviewData' => [
                'pp01' => $pp01,
                'pp02' => $pp02,
                'pp03' => $pp03,
                'pp04' => $pp04,
            ],
            'canApprove' => $isActive,
            'canReject' => $isActive,
            'canTerminate' => $this->engine->getWorkflowStatus($history) === 'active',
        ]);
    }

    public function pp05Approve(Request $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP05', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['approve' => 'Step ini sudah tidak aktif.']);
        }

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

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $ppWorkflow, $reviewed) {
            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP05',
                action: 'approved',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                notes: $request->input('notes'),
                extra: ['reviewed' => $reviewed],
            );

            // Compile PP06
            $compileService = app(PpCompileService::class);
            $pp06 = $compileService->compile($ppWorkflow);

            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP06',
                action: 'completed',
                userId: null,
                sessionContext: $this->getSessionContext(),
                table: 'pp06_periode_tahunan',
                dataId: $pp06->id,
            );
        });

        return to_route('admin.workflows.pp.show', $ppWorkflow);
    }

    public function pp05Reject(Request $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $request->validate([
            'notes' => ['required', 'string'],
        ]);

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);

        if (! in_array('PP05', $this->engine->getCurrentSteps($definition, $ppWorkflow->history ?? []))) {
            return back()->withErrors(['reject' => 'Step ini sudah tidak aktif.']);
        }

        $pp01 = $ppWorkflow->pp01Data()->latest('id')->first();
        $pp02 = $ppWorkflow->pp02Data()->latest('id')->first();
        $pp03 = $ppWorkflow->pp03Data()->latest('id')->first();
        $pp04 = $ppWorkflow->pp04Data()->latest('id')->first();

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP05',
            action: 'rejected',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            notes: $request->input('notes'),
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
            sessionContext: $this->getSessionContext(),
            table: 'pp01_data',
            dataId: $newPp01->id,
        );

        return to_route('admin.workflows.pp.show', $ppWorkflow);
    }

    public function pp06Show(PpWorkflow $ppWorkflow): Response
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $pp06 = $ppWorkflow->latestPp06();
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

        return Inertia::render('admin/workflows/pp/pp06', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'pp06' => $pp06,
            'allRevisions' => $ppWorkflow->pp06PeriodeTahunan()
                ->orderBy('revision')
                ->get(['id', 'revision', 'tahun', 'created_at']),
            'canRevise' => $pp06 !== null && $workflowStatus === 'completed',
            'activeDraftId' => $activeDraft?->id,
        ]);
    }

    public function pp07Create(Request $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];

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
        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $isSubmitted = $pp07Data->submitted_at !== null;
        $mode = $isSubmitted ? 'readonly' : 'edit';

        return Inertia::render('admin/workflows/pp/pp07', [
            'workflow' => $this->workflowProps($ppWorkflow, $definition),
            'stepData' => [
                'id' => $pp07Data->id,
                'draft_data' => $pp07Data->draft_data ?? [],
                'submitted_at' => $pp07Data->submitted_at?->toIso8601String(),
                'updated_at' => $pp07Data->updated_at->toIso8601String(),
            ],
            'mode' => $mode,
            'canDraft' => ! $isSubmitted,
            'canSubmit' => ! $isSubmitted,
            'teams' => $this->getWorkspaceTeams($ppWorkflow->workspace_id),
        ]);
    }

    public function pp07Draft(Request $request, PpWorkflow $ppWorkflow, Pp07Data $pp07Data): RedirectResponse
    {
        if ($pp07Data->submitted_at !== null) {
            abort(409, 'Revisi ini sudah disubmit.');
        }

        $this->checkOptimisticLock($pp07Data, $request->input('expected_updated_at'));

        $pp07Data->update([
            'draft_data' => $request->input('draft_data'),
        ]);

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: 'PP07',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            table: 'pp07_data',
            dataId: $pp07Data->id,
            notes: $request->input('notes'),
        );

        return back();
    }

    public function pp07Submit(Request $request, PpWorkflow $ppWorkflow, Pp07Data $pp07Data): RedirectResponse
    {
        if ($pp07Data->submitted_at !== null) {
            abort(409, 'Revisi ini sudah disubmit.');
        }

        $this->checkOptimisticLock($pp07Data, $request->input('expected_updated_at'));

        $draftData = $request->input('draft_data', $pp07Data->draft_data ?? []);

        // Validate combined PP01-PP04 rules
        $validated = validator($draftData, [
            'tahun' => ['required', 'integer', 'min:2020', 'max:2099'],
            'tanggal_mulai_pra_raker' => ['required', 'date'],
            'tanggal_penetapan_program' => ['required', 'date', 'after:tanggal_mulai_pra_raker'],
            'kode_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'kode_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_sub_bidang_pelayanan' => ['required', 'array', 'min:1'],
            'kode_sub_bidang_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_sub_bidang_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_kategori_pelayanan' => ['required', 'array', 'min:1'],
            'kode_kategori_pelayanan.*.kode' => ['required', 'string', 'max:10'],
            'kode_kategori_pelayanan.*.nama' => ['required', 'string', 'max:255'],
            'kode_jenis_program' => ['required', 'array', 'min:1'],
            'kode_jenis_program.*.kode' => ['required', 'string', 'max:10'],
            'kode_jenis_program.*.nama' => ['required', 'string', 'max:255'],
            'item_kuisioner' => ['required', 'array', 'min:1'],
            'item_kuisioner.*.kode' => ['required', 'string', 'max:10'],
            'item_kuisioner.*.pertanyaan' => ['required', 'string', 'max:255'],
            'item_kuisioner.*.tipe' => ['required', 'string', 'max:50'],
            'item_kuisioner.*.satuan' => ['required_unless:item_kuisioner.*.tipe,Kualitatif', 'nullable', 'string', 'max:100'],
            'item_plafon_anggaran' => ['required', 'array', 'min:1'],
            'item_plafon_anggaran.*.team_id' => ['required', 'integer', 'exists:teams,id'],
            'item_plafon_anggaran.*.kode_team' => ['required', 'string', 'max:10'],
            'item_plafon_anggaran.*.plafon_anggaran' => ['required', 'numeric', 'min:0'],
            'item_plafon_anggaran.*.nama_bank' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nama_rekening' => ['required', 'string', 'max:255'],
            'item_plafon_anggaran.*.nomor_rekening' => ['required', 'string', 'max:50'],
        ])->validate();

        // Unique tahun check (PP07 may change tahun)
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

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $ppWorkflow, $pp07Data, $validated, $newRevision, $authorOverrides) {
            // Save final draft_data and mark as submitted
            $pp07Data->update([
                'draft_data' => $validated,
                'submitted_at' => now(),
            ]);

            // Record PP07 submitted
            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP07',
                action: 'submitted',
                userId: $request->user()->id,
                sessionContext: $this->getSessionContext(),
                table: 'pp07_data',
                dataId: $pp07Data->id,
                notes: $request->input('notes'),
                extra: ['revision' => $newRevision],
            );

            // Compile new PP06 revision
            $compileService = app(PpCompileService::class);
            $pp06 = $compileService->compileFromDraft($ppWorkflow, $validated, $newRevision, $authorOverrides);

            // Record PP06 completed
            $this->engine->recordAction(
                workflow: $ppWorkflow,
                step: 'PP06',
                action: 'completed',
                userId: null,
                sessionContext: $this->getSessionContext(),
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
        });

        return to_route('admin.workflows.pp.pp06.show', $ppWorkflow);
    }

    public function comment(Request $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string'],
            'source' => ['required', 'string'],
        ]);

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: $validated['source'],
            action: 'commented',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            notes: $validated['notes'],
            extra: ['source' => $validated['source']],
        );

        return back();
    }

    public function terminate(Request $request, PpWorkflow $ppWorkflow): RedirectResponse
    {
        $request->validate([
            'notes' => ['required', 'string'],
        ]);

        $definition = $this->engine->resolveDefinition(WorkflowType::PP);
        $history = $ppWorkflow->history ?? [];

        if ($this->engine->getWorkflowStatus($history) !== 'active') {
            return back()->withErrors(['terminate' => 'Workflow tidak dalam status aktif.']);
        }

        $currentSteps = $this->engine->getCurrentSteps($definition, $history);
        $activeStep = $currentSteps[0] ?? 'PP01';

        $this->engine->recordAction(
            workflow: $ppWorkflow,
            step: $activeStep,
            action: 'terminated',
            userId: $request->user()->id,
            sessionContext: $this->getSessionContext(),
            notes: $request->input('notes'),
        );

        return to_route('admin.workflows.pp.index');
    }

    // --- Helper Methods ---

    /** @return array<string, mixed> */
    private function getSessionContext(): array
    {
        return [
            'role' => $this->session->getActiveRoleId(),
            'team' => null, // PP is workspace-level, no team
            'org' => null,
            'workspace' => $this->session->getActiveWorkspaceId(),
        ];
    }

    /** @return array<string, mixed> */
    private function workflowProps(PpWorkflow $ppWorkflow, \App\Contracts\WorkflowDefinition $definition): array
    {
        $history = $ppWorkflow->history ?? [];
        $pp01 = $ppWorkflow->latestPp01();

        return [
            'id' => $ppWorkflow->id,
            'label' => $pp01?->tahun ? "PP-{$pp01->tahun}" : 'PP-Baru',
            'status' => $this->engine->getWorkflowStatus($history),
            'history' => $this->formatHistoryForFrontend($history),
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
                $pp01Data->$relation()->create($item);
            }
        }
    }

    private function resolveSessionRoleName(): string
    {
        $roleId = $this->session->getActiveRoleId();

        if (! $roleId) {
            return 'System';
        }

        return \App\Models\Role::find($roleId)?->name ?? 'Unknown';
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

    /** @return list<array<string, mixed>> */
    private function formatHistoryForFrontend(array $history): array
    {
        $userCache = [];

        return array_map(function ($entry) use (&$userCache) {
            $userId = $entry['by'] ?? null;

            if ($userId && ! isset($userCache[$userId])) {
                $user = \App\Models\User::find($userId);
                $userCache[$userId] = $user?->name ?? 'Unknown';
            }

            $entry['by_name'] = $userId ? ($userCache[$userId] ?? 'System') : 'System';

            return $entry;
        }, $history);
    }
}
