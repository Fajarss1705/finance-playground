<?php

use App\Services\WorkflowEngine;
use App\Workflows\PpWorkflowDefinition;

beforeEach(function () {
    $this->engine = new WorkflowEngine;
    $this->definition = new PpWorkflowDefinition;
});

function makeEntry(string $step, string $action, string $at, ?int $id = null, ?string $table = null, ?array $extra = []): array
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

    if ($table !== null) {
        $entry['table'] = $table;
    }

    if ($id !== null) {
        $entry['id'] = $id;
    }

    return array_merge($entry, $extra);
}

// --- Workflow Status ---

test('empty history returns empty status', function () {
    expect($this->engine->getWorkflowStatus([]))->toBe('empty');
});

test('workflow with only created entry is active', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
    ];

    expect($this->engine->getWorkflowStatus($history))->toBe('active');
});

test('workflow with completed action is completed', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP06', 'completed', '2026-01-01T16:00:00Z', 1, 'pp06_periode_tahunan'),
    ];

    expect($this->engine->getWorkflowStatus($history))->toBe('completed');
});

test('terminated workflow returns terminated', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'terminated', '2026-01-01T10:00:00Z'),
    ];

    expect($this->engine->getWorkflowStatus($history))->toBe('terminated');
});

test('deleted workflow returns deleted', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'terminated', '2026-01-01T10:00:00Z'),
        makeEntry('PP01', 'deleted', '2026-01-01T11:00:00Z'),
    ];

    expect($this->engine->getWorkflowStatus($history))->toBe('deleted');
});

test('restored workflow after terminated is terminated', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'terminated', '2026-01-01T10:00:00Z'),
        makeEntry('PP01', 'deleted', '2026-01-01T11:00:00Z'),
        makeEntry('PP01', 'restored', '2026-01-01T12:00:00Z'),
    ];

    expect($this->engine->getWorkflowStatus($history))->toBe('terminated');
});

// --- Step Statuses ---

test('fresh workflow has PP01 active and rest pending', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['status'])->toBe('active')
        ->and($statuses['PP02']['status'])->toBe('pending')
        ->and($statuses['PP03']['status'])->toBe('pending')
        ->and($statuses['PP04']['status'])->toBe('pending')
        ->and($statuses['PP05']['status'])->toBe('pending')
        ->and($statuses['PP06']['status'])->toBe('pending')
        ->and($statuses['PP07']['status'])->toBe('pending');
});

test('PP01 submitted makes PP02 active', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['status'])->toBe('completed')
        ->and($statuses['PP02']['status'])->toBe('active')
        ->and($statuses['PP03']['status'])->toBe('pending');
});

test('linear progression PP01 through PP04 submit', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
        makeEntry('PP02', 'submitted', '2026-01-01T11:00:00Z', 1, 'pp02_data'),
        makeEntry('PP03', 'created', '2026-01-01T11:01:00Z', 1, 'pp03_data'),
        makeEntry('PP03', 'submitted', '2026-01-01T12:00:00Z', 1, 'pp03_data'),
        makeEntry('PP04', 'created', '2026-01-01T12:01:00Z', 1, 'pp04_data'),
        makeEntry('PP04', 'submitted', '2026-01-01T13:00:00Z', 1, 'pp04_data'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['status'])->toBe('completed')
        ->and($statuses['PP02']['status'])->toBe('completed')
        ->and($statuses['PP03']['status'])->toBe('completed')
        ->and($statuses['PP04']['status'])->toBe('completed')
        ->and($statuses['PP05']['status'])->toBe('active')
        ->and($statuses['PP06']['status'])->toBe('pending');
});

test('PP05 approve completes PP05 and makes PP06 active', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
        makeEntry('PP02', 'submitted', '2026-01-01T11:00:00Z', 1, 'pp02_data'),
        makeEntry('PP03', 'created', '2026-01-01T11:01:00Z', 1, 'pp03_data'),
        makeEntry('PP03', 'submitted', '2026-01-01T12:00:00Z', 1, 'pp03_data'),
        makeEntry('PP04', 'created', '2026-01-01T12:01:00Z', 1, 'pp04_data'),
        makeEntry('PP04', 'submitted', '2026-01-01T13:00:00Z', 1, 'pp04_data'),
        makeEntry('PP05', 'approved', '2026-01-01T14:00:00Z'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP05']['status'])->toBe('completed')
        ->and($statuses['PP06']['status'])->toBe('active');
});

test('PP06 completed makes PP07 active', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
        makeEntry('PP02', 'submitted', '2026-01-01T11:00:00Z', 1, 'pp02_data'),
        makeEntry('PP03', 'created', '2026-01-01T11:01:00Z', 1, 'pp03_data'),
        makeEntry('PP03', 'submitted', '2026-01-01T12:00:00Z', 1, 'pp03_data'),
        makeEntry('PP04', 'created', '2026-01-01T12:01:00Z', 1, 'pp04_data'),
        makeEntry('PP04', 'submitted', '2026-01-01T13:00:00Z', 1, 'pp04_data'),
        makeEntry('PP05', 'approved', '2026-01-01T14:00:00Z'),
        makeEntry('PP06', 'completed', '2026-01-01T14:01:00Z', 1, 'pp06_periode_tahunan'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP06']['status'])->toBe('completed')
        ->and($statuses['PP07']['status'])->toBe('active');
});

