import { Head, usePage, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Download, Plus, Trash2, Upload } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DateInput } from '@/components/ui/date-input';
import { Input } from '@/components/ui/input';
import { RupiahInput } from '@/components/ui/rupiah-input';
import { Textarea } from '@/components/ui/textarea';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
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
type DokumenFile = { file_id: number; uuid: string; original_filename: string; size: number };

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
    canComment: boolean;
    teams: Team[];
    dokumenFiles: DokumenFile[];
    actionRoles: ActionRole[];
    activeRoleName: string | null;
};

const tipePresets = ['Kualitatif', 'Kuantitatif'];

function nextKode(prefix: string, existing: { kode_team?: string; kode?: string }[]): string {
    const nums = existing
        .map((item) => {
            const k = item.kode_team ?? item.kode ?? '';
            const m = k.match(new RegExp(`^${prefix}(\\d+)$`));
            return m ? parseInt(m[1], 10) : 0;
        })
        .filter((n) => n > 0);
    const next = nums.length > 0 ? Math.max(...nums) + 1 : 1;
    return `${prefix}${String(next).padStart(2, '0')}`;
}

const kodePrefixMap: Record<string, string> = {
    kode_bidang_pelayanan: 'B',
    kode_sub_bidang_pelayanan: 'SB',
    kode_kategori_pelayanan: 'K',
    kode_jenis_program: 'J',
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

type ExistingFile = { type: 'existing'; file_id: number; uuid: string; name: string; size: number };
type NewFile = { type: 'new'; file: File; name: string; size: number; key: string };
type FileEntry = ExistingFile | NewFile;

export default function Pp07({ workflow, stepData, mode, canDraft, canSubmit, canComment, teams, dokumenFiles, actionRoles, activeRoleName }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [processing, setProcessing] = useState(false);
    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);
    const isPermissionLocked = mode !== 'readonly' && !canDraft && !canSubmit;
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [dragOver, setDragOver] = useState(false);
    const d = stepData.draft_data;

    const [draft, setDraftState] = useState<DraftData>({
        tahun: d.tahun ?? null,
        tanggal_mulai_pra_raker: d.tanggal_mulai_pra_raker ?? null,
        tanggal_penetapan_program: d.tanggal_penetapan_program ?? null,
        kode_bidang_pelayanan: d.kode_bidang_pelayanan?.length > 0 ? d.kode_bidang_pelayanan : [{ kode: 'B01', nama: '', catatan: '' }],
        kode_sub_bidang_pelayanan: d.kode_sub_bidang_pelayanan?.length > 0 ? d.kode_sub_bidang_pelayanan : [{ kode: 'SB01', nama: '', catatan: '' }],
        kode_kategori_pelayanan: d.kode_kategori_pelayanan?.length > 0 ? d.kode_kategori_pelayanan : [{ kode: 'K01', nama: '', catatan: '' }],
        kode_jenis_program: d.kode_jenis_program?.length > 0 ? d.kode_jenis_program : [{ kode: 'J01', nama: '', catatan: '' }],
        item_kuisioner: d.item_kuisioner?.length > 0 ? d.item_kuisioner : [{ kode: 'Q01', pertanyaan: '', tipe: 'Kualitatif', satuan: '' }],
        item_plafon_anggaran: d.item_plafon_anggaran?.length > 0 ? d.item_plafon_anggaran.map((item) => ({ ...item, catatan: item.catatan ?? '' })) : [],
        item_dokumen_sop: d.item_dokumen_sop ?? [],
    });

    const [fileEntries, setFileEntries] = useState<FileEntry[]>(
        dokumenFiles.map((f) => ({ type: 'existing' as const, file_id: f.file_id, uuid: f.uuid, name: f.original_filename, size: f.size })),
    );

    const usedTeamIds = draft.item_plafon_anggaran.map((item) => item.team_id);
    const totalPlafon = draft.item_plafon_anggaran.reduce((sum, item) => sum + (Number(item.plafon_anggaran) || 0), 0);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP07: Revisi', href: '#' },
    ];

    function setDraft<K extends keyof DraftData>(field: K, value: DraftData[K]) {
        setDraftState((prev) => ({ ...prev, [field]: value }));
    }

    // Kode table helpers
    type KodeField = 'kode_bidang_pelayanan' | 'kode_sub_bidang_pelayanan' | 'kode_kategori_pelayanan' | 'kode_jenis_program';

    function addKodeRow(field: KodeField) {
        setDraft(field, [...draft[field], { kode: nextKode(kodePrefixMap[field], draft[field]), nama: '', catatan: '' }]);
    }

    function removeKodeRow(field: KodeField, index: number) {
        setDraft(field, draft[field].filter((_, i) => i !== index));
    }

    function updateKodeRow(field: KodeField, index: number, key: keyof KodeItem, value: string) {
        setDraft(field, draft[field].map((item, i) => (i === index ? { ...item, [key]: value } : item)));
    }

    // Kuisioner helpers
    function addKuisionerRow() {
        setDraft('item_kuisioner', [...draft.item_kuisioner, { kode: nextKode('Q', draft.item_kuisioner), pertanyaan: '', tipe: 'Kualitatif', satuan: '' }]);
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
            { team_id: 0, kode_team: nextKode('T', draft.item_plafon_anggaran), plafon_anggaran: 0, nama_bank: '', nama_rekening: '', nomor_rekening: '', catatan: '' },
        ]);
    }

    function removePlafonRow(index: number) {
        setDraft('item_plafon_anggaran', draft.item_plafon_anggaran.filter((_, i) => i !== index));
    }

    function updatePlafonRow(index: number, field: string, value: string | number) {
        setDraft('item_plafon_anggaran', draft.item_plafon_anggaran.map((item, i) => (i === index ? { ...item, [field]: value } : item)));
    }

    function addFiles(newFiles: FileList | File[]) {
        const entries: FileEntry[] = Array.from(newFiles).map((file) => ({
            type: 'new' as const,
            file,
            name: file.name,
            size: file.size,
            key: `${file.name}-${file.size}-${Date.now()}-${Math.random()}`,
        }));
        setFileEntries((prev) => [...prev, ...entries]);
    }

    function removeFileEntry(index: number) {
        setFileEntries((prev) => prev.filter((_, i) => i !== index));
    }

    function handleDrop(e: React.DragEvent) {
        e.preventDefault();
        setDragOver(false);
        if (e.dataTransfer.files.length > 0) {
            addFiles(e.dataTransfer.files);
        }
    }

    function handleAction(action: 'draft' | 'submit', notes: string, commentFiles: File[]) {
        setProcessing(true);
        const url = `/admin/workflows/pp/${workflow.id}/pp07/${stepData.id}/${action}`;

        const keepFileIds = fileEntries.filter((e): e is ExistingFile => e.type === 'existing').map((e) => e.file_id);
        const newDokumenFiles = fileEntries.filter((e): e is NewFile => e.type === 'new').map((e) => e.file);

        // Update draft_data with current file state
        const updatedDraft = { ...draft, item_dokumen_sop: keepFileIds.map((id) => ({ file_id: id })) };

        router.post(url, {
            draft_data: updatedDraft,
            keep_file_ids: keepFileIds,
            dokumen_files: newDokumenFiles,
            expected_updated_at: stepData.updated_at,
            notes: notes || undefined,
            ...(commentFiles.length > 0 ? { files: commentFiles } : {}),
        }, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PP05') return `/admin/workflows/pp/${workflow.id}/pp05`;
        if (step === 'PP06') return `/admin/workflows/pp/${workflow.id}/pp06${entry.revision !== undefined ? `?revision=${entry.revision}` : ''}`;
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

                {mode === 'readonly' && (
                    <div className="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
                        Step ini sudah selesai disubmit. Data hanya dapat dilihat.
                    </div>
                )}

                {isPermissionLocked && (
                    <div className="rounded-md border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-200">
                        Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {Object.keys(errors).length > 0 && (
                    <AlertError errors={Object.values(errors)} title="Validasi gagal" />
                )}

                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp07"
                    canComment={canComment}
                    finalSteps={['PP06']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* Informasi Periode */}
                <SectionCard title="Informasi Periode">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Tahun <span className="text-destructive">*</span></label>
                            <Input
                                type="number"
                                value={draft.tahun ?? ''}
                                onChange={(e) => setDraft('tahun', e.target.value === '' ? null : Number(e.target.value))}
                                disabled={isReadonly}
                                min={2020}
                                max={2099}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Tanggal Mulai Pra-Raker <span className="text-destructive">*</span></label>
                            <DateInput
                                value={draft.tanggal_mulai_pra_raker ?? ''}
                                onChange={(v) => setDraft('tanggal_mulai_pra_raker', v || null)}
                                disabled={isReadonly}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Tanggal Penetapan Program <span className="text-destructive">*</span></label>
                            <DateInput
                                value={draft.tanggal_penetapan_program ?? ''}
                                onChange={(v) => setDraft('tanggal_penetapan_program', v || null)}
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
                            <table className="min-w-200 w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-3 py-2 text-left font-medium w-24">Kode <span className="text-destructive">*</span></th>
                                        {!isReadonly && <th className="px-3 py-2 w-12" />}
                                        <th className="px-3 py-2 text-left font-medium">Nama <span className="text-destructive">*</span></th>
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
                                                        <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                    </Button>
                                                </td>
                                            )}
                                            <td className="px-3 py-1.5">
                                                <Input value={item.nama} onChange={(e) => updateKodeRow(field, i, 'nama', e.target.value)} disabled={isReadonly} className="h-8" />
                                            </td>
                                            <td className="px-3 py-1.5 align-top">
                                                <Textarea value={item.catatan ?? ''} onChange={(e) => updateKodeRow(field, i, 'catatan', e.target.value)} disabled={isReadonly} rows={1} className="min-h-8" />
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
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode <span className="text-destructive">*</span></th>
                                    {!isReadonly && <th className="px-3 py-2 w-12" />}
                                    <th className="px-3 py-2 text-left font-medium">Pertanyaan <span className="text-destructive">*</span></th>
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
                                                    <Trash2 className="h-3.5 w-3.5 text-destructive" />
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
                                    <th className="px-3 py-2 text-left font-medium w-20">Kode Tim <span className="text-destructive">*</span></th>
                                    {!isReadonly && <th className="px-3 py-2 w-12" />}
                                    <th className="px-3 py-2 text-left font-medium w-56">Tim <span className="text-destructive">*</span></th>
                                    <th className="px-3 py-2 text-right font-medium w-44">Plafon <span className="text-destructive">*</span></th>
                                    <th className="px-3 py-2 text-left font-medium w-28">Bank</th>
                                    <th className="px-3 py-2 text-left font-medium w-48">Nama Rek.</th>
                                    <th className="px-3 py-2 text-left font-medium w-44">No. Rek.</th>
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
                                                    <Trash2 className="h-3.5 w-3.5 text-destructive" />
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
                                            <RupiahInput value={item.plafon_anggaran} onChange={(v) => updatePlafonRow(i, 'plafon_anggaran', v)} disabled={isReadonly} className="h-8" min={0} />
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
                                        <td className="px-3 py-1.5 align-top">
                                            <Textarea value={item.catatan ?? ''} onChange={(e) => updatePlafonRow(i, 'catatan', e.target.value)} disabled={isReadonly} rows={1} className="min-h-8" />
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
                    {fileEntries.length > 0 && (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-3 py-2 text-left font-medium w-12">#</th>
                                        <th className="px-3 py-2 text-left font-medium">Nama File</th>
                                        <th className="px-3 py-2 text-right font-medium w-24">Ukuran</th>
                                        {!isReadonly && <th className="px-3 py-2 w-12" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {fileEntries.map((entry, i) => (
                                        <tr key={entry.type === 'existing' ? `e-${entry.file_id}` : entry.key} className="border-b last:border-0">
                                            <td className="px-3 py-2 text-muted-foreground">{i + 1}</td>
                                            <td className="px-3 py-2">
                                                {entry.type === 'existing' ? (
                                                    <a href={`/files/${entry.uuid}/download`} className="inline-flex items-center gap-1 text-blue-700 underline decoration-blue-300 hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100">
                                                        <Download className="h-3 w-3 shrink-0" />
                                                        {entry.name}
                                                    </a>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1">
                                                        {entry.name}
                                                        <Badge className="ml-1 bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">Baru</Badge>
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right text-muted-foreground">
                                                {entry.size > 0 ? formatFileSize(entry.size) : '—'}
                                            </td>
                                            {!isReadonly && (
                                                <td className="px-3 py-2">
                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => removeFileEntry(i)}>
                                                        <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {!isReadonly && (
                        <div
                            className={`mt-3 flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 transition-colors ${dragOver ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'}`}
                            onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                            onDragLeave={() => setDragOver(false)}
                            onDrop={handleDrop}
                        >
                            <Upload className="mb-2 h-8 w-8 text-muted-foreground" />
                            <p className="text-sm text-muted-foreground">
                                Drag & drop file di sini, atau{' '}
                                <button type="button" className="text-primary underline" onClick={() => fileInputRef.current?.click()}>
                                    pilih file
                                </button>
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">Maks. 50MB per file</p>
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                className="hidden"
                                onChange={(e) => {
                                    if (e.target.files && e.target.files.length > 0) {
                                        addFiles(e.target.files);
                                        e.target.value = '';
                                    }
                                }}
                            />
                        </div>
                    )}

                    {isReadonly && fileEntries.length === 0 && (
                        <p className="py-4 text-center text-sm text-muted-foreground">Tidak ada dokumen dilampirkan.</p>
                    )}
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canDraft || canSubmit) && (
                    <div className="flex gap-2">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button variant="outline" disabled={processing}>
                                        {processing ? 'Menyimpan...' : 'Simpan Draft'}
                                    </Button>
                                }
                                title="Simpan Draft Revisi"
                                description="Simpan data revisi sebagai draft. Data belum divalidasi dan bisa diubah kembali."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing}>
                                        {processing ? 'Mengirim...' : 'Submit Revisi'}
                                    </Button>
                                }
                                title="Submit Revisi"
                                description="Data akan divalidasi dan dikompilasi menjadi revisi baru PP06. Revisi sebelumnya tetap tersimpan."
                                confirmLabel="Submit Revisi"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
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
