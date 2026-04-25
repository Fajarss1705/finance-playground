import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ChevronDown, ChevronRight, CheckCircle2, FileText, Info, Upload, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { RupiahInput } from '@/components/ui/rupiah-input';
import { Textarea } from '@/components/ui/textarea';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type FotoItem = {
    id: number;
    file_id: number;
    original_filename: string;
    thumbnail_url: string | null;
    download_url: string | null;
};

type NotaItem = {
    id: number;
    file_id: number;
    original_filename: string;
    mime_type: string;
    download_url: string | null;
};

type KuisionerItem = {
    prbl01_item_kuisioner_id: number;
    pk04_kuisioner_id: number;
    pertanyaan: string;
    tipe: string;
    satuan: string | null;
    jawaban: string | null;
};

type RealisasiItem = {
    prbl01_item_realisasi_id: number | null;
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    kode_anggaran_lama: string | null;
    mata_anggaran: string;
    nominal_anggaran: number;
    nominal_realisasi: number;
    komentar_realisasi: string | null;
};

type KegiatanItem = {
    prbl01_item_kegiatan_id: number;
    pk04_kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    masalah: string | null;
    langkah_penanganan: string | null;
    harapan: string | null;
    catatan_tim: string | null;
    fotos: FotoItem[];
    nota: NotaItem[];
    kuisioner: KuisionerItem[];
    realisasi: RealisasiItem[];
};

type ProgramGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    kegiatan: KegiatanItem[];
};

type CycleBackNotes = {
    source: 'prbl02' | 'prbl04';
    prbl02a?: {
        status: string;
        by_name: string | null;
        role_name: string | null;
        team_name: string | null;
        at: string | null;
        notes: string | null;
        files: number[] | null;
    } | null;
    prbl02b?: {
        status: string;
        by_name: string | null;
        role_name: string | null;
        team_name: string | null;
        at: string | null;
        notes: string | null;
        files: number[] | null;
    } | null;
    prbl04?: {
        step: string;
        step_label: string;
        by_name: string | null;
        role_name: string | null;
        team_name: string | null;
        at: string | null;
        notes: string | null;
        files: number[] | null;
    } | null;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    stepper_cycles: unknown[];
};

type WorkflowMeta = {
    bulan_laporan: number;
    bulan_label: string;
    tahun_laporan: number;
    team_name: string;
    auto_created_at: string;
};

type Props = {
    workflow: Workflow;
    prbl01: { id: number; updated_at: string };
    kegiatanItems: ProgramGroup[];
    totalDicairkan: number;
    totalRealisasi: number;
    ppLabel: string | null;
    pabdLabel: string | null;
    workflowMeta: WorkflowMeta;
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canComment: boolean;
    isReentry: boolean;
    cycleBackNotes: CycleBackNotes | null;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    scope: 'team' | 'admin';
    basePath: string;
};

// ─── Status badges ───────────────────────────────────

function StepStatusBadge({ mode, scope }: { mode: string; scope: string }) {
    if (mode === 'readonly' && scope === 'admin') {
        return <Badge className="bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">Admin View</Badge>;
    }
    if (mode === 'readonly') {
        return <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Sudah Disubmit</Badge>;
    }
    if (mode === 'edit') {
        return <Badge className="bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300">Draft</Badge>;
    }
    return <Badge className="bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">Menunggu Diisi</Badge>;
}

// ─── Main component ──────────────────────────────────

