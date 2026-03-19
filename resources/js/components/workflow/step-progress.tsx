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
    parallelGroup?: string | null;
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

/** Extract short number from step code, e.g. "PK02A" → "2A", "PP01" → "1", "PK03" → "3" */
function stepShortLabel(code: string): string {
    const m = code.match(/\d+[A-Za-z]?$/);
    if (!m) return code;
    // Strip leading zeros: "01" → "1", "02A" → "2A"
    return m[0].replace(/^0+/, '') || '0';
}

function StepDot({ step, index, activeRoleName }: { step: StepperStep; index: number; activeRoleName?: string | null }) {
    const roles = step.roles ?? [];
    const hasAccess = activeRoleName ? roles.includes(activeRoleName) : false;
    const isYourTurn = step.status === 'active' && hasAccess;
    const isFinal = step.stepType === 'final';

    const dotColor = isFinal && (step.status === 'completed' || step.status === 'active')
        ? step.status === 'completed'
            ? 'bg-amber-500 text-white'
            : 'bg-amber-500 text-white ring-2 ring-amber-300 dark:ring-amber-700'
        : isYourTurn
            ? 'bg-blue-600 text-white ring-2 ring-blue-400 ring-offset-1 ring-offset-background dark:ring-blue-500'
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
                stepShortLabel(step.code)
            )}
        </div>
    );

    const wrappedDot = step.url ? <Link href={step.url}>{dot}</Link> : dot;

    const turnLabel = step.status === 'active' && activeRoleName
        ? hasAccess
            ? <span className="whitespace-nowrap rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold text-blue-700 dark:bg-blue-900 dark:text-blue-300">Giliran Anda</span>
            : <span className="whitespace-nowrap rounded-full bg-muted px-1.5 py-0.5 text-[9px] text-muted-foreground">Bukan giliran Anda</span>
        : null;

    return (
        <div className="relative flex flex-col items-center gap-1">
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
            {turnLabel && (
                <span className="absolute -bottom-4 left-1/2 -translate-x-1/2">
                    {turnLabel}
                </span>
            )}
        </div>
    );
}

/** Resolve line color, with special amber for final steps */
function resolveLineColor(step: StepperStep): string {
    const isFinal = step.stepType === 'final';
    if (isFinal && (step.status === 'completed' || step.status === 'active')) return 'bg-amber-500';
    return lineColors[step.status];
}

/** Horizontal connector line */
function HLine({ className, style }: { className: string; style?: React.CSSProperties }) {
    return <div className={cn('mx-1 h-0.5 w-6 shrink-0', className)} style={style} />;
}

/** Map bg-* classes to stroke color CSS values for SVG */
const strokeColorMap: Record<string, string> = {
    'bg-green-500': 'var(--color-green-500)',
    'bg-blue-500': 'var(--color-blue-500)',
    'bg-muted': 'var(--color-muted)',
    'bg-red-500': 'var(--color-red-500)',
    'bg-amber-500': 'var(--color-amber-500)',
    'bg-gray-300 dark:bg-gray-700': 'var(--color-gray-300)',
};

function bgToStroke(bgClass: string): string {
    return strokeColorMap[bgClass] ?? 'var(--color-muted)';
}

/**
 * SVG fork/join bracket with curved corners.
 * Fork renders ╮╯ shape, join renders ╭╰ shape.
 */
