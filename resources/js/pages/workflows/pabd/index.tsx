import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type PabdWorkflowRow = {
    id: number;
    bulan_anggaran: number;
    bulan_label: string;
    tahun_anggaran: number;
    status: string;
    step_aktif: string | null;
    pp_label: string;
    total_anggaran: number | null;
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

type CreatableTeamMonth = {
    team_id: number;
    team_name: string;
    months: number[];
};

type CreatablePpOption = {
    pp_workflow_id: number;
    pp_label: string;
    teams: CreatableTeamMonth[];
};

type Props = {
    workflows: {
        data: PabdWorkflowRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Filters;
    availablePpPeriods: { value: string; label: string }[];
    availableTeams?: { value: string; label: string }[];
    canAdminReset?: boolean;
    canAdminCreate?: boolean;
    creatablePpOptions?: CreatablePpOption[];
    scope: 'team' | 'admin';
};

const statusOptions = [
    { value: '', label: 'Semua' },
    { value: 'active', label: 'Aktif' },
    { value: 'completed', label: 'Selesai' },
];

const bulanLabels: Record<number, string> = {
    1: 'Januari', 2: 'Februari', 3: 'Maret', 4: 'April',
    5: 'Mei', 6: 'Juni', 7: 'Juli', 8: 'Agustus',
    9: 'September', 10: 'Oktober', 11: 'November', 12: 'Desember',
};

const bulanOptions = [
    { value: '', label: 'Semua' },
    ...Object.entries(bulanLabels).map(([v, l]) => ({ value: v, label: l })),
];

export default function PabdIndex({
    workflows,
    filters,
    availablePpPeriods,
    availableTeams,
    canAdminReset,
    canAdminCreate,
    creatablePpOptions,
    scope,
}: Props) {
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';
    const isAdmin = scope === 'admin';
    const [resettingId, setResettingId] = useState<number | null>(null);

    // Create modal state
    const [createOpen, setCreateOpen] = useState(false);
    const [createPpId, setCreatePpId] = useState<string>('');
    const [createTeamId, setCreateTeamId] = useState<string>('');
    const [createBulan, setCreateBulan] = useState<string>('');
    const [creating, setCreating] = useState(false);

    const selectedPp = creatablePpOptions?.find((p) => String(p.pp_workflow_id) === createPpId);
    const selectedTeams = selectedPp?.teams ?? [];
    const selectedTeamMonths = selectedTeams.find((t) => String(t.team_id) === createTeamId)?.months ?? [];

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Anggaran Bulanan', href: `${scopeBase}/workflows/pabd` },
    ];

    function applyFilter(key: string, value: string) {
        const params = new URLSearchParams(window.location.search);
        if (value) {
            params.set(key, value);
        } else {
            params.delete(key);
        }
        params.delete('page');
        router.visit(`${scopeBase}/workflows/pabd?${params.toString()}`, { preserveState: true });
    }

    function handleAdminReset(wfId: number, { notes }: { notes: string; files: File[] }) {
        setResettingId(wfId);
        router.post(`/admin/workflows/pabd/${wfId}/admin-reset`, { notes }, {
            preserveScroll: true,
            onFinish: () => setResettingId(null),
        });
    }

    function handleAdminCreate() {
        if (!createPpId || !createTeamId || !createBulan) return;
        setCreating(true);
        router.post('/admin/workflows/pabd/admin-create', {
            pp_workflow_id: createPpId,
            team_id: createTeamId,
            bulan: createBulan,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setCreateOpen(false);
                setCreatePpId('');
                setCreateTeamId('');
                setCreateBulan('');
            },
            onFinish: () => setCreating(false),
        });
    }

    const showResetCol = isAdmin && canAdminReset;
    const colCount = (isAdmin ? 8 : 7) + (showResetCol ? 1 : 0);
    const hasCreatableOptions = creatablePpOptions && creatablePpOptions.length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anggaran Bulanan" />
            <div className="space-y-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Anggaran Bulanan"
                        description={isAdmin ? 'Semua pengajuan anggaran bulanan' : 'Daftar pengajuan anggaran bulanan tim Anda'}
                    />
                    {isAdmin && canAdminCreate && hasCreatableOptions && (
                        <Dialog open={createOpen} onOpenChange={(v) => { setCreateOpen(v); if (!v) { setCreatePpId(''); setCreateTeamId(''); setCreateBulan(''); } }}>
                            <DialogTrigger asChild>
                                <Button size="sm" className="gap-1.5 shrink-0">
                                    <Plus className="h-4 w-4" />
                                    Buat PABD
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>Buat PABD Baru</DialogTitle>
                                <DialogDescription>
                                    Pilih PP, tim, dan bulan untuk membuat PABD baru. Hanya bulan yang memiliki anggaran aktif dan belum memiliki PABD yang tersedia.
                                </DialogDescription>
                                <div className="space-y-4">
                                    <div className="space-y-1.5">
                                        <Label>Periode PP *</Label>
                                        <select
                                            value={createPpId}
                                            onChange={(e) => { setCreatePpId(e.target.value); setCreateTeamId(''); setCreateBulan(''); }}
                                            className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                        >
                                            <option value="">Pilih PP...</option>
                                            {creatablePpOptions?.map((p) => (
                                                <option key={p.pp_workflow_id} value={p.pp_workflow_id}>{p.pp_label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Tim *</Label>
                                        <select
                                            value={createTeamId}
                                            onChange={(e) => { setCreateTeamId(e.target.value); setCreateBulan(''); }}
                                            disabled={!createPpId}
                                            className="h-9 w-full rounded-md border bg-background px-3 text-sm disabled:opacity-50"
                                        >
                                            <option value="">{createPpId ? 'Pilih tim...' : 'Pilih PP dahulu'}</option>
                                            {selectedTeams.map((t) => (
                                                <option key={t.team_id} value={t.team_id}>{t.team_name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Bulan *</Label>
                                        <select
                                            value={createBulan}
                                            onChange={(e) => setCreateBulan(e.target.value)}
                                            disabled={!createTeamId}
                                            className="h-9 w-full rounded-md border bg-background px-3 text-sm disabled:opacity-50"
                                        >
                                            <option value="">{createTeamId ? 'Pilih bulan...' : 'Pilih tim dahulu'}</option>
                                            {selectedTeamMonths.map((m) => (
                                                <option key={m} value={m}>{bulanLabels[m]}</option>
                                            ))}
                                        </select>
                                        {createTeamId && selectedTeamMonths.length === 0 && (
                                            <p className="text-xs text-muted-foreground">
                                                Tidak ada bulan yang tersedia. Pastikan PRBL bulan sebelumnya sudah selesai.
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">Batal</Button>
                                    </DialogClose>
                                    <Button
                                        disabled={!createPpId || !createTeamId || !createBulan || creating}
                                        onClick={handleAdminCreate}
                                    >
                                        {creating ? 'Membuat...' : 'Buat PABD'}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
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
                                <th className="px-4 py-3 text-left font-medium">Bulan Anggaran</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Step Aktif</th>
                                <th className="px-4 py-3 text-left font-medium">PP</th>
                                <th className="px-4 py-3 text-right font-medium">Total Angg.</th>
                                <th className="px-4 py-3 text-left font-medium">Terakhir</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tanggal</th>
                                {showResetCol && <th className="px-4 py-3 text-center font-medium">Aksi</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {workflows.data.length === 0 ? (
                                <tr>
                                    <td colSpan={colCount} className="px-4 py-8 text-center text-muted-foreground">
                                        <FileText className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada pengajuan anggaran bulanan.
                                    </td>
                                </tr>
                            ) : (
                                workflows.data.map((wf) => (
                                    <tr
                                        key={wf.id}
                                        className="cursor-pointer border-b transition-colors last:border-0 hover:bg-muted/30"
                                        onClick={() => router.visit(`${scopeBase}/workflows/pabd/${wf.id}`)}
                                    >
                                        {isAdmin && (
                                            <td className="px-4 py-3 text-muted-foreground">{wf.team_name}</td>
                                        )}
                                        <td className="px-4 py-3 font-medium">
                                            <Link
                                                href={`${scopeBase}/workflows/pabd/${wf.id}`}
                                                className="text-primary hover:underline"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                {wf.bulan_label} {wf.tahun_anggaran}
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
                                        <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{wf.tanggal}</td>
                                        {showResetCol && (
                                            <td className="px-4 py-3 text-center" onClick={(e) => e.stopPropagation()}>
                                                {wf.status === 'active' && (
                                                    <ActionConfirmDialog
                                                        trigger={
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-8 gap-1.5 text-destructive hover:text-destructive"
                                                                disabled={resettingId === wf.id}
                                                            >
                                                                <RotateCcw className="h-3.5 w-3.5" />
                                                                Reset
                                                            </Button>
                                                        }
                                                        title="Reset PABD ke PABD01?"
                                                        description={`PABD ${wf.bulan_label} ${wf.tahun_anggaran}${wf.team_name ? ` (${wf.team_name})` : ''} akan direset ke checklist pencairan awal. Progress saat ini (${wf.step_aktif ?? 'PABD01'}) akan diinvalidasi.`}
                                                        confirmLabel="Reset"
                                                        variant="destructive"
                                                        processing={resettingId === wf.id}
                                                        onConfirm={(data) => handleAdminReset(wf.id, data)}
                                                    />
                                                )}
                                            </td>
                                        )}
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
