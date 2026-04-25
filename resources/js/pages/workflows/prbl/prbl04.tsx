import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Download, FileIcon, FileText, Info } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import SubmitterLine from '@/components/workflow/submitter-line';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRupiah } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import prbl from '@/routes/admin/workflows/prbl';
import { download as filesDownload } from '@/routes/files';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type FotoItem = {
    id: number;
    file_id: number;
    original_filename: string;
    thumbnail_url: string | null;
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

type SubmitterInfo = { name: string; role: string; team: string | null; date: string } | null;

type ApprovalChainEntry = { name: string; role: string; team: string | null; date: string; notes?: string | null } | null;

type ApprovalChain = {
    prbl02a: ApprovalChainEntry;
    prbl02b: ApprovalChainEntry;
    prbl03: ApprovalChainEntry;
};

type BuktiFile = {
    id: number;
    file_id: number;
    original_filename: string | null;
    mime_type: string | null;
    size: number | null;
    uuid: string | null;
};

type RekeningOrganisasi = {
    nama_bank: string;
    nama_rekening: string;
    nomor_rekening: string;
};

type Prbl03DataProp = {
    id: number;
    nominal_refund: number;
    keterangan: string | null;
} | null;

type RejectionInfo = {
    target: string;
    by_name: string | null;
    role_name: string | null;
    team_name: string | null;
    at: string | null;
    notes: string | null;
} | null;

type Props = {
    scope: 'team' | 'admin';
    mode: 'review' | 'readonly';
    canApprove: boolean;
    canReject: boolean;
    canComment: boolean;
    workflow: {
        id: number;
        label: string;
        status: string;
        updated_at: string;
        history: HistoryEntry[];
        stepper_cycles: unknown;
    };
    kegiatanItems: ProgramGroup[];
    totalDicairkan: number;
    totalRealisasi: number;
    prbl03Data: Prbl03DataProp;
    buktiTransferFiles: BuktiFile[];
    fotoNotaFiles: BuktiFile[];
    nominalRefund: number;
    rekeningOrganisasi: RekeningOrganisasi[];
    submitterInfo: SubmitterInfo;
    approvalChain: ApprovalChain;
    ppLabel: string | null;
    workflowMeta: {
        bulan_laporan: number;
        bulan_label: string;
        tahun_laporan: number;
        team_name: string;
    };
    stepStatus: string;
    prbl01Cycle: number;
    prbl03Cycle: number;
    previousPrbl01Cycles: { cycle: number; dataId: number }[];
    previousPrbl03Cycles: { cycle: number; dataId: number }[];
    rejectionInfo: RejectionInfo;
    history: HistoryEntry[];
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    basePath: string;
};

// ─── Step Status Badge ──────────────────────────────

function StepStatusBadge({ status }: { status: string }) {
    const map: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
        active: { label: 'Menunggu Keputusan', variant: 'outline' },
        approved: { label: 'Disetujui', variant: 'default' },
        rejected: { label: 'Ditolak', variant: 'destructive' },
    };
    const found = map[status];
    if (!found) return null;
    return <Badge variant={found.variant}>{found.label}</Badge>;
}

// ─── Narasi Blockquote ──────────────────────────────

function NarasiField({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <p className="text-xs font-medium">{label}:</p>
            {value ? (
                <p className="mt-0.5 border-l-2 border-muted-foreground/30 pl-3 text-xs italic text-muted-foreground whitespace-pre-line">{value}</p>
            ) : (
                <p className="mt-0.5 text-xs text-muted-foreground/50">Tidak ada</p>
            )}
        </div>
    );
}

// ─── Foto View Grid ─────────────────────────────────

function FotoViewGrid({ photos }: { photos: FotoItem[] }) {
    const [viewingPhoto, setViewingPhoto] = useState<string | null>(null);

    if (photos.length === 0) {
        return <p className="text-xs text-muted-foreground/50">Tidak ada foto kegiatan.</p>;
    }

    return (
        <>
            <div className="flex flex-wrap gap-2">
                {photos.map((foto) => (
                    <button key={foto.id} type="button" className="group relative h-16 w-16 overflow-hidden rounded border" onClick={() => foto.thumbnail_url && setViewingPhoto(foto.thumbnail_url)}>
                        {foto.thumbnail_url ? (
                            <img src={foto.thumbnail_url} alt={foto.original_filename} className="h-full w-full object-cover" />
                        ) : (
                            <div className="flex h-full w-full items-center justify-center bg-muted text-[10px] text-muted-foreground">No img</div>
                        )}
                    </button>
                ))}
            </div>
            {viewingPhoto && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70" onClick={() => setViewingPhoto(null)}>
                    <img src={viewingPhoto} alt="Full size" className="max-h-[90vh] max-w-[90vw] rounded" />
                </div>
            )}
        </>
    );
}

