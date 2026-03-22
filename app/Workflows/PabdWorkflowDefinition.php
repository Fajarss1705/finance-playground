<?php

namespace App\Workflows;

use App\Contracts\WorkflowDefinition;
use App\Enums\StepType;
use App\Enums\WorkflowType;

class PabdWorkflowDefinition implements WorkflowDefinition
{
    /** @var array<string, array{table: ?string, type: StepType, label: string, prerequisites: list<string>, rejectionTarget: ?string, cycleTarget: ?string}> */
    private const STEPS = [
        'PABD01' => [
            'table' => 'pabd01_data',
            'type' => StepType::Form,
            'label' => 'Checklist Pencairan',
            'prerequisites' => [],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
        'PABD02A' => [
            'table' => 'pabd02a_data',
            'type' => StepType::Form,
            'label' => 'Perubahan Anggaran',
            'prerequisites' => ['PABD01'],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
        'PABD02B' => [
            'table' => 'pabd02b_data',
            'type' => StepType::Approval,
            'label' => 'Approval Perubahan',
            'prerequisites' => ['PABD02A'],
            'rejectionTarget' => null,
            'cycleTarget' => 'PABD01',
        ],
        'PABD03' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'Approval Transfer',
            'prerequisites' => ['PABD02A', 'PABD02B'],
            'rejectionTarget' => 'PABD01',
            'cycleTarget' => null,
        ],
        'PABD04' => [
            'table' => 'pabd04_data',
            'type' => StepType::Form,
            'label' => 'Bukti Transfer',
            'prerequisites' => ['PABD03'],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
        'PABD05' => [
            'table' => 'pabd05_pengajuan_bulanan',
            'type' => StepType::Final,
            'label' => 'Pengajuan Bulanan',
            'prerequisites' => ['PABD04'],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
    ];

    public function type(): WorkflowType
    {
        return WorkflowType::PABD;
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
}