export default function Prbl01({
    workflow,
    prbl01,
    kegiatanItems,
    totalDicairkan,
    ppLabel,
    pabdLabel,
    workflowMeta,
    mode,
    canDraft,
    canSubmit,
    canComment,
    isReentry,
    cycleBackNotes,
    actionRoles,
    activeRoleName,
    scope,
    basePath,
}: Props) {
    const [processing, setProcessing] = useState(false);
    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);

    // ─── Local form state ───────────────────────────────

    // Narrative fields per kegiatan (keyed by prbl01_item_kegiatan_id)
    const [narratives, setNarratives] = useState<Record<number, { masalah: string; langkah_penanganan: string; harapan: string; catatan_tim: string }>>(() => {
        const map: Record<number, { masalah: string; langkah_penanganan: string; harapan: string; catatan_tim: string }> = {};
        for (const program of kegiatanItems) {
            for (const k of program.kegiatan) {
                map[k.prbl01_item_kegiatan_id] = {
                    masalah: k.masalah ?? '',
                    langkah_penanganan: k.langkah_penanganan ?? '',
                    harapan: k.harapan ?? '',
                    catatan_tim: k.catatan_tim ?? '',
                };
            }
        }
        return map;
    });

    // Kuisioner answers (keyed by prbl01_item_kuisioner_id)
    const [kuisionerAnswers, setKuisionerAnswers] = useState<Record<number, string>>(() => {
        const map: Record<number, string> = {};
        for (const program of kegiatanItems) {
            for (const k of program.kegiatan) {
                for (const q of k.kuisioner) {
                    map[q.prbl01_item_kuisioner_id] = q.jawaban ?? '';
                }
            }
        }
        return map;
    });

    // Realisasi amounts (keyed by prbl01_item_realisasi_id)
    const [realisasiMap, setRealisasiMap] = useState<Record<number, { nominal: number; komentar: string }>>(() => {
        const map: Record<number, { nominal: number; komentar: string }> = {};
        for (const program of kegiatanItems) {
            for (const k of program.kegiatan) {
                for (const r of k.realisasi) {
                    if (r.prbl01_item_realisasi_id) {
                        map[r.prbl01_item_realisasi_id] = {
                            nominal: r.nominal_realisasi,
                            komentar: r.komentar_realisasi ?? '',
                        };
                    }
                }
            }
        }
        return map;
    });

    // Foto/nota staged changes (applied on draft/submit, mirrors PRBL03 pattern)
    const [newFotoFiles, setNewFotoFiles] = useState<Record<number, File[]>>({});
    const [removeFotoIds, setRemoveFotoIds] = useState<number[]>([]);
    const [newNotaFiles, setNewNotaFiles] = useState<Record<number, File[]>>({});
    const [removeNotaIds, setRemoveNotaIds] = useState<number[]>([]);

    // Collapsed sections (keyed by prbl01_item_kegiatan_id)
    const [collapsed, setCollapsed] = useState<Record<number, boolean>>(() => {
        const map: Record<number, boolean> = {};
        for (const program of kegiatanItems) {
            for (const k of program.kegiatan) {
                map[k.prbl01_item_kegiatan_id] = false;
            }
        }
        return map;
    });

    // ─── Computed totals ────────────────────────────────

    const summary = useMemo(() => {
        let totalRealisasi = 0;
        let realisasiCount = 0;
        let dicairkanCount = 0;

        for (const program of kegiatanItems) {
            for (const k of program.kegiatan) {
                for (const r of k.realisasi) {
                    dicairkanCount++;
                    if (r.prbl01_item_realisasi_id) {
                        totalRealisasi += realisasiMap[r.prbl01_item_realisasi_id]?.nominal ?? 0;
                        realisasiCount++;
                    }
                }
            }
        }

        const selisih = totalDicairkan - totalRealisasi;
        const overBudget = totalRealisasi > totalDicairkan;

        return { totalRealisasi, realisasiCount, dicairkanCount, selisih, overBudget };
    }, [kegiatanItems, realisasiMap, totalDicairkan]);

    // ─── File upload handlers (staged — applied on draft/submit) ─

    const addFotoFiles = useCallback((kegiatanId: number, files: FileList | null) => {
        if (!files || files.length === 0) return;
        setNewFotoFiles((prev) => ({
            ...prev,
            [kegiatanId]: [...(prev[kegiatanId] ?? []), ...Array.from(files)],
        }));
    }, []);

    const removeNewFoto = useCallback((kegiatanId: number, index: number) => {
        setNewFotoFiles((prev) => ({
            ...prev,
            [kegiatanId]: (prev[kegiatanId] ?? []).filter((_, i) => i !== index),
        }));
    }, []);

    const markFotoForRemoval = useCallback((fotoId: number) => {
        setRemoveFotoIds((prev) => (prev.includes(fotoId) ? prev : [...prev, fotoId]));
    }, []);

    const addNotaFiles = useCallback((kegiatanId: number, files: FileList | null) => {
        if (!files || files.length === 0) return;
        setNewNotaFiles((prev) => ({
            ...prev,
            [kegiatanId]: [...(prev[kegiatanId] ?? []), ...Array.from(files)],
        }));
    }, []);

    const removeNewNota = useCallback((kegiatanId: number, index: number) => {
        setNewNotaFiles((prev) => ({
            ...prev,
            [kegiatanId]: (prev[kegiatanId] ?? []).filter((_, i) => i !== index),
        }));
    }, []);

    const markNotaForRemoval = useCallback((notaId: number) => {
        setRemoveNotaIds((prev) => (prev.includes(notaId) ? prev : [...prev, notaId]));
    }, []);

    // ─── Form submission ────────────────────────────────

    function buildFormData(notes: string, files: File[]): FormData {
        const formData = new FormData();

        let itemIdx = 0;
        for (const program of kegiatanItems) {
            for (const k of program.kegiatan) {
                const kId = k.prbl01_item_kegiatan_id;
                const narr = narratives[kId] ?? {};
                formData.append(`items[${itemIdx}][prbl01_item_kegiatan_id]`, String(kId));
                if (narr.masalah) formData.append(`items[${itemIdx}][masalah]`, narr.masalah);
                if (narr.langkah_penanganan) formData.append(`items[${itemIdx}][langkah_penanganan]`, narr.langkah_penanganan);
                if (narr.harapan) formData.append(`items[${itemIdx}][harapan]`, narr.harapan);
                if (narr.catatan_tim) formData.append(`items[${itemIdx}][catatan_tim]`, narr.catatan_tim);

                k.kuisioner.forEach((q, qIdx) => {
                    formData.append(`items[${itemIdx}][kuisioner][${qIdx}][prbl01_item_kuisioner_id]`, String(q.prbl01_item_kuisioner_id));
                    const jawaban = kuisionerAnswers[q.prbl01_item_kuisioner_id];
                    if (jawaban) formData.append(`items[${itemIdx}][kuisioner][${qIdx}][jawaban]`, jawaban);
                });
                itemIdx++;
            }
        }

        let realIdx = 0;
        for (const program of kegiatanItems) {
            for (const k of program.kegiatan) {
                for (const r of k.realisasi) {
                    if (r.prbl01_item_realisasi_id) {
                        const val = realisasiMap[r.prbl01_item_realisasi_id];
                        formData.append(`realisasi[${realIdx}][prbl01_item_realisasi_id]`, String(r.prbl01_item_realisasi_id));
                        formData.append(`realisasi[${realIdx}][nominal_realisasi]`, String(val?.nominal ?? 0));
                        if (val?.komentar) formData.append(`realisasi[${realIdx}][komentar_realisasi]`, val.komentar);
                        realIdx++;
                    }
                }
            }
        }

        formData.append('expected_updated_at', prbl01.updated_at);
        if (notes) formData.append('notes', notes);
        files.forEach((file) => formData.append('files[]', file));

        // Staged foto/nota uploads, grouped by kegiatan id
        for (const [kId, fileList] of Object.entries(newFotoFiles)) {
            fileList.forEach((file) => formData.append(`foto_files[${kId}][]`, file));
        }
        for (const [kId, fileList] of Object.entries(newNotaFiles)) {
            fileList.forEach((file) => formData.append(`nota_files[${kId}][]`, file));
        }
        removeFotoIds.forEach((id) => formData.append('remove_foto_ids[]', String(id)));
        removeNotaIds.forEach((id) => formData.append('remove_nota_ids[]', String(id)));

        return formData;
    }

    function handleAction(action: 'draft' | 'submit', notes: string, files: File[]) {
        setProcessing(true);
        const url = `${basePath}/prbl01/${prbl01.id}/${action}`;
        router.post(url, buildFormData(notes, files), {
            forceFormData: true,
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                setNewFotoFiles({});
                setRemoveFotoIds([]);
                setNewNotaFiles({});
                setRemoveNotaIds([]);
            },
        });
    }

    // ─── Breadcrumbs ────────────────────────────────────

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'team' ? 'Tim' : 'Admin', href: `/${scope}` },
        { title: 'Laporan Bulanan', href: `/${scope}/workflows/prbl` },
        { title: workflow.label, href: basePath },
        { title: 'PRBL01: Laporan Kegiatan', href: '#' },
    ];

    const hasNoItems = kegiatanItems.length === 0 || kegiatanItems.every((p) => p.kegiatan.length === 0);

    // Submit is blocked if any kegiatan would end up without foto or nota
    function visibleFotoCount(k: KegiatanItem): number {
        const existing = k.fotos.filter((f) => !removeFotoIds.includes(f.id)).length;
        const staged = (newFotoFiles[k.prbl01_item_kegiatan_id] ?? []).length;
        return existing + staged;
    }
    function visibleNotaCount(k: KegiatanItem): number {
        const existing = k.nota.filter((n) => !removeNotaIds.includes(n.id)).length;
        const staged = (newNotaFiles[k.prbl01_item_kegiatan_id] ?? []).length;
        return existing + staged;
    }

    const missingFoto = !hasNoItems && kegiatanItems.some((p) => p.kegiatan.some((k) => visibleFotoCount(k) === 0));
    const missingNota = !hasNoItems && kegiatanItems.some((p) => p.kegiatan.some((k) => visibleNotaCount(k) === 0));
    const hasFilesError = missingFoto || missingNota;

    // Kegiatan counter
    let kegiatanIndex = 0;
    let totalKegiatan = 0;
    for (const program of kegiatanItems) {
        totalKegiatan += program.kegiatan.length;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PRBL01: Laporan Kegiatan — ${workflowMeta.team_name}`} />
            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <Heading title="PRBL01: Laporan Kegiatan & Realisasi Anggaran" />
                            <StepStatusBadge mode={mode} scope={scope} />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {workflowMeta.bulan_label} {workflowMeta.tahun_laporan} — {workflowMeta.team_name}
                        </p>
                    </div>
                </div>

                {/* Cycle-back Banner */}
                {isReentry && cycleBackNotes && (
                    <CycleBackBanner cycleBackNotes={cycleBackNotes} />
                )}

                {/* Readonly banners */}
                {mode === 'readonly' && scope !== 'admin' && (
                    <div className="flex gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
                        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-green-600" />
                        <p className="text-sm text-green-800 dark:text-green-200">
                            Step ini sudah selesai disubmit. Data hanya dapat dilihat.
                        </p>
                    </div>
                )}

                {isReadonly && scope === 'admin' && (
                    <div className="flex gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
                        <Info className="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                        <p className="text-sm text-blue-800 dark:text-blue-200">
                            Anda hanya dapat melihat data pada step ini.
                        </p>
                    </div>
                )}

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                {/* History & Comments */}
                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`${basePath}/comment`}
                    commentSource="prbl01"
                    canComment={canComment}
                    finalSteps={['PRBL05']}
                    stepUrlResolver={(entry: HistoryEntry) => {
                        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
                        const step = entry.step;
                        if (step === 'PRBL01' && entry.id) return `${basePath}/prbl01/${entry.id}`;
                        if (step === 'PRBL02A') return `${basePath}/prbl02a`;
                        if (step === 'PRBL02B') return `${basePath}/prbl02b`;
                        if (step === 'PRBL03' && entry.id) return `${basePath}/prbl03/${entry.id}`;
                        if (step === 'PRBL04') return `${basePath}/prbl04`;
                        if (step === 'PRBL05') return `${basePath}/prbl05`;
                        return null;
                    }}
                />

                {/* Info Laporan */}
                <SectionCard title="Info Laporan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Tim</dt>
                            <dd className="font-medium">{workflowMeta.team_name}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Bulan Laporan</dt>
                            <dd className="font-medium">{workflowMeta.bulan_label} {workflowMeta.tahun_laporan}</dd>
                        </div>
                        {ppLabel && (
                            <div>
                                <dt className="text-muted-foreground">Referensi PP</dt>
                                <dd className="font-medium">{ppLabel}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-muted-foreground">Dibuat Otomatis</dt>
                            <dd className="font-medium">{workflowMeta.auto_created_at}</dd>
                        </div>
                        {pabdLabel && (
                            <div>
                                <dt className="text-muted-foreground">Referensi PABD</dt>
                                <dd className="font-medium">{pabdLabel}</dd>
                            </div>
                        )}
                    </dl>
                </SectionCard>

                {/* Empty state */}
                {hasNoItems && (
                    <SectionCard title="Kegiatan">
                        <p className="text-sm text-muted-foreground">
                            Tidak ada kegiatan dengan anggaran dicairkan untuk bulan ini.
                        </p>
                    </SectionCard>
                )}

                {/* Per-kegiatan sections */}
                {kegiatanItems.flatMap((program) =>
                    program.kegiatan.map((kegiatan) => {
                        kegiatanIndex++;
                        const kId = kegiatan.prbl01_item_kegiatan_id;
                        const isCollapsed = collapsed[kId] ?? false;
                        const visibleFotos = kegiatan.fotos.filter((f) => !removeFotoIds.includes(f.id));
                        const visibleNotaExisting = kegiatan.nota.filter((n) => !removeNotaIds.includes(n.id));
                        const stagedFotos = newFotoFiles[kId] ?? [];
                        const stagedNota = newNotaFiles[kId] ?? [];
                        const totalFotoCount = visibleFotos.length + stagedFotos.length;
                        const totalNotaCount = visibleNotaExisting.length + stagedNota.length;

                        // Per-kegiatan subtotals
                        let subDicairkan = 0;
                        let subRealisasi = 0;
                        for (const r of kegiatan.realisasi) {
                            subDicairkan += r.nominal_anggaran;
                            if (r.prbl01_item_realisasi_id) {
                                subRealisasi += realisasiMap[r.prbl01_item_realisasi_id]?.nominal ?? 0;
                            }
                        }

                        return (
                            <SectionCard
                                key={kId}
                                title={`Kegiatan ${kegiatanIndex} / ${totalKegiatan}`}
                                headerRight={
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setCollapsed((prev) => ({ ...prev, [kId]: !isCollapsed }))}
                                    >
                                        {isCollapsed ? <ChevronRight className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                                    </Button>
                                }
                            >
                                {/* Kegiatan header — always visible */}
                                <div className="mb-3 text-sm">
                                    <p className="font-semibold">
                                        {program.program_name}{' '}
                                        <span className="text-muted-foreground">({program.kode_kategori})</span>
                                    </p>
                                    <p className="text-muted-foreground">
                                        {kegiatan.nama_kegiatan} — {kegiatan.bulan_label}
                                    </p>
                                </div>

                                {!isCollapsed && (
                                    <div className="space-y-5">
                                        {/* Narrative fields */}
                                        <div className="space-y-3">
                                            <div>
                                                <label className="mb-1 block text-sm font-medium">Masalah/Kendala</label>
                                                {isReadonly ? (
                                                    <p className="whitespace-pre-line text-sm">{narratives[kId]?.masalah || <span className="text-muted-foreground">—</span>}</p>
                                                ) : (
                                                    <Textarea
                                                        value={narratives[kId]?.masalah ?? ''}
                                                        onChange={(e) => setNarratives((prev) => ({ ...prev, [kId]: { ...prev[kId], masalah: e.target.value } }))}
                                                        placeholder="Jelaskan masalah/kendala yang dihadapi..."
                                                        rows={2}
                                                    />
                                                )}
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-sm font-medium">Langkah Penanganan</label>
                                                {isReadonly ? (
                                                    <p className="whitespace-pre-line text-sm">{narratives[kId]?.langkah_penanganan || <span className="text-muted-foreground">—</span>}</p>
                                                ) : (
                                                    <Textarea
                                                        value={narratives[kId]?.langkah_penanganan ?? ''}
                                                        onChange={(e) => setNarratives((prev) => ({ ...prev, [kId]: { ...prev[kId], langkah_penanganan: e.target.value } }))}
                                                        placeholder="Jelaskan langkah penanganan yang dilakukan..."
                                                        rows={2}
                                                    />
                                                )}
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-sm font-medium">Harapan</label>
                                                {isReadonly ? (
                                                    <p className="whitespace-pre-line text-sm">{narratives[kId]?.harapan || <span className="text-muted-foreground">—</span>}</p>
                                                ) : (
                                                    <Textarea
                                                        value={narratives[kId]?.harapan ?? ''}
                                                        onChange={(e) => setNarratives((prev) => ({ ...prev, [kId]: { ...prev[kId], harapan: e.target.value } }))}
                                                        placeholder="Jelaskan harapan ke depan..."
                                                        rows={2}
                                                    />
                                                )}
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-sm font-medium">Catatan Tim</label>
                                                {isReadonly ? (
                                                    <p className="whitespace-pre-line text-sm">{narratives[kId]?.catatan_tim || <span className="text-muted-foreground">—</span>}</p>
                                                ) : (
                                                    <Textarea
                                                        value={narratives[kId]?.catatan_tim ?? ''}
                                                        onChange={(e) => setNarratives((prev) => ({ ...prev, [kId]: { ...prev[kId], catatan_tim: e.target.value } }))}
                                                        placeholder="Catatan tambahan dari tim..."
                                                        rows={2}
                                                    />
                                                )}
                                            </div>
                                        </div>

                                        {/* Foto Kegiatan */}
                                        <div>
                                            <h5 className="mb-2 text-sm font-semibold">
                                                Foto Kegiatan <span className="text-red-500">*</span>
                                            </h5>
                                            <p className="text-xs text-muted-foreground">
                                                Upload foto kegiatan (minimal 1). Format: JPG, PNG, WEBP — Maks 50MB/file.
                                            </p>
                                            <div className="mt-2 space-y-2">
                                                {visibleFotos.map((foto) => (
                                                    <div key={`existing-${foto.id}`} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                                        {foto.thumbnail_url ? (
                                                            <a href={foto.thumbnail_url} target="_blank" rel="noopener noreferrer" className="shrink-0" title="Lihat foto">
                                                                <img src={foto.thumbnail_url} alt={foto.original_filename} className="h-10 w-10 rounded border object-cover" />
                                                            </a>
                                                        ) : (
                                                            <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                        )}
                                                        {foto.download_url ? (
                                                            <a href={foto.download_url} className="flex-1 truncate text-blue-600 hover:underline dark:text-blue-400" title="Unduh">
                                                                {foto.original_filename}
                                                            </a>
                                                        ) : (
                                                            <span className="flex-1 truncate">{foto.original_filename}</span>
                                                        )}
                                                        {!isReadonly && (
                                                            <button
                                                                type="button"
                                                                className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive"
                                                                onClick={() => markFotoForRemoval(foto.id)}
                                                            >
                                                                <X className="h-3.5 w-3.5" />
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}

                                                {stagedFotos.map((file, idx) => {
                                                    const previewUrl = URL.createObjectURL(file);
                                                    return (
                                                        <div key={`new-${idx}`} className="flex items-center gap-2 rounded-md border border-dashed border-blue-300 bg-blue-50/50 px-3 py-2 text-sm dark:border-blue-700 dark:bg-blue-950/20">
                                                            <a href={previewUrl} target="_blank" rel="noopener noreferrer" className="shrink-0" title="Pratinjau">
                                                                <img src={previewUrl} alt={file.name} className="h-10 w-10 rounded border object-cover" />
                                                            </a>
                                                            <a href={previewUrl} download={file.name} className="flex-1 truncate text-blue-600 hover:underline dark:text-blue-400" title="Unduh pratinjau">
                                                                {file.name}
                                                            </a>
                                                            <span className="shrink-0 text-xs text-muted-foreground">{(file.size / 1024).toFixed(0)} KB</span>
                                                            <button
                                                                type="button"
                                                                className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive"
                                                                onClick={() => removeNewFoto(kId, idx)}
                                                            >
                                                                <X className="h-3.5 w-3.5" />
                                                            </button>
                                                        </div>
                                                    );
                                                })}

                                                {!isReadonly && (
                                                    <label className="flex w-full cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-muted-foreground/25 px-6 py-6 text-center transition-colors hover:border-muted-foreground/50 hover:bg-muted/30">
                                                        <Upload className="h-6 w-6 text-muted-foreground" />
                                                        <p className="text-sm text-muted-foreground">Klik untuk memilih foto</p>
                                                        <input
                                                            type="file"
                                                            accept="image/jpeg,image/png,image/webp"
                                                            multiple
                                                            className="sr-only"
                                                            onChange={(e) => {
                                                                addFotoFiles(kId, e.target.files);
                                                                e.target.value = '';
                                                            }}
                                                        />
                                                    </label>
                                                )}

                                                {totalFotoCount === 0 && !isReadonly && (
                                                    <p className="text-xs text-red-500">Belum ada foto. Minimal 1 foto wajib diupload.</p>
                                                )}
                                            </div>
                                        </div>

                                        {/* Nota Pengeluaran */}
                                        <div>
                                            <h5 className="mb-2 text-sm font-semibold">
                                                Nota Pengeluaran <span className="text-red-500">*</span>
                                            </h5>
                                            <p className="text-xs text-muted-foreground">
                                                Bukti pengeluaran (kuitansi, invoice). Semua tipe file kecuali executable — Maks 25MB/file.
                                            </p>
                                            <div className="mt-2 space-y-2">
                                                {visibleNotaExisting.map((nota) => (
                                                    <div key={`existing-${nota.id}`} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                                        <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                        {nota.download_url ? (
                                                            <a href={nota.download_url} className="flex-1 truncate text-blue-600 hover:underline dark:text-blue-400" title="Unduh">
                                                                {nota.original_filename}
                                                            </a>
                                                        ) : (
                                                            <span className="flex-1 truncate">{nota.original_filename}</span>
                                                        )}
                                                        {!isReadonly && (
                                                            <button
                                                                type="button"
                                                                className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive"
                                                                onClick={() => markNotaForRemoval(nota.id)}
                                                            >
                                                                <X className="h-3.5 w-3.5" />
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}

                                                {stagedNota.map((file, idx) => {
                                                    const previewUrl = URL.createObjectURL(file);
                                                    return (
                                                        <div key={`new-${idx}`} className="flex items-center gap-2 rounded-md border border-dashed border-blue-300 bg-blue-50/50 px-3 py-2 text-sm dark:border-blue-700 dark:bg-blue-950/20">
                                                            <FileText className="h-4 w-4 shrink-0 text-blue-500" />
                                                            <a href={previewUrl} download={file.name} className="flex-1 truncate text-blue-600 hover:underline dark:text-blue-400" title="Unduh pratinjau">
                                                                {file.name}
                                                            </a>
                                                            <span className="shrink-0 text-xs text-muted-foreground">{(file.size / 1024).toFixed(0)} KB</span>
                                                            <button
                                                                type="button"
                                                                className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive"
                                                                onClick={() => removeNewNota(kId, idx)}
                                                            >
                                                                <X className="h-3.5 w-3.5" />
                                                            </button>
                                                        </div>
                                                    );
                                                })}

                                                {!isReadonly && (
                                                    <label className="flex w-full cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-muted-foreground/25 px-6 py-6 text-center transition-colors hover:border-muted-foreground/50 hover:bg-muted/30">
                                                        <Upload className="h-6 w-6 text-muted-foreground" />
                                                        <p className="text-sm text-muted-foreground">Klik untuk memilih file</p>
                                                        <input
                                                            type="file"
                                                            multiple
                                                            className="sr-only"
                                                            onChange={(e) => {
                                                                addNotaFiles(kId, e.target.files);
                                                                e.target.value = '';
                                                            }}
                                                        />
                                                    </label>
                                                )}

                                                {totalNotaCount === 0 && !isReadonly && (
                                                    <p className="text-xs text-red-500">Belum ada nota. Minimal 1 nota wajib diupload.</p>
                                                )}
                                            </div>
                                        </div>

                                        {/* Kuisioner */}
                                        {kegiatan.kuisioner.length > 0 && (
                                            <div>
                                                <h5 className="mb-2 text-sm font-semibold">Kuisioner</h5>
                                                <div className="overflow-x-auto">
                                                    <table className="w-full text-sm">
                                                        <thead>
                                                            <tr className="border-b bg-muted/50">
                                                                <th className="w-10 px-3 py-2 text-center font-medium">#</th>
                                                                <th className="px-3 py-2 text-left font-medium">Pertanyaan</th>
                                                                <th className="w-20 px-3 py-2 text-left font-medium">Tipe</th>
                                                                <th className="w-20 px-3 py-2 text-left font-medium">Satuan</th>
                                                                <th className="w-40 px-3 py-2 text-left font-medium">
                                                                    Jawaban <span className="text-red-500">*</span>
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {kegiatan.kuisioner.map((q, idx) => (
                                                                <tr key={q.prbl01_item_kuisioner_id} className="border-b last:border-0">
                                                                    <td className="px-3 py-2 text-center">{idx + 1}</td>
                                                                    <td className="px-3 py-2">{q.pertanyaan}</td>
                                                                    <td className="px-3 py-2 text-muted-foreground">{q.tipe}</td>
                                                                    <td className="px-3 py-2 text-muted-foreground">{q.satuan ?? '—'}</td>
                                                                    <td className="px-3 py-2">
                                                                        {isReadonly ? (
                                                                            <span>{kuisionerAnswers[q.prbl01_item_kuisioner_id] || '—'}</span>
                                                                        ) : (
                                                                            <Input
                                                                                value={kuisionerAnswers[q.prbl01_item_kuisioner_id] ?? ''}
                                                                                onChange={(e) =>
                                                                                    setKuisionerAnswers((prev) => ({
                                                                                        ...prev,
                                                                                        [q.prbl01_item_kuisioner_id]: e.target.value,
                                                                                    }))
                                                                                }
                                                                                className="h-8 text-sm border-red-200 focus:border-red-500 focus:ring-red-500"
                                                                                placeholder="Wajib diisi"
                                                                            />
                                                                        )}
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        )}

                                        {/* Realisasi Anggaran */}
                                        <div>
                                            <h5 className="mb-2 text-sm font-semibold">Realisasi Anggaran</h5>
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-sm">
                                                    <thead>
                                                        <tr className="border-b bg-muted/50">
                                                            <th className="px-3 py-2 text-left font-medium">Kode Anggaran Baru</th>
                                                            <th className="px-3 py-2 text-left font-medium">Mata Anggaran</th>
                                                            <th className="px-3 py-2 text-right font-medium">Dicairkan (Rp)</th>
                                                            <th className="w-40 px-3 py-2 text-right font-medium">
                                                                Realisasi (Rp) <span className="text-red-500">*</span>
                                                            </th>
                                                            <th className="w-40 px-3 py-2 text-left font-medium">Komentar</th>
                                                            <th className="px-3 py-2 text-left font-medium">Kode Anggaran Lama</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {kegiatan.realisasi.map((r) => {
                                                            const rId = r.prbl01_item_realisasi_id;
                                                            return (
                                                                <tr key={r.pk04_anggaran_id} className="border-b last:border-0">
                                                                    <td className="px-3 py-2">
                                                                        <KodeAnggaranFromString kode={r.kode_anggaran_baru} />
                                                                    </td>
                                                                    <td className="px-3 py-2">{r.mata_anggaran}</td>
                                                                    <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(r.nominal_anggaran)}</td>
                                                                    <td className="px-3 py-2 text-right">
                                                                        {isReadonly ? (
                                                                            <span className="tabular-nums">{formatRupiah(rId ? (realisasiMap[rId]?.nominal ?? 0) : 0)}</span>
                                                                        ) : rId ? (
                                                                            <RupiahInput
                                                                                value={realisasiMap[rId]?.nominal ?? 0}
                                                                                onChange={(v) =>
                                                                                    setRealisasiMap((prev) => ({
                                                                                        ...prev,
                                                                                        [rId]: { ...prev[rId], nominal: v },
                                                                                    }))
                                                                                }
                                                                                min={0}
                                                                                className="h-8 text-sm tabular-nums"
                                                                            />
                                                                        ) : (
                                                                            <span className="text-muted-foreground">—</span>
                                                                        )}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {isReadonly ? (
                                                                            <span className="text-muted-foreground">{rId ? (realisasiMap[rId]?.komentar || '—') : '—'}</span>
                                                                        ) : rId ? (
                                                                            <Input
                                                                                value={realisasiMap[rId]?.komentar ?? ''}
                                                                                onChange={(e) =>
                                                                                    setRealisasiMap((prev) => ({
                                                                                        ...prev,
                                                                                        [rId]: { ...prev[rId], komentar: e.target.value },
                                                                                    }))
                                                                                }
                                                                                className="h-8 text-sm"
                                                                                placeholder="Opsional"
                                                                            />
                                                                        ) : null}
                                                                    </td>
                                                                    <td className="px-3 py-2 font-mono text-xs text-muted-foreground">
                                                                        {r.kode_anggaran_lama ?? '—'}
                                                                    </td>
                                                                </tr>
                                                            );
                                                        })}
                                                    </tbody>
                                                    <tfoot>
                                                        <tr className="border-t bg-muted/30 font-medium">
                                                            <td colSpan={2} className="px-3 py-2 text-right">Subtotal</td>
                                                            <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(subDicairkan)}</td>
                                                            <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(subRealisasi)}</td>
                                                            <td className="px-3 py-2 text-sm text-muted-foreground">
                                                                Selisih: {formatRupiah(subDicairkan - subRealisasi)}
                                                            </td>
                                                            <td />
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </SectionCard>
                        );
                    }),
                )}

                {/* Ringkasan Realisasi */}
                <SectionCard title="Ringkasan Realisasi">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">Total Anggaran Dicairkan</dt>
                            <dd className="font-medium">
                                {formatRupiah(totalDicairkan)}{' '}
                                <span className="text-muted-foreground">({summary.dicairkanCount} item)</span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Total Realisasi</dt>
                            <dd className={`font-medium ${summary.overBudget ? 'text-red-600 dark:text-red-400' : ''}`}>
                                {formatRupiah(summary.totalRealisasi)}{' '}
                                <span className="text-muted-foreground">({summary.realisasiCount} item)</span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Selisih</dt>
                            <dd className="font-medium">
                                {formatRupiah(summary.selisih)}{' '}
                                {!summary.overBudget && summary.selisih > 0 && (
                                    <span className="text-muted-foreground">(akan di-refund)</span>
                                )}
                            </dd>
                        </div>
                    </dl>
                    {summary.overBudget && (
                        <p className="mt-2 text-sm font-medium text-red-600 dark:text-red-400">
                            Total realisasi melebihi total anggaran dicairkan.
                        </p>
                    )}
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canDraft || canSubmit) && (
                    <>
                        {hasFilesError && (
                            <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950">
                                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />
                                <div className="text-sm text-red-800 dark:text-red-200">
                                    <p className="font-medium">Tidak dapat submit:</p>
                                    <ul className="mt-1 list-disc pl-4">
                                        {missingFoto && <li>Setiap kegiatan wajib memiliki minimal 1 foto kegiatan</li>}
                                        {missingNota && <li>Setiap kegiatan wajib memiliki minimal 1 nota pengeluaran</li>}
                                    </ul>
                                </div>
                            </div>
                        )}
                        <div className="flex justify-end gap-3">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button variant="secondary" disabled={processing}>
                                        Simpan Draft
                                    </Button>
                                }
                                title="Simpan Draft"
                                description="Data laporan kegiatan dan realisasi akan disimpan sebagai draft."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing || hasNoItems || summary.overBudget || hasFilesError}>
                                        Submit
                                    </Button>
                                }
                                title="Submit Laporan Kegiatan"
                                description="Laporan kegiatan dan realisasi anggaran akan disubmit untuk review narasi (PRBL02A) dan anggaran (PRBL02B)."
                                confirmLabel="Submit"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
                        )}
                    </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

// ─── Cycle-back Banner ──────────────────────────────

function CycleBackBanner({ cycleBackNotes }: { cycleBackNotes: CycleBackNotes }) {
    if (cycleBackNotes.source === 'prbl04') {
        const info = cycleBackNotes.prbl04;
        return (
            <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                <div className="space-y-1 text-sm">
                    <p className="font-medium text-amber-800 dark:text-amber-200">
                        Laporan dikembalikan dari review final. Silakan perbaiki laporan kegiatan dan/atau realisasi anggaran.
                    </p>
                    {info && (
                        <>
                            <p className="text-amber-700 dark:text-amber-300">
                                Oleh: {info.by_name} ({info.role_name}{info.team_name ? ` \u00B7 ${info.team_name}` : ''}) — {formatDateTime(info.at)}
                            </p>
                            {info.notes && (
                                <p className="whitespace-pre-line text-amber-700 dark:text-amber-300">
                                    &ldquo;{info.notes}&rdquo;
                                </p>
                            )}
                        </>
                    )}
                </div>
            </div>
        );
    }

    // prbl02 compiled rejection
    const { prbl02a, prbl02b } = cycleBackNotes;
    return (
        <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div className="space-y-3 text-sm">
                <p className="font-medium text-amber-800 dark:text-amber-200">
                    Laporan dikembalikan untuk diperbaiki.
                </p>

                {prbl02a && (
                    <div className="border-t border-amber-200 pt-2 dark:border-amber-700">
                        <p className="font-medium text-amber-800 dark:text-amber-200">Review Narasi (PRBL02A)</p>
                        <p className="text-amber-700 dark:text-amber-300">
                            Status: {prbl02a.status}
                        </p>
                        <p className="text-amber-700 dark:text-amber-300">
                            Oleh: {prbl02a.by_name} ({prbl02a.role_name}{prbl02a.team_name ? ` \u00B7 ${prbl02a.team_name}` : ''}) — {formatDateTime(prbl02a.at)}
                        </p>
                        {prbl02a.notes && (
                            <p className="whitespace-pre-line text-amber-700 dark:text-amber-300">
                                &ldquo;{prbl02a.notes}&rdquo;
                            </p>
                        )}
                    </div>
                )}

                {prbl02b && (
                    <div className="border-t border-amber-200 pt-2 dark:border-amber-700">
                        <p className="font-medium text-amber-800 dark:text-amber-200">Review Anggaran (PRBL02B)</p>
                        <p className="text-amber-700 dark:text-amber-300">
                            Status: {prbl02b.status}
                        </p>
                        <p className="text-amber-700 dark:text-amber-300">
                            Oleh: {prbl02b.by_name} ({prbl02b.role_name}{prbl02b.team_name ? ` \u00B7 ${prbl02b.team_name}` : ''}) — {formatDateTime(prbl02b.at)}
                        </p>
                        {prbl02b.notes && (
                            <p className="whitespace-pre-line text-amber-700 dark:text-amber-300">
                                &ldquo;{prbl02b.notes}&rdquo;
                            </p>
                        )}
                    </div>
                )}

                <p className="text-amber-700 dark:text-amber-300">
                    Silakan perbaiki laporan sesuai catatan di atas dan submit ulang.
                </p>
            </div>
        </div>
    );
}
