<?php

namespace App\Services;

use App\Contracts\WorkflowDefinition;
use App\Enums\StepType;
use App\Enums\WorkflowType;
use App\Workflows\PpWorkflowDefinition;
use Illuminate\Database\Eloquent\Model;

class WorkflowEngine
{
    /** Actions that complete a step (advance workflow forward). */
    private const COMPLETING_ACTIONS = ['submitted', 'approved', 'skipped', 'completed'];

    /** @var list<string> Step order for the current definition context. */
    private array $stepOrder = [];

    public function resolveDefinition(WorkflowType $type): WorkflowDefinition
    {
        return match ($type) {
            WorkflowType::PP => new PpWorkflowDefinition,
            default => throw new \InvalidArgumentException("No definition for workflow type: {$type->value}"),
        };
    }

    /**
     * Derive workflow status from history.
     *
     * @param  list<array<string, mixed>>  $history
     */
    public function getWorkflowStatus(array $history): string
    {
        if (empty($history)) {
            return 'empty';
        }

        // Check lifecycle actions in reverse order
        $hasDeleted = false;
        $hasRestored = false;
        $hasTerminated = false;

        foreach (array_reverse($history) as $entry) {
            $action = $entry['action'] ?? '';

            if ($action === 'deleted' && ! $hasRestored) {
                $hasDeleted = true;

                break;
            }

            if ($action === 'restored') {
                $hasRestored = true;
            }

            if ($action === 'terminated') {
                $hasTerminated = true;

                break;
            }
        }

        if ($hasDeleted) {
            return 'deleted';
        }

        if ($hasTerminated) {
            return 'terminated';
        }

        if ($this->hasCompletedFinalStep($history)) {
            return 'completed';
        }

        return 'active';
    }

    /**
     * Compute status for every step in the workflow.
     *
     * @param  list<array<string, mixed>>  $history
     * @return array<string, array{status: string, dataId: ?int, cycle: int}>
     */
    public function getStepStatuses(WorkflowDefinition $definition, array $history): array
    {
        $this->stepOrder = $definition->steps();
        $steps = $definition->steps();
        $result = [];
        $rejections = $this->extractRejections($definition, $history);

        foreach ($steps as $code) {
            $result[$code] = [
                'status' => $this->computeStepStatus($definition, $code, $history, $rejections),
                'dataId' => $this->getLatestDataId($code, $history),
                'cycle' => $this->getCycleCount($code, $history),
            ];
        }

        return $result;
    }

    /**
     * Get currently active (actionable) steps.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<string>
     */
    public function getCurrentSteps(WorkflowDefinition $definition, array $history): array
    {
        $statuses = $this->getStepStatuses($definition, $history);
        $active = [];

        foreach ($statuses as $code => $info) {
            if ($info['status'] === 'active') {
                $active[] = $code;
            }
        }

        return $active;
    }

    /**
     * Record an action on a workflow instance.
     *
     * @param  array<string, mixed>  $sessionContext  Keys: role, team, org, workspace
     * @param  list<int>|null  $files
     */
    public function recordAction(
        Model $workflow,
        string $step,
        string $action,
        ?int $userId,
        array $sessionContext,
        ?string $table = null,
        ?int $dataId = null,
        ?string $notes = null,
        ?array $files = null,
        ?array $extra = null,
    ): void {
        $history = $workflow->history ?? [];

        $entry = array_filter([
            'step' => $step,
            'action' => $action,
            'by' => $userId,
            'role' => $sessionContext['role'] ?? null,
            'team' => $sessionContext['team'] ?? null,
            'org' => $sessionContext['org'] ?? null,
            'workspace' => $sessionContext['workspace'] ?? null,
            'at' => now()->toIso8601String(),
            'table' => $table,
            'id' => $dataId,
            'notes' => $notes,
            'files' => $files,
        ], fn ($v) => $v !== null);

        if ($extra) {
            $entry = array_merge($entry, $extra);
        }

        $history[] = $entry;

        $workflow->history = $history;
        $workflow->save();
    }

    /**
     * Compute status for a single step.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{step: string, at: string, targetStep: string}>  $rejections
     */
    private function computeStepStatus(WorkflowDefinition $definition, string $code, array $history, array $rejections): string
    {
        $stepType = $definition->stepType($code);

        // Revision step: special handling — active only after final is completed
        if ($stepType === StepType::Revision) {
            $prereqs = $definition->prerequisites($code);
            $allPrereqsMet = $this->arePrerequisitesMet($definition, $prereqs, $history, $rejections);

            if (! $allPrereqsMet) {
                return 'pending';
            }

            return 'active';
        }

        // Final step: auto-completed by system
        if ($stepType === StepType::Final) {
            if ($this->hasValidCompletingAction($code, $history, $rejections)) {
                return 'completed';
            }

            $prereqs = $definition->prerequisites($code);

            return $this->arePrerequisitesMet($definition, $prereqs, $history, $rejections) ? 'active' : 'pending';
        }

        // Has a valid completing action after last relevant rejection?
        if ($this->hasValidCompletingAction($code, $history, $rejections)) {
            return 'completed';
        }

        // Was this step skipped?
        if ($this->hasValidAction($code, 'skipped', $history, $rejections)) {
            return 'skipped';
        }

        // Check prerequisites
        $prereqs = $definition->prerequisites($code);
        $allPrereqsMet = $this->arePrerequisitesMet($definition, $prereqs, $history, $rejections);

        if (! $allPrereqsMet) {
            return 'pending';
        }

        return 'active';
    }

