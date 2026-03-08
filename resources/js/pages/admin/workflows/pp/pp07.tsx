import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type KodeItem = { kode: string; nama: string; catatan: string | null };
type KuisionerItem = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };
type PlafonItem = {
    team_id: number;
    kode_team: string;
    plafon_anggaran: number;
    nama_bank: string;
    nama_rekening: string;
    nomor_rekening: string;
    catatan: string | null;
};
type DokumenItem = { file_id: number };

type DraftData = {
    tahun: number | null;
    tanggal_mulai_pra_raker: string | null;
    tanggal_penetapan_program: string | null;
    kode_bidang_pelayanan: KodeItem[];
    kode_sub_bidang_pelayanan: KodeItem[];
    kode_kategori_pelayanan: KodeItem[];
    kode_jenis_program: KodeItem[];
    item_kuisioner: KuisionerItem[];
    item_plafon_anggaran: PlafonItem[];
    item_dokumen_sop: DokumenItem[];
};

type StepData = {
    id: number;
    draft_data: DraftData;
    submitted_at: string | null;
    updated_at: string;
};

type Team = { id: number; name: string };
type Workflow = { id: number; label: string; history: HistoryEntry[] };

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    teams: Team[];
};

const tipePresets = ['Kualitatif', 'Kuantitatif'];

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

