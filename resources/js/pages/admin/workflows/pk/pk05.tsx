import { Head, usePage, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { ChevronDown, ChevronUp, FileText, Lock, Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RupiahInput } from '@/components/ui/rupiah-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

// ────────────────────────────────────────────────────────────
// Types
// ────────────────────────────────────────────────────────────

type KodeRef = { kode: string; nama: string };
type KuisionerTemplate = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };

type Anggaran = {
    pk04_anggaran_id: number | null;
    kode_bidang: string;
    kode_sub_bidang: string;
    kode_jenis: string;
    mata_anggaran: string;
    deskripsi_pk: string;
    nominal_anggaran: number;
    is_locked: boolean;
    lock_reason?: string;
};

type Kuisioner = {
    pk04_kuisioner_id: number | null;
    kode_kuisioner: string | null;
    pertanyaan: string;
    tipe: string;
    satuan: string | null;
};

type Kegiatan = {
    pk04_kegiatan_id: number | null;
    nama_kegiatan: string;
    bulan: number | null;
    source?: string;
    anggaran: Anggaran[];
    kuisioner: Kuisioner[];
};

type DraftData = {
    kode_kategori: string;
    nama_program: string;
    deskripsi_program: string;
    tujuan_program: string;
    kegiatan: Kegiatan[];
};

type StepData = {
    id: number;
    draft_data: DraftData;
    submitted_at: string | null;
    updated_at: string;
};

type BudgetContext = {
    plafon: number;
    sudah_ditetapkan_lain: number;
    sisa: number;
};

type Pp06Kodes = {
    kategori: KodeRef[];
    bidang: KodeRef[];
    subBidang: KodeRef[];
    jenis: KodeRef[];
};

type Workflow = { id: number; label: string; status: string; history: HistoryEntry[]; tipe: string };

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canComment: boolean;
    pp06Kodes: Pp06Kodes;
    kuisionerTemplates: KuisionerTemplate[];
    budgetContext: BudgetContext | null;
    pkType: string;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
};

const BULAN_LABELS = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const tipePresets = ['Kualitatif', 'Kuantitatif'];

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

function emptyAnggaran(): Anggaran {
    return { pk04_anggaran_id: null, kode_bidang: '', kode_sub_bidang: '', kode_jenis: '', mata_anggaran: '', deskripsi_pk: '', nominal_anggaran: 0, is_locked: false };
}

function emptyKuisioner(): Kuisioner {
    return { pk04_kuisioner_id: null, kode_kuisioner: null, pertanyaan: '', tipe: 'Kualitatif', satuan: null };
}

function emptyKegiatan(): Kegiatan {
    return { pk04_kegiatan_id: null, nama_kegiatan: '', bulan: null, anggaran: [emptyAnggaran()], kuisioner: [] };
}

// ────────────────────────────────────────────────────────────
// Main Page Component
// ────────────────────────────────────────────────────────────

