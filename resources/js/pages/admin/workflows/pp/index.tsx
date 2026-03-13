import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus, Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type Workflow = {
    id: number;
    label: string;
    status: string;
    step_aktif: string | null;
    pra_raker: string | null;
    penetapan: string | null;
    tim_count: number | null;
    total_anggaran: number | null;
    terakhir_name: string | null;
    terakhir_role: string | null;
    revision: number | null;
    dibuat_oleh_name: string;
    dibuat_oleh_role: string | null;
    tanggal: string;
};

type Filters = {
    status: string | null;
    tahun: string | null;
    trash: boolean;
};

type Props = {
    workflows: {
        data: Workflow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Filters;
    availableTahun: number[];
    canCreate: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
];

const statusOptions = [
    { value: '', label: 'Semua' },
    { value: 'active', label: 'Aktif' },
    { value: 'completed', label: 'Selesai' },
    { value: 'revising', label: 'Dalam Revisi' },
    { value: 'terminated', label: 'Dibatalkan' },
];

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID').format(value);
}

function applyFilter(key: string, value: string) {
    const params = new URLSearchParams(window.location.search);
    if (value) {
        params.set(key, value);
    } else {
        params.delete(key);
    }
    params.delete('page');
    router.visit(`/admin/workflows/pp?${params.toString()}`, { preserveState: true });
}

export default function PpIndex({ workflows, filters, availableTahun, canCreate }: Props) {
    function handleCreate() {
        router.post('/admin/workflows/pp/create');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Perencanaan Periode" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title="Perencanaan Periode" description="Kelola perencanaan periode tahunan" />
                    {canCreate && (
                        <Button onClick={handleCreate}>
                            <Plus className="mr-1 h-4 w-4" />
                            Buat PP
                        </Button>
                    )}
                </div>

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
                                <option key={opt.value} value={opt.value}>
                                    {opt.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm font-medium text-muted-foreground">Tahun:</label>
                        <select
                            value={filters.tahun ?? ''}
                            onChange={(e) => applyFilter('tahun', e.target.value)}
                            className="h-8 rounded-md border bg-background px-2 text-sm"
                        >
                            <option value="">Semua</option>
                            {availableTahun.map((t) => (
                                <option key={t} value={t}>
                                    {t}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="ml-auto">
                        <Button
                            variant={filters.trash ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => applyFilter('trash', filters.trash ? '' : '1')}
                        >
                            <Trash2 className="mr-1 h-3.5 w-3.5" />
                            Tong Sampah
                        </Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="min-w-300 w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">Label</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Step Aktif</th>
                                <th className="px-4 py-3 text-left font-medium">Pra-Raker</th>
                                <th className="px-4 py-3 text-left font-medium">Penetap.</th>
                                <th className="px-4 py-3 text-right font-medium">Tim</th>
                                <th className="px-4 py-3 text-right font-medium">Total Angg.</th>
                                <th className="px-4 py-3 text-left font-medium">Terakhir</th>
                                <th className="px-4 py-3 text-right font-medium">Rev.</th>
                                <th className="px-4 py-3 text-left font-medium">Dibuat oleh</th>
                                <th className="px-4 py-3 text-left font-medium">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            {workflows.data.length === 0 ? (
                                <tr>
                                    <td colSpan={11} className="px-4 py-8 text-center text-muted-foreground">
                                        <FileText className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        {filters.trash ? 'Tong sampah kosong.' : 'Belum ada perencanaan periode.'}
                                    </td>
                                </tr>
                            ) : (
                                workflows.data.map((wf) => (
                                    <tr
                                        key={wf.id}
                                        className="border-b last:border-0 cursor-pointer hover:bg-muted/30 transition-colors"
                                        onClick={() => router.visit(`/admin/workflows/pp/${wf.id}`)}
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            <Link
                                                href={`/admin/workflows/pp/${wf.id}`}
                                                className="text-primary hover:underline"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {wf.label}
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
                                        <td className="px-4 py-3 text-muted-foreground">{wf.pra_raker ?? '—'}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{wf.penetapan ?? '—'}</td>
                                        <td className="px-4 py-3 text-right text-muted-foreground">{wf.tim_count ?? '—'}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {wf.total_anggaran != null ? formatRupiah(wf.total_anggaran) : '—'}
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
                                        <td className="px-4 py-3 text-right text-muted-foreground">{wf.revision ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <div>
                                                <span className="text-sm">{wf.dibuat_oleh_name}</span>
                                                {wf.dibuat_oleh_role && (
                                                    <span className="ml-1 text-xs text-muted-foreground">({wf.dibuat_oleh_role})</span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">{wf.tanggal}</td>
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
