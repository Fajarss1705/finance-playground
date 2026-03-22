import { Head, Link, router } from '@inertiajs/react';
import { Check, Circle, ExternalLink, Minus } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { cn, formatRupiah } from '@/lib/utils';
import { index as teamIndex } from '@/routes/team';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tim', href: teamIndex() }];

// --- Types ---

type PkStatusSummary = {
    rakerStatus: 'belum_ada' | 'aktif' | 'selesai' | 'tidak_tersedia';
    rakerRevision: number;
    rakerCurrentStep: string | null;
    rakerUrl: string | null;
    proposalCount: number;
    plafon: number;
};

type CalendarCellStatus = {
    state: 'selesai' | 'aktif' | 'belum' | 'skip';
    currentStep: string | null;
    currentStepName: string | null;
    url: string | null;
};

type MonthlyCalendarEntry = {
    bulan: number;
    bulanLabel: string;
    pabd: CalendarCellStatus;
    prbl: CalendarCellStatus;
};

type MonthlyAnggaranRow = {
    bulan: number;
    bulanLabel: string;
    direncanakan: number;
    dicairkan: number | null;
    realisasi: number | null;
    refund: number | null;
};

type AnggaranTotals = {
    direncanakan: number;
    dicairkan: number;
    realisasi: number;
    refund: number;
};

type ActiveStepInfo = {
    stepCode: string;
    stepName: string;
};

type ActiveWorkflowItem = {
    workflowType: 'pk' | 'pabd' | 'prbl';
    workflowLabel: string;
    showUrl: string;
    canAct: boolean;
    actionUrl: string | null;
    currentSteps: ActiveStepInfo[];
};

type TeamDashboardProps = {
    team: { id: number; name: string } | null;
    year: number | null;
    availableYears: number[];
    pkStatus: PkStatusSummary | null;
    calendar: MonthlyCalendarEntry[];
    anggaranRows: MonthlyAnggaranRow[];
    anggaranTotals: AnggaranTotals;
    activeWorkflows: ActiveWorkflowItem[];
};

// --- Helper Components ---

const MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

function CalendarCell({ cell }: { cell: CalendarCellStatus }) {
    const content = (
        <span
            className={cn(
                'flex h-8 w-8 items-center justify-center rounded-md text-xs transition-colors',
                cell.state === 'selesai' && 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                cell.state === 'aktif' && 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                cell.state === 'belum' && 'bg-muted text-muted-foreground',
                cell.state === 'skip' && 'text-muted-foreground/40',
                cell.url && 'cursor-pointer hover:ring-2 hover:ring-ring/50',
                !cell.url && 'cursor-default',
            )}
        >
            {cell.state === 'selesai' && <Check className="h-3.5 w-3.5" />}
            {cell.state === 'aktif' && <Circle className="h-3 w-3 fill-current" />}
            {cell.state === 'belum' && <Circle className="h-3 w-3" />}
            {cell.state === 'skip' && <Minus className="h-3.5 w-3.5" />}
        </span>
    );

    if (cell.state === 'aktif' && cell.currentStep) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    {cell.url ? <Link href={cell.url}>{content}</Link> : content}
                </TooltipTrigger>
                <TooltipContent>
                    <p className="text-xs">{cell.currentStep}: {cell.currentStepName}</p>
                </TooltipContent>
            </Tooltip>
        );
    }

    if (cell.url) {
        return <Link href={cell.url}>{content}</Link>;
    }

    return content;
}

function PkStatusBar({ pkStatus }: { pkStatus: PkStatusSummary }) {
    const rakerText = (() => {
        switch (pkStatus.rakerStatus) {
            case 'tidak_tersedia':
                return 'Tidak tersedia';
            case 'belum_ada':
                return 'Belum ada';
            case 'aktif':
                return `Aktif (Step: ${pkStatus.rakerCurrentStep})`;
            case 'selesai':
                return `Selesai (Rev. ${pkStatus.rakerRevision})`;
        }
    })();

    return (
        <SectionCard title="Status PK">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                <div>
                    <span className="text-muted-foreground">PK Raker: </span>
                    {pkStatus.rakerUrl ? (
                        <Link href={pkStatus.rakerUrl} className="font-medium hover:underline">
                            {pkStatus.rakerStatus === 'selesai' && <Check className="mr-1 inline h-3.5 w-3.5 text-green-600" />}
                            {rakerText}
                        </Link>
                    ) : (
                        <span className="font-medium">{rakerText}</span>
                    )}
                </div>
                <div>
                    <span className="text-muted-foreground">Plafon: </span>
                    <span className="font-medium">Rp {formatRupiah(pkStatus.plafon)}</span>
                </div>
                <div>
                    <span className="text-muted-foreground">Proposal: </span>
                    <span className="font-medium">{pkStatus.proposalCount}</span>
                </div>
            </div>
            {pkStatus.rakerStatus === 'tidak_tersedia' && (
                <p className="mt-1 text-xs text-muted-foreground">Tim ini belum memiliki plafon anggaran.</p>
            )}
        </SectionCard>
    );
}

