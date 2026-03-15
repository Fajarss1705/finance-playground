<?php

namespace App\Workflows;

use App\Contracts\WorkflowDefinition;
use App\Enums\StepType;
use App\Enums\WorkflowType;

class PpWorkflowDefinition implements WorkflowDefinition
{
    /** @var array<string, array{table: ?string, type: StepType, label: string, prerequisites: list<string>, rejectionTarget: ?string}> */
    private const STEPS = [
        'PP01' => [
            'table' => 'pp01_data',
            'type' => StepType::Form,
            'label' => 'Rencana Periode',
            'prerequisites' => [],
            'rejectionTarget' => null,
        ],
        'PP02' => [
            'table' => 'pp02_data',
            'type' => StepType::Form,
            'label' => 'Pertanyaan Kuisioner',
            'prerequisites' => ['PP01'],
            'rejectionTarget' => null,
        ],
        'PP03' => [
            'table' => 'pp03_data',
            'type' => StepType::Form,
            'label' => 'Plafon Anggaran',
            'prerequisites' => ['PP02'],
            'rejectionTarget' => null,
        ],
        'PP04' => [
            'table' => 'pp04_data',
            'type' => StepType::Form,
            'label' => 'Dokumen SOP',
            'prerequisites' => ['PP03'],
            'rejectionTarget' => null,
        ],
        'PP05' => [
            'table' => null,
            'type' => StepType::Approval,
            'label' => 'Persetujuan',
            'prerequisites' => ['PP04'],
            'rejectionTarget' => 'PP01',
        ],
        'PP06' => [
            'table' => 'pp06_periode_tahunan',
            'type' => StepType::Final,
            'label' => 'Periode Tahunan',
            'prerequisites' => ['PP05'],
            'rejectionTarget' => null,
        ],
        'PP07' => [
            'table' => 'pp07_data',
            'type' => StepType::Revision,
            'label' => 'Revisi',
            'prerequisites' => ['PP06'],
            'rejectionTarget' => null,
        ],
    ];

    public function type(): WorkflowType
    {
        return WorkflowType::PP;
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
        return null;
    }
}