export default function Pk05({
    workflow, stepData, mode, canDraft, canSubmit, canComment,
    pp06Kodes, kuisionerTemplates, budgetContext, pkType, actionRoles, activeRoleName,
}: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [processing, setProcessing] = useState(false);
    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);
    const isPermissionLocked = mode !== 'readonly' && !canDraft && !canSubmit;
    const basePath = `/admin/workflows/pk/${workflow.id}`;

    const d = stepData.draft_data;
    const [kodeKategori, setKodeKategori] = useState(d.kode_kategori ?? '');
    const [namaProgram, setNamaProgram] = useState(d.nama_program ?? '');
    const [deskripsiProgram, setDeskripsiProgram] = useState(d.deskripsi_program ?? '');
    const [tujuanProgram, setTujuanProgram] = useState(d.tujuan_program ?? '');
    const [kegiatanList, setKegiatanList] = useState<Kegiatan[]>(
        d.kegiatan?.length > 0
            ? d.kegiatan.map((k) => ({
                ...k,
                anggaran: k.anggaran?.length > 0 ? k.anggaran : [emptyAnggaran()],
                kuisioner: k.kuisioner ?? [],
            }))
            : [emptyKegiatan()],
    );

    // Live budget total
    const draftTotal = useMemo(() => {
        return kegiatanList.reduce((sum, k) =>
            sum + k.anggaran.reduce((s, a) => s + (Number(a.nominal_anggaran) || 0), 0), 0);
    }, [kegiatanList]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Kegiatan', href: '/admin/workflows/pk' },
        { title: workflow.label, href: basePath },
        { title: 'PK05: Revisi', href: '#' },
    ];

    // ── Kegiatan helpers ──
    function updateKegiatan(index: number, updates: Partial<Kegiatan>) {
        setKegiatanList((prev) => prev.map((k, i) => (i === index ? { ...k, ...updates } : k)));
    }

    function addKegiatan() {
        setKegiatanList((prev) => [...prev, emptyKegiatan()]);
    }

    function removeKegiatan(index: number) {
        setKegiatanList((prev) => prev.filter((_, i) => i !== index));
    }

    // ── Kuisioner template picker ──
    const [templatePickerIdx, setTemplatePickerIdx] = useState<number | null>(null);

    function addFromTemplate(kegiatanIdx: number, templates: KuisionerTemplate[]) {
        setKegiatanList((prev) => prev.map((k, i) => {
            if (i !== kegiatanIdx) return k;
            const existing = new Set(k.kuisioner.map((q) => q.kode_kuisioner).filter(Boolean));
            const newItems: Kuisioner[] = templates
                .filter((t) => !existing.has(t.kode))
                .map((t) => ({
                    pk04_kuisioner_id: null,
                    kode_kuisioner: t.kode,
                    pertanyaan: t.pertanyaan,
                    tipe: t.tipe,
                    satuan: t.satuan,
                }));
            return { ...k, kuisioner: [...k.kuisioner, ...newItems] };
        }));
        setTemplatePickerIdx(null);
    }

    // ── Form data builder ──
    function buildDraftData(): DraftData {
        return {
            kode_kategori: kodeKategori,
            nama_program: namaProgram,
            deskripsi_program: deskripsiProgram,
            tujuan_program: tujuanProgram,
            kegiatan: kegiatanList.map((k) => ({
                pk04_kegiatan_id: k.pk04_kegiatan_id,
                nama_kegiatan: k.nama_kegiatan,
                bulan: k.bulan,
                anggaran: k.anggaran.map((a) => ({
                    pk04_anggaran_id: a.pk04_anggaran_id,
                    kode_bidang: a.kode_bidang,
                    kode_sub_bidang: a.kode_sub_bidang,
                    kode_jenis: a.kode_jenis,
                    mata_anggaran: a.mata_anggaran,
                    deskripsi_pk: a.deskripsi_pk,
                    nominal_anggaran: a.nominal_anggaran,
                    is_locked: a.is_locked,
                })),
                kuisioner: k.kuisioner.map((q) => ({
                    pk04_kuisioner_id: q.pk04_kuisioner_id,
                    kode_kuisioner: q.kode_kuisioner,
                    pertanyaan: q.pertanyaan,
                    tipe: q.tipe,
                    satuan: q.satuan,
                })),
            })),
        };
    }

    function handleAction(action: 'draft' | 'submit', notes: string, commentFiles: File[]) {
        setProcessing(true);
        const url = `${basePath}/pk05/${stepData.id}/${action}`;
        router.post(url, {
            draft_data: buildDraftData(),
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
        if (step === 'PK02A') return `${basePath}/pk02a`;
        if (step === 'PK02B') return `${basePath}/pk02b`;
        if (step === 'PK03') return `${basePath}/pk03`;
        if (step === 'PK04') return `${basePath}/pk04${entry.revision !== undefined ? `?revision=${entry.revision}` : ''}`;
        if (entry.id && entry.table) return `${basePath}/${step.toLowerCase()}/${entry.id}`;
        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PK05: Revisi — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <Heading title="PK05: Revisi Program Tahunan" description="Revisi data perencanaan kegiatan yang sudah dikompilasi" />
                    <StepStatusBadge mode={mode} />
                    {pkType === 'proposal' && (
                        <Badge className="bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">PK Proposal</Badge>
                    )}
                </div>

                {mode === 'readonly' && (
                    <div className="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
                        Revisi ini sudah disubmit. Data hanya dapat dilihat.
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
                    commentUrl={`${basePath}/comment`}
                    commentSource="pk05"
                    canComment={canComment}
                    finalSteps={['PK04']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* Informasi Program */}
                <SectionCard title="Informasi Program">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Kategori Pelayanan <Lock className="ml-1 inline h-3 w-3 text-amber-500" /></Label>
                            <Select value={kodeKategori} onValueChange={setKodeKategori} disabled>
                                <SelectTrigger>
                                    <SelectValue placeholder="Pilih kategori..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {pp06Kodes.kategori.map((k) => (
                                        <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">Kategori tidak dapat diubah setelah kompilasi.</p>
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Nama Program <span className="text-destructive">*</span></Label>
                            <Input value={namaProgram} onChange={(e) => setNamaProgram(e.target.value)} disabled={isReadonly} maxLength={255} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Deskripsi Program <span className="text-destructive">*</span></Label>
                            <Textarea value={deskripsiProgram} onChange={(e) => setDeskripsiProgram(e.target.value)} disabled={isReadonly} rows={3} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tujuan Program <span className="text-destructive">*</span></Label>
                            <Textarea value={tujuanProgram} onChange={(e) => setTujuanProgram(e.target.value)} disabled={isReadonly} rows={3} />
                        </div>
                    </div>
                </SectionCard>

                {/* Budget Summary (raker only) */}
                {budgetContext && (
                    <SectionCard title="Ringkasan Anggaran">
                        {pkType === 'proposal' ? (
                            <p className="text-sm text-muted-foreground">PK Proposal — tidak dihitung dalam plafon.</p>
                        ) : (
                            <div className="space-y-3">
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <BudgetItem label="Total Anggaran Draft" value={draftTotal} highlight />
                                    <BudgetItem label="Plafon Tim" value={budgetContext.plafon} />
                                    <BudgetItem label="Sudah Ditetapkan (PK Lain)" value={budgetContext.sudah_ditetapkan_lain} />
                                    <BudgetItem label="Tersedia" value={budgetContext.sisa} />
                                </div>
                                {draftTotal > budgetContext.sisa && budgetContext.sisa >= 0 && (
                                    <p className="text-xs text-amber-600 dark:text-amber-400">
                                        Total anggaran draft melebihi sisa plafon. Submit akan diblokir.
                                    </p>
                                )}
                            </div>
                        )}
                    </SectionCard>
                )}

                {/* Kegiatan & Anggaran */}
                <SectionCard
                    title={`Kegiatan & Anggaran (${kegiatanList.length} kegiatan)`}
                    headerContent={!isReadonly && (
                        <Button variant="outline" size="sm" onClick={addKegiatan}>
                            <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Kegiatan
                        </Button>
                    )}
                >
                    {kegiatanList.length === 0 && (
                        <p className="text-sm text-muted-foreground">Belum ada kegiatan.</p>
                    )}

                    <div className="space-y-4">
                        {kegiatanList.map((kegiatan, kIdx) => (
                            <KegiatanCard
                                key={kIdx}
                                index={kIdx}
                                kegiatan={kegiatan}
                                isReadonly={isReadonly}
                                pp06Kodes={pp06Kodes}
                                kuisionerTemplates={kuisionerTemplates}
                                errors={errors}
                                onUpdate={(updates) => updateKegiatan(kIdx, updates)}
                                onRemove={() => removeKegiatan(kIdx)}
                                canRemove={kegiatan.pk04_kegiatan_id === null}
                                onOpenTemplatePicker={() => setTemplatePickerIdx(kIdx)}
                            />
                        ))}
                    </div>
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
                                description="Data akan divalidasi dan dikompilasi menjadi revisi baru PK04. Revisi sebelumnya tetap tersimpan."
                                confirmLabel="Submit Revisi"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
                        )}
                    </div>
                )}
            </div>

            {/* Kuisioner Template Picker Modal */}
            {templatePickerIdx !== null && (
                <KuisionerTemplatePicker
                    templates={kuisionerTemplates}
                    existingKodes={new Set(kegiatanList[templatePickerIdx]?.kuisioner.map((q) => q.kode_kuisioner).filter(Boolean) as string[])}
                    onConfirm={(selected) => addFromTemplate(templatePickerIdx, selected)}
                    onClose={() => setTemplatePickerIdx(null)}
                />
            )}
        </AppLayout>
    );
}

