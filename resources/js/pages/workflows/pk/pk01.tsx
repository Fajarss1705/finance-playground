import { Head, usePage, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { ChevronDown, ChevronUp, Copy, FileText, Plus, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RupiahInput } from '@/components/ui/rupiah-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

// ────────────────────────────────────────────────────────────
// Types
// ────────────────────────────────────────────────────────────

type KodeRef = { kode: string; nama: string };
type KuisionerTemplate = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };

type AnggaranRow = {
    id?: number;
    kode_bidang: string;
    kode_sub_bidang: string;
    kode_jenis: string;
    mata_anggaran: string;
    deskripsi_pk: string;
    nominal_anggaran: number;
};

type KuisionerRow = {
    id?: number;
    kode_kuisioner: string | null;
    pertanyaan: string;
    tipe: string;
    satuan: string;
};

type Kegiatan = {
    id?: number;
    nama_kegiatan: string;
    bulan: number | '';
    anggaran: AnggaranRow[];
    kuisioner: KuisionerRow[];
};

type StepData = {
    id: number;
    kode_kategori: string | null;
    nama_program: string | null;
    deskripsi_program: string | null;
    tujuan_program: string | null;
    kegiatan: Kegiatan[];
    updated_at: string;
};

type BudgetCounter = {
    ppLabel: string | null;
    plafon: number;
    accepted: number;
    planned: number;
    sisa: number;
};

type RejectionNotes = {
    step: string;
    step_label: string;
    notes: string | null;
    by_name: string | null;
    role_name: string | null;
    at: string | null;
    files: string[];
    parallelTrackNotes: {
        step: string;
        step_label: string;
        action: string;
        notes: string | null;
        by_name: string | null;
        role_name: string | null;
        at: string | null;
        files: string[];
    } | null;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    tipe: string;
};

type Props = {
    workflow: Workflow;
    stepData: StepData;
    kodeKategori: KodeRef[];
    kodeBidang: KodeRef[];
    kodeSubBidang: KodeRef[];
    kodeJenis: KodeRef[];
    kuisionerTemplates: KuisionerTemplate[];
    budgetCounter: BudgetCounter;
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canTerminate: boolean;
    canComment: boolean;
    isRejectionReentry: boolean;
    rejectionNotes: RejectionNotes | null;
    isPp07Active: boolean;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    scope: 'team' | 'admin';
    basePath: string;
};

const BULAN_LABELS = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID').format(value);
}

// ────────────────────────────────────────────────────────────
// Main Page Component
// ────────────────────────────────────────────────────────────

