<?php

use App\Enums\StepType;
use App\Services\WorkflowEngine;
use App\Workflows\PrblWorkflowDefinition;

function prblEntry(string $step, string $action, string $at, ?int $id = null, ?array $extra = []): array
{
    $entry = [
        'step' => $step,
        'action' => $action,
        'by' => 1,
        'role' => 1,
        'team' => 1,
        'org' => 1,
        'workspace' => 1,
        'at' => $at,
    ];

    if ($id !== null) {
        $entry['id'] = $id;
    }

    return array_merge($entry, $extra);
}

beforeEach(function () {
    $this->engine = new WorkflowEngine;
    $this->definition = new PrblWorkflowDefinition;
});

// --- Definition ---

test('PRBL definition has 6 steps in correct order', function () {
    $steps = $this->definition->steps();

    expect($steps)->toBe(['PRBL01', 'PRBL02A', 'PRBL02B', 'PRBL03', 'PRBL04', 'PRBL05']);
});

test('PRBL step types are correct', function () {
    expect($this->definition->stepType('PRBL01'))->toBe(StepType::Form)
        ->and($this->definition->stepType('PRBL02A'))->toBe(StepType::Approval)
        ->and($this->definition->stepType('PRBL02B'))->toBe(StepType::Approval)
        ->and($this->definition->stepType('PRBL03'))->toBe(StepType::Form)
        ->and($this->definition->stepType('PRBL04'))->toBe(StepType::Approval)
        ->and($this->definition->stepType('PRBL05'))->toBe(StepType::Final);
});

test('PRBL02A and PRBL02B share PRBL01 as prerequisite (fork)', function () {
    expect($this->definition->prerequisites('PRBL02A'))->toBe(['PRBL01'])
        ->and($this->definition->prerequisites('PRBL02B'))->toBe(['PRBL01']);
});

test('PRBL03 requires both PRBL02A and PRBL02B (join)', function () {
    expect($this->definition->prerequisites('PRBL03'))->toBe(['PRBL02A', 'PRBL02B']);
});

test('PRBL04 has no static rejectionTarget (dual rejection via entry)', function () {
    expect($this->definition->rejectionTarget('PRBL04'))->toBeNull();
});

test('PRBL04 dual rejection targets resolve by action', function () {
    expect($this->definition->rejectionTargetForAction('reject_to_prbl03'))->toBe('PRBL03')
        ->and($this->definition->rejectionTargetForAction('reject_to_prbl01'))->toBe('PRBL01')
        ->and($this->definition->rejectionTargetForAction('unknown'))->toBeNull();
});

test('PRBL step tables are correct', function () {
    expect($this->definition->stepTable('PRBL01'))->toBe('prbl01_data')
        ->and($this->definition->stepTable('PRBL02A'))->toBeNull()
        ->and($this->definition->stepTable('PRBL02B'))->toBeNull()
        ->and($this->definition->stepTable('PRBL03'))->toBe('prbl03_data')
        ->and($this->definition->stepTable('PRBL04'))->toBeNull()
        ->and($this->definition->stepTable('PRBL05'))->toBe('prbl05_laporan_bulanan');
});

// --- Fork/Join Step Statuses ---

test('empty history: PRBL01 active, all others pending', function () {
    $statuses = $this->engine->getStepStatuses($this->definition, []);

    expect($statuses['PRBL01']['status'])->toBe('active')
        ->and($statuses['PRBL02A']['status'])->toBe('pending')
        ->and($statuses['PRBL02B']['status'])->toBe('pending')
        ->and($statuses['PRBL03']['status'])->toBe('pending')
        ->and($statuses['PRBL04']['status'])->toBe('pending')
        ->and($statuses['PRBL05']['status'])->toBe('pending');
});

test('PRBL01 submitted: both PRBL02A and PRBL02B become active (fork)', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PRBL01']['status'])->toBe('completed')
        ->and($statuses['PRBL02A']['status'])->toBe('active')
        ->and($statuses['PRBL02B']['status'])->toBe('active')
        ->and($statuses['PRBL03']['status'])->toBe('pending');
});

test('only PRBL02A approved: PRBL03 still pending (waiting for PRBL02B)', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'approved', '2026-04-17T09:00:00Z'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('active')
        ->and($statuses['PRBL03']['status'])->toBe('pending');
});

test('both PRBL02A and PRBL02B approved: PRBL03 becomes active (join)', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'approved', '2026-04-17T09:00:00Z'),
        prblEntry('PRBL02B', 'approved', '2026-04-17T10:00:00Z'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('completed')
        ->and($statuses['PRBL03']['status'])->toBe('active');
});

test('PRBL02A rejected + PRBL02B approved: both completed by engine (wait-for-both)', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'rejected', '2026-04-17T09:00:00Z'),
        prblEntry('PRBL02B', 'approved', '2026-04-17T10:00:00Z'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    // Both are 'completed' by engine (rejection = completing for parallel approval without rejectionTarget)
    expect($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('completed')
        ->and($statuses['PRBL03']['status'])->toBe('active');
});

// --- Happy Path ---

