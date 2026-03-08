import { Head, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type KuisionerItem = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };

type StepData = {
    id: number;
    item_kuisioner: KuisionerItem[];
    updated_at: string;
};

type Workflow = { id: number; label: string };

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'create' | 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canTerminate: boolean;
    isRejectionReentry: boolean;
};

const tipePresets = ['Kualitatif', 'Kuantitatif'];

export default function Pp02({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, isRejectionReentry }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isReadonly = mode === 'readonly';
    const [showTerminate, setShowTerminate] = useState(false);
    const [terminateNotes, setTerminateNotes] = useState('');
    const [terminateProcessing, setTerminateProcessing] = useState(false);

    const form = useForm({
        item_kuisioner: stepData.item_kuisioner.length > 0
            ? stepData.item_kuisioner
            : [{ kode: '', pertanyaan: '', tipe: 'Kualitatif', satuan: '' }],
        expected_updated_at: stepData.updated_at,
        notes: '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP02: Pertanyaan Kuisioner', href: '#' },
    ];

    function addRow() {
        form.setData('item_kuisioner', [...form.data.item_kuisioner, { kode: '', pertanyaan: '', tipe: 'Kualitatif', satuan: '' }]);
    }

    function removeRow(index: number) {
        form.setData('item_kuisioner', form.data.item_kuisioner.filter((_, i) => i !== index));
    }

    function updateRow(index: number, field: keyof KuisionerItem, value: string) {
        const updates: Partial<KuisionerItem> = { [field]: value };
        if (field === 'tipe' && value === 'Kualitatif') {
            updates.satuan = '';
        }
        const items = form.data.item_kuisioner.map((item, i) => (i === index ? { ...item, ...updates } : item));
        form.setData('item_kuisioner', items);
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

    function handleDraft() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp02/${stepData.id}/draft`, { preserveScroll: true });
    }

    function handleSubmit() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp02/${stepData.id}/submit`);
    }

    function handleTerminate() {
        if (!terminateNotes.trim()) return;
        setTerminateProcessing(true);
        router.post(`/admin/workflows/pp/${workflow.id}/terminate`, { notes: terminateNotes }, {
            onFinish: () => setTerminateProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP02: Pertanyaan Kuisioner — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP02: Pertanyaan Kuisioner" description="Definisikan pertanyaan kuisioner untuk monitoring" />
                    <Badge variant={isReadonly ? 'secondary' : 'default'}>
                        {isReadonly ? 'Sudah Disubmit' : mode === 'edit' ? 'Draft' : 'Pengisian Baru'}
                    </Badge>
                </div>

                {isRejectionReentry && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        Step ini dikembalikan dari PP05. Data dari pengisian sebelumnya sudah dimuat ulang.
                    </div>
                )}

                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}

                <SectionCard title="Daftar Pertanyaan Kuisioner">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode</th>
                                    <th className="px-3 py-2 text-left font-medium">Pertanyaan</th>
                                    <th className="px-3 py-2 text-left font-medium w-36">Tipe</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Satuan</th>
                                    {!isReadonly && <th className="px-3 py-2 w-12" />}
                                </tr>
                            </thead>
                            <tbody>
                                {form.data.item_kuisioner.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-1.5">
                                            <Input value={item.kode} onChange={(e) => updateRow(i, 'kode', e.target.value)} disabled={isReadonly} className="h-8" maxLength={10} />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.pertanyaan} onChange={(e) => updateRow(i, 'pertanyaan', e.target.value)} disabled={isReadonly} className="h-8" />
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
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input
                                                value={item.satuan ?? ''}
                                                onChange={(e) => updateRow(i, 'satuan', e.target.value)}
                                                disabled={isReadonly || item.tipe === 'Kualitatif'}
                                                className="h-8"
                                                placeholder={item.tipe === 'Kualitatif' ? '—' : 'opsional'}
                                            />
                                        </td>
                                        {!isReadonly && (
                                            <td className="px-3 py-1.5">
                                                <Button variant="ghost" size="sm" onClick={() => removeRow(i)} className="h-8 w-8 p-0">
                                                    <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                </Button>
                                            </td>
                                        )}
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

                {!isReadonly && (
                    <div className="flex gap-2">
                        {canDraft && <Button variant="outline" onClick={handleDraft} disabled={form.processing}>Simpan Draft</Button>}
                        {canSubmit && <Button onClick={handleSubmit} disabled={form.processing}>Submit PP02</Button>}
                        {canTerminate && !showTerminate && (
                            <Button variant="destructive" className="ml-auto" onClick={() => setShowTerminate(true)}>Batalkan Workflow</Button>
                        )}
                    </div>
                )}

                {showTerminate && (
                    <SectionCard title="Batalkan Workflow">
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">Workflow yang dibatalkan tidak dapat dilanjutkan. Tuliskan alasan pembatalan.</p>
                            <textarea
                                value={terminateNotes}
                                onChange={(e) => setTerminateNotes(e.target.value)}
                                placeholder="Alasan pembatalan (wajib)..."
                                rows={3}
                                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <div className="flex gap-2">
                                <Button variant="destructive" onClick={handleTerminate} disabled={terminateProcessing || !terminateNotes.trim()}>Konfirmasi Batalkan</Button>
                                <Button variant="outline" onClick={() => setShowTerminate(false)}>Batal</Button>
                            </div>
                        </div>
                    </SectionCard>
                )}
            </div>
        </AppLayout>
    );
}
