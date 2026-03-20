<?php

namespace App\Services;

use App\Contracts\WorkflowDefinition;
use App\Enums\StepType;
use App\Enums\WorkflowType;
use App\Workflows\PabdWorkflowDefinition;
use App\Workflows\PkWorkflowDefinition;
use App\Workflows\PpWorkflowDefinition;
use App\Workflows\PrblWorkflowDefinition;
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
            WorkflowType::PK => new PkWorkflowDefinition,
            WorkflowType::PABD => new PabdWorkflowDefinition,
            WorkflowType::PRBL => new PrblWorkflowDefinition,
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
        $cyclebacks = $this->extractCyclebacks($definition, $history);

        if (empty($cyclebacks)) {
            $cycles = [$this->buildCurrentCycle($definition, $history, $urlResolver, 1)];
        } else {
            $cycles = [];
            $cycleNumber = 1;

            foreach ($cyclebacks as $cycleback) {
                $cycles[] = $this->buildPreviousCycle(
                    $definition,
                    $history,
                    $urlResolver,
                    $cycleNumber,
                    $cycleback,
                    $cyclebacks,
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
            $displayStatus = $info['status'];

            // Parallel approval steps without rejectionTarget are engine-'completed'
            // even when rejected (so the join gate works). Override display to 'rejected'.
            if ($displayStatus === 'completed'
                && $definition->stepType($code) === StepType::Approval
                && $definition->rejectionTarget($code) === null
                && $this->getLatestAction($code, $history) === 'rejected') {
                $displayStatus = 'rejected';
            }

            $steps[] = [
                'code' => $code,
                'label' => $definition->stepLabel($code),
                'status' => $displayStatus,
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
     * Build cycle data for a previous (cycled-back) cycle.
     *
     * Handles rejection cycles, approve-with-cycleback, and reset cycles.
     *
     * @param  list<array<string, mixed>>  $history
     * @param  \Closure(string, ?int): ?string  $urlResolver
     * @param  array{step: string, at: string, targetStep: string, action: string}  $cycleback
     * @param  list<array{step: string, at: string, targetStep: string, action: string}>  $allCyclebacks
     * @return array{number: int, status: string, type: string, steps: list<array{code: string, label: string, status: string, url: ?string}>}
     */
    private function buildPreviousCycle(
        WorkflowDefinition $definition,
        array $history,
        \Closure $urlResolver,
        int $number,
        array $cycleback,
        array $allCyclebacks,
    ): array {
        $allSteps = $definition->steps();
        $cyclingStepIndex = array_search($cycleback['step'], $allSteps);
        $cycleSteps = array_slice($allSteps, 0, $cyclingStepIndex + 1);

        // Determine this cycle's time window
        $previousCycleback = $number > 1 ? $allCyclebacks[$number - 2] : null;
        $windowStart = $previousCycleback ? $previousCycleback['at'] : null;
        $windowEnd = $cycleback['at'];

        $steps = [];

        foreach ($cycleSteps as $code) {
            if ($definition->stepType($code) === StepType::Revision) {
                continue;
            }

            if ($code === $cycleback['step']) {
                $status = match ($cycleback['action']) {
                    'rejected' => 'rejected',
                    'reset' => 'reset',
                    default => 'completed',
                };
            } else {
                // Check if this step voted 'rejected' in the window (e.g. parallel approval)
                $lastAction = $this->getLastActionInWindow($code, $history, $windowStart, $windowEnd);
                $status = $lastAction === 'rejected' ? 'rejected' : 'completed';
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

        $cycleStatus = match ($cycleback['action']) {
            'rejected' => 'rejected',
            'reset' => 'reset',
            default => 'cycled',
        };

        return [
            'number' => $number,
            'status' => $cycleStatus,
            'type' => $cycleback['action'] === 'rejected' ? 'rejection' : 'cycleback',
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
     * Get the last action for a step within a time window.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function getLastActionInWindow(string $code, array $history, ?string $windowStart, string $windowEnd): ?string
    {
        $lastAction = null;

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

            $lastAction = $entry['action'] ?? null;
        }

        return $lastAction;
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
        $cyclebacks = $this->extractCyclebacks($definition, $history);

        foreach ($steps as $code) {
            $result[$code] = [
                'status' => $this->computeStepStatus($definition, $code, $history, $cyclebacks),
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
     * @param  list<array{step: string, at: string, targetStep: string, action: string}>  $cyclebacks
     */
    private function computeStepStatus(WorkflowDefinition $definition, string $code, array $history, array $cyclebacks): string
    {
        $stepType = $definition->stepType($code);

        // Revision step: pending by default, active only when a revision draft is in progress
        if ($stepType === StepType::Revision) {
            $prereqs = $definition->prerequisites($code);

            if (! $this->arePrerequisitesMet($definition, $prereqs, $history, $cyclebacks)) {
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
            if ($this->hasValidCompletingAction($code, $history, $cyclebacks)) {
                return 'completed';
            }

            $prereqs = $definition->prerequisites($code);

            return $this->arePrerequisitesMet($definition, $prereqs, $history, $cyclebacks) ? 'active' : 'pending';
        }

        // Has a valid completing action after last relevant rejection?
        if ($this->hasValidCompletingAction($code, $history, $cyclebacks)) {
            return 'completed';
        }

        // For parallel approval steps without rejectionTarget or cycleTarget,
        // 'rejected' also completes the step (the join gate handles the cascade).
        // Steps with cycleTarget handle rejection via cycle-back, not as completion.
        // Exclude steps whose rejection created a cycleback (e.g. PRBL04 dual rejection
        // via entry-level cycleTarget) — those are real rejections, not parallel votes.
        if ($stepType === StepType::Approval
            && $definition->rejectionTarget($code) === null
            && $definition->cycleTarget($code) === null
            && ! $this->hasRejectionCycleback($code, $cyclebacks)) {
            if ($this->hasValidAction($code, 'rejected', $history, $cyclebacks)) {
                return 'completed';
            }
        }

        // Was this step skipped?
        if ($this->hasValidAction($code, 'skipped', $history, $cyclebacks)) {
            return 'skipped';
        }

        // Check prerequisites
        $prereqs = $definition->prerequisites($code);
        $allPrereqsMet = $this->arePrerequisitesMet($definition, $prereqs, $history, $cyclebacks);

        if (! $allPrereqsMet) {
            return 'pending';
        }

        return 'active';
    }

    /**
     * Extract all cycle-back events from history with their targets.
     *
     * Handles three types of cycle-back:
     * - rejected + rejectionTarget (existing PP/PK pattern)
     * - rejected/approved + cycleTarget (PABD02B loop-back on any outcome)
     * - reset + cycleTarget (PK04 staleness → PABD01 reset, target from entry or definition)
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array{step: string, at: string, targetStep: string, action: string}>
     */
    private function extractCyclebacks(WorkflowDefinition $definition, array $history): array
    {
        $cyclebacks = [];

        foreach ($history as $entry) {
            $action = $entry['action'] ?? '';
            $step = $entry['step'] ?? '';
            $target = null;

            if ($action === 'rejected') {
                $target = $entry['cycleTarget'] ?? $definition->rejectionTarget($step) ?? $definition->cycleTarget($step);
            } elseif ($action === 'approved') {
                $target = $definition->cycleTarget($step);
            } elseif ($action === 'reset') {
                $target = $entry['cycleTarget'] ?? $definition->cycleTarget($step);
            }

            if ($target !== null) {
                $cyclebacks[] = [
                    'step' => $step,
                    'at' => $entry['at'] ?? '',
                    'targetStep' => $target,
                    'action' => $action,
                ];
            }
        }

        return $cyclebacks;
    }

    /**
     * Check if a step has a completing action that is still valid (not invalidated by later cycle-back).
     *
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{step: string, at: string, targetStep: string, action: string}>  $cyclebacks
     */
    private function hasValidCompletingAction(string $code, array $history, array $cyclebacks): bool
    {
        return $this->hasValidAction($code, self::COMPLETING_ACTIONS, $history, $cyclebacks);
    }

    /**
     * Check if step has a valid action (not invalidated by later cycle-back).
     *
     * @param  string|list<string>  $actions
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{step: string, at: string, targetStep: string, action: string}>  $cyclebacks
     */
    private function hasValidAction(string $code, string|array $actions, array $history, array $cyclebacks): bool
    {
        $actions = (array) $actions;
        $lastCyclebackTime = $this->getLastCyclebackTimeAffecting($code, null, $cyclebacks);

        foreach (array_reverse($history) as $entry) {
            $entryStep = $entry['step'] ?? '';
            $entryAction = $entry['action'] ?? '';

            if ($entryStep === $code && in_array($entryAction, $actions, true)) {
                $entryTime = $entry['at'] ?? '';

                if ($lastCyclebackTime === null || $entryTime > $lastCyclebackTime) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the latest cycle-back time that invalidates a specific step.
     *
     * For rejection: range is [targetStep, cyclingStep) — cycling step excluded.
     * For approve/reset: range is [targetStep, cyclingStep] — cycling step included,
     * because the action that triggered the cycle-back should itself be invalidated.
     *
     * @param  list<array{step: string, at: string, targetStep: string, action: string}>  $cyclebacks
     */
    private function getLastCyclebackTimeAffecting(string $code, ?WorkflowDefinition $definition, array $cyclebacks): ?string
    {
        $latest = null;

        foreach ($cyclebacks as $cycleback) {
            $inRange = $this->isStepInCyclebackRange($code, $cycleback['targetStep'], $cycleback['step']);

            // For non-rejection cyclebacks, the cycling step itself is also invalidated
            if (! $inRange && $cycleback['action'] !== 'rejected' && $code === $cycleback['step']) {
                $inRange = true;
            }

            if ($inRange && ($latest === null || $cycleback['at'] > $latest)) {
                $latest = $cycleback['at'];
            }
        }

        return $latest;
    }

    /**
     * Check if a step has any cycleback originating from its rejected action.
     *
     * Used to distinguish parallel approval steps (rejection = vote, completing)
     * from steps with entry-level cycleTarget (rejection = real cycle-back).
     *
     * @param  list<array{step: string, at: string, targetStep: string, action: string}>  $cyclebacks
     */
    private function hasRejectionCycleback(string $code, array $cyclebacks): bool
    {
        foreach ($cyclebacks as $cycleback) {
            if ($cycleback['step'] === $code && $cycleback['action'] === 'rejected') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a step code falls within the range of steps invalidated by a cycle-back.
     * Range: from targetStep (inclusive) to cyclingStep (exclusive).
     */
    private function isStepInCyclebackRange(string $code, string $targetStep, string $cyclingStep): bool
    {
        $codeIndex = array_search($code, $this->stepOrder);
        $targetIndex = array_search($targetStep, $this->stepOrder);
        $cyclingIndex = array_search($cyclingStep, $this->stepOrder);

        if ($codeIndex === false || $targetIndex === false || $cyclingIndex === false) {
            return false;
        }

        return $codeIndex >= $targetIndex && $codeIndex < $cyclingIndex;
    }

    /**
     * Check if all prerequisites are met (completed or skipped).
     *
     * @param  list<string>  $prereqs
     * @param  list<array<string, mixed>>  $history
     * @param  list<array{step: string, at: string, targetStep: string, action: string}>  $cyclebacks
     */
    private function arePrerequisitesMet(WorkflowDefinition $definition, array $prereqs, array $history, array $cyclebacks): bool
    {
        foreach ($prereqs as $prereq) {
            $status = $this->computeStepStatus($definition, $prereq, $history, $cyclebacks);

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
