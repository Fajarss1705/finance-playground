<?php

namespace App\Http\Controllers;

use App\Concerns\HasDashboardStepNames;
use App\Enums\WorkflowType;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Services\WorkflowEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PersonalDashboardController extends Controller
{
    use HasDashboardStepNames;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $workspaceId = (int) session('active_workspace_id');
        $activeRoleId = (int) session('active_role_id');

        $roles = $user->roles()
            ->with(['permissions', 'team.organization.workspaces'])
            ->get();

        if ($roles->isEmpty()) {
            return Inertia::render('personal/index', [
                'activeRole' => null,
                'activeRoleTasks' => [],
                'otherRoles' => [],
            ]);
        }

        $activeRole = $roles->firstWhere('id', $activeRoleId);

        $engine = new WorkflowEngine;
        $workflowData = $this->loadActiveWorkflows($engine, $workspaceId);

        $allRoleTasks = [];
        foreach ($roles as $role) {
            $allRoleTasks[$role->id] = $this->findTasksForRole($role, $workflowData);
        }

        $activeRoleTasks = $allRoleTasks[$activeRoleId] ?? [];

        $otherRoles = $roles
            ->reject(fn (Role $r) => $r->id === $activeRoleId)
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'teamName' => $r->team->name,
                'workspaceId' => $r->team->organization->workspaces->contains('id', $workspaceId)
                    ? $workspaceId
                    : $r->team->organization->workspaces->first()?->id,
                'tasks' => $allRoleTasks[$r->id] ?? [],
            ])
            ->sortByDesc(fn (array $r) => count($r['tasks']))
            ->values()
            ->all();

        return Inertia::render('personal/index', [
            'activeRole' => $activeRole ? [
                'id' => $activeRole->id,
                'name' => $activeRole->name,
                'teamName' => $activeRole->team->name,
            ] : null,
            'activeRoleTasks' => $activeRoleTasks,
            'otherRoles' => $otherRoles,
        ]);
    }

    /**
     * Load all active (non-completed) workflows in workspace with their step statuses.
     *
     * @return list<array{type: string, workflow: \Illuminate\Database\Eloquent\Model, statuses: array, teamId: ?int, label: string, history: array}>
     */
    private function loadActiveWorkflows(WorkflowEngine $engine, int $workspaceId): array
    {
        $result = [];

        $ppDef = $engine->resolveDefinition(WorkflowType::PP);
        $pkDef = $engine->resolveDefinition(WorkflowType::PK);
        $pabdDef = $engine->resolveDefinition(WorkflowType::PABD);
        $prblDef = $engine->resolveDefinition(WorkflowType::PRBL);

        // PP (workspace-level, no team)
        foreach (PpWorkflow::where('workspace_id', $workspaceId)->get() as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $pp01 = $wf->latestPp01();

            $result[] = [
                'type' => 'pp',
                'workflow' => $wf,
                'statuses' => $engine->getStepStatuses($ppDef, $history),
                'teamId' => null,
                'label' => 'PP-'.($pp01?->tahun ?? ''),
                'history' => $history,
            ];
        }

        // PK (team-scoped)
        foreach (PkWorkflow::where('workspace_id', $workspaceId)->with('team')->get() as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $result[] = [
                'type' => 'pk',
                'workflow' => $wf,
                'statuses' => $engine->getStepStatuses($pkDef, $history),
                'teamId' => $wf->team_id,
                'label' => 'PK-'.($wf->team?->name ?? ''),
                'history' => $history,
            ];
        }

        // PABD (team-scoped, monthly)
        foreach (PabdWorkflow::where('workspace_id', $workspaceId)->with('team')->get() as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $bulan = str_pad((string) $wf->bulan_anggaran, 2, '0', STR_PAD_LEFT);

            $result[] = [
                'type' => 'pabd',
                'workflow' => $wf,
                'statuses' => $engine->getStepStatuses($pabdDef, $history),
                'teamId' => $wf->team_id,
                'label' => "PABD-{$wf->team?->name}-{$bulan}/{$wf->tahun_anggaran}",
                'history' => $history,
            ];
        }

        // PRBL (team-scoped, monthly)
        foreach (PrblWorkflow::where('workspace_id', $workspaceId)->with('team')->get() as $wf) {
            $history = $wf->history ?? [];
            if ($engine->getWorkflowStatus($history) !== 'active') {
                continue;
            }

            $bulan = str_pad((string) $wf->bulan_laporan, 2, '0', STR_PAD_LEFT);

            $result[] = [
                'type' => 'prbl',
                'workflow' => $wf,
                'statuses' => $engine->getStepStatuses($prblDef, $history),
                'teamId' => $wf->team_id,
                'label' => "PRBL-{$wf->team?->name}-{$bulan}/{$wf->tahun_laporan}",
                'history' => $history,
            ];
        }

        return $result;
    }

    /**
     * Find all actionable tasks for a given role across all active workflows.
     *
     * @param  list<array{type: string, workflow: \Illuminate\Database\Eloquent\Model, statuses: array, teamId: ?int, label: string, history: array}>  $workflowData
     * @return list<array{workflowType: string, workflowLabel: string, stepCode: string, stepName: string, statusHint: string, url: string}>
     */
    private function findTasksForRole(Role $role, array $workflowData): array
    {
        $permissions = $role->permissions->pluck('name')->all();
        $tasks = [];

        foreach ($workflowData as $wd) {
            $scope = $this->getRoleScope($permissions, $wd['type']);
            if ($scope === null) {
                continue;
            }

            // Team scope: filter to own team only
            if ($scope === 'team' && $wd['teamId'] !== null && $wd['teamId'] !== $role->team_id) {
                continue;
            }

            foreach ($wd['statuses'] as $stepCode => $stepInfo) {
                if ($stepInfo['status'] !== 'active') {
                    continue;
                }
                if (! isset(self::STEP_NAMES[$stepCode])) {
                    continue;
                }
                if (! $this->hasActionablePermission($permissions, $scope, $wd['type'], $stepCode)) {
                    continue;
                }

                $tasks[] = [
                    'workflowType' => $wd['type'],
                    'workflowLabel' => $wd['label'],
                    'stepCode' => $stepCode,
                    'stepName' => self::STEP_NAMES[$stepCode],
                    'statusHint' => $this->resolveStatusHint(
                        $stepCode,
                        $wd['history'],
                        $permissions,
                        $scope,
                        $wd['type'],
                    ),
                    'url' => $this->buildStepUrl(
                        $scope,
                        $wd['type'],
                        $wd['workflow']->id,
                        $stepCode,
                        $stepInfo['dataId'],
                    ),
                    '_sortType' => $this->taskSortPriority($wd['type']),
                    '_sortDate' => $wd['workflow']->created_at?->toIso8601String() ?? '',
                ];
            }
        }

        // Sort: PABD/PRBL first, then PK, then PP. Within same type, by created_at desc.
        usort($tasks, function (array $a, array $b): int {
            if ($a['_sortType'] !== $b['_sortType']) {
                return $a['_sortType'] <=> $b['_sortType'];
            }

            return $b['_sortDate'] <=> $a['_sortDate'];
        });

        // Strip internal sort keys
        return array_map(function (array $task): array {
            unset($task['_sortType'], $task['_sortDate']);

            return $task;
        }, $tasks);
    }

    /**
     * Resolve the status hint text for a task item.
     *
     * Priority: Draft > Dikembalikan > Menunggu review > Menunggu diisi
     *
     * @param  list<string>  $permissions
     */
    private function resolveStatusHint(string $stepCode, array $history, array $permissions, string $scope, string $type): string
    {
        // Draft always takes priority
        if ($this->hasDraftInCurrentCycle($stepCode, $history)) {
            return 'Draft';
        }

        // Re-entry after rejection
        if ($this->isRejectionReentry($stepCode, $history)) {
            return 'Dikembalikan';
        }

        // Approval steps
        $stepLower = strtolower($stepCode);
        $hasApprove = in_array("{$scope}.workflows.{$type}.{$stepLower}.approve", $permissions, true);
        $hasReject = in_array("{$scope}.workflows.{$type}.{$stepLower}.reject", $permissions, true);

        if ($hasApprove || $hasReject) {
            return 'Menunggu review';
        }

        return 'Menunggu diisi';
    }

    /**
     * Check if a step has a 'drafted' action in the current cycle.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function hasDraftInCurrentCycle(string $stepCode, array $history): bool
    {
        // Find the last 'created' timestamp for this step (start of current cycle)
        $lastCreatedAt = null;
        foreach ($history as $entry) {
            if (($entry['step'] ?? '') === $stepCode && ($entry['action'] ?? '') === 'created') {
                $lastCreatedAt = $entry['at'] ?? '';
            }
        }

        // Look for 'drafted' after the last 'created' (or any 'drafted' if no 'created')
        foreach ($history as $entry) {
            if (($entry['step'] ?? '') !== $stepCode) {
                continue;
            }
            if (($entry['action'] ?? '') !== 'drafted') {
                continue;
            }
            if ($lastCreatedAt === null || ($entry['at'] ?? '') > $lastCreatedAt) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a step is a re-entry after rejection (multiple 'created' actions).
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function isRejectionReentry(string $stepCode, array $history): bool
    {
        $createdCount = 0;
        foreach ($history as $entry) {
            if (($entry['step'] ?? '') === $stepCode && ($entry['action'] ?? '') === 'created') {
                $createdCount++;
            }
        }

        return $createdCount > 1;
    }
}