function CalendarGrid({ calendar }: { calendar: MonthlyCalendarEntry[] }) {
    return (
        <SectionCard title="Kalender PABD & PRBL">
            {calendar.length === 0 ? (
                <p className="py-4 text-center text-sm text-muted-foreground">Belum ada data. PK belum dikompilasi.</p>
            ) : (
                <>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-160">
                            <thead>
                                <tr>
                                    <th className="py-1 pr-3 text-left text-xs font-medium text-muted-foreground" />
                                    {MONTH_SHORT.map((m) => (
                                        <th key={m} className="px-1 py-1 text-center text-xs font-medium text-muted-foreground">{m}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td className="py-1 pr-3 text-xs font-medium">PABD</td>
                                    {calendar.map((entry) => (
                                        <td key={entry.bulan} className="px-1 py-1 text-center">
                                            <div className="flex justify-center">
                                                <CalendarCell cell={entry.pabd} />
                                            </div>
                                        </td>
                                    ))}
                                </tr>
                                <tr>
                                    <td className="py-1 pr-3 text-xs font-medium">PRBL</td>
                                    {calendar.map((entry) => (
                                        <td key={entry.bulan} className="px-1 py-1 text-center">
                                            <div className="flex justify-center">
                                                <CalendarCell cell={entry.prbl} />
                                            </div>
                                        </td>
                                    ))}
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-4 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1.5">
                            <span className="flex h-4 w-4 items-center justify-center rounded bg-green-100 dark:bg-green-900/30"><Check className="h-2.5 w-2.5 text-green-700 dark:text-green-400" /></span>
                            Selesai
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="flex h-4 w-4 items-center justify-center rounded bg-blue-100 dark:bg-blue-900/30"><Circle className="h-2.5 w-2.5 fill-blue-700 text-blue-700 dark:fill-blue-400 dark:text-blue-400" /></span>
                            Aktif
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="flex h-4 w-4 items-center justify-center rounded bg-muted"><Circle className="h-2.5 w-2.5 text-muted-foreground" /></span>
                            Belum dimulai
                        </span>
                        <span className="flex items-center gap-1.5">
                            <span className="flex h-4 w-4 items-center justify-center rounded"><Minus className="h-2.5 w-2.5 text-muted-foreground/40" /></span>
                            Tidak ada
                        </span>
                    </div>
                </>
            )}
        </SectionCard>
    );
}

function AnggaranTable({ rows, totals }: { rows: MonthlyAnggaranRow[]; totals: AnggaranTotals }) {
    const hasAnyData = rows.some((r) => r.direncanakan > 0 || r.dicairkan !== null || r.realisasi !== null);

    return (
        <SectionCard title="Ringkasan Anggaran & Realisasi">
            {!hasAnyData ? (
                <p className="py-4 text-center text-sm text-muted-foreground">Belum ada data anggaran. PK belum dikompilasi.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-135 text-sm">
                        <thead>
                            <tr className="border-b">
                                <th className="py-2 text-left font-medium">Bulan</th>
                                <th className="py-2 text-right font-medium">Direncanakan</th>
                                <th className="py-2 text-right font-medium">Dicairkan</th>
                                <th className="py-2 text-right font-medium">Realisasi</th>
                                <th className="py-2 text-right font-medium">Refund</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.bulan} className="border-b last:border-0">
                                    <td className="py-1.5">{row.bulanLabel}</td>
                                    <td className="py-1.5 text-right tabular-nums">Rp {formatRupiah(row.direncanakan)}</td>
                                    <td className="py-1.5 text-right tabular-nums">{row.dicairkan !== null ? `Rp ${formatRupiah(row.dicairkan)}` : '\u2014'}</td>
                                    <td className="py-1.5 text-right tabular-nums">{row.realisasi !== null ? `Rp ${formatRupiah(row.realisasi)}` : '\u2014'}</td>
                                    <td className="py-1.5 text-right tabular-nums">{row.refund !== null ? `Rp ${formatRupiah(row.refund)}` : '\u2014'}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 font-semibold">
                                <td className="py-2">Total</td>
                                <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.direncanakan)}</td>
                                <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.dicairkan)}</td>
                                <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.realisasi)}</td>
                                <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.refund)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </SectionCard>
    );
}