function Bracket({ color, side, count, rowHeight, gap }: {
    color: string;
    side: 'fork' | 'join';
    count: number;
    rowHeight: number;
    gap: number;
}) {
    const stroke = bgToStroke(color);
    const totalHeight = count * rowHeight + (count - 1) * gap;
    const width = 16;
    const strokeW = 2;
    const r = 8; // curve radius

    // Y positions at dot center for each row (offset up for label below dot)
    const labelOffset = 10;
    const armYs = Array.from({ length: count }, (_, i) => {
        const rowTop = i * (rowHeight + gap);
        return rowTop + rowHeight / 2 - labelOffset;
    });

    const topY = armYs[0];
    const bottomY = armYs[armYs.length - 1];

    // Build a single curved bracket path
    // Fork (right-facing): arms come from right, curve into vertical bar on left
    //   (w, topY) ──── (r, topY) ╮
    //                             │
    //   (w, botY) ──── (r, botY) ╯
    //
    // Join (left-facing): mirror
    //   ╭ (w-r, topY) ──── (0, topY)
    //   │
    //   ╰ (w-r, botY) ──── (0, botY)

    let d: string;
    if (side === 'fork') {
        d = [
            `M ${width} ${topY}`,
            `H ${r}`,
            `Q 0 ${topY} 0 ${topY + r}`,
            `V ${bottomY - r}`,
            `Q 0 ${bottomY} ${r} ${bottomY}`,
            `H ${width}`,
        ].join(' ');
    } else {
        d = [
            `M 0 ${topY}`,
            `H ${width - r}`,
            `Q ${width} ${topY} ${width} ${topY + r}`,
            `V ${bottomY - r}`,
            `Q ${width} ${bottomY} ${width - r} ${bottomY}`,
            `H 0`,
        ].join(' ');
    }

    return (
        <svg
            width={width}
            height={totalHeight}
            className="shrink-0"
            style={{ marginTop: -labelOffset }}
        >
            <path
                d={d}
                fill="none"
                stroke={stroke}
                strokeWidth={strokeW}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

/**
 * Groups steps into segments: sequential steps and parallel groups.
 * Returns an array of { type: 'step', step } | { type: 'parallel', steps[] }
 */
function groupSteps(steps: StepperStep[]) {
    const segments: ({ type: 'step'; step: StepperStep } | { type: 'parallel'; steps: StepperStep[] })[] = [];
    let i = 0;

    while (i < steps.length) {
        const step = steps[i];
        if (step.parallelGroup) {
            // Collect all consecutive steps with the same parallelGroup
            const group: StepperStep[] = [];
            const groupId = step.parallelGroup;
            while (i < steps.length && steps[i].parallelGroup === groupId) {
                group.push(steps[i]);
                i++;
            }
            segments.push({ type: 'parallel', steps: group });
        } else {
            segments.push({ type: 'step', step });
            i++;
        }
    }

    return segments;
}

function StepRow({ steps, activeRoleName }: { steps: StepperStep[]; activeRoleName?: string | null }) {
    const segments = groupSteps(steps);
    let globalIndex = 0;

    return (
        <div className="flex items-center gap-0 overflow-x-auto pb-6 pl-8 pt-1">
            {segments.map((segment, segIdx) => {
                if (segment.type === 'step') {
                    const idx = globalIndex++;
                    const lineColor = resolveLineColor(segment.step);

                    return (
                        <div key={`${segment.step.code}-${segIdx}`} className="flex items-center">
                            <StepDot step={segment.step} index={idx} activeRoleName={activeRoleName} />
                            {segIdx < segments.length - 1 && (
                                <HLine className={cn('-mt-4.5', lineColor)} />
                            )}
                        </div>
                    );
                }

                // Parallel group — fork/join rendering
                const parallelSteps = segment.steps;
                const startIdx = globalIndex;
                globalIndex += parallelSteps.length;

                // Determine fork line color (from the step before this group)
                const prevSegment = segIdx > 0 ? segments[segIdx - 1] : null;
                const forkLineColor = prevSegment
                    ? prevSegment.type === 'step'
                        ? resolveLineColor(prevSegment.step)
                        : resolveLineColor(prevSegment.steps[prevSegment.steps.length - 1])
                    : 'bg-muted';

                // Join line color — best status of parallel steps
                const allCompleted = parallelSteps.every(s => s.status === 'completed');
                const anyRejected = parallelSteps.some(s => s.status === 'rejected');
                const joinLineColor = allCompleted ? 'bg-green-500' : anyRejected ? 'bg-red-500' : 'bg-muted';

                const hasNext = segIdx < segments.length - 1;

                // Row measurements for SVG bracket alignment
                const rowHeight = 52; // h-8 dot + gap-1 + label ≈ 52px
                const rowGap = 28; // gap-7 — enough space for turn labels

                return (
                    <div key={`parallel-${segIdx}`} className="flex items-start">
                        {/* Fork bracket ┤ */}
                        <Bracket
                            color={forkLineColor}
                            side="fork"
                            count={parallelSteps.length}
                            rowHeight={rowHeight}
                            gap={rowGap}
                        />

                        {/* Parallel step dots */}
                        <div className="flex flex-col gap-7">
                            {parallelSteps.map((pStep, pIdx) => (
                                <div key={pStep.code} className="flex items-center">
                                    <StepDot step={pStep} index={startIdx + pIdx} activeRoleName={activeRoleName} />
                                </div>
                            ))}
                        </div>

                        {/* Join bracket ├ */}
                        <Bracket
                            color={joinLineColor}
                            side="join"
                            count={parallelSteps.length}
                            rowHeight={rowHeight}
                            gap={rowGap}
                        />

                        {/* Line to next step — positioned at midpoint between dot centers */}
                        {hasNext && (() => {
                            const labelOffset = 10;
                            const topDotY = rowHeight / 2 - labelOffset;
                            const bottomDotY = (parallelSteps.length - 1) * (rowHeight + rowGap) + rowHeight / 2 - labelOffset;
                            const midY = (topDotY + bottomDotY) / 2;
                            return <HLine className={joinLineColor} style={{ marginTop: midY - 1 }} />;
                        })()}
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
            {cycles.map((cycle) => (
                <CycleRow
                    key={cycle.number}
                    cycle={cycle}
                    defaultExpanded={true}
                    collapsible={true}
                    activeRoleName={activeRoleName}
                />
            ))}
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
