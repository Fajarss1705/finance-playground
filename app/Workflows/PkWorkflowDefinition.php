<?php

namespace App\Workflows;

use App\Contracts\WorkflowDefinition;
use App\Enums\StepType;
use App\Enums\WorkflowType;

class PkWorkflowDefinition implements WorkflowDefinition
{
    /** @var array<string, array{table: ?string, type: StepType, label: string, prerequisites: list<string>, rejectionTarget: ?string, cycleTarget: ?string}> */
    private const STEPS = [
        'PK01' => [
            'table' => 'pk01_data',
            'type' => StepType::Form,
            'label' => 'Program Kegiatan',
            'prerequisites' => [],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
        'PK02A' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'Approval Narasi',
            'prerequisites' => ['PK01'],
            'rejectionTarget' => null, // wait-for-both: join gate handles rejection via PK03
            'cycleTarget' => null,
        ],
        'PK02B' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'Approval Anggaran',
            'prerequisites' => ['PK01'],
            'rejectionTarget' => null, // wait-for-both: join gate handles rejection via PK03
            'cycleTarget' => null,
        ],
        'PK03' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'RAKER',
            'prerequisites' => ['PK02A', 'PK02B'],
            'rejectionTarget' => 'PK01',
            'cycleTarget' => null,
        ],
        'PK04' => [
            'table' => 'pk04_program_tahunan',
            'type' => StepType::Final,
            'label' => 'Program Tahunan',
            'prerequisites' => ['PK03'],
            'rejectionTarget' => null,
            'cycleTarget' => null,
        ],
        'PK05' => [
            'table' => 'pk05_data',
            'type' => StepType::Revision,
            'label' => 'Revisi',
            'prerequisites' => ['PK04'],
            'rejectionTarget' => null,
            'cycleTarget' => 'PK04',
        ],
    ];

    public function type(): WorkflowType
    {
        return WorkflowType::PK;
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
        return self::STEPS[$code]['rejectionTarget'];
    }

    public function cycleTarget(string $code): ?string
    {
        return self::STEPS[$code]['cycleTarget'];
    }
}
