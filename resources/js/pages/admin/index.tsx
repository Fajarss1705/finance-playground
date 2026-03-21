import { Head, Link, router } from '@inertiajs/react';
import { Circle, ExternalLink } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { cn, formatRupiah } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Manajemen', href: adminIndex() }];

const BULAN_LABELS = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

// --- Types ---

type AdminActiveStepInfo = {
    stepCode: string;
    stepName: string;
};

type AdminActiveWorkflowItem = {
    teamId: number | null;
    teamName: string | null;
    workflowType: 'pp' | 'pk' | 'pabd' | 'prbl';
    workflowLabel: string;
    showUrl: string;
    canAct: boolean;
    actionUrl: string | null;
    currentSteps: AdminActiveStepInfo[];
};

type TeamSummaryRow = {
    teamId: number;
    teamName: string;
    plafon: number;
    totalPkRaker: number;
    totalPkProposal: number;
    pabdCompleted: number;
    pabdTotal: number;
    prblCompleted: number;
    prblTotal: number;
    totalRealisasi: number;
};

type TeamOption = {
    id: number;
    name: string;
};

type TeamMonthlyDataRow = {
    teamId: number;
    bulan: number;
    pkRaker: number;
    pkProposal: number;
    dicairkan: number | null;
    realisasi: number | null;
    refund: number | null;
};

type AdminDashboardProps = {
    year: number | null;
    availableYears: number[];
    activeWorkflows: AdminActiveWorkflowItem[];
    teamSummaries: TeamSummaryRow[];
    teamOptions: TeamOption[];
    monthlyData: TeamMonthlyDataRow[];
};

// --- Helper Components ---

function ProgressFraction({ completed, total }: { completed: number; total: number }) {
    if (total === 0) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    const allDone = completed === total;

    return (
        <span className={cn(allDone && 'text-green-600 dark:text-green-400')}>
            {completed}/{total}
        </span>
    );
}

