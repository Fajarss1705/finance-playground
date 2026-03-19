<?php

namespace App\Http\Controllers\Workflows;

use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\Pabd01DraftRequest;
use App\Http\Requests\Workflows\Pabd01SubmitRequest;
use App\Http\Requests\Workflows\PabdCommentRequest;
use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd02aData;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Role;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Http\RedirectResponse;
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
