import { Head, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type KodeItem = { kode: string; nama: string; catatan: string | null };

type StepData = {
    id: number;
    tahun: number | null;
    tanggal_mulai_pra_raker: string | null;
    tanggal_penetapan_program: string | null;
    kode_bidang_pelayanan: KodeItem[];
    kode_sub_bidang_pelayanan: KodeItem[];
    kode_kategori_pelayanan: KodeItem[];
    kode_jenis_program: KodeItem[];
    updated_at: string;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
};

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'create' | 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canTerminate: boolean;
    isRejectionReentry: boolean;
};

export default function Pp01({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, isRejectionReentry }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    const form = useForm({
        tahun: stepData.tahun ?? '',
        tanggal_mulai_pra_raker: stepData.tanggal_mulai_pra_raker ?? '',
        tanggal_penetapan_program: stepData.tanggal_penetapan_program ?? '',
        kode_bidang_pelayanan: stepData.kode_bidang_pelayanan.length > 0 ? stepData.kode_bidang_pelayanan : [{ kode: '', nama: '', catatan: '' }],
        kode_sub_bidang_pelayanan: stepData.kode_sub_bidang_pelayanan.length > 0 ? stepData.kode_sub_bidang_pelayanan : [{ kode: '', nama: '', catatan: '' }],
        kode_kategori_pelayanan: stepData.kode_kategori_pelayanan.length > 0 ? stepData.kode_kategori_pelayanan : [{ kode: '', nama: '', catatan: '' }],
        kode_jenis_program: stepData.kode_jenis_program.length > 0 ? stepData.kode_jenis_program : [{ kode: '', nama: '', catatan: '' }],
        expected_updated_at: stepData.updated_at,
        notes: '',
    });

    const isReadonly = mode === 'readonly';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP01: Rencana Periode', href: '#' },
    ];

    function handleDraft() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp01/${stepData.id}/draft`, {
            preserveScroll: true,
        });
    }

    function handleSubmit() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp01/${stepData.id}/submit`);
    }

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PP05' || step === 'PP06') {
            return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}`;
        }
        if (entry.id && entry.table) {
            return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}/${entry.id}`;
        }
        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP01: Rencana Periode — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP01: Rencana Periode" description="Tentukan tahun perencanaan, jadwal, dan kode-kode referensi" />
                    <StepStatusBadge mode={mode} />
                </div>

                {isRejectionReentry && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        Step ini dikembalikan dari PP05. Data dari pengisian sebelumnya sudah dimuat ulang.
                    </div>
                )}

                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}
                {errors.tahun && <AlertError errors={[errors.tahun]} title="Validasi gagal" />}

                {/* History & Comment — collapsed by default on step pages */}
                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp01"
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                <SectionCard title="Informasi Periode">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label>Tahun Periode *</Label>
                            <Input
                                type="number"
                                value={form.data.tahun}
                                onChange={(e) => form.setData('tahun', e.target.value as unknown as number)}
                                disabled={isReadonly}
                                min={2020}
                                max={2099}
                            />
                            {form.errors.tahun && <p className="text-xs text-destructive">{form.errors.tahun}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal Mulai Pra-Raker *</Label>
                            <Input
                                type="date"
                                value={form.data.tanggal_mulai_pra_raker}
                                onChange={(e) => form.setData('tanggal_mulai_pra_raker', e.target.value)}
                                disabled={isReadonly}
                            />
                            {form.errors.tanggal_mulai_pra_raker && <p className="text-xs text-destructive">{form.errors.tanggal_mulai_pra_raker}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal Penetapan Program *</Label>
                            <Input
                                type="date"
                                value={form.data.tanggal_penetapan_program}
                                onChange={(e) => form.setData('tanggal_penetapan_program', e.target.value)}
                                disabled={isReadonly}
                            />
                            {form.errors.tanggal_penetapan_program && <p className="text-xs text-destructive">{form.errors.tanggal_penetapan_program}</p>}
                        </div>
                    </div>
                </SectionCard>

                <KodeTable
                    title="Kode Bidang Pelayanan"
                    addLabel="Tambah Bidang Pelayanan"
                    items={form.data.kode_bidang_pelayanan}
                    onChange={(items) => form.setData('kode_bidang_pelayanan', items)}
                    disabled={isReadonly}
                />

                <KodeTable
                    title="Kode Sub Bidang Pelayanan"
                    addLabel="Tambah Sub Bidang Pelayanan"
                    items={form.data.kode_sub_bidang_pelayanan}
                    onChange={(items) => form.setData('kode_sub_bidang_pelayanan', items)}
                    disabled={isReadonly}
                />

                <KodeTable
                    title="Kode Kategori Pelayanan"
                    addLabel="Tambah Kategori Pelayanan"
                    items={form.data.kode_kategori_pelayanan}
                    onChange={(items) => form.setData('kode_kategori_pelayanan', items)}
                    disabled={isReadonly}
                />

                <KodeTable
                    title="Kode Jenis Program"
                    addLabel="Tambah Jenis Program"
                    items={form.data.kode_jenis_program}
                    onChange={(items) => form.setData('kode_jenis_program', items)}
                    disabled={isReadonly}
                />

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
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`/admin/workflows/pp/${workflowId}/terminate`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function KodeTable({
    title,
    addLabel,
    items,
    onChange,
    disabled,
}: {
    title: string;
    addLabel: string;
    items: KodeItem[];
    onChange: (items: KodeItem[]) => void;
    disabled: boolean;
}) {
    function addRow() {
        onChange([...items, { kode: '', nama: '', catatan: '' }]);
    }

    function removeRow(index: number) {
        onChange(items.filter((_, i) => i !== index));
    }

    function updateRow(index: number, field: keyof KodeItem, value: string) {
        const updated = items.map((item, i) => (i === index ? { ...item, [field]: value } : item));
        onChange(updated);
    }

    return (
        <SectionCard title={title}>
            <div className="overflow-x-auto">
                <table className="min-w-150 w-full text-sm">
                    <thead>
                        <tr className="border-b bg-muted/50">
                            <th className="px-3 py-2 text-left font-medium w-24">Kode</th>
                            {!disabled && <th className="px-3 py-2 w-12" />}
                            <th className="px-3 py-2 text-left font-medium">Nama</th>
                            <th className="px-3 py-2 text-left font-medium w-48">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item, i) => (
                            <tr key={i} className="border-b last:border-0">
                                <td className="px-3 py-1.5">
                                    <Input
                                        value={item.kode}
                                        onChange={(e) => updateRow(i, 'kode', e.target.value)}
                                        disabled={disabled}
                                        className="h-8"
                                        maxLength={10}
                                    />
                                </td>
                                {!disabled && (
                                    <td className="px-3 py-1.5">
                                        <Button variant="ghost" size="sm" onClick={() => removeRow(i)} className="h-8 w-8 p-0">
                                            <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                        </Button>
                                    </td>
                                )}
                                <td className="px-3 py-1.5">
                                    <Input
                                        value={item.nama}
                                        onChange={(e) => updateRow(i, 'nama', e.target.value)}
                                        disabled={disabled}
                                        className="h-8"
                                    />
                                </td>
                                <td className="px-3 py-1.5">
                                    <Input
                                        value={item.catatan ?? ''}
                                        onChange={(e) => updateRow(i, 'catatan', e.target.value)}
                                        disabled={disabled}
                                        className="h-8"
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {!disabled && (
                <Button variant="outline" size="sm" onClick={addRow} className="mt-2">
                    <Plus className="mr-1 h-3.5 w-3.5" />
                    {addLabel}
                </Button>
            )}
        </SectionCard>
    );
}