// ────────────────────────────────────────────────────────────
// KuisionerTemplatePicker
// ────────────────────────────────────────────────────────────

function KuisionerTemplatePicker({ templates, existingKodes, onConfirm, onClose }: {
    templates: KuisionerTemplate[];
    existingKodes: Set<string>;
    onConfirm: (selected: KuisionerTemplate[]) => void;
    onClose: () => void;
}) {
    const [selected, setSelected] = useState<Set<string>>(new Set());

    function toggle(kode: string) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(kode)) next.delete(kode);
            else next.add(kode);
            return next;
        });
    }

    const available = templates.filter((t) => !existingKodes.has(t.kode));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={onClose}>
            <div className="mx-4 max-h-[80vh] w-full max-w-lg overflow-hidden rounded-lg bg-background shadow-lg" onClick={(e) => e.stopPropagation()}>
                <div className="border-b px-4 py-3">
                    <h3 className="font-medium">Tambah Kuisioner dari Template PP</h3>
                    <p className="text-xs text-muted-foreground">Pilih pertanyaan kuisioner dari template yang sudah didefinisikan.</p>
                </div>
                <div className="max-h-[50vh] overflow-y-auto p-4">
                    {available.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Semua template sudah ditambahkan.</p>
                    ) : (
                        <div className="space-y-2">
                            {available.map((t) => (
                                <label key={t.kode} className="flex cursor-pointer items-start gap-3 rounded border p-3 hover:bg-muted/50">
                                    <input type="checkbox" checked={selected.has(t.kode)} onChange={() => toggle(t.kode)} className="mt-0.5" />
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium">{t.pertanyaan}</p>
                                        <p className="text-xs text-muted-foreground">
                                            Tipe: {t.tipe}{t.satuan ? ` · Satuan: ${t.satuan}` : ''} · Kode: {t.kode}
                                        </p>
                                    </div>
                                </label>
                            ))}
                        </div>
                    )}
                </div>
                <div className="flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="outline" size="sm" onClick={onClose}>Batal</Button>
                    <Button size="sm" onClick={() => onConfirm(templates.filter((t) => selected.has(t.kode)))} disabled={selected.size === 0}>
                        Tambahkan ({selected.size})
                    </Button>
                </div>
            </div>
        </div>
    );
}

