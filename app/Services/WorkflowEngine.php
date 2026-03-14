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
     * Get stepper data grouped by cycles for the show page.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  \Closure(string, ?int): ?string  $urlResolver  Resolves (stepCode, dataId) → URL or null
     * @return list<array{number: int, status: string, steps: list<array{code: string, label: string, status: string, url: ?string}>}>
     */
    public function getStepperData(WorkflowDefinition $definition, array $history, \Closure $urlResolver): array
    {
        $this->stepOrder = $definition->steps();
        $rejections = $this->extractRejections($definition, $history);

        if (empty($rejections)) {
            $cycles = [$this->buildCurrentCycle($definition, $history, $urlResolver, 1)];
        } else {
            $cycles = [];
            $cycleNumber = 1;

            foreach ($rejections as $rejection) {
                $cycles[] = $this->buildPreviousCycle(
                    $definition,
                    $history,
                    $urlResolver,
                    $cycleNumber,
                    $rejection,
                    $rejections,
                );
                $cycleNumber++;
            }

            $cycles[] = $this->buildCurrentCycle($definition, $history, $urlResolver, $cycleNumber);
        }

        // Append revision cycles (PP07 → PP06) if any revisions exist
        $revisionCycles = $this->buildRevisionCycles($definition, $history, $urlResolver);
        foreach ($revisionCycles as $revCycle) {
            $cycles[] = $revCycle;
        }

        return $cycles;
    }

    /**
     * Build cycle data for the current (latest) cycle using live step statuses.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  \Closure(string, ?int): ?string  $urlResolver
     * @return array{number: int, status: string, steps: list<array{code: string, label: string, status: string, url: ?string}>}
     */
    private function buildCurrentCycle(WorkflowDefinition $definition, array $history, \Closure $urlResolver, int $number): array
    {
        $statuses = $this->getStepStatuses($definition, $history);
        $steps = [];

        foreach ($definition->steps() as $code) {
            // Exclude revision steps — they appear in their own revision cycles
            if ($definition->stepType($code) === StepType::Revision) {
                continue;
            }

            $info = $statuses[$code];
            $steps[] = [
                'code' => $code,
                'label' => $definition->stepLabel($code),
                'status' => $info['status'],
                'url' => $urlResolver($code, $info['dataId']),
                'stepType' => $definition->stepType($code)->value,
            ];
        }

        $workflowStatus = $this->getWorkflowStatus($history);
        $cycleStatus = $workflowStatus === 'completed' ? 'completed' : 'active';

        return [
            'number' => $number,
            'status' => $cycleStatus,
            'type' => 'initial',
            'steps' => $steps,
        ];
    }

    /**
     * Build cycle data for a previous (rejected) cycle.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  \Closure(string, ?int): ?string  $urlResolver
     * @param  array{step: string, at: string, targetStep: string}  $rejection
     * @param  list<array{step: string, at: string, targetStep: string}>  $allRejections
     * @return array{number: int, status: string, steps: list<array{code: string, label: string, status: string, url: ?string}>}
     */
    private function buildPreviousCycle(
        WorkflowDefinition $definition,
        array $history,
        \Closure $urlResolver,
        int $number,
        array $rejection,
        array $allRejections,
    ): array {
        $allSteps = $definition->steps();
        $rejectingStepIndex = array_search($rejection['step'], $allSteps);
        $cycleSteps = array_slice($allSteps, 0, $rejectingStepIndex + 1);

        // Determine this cycle's time window
        $previousRejection = $number > 1 ? $allRejections[$number - 2] : null;
        $windowStart = $previousRejection ? $previousRejection['at'] : null;
        $windowEnd = $rejection['at'];

        $steps = [];

        foreach ($cycleSteps as $code) {
            if ($definition->stepType($code) === StepType::Revision) {
                continue;
            }

            if ($code === $rejection['step']) {
                $status = 'rejected';
            } else {
                $status = 'completed';
            }

            $dataId = $this->getDataIdInWindow($code, $history, $windowStart, $windowEnd);
            $steps[] = [
                'code' => $code,
                'label' => $definition->stepLabel($code),
                'status' => $status,
                'url' => $urlResolver($code, $dataId),
                'stepType' => $definition->stepType($code)->value,
            ];
        }

        return [
            'number' => $number,
            'status' => 'rejected',
            'type' => 'rejection',
            'steps' => $steps,
        ];
    }

    /**
     * Build revision cycles from PP07 → PP06 pairs in history.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  \Closure(string, ?int): ?string  $urlResolver
     * @return list<array{number: int, status: string, type: string, revisionNumber: int, steps: list<array{code: string, label: string, status: string, url: ?string}>}>
     */
    private function buildRevisionCycles(WorkflowDefinition $definition, array $history, \Closure $urlResolver): array
    {
        // Find the revision step code and its prerequisite (final step)
        $revisionCode = null;
        $finalCode = null;

        foreach ($definition->steps() as $code) {
            if ($definition->stepType($code) === StepType::Revision) {
                $revisionCode = $code;
                $prereqs = $definition->prerequisites($code);
                $finalCode = $prereqs[0] ?? null;

                break;
            }
        }

        if ($revisionCode === null || $finalCode === null) {
            return [];
        }

        // Find all PP07 'created' entries — each one starts a revision cycle
        $revisionCreations = [];

        foreach ($history as $entry) {
            if (($entry['step'] ?? '') === $revisionCode && ($entry['action'] ?? '') === 'created') {
                $revisionCreations[] = $entry;
            }
        }

        if (empty($revisionCreations)) {
            return [];
        }

        $cycles = [];

        foreach ($revisionCreations as $i => $creation) {
            $revisionNumber = $i + 1;
            $pp07DataId = $creation['id'] ?? null;

            // Check if this PP07 was submitted
            $pp07Submitted = false;

            foreach ($history as $entry) {
                if (($entry['step'] ?? '') === $revisionCode
                    && ($entry['action'] ?? '') === 'submitted'
                    && ($entry['id'] ?? null) === $pp07DataId) {
                    $pp07Submitted = true;

                    break;
                }
            }

            // Find the matching PP06 completed entry for this revision
            $pp06DataId = null;

            foreach ($history as $entry) {
                if (($entry['step'] ?? '') === $finalCode
                    && ($entry['action'] ?? '') === 'completed'
                    && ($entry['revision'] ?? null) === $revisionNumber) {
                    $pp06DataId = $entry['id'] ?? null;

                    break;
                }
            }

            $pp07Status = $pp07Submitted ? 'completed' : 'active';
            $pp06Status = $pp06DataId !== null ? 'completed' : 'pending';

            // For PP06 in revision cycles, append revision query param to base URL
            $pp06Url = null;

            if ($pp06DataId !== null) {
                $baseUrl = $urlResolver($finalCode, $pp06DataId);
                $pp06Url = $baseUrl !== null ? $baseUrl."?revision={$revisionNumber}" : null;
            }

            $cycles[] = [
                'number' => count($cycles) + 1,
                'status' => $pp07Submitted ? 'completed' : 'active',
                'type' => 'revision',
                'revisionNumber' => $revisionNumber,
                'steps' => [
                    [
                        'code' => $revisionCode,
                        'label' => $definition->stepLabel($revisionCode),
                        'status' => $pp07Status,
                        'url' => $urlResolver($revisionCode, $pp07DataId),
                        'stepType' => $definition->stepType($revisionCode)->value,
                    ],
                    [
                        'code' => $finalCode,
                        'label' => $definition->stepLabel($finalCode),
                        'status' => $pp06Status,
                        'url' => $pp06Url,
                        'stepType' => $definition->stepType($finalCode)->value,
                    ],
                ],
            ];
        }

        return $cycles;
    }

    /**
     * Get the latest data ID for a step within a time window.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function getDataIdInWindow(string $code, array $history, ?string $windowStart, string $windowEnd): ?int
    {
        $latestId = null;

        foreach ($history as $entry) {
            $entryStep = $entry['step'] ?? '';
            $entryTime = $entry['at'] ?? '';

            if ($entryStep !== $code) {
                continue;
            }

            if ($windowStart !== null && $entryTime <= $windowStart) {
                continue;
            }

            if ($entryTime > $windowEnd) {
                break;
            }

            if (isset($entry['id'])) {
                $latestId = $entry['id'];
            }
        }

        return $latestId;
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

        // Revision step: pending by default, active only when a revision draft is in progress
        if ($stepType === StepType::Revision) {
            $prereqs = $definition->prerequisites($code);

            if (! $this->arePrerequisitesMet($definition, $prereqs, $history, $rejections)) {
                return 'pending';
            }

            // Check latest action for this step — active only if created/drafted but not submitted
            $latestAction = $this->getLatestAction($code, $history);

            if ($latestAction === 'created' || $latestAction === 'drafted') {
                return 'active';
            }

            return 'pending';
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
     * Get the latest action for a step from history.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function getLatestAction(string $code, array $history): ?string
    {
        $latest = null;

        foreach ($history as $entry) {
            if (($entry['step'] ?? '') === $code) {
                $latest = $entry['action'] ?? null;
            }
        }

        return $latest;
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
