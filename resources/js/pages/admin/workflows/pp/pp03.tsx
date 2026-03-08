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

type PlafonItem = {
    team_id: number;
    kode_team: string;
    plafon_anggaran: number;
    nama_bank: string;
    nama_rekening: string;
    nomor_rekening: string;
    catatan: string | null;
    team?: { id: number; name: string };
};

type Team = { id: number; name: string };

type StepData = {
    id: number;
    item_plafon_anggaran: PlafonItem[];
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
    teams: Team[];
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

export default function Pp03({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, isRejectionReentry, teams }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isReadonly = mode === 'readonly';
    const [showTerminate, setShowTerminate] = useState(false);
    const [terminateNotes, setTerminateNotes] = useState('');
    const [terminateProcessing, setTerminateProcessing] = useState(false);

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

    function handleTerminate() {
        if (!terminateNotes.trim()) return;
        setTerminateProcessing(true);
        router.post(`/admin/workflows/pp/${workflow.id}/terminate`, { notes: terminateNotes }, {
            onFinish: () => setTerminateProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP03: Plafon Anggaran — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP03: Plafon Anggaran" description="Tentukan plafon anggaran dan rekening per tim" />
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

                <SectionCard title="Daftar Plafon Anggaran">
                    <div className="overflow-x-auto">
                        <table className="min-w-[900px] w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium w-44">Tim</th>
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode</th>
                                    <th className="px-3 py-2 text-right font-medium w-36">Plafon</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Bank</th>
                                    <th className="px-3 py-2 text-left font-medium w-32">Nama Rekening</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">No. Rekening</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Catatan</th>
                                    {!isReadonly && <th className="px-3 py-2 w-12" />}
                                </tr>
                            </thead>
                            <tbody>
                                {form.data.item_plafon_anggaran.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
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
                                            <Input value={item.kode_team} onChange={(e) => updateRow(i, 'kode_team', e.target.value)} disabled={isReadonly} className="h-8" maxLength={10} />
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
                            <tfoot>
                                <tr className="border-t bg-muted/30">
                                    <td className="px-3 py-2 font-medium" colSpan={2}>Total Plafon</td>
                                    <td className="px-3 py-2 text-right font-medium">{formatRupiah(totalPlafon)}</td>
                                    <td colSpan={isReadonly ? 4 : 5} />
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
                        {canDraft && <Button variant="outline" onClick={handleDraft} disabled={form.processing}>Simpan Draft</Button>}
                        {canSubmit && <Button onClick={handleSubmit} disabled={form.processing}>Submit PP03</Button>}
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