// ────────────────────────────────────────────────────────────
// StepStatusBadge
// ────────────────────────────────────────────────────────────

function StepStatusBadge({ mode }: { mode: string }) {
    const config: Record<string, { label: string; className: string }> = {
        readonly: { label: 'Sudah Disubmit', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
        edit: { label: 'Draft Revisi', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
    };
    const c = config[mode] ?? config.edit;
    return <Badge className={c.className}>{c.label}</Badge>;
}

// ────────────────────────────────────────────────────────────
// Budget Item
// ────────────────────────────────────────────────────────────

function BudgetItem({ label, value, highlight }: { label: string; value: number; highlight?: boolean }) {
    return (
        <div className="rounded-md border px-3 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={`text-sm font-semibold tabular-nums ${highlight ? 'text-primary' : ''}`}>{formatRupiah(value)}</p>
        </div>
    );
}

// ────────────────────────────────────────────────────────────
// Kegiatan Card
// ────────────────────────────────────────────────────────────

function KegiatanCard({
    index, kegiatan, isReadonly, pp06Kodes, kuisionerTemplates, errors, onUpdate, onRemove, canRemove, onOpenTemplatePicker,
}: {
    index: number;
    kegiatan: Kegiatan;
    isReadonly: boolean;
    pp06Kodes: Pp06Kodes;
    kuisionerTemplates: KuisionerTemplate[];
    errors: Record<string, string>;
    onUpdate: (updates: Partial<Kegiatan>) => void;
    onRemove: () => void;
    canRemove: boolean;
    onOpenTemplatePicker: () => void;
}) {
    const [collapsed, setCollapsed] = useState(false);
    const ep = `draft_data.kegiatan.${index}`;
    const kegiatanTotal = kegiatan.anggaran.reduce((s, a) => s + (Number(a.nominal_anggaran) || 0), 0);
    const isExistingKegiatan = kegiatan.pk04_kegiatan_id !== null;

    // Anggaran helpers
    function updateAnggaran(aIdx: number, updates: Partial<Anggaran>) {
        const newAnggaran = kegiatan.anggaran.map((a, i) => (i === aIdx ? { ...a, ...updates } : a));
        onUpdate({ anggaran: newAnggaran });
    }

    function addAnggaran() {
        onUpdate({ anggaran: [...kegiatan.anggaran, emptyAnggaran()] });
    }

    function removeAnggaran(aIdx: number) {
        onUpdate({ anggaran: kegiatan.anggaran.filter((_, i) => i !== aIdx) });
    }

    // Kuisioner helpers
    function updateKuisioner(qIdx: number, updates: Partial<Kuisioner>) {
        const newK = kegiatan.kuisioner.map((q, i) => (i === qIdx ? { ...q, ...updates } : q));
        onUpdate({ kuisioner: newK });
    }

    function addKuisioner() {
        onUpdate({ kuisioner: [...kegiatan.kuisioner, emptyKuisioner()] });
    }

    function removeKuisioner(qIdx: number) {
        onUpdate({ kuisioner: kegiatan.kuisioner.filter((_, i) => i !== qIdx) });
    }

    return (
        <div className="rounded-lg border">
            {/* Card header */}
            <div className="flex items-center gap-2 border-b bg-muted/30 px-4 py-2">
                <button type="button" onClick={() => setCollapsed(!collapsed)} className="flex items-center gap-1 text-sm font-medium">
                    {collapsed ? <ChevronDown className="h-4 w-4" /> : <ChevronUp className="h-4 w-4" />}
                    Kegiatan {index + 1}
                    {kegiatan.nama_kegiatan && <span className="font-normal text-muted-foreground">: {kegiatan.nama_kegiatan}</span>}
                </button>
                {kegiatan.source && (
                    <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">{kegiatan.source}</Badge>
                )}
                {kegiatan.pk04_kegiatan_id !== null && (
                    <Badge variant="outline" className="gap-1 text-amber-600"><Lock className="h-3 w-3" /> Terkompilasi</Badge>
                )}
                <span className="ml-auto text-xs text-muted-foreground tabular-nums">Rp {new Intl.NumberFormat('id-ID').format(kegiatanTotal)}</span>
                {!isReadonly && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onRemove}
                        disabled={!canRemove}
                        title={canRemove ? 'Hapus Kegiatan' : 'Kegiatan yang sudah dikompilasi tidak dapat dihapus'}
                        className="h-7 w-7 p-0"
                    >
                        <Trash2 className="h-3.5 w-3.5 text-destructive" />
                    </Button>
                )}
            </div>

            {/* Card body */}
            {!collapsed && (
                <div className="space-y-4 p-4">
                    {/* Kegiatan fields */}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Nama Kegiatan <span className="text-destructive">*</span></Label>
                            <Input
                                value={kegiatan.nama_kegiatan}
                                onChange={(e) => onUpdate({ nama_kegiatan: e.target.value })}
                                disabled={isReadonly}
                                maxLength={255}
                            />
                            {errors[`${ep}.nama_kegiatan`] && <p className="text-xs text-destructive">{errors[`${ep}.nama_kegiatan`]}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Bulan <span className="text-destructive">*</span>{isExistingKegiatan && <Lock className="ml-1 inline h-3 w-3 text-amber-500" />}</Label>
                            <Select
                                value={kegiatan.bulan !== null ? String(kegiatan.bulan) : ''}
                                onValueChange={(v) => onUpdate({ bulan: v === '' ? null : Number(v) })}
                                disabled={isReadonly || isExistingKegiatan}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Pilih bulan..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {BULAN_LABELS.map((label, i) => (
                                        <SelectItem key={i + 1} value={String(i + 1)}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors[`${ep}.bulan`] && <p className="text-xs text-destructive">{errors[`${ep}.bulan`]}</p>}
                        </div>
                    </div>

                    {/* Anggaran sub-table */}
                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h4 className="text-sm font-medium">Anggaran ({kegiatan.anggaran.length})</h4>
                            {!isReadonly && (
                                <Button variant="outline" size="sm" onClick={addAnggaran}>
                                    <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Anggaran
                                </Button>
                            )}
                        </div>
                        {errors[`${ep}.anggaran`] && <p className="mb-2 text-xs text-destructive">{errors[`${ep}.anggaran`]}</p>}
                        <div className="overflow-x-auto">
                            <table className="min-w-[900px] w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-2 py-1.5 text-left font-medium w-8" />
                                        <th className="px-2 py-1.5 text-left font-medium w-36">Bidang <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium w-36">Sub Bidang <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium w-36">Jenis <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-32">Mata Anggaran <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-32">Deskripsi <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium w-40">Nominal (Rp) <span className="text-destructive">*</span></th>
                                        {!isReadonly && <th className="px-2 py-1.5 w-10" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {kegiatan.anggaran.map((a, aIdx) => {
                                        const isPencairanLocked = a.is_locked;
                                        const isExistingAnggaran = a.pk04_anggaran_id !== null;
                                        const isKodeLocked = isExistingAnggaran || isPencairanLocked;
                                        const isFullyLocked = isPencairanLocked;
                                        const aep = `${ep}.anggaran.${aIdx}`;
                                        return (
                                            <tr key={aIdx} className={`border-b last:border-0 ${isKodeLocked ? 'bg-muted/40' : ''}`}>
                                                <td className="px-2 py-1 text-center">
                                                    {isKodeLocked && (
                                                        <span title={isPencairanLocked ? (a.lock_reason ?? 'Item terkunci (pencairan)') : 'Kode terkunci setelah kompilasi'}>
                                                            <Lock className="h-3.5 w-3.5 text-amber-500" />
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <Select value={a.kode_bidang} onValueChange={(v) => updateAnggaran(aIdx, { kode_bidang: v })} disabled={isReadonly || isKodeLocked}>
                                                        <SelectTrigger className="h-8 text-xs">
                                                            <SelectValue placeholder="Pilih..." />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {pp06Kodes.bidang.map((k) => (
                                                                <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {errors[`${aep}.kode_bidang`] && <p className="text-xs text-destructive">{errors[`${aep}.kode_bidang`]}</p>}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <Select value={a.kode_sub_bidang} onValueChange={(v) => updateAnggaran(aIdx, { kode_sub_bidang: v })} disabled={isReadonly || isKodeLocked}>
                                                        <SelectTrigger className="h-8 text-xs">
                                                            <SelectValue placeholder="Pilih..." />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {pp06Kodes.subBidang.map((k) => (
                                                                <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {errors[`${aep}.kode_sub_bidang`] && <p className="text-xs text-destructive">{errors[`${aep}.kode_sub_bidang`]}</p>}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <Select value={a.kode_jenis} onValueChange={(v) => updateAnggaran(aIdx, { kode_jenis: v })} disabled={isReadonly || isKodeLocked}>
                                                        <SelectTrigger className="h-8 text-xs">
                                                            <SelectValue placeholder="Pilih..." />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {pp06Kodes.jenis.map((k) => (
                                                                <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {errors[`${aep}.kode_jenis`] && <p className="text-xs text-destructive">{errors[`${aep}.kode_jenis`]}</p>}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <Input value={a.mata_anggaran} onChange={(e) => updateAnggaran(aIdx, { mata_anggaran: e.target.value })} disabled={isReadonly || isFullyLocked} className="h-8" maxLength={255} />
                                                    {errors[`${aep}.mata_anggaran`] && <p className="text-xs text-destructive">{errors[`${aep}.mata_anggaran`]}</p>}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <Input value={a.deskripsi_pk} onChange={(e) => updateAnggaran(aIdx, { deskripsi_pk: e.target.value })} disabled={isReadonly || isFullyLocked} className="h-8" />
                                                    {errors[`${aep}.deskripsi_pk`] && <p className="text-xs text-destructive">{errors[`${aep}.deskripsi_pk`]}</p>}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <RupiahInput value={a.nominal_anggaran} onChange={(v) => updateAnggaran(aIdx, { nominal_anggaran: v })} disabled={isReadonly || isFullyLocked} className="h-8" min={0} />
                                                    {errors[`${aep}.nominal_anggaran`] && <p className="text-xs text-destructive">{errors[`${aep}.nominal_anggaran`]}</p>}
                                                </td>
                                                {!isReadonly && (
                                                    <td className="px-2 py-1">
                                                        {!isExistingAnggaran && !isPencairanLocked && (
                                                            <Button variant="ghost" size="sm" onClick={() => removeAnggaran(aIdx)} className="h-8 w-8 p-0">
                                                                <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                            </Button>
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t bg-muted/30 font-medium">
                                        <td className="px-2 py-2" colSpan={6}>Subtotal</td>
                                        <td className="px-2 py-2 tabular-nums">{formatRupiah(kegiatanTotal)}</td>
                                        {!isReadonly && <td />}
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    {/* Kuisioner sub-table */}
                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h4 className="text-sm font-medium">Kuisioner ({kegiatan.kuisioner.length})</h4>
                            {!isReadonly && (
                                <div className="flex gap-1">
                                    {kuisionerTemplates.length > 0 && (
                                        <Button variant="outline" size="sm" onClick={onOpenTemplatePicker}>
                                            <FileText className="mr-1 h-3.5 w-3.5" /> Dari Template
                                        </Button>
                                    )}
                                    <Button variant="outline" size="sm" onClick={addKuisioner}>
                                        <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Custom
                                    </Button>
                                </div>
                            )}
                        </div>
                        {kegiatan.kuisioner.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="px-2 py-1.5 text-left font-medium w-20">Kode</th>
                                            {!isReadonly && <th className="px-2 py-1.5 w-10" />}
                                            <th className="px-2 py-1.5 text-left font-medium">Pertanyaan <span className="text-destructive">*</span></th>
                                            <th className="px-2 py-1.5 text-left font-medium w-36">Tipe <span className="text-destructive">*</span></th>
                                            <th className="px-2 py-1.5 text-left font-medium w-28">Satuan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.kuisioner.map((q, qIdx) => {
                                            const qep = `${ep}.kuisioner.${qIdx}`;
                                            return (
                                                <tr key={qIdx} className="border-b last:border-0">
                                                    <td className="px-2 py-1.5 font-mono text-xs text-muted-foreground">
                                                        {q.kode_kuisioner || '—'}
                                                    </td>
                                                    {!isReadonly && (
                                                        <td className="px-2 py-1.5">
                                                            <Button variant="ghost" size="sm" onClick={() => removeKuisioner(qIdx)} className="h-8 w-8 p-0">
                                                                <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                            </Button>
                                                        </td>
                                                    )}
                                                    <td className="px-2 py-1.5">
                                                        <Input value={q.pertanyaan} onChange={(e) => updateKuisioner(qIdx, { pertanyaan: e.target.value })} disabled={isReadonly || !!q.kode_kuisioner} className="h-8" maxLength={255} />
                                                        {errors[`${qep}.pertanyaan`] && <p className="text-xs text-destructive">{errors[`${qep}.pertanyaan`]}</p>}
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <select
                                                            value={q.tipe}
                                                            onChange={(e) => {
                                                                const updates: Partial<Kuisioner> = { tipe: e.target.value };
                                                                if (e.target.value === 'Kualitatif') updates.satuan = null;
                                                                updateKuisioner(qIdx, updates);
                                                            }}
                                                            disabled={isReadonly || !!q.kode_kuisioner}
                                                            className="h-8 w-full rounded-md border bg-background px-2 text-sm"
                                                        >
                                                            {tipePresets.map((t) => <option key={t} value={t}>{t}</option>)}
                                                        </select>
                                                        {errors[`${qep}.tipe`] && <p className="text-xs text-destructive">{errors[`${qep}.tipe`]}</p>}
                                                    </td>
                                                    <td className="px-2 py-1.5">
                                                        <Input
                                                            value={q.satuan ?? ''}
                                                            onChange={(e) => updateKuisioner(qIdx, { satuan: e.target.value || null })}
                                                            disabled={isReadonly || !!q.kode_kuisioner || q.tipe === 'Kualitatif'}
                                                            className="h-8"
                                                            placeholder={q.tipe === 'Kualitatif' ? '—' : 'wajib'}
                                                            maxLength={100}
                                                        />
                                                        {errors[`${qep}.satuan`] && <p className="text-xs text-destructive">{errors[`${qep}.satuan`]}</p>}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {kegiatan.kuisioner.length === 0 && (
                            <p className="text-xs text-muted-foreground">Belum ada kuisioner untuk kegiatan ini.</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
