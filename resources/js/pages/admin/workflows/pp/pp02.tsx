import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
};

const tipeOptions = ['Kualitatif', 'Kuantitatif'];

export default function Pp02({ workflow, stepData, mode, canDraft, canSubmit }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isReadonly = mode === 'readonly';

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
        const items = form.data.item_kuisioner.map((item, i) => (i === index ? { ...item, [field]: value } : item));
        form.setData('item_kuisioner', items);
    }

    function handleDraft() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp02/${stepData.id}/draft`, { preserveScroll: true });
    }

    function handleSubmit() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp02/${stepData.id}/submit`);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP02: Pertanyaan Kuisioner — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP02: Pertanyaan Kuisioner" description="Definisikan pertanyaan kuisioner untuk monitoring" />
                    <Badge variant={isReadonly ? 'secondary' : 'default'}>
                        {isReadonly ? 'Sudah Disubmit' : 'Pengisian'}
                    </Badge>
                </div>

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
                                            <select
                                                value={item.tipe}
                                                onChange={(e) => updateRow(i, 'tipe', e.target.value)}
                                                disabled={isReadonly}
                                                className="h-8 w-full rounded-md border bg-background px-2 text-sm"
                                            >
                                                {tipeOptions.map((t) => <option key={t} value={t}>{t}</option>)}
                                            </select>
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.satuan ?? ''} onChange={(e) => updateRow(i, 'satuan', e.target.value)} disabled={isReadonly} className="h-8" placeholder="opsional" />
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
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