function WorkQueueTable({ items }: { items: AdminActiveWorkflowItem[] }) {
    const [tab, setTab] = useState<'actionable' | 'all'>('actionable');

    const actionableItems = useMemo(() => items.filter((i) => i.canAct), [items]);
    const displayItems = tab === 'actionable' ? actionableItems : items;

    return (
        <SectionCard
            title="Pekerjaan Aktif"
            headerRight={<span className="text-xs text-muted-foreground">{items.length} aktif</span>}
        >
            {/* Tab buttons */}
            <div className="mb-3 flex gap-1">
                <button
                    type="button"
                    onClick={() => setTab('actionable')}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        tab === 'actionable'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80',
                    )}
                >
                    Menunggu Anda ({actionableItems.length})
                </button>
                <button
                    type="button"
                    onClick={() => setTab('all')}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        tab === 'all'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80',
                    )}
                >
                    Semua ({items.length})
                </button>
            </div>

            {displayItems.length === 0 ? (
                <p className="py-4 text-center text-sm text-muted-foreground">
                    {tab === 'actionable' ? 'Tidak ada pekerjaan yang menunggu Anda.' : 'Tidak ada workflow aktif saat ini.'}
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-140 text-sm">
                        <thead>
                            <tr className="border-b">
                                <th className="py-2 text-left font-medium">Tim</th>
                                <th className="py-2 text-left font-medium">Workflow</th>
                                <th className="py-2 text-left font-medium">Step Aktif</th>
                                <th className="py-2 text-right font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {displayItems.map((item, i) => (
                                <tr key={`${item.workflowType}-${item.workflowLabel}-${i}`} className="border-b last:border-0">
                                    <td className="py-2">{item.teamName ?? <span className="text-muted-foreground">&mdash;</span>}</td>
                                    <td className="py-2">
                                        <div className="flex items-center gap-1.5">
                                            <Circle className="h-2 w-2 shrink-0 fill-blue-500 text-blue-500" />
                                            <span className="truncate">{item.workflowLabel}</span>
                                        </div>
                                    </td>
                                    <td className="py-2">
                                        {item.currentSteps.length > 1 ? (
                                            <ul className="space-y-0.5">
                                                {item.currentSteps.map((s) => (
                                                    <li key={s.stepCode} className="flex items-center gap-1">
                                                        <span className="font-mono text-xs font-semibold">{s.stepCode}</span>
                                                        <span>: {s.stepName}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <span>
                                                <span className="font-mono text-xs font-semibold">{item.currentSteps[0]?.stepCode}</span>
                                                {': '}
                                                {item.currentSteps[0]?.stepName}
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">
                                        {item.canAct && item.actionUrl ? (
                                            <Button variant="outline" size="sm" className="text-xs" asChild>
                                                <Link href={item.actionUrl}>Kerjakan &rarr;</Link>
                                            </Button>
                                        ) : (
                                            <Button variant="ghost" size="sm" className="text-xs" asChild>
                                                <Link href={item.showUrl}>
                                                    Lihat <ExternalLink className="ml-1 h-3 w-3" />
                                                </Link>
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SectionCard>
    );
}

function TeamSummaryTable({ summaries }: { summaries: TeamSummaryRow[] }) {
    const totals = useMemo(() => {
        const t = { plafon: 0, pkRaker: 0, pkProposal: 0, pabdC: 0, pabdT: 0, prblC: 0, prblT: 0, realisasi: 0 };
        for (const row of summaries) {
            t.plafon += row.plafon;
            t.pkRaker += row.totalPkRaker;
            t.pkProposal += row.totalPkProposal;
            t.pabdC += row.pabdCompleted;
            t.pabdT += row.pabdTotal;
            t.prblC += row.prblCompleted;
            t.prblT += row.prblTotal;
            t.realisasi += row.totalRealisasi;
        }
        return t;
    }, [summaries]);

    if (summaries.length === 0) {
        return (
            <SectionCard title="Ringkasan Per Tim">
                <p className="py-4 text-center text-sm text-muted-foreground">Belum ada data tim.</p>
            </SectionCard>
        );
    }

    return (
        <SectionCard title="Ringkasan Per Tim">
            <div className="overflow-x-auto">
                <table className="w-full min-w-160 text-sm">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2 text-left font-medium">Tim</th>
                            <th className="py-2 text-right font-medium">Plafon</th>
                            <th className="py-2 text-right font-medium">PK Raker</th>
                            <th className="py-2 text-right font-medium">PK Proposal</th>
                            <th className="py-2 text-center font-medium">PABD</th>
                            <th className="py-2 text-center font-medium">PRBL</th>
                            <th className="py-2 text-right font-medium">Realisasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        {summaries.map((row) => (
                            <tr key={row.teamId} className="border-b last:border-0">
                                <td className="py-1.5">{row.teamName}</td>
                                <td className="py-1.5 text-right tabular-nums">Rp {formatRupiah(row.plafon)}</td>
                                <td className="py-1.5 text-right tabular-nums">Rp {formatRupiah(row.totalPkRaker)}</td>
                                <td className="py-1.5 text-right tabular-nums">Rp {formatRupiah(row.totalPkProposal)}</td>
                                <td className="py-1.5 text-center tabular-nums">
                                    <ProgressFraction completed={row.pabdCompleted} total={row.pabdTotal} />
                                </td>
                                <td className="py-1.5 text-center tabular-nums">
                                    <ProgressFraction completed={row.prblCompleted} total={row.prblTotal} />
                                </td>
                                <td className="py-1.5 text-right tabular-nums">Rp {formatRupiah(row.totalRealisasi)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t-2 font-semibold">
                            <td className="py-2">Total</td>
                            <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.plafon)}</td>
                            <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.pkRaker)}</td>
                            <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.pkProposal)}</td>
                            <td className="py-2 text-center tabular-nums">
                                <ProgressFraction completed={totals.pabdC} total={totals.pabdT} />
                            </td>
                            <td className="py-2 text-center tabular-nums">
                                <ProgressFraction completed={totals.prblC} total={totals.prblT} />
                            </td>
                            <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.realisasi)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </SectionCard>
    );
}

type AggregatedMonth = {
    pkRaker: number;
    pkProposal: number;
    dicairkan: number | null;
    realisasi: number | null;
    refund: number | null;
};

function AdminAnggaranSection({ teamOptions, monthlyData }: { teamOptions: TeamOption[]; monthlyData: TeamMonthlyDataRow[] }) {
    const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null);

    const { rows, totals } = useMemo(() => {
        const filtered = selectedTeamId ? monthlyData.filter((d) => d.teamId === selectedTeamId) : monthlyData;

        const byMonth = new Map<number, AggregatedMonth>();
        for (const row of filtered) {
            const existing = byMonth.get(row.bulan) ?? {
                pkRaker: 0,
                pkProposal: 0,
                dicairkan: null as number | null,
                realisasi: null as number | null,
                refund: null as number | null,
            };
            existing.pkRaker += row.pkRaker;
            existing.pkProposal += row.pkProposal;
            if (row.dicairkan !== null) existing.dicairkan = (existing.dicairkan ?? 0) + row.dicairkan;
            if (row.realisasi !== null) existing.realisasi = (existing.realisasi ?? 0) + row.realisasi;
            if (row.refund !== null) existing.refund = (existing.refund ?? 0) + row.refund;
            byMonth.set(row.bulan, existing);
        }

        const aggregatedRows = [];
        const t = { pkRaker: 0, pkProposal: 0, dicairkan: 0, realisasi: 0, refund: 0 };
        for (let bulan = 1; bulan <= 12; bulan++) {
            const m = byMonth.get(bulan) ?? { pkRaker: 0, pkProposal: 0, dicairkan: null, realisasi: null, refund: null };
            aggregatedRows.push({ bulan, bulanLabel: BULAN_LABELS[bulan - 1], ...m });
            t.pkRaker += m.pkRaker;
            t.pkProposal += m.pkProposal;
            if (m.dicairkan !== null) t.dicairkan += m.dicairkan;
            if (m.realisasi !== null) t.realisasi += m.realisasi;
            if (m.refund !== null) t.refund += m.refund;
        }

        return { rows: aggregatedRows, totals: t };
    }, [monthlyData, selectedTeamId]);

    const hasAnyData = rows.some((r) => r.pkRaker > 0 || r.pkProposal > 0 || r.dicairkan !== null || r.realisasi !== null);

    return (
        <SectionCard
            title="Anggaran & Realisasi Bulanan"
            headerRight={
                teamOptions.length > 0 ? (
                    <Select
                        value={selectedTeamId ? String(selectedTeamId) : 'all'}
                        onValueChange={(v) => setSelectedTeamId(v === 'all' ? null : Number(v))}
                    >
                        <SelectTrigger className="h-7 w-40 text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua Tim</SelectItem>
                            {teamOptions.map((t) => (
                                <SelectItem key={t.id} value={String(t.id)}>
                                    {t.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : undefined
            }
        >
            {!hasAnyData ? (
                <p className="py-4 text-center text-sm text-muted-foreground">
                    Belum ada data anggaran. Belum ada PK yang dikompilasi.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-150 text-sm">
                        <thead>
                            <tr className="border-b">
                                <th className="py-2 text-left font-medium">Bulan</th>
                                <th className="py-2 text-right font-medium">PK Raker</th>
                                <th className="py-2 text-right font-medium">PK Proposal</th>
                                <th className="py-2 text-right font-medium">Dicairkan</th>
                                <th className="py-2 text-right font-medium">Realisasi</th>
                                <th className="py-2 text-right font-medium">Refund</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.bulan} className="border-b last:border-0">
                                    <td className="py-1.5">{row.bulanLabel}</td>
                                    <td className="py-1.5 text-right tabular-nums">Rp {formatRupiah(row.pkRaker)}</td>
                                    <td className="py-1.5 text-right tabular-nums">Rp {formatRupiah(row.pkProposal)}</td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {row.dicairkan !== null ? `Rp ${formatRupiah(row.dicairkan)}` : '\u2014'}
                                    </td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {row.realisasi !== null ? `Rp ${formatRupiah(row.realisasi)}` : '\u2014'}
                                    </td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {row.refund !== null ? `Rp ${formatRupiah(row.refund)}` : '\u2014'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 font-semibold">
                                <td className="py-2">Total</td>
                                <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.pkRaker)}</td>
                                <td className="py-2 text-right tabular-nums">Rp {formatRupiah(totals.pkProposal)}</td>
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

// --- Main Page ---

export default function AdminDashboard({
    year,
    availableYears,
    activeWorkflows,
    teamSummaries,
    teamOptions,
    monthlyData,
}: AdminDashboardProps) {
    // No PP06 at all
    if (!year || availableYears.length === 0) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Manajemen" />
                <div className="space-y-6 p-6">
                    <Heading title="Manajemen" description="Ringkasan organisasi" />
                    <div className="rounded-lg border bg-card p-8 text-center shadow-sm">
                        <p className="text-sm text-muted-foreground">
                            Belum ada Periode Tahunan (PP) yang dikompilasi.
                            <br />
                            Dashboard akan tersedia setelah PP06 dikompilasi.
                        </p>
                    </div>
                </div>
            </AppLayout>
        );
    }

    const handleYearChange = (value: string) => {
        router.get(adminIndex(), { year: value }, { preserveState: false });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Manajemen" />
            <div className="space-y-4 p-6">
                {/* Header with year selector */}
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Manajemen" description="Ringkasan organisasi" />
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

                {/* Section 1: Work Queue */}
                <WorkQueueTable items={activeWorkflows} />

                {/* Section 2: Per-Team Summary */}
                <TeamSummaryTable summaries={teamSummaries} />

                {/* Section 3: Monthly Financial */}
                <AdminAnggaranSection teamOptions={teamOptions} monthlyData={monthlyData} />
            </div>
        </AppLayout>
    );
}