// ─── Nota File List ─────────────────────────────────

function NotaFileList({ items }: { items: NotaItem[] }) {
    if (items.length === 0) {
        return <p className="text-xs text-muted-foreground/50">Tidak ada nota pengeluaran.</p>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {items.map((nota) => (
                <a key={nota.id} href={nota.download_url ?? '#'} target="_blank" rel="noopener noreferrer"
                    className={`inline-flex items-center gap-1 rounded px-2 py-0.5 text-[10px] ${nota.download_url ? 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/30 dark:text-blue-300' : 'bg-muted text-muted-foreground'}`}>
                    {nota.mime_type?.startsWith('image/') ? <FileIcon className="h-3 w-3" /> : <Download className="h-3 w-3" />}
                    {nota.original_filename}
                </a>
            ))}
        </div>
    );
}

// ─── Kegiatan Card (PRBL04: realisasi-first, same as PRBL02B) ──────

function KegiatanCard({ kegiatan, index, total }: { kegiatan: KegiatanItem; index: number; total: number }) {
    const [open, setOpen] = useState(true);
    const subtotalDicairkan = kegiatan.realisasi.reduce((s, r) => s + r.nominal_anggaran, 0);
    const subtotalRealisasi = kegiatan.realisasi.reduce((s, r) => s + r.nominal_realisasi, 0);

    return (
        <div className="rounded border">
            <button type="button" className="flex w-full items-center gap-2 p-3 text-left text-xs" onClick={() => setOpen(!open)}>
                {open ? <ChevronDown className="h-3.5 w-3.5 shrink-0" /> : <ChevronRight className="h-3.5 w-3.5 shrink-0" />}
                <span className="font-semibold">Kegiatan {index + 1} / {total}</span>
                <span className="text-muted-foreground">&mdash; {kegiatan.nama_kegiatan} ({kegiatan.bulan_label})</span>
            </button>

            {open && (
                <div className="space-y-4 border-t p-3">
                    {/* Realisasi — PRIMARY for PRBL04 (BU financial focus) */}
                    {kegiatan.realisasi.length > 0 && (
                        <div>
                            <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Realisasi Anggaran</p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="p-1">Kode Anggaran Baru</th>
                                            <th className="p-1">Mata Anggaran</th>
                                            <th className="p-1 text-right">Dicairkan</th>
                                            <th className="p-1 text-right">Realisasi</th>
                                            <th className="p-1 text-right">Selisih</th>
                                            <th className="p-1">Kode Anggaran Lama</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.realisasi.map((r) => {
                                            const selisih = r.nominal_anggaran - r.nominal_realisasi;
                                            return (
                                                <tr key={r.pk04_anggaran_id} className="border-b last:border-0">
                                                    <td className="p-1">{r.kode_anggaran_baru ? <KodeAnggaranFromString kode={r.kode_anggaran_baru} /> : '—'}</td>
                                                    <td className="p-1">{r.mata_anggaran}</td>
                                                    <td className="p-1 text-right font-mono">{formatRupiah(r.nominal_anggaran)}</td>
                                                    <td className="p-1 text-right font-mono">{formatRupiah(r.nominal_realisasi)}</td>
                                                    <td className={`p-1 text-right font-mono ${selisih < 0 ? 'text-amber-600 font-semibold' : ''}`}>{formatRupiah(selisih)}</td>
                                                    <td className="p-1 font-mono text-muted-foreground">{r.kode_anggaran_lama ?? '—'}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t font-medium">
                                            <td colSpan={2} className="p-1">Subtotal</td>
                                            <td className="p-1 text-right font-mono">{formatRupiah(subtotalDicairkan)}</td>
                                            <td className="p-1 text-right font-mono">{formatRupiah(subtotalRealisasi)}</td>
                                            <td className="p-1 text-right font-mono">{formatRupiah(subtotalDicairkan - subtotalRealisasi)}</td>
                                            <td />
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            {/* Komentar realisasi */}
                            {kegiatan.realisasi.some((r) => r.komentar_realisasi) && (
                                <div className="mt-2 space-y-1">
                                    <p className="text-[10px] font-medium text-muted-foreground">Komentar Realisasi:</p>
                                    {kegiatan.realisasi.filter((r) => r.komentar_realisasi).map((r) => (
                                        <p key={r.pk04_anggaran_id} className="text-xs text-muted-foreground">
                                            <span className="font-medium">{r.mata_anggaran}:</span> &ldquo;{r.komentar_realisasi}&rdquo;
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Nota Pengeluaran */}
                    <div>
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Nota Pengeluaran</p>
                        <NotaFileList items={kegiatan.nota} />
                    </div>

                    {/* Narasi */}
                    <div className="space-y-2">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-primary">Narasi</p>
                        <NarasiField label="Masalah/Kendala" value={kegiatan.masalah} />
                        <NarasiField label="Langkah Penanganan" value={kegiatan.langkah_penanganan} />
                        <NarasiField label="Harapan" value={kegiatan.harapan} />
                        <NarasiField label="Catatan Tim" value={kegiatan.catatan_tim} />
                    </div>

                    {/* Foto Kegiatan */}
                    <div>
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Foto Kegiatan</p>
                        <FotoViewGrid photos={kegiatan.fotos} />
                    </div>

                    {/* Kuisioner */}
                    {kegiatan.kuisioner.length > 0 && (
                        <div>
                            <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Kuisioner</p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="w-8 p-1">#</th>
                                            <th className="p-1">Pertanyaan</th>
                                            <th className="p-1">Tipe</th>
                                            <th className="p-1">Satuan</th>
                                            <th className="p-1">Jawaban</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.kuisioner.map((k, ki) => (
                                            <tr key={k.prbl01_item_kuisioner_id} className="border-b last:border-0">
                                                <td className="p-1 text-muted-foreground">{ki + 1}</td>
                                                <td className="p-1">{k.pertanyaan}</td>
                                                <td className="p-1">{k.tipe}</td>
                                                <td className="p-1">{k.satuan || '—'}</td>
                                                <td className="p-1 font-medium">{k.jawaban || '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ─── Bukti File Chips ───────────────────────────────

function BuktiFileChips({ files, emptyText }: { files: BuktiFile[]; emptyText: string }) {
    if (files.length === 0) {
        return <p className="text-xs text-muted-foreground/50">{emptyText}</p>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {files.map((file) => (
                <a
                    key={file.id}
                    href={file.uuid ? filesDownload.url(file.uuid) : '#'}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`inline-flex items-center gap-1 rounded px-2 py-0.5 text-[10px] ${file.uuid ? 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/30 dark:text-blue-300' : 'bg-muted text-muted-foreground'}`}
                >
                    <FileText className="h-3 w-3" />
                    {file.original_filename ?? 'File'}
                </a>
            ))}
        </div>
    );
}

// ─── Dual Reject Dialog ─────────────────────────────

function DualRejectDialog({
    processing,
    onConfirm,
}: {
    processing: boolean;
    onConfirm: (data: { rejection_target: string; notes: string; files?: File[] }) => void;
}) {
    const [open, setOpen] = useState(false);
    const [rejectionTarget, setRejectionTarget] = useState<string>('');
    const [notes, setNotes] = useState('');
    const isValid = rejectionTarget !== '' && notes.trim().length >= 10;

    function handleConfirm() {
        if (!isValid) return;
        onConfirm({ rejection_target: rejectionTarget, notes: notes.trim() });
        setOpen(false);
    }

    function handleOpenChange(nextOpen: boolean) {
        setOpen(nextOpen);
        if (!nextOpen) {
            setRejectionTarget('');
            setNotes('');
        }
    }

    return (
        <>
            <Button variant="destructive" disabled={processing} onClick={() => handleOpenChange(true)}>
                Tolak
            </Button>

            {open && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={() => handleOpenChange(false)}>
                    <div className="mx-4 w-full max-w-md rounded-lg border bg-background p-6 shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <h3 className="text-sm font-semibold">Tolak PRBL &mdash; Pilih Tindakan</h3>

                        <RadioGroup value={rejectionTarget} onValueChange={setRejectionTarget} className="mt-4 space-y-3">
                            <div className="flex items-start gap-3 rounded border p-3">
                                <RadioGroupItem value="PRBL03" id="reject-prbl03" className="mt-0.5" />
                                <Label htmlFor="reject-prbl03" className="cursor-pointer space-y-0.5">
                                    <p className="text-xs font-medium">Perbaiki bukti transfer / foto nota (kecil)</p>
                                    <p className="text-[10px] text-muted-foreground">
                                        Dikembalikan ke PRBL03. Laporan kegiatan dan realisasi anggaran tidak perlu diubah.
                                    </p>
                                </Label>
                            </div>
                            <div className="flex items-start gap-3 rounded border p-3">
                                <RadioGroupItem value="PRBL01" id="reject-prbl01" className="mt-0.5" />
                                <Label htmlFor="reject-prbl01" className="cursor-pointer space-y-0.5">
                                    <p className="text-xs font-medium">Perbaiki laporan kegiatan / realisasi (besar)</p>
                                    <p className="text-[10px] text-muted-foreground">
                                        Dikembalikan ke PRBL01. Seluruh proses pelaporan dimulai ulang dari awal.
                                    </p>
                                </Label>
                            </div>
                        </RadioGroup>

                        <div className="mt-4">
                            <Label htmlFor="reject-notes" className="text-xs">
                                Catatan penolakan <span className="text-destructive">*</span>
                            </Label>
                            <Textarea
                                id="reject-notes"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Minimal 10 karakter..."
                                className="mt-1 text-xs"
                                rows={3}
                            />
                            {notes.length > 0 && notes.trim().length < 10 && (
                                <p className="mt-1 text-[10px] text-destructive">Minimal 10 karakter.</p>
                            )}
                        </div>

                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="outline" size="sm" onClick={() => handleOpenChange(false)}>
                                Batal
                            </Button>
                            <Button variant="destructive" size="sm" disabled={!isValid || processing} onClick={handleConfirm}>
                                Tolak
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

// ─── Main Page ──────────────────────────────────────

export default function Prbl04({
    scope, mode, canApprove, canReject, canComment, workflow,
    kegiatanItems, totalDicairkan, totalRealisasi,
    prbl03Data, buktiTransferFiles, fotoNotaFiles, nominalRefund, rekeningOrganisasi,
    submitterInfo, approvalChain, ppLabel, workflowMeta,
    stepStatus, prbl01Cycle, prbl03Cycle,
    previousPrbl01Cycles, previousPrbl03Cycles,
    rejectionInfo,
    actionRoles, activeRoleName,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const isReadonly = mode === 'readonly';
    const totalKegiatan = kegiatanItems.reduce((sum, p) => sum + p.kegiatan.length, 0);
    const totalAnggaranItems = kegiatanItems.reduce((sum, p) => sum + p.kegiatan.reduce((s, k) => s + k.realisasi.length, 0), 0);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'admin' ? 'Admin' : 'Tim', href: adminIndex.url() },
        { title: 'Laporan Bulanan', href: prbl.index.url() },
        { title: workflow.label, href: prbl.show.url(workflow.id) },
        { title: 'PRBL04: Review Final' },
    ];

    const commentUrl = prbl.comment.url(workflow.id);

    function handleApprove(notes?: string, files?: File[]) {
        if (processing) return;
        setProcessing(true);

        const data: Record<string, unknown> = {
            expected_updated_at: workflow.updated_at,
            notes: notes || null,
        };
        if (files && files.length > 0) {
            data.files = files;
        }

        router.post(prbl.prbl04.approve.url(workflow.id), data, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    function handleReject(data: { rejection_target: string; notes: string; files?: File[] }) {
        if (processing) return;
        setProcessing(true);

        router.post(prbl.prbl04.reject.url(workflow.id), {
            expected_updated_at: workflow.updated_at,
            rejection_target: data.rejection_target,
            notes: data.notes,
        }, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PRBL04: Review Final" />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <div className="flex items-center gap-3">
                    <Heading title="PRBL04: Review Final" description={`${workflowMeta.team_name} — ${workflowMeta.bulan_label} ${workflowMeta.tahun_laporan}`} />
                    <StepStatusBadge status={stepStatus} />
                </div>

                {/* Status Banners */}
                {isReadonly && stepStatus === 'approved' && (
                    <div className="flex items-center gap-2 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300">
                        <CheckCircle2 className="h-4 w-4" /> Laporan bulanan telah disetujui dan PRBL05 telah dikompilasi.
                    </div>
                )}
                {rejectionInfo && rejectionInfo.target === 'PRBL03' && (
                    <div className="flex items-start gap-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p className="font-medium">Dikembalikan ke PRBL03: perbaiki bukti transfer / foto nota.</p>
                            <p className="mt-0.5 text-[10px]">
                                Oleh: {rejectionInfo.by_name} ({rejectionInfo.role_name}{rejectionInfo.team_name ? ` · ${rejectionInfo.team_name}` : ''}) &mdash; {formatDateTime(rejectionInfo.at)}
                            </p>
                            {rejectionInfo.notes && <p className="mt-0.5 text-[10px]">Catatan: {rejectionInfo.notes}</p>}
                        </div>
                    </div>
                )}
                {rejectionInfo && rejectionInfo.target === 'PRBL01' && (
                    <div className="flex items-start gap-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p className="font-medium">Dikembalikan ke PRBL01: perbaiki laporan kegiatan / realisasi.</p>
                            <p className="mt-0.5 text-[10px]">
                                Oleh: {rejectionInfo.by_name} ({rejectionInfo.role_name}{rejectionInfo.team_name ? ` · ${rejectionInfo.team_name}` : ''}) &mdash; {formatDateTime(rejectionInfo.at)}
                            </p>
                            {rejectionInfo.notes && <p className="mt-0.5 text-[10px]">Catatan: {rejectionInfo.notes}</p>}
                        </div>
                    </div>
                )}
                {isReadonly && !rejectionInfo && stepStatus !== 'approved' && (
                    <div className="flex items-center gap-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                        <Info className="h-4 w-4" /> Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName ?? undefined} />

                {/* History & Comments */}
                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={commentUrl}
                    commentSource="prbl04"
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

                {/* Info Laporan + Approval Chain */}
                <SectionCard title="Info Laporan">
                    <div className="space-y-2 text-xs">
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div><span className="text-muted-foreground">Tim:</span> <span className="font-medium">{workflowMeta.team_name}</span></div>
                            <div><span className="text-muted-foreground">Bulan Laporan:</span> <span className="font-medium">{workflowMeta.bulan_label} {workflowMeta.tahun_laporan}</span></div>
                            {ppLabel && <div><span className="text-muted-foreground">Referensi PP:</span> <span className="font-medium">{ppLabel}</span></div>}
                        </div>
                        <div className="mt-2 space-y-1 border-t pt-2">
                            {submitterInfo && (
                                <SubmitterLine label="Diajukan oleh (PRBL01)" name={submitterInfo.name} role={submitterInfo.role} team={submitterInfo.team ?? undefined} date={submitterInfo.date} />
                            )}
                            {approvalChain.prbl02a && (
                                <SubmitterLine label="Review Narasi (PRBL02A)" name={approvalChain.prbl02a.name} role={approvalChain.prbl02a.role} team={approvalChain.prbl02a.team ?? undefined} date={approvalChain.prbl02a.date} />
                            )}
                            {approvalChain.prbl02b && (
                                <SubmitterLine label="Review Anggaran (PRBL02B)" name={approvalChain.prbl02b.name} role={approvalChain.prbl02b.role} team={approvalChain.prbl02b.team ?? undefined} date={approvalChain.prbl02b.date} />
                            )}
                            {approvalChain.prbl03 && (
                                <SubmitterLine label="Refund (PRBL03)" name={approvalChain.prbl03.name} role={approvalChain.prbl03.role} team={approvalChain.prbl03.team ?? undefined} date={approvalChain.prbl03.date} />
                            )}
                        </div>
                    </div>
                </SectionCard>

                {/* PRBL01 Data — Realisasi-first display (same as PRBL02B) */}
                <SectionCard
                    title="PRBL01 — Laporan Kegiatan"
                    headerRight={
                        <div className="flex items-center gap-2">
                            {prbl01Cycle > 1 && <Badge variant="outline" className="text-[10px]">Pengisian ke-{prbl01Cycle}</Badge>}
                            <Badge variant="secondary" className="text-[10px]">{totalKegiatan} kegiatan</Badge>
                        </div>
                    }
                >
                    {previousPrbl01Cycles.length > 0 && (
                        <p className="mb-2 text-[10px] text-muted-foreground">
                            Pengisian sebelumnya: {previousPrbl01Cycles.length}
                        </p>
                    )}
                    <div className="space-y-3">
                        {kegiatanItems.map((program) => (
                            <div key={program.program_id}>
                                <p className="mb-2 text-xs font-semibold">{program.program_name} <span className="text-muted-foreground">({program.kode_kategori})</span></p>
                                <div className="space-y-2">
                                    {program.kegiatan.map((kegiatan, ki) => (
                                        <KegiatanCard
                                            key={kegiatan.prbl01_item_kegiatan_id}
                                            kegiatan={kegiatan}
                                            index={ki}
                                            total={program.kegiatan.length}
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </SectionCard>

                {/* Ringkasan Realisasi */}
                <SectionCard title="Ringkasan Realisasi">
                    <div className="space-y-1 text-xs">
                        <div className="flex justify-between"><span className="text-muted-foreground">Total Anggaran Dicairkan:</span><span className="font-mono font-medium">{formatRupiah(totalDicairkan)} ({totalAnggaranItems} item)</span></div>
                        <div className="flex justify-between"><span className="text-muted-foreground">Total Realisasi:</span><span className="font-mono font-medium">{formatRupiah(totalRealisasi)}</span></div>
                        <div className="flex justify-between border-t pt-1"><span className="text-muted-foreground">Selisih (akan di-refund):</span><span className="font-mono font-medium">{formatRupiah(totalDicairkan - totalRealisasi)}</span></div>
                    </div>
                </SectionCard>

                {/* PRBL03 Data — Refund & Bukti Transfer */}
                <SectionCard
                    title="PRBL03 — Refund & Bukti Transfer"
                    headerRight={prbl03Cycle > 1 ? <Badge variant="outline" className="text-[10px]">Pengisian ke-{prbl03Cycle}</Badge> : undefined}
                >
                    {previousPrbl03Cycles.length > 0 && (
                        <p className="mb-2 text-[10px] text-muted-foreground">
                            Pengisian sebelumnya: {previousPrbl03Cycles.length}
                        </p>
                    )}

                    {prbl03Data && (
                        <div className="space-y-4">
                            {/* Refund Summary */}
                            <div>
                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Rincian Refund</p>
                                <div className="rounded border bg-muted/30 p-3 text-xs">
                                    <div className="flex justify-between"><span>Total Anggaran Dicairkan:</span><span className="font-mono font-medium">{formatRupiah(totalDicairkan)} ({totalAnggaranItems} item)</span></div>
                                    <div className="flex justify-between"><span>Total Realisasi:</span><span className="font-mono">{formatRupiah(totalRealisasi)}</span></div>
                                    <div className="mt-1 flex justify-between border-t pt-1 font-medium"><span>Total Refund:</span><span className="font-mono">{formatRupiah(nominalRefund)}</span></div>
                                </div>
                            </div>

                            {/* Rekening Organisasi */}
                            {nominalRefund > 0 && rekeningOrganisasi.length > 0 && (
                                <div>
                                    <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Rekening Organisasi Tujuan Refund</p>
                                    {ppLabel && <p className="mb-1 text-[10px] text-muted-foreground">Referensi PP: {ppLabel}</p>}
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-xs">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="p-1">Nama Bank</th>
                                                    <th className="p-1">Nama Rekening</th>
                                                    <th className="p-1">Nomor Rekening</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {rekeningOrganisasi.map((r, i) => (
                                                    <tr key={i} className="border-b last:border-0">
                                                        <td className="p-1">{r.nama_bank}</td>
                                                        <td className="p-1">{r.nama_rekening}</td>
                                                        <td className="p-1 font-mono">{r.nomor_rekening}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            {/* Bukti Transfer */}
                            <div>
                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Bukti Transfer Refund</p>
                                {nominalRefund > 0 ? (
                                    <BuktiFileChips files={buktiTransferFiles} emptyText="Tidak ada bukti transfer." />
                                ) : (
                                    <p className="text-xs text-muted-foreground/50">Tidak ada refund. Bukti transfer tidak diunggah.</p>
                                )}
                            </div>

                            {/* Foto Nota */}
                            <div>
                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Foto Nota di Kantor Pusat</p>
                                <BuktiFileChips files={fotoNotaFiles} emptyText="Tidak ada foto nota." />
                            </div>

                            {/* Keterangan */}
                            <div>
                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Keterangan</p>
                                <p className="text-xs text-muted-foreground">{prbl03Data.keterangan || 'Tidak ada'}</p>
                            </div>
                        </div>
                    )}
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canApprove || canReject) && (
                    <div className="flex justify-end gap-3">
                        {canReject && (
                            <DualRejectDialog
                                processing={processing}
                                onConfirm={handleReject}
                            />
                        )}
                        {canApprove && (
                            <ActionConfirmDialog
                                trigger={<Button disabled={processing}>Setujui</Button>}
                                title="Setujui Laporan Bulanan"
                                description="Laporan bulanan akan disetujui dan PRBL05 akan dikompilasi secara otomatis."
                                confirmLabel="Setujui"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleApprove(notes, files)}
                            />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
