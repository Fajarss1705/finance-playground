import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type PkWorkflowRow = {
    id: number;
    program: string | null;
    tipe: 'raker' | 'proposal';
    status: string;
    step_aktif: string | null;
    pp_label: string;
    total_anggaran: number | null;
    revision: number | null;
    terakhir_name: string | null;
    terakhir_role: string | null;
    tanggal: string;
    // Admin-only
    team_name?: string;
    dibuat_oleh_name?: string;
    dibuat_oleh_role?: string | null;
};

type EligiblePp = {
    id: number;
    label: string;
    tahun: number;
};

type Filters = {
    status: string | null;
    pp: string | null;
    tipe: string | null;
    team?: string | null;
    trash: boolean;
};

type Props = {
    workflows: {
        data: PkWorkflowRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Filters;
    availablePpPeriods: { value: string; label: string }[];
    availableTeams?: { value: string; label: string }[];
    canCreate?: boolean;
    eligiblePpWorkflows?: EligiblePp[];
    createMessage?: string | null;
    scope: 'team' | 'admin';
    teamName?: string;
};

const statusOptions = [
    { value: '', label: 'Semua' },
    { value: 'active', label: 'Aktif' },
    { value: 'completed', label: 'Selesai' },
    { value: 'revising', label: 'Dalam Revisi' },
    { value: 'terminated', label: 'Dibatalkan' },
];

const tipeOptions = [
    { value: '', label: 'Semua' },
    { value: 'raker', label: 'Raker' },
    { value: 'proposal', label: 'Proposal' },
];

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID').format(value);
}

