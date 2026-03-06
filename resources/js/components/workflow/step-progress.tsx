import { cn } from '@/lib/utils';

type StepStatus = 'completed' | 'active' | 'pending' | 'skipped' | 'rejected';

type Step = {
    code: string;
    label: string;
    status: StepStatus;
};

const statusColors: Record<StepStatus, string> = {
    completed: 'bg-green-500 text-white',
    active: 'bg-blue-500 text-white ring-2 ring-blue-300 dark:ring-blue-700',
    pending: 'bg-muted text-muted-foreground',
    skipped: 'bg-gray-300 text-gray-500 dark:bg-gray-700 dark:text-gray-400 line-through',
    rejected: 'bg-red-500 text-white',
};

const lineColors: Record<StepStatus, string> = {
    completed: 'bg-green-500',
    active: 'bg-blue-500',
    pending: 'bg-muted',
    skipped: 'bg-gray-300 dark:bg-gray-700',
    rejected: 'bg-red-500',
};

export default function StepProgress({ steps, currentStep }: { steps: Step[]; currentStep?: string }) {
    return (
        <div className="flex items-center gap-0 overflow-x-auto pb-2">
            {steps.map((step, i) => (
                <div key={step.code} className="flex items-center">
                    <div className="flex flex-col items-center gap-1">
                        <div
                            className={cn(
                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                statusColors[step.status],
                                currentStep === step.code && 'animate-pulse',
                            )}
                        >
                            {step.status === 'completed' ? (
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            ) : step.status === 'skipped' ? (
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                                </svg>
                            ) : (
                                i + 1
                            )}
                        </div>
                        <span className="max-w-16 text-center text-[10px] leading-tight text-muted-foreground">{step.label}</span>
                    </div>
                    {i < steps.length - 1 && <div className={cn('mx-1 mt-[-18px] h-0.5 w-6 shrink-0', lineColors[step.status])} />}
                </div>
            ))}
        </div>
    );
}
