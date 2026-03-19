<?php

namespace App\Workflows;

use App\Contracts\WorkflowDefinition;
use App\Enums\StepType;
use App\Enums\WorkflowType;

class PabdWorkflowDefinition implements WorkflowDefinition
{
    /** @var array<string, array{table: ?string, type: StepType, label: string, prerequisites: list<string>, rejectionTarget: ?string, cycleTarget: ?string}> */
    private const STEPS = [];

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