export default function Pk01({
    workflow, stepData, kodeKategori, kodeBidang, kodeSubBidang, kodeJenis,
    kuisionerTemplates, budgetCounter, mode, canDraft, canSubmit, canTerminate,
    canComment, isRejectionReentry, rejectionNotes, isPp07Active,
    actionRoles, activeRoleName, scope, basePath,
}: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [processing, setProcessing] = useState(false);

    // ── Form state ──
    const [kodeKategoriVal, setKodeKategoriVal] = useState(stepData.kode_kategori ?? '');
    const [namaProgram, setNamaProgram] = useState(stepData.nama_program ?? '');
    const [deskripsiProgram, setDeskripsiProgram] = useState(stepData.deskripsi_program ?? '');
    const [tujuanProgram, setTujuanProgram] = useState(stepData.tujuan_program ?? '');
    const [kegiatanList, setKegiatanList] = useState<Kegiatan[]>(
        stepData.kegiatan.length > 0
            ? stepData.kegiatan.map((k) => ({
                ...k,
                anggaran: k.anggaran.length > 0 ? k.anggaran : [emptyAnggaran()],
                kuisioner: k.kuisioner ?? [],
            }))
            : [emptyKegiatan()],
    );

    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);
    const isPermissionLocked = mode !== 'readonly' && !canDraft && !canSubmit;

    // ── Budget total for this PK ──
    const thisPkTotal = useMemo(() => {
        return kegiatanList.reduce((sum, k) =>
            sum + k.anggaran.reduce((s, a) => s + (a.nominal_anggaran || 0), 0), 0);
    }, [kegiatanList]);
    const isOverBudget = thisPkTotal > budgetCounter.sisa && budgetCounter.sisa > 0;

    // ── Breadcrumbs ──
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Perencanaan Kegiatan', href: `${scopeBase}/workflows/pk` },
        { title: workflow.label, href: `${basePath}` },
        { title: 'PK01: Program Kegiatan', href: '#' },
    ];

    // ── Form data builder ──
    function buildFormData(notes: string, files: File[]) {
        return {
            kode_kategori: kodeKategoriVal || undefined,
            nama_program: namaProgram || undefined,
            deskripsi_program: deskripsiProgram || undefined,
            tujuan_program: tujuanProgram || undefined,
            kegiatan: kegiatanList.map((k) => ({
                nama_kegiatan: k.nama_kegiatan || undefined,
                bulan: k.bulan === '' ? undefined : k.bulan,
                anggaran: k.anggaran.map((a) => ({
                    kode_bidang: a.kode_bidang || undefined,
                    kode_sub_bidang: a.kode_sub_bidang || undefined,
                    kode_jenis: a.kode_jenis || undefined,
                    mata_anggaran: a.mata_anggaran || undefined,
                    deskripsi_pk: a.deskripsi_pk || undefined,
                    nominal_anggaran: a.nominal_anggaran,
                })),
                kuisioner: k.kuisioner.length > 0 ? k.kuisioner.map((q) => ({
                    kode_kuisioner: q.kode_kuisioner || undefined,
                    pertanyaan: q.pertanyaan || undefined,
                    tipe: q.tipe || undefined,
                    satuan: q.satuan || undefined,
                })) : undefined,
            })),
            expected_updated_at: stepData.updated_at,
            notes: notes || undefined,
            ...(files.length > 0 ? { files } : {}),
        };
    }

    function handleAction(action: 'draft' | 'submit', notes: string, files: File[]) {
        setProcessing(true);
        const url = `${basePath}/pk01/${stepData.id}/${action}`;
        router.post(url, buildFormData(notes, files), {
            forceFormData: files.length > 0,
            onFinish: () => setProcessing(false),
        });
    }

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PK04') return `${basePath}/pk04`;
        if (entry.id && entry.table) {
            return `${basePath}/${step.toLowerCase()}/${entry.id}`;
        }
        return null;
    }

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

    function duplicateKegiatan(index: number) {
        const source = kegiatanList[index];
        const copy: Kegiatan = {
            nama_kegiatan: source.nama_kegiatan,
            bulan: source.bulan,
            anggaran: source.anggaran.map((a) => ({ ...a, id: undefined })),
            kuisioner: source.kuisioner.map((q) => ({ ...q, id: undefined })),
        };
        setKegiatanList((prev) => [...prev.slice(0, index + 1), copy, ...prev.slice(index + 1)]);
    }

    // ── Kuisioner template picker ──
    const [templatePickerIdx, setTemplatePickerIdx] = useState<number | null>(null);

    function addFromTemplate(kegiatanIdx: number, templates: KuisionerTemplate[]) {
        setKegiatanList((prev) => prev.map((k, i) => {
            if (i !== kegiatanIdx) return k;
            const existing = new Set(k.kuisioner.map((q) => q.kode_kuisioner).filter(Boolean));
            const newItems: KuisionerRow[] = templates
                .filter((t) => !existing.has(t.kode))
                .map((t) => ({
                    kode_kuisioner: t.kode,
                    pertanyaan: t.pertanyaan,
                    tipe: t.tipe,
                    satuan: t.satuan ?? '',
                }));
            return { ...k, kuisioner: [...k.kuisioner, ...newItems] };
        }));
        setTemplatePickerIdx(null);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PK01: Program Kegiatan — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <Heading title="PK01: Program Kegiatan" description="Formulir program, kegiatan, anggaran, dan kuisioner" />
                    <StepStatusBadge mode={mode} />
                    {workflow.tipe === 'proposal' && (
                        <Badge className="bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">PK Proposal</Badge>
                    )}
                </div>

                {/* PP07 Active Warning */}
                {isPp07Active && !isReadonly && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        PP sedang dalam proses revisi. Anda tidak dapat submit sampai revisi PP selesai.
                    </div>
                )}

                {/* Readonly banner */}
                {mode === 'readonly' && (
                    <div className="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
                        Step ini sudah selesai disubmit. Data hanya dapat dilihat.
                    </div>
                )}

                {/* Permission locked banner */}
                {isPermissionLocked && (
                    <div className="rounded-md border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-200">
                        Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {/* Rejection re-entry banner */}
                {isRejectionReentry && rejectionNotes && (
                    <RejectionBanner rejectionNotes={rejectionNotes} />
                )}

                {/* Errors */}
                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}
                {errors.kegiatan && <AlertError errors={[errors.kegiatan]} title="Validasi gagal" />}

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                {/* History & Comments */}
                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`${basePath}/comment`}
                    commentSource="pk01"
                    canComment={canComment}
                    finalSteps={['PK04']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* Budget Counter */}
                <BudgetCounterSection
                    counter={budgetCounter}
                    thisPkTotal={thisPkTotal}
                    isOverBudget={isOverBudget}
                />

                {/* Informasi Program */}
                <SectionCard title="Informasi Program">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Kategori Pelayanan <span className="text-destructive">*</span></Label>
                            <Select value={kodeKategoriVal} onValueChange={setKodeKategoriVal} disabled={isReadonly}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Pilih kategori..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {kodeKategori.map((k) => (
                                        <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.kode_kategori && <p className="text-xs text-destructive">{errors.kode_kategori}</p>}
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Nama Program <span className="text-destructive">*</span></Label>
                            <Input value={namaProgram} onChange={(e) => setNamaProgram(e.target.value)} disabled={isReadonly} maxLength={255} />
                            {errors.nama_program && <p className="text-xs text-destructive">{errors.nama_program}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Deskripsi Program <span className="text-destructive">*</span></Label>
                            <Textarea value={deskripsiProgram} onChange={(e) => setDeskripsiProgram(e.target.value)} disabled={isReadonly} rows={3} />
                            {errors.deskripsi_program && <p className="text-xs text-destructive">{errors.deskripsi_program}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tujuan Program <span className="text-destructive">*</span></Label>
                            <Textarea value={tujuanProgram} onChange={(e) => setTujuanProgram(e.target.value)} disabled={isReadonly} rows={3} />
                            {errors.tujuan_program && <p className="text-xs text-destructive">{errors.tujuan_program}</p>}
                        </div>
                    </div>
                </SectionCard>

                {/* Kegiatan & Anggaran */}
                <SectionCard
                    title="Kegiatan & Anggaran"
                    headerContent={!isReadonly && (
                        <Button variant="outline" size="sm" onClick={addKegiatan}>
                            <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Kegiatan
                        </Button>
                    )}
                >
                    {kegiatanList.length === 0 && (
                        <p className="text-sm text-muted-foreground">Belum ada kegiatan. Klik "Tambah Kegiatan" untuk menambah.</p>
                    )}

                    <div className="space-y-4">
                        {kegiatanList.map((kegiatan, kIdx) => (
                            <KegiatanCard
                                key={kIdx}
                                index={kIdx}
                                kegiatan={kegiatan}
                                isReadonly={isReadonly}
                                kodeBidang={kodeBidang}
                                kodeSubBidang={kodeSubBidang}
                                kodeJenis={kodeJenis}
                                kuisionerTemplates={kuisionerTemplates}
                                errors={errors}
                                onUpdate={(updates) => updateKegiatan(kIdx, updates)}
                                onRemove={() => removeKegiatan(kIdx)}
                                onDuplicate={() => duplicateKegiatan(kIdx)}
                                onOpenTemplatePicker={() => setTemplatePickerIdx(kIdx)}
                            />
                        ))}
                    </div>
                </SectionCard>

                {/* Actions */}
                {((!isReadonly && (canDraft || canSubmit)) || canTerminate) && (
                    <div className="flex gap-2">
                        {!isReadonly && canDraft && (
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
                        {!isReadonly && canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing || isPp07Active}>
                                        {processing ? 'Mengirim...' : 'Submit'}
                                    </Button>
                                }
                                title="Submit PK01"
                                description="Data akan divalidasi dan dikunci. PK02A (Approval Narasi) dan PK02B (Approval Anggaran) akan diaktifkan."
                                confirmLabel="Submit"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
                        )}
                        {canTerminate && (
                            <TerminateButton basePath={basePath} />
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
// StepStatusBadge
// ────────────────────────────────────────────────────────────

function StepStatusBadge({ mode }: { mode: string }) {
    const config: Record<string, { label: string; className: string }> = {
        readonly: { label: 'Sudah Disubmit', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
        edit: { label: 'Draft', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
    };
    const c = config[mode] ?? { label: 'Menunggu Diisi', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' };
    return <Badge className={c.className}>{c.label}</Badge>;
}

// ────────────────────────────────────────────────────────────
// Budget Counter Section
// ────────────────────────────────────────────────────────────

function BudgetCounterSection({ counter, thisPkTotal, isOverBudget }: { counter: BudgetCounter; thisPkTotal: number; isOverBudget: boolean }) {
    return (
        <SectionCard title="Referensi Anggaran">
            {counter.ppLabel && (
                <p className="mb-3 text-sm text-muted-foreground">Menggunakan data dari {counter.ppLabel}</p>
            )}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <BudgetItem label="Plafon Tim" value={counter.plafon} />
                <BudgetItem label="Sudah Ditetapkan" value={counter.accepted} />
                <BudgetItem label="Sedang Diajukan" value={counter.planned} />
                <BudgetItem label="Sisa" value={counter.sisa} />
            </div>
            <div className="mt-3 flex items-center gap-2 border-t pt-3">
                <span className="text-sm font-medium">PK Ini:</span>
                <span className={`text-sm font-semibold ${isOverBudget ? 'text-amber-600 dark:text-amber-400' : ''}`}>
                    Rp {formatRupiah(thisPkTotal)}
                </span>
            </div>
            {isOverBudget && (
                <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                    Total anggaran PK ini melebihi sisa plafon. Submit tetap diizinkan, namun akan diblokir saat kompilasi PK04.
                </p>
            )}
        </SectionCard>
    );
}

function BudgetItem({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md border px-3 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm font-semibold">Rp {formatRupiah(value)}</p>
        </div>
    );
}

// ────────────────────────────────────────────────────────────
// Rejection Banner
// ────────────────────────────────────────────────────────────

function RejectionBanner({ rejectionNotes }: { rejectionNotes: RejectionNotes }) {
    return (
        <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
            <p className="font-medium">Ditolak oleh {rejectionNotes.step}: {rejectionNotes.step_label}</p>
            {rejectionNotes.by_name && (
                <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                    Oleh: {rejectionNotes.by_name}{rejectionNotes.role_name ? ` (${rejectionNotes.role_name})` : ''}
                    {rejectionNotes.at && ` — ${new Date(rejectionNotes.at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}`}
                </p>
            )}
            {rejectionNotes.notes && (
                <div className="mt-2 rounded border border-amber-200 bg-amber-100/50 px-3 py-2 dark:border-amber-600 dark:bg-amber-900/30">
                    <p>{rejectionNotes.notes}</p>
                </div>
            )}
            {rejectionNotes.files.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {rejectionNotes.files.map((f, i) => (
                        <span key={i} className="inline-flex items-center gap-1 text-xs"><FileText className="h-3 w-3" />{f}</span>
                    ))}
                </div>
            )}

            {/* Parallel track notes */}
            {rejectionNotes.parallelTrackNotes && rejectionNotes.parallelTrackNotes.notes && (
                <div className="mt-3 border-t border-amber-200 pt-3 dark:border-amber-600">
                    <p className="text-xs font-medium text-amber-600 dark:text-amber-400">
                        Catatan dari {rejectionNotes.parallelTrackNotes.step_label}
                        {rejectionNotes.parallelTrackNotes.action === 'approved' ? ' (disetujui)' : ' (ditolak)'}
                    </p>
                    {rejectionNotes.parallelTrackNotes.by_name && (
                        <p className="mt-0.5 text-xs text-amber-500 dark:text-amber-500">
                            Oleh: {rejectionNotes.parallelTrackNotes.by_name}
                            {rejectionNotes.parallelTrackNotes.role_name ? ` (${rejectionNotes.parallelTrackNotes.role_name})` : ''}
                        </p>
                    )}
                    <p className="mt-1 text-sm opacity-80">{rejectionNotes.parallelTrackNotes.notes}</p>
                    {rejectionNotes.parallelTrackNotes.files.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-1">
                            {rejectionNotes.parallelTrackNotes.files.map((f, i) => (
                                <span key={i} className="inline-flex items-center gap-1 text-xs"><FileText className="h-3 w-3" />{f}</span>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ────────────────────────────────────────────────────────────
// Kegiatan Card
// ────────────────────────────────────────────────────────────

function KegiatanCard({
    index, kegiatan, isReadonly, kodeBidang, kodeSubBidang, kodeJenis,
    kuisionerTemplates, errors, onUpdate, onRemove, onDuplicate, onOpenTemplatePicker,
}: {
    index: number;
    kegiatan: Kegiatan;
    isReadonly: boolean;
    kodeBidang: KodeRef[];
    kodeSubBidang: KodeRef[];
    kodeJenis: KodeRef[];
    kuisionerTemplates: KuisionerTemplate[];
    errors: Record<string, string>;
    onUpdate: (updates: Partial<Kegiatan>) => void;
    onRemove: () => void;
    onDuplicate: () => void;
    onOpenTemplatePicker: () => void;
}) {
    const [collapsed, setCollapsed] = useState(false);
    const ep = `kegiatan.${index}`;

    const kegiatanTotal = kegiatan.anggaran.reduce((s, a) => s + (a.nominal_anggaran || 0), 0);

    // Anggaran helpers
    function updateAnggaran(aIdx: number, updates: Partial<AnggaranRow>) {
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
    function updateKuisioner(qIdx: number, updates: Partial<KuisionerRow>) {
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
                <span className="ml-auto text-xs text-muted-foreground">Rp {formatRupiah(kegiatanTotal)}</span>
                {!isReadonly && (
                    <>
                        <Button variant="ghost" size="sm" onClick={onDuplicate} title="Duplikat Kegiatan" className="h-7 w-7 p-0">
                            <Copy className="h-3.5 w-3.5" />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={onRemove} title="Hapus Kegiatan" className="h-7 w-7 p-0">
                            <Trash2 className="h-3.5 w-3.5 text-destructive" />
                        </Button>
                    </>
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
                            <Label>Bulan <span className="text-destructive">*</span></Label>
                            <Select
                                value={kegiatan.bulan === '' ? '' : String(kegiatan.bulan)}
                                onValueChange={(v) => onUpdate({ bulan: v === '' ? '' : Number(v) })}
                                disabled={isReadonly}
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
                            <h4 className="text-sm font-medium">Anggaran</h4>
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
                                        <th className="px-2 py-1.5 text-left font-medium w-36">Bidang <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium w-36">Sub Bidang <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium w-36">Jenis <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-32">Mata Anggaran <span className="text-destructive">*</span></th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-32">Deskripsi</th>
                                        <th className="px-2 py-1.5 text-left font-medium w-40">Nominal (Rp) <span className="text-destructive">*</span></th>
                                        {!isReadonly && <th className="px-2 py-1.5 w-10" />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {kegiatan.anggaran.map((a, aIdx) => (
                                        <tr key={aIdx} className="border-b last:border-0">
                                            <td className="px-2 py-1">
                                                <Select value={a.kode_bidang} onValueChange={(v) => updateAnggaran(aIdx, { kode_bidang: v })} disabled={isReadonly}>
                                                    <SelectTrigger className="h-8 text-xs">
                                                        <SelectValue placeholder="Pilih..." />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {kodeBidang.map((k) => (
                                                            <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {errors[`${ep}.anggaran.${aIdx}.kode_bidang`] && <p className="text-xs text-destructive">{errors[`${ep}.anggaran.${aIdx}.kode_bidang`]}</p>}
                                            </td>
                                            <td className="px-2 py-1">
                                                <Select value={a.kode_sub_bidang} onValueChange={(v) => updateAnggaran(aIdx, { kode_sub_bidang: v })} disabled={isReadonly}>
                                                    <SelectTrigger className="h-8 text-xs">
                                                        <SelectValue placeholder="Pilih..." />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {kodeSubBidang.map((k) => (
                                                            <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {errors[`${ep}.anggaran.${aIdx}.kode_sub_bidang`] && <p className="text-xs text-destructive">{errors[`${ep}.anggaran.${aIdx}.kode_sub_bidang`]}</p>}
                                            </td>
                                            <td className="px-2 py-1">
                                                <Select value={a.kode_jenis} onValueChange={(v) => updateAnggaran(aIdx, { kode_jenis: v })} disabled={isReadonly}>
                                                    <SelectTrigger className="h-8 text-xs">
                                                        <SelectValue placeholder="Pilih..." />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {kodeJenis.map((k) => (
                                                            <SelectItem key={k.kode} value={k.kode}>{k.kode} - {k.nama}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {errors[`${ep}.anggaran.${aIdx}.kode_jenis`] && <p className="text-xs text-destructive">{errors[`${ep}.anggaran.${aIdx}.kode_jenis`]}</p>}
                                            </td>
                                            <td className="px-2 py-1">
                                                <Input className="h-8 text-xs" value={a.mata_anggaran} onChange={(e) => updateAnggaran(aIdx, { mata_anggaran: e.target.value })} disabled={isReadonly} maxLength={255} />
                                                {errors[`${ep}.anggaran.${aIdx}.mata_anggaran`] && <p className="text-xs text-destructive">{errors[`${ep}.anggaran.${aIdx}.mata_anggaran`]}</p>}
                                            </td>
                                            <td className="px-2 py-1">
                                                <Input className="h-8 text-xs" value={a.deskripsi_pk} onChange={(e) => updateAnggaran(aIdx, { deskripsi_pk: e.target.value })} disabled={isReadonly} />
                                            </td>
                                            <td className="px-2 py-1">
                                                <RupiahInput value={a.nominal_anggaran} onChange={(v) => updateAnggaran(aIdx, { nominal_anggaran: v })} disabled={isReadonly} className="h-8 text-xs" />
                                                {errors[`${ep}.anggaran.${aIdx}.nominal_anggaran`] && <p className="text-xs text-destructive">{errors[`${ep}.anggaran.${aIdx}.nominal_anggaran`]}</p>}
                                            </td>
                                            {!isReadonly && (
                                                <td className="px-2 py-1">
                                                    <Button variant="ghost" size="sm" onClick={() => removeAnggaran(aIdx)} className="h-7 w-7 p-0">
                                                        <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Kuisioner sub-table */}
                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h4 className="text-sm font-medium">Kuisioner</h4>
                            {!isReadonly && (
                                <div className="flex gap-1">
                                    {kuisionerTemplates.length > 0 && (
                                        <Button variant="outline" size="sm" onClick={onOpenTemplatePicker}>
                                            <FileText className="mr-1 h-3.5 w-3.5" /> Tambah dari Template
                                        </Button>
                                    )}
                                    <Button variant="outline" size="sm" onClick={addKuisioner}>
                                        <Plus className="mr-1 h-3.5 w-3.5" /> Tambah Kuisioner
                                    </Button>
                                </div>
                            )}
                        </div>
                        {kegiatan.kuisioner.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="min-w-[700px] w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="px-2 py-1.5 text-left font-medium min-w-48">Pertanyaan <span className="text-destructive">*</span></th>
                                            <th className="px-2 py-1.5 text-left font-medium w-32">Tipe <span className="text-destructive">*</span></th>
                                            <th className="px-2 py-1.5 text-left font-medium w-28">Satuan</th>
                                            <th className="px-2 py-1.5 text-left font-medium w-28">Sumber</th>
                                            {!isReadonly && <th className="px-2 py-1.5 w-10" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.kuisioner.map((q, qIdx) => (
                                            <tr key={qIdx} className="border-b last:border-0">
                                                <td className="px-2 py-1">
                                                    <Input className="h-8 text-xs" value={q.pertanyaan} onChange={(e) => updateKuisioner(qIdx, { pertanyaan: e.target.value })} disabled={isReadonly} maxLength={255} />
                                                    {errors[`${ep}.kuisioner.${qIdx}.pertanyaan`] && <p className="text-xs text-destructive">{errors[`${ep}.kuisioner.${qIdx}.pertanyaan`]}</p>}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <Input className="h-8 text-xs" value={q.tipe} onChange={(e) => updateKuisioner(qIdx, { tipe: e.target.value })} disabled={isReadonly} maxLength={50} />
                                                    {errors[`${ep}.kuisioner.${qIdx}.tipe`] && <p className="text-xs text-destructive">{errors[`${ep}.kuisioner.${qIdx}.tipe`]}</p>}
                                                </td>
                                                <td className="px-2 py-1">
                                                    <Input className="h-8 text-xs" value={q.satuan} onChange={(e) => updateKuisioner(qIdx, { satuan: e.target.value })} disabled={isReadonly} maxLength={100} />
                                                </td>
                                                <td className="px-2 py-1">
                                                    {q.kode_kuisioner
                                                        ? <Badge className="bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 text-xs">Template PP</Badge>
                                                        : <Badge variant="outline" className="text-xs">Custom</Badge>
                                                    }
                                                </td>
                                                {!isReadonly && (
                                                    <td className="px-2 py-1">
                                                        <Button variant="ghost" size="sm" onClick={() => removeKuisioner(qIdx)} className="h-7 w-7 p-0">
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
                        {kegiatan.kuisioner.length === 0 && (
                            <p className="text-xs text-muted-foreground">Belum ada kuisioner untuk kegiatan ini (opsional).</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

// ────────────────────────────────────────────────────────────
// Terminate Button
// ────────────────────────────────────────────────────────────

function TerminateButton({ basePath }: { basePath: string }) {
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
                router.post(`${basePath}/terminate`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

// ────────────────────────────────────────────────────────────
// Kuisioner Template Picker (Modal)
// ────────────────────────────────────────────────────────────

function KuisionerTemplatePicker({
    templates, existingKodes, onConfirm, onClose,
}: {
    templates: KuisionerTemplate[];
    existingKodes: Set<string>;
    onConfirm: (selected: KuisionerTemplate[]) => void;
    onClose: () => void;
}) {
    const [selected, setSelected] = useState<Set<string>>(new Set());

    function toggle(kode: string) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(kode)) {
                next.delete(kode);
            } else {
                next.add(kode);
            }
            return next;
        });
    }

    function handleConfirm() {
        const items = templates.filter((t) => selected.has(t.kode));
        onConfirm(items);
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
                                    <input
                                        type="checkbox"
                                        checked={selected.has(t.kode)}
                                        onChange={() => toggle(t.kode)}
                                        className="mt-0.5"
                                    />
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
                    <Button size="sm" onClick={handleConfirm} disabled={selected.size === 0}>
                        Tambahkan ({selected.size})
                    </Button>
                </div>
            </div>
        </div>
    );
}

// ────────────────────────────────────────────────────────────
// Empty row factories
// ────────────────────────────────────────────────────────────

function emptyAnggaran(): AnggaranRow {
    return { kode_bidang: '', kode_sub_bidang: '', kode_jenis: '', mata_anggaran: '', deskripsi_pk: '', nominal_anggaran: 0 };
}

function emptyKuisioner(): KuisionerRow {
    return { kode_kuisioner: null, pertanyaan: '', tipe: '', satuan: '' };
}

function emptyKegiatan(): Kegiatan {
    return { nama_kegiatan: '', bulan: '', anggaran: [emptyAnggaran()], kuisioner: [] };
}
