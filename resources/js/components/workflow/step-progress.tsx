import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

type StepStatus = 'completed' | 'active' | 'pending' | 'skipped' | 'rejected';

export type StepperStep = {
    code: string;
    label: string;
    status: StepStatus;
    url: string | null;
};

export type StepperCycle = {
    number: number;
    status: 'rejected' | 'active' | 'completed';
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

function StepDot({ step, index }: { step: StepperStep; index: number }) {
    const dot = (
        <div
            className={cn(
                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                statusColors[step.status],
                step.status === 'active' && 'animate-pulse',
                step.url && 'cursor-pointer hover:opacity-80',
            )}
            title={`${step.code}: ${step.label}`}
        >
            {step.status === 'completed' ? (
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

    return (
        <div className="flex flex-col items-center gap-1">
            {step.url ? (
                <Link href={step.url}>{dot}</Link>
            ) : (
                dot
            )}
            <span className={cn(
                'max-w-16 text-center text-[10px] leading-tight text-muted-foreground',
                step.status === 'skipped' && 'line-through',
            )}>
                {step.code}
            </span>
        </div>
    );
}

function StepRow({ steps }: { steps: StepperStep[] }) {
    return (
        <div className="flex items-center gap-0 overflow-x-auto pb-1">
            {steps.map((step, i) => (
                <div key={`${step.code}-${i}`} className="flex items-center">
                    <StepDot step={step} index={i} />
                    {i < steps.length - 1 && (
                        <div className={cn('mx-1 -mt-4.5 h-0.5 w-6 shrink-0', lineColors[step.status])} />
                    )}
                </div>
            ))}
        </div>
    );
}

export default function StepProgress({ cycles }: { cycles: StepperCycle[] }) {
    const isSingleCycle = cycles.length === 1;

    if (isSingleCycle) {
        return <StepRow steps={cycles[0].steps} />;
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
                    />
                );
            })}
        </div>
    );
}

function CycleRow({ cycle, defaultExpanded, collapsible }: { cycle: StepperCycle; defaultExpanded: boolean; collapsible: boolean }) {
    const [expanded, setExpanded] = useState(defaultExpanded);
    const label = `Pengisian ke-${cycle.number} (${cycleStatusLabels[cycle.status] ?? cycle.status})`;

    if (!collapsible) {
        return (
            <div>
                <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
                <StepRow steps={cycle.steps} />
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
            {expanded && <StepRow steps={cycle.steps} />}
        </div>
    );
}