export default function PkIndex({
    workflows,
    filters,
    availablePpPeriods,
    availableTeams,
    canCreate,
    eligiblePpWorkflows,
    createMessage,
    scope,
    teamName,
}: Props) {
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';
    const isAdmin = scope === 'admin';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Perencanaan Kegiatan', href: `${scopeBase}/workflows/pk` },
    ];

    const [ppDialogOpen, setPpDialogOpen] = useState(false);
    const [selectedPpId, setSelectedPpId] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);

    function applyFilter(key: string, value: string) {
        const params = new URLSearchParams(window.location.search);
        if (value) {
            params.set(key, value);
        } else {
            params.delete(key);
        }
        params.delete('page');
        router.visit(`${scopeBase}/workflows/pk?${params.toString()}`, { preserveState: true });
    }

    function handleCreate() {
        if (!eligiblePpWorkflows || eligiblePpWorkflows.length === 0) return;

        if (eligiblePpWorkflows.length === 1) {
            setCreating(true);
            router.post(`/team/workflows/pk/create/${eligiblePpWorkflows[0].id}`, {}, {
                onFinish: () => setCreating(false),
            });
        } else {
            setSelectedPpId(eligiblePpWorkflows[0].id);
            setPpDialogOpen(true);
        }
    }

    function handleCreateWithPp() {
        if (!selectedPpId) return;
        setCreating(true);
        router.post(`/team/workflows/pk/create/${selectedPpId}`, {}, {
            onFinish: () => { setCreating(false); setPpDialogOpen(false); },
        });
    }

    const colCount = isAdmin ? 11 : 9;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Perencanaan Kegiatan" />
            <div className="space-y-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <Heading
                                title="Perencanaan Kegiatan"
                                description={isAdmin ? 'Semua program kegiatan' : 'Daftar program kegiatan tim Anda'}
                            />
                            {teamName && <Badge variant="secondary">{teamName}</Badge>}
                        </div>
                    </div>
                    {scope === 'team' && canCreate && (
                        <Button onClick={handleCreate} disabled={creating}>
                            <Plus className="mr-1 h-4 w-4" />
                            Buat PK
                        </Button>
                    )}
                </div>

                {/* Create message (team scope, when can't create) */}
                {scope === 'team' && !canCreate && createMessage && (
                    <p className="text-sm text-muted-foreground">{createMessage}</p>
                )}

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
                        <label className="text-sm font-medium text-muted-foreground">Tipe:</label>
                        <select
                            value={filters.tipe ?? ''}
                            onChange={(e) => applyFilter('tipe', e.target.value)}
                            className="h-8 rounded-md border bg-background px-2 text-sm"
                        >
                            {tipeOptions.map((opt) => (
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

                {/* Table */}
                <div className="overflow-x-auto rounded-lg border">
                    <table className="min-w-300 w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                {isAdmin && <th className="px-4 py-3 text-left font-medium">Tim</th>}
                                <th className="px-4 py-3 text-left font-medium">Program</th>
                                <th className="px-4 py-3 text-left font-medium">Tipe</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Step Aktif</th>
                                <th className="px-4 py-3 text-left font-medium">PP</th>
                                <th className="px-4 py-3 text-right font-medium">Total Angg.</th>
                                <th className="px-4 py-3 text-right font-medium">Rev.</th>
                                <th className="px-4 py-3 text-left font-medium">Terakhir</th>
                                {isAdmin && <th className="px-4 py-3 text-left font-medium">Dibuat oleh</th>}
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            {workflows.data.length === 0 ? (
                                <tr>
                                    <td colSpan={colCount} className="px-4 py-8 text-center text-muted-foreground">
                                        <FileText className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        {filters.trash
                                            ? 'Tidak ada program kegiatan di tong sampah.'
                                            : 'Belum ada program kegiatan.'}
                                    </td>
                                </tr>
                            ) : (
                                workflows.data.map((wf) => (
                                    <tr
                                        key={wf.id}
                                        className="cursor-pointer border-b transition-colors last:border-0 hover:bg-muted/30"
                                        onClick={() => router.visit(`${scopeBase}/workflows/pk/${wf.id}`)}
                                    >
                                        {isAdmin && (
                                            <td className="px-4 py-3 text-muted-foreground">{wf.team_name}</td>
                                        )}
                                        <td className="max-w-48 truncate px-4 py-3 font-medium">
                                            <Link
                                                href={`${scopeBase}/workflows/pk/${wf.id}`}
                                                className="text-primary hover:underline"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {wf.program ?? '—'}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant="outline"
                                                className={wf.tipe === 'raker'
                                                    ? 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300'
                                                    : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300'}
                                            >
                                                {wf.tipe === 'raker' ? 'Raker' : 'Proposal'}
                                            </Badge>
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
                                            {wf.total_anggaran != null ? formatRupiah(wf.total_anggaran) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right text-muted-foreground">{wf.revision ?? '—'}</td>
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
                                        {isAdmin && (
                                            <td className="px-4 py-3">
                                                {wf.dibuat_oleh_name ? (
                                                    <div>
                                                        <span className="text-sm">{wf.dibuat_oleh_name}</span>
                                                        {wf.dibuat_oleh_role && (
                                                            <span className="ml-1 text-xs text-muted-foreground">({wf.dibuat_oleh_role})</span>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                        )}
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

            {/* PP Selection Dialog (multiple eligible PPs) */}
            <Dialog open={ppDialogOpen} onOpenChange={setPpDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Pilih Periode PP</DialogTitle>
                        <DialogDescription>Pilih periode PP untuk program kegiatan baru:</DialogDescription>
                    </DialogHeader>
                    <div className="py-2">
                        <label className="text-sm font-medium">Periode PP</label>
                        <select
                            value={selectedPpId ?? ''}
                            onChange={(e) => setSelectedPpId(Number(e.target.value))}
                            className="mt-1 w-full rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            {eligiblePpWorkflows?.map((pp) => (
                                <option key={pp.id} value={pp.id}>{pp.label}</option>
                            ))}
                        </select>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPpDialogOpen(false)}>Batal</Button>
                        <Button onClick={handleCreateWithPp} disabled={creating}>Buat PK</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
