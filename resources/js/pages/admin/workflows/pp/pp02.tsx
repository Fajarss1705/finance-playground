import { Head, usePage, router } from '@inertiajs/react';
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

type KuisionerItem = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };

type StepData = {
    id: number;
    item_kuisioner: KuisionerItem[];
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
    canComment: boolean;
    isRejectionReentry: boolean;
};

const tipePresets = ['Kualitatif', 'Kuantitatif'];

export default function Pp02({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, canComment, isRejectionReentry }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [processing, setProcessing] = useState(false);
    const isReadonly = mode === 'readonly';

    const [items, setItems] = useState<KuisionerItem[]>(
        stepData.item_kuisioner.length > 0
            ? stepData.item_kuisioner
            : [{ kode: '', pertanyaan: '', tipe: 'Kualitatif', satuan: '' }],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP02: Pertanyaan Kuisioner', href: '#' },
    ];

    function addRow() {
        setItems([...items, { kode: '', pertanyaan: '', tipe: 'Kualitatif', satuan: '' }]);
    }

    function removeRow(index: number) {
        setItems(items.filter((_, i) => i !== index));
    }

    function updateRow(index: number, field: keyof KuisionerItem, value: string) {
        const updates: Partial<KuisionerItem> = { [field]: value };
        if (field === 'tipe' && value === 'Kualitatif') {
            updates.satuan = '';
        }
        setItems(items.map((item, i) => (i === index ? { ...item, ...updates } : item)));
    }

    function isCustomTipe(tipe: string): boolean {
        return tipe !== '' && !tipePresets.includes(tipe);
    }

    function handleTipeChange(index: number, value: string) {
        if (value === 'Lainnya') {
            updateRow(index, 'tipe', '');
        } else {
            updateRow(index, 'tipe', value);
        }
    }

    function handleAction(action: 'draft' | 'submit', notes: string, files: File[]) {
        setProcessing(true);
        const url = `/admin/workflows/pp/${workflow.id}/pp02/${stepData.id}/${action}`;
        router.post(url, {
            item_kuisioner: items,
            expected_updated_at: stepData.updated_at,
            notes: notes || undefined,
            ...(files.length > 0 ? { files } : {}),
        }, {
            forceFormData: files.length > 0,
            preserveScroll: action === 'draft',
            onFinish: () => setProcessing(false),
        });
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
            <Head title={`PP02: Pertanyaan Kuisioner — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP02: Pertanyaan Kuisioner" description="Definisikan pertanyaan kuisioner untuk monitoring" />
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
                    commentSource="pp02"
                    canComment={canComment}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                <SectionCard title="Daftar Pertanyaan Kuisioner">
                    {errors.item_kuisioner && <p className="mb-2 text-xs text-destructive">{errors.item_kuisioner}</p>}
                    <div className="overflow-x-auto">
                        <table className="min-w-200 w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode</th>
                                    {!isReadonly && <th className="px-3 py-2 w-12" />}
                                    <th className="px-3 py-2 text-left font-medium">Pertanyaan</th>
                                    <th className="px-3 py-2 text-left font-medium w-36">Tipe</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Satuan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-1.5">
                                            <Input value={item.kode} onChange={(e) => updateRow(i, 'kode', e.target.value)} disabled={isReadonly} className="h-8" maxLength={10} />
                                            {errors[`item_kuisioner.${i}.kode`] && <p className="text-xs text-destructive">{errors[`item_kuisioner.${i}.kode`]}</p>}
                                        </td>
                                        {!isReadonly && (
                                            <td className="px-3 py-1.5">
                                                <Button variant="ghost" size="sm" onClick={() => removeRow(i)} className="h-8 w-8 p-0">
                                                    <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                </Button>
                                            </td>
                                        )}
                                        <td className="px-3 py-1.5">
                                            <Input value={item.pertanyaan} onChange={(e) => updateRow(i, 'pertanyaan', e.target.value)} disabled={isReadonly} className="h-8" />
                                            {errors[`item_kuisioner.${i}.pertanyaan`] && <p className="text-xs text-destructive">{errors[`item_kuisioner.${i}.pertanyaan`]}</p>}
                                        </td>
                                        <td className="px-3 py-1.5">
                                            {isCustomTipe(item.tipe) ? (
                                                <div className="flex gap-1">
                                                    <Input value={item.tipe} onChange={(e) => updateRow(i, 'tipe', e.target.value)} disabled={isReadonly} className="h-8" placeholder="Tipe custom" />
                                                    {!isReadonly && (
                                                        <Button variant="ghost" size="sm" onClick={() => updateRow(i, 'tipe', 'Kualitatif')} className="h-8 px-1.5 text-xs">X</Button>
                                                    )}
                                                </div>
                                            ) : (
                                                <select
                                                    value={item.tipe || 'Lainnya'}
                                                    onChange={(e) => handleTipeChange(i, e.target.value)}
                                                    disabled={isReadonly}
                                                    className="h-8 w-full rounded-md border bg-background px-2 text-sm"
                                                >
                                                    {tipePresets.map((t) => <option key={t} value={t}>{t}</option>)}
                                                    <option value="Lainnya">Lainnya...</option>
                                                </select>
                                            )}
                                            {errors[`item_kuisioner.${i}.tipe`] && <p className="text-xs text-destructive">{errors[`item_kuisioner.${i}.tipe`]}</p>}
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input
                                                value={item.satuan ?? ''}
                                                onChange={(e) => updateRow(i, 'satuan', e.target.value)}
                                                disabled={isReadonly || item.tipe === 'Kualitatif'}
                                                className="h-8"
                                                placeholder={item.tipe === 'Kualitatif' ? '—' : item.tipe === 'Kuantitatif' ? 'wajib' : 'opsional'}
                                            />
                                            {errors[`item_kuisioner.${i}.satuan`] && <p className="text-xs text-destructive">{errors[`item_kuisioner.${i}.satuan`]}</p>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {!isReadonly && (
                        <Button variant="outline" size="sm" onClick={addRow} className="mt-2">
                            <Plus className="mr-1 h-3.5 w-3.5" />
                            Tambah Pertanyaan
                        </Button>
                    )}
                </SectionCard>

                {!isReadonly && (canDraft || canSubmit || canTerminate) && (
                    <div className="flex gap-2">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button variant="outline" disabled={processing}>
                                        {processing ? 'Menyimpan...' : 'Simpan Draft'}
                                    </Button>
                                }
                                title="Simpan Draft"
                                description="Simpan data sebagai draft. Data belum divalidasi dan bisa diubah kembali."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing}>
                                        {processing ? 'Mengirim...' : 'Submit'}
                                    </Button>
                                }
                                title="Submit PP02"
                                description="Data akan divalidasi dan dikunci. Step selanjutnya (PP03) akan diaktifkan."
                                confirmLabel="Submit"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
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
