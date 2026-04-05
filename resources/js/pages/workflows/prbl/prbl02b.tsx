import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Download, Info } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import SubmitterLine from '@/components/workflow/submitter-line';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import prbl from '@/routes/admin/workflows/prbl';
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

type ParallelTrackStatus = {
    step: string;
    label: string;
    status: string;
};

type Props = {
    scope: 'admin';
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
    submitterInfo: SubmitterInfo;
    parallelTrackStatus: ParallelTrackStatus;
    ppLabel: string | null;
    workflowMeta: {
        bulan_laporan: number;
        bulan_label: string;
        tahun_laporan: number;
        team_name: string;
    };
    stepStatus: string;
    cycle: number;
    previousCycles: { cycle: number; dataId: number }[];
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

// ─── Parallel Status Label ──────────────────────────

function parallelStatusLabel(status: string): string {
    switch (status) {
        case 'approved': return 'Disetujui';
        case 'rejected': return 'Ditolak';
        default: return 'Menunggu Keputusan';
    }
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
    if (photos.length === 0) {
        return <p className="text-xs text-muted-foreground/50">Tidak ada foto kegiatan.</p>;
    }

    return (
        <div className="flex flex-wrap gap-2">
            {photos.map((foto) => (
                <a
                    key={foto.id}
                    href={foto.download_url ?? foto.thumbnail_url ?? '#'}
                    download={foto.original_filename}
                    className="group relative h-16 w-16 overflow-hidden rounded border"
                    title={foto.original_filename}
                >
                    {foto.thumbnail_url ? (
                        <img src={foto.thumbnail_url} alt={foto.original_filename} className="h-full w-full object-cover" />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center bg-muted text-[10px] text-muted-foreground">No img</div>
                    )}
                </a>
            ))}
        </div>
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
                <a key={nota.id} href={nota.download_url ?? '#'} download={nota.original_filename}
                    className={`inline-flex items-center gap-1 rounded px-2 py-0.5 text-[10px] ${nota.download_url ? 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/30 dark:text-blue-300' : 'bg-muted text-muted-foreground'}`}>
                    <Download className="h-3 w-3" />
                    {nota.original_filename}
                </a>
            ))}
        </div>
    );
}

// ─── Variance Highlight ─────────────────────────────

function VarianceHighlight({ anggaran, realisasi }: { anggaran: number; realisasi: number }) {
    if (realisasi > anggaran) {
        return <Badge variant="outline" className="border-amber-300 bg-amber-50 text-[9px] text-amber-700 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-400">Melebihi</Badge>;
    }
    return null;
}

