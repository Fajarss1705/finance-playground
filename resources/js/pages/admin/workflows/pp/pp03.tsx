import { Head, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type PlafonItem = {
    team_id: number;
    kode_team: string;
    plafon_anggaran: number;
    nama_bank: string;
    nama_rekening: string;
    nomor_rekening: string;
    catatan: string | null;
};

type Team = { id: number; name: string };

type StepData = {
    id: number;
    item_plafon_anggaran: PlafonItem[];
    updated_at: string;
};

type Workflow = { id: number; label: string; history: HistoryEntry[] };

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'create' | 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canTerminate: boolean;
    isRejectionReentry: boolean;
    teams: Team[];
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

export default function Pp03({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, isRejectionReentry, teams }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isReadonly = mode === 'readonly';

    const form = useForm({
        item_plafon_anggaran: stepData.item_plafon_anggaran.length > 0
            ? stepData.item_plafon_anggaran.map((item) => ({
                team_id: item.team_id,
                kode_team: item.kode_team,
                plafon_anggaran: item.plafon_anggaran,
                nama_bank: item.nama_bank,
                nama_rekening: item.nama_rekening,
                nomor_rekening: item.nomor_rekening,
                catatan: item.catatan ?? '',
            }))
            : [],
        expected_updated_at: stepData.updated_at,
        notes: '',
    });

    const usedTeamIds = form.data.item_plafon_anggaran.map((item) => item.team_id);
    const totalPlafon = form.data.item_plafon_anggaran.reduce((sum, item) => sum + (Number(item.plafon_anggaran) || 0), 0);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP03: Plafon Anggaran', href: '#' },
    ];

    function addRow() {
        form.setData('item_plafon_anggaran', [
            ...form.data.item_plafon_anggaran,
            { team_id: 0, kode_team: '', plafon_anggaran: 0, nama_bank: '', nama_rekening: '', nomor_rekening: '', catatan: '' },
        ]);
    }

    function removeRow(index: number) {
        form.setData('item_plafon_anggaran', form.data.item_plafon_anggaran.filter((_, i) => i !== index));
    }

    function updateRow(index: number, field: string, value: string | number) {
        const items = form.data.item_plafon_anggaran.map((item, i) => (i === index ? { ...item, [field]: value } : item));
        form.setData('item_plafon_anggaran', items);
    }

    function handleDraft() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp03/${stepData.id}/draft`, { preserveScroll: true });
    }

    function handleSubmit() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp03/${stepData.id}/submit`);
    }

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PP05' || step === 'PP06') return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}`;
        if (entry.id && entry.table) return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}/${entry.id}`;
        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP03: Plafon Anggaran — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP03: Plafon Anggaran" description="Tentukan plafon anggaran dan rekening per tim" />
                    <StepStatusBadge mode={mode} />
                </div>

                {isRejectionReentry && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        Step ini dikembalikan dari PP05. Data dari pengisian sebelumnya sudah dimuat ulang.
                    </div>
                )}

                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp03"
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                <SectionCard title="Plafon Anggaran Per Tim">
                    <div className="overflow-x-auto">
                        <table className="min-w-300 w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode Tim</th>
                                    {!isReadonly && <th className="px-3 py-2 w-12" />}
                                    <th className="px-3 py-2 text-left font-medium w-44">Tim</th>
                                    <th className="px-3 py-2 text-right font-medium w-36">Plafon Rp</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Bank</th>
                                    <th className="px-3 py-2 text-left font-medium w-32">Nama Rek.</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">No. Rek.</th>
                                    <th className="px-3 py-2 text-left font-medium">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {form.data.item_plafon_anggaran.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-1.5">
                                            <Input value={item.kode_team} onChange={(e) => updateRow(i, 'kode_team', e.target.value)} disabled={isReadonly} className="h-8" maxLength={10} />
                                        </td>
                                        {!isReadonly && (
                                            <td className="px-3 py-1.5">
                                                <Button variant="ghost" size="sm" onClick={() => removeRow(i)} className="h-8 w-8 p-0">
                                                    <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                </Button>
                                            </td>
                                        )}
                                        <td className="px-3 py-1.5">
                                            <select
                                                value={item.team_id}
                                                onChange={(e) => updateRow(i, 'team_id', parseInt(e.target.value))}
                                                disabled={isReadonly}
                                                className="h-8 w-full rounded-md border bg-background px-2 text-sm"
                                            >
                                                <option value={0}>Pilih tim...</option>
                                                {teams.filter((t) => t.id === item.team_id || !usedTeamIds.includes(t.id)).map((t) => (
                                                    <option key={t.id} value={t.id}>{t.name}</option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input type="number" value={item.plafon_anggaran} onChange={(e) => updateRow(i, 'plafon_anggaran', parseFloat(e.target.value) || 0)} disabled={isReadonly} className="h-8 text-right" min={0} />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.nama_bank} onChange={(e) => updateRow(i, 'nama_bank', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.nama_rekening} onChange={(e) => updateRow(i, 'nama_rekening', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.nomor_rekening} onChange={(e) => updateRow(i, 'nomor_rekening', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.catatan ?? ''} onChange={(e) => updateRow(i, 'catatan', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t bg-muted/30">
                                    <td className="px-3 py-2 font-medium" colSpan={isReadonly ? 3 : 4}>Total Plafon</td>
                                    <td className="px-3 py-2 text-right font-medium">{formatRupiah(totalPlafon)}</td>
                                    <td colSpan={4} />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    {!isReadonly && (
                        <Button variant="outline" size="sm" onClick={addRow} className="mt-2">
                            <Plus className="mr-1 h-3.5 w-3.5" />
                            Tambah Tim
                        </Button>
                    )}
                </SectionCard>

                {!isReadonly && (
                    <div className="flex gap-2">
                        {canDraft && (
                            <Button variant="outline" onClick={handleDraft} disabled={form.processing}>
                                {form.processing ? 'Menyimpan...' : 'Simpan Draft'}
                            </Button>
                        )}
                        {canSubmit && (
                            <Button onClick={handleSubmit} disabled={form.processing}>
                                {form.processing ? 'Mengirim...' : 'Submit'}
                            </Button>
                        )}
                        {canTerminate && (
                            <TerminateButton workflowId={workflow.id} />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function StepStatusBadge({ mode }: { mode: string }) {
    const config: Record<string, { label: string; className: string }> = {
        readonly: { label: 'Sudah Disubmit', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
        edit: { label: 'Draft', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
        create: { label: 'Menunggu Diisi', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
    };
    const c = config[mode] ?? config.create;
    return <Badge className={c.className}>{c.label}</Badge>;
}

function TerminateButton({ workflowId }: { workflowId: number }) {
    const [processing, setProcessing] = useState(false);
    return (
        <ActionConfirmDialog
            trigger={
                <Button variant="outline" className="ml-auto text-destructive border-destructive hover:bg-destructive/10">
                    Batalkan Workflow
                </Button>
            }
            title="Batalkan Workflow"
            description="Workflow yang dibatalkan tidak bisa dilanjutkan. Tulis alasan pembatalan."
            confirmLabel="Batalkan Workflow"
            variant="destructive"
            requireNotes
            processing={processing}
            onConfirm={({ notes }) => {
                setProcessing(true);
                router.post(`/admin/workflows/pp/${workflowId}/terminate`, { notes }, {
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}