export default function Pp07({ workflow, stepData, mode, canDraft, canSubmit, teams }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isReadonly = mode === 'readonly';
    const d = stepData.draft_data;

    const form = useForm({
        draft_data: {
            tahun: d.tahun ?? '',
            tanggal_mulai_pra_raker: d.tanggal_mulai_pra_raker ?? '',
            tanggal_penetapan_program: d.tanggal_penetapan_program ?? '',
            kode_bidang_pelayanan: d.kode_bidang_pelayanan?.length > 0 ? d.kode_bidang_pelayanan : [{ kode: '', nama: '', catatan: '' }],
            kode_sub_bidang_pelayanan: d.kode_sub_bidang_pelayanan?.length > 0 ? d.kode_sub_bidang_pelayanan : [{ kode: '', nama: '', catatan: '' }],
            kode_kategori_pelayanan: d.kode_kategori_pelayanan?.length > 0 ? d.kode_kategori_pelayanan : [{ kode: '', nama: '', catatan: '' }],
            kode_jenis_program: d.kode_jenis_program?.length > 0 ? d.kode_jenis_program : [{ kode: '', nama: '', catatan: '' }],
            item_kuisioner: d.item_kuisioner?.length > 0 ? d.item_kuisioner : [{ kode: '', pertanyaan: '', tipe: 'Kualitatif', satuan: '' }],
            item_plafon_anggaran: d.item_plafon_anggaran?.length > 0 ? d.item_plafon_anggaran.map((item) => ({ ...item, catatan: item.catatan ?? '' })) : [],
            item_dokumen_sop: d.item_dokumen_sop ?? [],
        } as DraftData,
        expected_updated_at: stepData.updated_at,
        notes: '',
    });

    const draft = form.data.draft_data;
    const usedTeamIds = draft.item_plafon_anggaran.map((item) => item.team_id);
    const totalPlafon = draft.item_plafon_anggaran.reduce((sum, item) => sum + (Number(item.plafon_anggaran) || 0), 0);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP07: Revisi', href: '#' },
    ];

    function setDraft<K extends keyof DraftData>(field: K, value: DraftData[K]) {
        form.setData('draft_data', { ...draft, [field]: value });
    }

    // Kode table helpers
    type KodeField = 'kode_bidang_pelayanan' | 'kode_sub_bidang_pelayanan' | 'kode_kategori_pelayanan' | 'kode_jenis_program';

    function addKodeRow(field: KodeField) {
        setDraft(field, [...draft[field], { kode: '', nama: '', catatan: '' }]);
    }

    function removeKodeRow(field: KodeField, index: number) {
        setDraft(field, draft[field].filter((_, i) => i !== index));
    }

    function updateKodeRow(field: KodeField, index: number, key: keyof KodeItem, value: string) {
        setDraft(field, draft[field].map((item, i) => (i === index ? { ...item, [key]: value } : item)));
    }

    // Kuisioner helpers
    function addKuisionerRow() {
        setDraft('item_kuisioner', [...draft.item_kuisioner, { kode: '', pertanyaan: '', tipe: 'Kualitatif', satuan: '' }]);
    }

    function removeKuisionerRow(index: number) {
        setDraft('item_kuisioner', draft.item_kuisioner.filter((_, i) => i !== index));
    }

    function updateKuisionerRow(index: number, field: keyof KuisionerItem, value: string) {
        const updates: Partial<KuisionerItem> = { [field]: value };
        if (field === 'tipe' && value === 'Kualitatif') {
            updates.satuan = '';
        }
        setDraft('item_kuisioner', draft.item_kuisioner.map((item, i) => (i === index ? { ...item, ...updates } : item)));
    }

    function isCustomTipe(tipe: string): boolean {
        return tipe !== '' && !tipePresets.includes(tipe);
    }

    function handleTipeChange(index: number, value: string) {
        if (value === 'Lainnya') {
            updateKuisionerRow(index, 'tipe', '');
        } else {
            updateKuisionerRow(index, 'tipe', value);
        }
    }

    // Plafon helpers
    function addPlafonRow() {
        setDraft('item_plafon_anggaran', [
            ...draft.item_plafon_anggaran,
            { team_id: 0, kode_team: '', plafon_anggaran: 0, nama_bank: '', nama_rekening: '', nomor_rekening: '', catatan: '' },
        ]);
    }

    function removePlafonRow(index: number) {
        setDraft('item_plafon_anggaran', draft.item_plafon_anggaran.filter((_, i) => i !== index));
    }

    function updatePlafonRow(index: number, field: string, value: string | number) {
        setDraft('item_plafon_anggaran', draft.item_plafon_anggaran.map((item, i) => (i === index ? { ...item, [field]: value } : item)));
    }

    function handleDraft() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp07/${stepData.id}/draft`, { preserveScroll: true });
    }

    function handleSubmit() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp07/${stepData.id}/submit`);
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
            <Head title={`PP07: Revisi — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP07: Revisi Periode Tahunan" description="Revisi data perencanaan yang sudah dikompilasi" />
                    <StepStatusBadge mode={mode} />
                </div>

                {Object.keys(errors).length > 0 && (
                    <AlertError errors={Object.values(errors)} title="Validasi gagal" />
                )}

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp07"
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* Informasi Periode */}
                <SectionCard title="Informasi Periode">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Tahun *</label>
                            <Input
                                type="number"
                                value={draft.tahun ?? ''}
                                onChange={(e) => setDraft('tahun', e.target.value as unknown as number)}
                                disabled={isReadonly}
                                min={2020}
                                max={2099}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Tanggal Mulai Pra-Raker *</label>
                            <Input
                                type="date"
                                value={draft.tanggal_mulai_pra_raker ?? ''}
                                onChange={(e) => setDraft('tanggal_mulai_pra_raker', e.target.value)}
                                disabled={isReadonly}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Tanggal Penetapan Program *</label>
                            <Input
                                type="date"
                                value={draft.tanggal_penetapan_program ?? ''}
                                onChange={(e) => setDraft('tanggal_penetapan_program', e.target.value)}
                                disabled={isReadonly}
                            />
                        </div>
                    </div>
                </SectionCard>

                {/* 4 Kode Tables */}
                {([
                    ['kode_bidang_pelayanan', 'Kode Bidang Pelayanan'],
                    ['kode_sub_bidang_pelayanan', 'Kode Sub Bidang Pelayanan'],
                    ['kode_kategori_pelayanan', 'Kode Kategori Pelayanan'],
                    ['kode_jenis_program', 'Kode Jenis Program'],
                ] as [KodeField, string][]).map(([field, title]) => (
                    <SectionCard key={field} title={title}>
                        <div className="overflow-x-auto">
                            <table className="min-w-150 w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-3 py-2 text-left font-medium w-24">Kode</th>
                                        {!isReadonly && <th className="px-3 py-2 w-12" />}
                                        <th className="px-3 py-2 text-left font-medium">Nama</th>
                                        <th className="px-3 py-2 text-left font-medium w-48">Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {draft[field].map((item, i) => (
                                        <tr key={i} className="border-b last:border-0">
                                            <td className="px-3 py-1.5">
                                                <Input value={item.kode} onChange={(e) => updateKodeRow(field, i, 'kode', e.target.value)} disabled={isReadonly} className="h-8" maxLength={10} />
                                            </td>
                                            {!isReadonly && (
                                                <td className="px-3 py-1.5">
                                                    <Button variant="ghost" size="sm" onClick={() => removeKodeRow(field, i)} className="h-8 w-8 p-0">
                                                        <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                    </Button>
                                                </td>
                                            )}
                                            <td className="px-3 py-1.5">
                                                <Input value={item.nama} onChange={(e) => updateKodeRow(field, i, 'nama', e.target.value)} disabled={isReadonly} className="h-8" />
                                            </td>
                                            <td className="px-3 py-1.5">
                                                <Input value={item.catatan ?? ''} onChange={(e) => updateKodeRow(field, i, 'catatan', e.target.value)} disabled={isReadonly} className="h-8" />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {!isReadonly && (
                            <Button variant="outline" size="sm" onClick={() => addKodeRow(field)} className="mt-2">
                                <Plus className="mr-1 h-3.5 w-3.5" />Tambah Baris
                            </Button>
                        )}
                    </SectionCard>
                ))}

                {/* Kuisioner */}
                <SectionCard title="Pertanyaan Kuisioner">
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
                                {draft.item_kuisioner.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-1.5">
                                            <Input value={item.kode} onChange={(e) => updateKuisionerRow(i, 'kode', e.target.value)} disabled={isReadonly} className="h-8" maxLength={10} />
                                        </td>
                                        {!isReadonly && (
                                            <td className="px-3 py-1.5">
                                                <Button variant="ghost" size="sm" onClick={() => removeKuisionerRow(i)} className="h-8 w-8 p-0">
                                                    <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                </Button>
                                            </td>
                                        )}
                                        <td className="px-3 py-1.5">
                                            <Input value={item.pertanyaan} onChange={(e) => updateKuisionerRow(i, 'pertanyaan', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            {isCustomTipe(item.tipe) ? (
                                                <div className="flex gap-1">
                                                    <Input value={item.tipe} onChange={(e) => updateKuisionerRow(i, 'tipe', e.target.value)} disabled={isReadonly} className="h-8" placeholder="Tipe custom" />
                                                    {!isReadonly && (
                                                        <Button variant="ghost" size="sm" onClick={() => updateKuisionerRow(i, 'tipe', 'Kualitatif')} className="h-8 px-1.5 text-xs">X</Button>
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
                                                onChange={(e) => updateKuisionerRow(i, 'satuan', e.target.value)}
                                                disabled={isReadonly || item.tipe === 'Kualitatif'}
                                                className="h-8"
                                                placeholder={item.tipe === 'Kualitatif' ? '—' : item.tipe === 'Kuantitatif' ? 'wajib' : 'opsional'}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {!isReadonly && (
                        <Button variant="outline" size="sm" onClick={addKuisionerRow} className="mt-2">
                            <Plus className="mr-1 h-3.5 w-3.5" />Tambah Pertanyaan
                        </Button>
                    )}
                </SectionCard>

                {/* Plafon Anggaran */}
                <SectionCard title="Plafon Anggaran">
                    <div className="overflow-x-auto">
                        <table className="min-w-300 w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode Tim</th>
                                    {!isReadonly && <th className="px-3 py-2 w-12" />}
                                    <th className="px-3 py-2 text-left font-medium w-44">Tim</th>
                                    <th className="px-3 py-2 text-right font-medium w-36">Plafon</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Bank</th>
                                    <th className="px-3 py-2 text-left font-medium w-32">Nama Rek.</th>
                                    <th className="px-3 py-2 text-left font-medium w-28">No. Rek.</th>
                                    <th className="px-3 py-2 text-left font-medium">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {draft.item_plafon_anggaran.map((item, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        <td className="px-3 py-1.5">
                                            <Input value={item.kode_team} onChange={(e) => updatePlafonRow(i, 'kode_team', e.target.value)} disabled={isReadonly} className="h-8" maxLength={10} />
                                        </td>
                                        {!isReadonly && (
                                            <td className="px-3 py-1.5">
                                                <Button variant="ghost" size="sm" onClick={() => removePlafonRow(i)} className="h-8 w-8 p-0">
                                                    <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
                                                </Button>
                                            </td>
                                        )}
                                        <td className="px-3 py-1.5">
                                            <select
                                                value={item.team_id}
                                                onChange={(e) => updatePlafonRow(i, 'team_id', parseInt(e.target.value))}
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
                                            <Input type="number" value={item.plafon_anggaran} onChange={(e) => updatePlafonRow(i, 'plafon_anggaran', parseFloat(e.target.value) || 0)} disabled={isReadonly} className="h-8 text-right" min={0} />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.nama_bank} onChange={(e) => updatePlafonRow(i, 'nama_bank', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.nama_rekening} onChange={(e) => updatePlafonRow(i, 'nama_rekening', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.nomor_rekening} onChange={(e) => updatePlafonRow(i, 'nomor_rekening', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <Input value={item.catatan ?? ''} onChange={(e) => updatePlafonRow(i, 'catatan', e.target.value)} disabled={isReadonly} className="h-8" />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t bg-muted/30">
                                    <td className="px-3 py-2 font-medium" colSpan={isReadonly ? 3 : 4}>Total Plafon</td>
                                    <td className="px-3 py-2 text-right font-medium tabular-nums">{formatRupiah(totalPlafon)}</td>
                                    <td colSpan={4} />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    {!isReadonly && (
                        <Button variant="outline" size="sm" onClick={addPlafonRow} className="mt-2">
                            <Plus className="mr-1 h-3.5 w-3.5" />Tambah Tim
                        </Button>
                    )}
                </SectionCard>

                {/* Dokumen SOP */}
                <SectionCard title="Dokumen SOP">
                    {draft.item_dokumen_sop.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">Tidak ada dokumen dilampirkan.</p>
                    ) : (
                        <p className="text-sm text-muted-foreground">{draft.item_dokumen_sop.length} file dari revisi sebelumnya.</p>
                    )}
                    {!isReadonly && (
                        <p className="mt-2 text-xs text-muted-foreground">(Prototype: file upload/de-attach dilewati)</p>
                    )}
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (
                    <div className="flex gap-2">
                        {canDraft && (
                            <Button variant="outline" onClick={handleDraft} disabled={form.processing}>
                                {form.processing ? 'Menyimpan...' : 'Simpan Draft'}
                            </Button>
                        )}
                        {canSubmit && (
                            <Button onClick={handleSubmit} disabled={form.processing}>
                                {form.processing ? 'Mengirim...' : 'Submit Revisi'}
                            </Button>
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
        edit: { label: 'Draft Revisi', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
    };
    const c = config[mode] ?? config.edit;
    return <Badge className={c.className}>{c.label}</Badge>;
}