// --- Rejection Cycle ---

test('PP05 rejection invalidates PP01-PP04 and makes PP01 active again', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
        makeEntry('PP02', 'submitted', '2026-01-01T11:00:00Z', 1, 'pp02_data'),
        makeEntry('PP03', 'created', '2026-01-01T11:01:00Z', 1, 'pp03_data'),
        makeEntry('PP03', 'submitted', '2026-01-01T12:00:00Z', 1, 'pp03_data'),
        makeEntry('PP04', 'created', '2026-01-01T12:01:00Z', 1, 'pp04_data'),
        makeEntry('PP04', 'submitted', '2026-01-01T13:00:00Z', 1, 'pp04_data'),
        makeEntry('PP05', 'rejected', '2026-01-01T14:00:00Z', extra: ['notes' => 'Anggaran belum lengkap']),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['status'])->toBe('active')
        ->and($statuses['PP02']['status'])->toBe('pending')
        ->and($statuses['PP03']['status'])->toBe('pending')
        ->and($statuses['PP04']['status'])->toBe('pending')
        ->and($statuses['PP05']['status'])->toBe('pending');
});

test('after rejection, resubmission creates new cycle', function () {
    $history = [
        // Cycle 1
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
        makeEntry('PP02', 'submitted', '2026-01-01T11:00:00Z', 1, 'pp02_data'),
        makeEntry('PP03', 'created', '2026-01-01T11:01:00Z', 1, 'pp03_data'),
        makeEntry('PP03', 'submitted', '2026-01-01T12:00:00Z', 1, 'pp03_data'),
        makeEntry('PP04', 'created', '2026-01-01T12:01:00Z', 1, 'pp04_data'),
        makeEntry('PP04', 'submitted', '2026-01-01T13:00:00Z', 1, 'pp04_data'),
        makeEntry('PP05', 'rejected', '2026-01-01T14:00:00Z'),
        // Cycle 2
        makeEntry('PP01', 'created', '2026-01-02T09:00:00Z', 2, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-02T10:00:00Z', 2, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-02T10:01:00Z', 2, 'pp02_data'),
        makeEntry('PP02', 'submitted', '2026-01-02T11:00:00Z', 2, 'pp02_data'),
        makeEntry('PP03', 'created', '2026-01-02T11:01:00Z', 2, 'pp03_data'),
        makeEntry('PP03', 'submitted', '2026-01-02T12:00:00Z', 2, 'pp03_data'),
        makeEntry('PP04', 'created', '2026-01-02T12:01:00Z', 2, 'pp04_data'),
        makeEntry('PP04', 'submitted', '2026-01-02T13:00:00Z', 2, 'pp04_data'),
        makeEntry('PP05', 'approved', '2026-01-02T14:00:00Z'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['status'])->toBe('completed')
        ->and($statuses['PP01']['cycle'])->toBe(2)
        ->and($statuses['PP02']['status'])->toBe('completed')
        ->and($statuses['PP05']['status'])->toBe('completed')
        ->and($statuses['PP06']['status'])->toBe('active');
});

// --- getCurrentSteps ---

test('getCurrentSteps returns only active steps', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
    ];

    $current = $this->engine->getCurrentSteps($this->definition, $history);

    expect($current)->toBe(['PP02']);
});

test('draft does not complete step', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'drafted', '2026-01-01T09:30:00Z', 1, 'pp01_data'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['status'])->toBe('active')
        ->and($statuses['PP02']['status'])->toBe('pending');
});

// --- Edge Cases ---

test('comment does not change step status', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'commented', '2026-01-01T09:30:00Z', extra: ['notes' => 'test comment']),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['status'])->toBe('active');
});

test('data IDs track correctly through rejection cycles', function () {
    $history = [
        makeEntry('PP01', 'created', '2026-01-01T09:00:00Z', 1, 'pp01_data'),
        makeEntry('PP01', 'submitted', '2026-01-01T10:00:00Z', 1, 'pp01_data'),
        makeEntry('PP02', 'created', '2026-01-01T10:01:00Z', 1, 'pp02_data'),
        makeEntry('PP02', 'submitted', '2026-01-01T11:00:00Z', 1, 'pp02_data'),
        makeEntry('PP03', 'created', '2026-01-01T11:01:00Z', 1, 'pp03_data'),
        makeEntry('PP03', 'submitted', '2026-01-01T12:00:00Z', 1, 'pp03_data'),
        makeEntry('PP04', 'created', '2026-01-01T12:01:00Z', 1, 'pp04_data'),
        makeEntry('PP04', 'submitted', '2026-01-01T13:00:00Z', 1, 'pp04_data'),
        makeEntry('PP05', 'rejected', '2026-01-01T14:00:00Z'),
        makeEntry('PP01', 'created', '2026-01-02T09:00:00Z', 2, 'pp01_data'),
    ];

    $statuses = $this->engine->getStepStatuses($this->definition, $history);

    expect($statuses['PP01']['dataId'])->toBe(2)
        ->and($statuses['PP01']['status'])->toBe('active');
});
