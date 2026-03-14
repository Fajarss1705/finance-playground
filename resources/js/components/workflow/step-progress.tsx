import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

type StepStatus = 'completed' | 'active' | 'pending' | 'skipped' | 'rejected';

export type StepperStep = {
    code: string;
    label: string;
    status: StepStatus;
    url: string | null;
    roles?: string[];
    stepType?: 'form' | 'approval' | 'final' | 'revision';
};

export type StepperCycle = {
    number: number;
    status: 'rejected' | 'active' | 'completed';
    type?: 'initial' | 'rejection' | 'revision';
    revisionNumber?: number;
    steps: StepperStep[];
};

const statusColors: Record<StepStatus, string> = {
    completed: 'bg-green-500 text-white',
    active: 'bg-blue-500 text-white ring-2 ring-blue-300 dark:ring-blue-700',
    pending: 'bg-muted text-muted-foreground',
    skipped: 'bg-gray-300 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
    rejected: 'bg-red-500 text-white',
};

const lineColors: Record<StepStatus, string> = {
    completed: 'bg-green-500',
    active: 'bg-blue-500',
    pending: 'bg-muted',
    skipped: 'bg-gray-300 dark:bg-gray-700',
    rejected: 'bg-red-500',
};

const cycleStatusLabels: Record<string, string> = {
    rejected: 'ditolak',
    active: 'aktif',
    completed: 'selesai',
};

function StepDot({ step, index, activeRoleName }: { step: StepperStep; index: number; activeRoleName?: string | null }) {
    const roles = step.roles ?? [];
    const hasAccess = activeRoleName ? roles.includes(activeRoleName) : false;
    const isFinal = step.stepType === 'final';

    const dotColor = isFinal && (step.status === 'completed' || step.status === 'active')
        ? step.status === 'completed'
            ? 'bg-amber-500 text-white'
            : 'bg-amber-500 text-white ring-2 ring-amber-300 dark:ring-amber-700'
        : statusColors[step.status];

    const dot = (
        <div
            className={cn(
                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                dotColor,
                step.status === 'active' && 'animate-pulse',
                step.url && 'cursor-pointer hover:opacity-80',
            )}
        >
            {isFinal && step.status === 'completed' ? (
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                </svg>
            ) : step.status === 'completed' ? (
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            ) : step.status === 'skipped' ? (
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                </svg>
            ) : step.status === 'rejected' ? (
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            ) : (
                index + 1
            )}
        </div>
    );

    const wrappedDot = step.url ? <Link href={step.url}>{dot}</Link> : dot;

    return (
        <div className="flex flex-col items-center gap-1">
            {roles.length > 0 ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span>{wrappedDot}</span>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="max-w-xs">
                        <div className="space-y-1">
                            <div className="font-medium">{step.code}: {step.label}</div>
                            <div className="flex flex-wrap gap-1">
                                {roles.map((role) => (
                                    <Badge
                                        key={role}
                                        variant="secondary"
                                        className={cn(
                                            'text-[10px]',
                                            activeRoleName && role === activeRoleName
                                                ? 'bg-blue-100 text-blue-800 ring-1 ring-blue-300 dark:bg-blue-900 dark:text-blue-200 dark:ring-blue-700'
                                                : '',
                                        )}
                                    >
                                        {role}
                                    </Badge>
                                ))}
                            </div>
                            {activeRoleName && !hasAccess && (
                                <div className="text-[10px] italic text-amber-300">Anda tidak punya akses di step ini</div>
                            )}
                        </div>
                    </TooltipContent>
                </Tooltip>
            ) : (
                wrappedDot
            )}
            <span className={cn(
                'max-w-16 text-center text-[10px] leading-tight text-muted-foreground',
                step.status === 'skipped' && 'line-through',
                isFinal && step.status === 'completed' && 'font-semibold text-amber-600 dark:text-amber-400',
            )}>
                {step.code}
            </span>
        </div>
    );
}

function StepRow({ steps, activeRoleName }: { steps: StepperStep[]; activeRoleName?: string | null }) {
    return (
        <div className="flex items-center gap-0 overflow-x-auto pb-1">
            {steps.map((step, i) => {
                const isFinal = step.stepType === 'final';
                const lineColor = isFinal && (step.status === 'completed' || step.status === 'active')
                    ? 'bg-amber-500'
                    : lineColors[step.status];

                return (
                    <div key={`${step.code}-${i}`} className="flex items-center">
                        <StepDot step={step} index={i} activeRoleName={activeRoleName} />
                        {i < steps.length - 1 && (
                            <div className={cn('mx-1 -mt-4.5 h-0.5 w-6 shrink-0', lineColor)} />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

export default function StepProgress({ cycles, activeRoleName }: { cycles: StepperCycle[]; activeRoleName?: string | null }) {
    const isSingleCycle = cycles.length === 1;

    if (isSingleCycle) {
        return <StepRow steps={cycles[0].steps} activeRoleName={activeRoleName} />;
    }

    return (
        <div className="space-y-2">
            {cycles.map((cycle, i) => {
                const isLatest = i === cycles.length - 1;
                return (
                    <CycleRow
                        key={cycle.number}
                        cycle={cycle}
                        defaultExpanded={isLatest}
                        collapsible={!isLatest}
                        activeRoleName={activeRoleName}
                    />
                );
            })}
        </div>
    );
}

function CycleRow({ cycle, defaultExpanded, collapsible, activeRoleName }: { cycle: StepperCycle; defaultExpanded: boolean; collapsible: boolean; activeRoleName?: string | null }) {
    const [expanded, setExpanded] = useState(defaultExpanded);
    const statusLabel = cycleStatusLabels[cycle.status] ?? cycle.status;
    const label = cycle.type === 'revision'
        ? `Revisi ke-${cycle.revisionNumber ?? cycle.number} (${statusLabel})`
        : `Pengisian ke-${cycle.number} (${statusLabel})`;

    if (!collapsible) {
        return (
            <div>
                <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
                <StepRow steps={cycle.steps} activeRoleName={activeRoleName} />
            </div>
        );
    }

    return (
        <div>
            <button
                type="button"
                className="mb-1 flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                onClick={() => setExpanded(!expanded)}
            >
                <svg
                    className={cn('h-3 w-3 transition-transform', expanded && 'rotate-90')}
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                </svg>
                {label}
            </button>
            {expanded && <StepRow steps={cycle.steps} activeRoleName={activeRoleName} />}
        </div>
    );
}
