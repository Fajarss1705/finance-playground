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
use App\Http\Requests\Workflows\PabdCommentRequest;
use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd02aData;
use App\Models\Pabd\Pabd02aItemPerubahan;
use App\Models\Pabd\Pabd02bData;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04AnggaranCatatanPerubahan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Role;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\PkCompileService;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PabdWorkflowController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private ActiveSessionService $session,
        private HistoryFormatter $historyFormatter,
        private CommentService $commentService,
        private WorkflowNotifier $notifier,
        private PkCompileService $compileService,
    ) {}

    // ──────────────────────────────────────
    // Index & Show (stub — to be built later)
    // ──────────────────────────────────────

    public function index(): Response
    {
        $scope = $this->getScope();
        $this->checkPermission("{$scope}.workflows.pabd.index");

        return Inertia::render("{$scope}/workflows/pabd/index");
    }

    public function show(PabdWorkflow $pabdWorkflow): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($pabdWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($pabdWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.pabd.show");

        return Inertia::render("{$scope}/workflows/pabd/show");
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
    // PABD01 — Checklist Pencairan
    // ──────────────────────────────────────

    public function pabd01Show(PabdWorkflow $pabdWorkflow, Pabd01Data $pabd01Data): Response|\Illuminate\Http\RedirectResponse
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

        return to_route('team.workflows.pabd.pabd01.show', [$pabdWorkflow, $pabd01Data])
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

    public function pabd02aShow(PabdWorkflow $pabdWorkflow, Pabd02aData $pabd02aData): Response|RedirectResponse
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
            $existingItems->where('tipe_perubahan', 'tarik_maju')->pluck('pk04_anggaran_id')->filter()->all(),
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

        return to_route('team.workflows.pabd.pabd02a.show', [$pabdWorkflow, $pabd02aData])
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
            ->where('tipe_perubahan', 'tarik_maju')
            ->pluck('pk04_anggaran_id')
            ->filter();
        if ($tarikMajuIds->count() !== $tarikMajuIds->unique()->count()) {
            abort(422, 'Setiap anggaran hanya dapat ditarik satu kali per pengajuan.');
        }

        // Validate bulan_tujuan constraints for tarik_maju items
        foreach ($validated['items'] as $idx => $item) {
            if ($item['tipe_perubahan'] === 'tarik_maju') {
                $bulanAwal = $item['bulan_awal'] ?? 0;
                $bulanTujuan = $item['bulan_tujuan'] ?? 0;
                $currentMonth = (int) now()->month;

                if ($bulanTujuan >= $bulanAwal) {
                    abort(422, "Bulan tujuan harus lebih kecil dari bulan asal (item #{$idx}).");
                }
                if ($bulanTujuan < $currentMonth) {
                    abort(422, "Bulan tujuan tidak boleh di masa lalu (item #{$idx}).");
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

            $itemAttrs = [
                'pabd02a_data_id' => $pabd02aData->id,
                'tipe_perubahan' => $tipe,
                'pk04_anggaran_id' => $tipe === 'tarik_maju' ? ($itemData['pk04_anggaran_id'] ?? null) : null,
                'bulan_awal' => $tipe === 'tarik_maju' ? ($itemData['bulan_awal'] ?? null) : null,
                'bulan_tujuan' => $tipe === 'tarik_maju' ? ($itemData['bulan_tujuan'] ?? null) : null,
                'komentar' => $itemData['komentar'] ?? null,
            ];

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
                'name' => $f->original_name ?? $f->name,
                'url' => $f->path ? asset("storage/{$f->path}") : null,
            ])->values(),
        ];

        // Tarik maju: enrich with anggaran details
        if ($item->tipe_perubahan === 'tarik_maju' && $item->pk04Anggaran) {
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
                'nominal' => (float) $anggaran->nominal_anggaran,
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

    public function pabd02bShow(PabdWorkflow $pabdWorkflow, Pabd02bData $pabd02bData): Response
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
            if ($perubahanItem->tipe_perubahan === 'tarik_maju' && $perubahanItem->pk04Anggaran) {
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

        return back()->with('success', 'Draft PABD02B berhasil disimpan.');
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

            // 3. Process tarik_maju items (grouped by PK)
            $pabd02bData->load(['itemReview.pabd02aItemPerubahan.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan.pkWorkflow']);
            $tarikMajuByPk = [];
            $allPerubahanItems = [];

            foreach ($pabd02bData->itemReview as $review) {
                $perubahan = $review->pabd02aItemPerubahan;
                $allPerubahanItems[] = ['review' => $review, 'perubahan' => $perubahan];

                if ($perubahan->tipe_perubahan === 'tarik_maju' && $perubahan->pk04Anggaran) {
                    $pkWorkflow = $perubahan->pk04Anggaran->pk04Kegiatan?->pk04ProgramTahunan?->pkWorkflow;
                    if ($pkWorkflow) {
                        $tarikMajuByPk[$pkWorkflow->id] ??= ['workflow' => $pkWorkflow, 'items' => []];
                        $tarikMajuByPk[$pkWorkflow->id]['items'][] = [
                            'pk04_anggaran_id' => $perubahan->pk04_anggaran_id,
                            'bulan_tujuan' => $perubahan->bulan_tujuan,
                            'pabd_workflow_id' => $pabdWorkflow->id,
                        ];
                    }
                }
            }

            foreach ($tarikMajuByPk as $pkData) {
                $this->compileService->recompileFromTarikMaju($pkData['workflow'], $pkData['items']);
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
            if ($perubahan->tipe_perubahan !== 'tarik_maju' || ! $perubahan->pk04_anggaran_id) {
                continue;
            }

            Pk04AnggaranCatatanPerubahan::create([
                'pk04_anggaran_id' => $perubahan->pk04_anggaran_id,
                'pabd_workflow_id' => $pabdWorkflowId,
                'tipe_perubahan' => 'tarik_maju',
                'catatan_pemohon' => $perubahan->komentar,
                'catatan_approval' => $review->komentar_approval,
            ]);
        }
    }

    /**
     * Compute kode anggaran preview for a tarik_maju item.
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
            $statusLabel = null;
            if ($anggaran->status_item === 'ditarik_maju') {
                // Find which month it was pulled forward to
                $targetKegiatan = Pk04Anggaran::where('previous_anggaran_id', $anggaran->id)
                    ->first()?->pk04Kegiatan;
                $targetBulan = $targetKegiatan?->bulan;
                $statusLabel = $targetBulan
                    ? "Ditarik Maju ke Bln {$targetBulan}"
                    : 'Ditarik Maju';
            } elseif ($tipe === 'proposal') {
                $statusLabel = 'Di Luar Plafon';
            }

            $grouped[$programKey]['kegiatan'][$kegiatanKey]['anggaran'][] = [
                'pabd01_item_id' => $item->id,
                'pk04_anggaran_id' => $anggaran->id,
                'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
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
        // Walk history backward to find the most recent action that triggered a cycle-back to PABD01
        $cycleBackActions = ['approved', 'rejected'];
        $cycleBackSteps = ['PABD02B', 'PABD03'];

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i];
            if (in_array($entry['action'] ?? '', $cycleBackActions)
                && in_array($entry['step'] ?? '', $cycleBackSteps)) {
                return [
                    'step' => $entry['step'],
                    'step_label' => $this->engine->resolveDefinition(WorkflowType::PABD)->stepLabel($entry['step']),
                    'action' => $entry['action'],
                    'notes' => $entry['notes'] ?? null,
                    'by_name' => null, // Will be enriched by frontend from formatted history
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
                'ppLabel' => null, 'teamName' => $teamName,
                'plafon' => 0, 'accepted' => 0,
            ];
        }

        $teamId = $pabdWorkflow->team_id;
        $pp01 = $pabdWorkflow->ppWorkflow?->latestPp01();

        $plafon = (float) ($pp06->itemPlafonAnggaran()
            ->where('team_id', $teamId)
            ->value('plafon_anggaran') ?? 0);

        $accepted = (float) Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan.pk04ProgramTahunan.pkWorkflow', fn ($q) => $q
                ->where('team_id', $teamId)
                ->where('workspace_id', $pabdWorkflow->workspace_id)
                ->where('pp_workflow_id', $pabdWorkflow->pp_workflow_id)
                ->where('tipe', 'raker')
                ->whereNull('deleted_at')
            )
            ->where('status_item', 'active')
            ->sum('nominal_anggaran');

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

        return [
            'ppLabel' => "PP-{$pp01?->tahun} Revisi {$pp06->revision}",
            'teamName' => $teamName,
            'plafon' => $plafon,
            'accepted' => $accepted,
            'proposalAccepted' => $proposalAccepted,
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
}