test('full happy path: all steps through PRBL05 completed', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'approved', '2026-04-17T09:00:00Z'),
        prblEntry('PRBL02B', 'approved', '2026-04-17T10:00:00Z'),
        prblEntry('PRBL03', 'created', '2026-04-18T09:00:00Z', 1),
        prblEntry('PRBL03', 'submitted', '2026-04-18T10:00:00Z', 1),
        prblEntry('PRBL04', 'approved', '2026-04-19T09:00:00Z'),
        prblEntry('PRBL05', 'completed', '2026-04-19T10:00:00Z', 1),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);
    $status = $this->engine->getWorkflowStatus($history);

    expect($status)->toBe('completed')
        ->and($statuses['PRBL05']['status'])->toBe('completed');
});

// --- PRBL04 Dual Rejection ---

test('PRBL04 reject_to_prbl03: PRBL03 becomes active, PRBL01/02A/02B stay completed', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'approved', '2026-04-17T09:00:00Z'),
        prblEntry('PRBL02B', 'approved', '2026-04-17T10:00:00Z'),
        prblEntry('PRBL03', 'created', '2026-04-18T09:00:00Z', 1),
        prblEntry('PRBL03', 'submitted', '2026-04-18T10:00:00Z', 1),
        prblEntry('PRBL04', 'rejected', '2026-04-19T09:00:00Z', null, ['cycleTarget' => 'PRBL03']),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PRBL01']['status'])->toBe('completed')
        ->and($statuses['PRBL02A']['status'])->toBe('completed')
        ->and($statuses['PRBL02B']['status'])->toBe('completed')
        ->and($statuses['PRBL03']['status'])->toBe('active')
        ->and($statuses['PRBL04']['status'])->toBe('pending');
});

test('PRBL04 reject_to_prbl01: PRBL01 becomes active, all middle steps invalidated', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'approved', '2026-04-17T09:00:00Z'),
        prblEntry('PRBL02B', 'approved', '2026-04-17T10:00:00Z'),
        prblEntry('PRBL03', 'created', '2026-04-18T09:00:00Z', 1),
        prblEntry('PRBL03', 'submitted', '2026-04-18T10:00:00Z', 1),
        prblEntry('PRBL04', 'rejected', '2026-04-19T09:00:00Z', null, ['cycleTarget' => 'PRBL01']),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PRBL01']['status'])->toBe('active')
        ->and($statuses['PRBL02A']['status'])->toBe('pending')
        ->and($statuses['PRBL02B']['status'])->toBe('pending')
        ->and($statuses['PRBL03']['status'])->toBe('pending')
        ->and($statuses['PRBL04']['status'])->toBe('pending');
});

test('PRBL04 reject_to_prbl03 then resubmit and approve completes workflow', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'approved', '2026-04-17T09:00:00Z'),
        prblEntry('PRBL02B', 'approved', '2026-04-17T10:00:00Z'),
        prblEntry('PRBL03', 'created', '2026-04-18T09:00:00Z', 1),
        prblEntry('PRBL03', 'submitted', '2026-04-18T10:00:00Z', 1),
        // First rejection — minor
        prblEntry('PRBL04', 'rejected', '2026-04-19T09:00:00Z', null, ['cycleTarget' => 'PRBL03']),
        // Resubmit PRBL03
        prblEntry('PRBL03', 'created', '2026-04-20T09:00:00Z', 2),
        prblEntry('PRBL03', 'submitted', '2026-04-20T10:00:00Z', 2),
        // Now approve
        prblEntry('PRBL04', 'approved', '2026-04-21T09:00:00Z'),
        prblEntry('PRBL05', 'completed', '2026-04-21T10:00:00Z', 1),
    ];

    $status = $this->engine->getWorkflowStatus($history);
    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($status)->toBe('completed')
        ->and($statuses['PRBL03']['status'])->toBe('completed')
        ->and($statuses['PRBL04']['status'])->toBe('completed')
        ->and($statuses['PRBL05']['status'])->toBe('completed');
});

// --- Stepper Data ---

test('stepper data shows rejection cycle for PRBL04 reject_to_prbl01', function () {
    $history = [
        prblEntry('PRBL01', 'created', '2026-04-16T09:00:00Z', 1),
        prblEntry('PRBL01', 'submitted', '2026-04-16T10:00:00Z', 1),
        prblEntry('PRBL02A', 'approved', '2026-04-17T09:00:00Z'),
        prblEntry('PRBL02B', 'approved', '2026-04-17T10:00:00Z'),
        prblEntry('PRBL03', 'created', '2026-04-18T09:00:00Z', 1),
        prblEntry('PRBL03', 'submitted', '2026-04-18T10:00:00Z', 1),
        prblEntry('PRBL04', 'rejected', '2026-04-19T09:00:00Z', null, ['cycleTarget' => 'PRBL01']),
        // Re-entry
        prblEntry('PRBL01', 'created', '2026-04-20T09:00:00Z', 2),
    ];

    $urlResolver = fn (string $code, ?int $id) => $id !== null ? "/prbl/{$code}/{$id}" : null;
    $cycles = $this->engine->getStepperData($this->definition, $history, $urlResolver);

    expect($cycles)->toHaveCount(2)
        ->and($cycles[0]['status'])->toBe('rejected')
        ->and($cycles[0]['type'])->toBe('rejection')
        ->and($cycles[1]['status'])->toBe('active');
});
