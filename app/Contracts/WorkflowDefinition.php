<?php

namespace App\Contracts;

use App\Enums\StepType;
use App\Enums\WorkflowType;

interface WorkflowDefinition
{
    public function type(): WorkflowType;

    /** @return list<string> */
    public function steps(): array;

    public function stepTable(string $code): ?string;

    public function stepType(string $code): StepType;

    public function stepLabel(string $code): string;

    /** @return list<string> */
    public function prerequisites(string $code): array;

    public function rejectionTarget(string $code): ?string;

    public function cycleTarget(string $code): ?string;
}