    /**
     * Extract all rejection events from history with their targets.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array{step: string, at: string, targetStep: string}>
     */
    private function extractRejections(WorkflowDefinition $definition, array $history): array
    {
        $rejections = [];

        foreach ($history as $entry) {
            if (($entry['action'] ?? '') === 'rejected') {
                $step = $entry['step'] ?? '';
                $target = $definition->rejectionTarget($step);

                if ($target !== null) {
                    $rejections[] = [
                        'step' => $step,
                        'at' => $entry['at'] ?? '',
                        'targetStep' => $target,
                    ];
                }
            }
        }

        return $rejections;
    }

    /**
     * Check if a step has a completing action that is still valid (not invalidated by later rejection).
     *
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{step: string, at: string, targetStep: string}>  $rejections
     */
    private function hasValidCompletingAction(string $code, array $history, array $rejections): bool
    {
        return $this->hasValidAction($code, self::COMPLETING_ACTIONS, $history, $rejections);
    }

    /**
     * Check if step has a valid action (not invalidated by later rejection).
     *
     * @param  string|list<string>  $actions
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{step: string, at: string, targetStep: string}>  $rejections
     */
    private function hasValidAction(string $code, string|array $actions, array $history, array $rejections): bool
    {
        $actions = (array) $actions;
        $lastRejectionTime = $this->getLastRejectionTimeAffecting($code, null, $rejections);

        foreach (array_reverse($history) as $entry) {
            $entryStep = $entry['step'] ?? '';
            $entryAction = $entry['action'] ?? '';

            if ($entryStep === $code && in_array($entryAction, $actions, true)) {
                $entryTime = $entry['at'] ?? '';

                if ($lastRejectionTime === null || $entryTime > $lastRejectionTime) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the latest rejection time that invalidates a specific step.
     * A rejection invalidates all steps from its target up to (but not including) the rejecting step.
     *
     * @param  list<array{step: string, at: string, targetStep: string}>  $rejections
     */
    private function getLastRejectionTimeAffecting(string $code, ?WorkflowDefinition $definition, array $rejections): ?string
    {
        $latest = null;

        foreach ($rejections as $rejection) {
            if ($this->isStepInRejectionRange($code, $rejection['targetStep'], $rejection['step'])) {
                if ($latest === null || $rejection['at'] > $latest) {
                    $latest = $rejection['at'];
                }
            }
        }

        return $latest;
    }

    /**
     * Check if a step code falls within the range of steps invalidated by a rejection.
     * Range: from targetStep (inclusive) to rejectingStep (exclusive).
     */
    private function isStepInRejectionRange(string $code, string $targetStep, string $rejectingStep): bool
    {
        $codeIndex = array_search($code, $this->stepOrder);
        $targetIndex = array_search($targetStep, $this->stepOrder);
        $rejectingIndex = array_search($rejectingStep, $this->stepOrder);

        if ($codeIndex === false || $targetIndex === false || $rejectingIndex === false) {
            return false;
        }

        return $codeIndex >= $targetIndex && $codeIndex < $rejectingIndex;
    }

    /**
     * Check if all prerequisites are met (completed or skipped).
     *
     * @param  list<string>  $prereqs
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{step: string, at: string, targetStep: string}>  $rejections
     */
    private function arePrerequisitesMet(WorkflowDefinition $definition, array $prereqs, array $history, array $rejections): bool
    {
        foreach ($prereqs as $prereq) {
            $status = $this->computeStepStatus($definition, $prereq, $history, $rejections);

            if ($status !== 'completed' && $status !== 'skipped') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the latest data ID for a step from history.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function getLatestDataId(string $code, array $history): ?int
    {
        $latestId = null;

        foreach ($history as $entry) {
            if (($entry['step'] ?? '') === $code && isset($entry['id'])) {
                $latestId = $entry['id'];
            }
        }

        return $latestId;
    }

    /**
     * Count how many cycles (submissions) a step has been through.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function getCycleCount(string $code, array $history): int
    {
        $count = 0;

        foreach ($history as $entry) {
            if (($entry['step'] ?? '') === $code && in_array($entry['action'] ?? '', ['created', 'submitted', 'approved'], true)) {
                if (($entry['action'] ?? '') === 'created') {
                    $count++;
                }
            }
        }

        return max($count, 0);
    }

    /**
     * Check if the final step (PP06 for PP) has been completed.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function hasCompletedFinalStep(array $history): bool
    {
        foreach ($history as $entry) {
            if (($entry['action'] ?? '') === 'completed') {
                return true;
            }
        }

        return false;
    }
}