function ActiveWorkflowCard({ item }: { item: ActiveWorkflowItem }) {
    const isParallel = item.currentSteps.length > 1;

    return (
        <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Circle className="h-2 w-2 shrink-0 fill-blue-500 text-blue-500" />
                    <span className="truncate">{item.workflowLabel}</span>
                </div>
                <div className="mt-1">
                    {isParallel ? (
                        <ul className="space-y-0.5">
                            {item.currentSteps.map((s) => (
                                <li key={s.stepCode} className="flex items-center gap-1.5 text-sm">
                                    <span className="text-muted-foreground">&middot;</span>
                                    <span className="font-mono text-xs font-semibold">{s.stepCode}</span>
                                    <span>: {s.stepName}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="flex items-center gap-1.5 text-sm">
                            <span className="font-mono text-xs font-semibold">{item.currentSteps[0]?.stepCode}</span>
                            <span>: {item.currentSteps[0]?.stepName}</span>
                        </div>
                    )}
                </div>
            </div>
            {item.canAct && item.actionUrl ? (
                <Button variant="outline" size="sm" className="shrink-0 text-xs" asChild>
                    <Link href={item.actionUrl}>Kerjakan &rarr;</Link>
                </Button>
            ) : (
                <Button variant="ghost" size="sm" className="shrink-0 text-xs" asChild>
                    <Link href={item.showUrl}>
                        Lihat <ExternalLink className="ml-1 h-3 w-3" />
                    </Link>
                </Button>
            )}
        </div>
    );
}

// --- Main Page ---

export default function TeamDashboard({
    team,
    year,
    availableYears,
    pkStatus,
    calendar,
    anggaranRows,
    anggaranTotals,
    activeWorkflows,
}: TeamDashboardProps) {
    // No PP06 at all
    if (!team || availableYears.length === 0) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Tim" />
                <div className="space-y-6 p-6">
                    <Heading title={team ? `Dashboard Tim \u00b7 ${team.name}` : 'Tim'} description="Dashboard tim" />
                    <div className="rounded-lg border bg-card p-8 text-center shadow-sm">
                        <p className="text-sm text-muted-foreground">
                            Belum ada Periode Tahunan (PP) yang dikompilasi.<br />
                            Hubungi administrator untuk memulai PP.
                        </p>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const handleYearChange = (value: string) => {
        router.get(teamIndex(), { year: value }, { preserveState: false });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tim" />
            <div className="space-y-4 p-6">
                {/* Header with year selector */}
                <div className="flex items-start justify-between gap-4">
                    <Heading title={`Dashboard Tim \u00b7 ${team.name}`} description="Dashboard tim" />
                    <Select
                        value={String(year)}
                        onValueChange={handleYearChange}
                        disabled={availableYears.length <= 1}
                    >
                        <SelectTrigger className="w-24">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {availableYears.map((y) => (
                                <SelectItem key={y} value={String(y)}>
                                    {y}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Status PK */}
                {pkStatus && <PkStatusBar pkStatus={pkStatus} />}

                {/* Calendar Grid */}
                <CalendarGrid calendar={calendar} />

                {/* Anggaran Table */}
                <AnggaranTable rows={anggaranRows} totals={anggaranTotals} />

                {/* Active Workflows */}
                <SectionCard
                    title="Workflow Aktif"
                    headerRight={
                        <span className="text-xs text-muted-foreground">{activeWorkflows.length} aktif</span>
                    }
                >
                    {activeWorkflows.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">Tidak ada workflow aktif.</p>
                    ) : (
                        <div className="space-y-2">
                            {activeWorkflows.map((item, i) => (
                                <ActiveWorkflowCard key={`${item.workflowType}-${item.workflowLabel}-${i}`} item={item} />
                            ))}
                        </div>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}
