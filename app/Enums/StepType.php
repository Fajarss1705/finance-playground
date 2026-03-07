<?php

namespace App\Enums;

enum StepType: string
{
    case Form = 'form';
    case Approval = 'approval';
    case Final = 'final';
    case Revision = 'revision';
}
