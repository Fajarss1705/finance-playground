<?php

namespace App\Workflows;

use App\Contracts\WorkflowDefinition;
use App\Enums\StepType;
use App\Enums\WorkflowType;

class PrblWorkflowDefinition implements WorkflowDefinition
{
    /** @var array<string, array{table: ?string, type: StepType, label: string, prerequisites: list<string>, rejectionTarget: ?string, cycleTarget: ?string}> */
    private const STEPS = [
        'PRBL01' => [
            'table' => 'prbl01_data',
            'type' => StepType::Form,
            'label' => 'Laporan Kegiatan',
            'prerequisites' => [],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
        'PRBL02A' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'Approval Narasi',
            'prerequisites' => ['PRBL01'],
            'rejectionTarget' => null, // wait-for-both: join gate handles rejection
            'cycleTarget' => null,
        ],
        'PRBL02B' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'Approval Anggaran',
            'prerequisites' => ['PRBL01'],
            'rejectionTarget' => null, // wait-for-both: join gate handles rejection
            'cycleTarget' => null,
        ],
        'PRBL03' => [
            'table' => 'prbl03_data',
            'type' => StepType::Form,
            'label' => 'Refund',
            'prerequisites' => ['PRBL02A', 'PRBL02B'],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
        'PRBL04' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'Review Final',
            'prerequisites' => ['PRBL03'],
            'rejectionTarget' => null, // dual rejection: resolved via rejectionTargets()
            'cycleTarget' => null,
        ],
        'PRBL05' => [
            'table' => 'prbl05_laporan_bulanan',
            'type' => StepType::Final,
            'label' => 'Laporan Bulanan',
            'prerequisites' => ['PRBL04'],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
    ];

    /**
     * PRBL04 dual rejection targets: action → target step.
     *
     * When recording PRBL04 rejection, include 'cycleTarget' in the history
     * entry extra data so the engine resolves the correct target.
     *
     * @var array<string, string>
     */
    private const REJECTION_TARGETS = [
        'reject_to_prbl03' => 'PRBL03',
        'reject_to_prbl01' => 'PRBL01',
    ];

    public function type(): WorkflowType
    {
        return WorkflowType::PRBL;
    }

    /** @return list<string> */
    public function steps(): array
    {
        return array_keys(self::STEPS);
    }

    public function stepTable(string $code): ?string
    {
        return self::STEPS[$code]['table'] ?? null;
    }

    public function stepType(string $code): StepType
    {
        return self::STEPS[$code]['type'];
    }

    public function stepLabel(string $code): string
    {
        return self::STEPS[$code]['label'];
    }

    /** @return list<string> */
    public function prerequisites(string $code): array
    {
        return self::STEPS[$code]['prerequisites'];
    }

    public function rejectionTarget(string $code): ?string
    {
        return self::STEPS[$code]['rejectionTarget'] ?? null;
    }

    public function cycleTarget(string $code): ?string
    {
        return self::STEPS[$code]['cycleTarget'] ?? null;
    }

    /**
     * Resolve PRBL04 dual rejection target by action name.
     *
     * @return array<string, string> Map of action → target step
     */
    public function rejectionTargets(): array
    {
        return self::REJECTION_TARGETS;
    }

    /**
     * Resolve a specific PRBL04 rejection target by action.
     */
    public function rejectionTargetForAction(string $action): ?string
    {
        return self::REJECTION_TARGETS[$action] ?? null;
    }
}
