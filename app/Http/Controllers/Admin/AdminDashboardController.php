<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\HasDashboardStepNames;
use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
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
        $role = Role::with('permissions')->find($activeRoleId);

        // Step 1: PP06 + Plafon (all teams)
        $pp06Records = Pp06PeriodeTahunan::query()
            ->whereHas('ppWorkflow', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->with(['ppWorkflow:id', 'itemPlafonAnggaran.team:id,name'])
            ->orderBy('tahun', 'desc')
            ->get(['id', 'pp_workflow_id', 'tahun']);

        $availableYears = $pp06Records->pluck('tahun')->unique()->values()->all();

        if ($pp06Records->isEmpty()) {
            return Inertia::render('admin/index', [
                'year' => null,
                'availableYears' => [],
                'activeWorkflows' => [],
                'teamSummaries' => [],
                'teamOptions' => [],
                'monthlyData' => [],
            ]);
        }

        $selectedYear = $request->integer('year') ?: $availableYears[0];
        $pp06 = $pp06Records->firstWhere('tahun', $selectedYear);

        if (! $pp06) {
            $selectedYear = $availableYears[0];
            $pp06 = $pp06Records->firstWhere('tahun', $selectedYear);
        }

        $ppWorkflowId = $pp06->pp_workflow_id;

        // Build team map from plafon items
        $plafonItems = $pp06->itemPlafonAnggaran;
        $teamIds = $plafonItems->pluck('team_id')->all();
        $teamMap = []; // teamId => {name, plafon}
        foreach ($plafonItems as $item) {
            $teamMap[$item->team_id] = [
                'name' => $item->team?->name ?? 'Tim #'.$item->team_id,
                'plafon' => (float) $item->plafon_anggaran,
            ];
        }

        // Step 2: PP workflows (active, for work queue)
        $ppWorkflows = PpWorkflow::query()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'uuid', 'history']);

        // Step 3: PK workflows for all teams
        $pkWorkflows = PkWorkflow::query()
            ->whereIn('team_id', $teamIds)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->with('pk04ProgramTahunan:id,pk_workflow_id,revision')
            ->get(['id', 'uuid', 'team_id', 'tipe', 'history']);

        // Step 4: PK anggaran per bulan per team, split by tipe
        $pkAnggaranRaw = Pk04Anggaran::query()
            ->join('pk04_kegiatan', 'pk04_kegiatan.id', '=', 'pk04_anggaran.pk04_kegiatan_id')
            ->join('pk04_program_tahunan', 'pk04_program_tahunan.id', '=', 'pk04_kegiatan.pk04_program_tahunan_id')
            ->join('pk_workflows', 'pk_workflows.id', '=', 'pk04_program_tahunan.pk_workflow_id')
            ->whereIn('pk_workflows.team_id', $teamIds)
            ->where('pk_workflows.pp_workflow_id', $ppWorkflowId)
            ->whereNull('pk_workflows.deleted_at')
            ->where('pk04_anggaran.status_item', 'active')
            ->select(DB::raw('pk_workflows.team_id, pk_workflows.tipe, pk04_kegiatan.bulan, SUM(pk04_anggaran.nominal_anggaran) as total'))
            ->groupBy('pk_workflows.team_id', 'pk_workflows.tipe', 'pk04_kegiatan.bulan')
            ->get();

        // Step 5: PABD workflows + PABD05
        $pabdWorkflows = PabdWorkflow::query()
            ->whereIn('pabd_workflows.team_id', $teamIds)
            ->where('pabd_workflows.pp_workflow_id', $ppWorkflowId)
            ->leftJoin('pabd05_pengajuan_bulanan', 'pabd05_pengajuan_bulanan.pabd_workflow_id', '=', 'pabd_workflows.id')
            ->select([
                'pabd_workflows.id', 'pabd_workflows.uuid', 'pabd_workflows.team_id',
                'pabd_workflows.bulan_anggaran', 'pabd_workflows.tahun_anggaran',
                'pabd_workflows.history',
                'pabd05_pengajuan_bulanan.total_anggaran_dicairkan',
            ])
            ->get();

        // Step 6: PRBL workflows + PRBL05
        $prblWorkflows = PrblWorkflow::query()
            ->whereIn('prbl_workflows.team_id', $teamIds)
            ->where('prbl_workflows.pp_workflow_id', $ppWorkflowId)
            ->leftJoin('prbl05_laporan_bulanan', 'prbl05_laporan_bulanan.prbl_workflow_id', '=', 'prbl_workflows.id')
            ->select([
                'prbl_workflows.id', 'prbl_workflows.uuid', 'prbl_workflows.team_id',
                'prbl_workflows.bulan_laporan', 'prbl_workflows.tahun_laporan',
                'prbl_workflows.history',
                'prbl05_laporan_bulanan.total_realisasi',
                'prbl05_laporan_bulanan.total_refund',
            ])
            ->get();

        $engine = new WorkflowEngine;
        $permissions = $role?->permissions->pluck('name')->all() ?? [];

        // Derived: Active workflows (Section 1)
        $activeWorkflows = $this->buildActiveWorkflows(
            $engine, $permissions, $ppWorkflows, $pkWorkflows, $pabdWorkflows, $prblWorkflows, $teamMap, $selectedYear,
        );

        // Derived: Per-team summary (Section 2)
        $teamSummaries = $this->buildTeamSummaries($engine, $teamMap, $pkAnggaranRaw, $pabdWorkflows, $prblWorkflows);

        // Derived: Monthly financial data (Section 3)
        [$teamOptions, $monthlyData] = $this->buildMonthlyData($teamMap, $pkAnggaranRaw, $pabdWorkflows, $prblWorkflows);

        return Inertia::render('admin/index', [
            'year' => $selectedYear,
            'availableYears' => $availableYears,
            'activeWorkflows' => $activeWorkflows,
            'teamSummaries' => $teamSummaries,
            'teamOptions' => $teamOptions,
            'monthlyData' => $monthlyData,
        ]);
    }

    /**
     * Build all active workflows for the work queue table.
     *
     * @param  list<string>  $permissions
     * @param  array<int, array{name: string, plafon: float}>  $teamMap
     * @return list<array<string, mixed>>
     */
    private function buildActiveWorkflows(
        WorkflowEngine $engine,
        array $permissions,
        $ppWorkflows,
        $pkWorkflows,
        $pabdWorkflows,
        $prblWorkflows,
        array $teamMap,
        int $year,
    ): array {
        $ppDef = $engine->resolveDefinition(WorkflowType::PP);
        $pkDef = $engine->resolveDefinition(WorkflowType::PK);
        $pabdDef = $engine->resolveDefinition(WorkflowType::PABD);
        $prblDef = $engine->resolveDefinition(WorkflowType::PRBL);

        $items = [];

        // PP (workspace-level)
        foreach ($ppWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $pp01 = $wf->latestPp01();
            $label = 'PP-'.($pp01?->tahun ?? $year);
            $currentSteps = $engine->getCurrentSteps($ppDef, $history);
            $statuses = $engine->getStepStatuses($ppDef, $history);

            $items[] = $this->buildWorkflowItem(
                'pp', $label, $wf->id, $currentSteps, $statuses, $permissions, null, null, 0,
            );
        }

        // PK
        foreach ($pkWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $teamName = $teamMap[$wf->team_id]['name'] ?? 'Tim';
            $label = "PK-{$teamName}-{$year}";
            $currentSteps = $engine->getCurrentSteps($pkDef, $history);
            $statuses = $engine->getStepStatuses($pkDef, $history);

            $items[] = $this->buildWorkflowItem(
                'pk', $label, $wf->id, $currentSteps, $statuses, $permissions, $wf->team_id, $teamName, 0,
            );
        }

        // PABD
        foreach ($pabdWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $teamName = $teamMap[$wf->team_id]['name'] ?? 'Tim';
            $bulan = str_pad((string) $wf->bulan_anggaran, 2, '0', STR_PAD_LEFT);
            $label = "PABD-{$teamName}-{$bulan}/{$wf->tahun_anggaran}";
            $currentSteps = $engine->getCurrentSteps($pabdDef, $history);
            $statuses = $engine->getStepStatuses($pabdDef, $history);

            $items[] = $this->buildWorkflowItem(
                'pabd', $label, $wf->id, $currentSteps, $statuses, $permissions, $wf->team_id, $teamName, $wf->bulan_anggaran,
            );
        }

        // PRBL
        foreach ($prblWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $teamName = $teamMap[$wf->team_id]['name'] ?? 'Tim';
            $bulan = str_pad((string) $wf->bulan_laporan, 2, '0', STR_PAD_LEFT);
            $label = "PRBL-{$teamName}-{$bulan}/{$wf->tahun_laporan}";
            $currentSteps = $engine->getCurrentSteps($prblDef, $history);
            $statuses = $engine->getStepStatuses($prblDef, $history);

            $items[] = $this->buildWorkflowItem(
                'prbl', $label, $wf->id, $currentSteps, $statuses, $permissions, $wf->team_id, $teamName, $wf->bulan_laporan,
            );
        }

        // Sort: canAct first, then by team name, then PABD→PRBL→PK→PP, then by bulan
        usort($items, function (array $a, array $b): int {
            // canAct first
            if ($a['canAct'] !== $b['canAct']) {
                return $b['canAct'] <=> $a['canAct'];
            }
            // Team name alphabetical (null = PP = last)
            $aName = $a['teamName'] ?? "\xFF";
            $bName = $b['teamName'] ?? "\xFF";
            if ($aName !== $bName) {
                return strcasecmp($aName, $bName);
            }
            // Type priority
            if ($a['_sortType'] !== $b['_sortType']) {
                return $a['_sortType'] <=> $b['_sortType'];
            }

            // Bulan asc
            return $a['_sortBulan'] <=> $b['_sortBulan'];
        });

        return array_map(function (array $item): array {
            unset($item['_sortType'], $item['_sortBulan']);

            return $item;
        }, $items);
    }

    /**
     * Build a single active workflow item.
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
        ?int $teamId,
        ?string $teamName,
        int $bulan,
    ): array {
        $scope = $this->getRoleScope($permissions, $type);
        $showUrl = $scope ? "/{$scope}/workflows/{$type}/{$workflowId}" : "/admin/workflows/{$type}/{$workflowId}";

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
            'teamId' => $teamId,
            'teamName' => $teamName,
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

    /**
     * Build per-team summary rows.
     *
     * @param  array<int, array{name: string, plafon: float}>  $teamMap
     * @return list<array<string, mixed>>
     */
    private function buildTeamSummaries(
        WorkflowEngine $engine,
        array $teamMap,
        $pkAnggaranRaw,
        $pabdWorkflows,
        $prblWorkflows,
    ): array {
        // PK anggaran totals per team per tipe
        $pkTotals = []; // [teamId][tipe] => total
        $monthsWithAnggaran = []; // [teamId] => set of bulan
        foreach ($pkAnggaranRaw as $row) {
            $tid = $row->team_id;
            $tipe = $row->tipe;
            $pkTotals[$tid][$tipe] = ($pkTotals[$tid][$tipe] ?? 0) + (float) $row->total;

            if (! isset($monthsWithAnggaran[$tid])) {
                $monthsWithAnggaran[$tid] = [];
            }
            $monthsWithAnggaran[$tid][$row->bulan] = true;
        }

        // PABD completed count per team
        $pabdCompleted = []; // [teamId] => count
        foreach ($pabdWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) === 'completed') {
                $pabdCompleted[$wf->team_id] = ($pabdCompleted[$wf->team_id] ?? 0) + 1;
            }
        }

        // PRBL completed count + realisasi per team
        $prblCompleted = []; // [teamId] => count
        $realisasiTotals = []; // [teamId] => total
        foreach ($prblWorkflows as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) === 'completed') {
                $prblCompleted[$wf->team_id] = ($prblCompleted[$wf->team_id] ?? 0) + 1;
            }
            if ($wf->total_realisasi !== null) {
                $realisasiTotals[$wf->team_id] = ($realisasiTotals[$wf->team_id] ?? 0) + (float) $wf->total_realisasi;
            }
        }

        $rows = [];
        foreach ($teamMap as $teamId => $info) {
            $expectedMonths = count($monthsWithAnggaran[$teamId] ?? []);

            $rows[] = [
                'teamId' => $teamId,
                'teamName' => $info['name'],
                'plafon' => $info['plafon'],
                'totalPkRaker' => (float) ($pkTotals[$teamId]['raker'] ?? 0),
                'totalPkProposal' => (float) ($pkTotals[$teamId]['proposal'] ?? 0),
                'pabdCompleted' => $pabdCompleted[$teamId] ?? 0,
                'pabdTotal' => $expectedMonths,
                'prblCompleted' => $prblCompleted[$teamId] ?? 0,
                'prblTotal' => $expectedMonths,
                'totalRealisasi' => (float) ($realisasiTotals[$teamId] ?? 0),
            ];
        }

        // Sort alphabetically by team name
        usort($rows, fn (array $a, array $b) => strcasecmp($a['teamName'], $b['teamName']));

        return $rows;
    }

    /**
     * Build monthly financial data (per-team per-month) and team options for filter.
     *
     * @param  array<int, array{name: string, plafon: float}>  $teamMap
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function buildMonthlyData(
        array $teamMap,
        $pkAnggaranRaw,
        $pabdWorkflows,
        $prblWorkflows,
    ): array {
        // Team options for filter dropdown
        $teamOptions = [];
        foreach ($teamMap as $teamId => $info) {
            $teamOptions[] = ['id' => $teamId, 'name' => $info['name']];
        }
        usort($teamOptions, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        // PK anggaran: [teamId][tipe][bulan] => total
        $pkByTeamTipeBulan = [];
        foreach ($pkAnggaranRaw as $row) {
            $pkByTeamTipeBulan[$row->team_id][$row->tipe][$row->bulan] = (float) $row->total;
        }

        // PABD05 dicairkan: [teamId][bulan] => total
        $dicairkanByTeamBulan = [];
        foreach ($pabdWorkflows as $wf) {
            if ($wf->total_anggaran_dicairkan !== null) {
                $tid = $wf->team_id;
                $bulan = $wf->bulan_anggaran;
                $dicairkanByTeamBulan[$tid][$bulan] = ($dicairkanByTeamBulan[$tid][$bulan] ?? 0) + (float) $wf->total_anggaran_dicairkan;
            }
        }

        // PRBL05 realisasi/refund: [teamId][bulan] => {realisasi, refund}
        $prblByTeamBulan = [];
        foreach ($prblWorkflows as $wf) {
            $tid = $wf->team_id;
            $bulan = $wf->bulan_laporan;
            if ($wf->total_realisasi !== null) {
                $prblByTeamBulan[$tid][$bulan]['realisasi'] = ($prblByTeamBulan[$tid][$bulan]['realisasi'] ?? 0) + (float) $wf->total_realisasi;
            }
            if ($wf->total_refund !== null) {
                $prblByTeamBulan[$tid][$bulan]['refund'] = ($prblByTeamBulan[$tid][$bulan]['refund'] ?? 0) + (float) $wf->total_refund;
            }
        }

        // Build flat per-team per-month rows
        $monthlyData = [];
        foreach ($teamMap as $teamId => $info) {
            for ($bulan = 1; $bulan <= 12; $bulan++) {
                $pkRaker = $pkByTeamTipeBulan[$teamId]['raker'][$bulan] ?? 0;
                $pkProposal = $pkByTeamTipeBulan[$teamId]['proposal'][$bulan] ?? 0;

                // Only include rows where there's any data (PK anggaran or PABD/PRBL data)
                $dicairkan = $dicairkanByTeamBulan[$teamId][$bulan] ?? null;
                $realisasi = $prblByTeamBulan[$teamId][$bulan]['realisasi'] ?? null;
                $refund = $prblByTeamBulan[$teamId][$bulan]['refund'] ?? null;

                $monthlyData[] = [
                    'teamId' => $teamId,
                    'bulan' => $bulan,
                    'pkRaker' => (float) $pkRaker,
                    'pkProposal' => (float) $pkProposal,
                    'dicairkan' => $dicairkan,
                    'realisasi' => $realisasi,
                    'refund' => $refund,
                ];
            }
        }

        return [$teamOptions, $monthlyData];
    }
}
