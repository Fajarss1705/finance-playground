import { Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type PrblWorkflowRow = {
    id: number;
    bulan_laporan: number;
    bulan_label: string;
    tahun_laporan: number;
    status: string;
    step_aktif: string | null;
    pp_label: string;
    total_realisasi: number | null;
    total_refund: number | null;
    terakhir_name: string | null;
    terakhir_role: string | null;
    tanggal: string;
    team_name?: string;
};

type Filters = {
    status: string | null;
    pp: string | null;
    bulan: string | null;
    team?: string | null;
};

type Props = {
    workflows: {
        data: PrblWorkflowRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Filters;
    availablePpPeriods: { value: string; label: string }[];
    availableTeams?: { value: string; label: string }[];
    scope: 'team' | 'admin';
};

const statusOptions = [
    { value: '', label: 'Semua' },
    { value: 'active', label: 'Aktif' },
    { value: 'completed', label: 'Selesai' },
];

const bulanOptions = [
    { value: '', label: 'Semua' },
    { value: '1', label: 'Januari' },
    { value: '2', label: 'Februari' },
    { value: '3', label: 'Maret' },
    { value: '4', label: 'April' },
    { value: '5', label: 'Mei' },
    { value: '6', label: 'Juni' },
    { value: '7', label: 'Juli' },
    { value: '8', label: 'Agustus' },
    { value: '9', label: 'September' },
    { value: '10', label: 'Oktober' },
    { value: '11', label: 'November' },
    { value: '12', label: 'Desember' },
];

export default function PrblIndex({
    workflows,
    filters,
    availablePpPeriods,
    availableTeams,
    scope,
}: Props) {
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';
    const isAdmin = scope === 'admin';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Laporan Bulanan', href: `${scopeBase}/workflows/prbl` },
    ];

    function applyFilter(key: string, value: string) {
        const params = new URLSearchParams(window.location.search);
        if (value) {
            params.set(key, value);
        } else {
            params.delete(key);
        }
        params.delete('page');
        router.visit(`${scopeBase}/workflows/prbl?${params.toString()}`, { preserveState: true });
    }

    const colCount = isAdmin ? 9 : 8;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laporan Bulanan" />
            <div className="space-y-6 p-6">
                <Heading
                    title="Laporan Bulanan"
                    description={isAdmin ? 'Semua laporan bulanan' : 'Daftar laporan bulanan tim Anda'}
                />

                {/* Filter Bar */}
                <div className="flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3">
                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium text-muted-foreground">Status:</label>
                        <select
                            value={filters.status ?? ''}
                            onChange={(e) => applyFilter('status', e.target.value)}
                            className="h-8 rounded-md border bg-background px-2 text-sm"
                        >
                            {statusOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium text-muted-foreground">PP:</label>
                        <select
                            value={filters.pp ?? ''}
                            onChange={(e) => applyFilter('pp', e.target.value)}
                            className="h-8 rounded-md border bg-background px-2 text-sm"
                        >
                            <option value="">Semua</option>
                            {availablePpPeriods.map((p) => (
                                <option key={p.value} value={p.value}>{p.label}</option>
                            ))}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium text-muted-foreground">Bulan:</label>
                        <select
                            value={filters.bulan ?? ''}
                            onChange={(e) => applyFilter('bulan', e.target.value)}
                            className="h-8 rounded-md border bg-background px-2 text-sm"
                        >
                            {bulanOptions.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                    </div>
                    {isAdmin && availableTeams && (
                        <div className="flex items-center gap-2">
                            <label className="text-sm font-medium text-muted-foreground">Tim:</label>
                            <select
                                value={filters.team ?? ''}
                                onChange={(e) => applyFilter('team', e.target.value)}
                                className="h-8 rounded-md border bg-background px-2 text-sm"
                            >
                                <option value="">Semua</option>
                                {availableTeams.map((t) => (
                                    <option key={t.value} value={t.value}>{t.label}</option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="min-w-200 w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                {isAdmin && <th className="px-4 py-3 text-left font-medium">Tim</th>}
                                <th className="px-4 py-3 text-left font-medium">Bulan Laporan</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Step Aktif</th>
                                <th className="px-4 py-3 text-left font-medium">PP</th>
                                <th className="px-4 py-3 text-right font-medium">Total Real.</th>
                                <th className="px-4 py-3 text-right font-medium">Total Ref.</th>
                                <th className="px-4 py-3 text-left font-medium">Terakhir</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            {workflows.data.length === 0 ? (
                                <tr>
                                    <td colSpan={colCount} className="px-4 py-8 text-center text-muted-foreground">
                                        <FileText className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada laporan bulanan.
                                    </td>
                                </tr>
                            ) : (
                                workflows.data.map((wf) => (
                                    <tr
                                        key={wf.id}
                                        className="cursor-pointer border-b transition-colors last:border-0 hover:bg-muted/30"
                                        onClick={() => router.visit(`${scopeBase}/workflows/prbl/${wf.id}`)}
                                    >
                                        {isAdmin && (
                                            <td className="px-4 py-3 text-muted-foreground">{wf.team_name}</td>
                                        )}
                                        <td className="px-4 py-3 font-medium">
                                            <Link
                                                href={`${scopeBase}/workflows/prbl/${wf.id}`}
                                                className="text-primary hover:underline"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {wf.bulan_label} {wf.tahun_laporan}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <WorkflowStatusBadge status={wf.status} />
                                        </td>
                                        <td className="px-4 py-3">
                                            {wf.step_aktif ? (
                                                <Badge variant="outline" className="font-mono text-xs">
                                                    {wf.step_aktif}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{wf.pp_label}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {wf.total_realisasi != null ? formatRupiah(wf.total_realisasi) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {wf.total_refund != null ? formatRupiah(wf.total_refund) : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {wf.terakhir_name ? (
                                                <div>
                                                    <span className="text-sm">{wf.terakhir_name}</span>
                                                    {wf.terakhir_role && (
                                                        <span className="ml-1 text-xs text-muted-foreground">({wf.terakhir_role})</span>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{wf.tanggal}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    links={workflows.links}
                    currentPage={workflows.current_page}
                    lastPage={workflows.last_page}
                    total={workflows.total}
                />
            </div>
        </AppLayout>
    );
}
