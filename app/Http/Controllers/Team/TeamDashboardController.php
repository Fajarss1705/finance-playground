<?php

namespace App\Http\Controllers\Team;

use App\Concerns\HasDashboardStepNames;
use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TeamDashboardController extends Controller
{
    use HasDashboardStepNames;

    private const BULAN_LABELS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __invoke(Request $request): Response
    {
        $workspaceId = (int) session('active_workspace_id');
        $activeRoleId = (int) session('active_role_id');
        $role = Role::with(['team', 'permissions'])->find($activeRoleId);
        $team = $role?->team;

        if (! $team) {
            return Inertia::render('team/index', [
                'team' => null,
                'year' => null,
                'availableYears' => [],
                'pkStatus' => null,
                'calendar' => [],
                'anggaranRows' => [],
                'anggaranTotals' => ['direncanakan' => 0, 'dicairkan' => 0, 'realisasi' => 0, 'refund' => 0],
                'activeWorkflows' => [],
            ]);
        }

        $teamId = $team->id;

        // Step 1: PP06 periods for year selector + plafon
        $pp06Records = Pp06PeriodeTahunan::query()
            ->whereHas('ppWorkflow', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->with(['ppWorkflow:id', 'itemPlafonAnggaran' => fn ($q) => $q->where('team_id', $teamId)])
            ->orderBy('tahun', 'desc')
            ->get(['id', 'pp_workflow_id', 'tahun']);

        $availableYears = $pp06Records->pluck('tahun')->unique()->values()->all();

        if ($pp06Records->isEmpty()) {
            return Inertia::render('team/index', [
                'team' => ['id' => $teamId, 'name' => $team->name],
                'year' => null,
                'availableYears' => [],
                'pkStatus' => null,
                'calendar' => [],
                'anggaranRows' => [],
                'anggaranTotals' => ['direncanakan' => 0, 'dicairkan' => 0, 'realisasi' => 0, 'refund' => 0],
                'activeWorkflows' => [],
            ]);
        }

        $selectedYear = $request->integer('year') ?: $availableYears[0];
        $pp06 = $pp06Records->firstWhere('tahun', $selectedYear);

        if (! $pp06) {
            $selectedYear = $availableYears[0];
            $pp06 = $pp06Records->firstWhere('tahun', $selectedYear);
        }

        $ppWorkflowId = $pp06->pp_workflow_id;
        $plafon = $pp06->itemPlafonAnggaran->first()?->plafon_anggaran ?? 0;
        $hasPlafon = $pp06->itemPlafonAnggaran->isNotEmpty();

        // Step 2: PK workflows for status bar
        $pkStatus = $this->buildPkStatus($teamId, $ppWorkflowId, $plafon, $hasPlafon, $role);

        // Step 3: Direncanakan per bulan
        $direncanakan = $this->queryDirencanakanPerBulan($teamId, $ppWorkflowId);

        // Step 4: PABD workflows + PABD05
        $pabdWorkflows = PabdWorkflow::query()
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->leftJoin('pabd05_pengajuan_bulanan', 'pabd05_pengajuan_bulanan.pabd_workflow_id', '=', 'pabd_workflows.id')
            ->select([
                'pabd_workflows.id', 'pabd_workflows.uuid', 'pabd_workflows.bulan_anggaran',
                'pabd_workflows.tahun_anggaran', 'pabd_workflows.history',
                'pabd05_pengajuan_bulanan.total_anggaran_dicairkan',
            ])
            ->get();

        // Step 5: PRBL workflows + PRBL05
        $prblWorkflows = PrblWorkflow::query()
            ->where('prbl_workflows.team_id', $teamId)
            ->where('prbl_workflows.pp_workflow_id', $ppWorkflowId)
            ->leftJoin('prbl05_laporan_bulanan', 'prbl05_laporan_bulanan.prbl_workflow_id', '=', 'prbl_workflows.id')
            ->select([
                'prbl_workflows.id', 'prbl_workflows.uuid', 'prbl_workflows.bulan_laporan',
                'prbl_workflows.tahun_laporan', 'prbl_workflows.history',
                'prbl05_laporan_bulanan.total_realisasi',
                'prbl05_laporan_bulanan.total_refund',
            ])
            ->get();

        $engine = new WorkflowEngine;

        // Build calendar + anggaran
        $calendar = $this->buildCalendar($engine, $pabdWorkflows, $prblWorkflows, $direncanakan);
        [$anggaranRows, $anggaranTotals] = $this->buildAnggaranTable($direncanakan, $pabdWorkflows, $prblWorkflows);

        // Build active workflows
        $activeWorkflows = $this->buildActiveWorkflows(
            $engine, $role, $teamId, $ppWorkflowId, $pabdWorkflows, $prblWorkflows, $selectedYear,
        );

        return Inertia::render('team/index', [
            'team' => ['id' => $teamId, 'name' => $team->name],
            'year' => $selectedYear,
            'availableYears' => $availableYears,
            'pkStatus' => $pkStatus,
            'calendar' => $calendar,
            'anggaranRows' => $anggaranRows,
            'anggaranTotals' => $anggaranTotals,
            'activeWorkflows' => $activeWorkflows,
        ]);
    }

    /**
     * Build PK status summary bar.
     *
     * @return array<string, mixed>|null
     */
    private function buildPkStatus(int $teamId, int $ppWorkflowId, float|string $plafon, bool $hasPlafon, Role $role): ?array
    {
        if (! $hasPlafon) {
            return [
                'rakerStatus' => 'tidak_tersedia',
                'rakerRevision' => 0,
                'rakerCurrentStep' => null,
                'rakerUrl' => null,
                'proposalCount' => 0,
                'plafon' => 0,
            ];
        }

        $pkWorkflows = PkWorkflow::query()
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->with('pk04ProgramTahunan:id,pk_workflow_id,revision')
            ->get(['id', 'uuid', 'tipe', 'history']);

        $raker = $pkWorkflows->firstWhere('tipe', 'raker');
        $proposalCount = $pkWorkflows->where('tipe', 'proposal')->count();

        $engine = new WorkflowEngine;
        $pkDef = $engine->resolveDefinition(WorkflowType::PK);
        $permissions = $role->permissions->pluck('name')->all();
        $scope = $this->getRoleScope($permissions, 'pk');

        if (! $raker) {
            return [
                'rakerStatus' => 'belum_ada',
                'rakerRevision' => 0,
                'rakerCurrentStep' => null,
                'rakerUrl' => null,
                'proposalCount' => $proposalCount,
                'plafon' => (float) $plafon,
            ];
        }

        $history = $raker->history ?? [];
        $status = $engine->getWorkflowStatus($history);
        $latestPk04 = $raker->pk04ProgramTahunan->sortByDesc('revision')->first();

        $rakerUrl = $scope ? "/{$scope}/workflows/pk/{$raker->id}" : null;

        if ($status === 'completed') {
            return [
                'rakerStatus' => 'selesai',
                'rakerRevision' => $latestPk04?->revision ?? 0,
                'rakerCurrentStep' => null,
                'rakerUrl' => $rakerUrl,
                'proposalCount' => $proposalCount,
                'plafon' => (float) $plafon,
            ];
        }

        // Active
        $currentSteps = $engine->getCurrentSteps($pkDef, $history);
        $currentStep = $currentSteps[0] ?? null;

        return [
            'rakerStatus' => 'aktif',
            'rakerRevision' => $latestPk04?->revision ?? 0,
            'rakerCurrentStep' => $currentStep,
            'rakerUrl' => $rakerUrl,
            'proposalCount' => $proposalCount,
            'plafon' => (float) $plafon,
        ];
    }

    /**
     * Query Direncanakan per bulan from pk04_anggaran.
     *
     * @return array<int, float>
     */
    private function queryDirencanakanPerBulan(int $teamId, int $ppWorkflowId): array
    {
        return Pk04Anggaran::query()
            ->join('pk04_kegiatan', 'pk04_kegiatan.id', '=', 'pk04_anggaran.pk04_kegiatan_id')
            ->join('pk04_program_tahunan', 'pk04_program_tahunan.id', '=', 'pk04_kegiatan.pk04_program_tahunan_id')
            ->join('pk_workflows', 'pk_workflows.id', '=', 'pk04_program_tahunan.pk_workflow_id')
            ->where('pk_workflows.team_id', $teamId)
            ->where('pk_workflows.pp_workflow_id', $ppWorkflowId)
            ->whereNull('pk_workflows.deleted_at')
            ->where('pk04_anggaran.status_item', 'active')
            ->select(DB::raw('pk04_kegiatan.bulan, SUM(pk04_anggaran.nominal_anggaran) as total'))
            ->groupBy('pk04_kegiatan.bulan')
            ->pluck('total', 'bulan')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Build the 12-month calendar grid.
     *
     * @param  \Illuminate\Support\Collection  $pabdWorkflows
     * @param  \Illuminate\Support\Collection  $prblWorkflows
     * @param  array<int, float>  $direncanakan
     * @return list<array<string, mixed>>
     */
    private function buildCalendar(
        WorkflowEngine $engine,
        $pabdWorkflows,
        $prblWorkflows,
        array $direncanakan,
    ): array {
        $pabdDef = $engine->resolveDefinition(WorkflowType::PABD);
        $prblDef = $engine->resolveDefinition(WorkflowType::PRBL);

        $pabdByBulan = $pabdWorkflows->keyBy('bulan_anggaran');
        $prblByBulan = $prblWorkflows->keyBy('bulan_laporan');

        $calendar = [];

        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $pabdWf = $pabdByBulan->get($bulan);
            $prblWf = $prblByBulan->get($bulan);
            $hasBudget = ($direncanakan[$bulan] ?? 0) > 0;

            $calendar[] = [
                'bulan' => $bulan,
                'bulanLabel' => self::BULAN_LABELS[$bulan],
                'pabd' => $this->resolveCalendarCell($engine, $pabdDef, $pabdWf, $hasBudget, 'pabd'),
                'prbl' => $this->resolveCalendarCell($engine, $prblDef, $prblWf, $hasBudget, 'prbl'),
            ];
        }

        return $calendar;
    }

    /**
     * Resolve a single calendar cell status.
     *
     * @return array<string, mixed>
     */
    private function resolveCalendarCell(
        WorkflowEngine $engine,
        $definition,
        $workflow,
        bool $hasBudget,
        string $type,
    ): array {
        if (! $workflow) {
            return [
                'state' => $hasBudget ? 'belum' : 'skip',
                'currentStep' => null,
                'currentStepName' => null,
                'url' => null,
            ];
        }

        $history = $workflow->history ?? [];
        $status = $engine->getWorkflowStatus($history);

        if ($status === 'completed') {
            return [
                'state' => 'selesai',
                'currentStep' => null,
                'currentStepName' => null,
                'url' => "/team/workflows/{$type}/{$workflow->id}",
            ];
        }

        // Active
        $currentSteps = $engine->getCurrentSteps($definition, $history);
        $step = $currentSteps[0] ?? null;

        return [
            'state' => 'aktif',
            'currentStep' => $step,
            'currentStepName' => $step ? (self::STEP_NAMES[$step] ?? $step) : null,
            'url' => "/team/workflows/{$type}/{$workflow->id}",
        ];
    }

    /**
     * Build anggaran table rows and totals.
     *
     * @param  array<int, float>  $direncanakan
     * @param  \Illuminate\Support\Collection  $pabdWorkflows
     * @param  \Illuminate\Support\Collection  $prblWorkflows
     * @return array{list<array<string, mixed>>, array<string, float>}
     */
    private function buildAnggaranTable(array $direncanakan, $pabdWorkflows, $prblWorkflows): array
    {
        $pabdByBulan = $pabdWorkflows->keyBy('bulan_anggaran');
        $prblByBulan = $prblWorkflows->keyBy('bulan_laporan');

        $rows = [];
        $totals = ['direncanakan' => 0.0, 'dicairkan' => 0.0, 'realisasi' => 0.0, 'refund' => 0.0];

        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $dir = (float) ($direncanakan[$bulan] ?? 0);
            $pabdWf = $pabdByBulan->get($bulan);
            $prblWf = $prblByBulan->get($bulan);

            $dicairkan = $pabdWf?->total_anggaran_dicairkan !== null ? (float) $pabdWf->total_anggaran_dicairkan : null;
            $realisasi = $prblWf?->total_realisasi !== null ? (float) $prblWf->total_realisasi : null;
            $refund = $prblWf?->total_refund !== null ? (float) $prblWf->total_refund : null;

            $rows[] = [
                'bulan' => $bulan,
                'bulanLabel' => self::BULAN_LABELS[$bulan],
                'direncanakan' => $dir,
                'dicairkan' => $dicairkan,
                'realisasi' => $realisasi,
                'refund' => $refund,
            ];

            $totals['direncanakan'] += $dir;
            if ($dicairkan !== null) {
                $totals['dicairkan'] += $dicairkan;
            }
            if ($realisasi !== null) {
                $totals['realisasi'] += $realisasi;
            }
            if ($refund !== null) {
                $totals['refund'] += $refund;
            }
        }

        return [$rows, $totals];
    }

    /**
     * Build active workflows list with canAct determination.
     *
     * @param  \Illuminate\Support\Collection  $pabdWorkflows
     * @param  \Illuminate\Support\Collection  $prblWorkflows
     * @return list<array<string, mixed>>
     */
    private function buildActiveWorkflows(
        WorkflowEngine $engine,
        Role $role,
        int $teamId,
        int $ppWorkflowId,
        $pabdWorkflows,
        $prblWorkflows,
        int $year,
    ): array {
        $permissions = $role->permissions->pluck('name')->all();
        $pabdDef = $engine->resolveDefinition(WorkflowType::PABD);
        $prblDef = $engine->resolveDefinition(WorkflowType::PRBL);
        $pkDef = $engine->resolveDefinition(WorkflowType::PK);

        $items = [];

        // PABD workflows
        foreach ($pabdWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $bulan = str_pad((string) $wf->bulan_anggaran, 2, '0', STR_PAD_LEFT);
            $label = "PABD-{$role->team->name}-{$bulan}/{$wf->tahun_anggaran}";
            $currentSteps = $engine->getCurrentSteps($pabdDef, $history);
            $statuses = $engine->getStepStatuses($pabdDef, $history);

            $items[] = $this->buildWorkflowItem(
                'pabd', $label, $wf->id, $currentSteps, $statuses, $permissions, $wf->bulan_anggaran,
            );
        }

        // PRBL workflows
        foreach ($prblWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $bulan = str_pad((string) $wf->bulan_laporan, 2, '0', STR_PAD_LEFT);
            $label = "PRBL-{$role->team->name}-{$bulan}/{$wf->tahun_laporan}";
            $currentSteps = $engine->getCurrentSteps($prblDef, $history);
            $statuses = $engine->getStepStatuses($prblDef, $history);

            $items[] = $this->buildWorkflowItem(
                'prbl', $label, $wf->id, $currentSteps, $statuses, $permissions, $wf->bulan_laporan,
            );
        }

        // PK raker (if still active)
        $pkRaker = PkWorkflow::query()
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->where('tipe', 'raker')
            ->first(['id', 'uuid', 'tipe', 'history']);

        if ($pkRaker) {
            $history = $pkRaker->history ?? [];
            if ($engine->getWorkflowStatus($history) === 'active') {
                $label = "PK-{$role->team->name}-{$year}";
                $currentSteps = $engine->getCurrentSteps($pkDef, $history);
                $statuses = $engine->getStepStatuses($pkDef, $history);

                $items[] = $this->buildWorkflowItem(
                    'pk', $label, $pkRaker->id, $currentSteps, $statuses, $permissions, 0,
                );
            }
        }

        // Sort: PABD first, then PRBL, then PK. Within same type, by bulan asc.
        usort($items, function (array $a, array $b): int {
            if ($a['_sortType'] !== $b['_sortType']) {
                return $a['_sortType'] <=> $b['_sortType'];
            }

            return $a['_sortBulan'] <=> $b['_sortBulan'];
        });

        return array_map(function (array $item): array {
            unset($item['_sortType'], $item['_sortBulan']);

            return $item;
        }, $items);
    }

    /**
     * Build a single active workflow item with canAct determination.
     *
     * @param  list<string>  $currentSteps
     * @param  array<string, array>  $statuses
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function buildWorkflowItem(
        string $type,
        string $label,
        int $workflowId,
        array $currentSteps,
        array $statuses,
        array $permissions,
        int $bulan,
    ): array {
        $scope = $this->getRoleScope($permissions, $type);
        $showUrl = $scope ? "/{$scope}/workflows/{$type}/{$workflowId}" : "/team/workflows/{$type}/{$workflowId}";

        $canAct = false;
        $actionUrl = null;
        $stepInfos = [];

        foreach ($currentSteps as $step) {
            $stepInfos[] = [
                'stepCode' => $step,
                'stepName' => self::STEP_NAMES[$step] ?? $step,
            ];

            if (! $canAct && $scope && $this->hasActionablePermission($permissions, $scope, $type, $step)) {
                $canAct = true;
                $dataId = $statuses[$step]['dataId'] ?? null;
                $actionUrl = $this->buildStepUrl($scope, $type, $workflowId, $step, $dataId);
            }
        }

        return [
            'workflowType' => $type,
            'workflowLabel' => $label,
            'showUrl' => $showUrl,
            'canAct' => $canAct,
            'actionUrl' => $actionUrl,
            'currentSteps' => $stepInfos,
            '_sortType' => $this->taskSortPriority($type),
            '_sortBulan' => $bulan,
        ];
    }
}