// ─── Kegiatan Card (PRBL02B: realisasi-first) ───────

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
                    {/* Realisasi — PRIMARY FOCUS for PRBL02B */}
                    {kegiatan.realisasi.length > 0 && (
                        <div>
                            <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Realisasi Anggaran</p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="p-1">Kode Anggaran</th>
                                            <th className="p-1">Mata Anggaran</th>
                                            <th className="p-1 text-right">Dicairkan</th>
                                            <th className="p-1 text-right">Realisasi</th>
                                            <th className="p-1 text-right">Selisih</th>
                                            <th className="w-14 p-1"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.realisasi.map((r) => {
                                            const exceeds = r.nominal_realisasi > r.nominal_anggaran;
                                            return (
                                                <tr key={r.pk04_anggaran_id} className={`border-b last:border-0 ${exceeds ? 'bg-amber-50/50 dark:bg-amber-950/10' : ''}`}>
                                                    <td className="p-1">{r.kode_anggaran_baru ? <KodeAnggaranFromString kode={r.kode_anggaran_baru} /> : '—'}</td>
                                                    <td className="p-1">{r.mata_anggaran}</td>
                                                    <td className="p-1 text-right font-mono">{formatRupiah(r.nominal_anggaran)}</td>
                                                    <td className="p-1 text-right font-mono">{formatRupiah(r.nominal_realisasi)}</td>
                                                    <td className="p-1 text-right font-mono">{formatRupiah(r.nominal_anggaran - r.nominal_realisasi)}</td>
                                                    <td className="p-1"><VarianceHighlight anggaran={r.nominal_anggaran} realisasi={r.nominal_realisasi} /></td>
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
                                            <td className="p-1"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            {/* Komentar realisasi displayed prominently */}
                            {kegiatan.realisasi.some(r => r.komentar_realisasi) && (
                                <div className="mt-2 space-y-1">
                                    <p className="text-[10px] font-medium text-muted-foreground">Komentar Realisasi:</p>
                                    {kegiatan.realisasi.filter(r => r.komentar_realisasi).map((r) => (
                                        <p key={r.pk04_anggaran_id} className="border-l-2 border-muted-foreground/30 pl-3 text-xs italic text-muted-foreground">
                                            <span className="font-medium not-italic">{r.mata_anggaran}:</span> {r.komentar_realisasi}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Nota Pengeluaran — PRIMARY FOCUS */}
                    <div>
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-primary">Nota Pengeluaran</p>
                        <NotaFileList items={kegiatan.nota} />
                    </div>

                    {/* Narasi — CONTEXT ONLY for PRBL02B */}
                    <div className="opacity-70">
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Narasi (konteks)</p>
                        <div className="space-y-2">
                            <NarasiField label="Masalah/Kendala" value={kegiatan.masalah} />
                            <NarasiField label="Langkah Penanganan" value={kegiatan.langkah_penanganan} />
                            <NarasiField label="Harapan" value={kegiatan.harapan} />
                            <NarasiField label="Catatan Tim" value={kegiatan.catatan_tim} />
                        </div>
                    </div>

                    {/* Foto Kegiatan — CONTEXT ONLY */}
                    <div className="opacity-70">
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Foto Kegiatan (konteks)</p>
                        <FotoViewGrid photos={kegiatan.fotos} />
                    </div>

                    {/* Kuisioner — CONTEXT ONLY */}
                    {kegiatan.kuisioner.length > 0 && (
                        <div className="opacity-70">
                            <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Kuisioner (konteks)</p>
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

// ─── Main Page ──────────────────────────────────────

export default function Prbl02b({
    mode, canApprove, canReject, canComment, workflow,
    kegiatanItems, totalDicairkan, totalRealisasi, submitterInfo,
    parallelTrackStatus, ppLabel, workflowMeta, stepStatus, cycle,
    actionRoles, activeRoleName,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const isReadonly = mode === 'readonly';
    const totalKegiatan = kegiatanItems.reduce((sum, p) => sum + p.kegiatan.length, 0);
    const constraintMet = totalRealisasi <= totalDicairkan;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admin', href: adminIndex.url() },
        { title: 'Laporan Bulanan', href: prbl.index.url() },
        { title: workflow.label, href: prbl.show.url(workflow.id) },
        { title: 'PRBL02B: Persetujuan Anggaran' },
    ];

    const commentUrl = prbl.comment.url(workflow.id);

    function handleAction(action: 'approve' | 'reject', notes?: string, files?: File[]) {
        if (processing) return;
        setProcessing(true);

        const actionFn = action === 'approve' ? prbl.prbl02b.approve : prbl.prbl02b.reject;
        const data: Record<string, unknown> = {
            expected_updated_at: workflow.updated_at,
            notes: notes || null,
        };

        if (files && files.length > 0) {
            data.files = files;
        }

        router.post(actionFn.url(workflow.id), data, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PRBL02B: Persetujuan Anggaran" />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <div className="flex items-center gap-3">
                    <Heading title="PRBL02B: Persetujuan Anggaran" description={`${workflowMeta.team_name} — ${workflowMeta.bulan_label} ${workflowMeta.tahun_laporan}`} />
                    <StepStatusBadge status={stepStatus} />
                </div>

                {/* Parallel Step Info Banner */}
                <div className="flex items-start gap-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                    <Info className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <p>Step ini berjalan paralel dengan PRBL02A (Persetujuan Narasi). Kedua persetujuan harus selesai sebelum proses dilanjutkan.</p>
                    </div>
                </div>

                {/* Status Banners */}
                {isReadonly && stepStatus === 'approved' && (
                    <div className="flex items-center gap-2 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300">
                        <CheckCircle2 className="h-4 w-4" /> Realisasi anggaran telah disetujui.
                    </div>
                )}
                {isReadonly && stepStatus === 'rejected' && (
                    <div className="flex items-center gap-2 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
                        <AlertTriangle className="h-4 w-4" /> Realisasi anggaran ditolak.
                    </div>
                )}
                {isReadonly && stepStatus !== 'approved' && stepStatus !== 'rejected' && (
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
                    commentSource="prbl02b"
                    canComment={canComment}
                    finalSteps={['PRBL05']}
                />

                {/* Info Laporan */}
                <SectionCard title="Info Laporan">
                    <div className="grid gap-2 text-xs sm:grid-cols-2">
                        {submitterInfo && (
                            <div className="sm:col-span-2">
                                <SubmitterLine label="Diajukan oleh" name={submitterInfo.name} role={submitterInfo.role} team={submitterInfo.team ?? undefined} date={submitterInfo.date} />
                            </div>
                        )}
                        <div><span className="text-muted-foreground">Tim:</span> <span className="font-medium">{workflowMeta.team_name}</span></div>
                        <div><span className="text-muted-foreground">Bulan Laporan:</span> <span className="font-medium">{workflowMeta.bulan_label} {workflowMeta.tahun_laporan}</span></div>
                        {ppLabel && <div><span className="text-muted-foreground">Referensi PP:</span> <span className="font-medium">{ppLabel}</span></div>}
                        <div>
                            <span className="text-muted-foreground">Status {parallelTrackStatus.label}:</span>{' '}
                            <Badge variant="outline" className="text-[10px]">{parallelStatusLabel(parallelTrackStatus.status)}</Badge>
                        </div>
                    </div>
                </SectionCard>

                {/* PRBL01 Data — Realisasi-first display */}
                <SectionCard
                    title={`PRBL01 — Laporan Kegiatan`}
                    headerRight={
                        <div className="flex items-center gap-2">
                            {cycle > 1 && <Badge variant="outline" className="text-[10px]">Pengisian ke-{cycle}</Badge>}
                            <Badge variant="secondary" className="text-[10px]">{totalKegiatan} kegiatan</Badge>
                        </div>
                    }
                >
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

                {/* Ringkasan Realisasi with constraint check */}
                <SectionCard title="Ringkasan Realisasi">
                    <div className="space-y-1 text-xs">
                        <div className="flex justify-between"><span className="text-muted-foreground">Total Anggaran Dicairkan:</span><span className="font-mono font-medium">{formatRupiah(totalDicairkan)}</span></div>
                        <div className="flex justify-between"><span className="text-muted-foreground">Total Realisasi:</span><span className="font-mono font-medium">{formatRupiah(totalRealisasi)}</span></div>
                        <div className="flex justify-between border-t pt-1"><span className="text-muted-foreground">Selisih (akan di-refund):</span><span className="font-mono font-medium">{formatRupiah(totalDicairkan - totalRealisasi)}</span></div>
                        <div className="mt-2 flex items-center gap-1.5 pt-1">
                            {constraintMet ? (
                                <>
                                    <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
                                    <span className="text-green-700 dark:text-green-400">Total realisasi tidak melebihi anggaran</span>
                                </>
                            ) : (
                                <>
                                    <AlertTriangle className="h-3.5 w-3.5 text-red-600" />
                                    <span className="text-red-700 dark:text-red-400">Total realisasi melebihi anggaran!</span>
                                </>
                            )}
                        </div>
                    </div>
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canApprove || canReject) && (
                    <div className="flex justify-end gap-3">
                        {canReject && (
                            <ActionConfirmDialog
                                trigger={<Button variant="destructive" disabled={processing}>Tolak</Button>}
                                title="Tolak Realisasi Anggaran"
                                description="Realisasi anggaran akan ditolak. Jika PRBL02A sudah selesai, flow akan dikembalikan ke PRBL01 untuk perbaikan."
                                confirmLabel="Tolak"
                                variant="destructive"
                                requireNotes
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('reject', notes, files)}
                            />
                        )}
                        {canApprove && (
                            <ActionConfirmDialog
                                trigger={<Button disabled={processing}>Setujui</Button>}
                                title="Setujui Realisasi Anggaran"
                                description="Realisasi anggaran akan disetujui. Jika PRBL02A juga menyetujui, flow akan dilanjutkan ke PRBL03 (Refund)."
                                confirmLabel="Setujui"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('approve', notes, files)}
                            />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
