import { useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, FileText, Info, AlertTriangle, Upload, X } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type AnggaranRealisasiItem = {
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    mata_anggaran: string;
    nominal_dicairkan: number;
    nominal_realisasi: number;
    selisih: number;
};

type KegiatanGroup = {
    kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    anggaran: AnggaranRealisasiItem[];
};

type ProgramGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    kegiatan: KegiatanGroup[];
};

type SubmitterInfo = { name: string; role: string; team: string | null; date: string } | null;

type ApprovalEntry = { name: string; role: string; team: string | null; date: string; notes: string | null } | null;

type ApprovalInfo = {
    prbl02a: ApprovalEntry;
    prbl02b: ApprovalEntry;
};

type RekeningOrganisasi = {
    nama_bank: string;
    nama_rekening: string;
    nomor_rekening: string;
};

type BuktiFile = {
    id: number;
    file_id: number;
    original_filename: string | null;
    mime_type: string | null;
    size: number | null;
    uuid: string | null;
};

type CycleBackNotes = {
    by_name: string | null;
    role_name: string | null;
    team_name: string | null;
    at: string | null;
    notes: string | null;
    files: unknown[] | null;
} | null;

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    stepper_cycles: unknown;
};

type Prbl03DataProp = {
    id: number;
    nominal_refund: number;
    keterangan: string | null;
    updated_at: string;
};

type Props = {
    scope: 'team' | 'admin';
    mode: 'edit' | 'readonly' | 'create';
    canDraft: boolean;
    canSubmit: boolean;
    canComment: boolean;
    workflow: Workflow;
    prbl03: Prbl03DataProp;
    kegiatanItems: ProgramGroup[];
    totalDicairkan: number;
    totalRealisasi: number;
    nominalRefund: number;
    buktiTransferFiles: BuktiFile[];
    fotoNotaFiles: BuktiFile[];
    rekeningOrganisasi: RekeningOrganisasi[];
    ppLabel: string | null;
    submitterInfo: SubmitterInfo;
    approvalInfo: ApprovalInfo;
    cycleBackNotes: CycleBackNotes;
    previousCycles: { cycle: number; dataId: number }[];
    workflowMeta: {
        bulan_laporan: number;
        bulan_label: string;
        tahun_laporan: number;
        team_name: string;
    };
    expectedUpdatedAt: string;
    history: HistoryEntry[];
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    basePath: string;
};

// ─── Step Status Badge ──────────────────────────────

function StepStatusBadge({ status }: { status: string }) {
    const map: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
        active: { label: 'Menunggu Diisi', variant: 'outline' },
        completed: { label: 'Sudah Disubmit', variant: 'default' },
    };
    const found = map[status];
    if (!found) return null;
    return <Badge variant={found.variant}>{found.label}</Badge>;
}

// ─── Realisasi Summary Table ────────────────────────

function RealisasiSummaryTable({ programs }: { programs: ProgramGroup[] }) {
    return (
        <div className="space-y-3">
            {programs.map((program) => (
                <div key={program.program_id} className="rounded border p-3">
                    <h5 className="text-xs font-semibold">
                        {program.program_name} ({program.kode_kategori})
                    </h5>
                    {program.kegiatan.map((kegiatan) => {
                        const subtotalDicairkan = kegiatan.anggaran.reduce((s, a) => s + a.nominal_dicairkan, 0);
                        const subtotalRealisasi = kegiatan.anggaran.reduce((s, a) => s + a.nominal_realisasi, 0);
                        const subtotalSelisih = subtotalDicairkan - subtotalRealisasi;

                        return (
                            <div key={kegiatan.kegiatan_id} className="mt-2 ml-2">
                                <p className="text-xs font-medium text-muted-foreground">
                                    {kegiatan.nama_kegiatan} &mdash; {kegiatan.bulan_label}
                                </p>
                                <div className="mt-1 overflow-x-auto">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="p-1">Kode Anggaran</th>
                                                <th className="p-1">Mata Anggaran</th>
                                                <th className="p-1 text-right">Dicairkan (Rp)</th>
                                                <th className="p-1 text-right">Realisasi (Rp)</th>
                                                <th className="p-1 text-right">Selisih (Rp)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {kegiatan.anggaran.map((a) => (
                                                <tr
                                                    key={a.pk04_anggaran_id}
                                                    className={`border-b last:border-0 ${a.selisih < 0 ? 'text-muted-foreground' : ''}`}
                                                >
                                                    <td className="p-1">
                                                        {a.kode_anggaran_baru ? (
                                                            <KodeAnggaranFromString kode={a.kode_anggaran_baru} />
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                    <td className="p-1">{a.mata_anggaran}</td>
                                                    <td className="p-1 text-right font-mono">{formatRupiah(a.nominal_dicairkan)}</td>
                                                    <td className="p-1 text-right font-mono">{formatRupiah(a.nominal_realisasi)}</td>
                                                    <td className={`p-1 text-right font-mono ${a.selisih < 0 ? 'text-muted-foreground' : ''}`}>
                                                        {formatRupiah(a.selisih)}
                                                    </td>
                                                </tr>
                                            ))}
                                            <tr className="border-t font-medium">
                                                <td className="p-1" colSpan={2}>
                                                    Subtotal
                                                </td>
                                                <td className="p-1 text-right font-mono">{formatRupiah(subtotalDicairkan)}</td>
                                                <td className="p-1 text-right font-mono">{formatRupiah(subtotalRealisasi)}</td>
                                                <td className="p-1 text-right font-mono">{formatRupiah(subtotalSelisih)}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

// ─── Refund Summary Card ────────────────────────────

function RefundSummaryCard({
    totalDicairkan,
    totalRealisasi,
    nominalRefund,
    itemCount,
}: {
    totalDicairkan: number;
    totalRealisasi: number;
    nominalRefund: number;
    itemCount: number;
}) {
    return (
        <div className="rounded border bg-muted/30 p-3 text-sm">
            <div className="flex justify-between">
                <span>Total Anggaran Dicairkan:</span>
                <span className="font-mono font-medium">
                    {formatRupiah(totalDicairkan)} ({itemCount} item)
                </span>
            </div>
            <div className="flex justify-between">
                <span>Total Realisasi:</span>
                <span className="font-mono">{formatRupiah(totalRealisasi)}</span>
            </div>
            <div className="mt-1 flex justify-between border-t pt-1 font-medium">
                <span>Total Refund:</span>
                <span className="font-mono">{formatRupiah(nominalRefund)}</span>
            </div>
        </div>
    );
}

// ─── File Upload Section ────────────────────────────

function FileUploadSection({
    title,
    description,
    existingFiles,
    newFiles,
    removeIds,
    onAddFiles,
    onRemoveExisting,
    onRemoveNew,
    readonly,
    accept,
}: {
    title: string;
    description: string;
    existingFiles: BuktiFile[];
    newFiles: File[];
    removeIds: number[];
    onAddFiles: (files: File[]) => void;
    onRemoveExisting: (id: number) => void;
    onRemoveNew: (index: number) => void;
    readonly: boolean;
    accept: string;
}) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const visibleExisting = existingFiles.filter((f) => !removeIds.includes(f.id));
    const totalFiles = visibleExisting.length + newFiles.length;

    return (
        <SectionCard title={title}>
            <p className="text-xs text-muted-foreground">{description}</p>

            <div className="mt-2 space-y-2">
                {visibleExisting.map((file) => (
                    <div key={file.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                        <span className="flex-1 truncate">{file.original_filename || 'File'}</span>
                        {!readonly && (
                            <button
                                type="button"
                                className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive"
                                onClick={() => onRemoveExisting(file.id)}
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        )}
                    </div>
                ))}

                {newFiles.map((file, index) => (
                    <div
                        key={`new-${index}`}
                        className="flex items-center gap-2 rounded-md border border-dashed border-blue-300 bg-blue-50/50 px-3 py-2 text-sm dark:border-blue-700 dark:bg-blue-950/20"
                    >
                        <FileText className="h-4 w-4 shrink-0 text-blue-500" />
                        <span className="flex-1 truncate">{file.name}</span>
                        <span className="shrink-0 text-xs text-muted-foreground">{(file.size / 1024).toFixed(0)} KB</span>
                        <button
                            type="button"
                            className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive"
                            onClick={() => onRemoveNew(index)}
                        >
                            <X className="h-3.5 w-3.5" />
                        </button>
                    </div>
                ))}

                {!readonly && (
                    <>
                        <button
                            type="button"
                            className="flex w-full cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-muted-foreground/25 px-6 py-6 text-center transition-colors hover:border-muted-foreground/50 hover:bg-muted/30"
                            onClick={() => fileInputRef.current?.click()}
                        >
                            <Upload className="h-6 w-6 text-muted-foreground" />
                            <p className="text-sm text-muted-foreground">Klik untuk memilih file</p>
                        </button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            className="sr-only"
                            multiple
                            accept={accept}
                            onChange={(e) => {
                                if (e.target.files) {
                                    onAddFiles(Array.from(e.target.files));
                                    e.target.value = '';
                                }
                            }}
                        />
                    </>
                )}

                {totalFiles === 0 && readonly && <p className="text-xs text-muted-foreground italic">Belum ada file.</p>}
            </div>
        </SectionCard>
    );
}

// ─── Main Page ──────────────────────────────────────

export default function Prbl03({
    scope,
    mode,
    canDraft,
    canSubmit,
    canComment,
    workflow,
    prbl03,
    kegiatanItems,
    totalDicairkan,
    totalRealisasi,
    nominalRefund,
    buktiTransferFiles,
    fotoNotaFiles,
    rekeningOrganisasi,
    ppLabel,
    submitterInfo,
    approvalInfo,
    cycleBackNotes,
    workflowMeta,
    expectedUpdatedAt,
    actionRoles,
    activeRoleName,
}: Props) {
    const [processing, setProcessing] = useState(false);
    const [keterangan, setKeterangan] = useState(prbl03.keterangan ?? '');

    // Bukti transfer file state
    const [newBuktiFiles, setNewBuktiFiles] = useState<File[]>([]);
    const [removeBuktiIds, setRemoveBuktiIds] = useState<number[]>([]);

    // Foto nota file state
    const [newNotaFiles, setNewNotaFiles] = useState<File[]>([]);
    const [removeNotaIds, setRemoveNotaIds] = useState<number[]>([]);

    const isReadonly = mode === 'readonly';

    // Compute visible file counts for submit validation
    const visibleBuktiCount = buktiTransferFiles.filter((f) => !removeBuktiIds.includes(f.id)).length + newBuktiFiles.length;
    const visibleNotaCount = fotoNotaFiles.filter((f) => !removeNotaIds.includes(f.id)).length + newNotaFiles.length;

    // Count total anggaran items
    const totalAnggaranItems = kegiatanItems.reduce((sum, p) => sum + p.kegiatan.reduce((s, k) => s + k.anggaran.length, 0), 0);

    // Submit disabled conditions
    const submitDisabledReason =
        visibleNotaCount === 0
            ? 'Upload minimal 1 foto nota'
            : nominalRefund > 0 && visibleBuktiCount === 0
              ? 'Upload minimal 1 bukti transfer'
              : undefined;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'admin' ? 'Admin' : 'Tim', href: scope === 'admin' ? route('admin.index') : route('team.index') },
        { title: 'Laporan Bulanan', href: route(`${scope}.workflows.prbl.index`) },
        {
            title: `PRBL-${workflowMeta.team_name}-${workflowMeta.bulan_label}/${workflowMeta.tahun_laporan}`,
            href: route(`${scope}.workflows.prbl.show`, { prblWorkflow: workflow.id }),
        },
        { title: 'PRBL03: Refund & Bukti Transfer' },
    ];

    const commentUrl = route(`${scope}.workflows.prbl.comment`, { prblWorkflow: workflow.id });

    function buildFormData(notes?: string, actionFiles?: File[]): FormData {
        const formData = new FormData();
        formData.append('expected_updated_at', expectedUpdatedAt);
        formData.append('keterangan', keterangan);

        newBuktiFiles.forEach((file) => formData.append('bukti_transfer_files[]', file));
        removeBuktiIds.forEach((id) => formData.append('remove_bukti_transfer_ids[]', String(id)));

        newNotaFiles.forEach((file) => formData.append('foto_nota_files[]', file));
        removeNotaIds.forEach((id) => formData.append('remove_foto_nota_ids[]', String(id)));

        if (notes) formData.append('notes', notes);
        if (actionFiles && actionFiles.length > 0) {
            actionFiles.forEach((file) => formData.append('files[]', file));
        }

        return formData;
    }

    function handleAction(action: 'draft' | 'submit', notes?: string, files?: File[]) {
        if (processing) return;
        setProcessing(true);

        const routeName = `team.workflows.prbl.prbl03.${action}`;
        const formData = buildFormData(notes, files);

        router.post(route(routeName, { prblWorkflow: workflow.id, prbl03Data: prbl03.id }), formData, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                setNewBuktiFiles([]);
                setRemoveBuktiIds([]);
                setNewNotaFiles([]);
                setRemoveNotaIds([]);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PRBL03: Refund & Bukti Transfer" />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <div className="flex items-center gap-3">
                    <Heading
                        title="PRBL03: Refund & Bukti Transfer"
                        description={`${workflowMeta.team_name} — ${workflowMeta.bulan_label} ${workflowMeta.tahun_laporan}`}
                    />
                    <StepStatusBadge status={mode === 'readonly' ? 'completed' : 'active'} />
                </div>

                {/* Banners */}
                {cycleBackNotes && !isReadonly && (
                    <div className="space-y-1 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                        <div className="flex items-center gap-2">
                            <AlertTriangle className="h-4 w-4" />
                            <span className="font-medium">Bukti transfer/nota dikembalikan untuk diperbaiki.</span>
                        </div>
                        {cycleBackNotes.by_name && (
                            <p>
                                Oleh: {cycleBackNotes.by_name}
                                {cycleBackNotes.role_name && ` (${cycleBackNotes.role_name}`}
                                {cycleBackNotes.team_name && ` · ${cycleBackNotes.team_name}`}
                                {cycleBackNotes.role_name && ')'}
                                {cycleBackNotes.at && ` — ${cycleBackNotes.at}`}
                            </p>
                        )}
                        {cycleBackNotes.notes && <p className="whitespace-pre-line italic">&ldquo;{cycleBackNotes.notes}&rdquo;</p>}
                        <p className="mt-1">Silakan perbaiki bukti transfer/foto nota sesuai catatan di atas dan submit ulang.</p>
                    </div>
                )}

                {isReadonly && (
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
                    commentSource="prbl03"
                    canComment={canComment}
                    finalSteps={['PRBL05']}
                />

                {/* Info Laporan */}
                <SectionCard title="Info Laporan">
                    <div className="grid gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <span className="text-xs text-muted-foreground">Tim</span>
                            <p className="font-medium">{workflowMeta.team_name}</p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Bulan Laporan</span>
                            <p className="font-medium">
                                {workflowMeta.bulan_label} {workflowMeta.tahun_laporan}
                            </p>
                        </div>
                        {ppLabel && (
                            <div>
                                <span className="text-xs text-muted-foreground">Referensi PP</span>
                                <p className="font-medium">{ppLabel}</p>
                            </div>
                        )}
                    </div>
                    <div className="mt-3 space-y-1 text-sm">
                        {submitterInfo && (
                            <SubmitterLine
                                label="Diajukan oleh"
                                name={submitterInfo.name}
                                role={submitterInfo.role}
                                team={submitterInfo.team ?? undefined}
                                date={submitterInfo.date}
                            />
                        )}
                        {approvalInfo.prbl02a && (
                            <SubmitterLine
                                label="Review Narasi"
                                name={`Disetujui — ${approvalInfo.prbl02a.name}`}
                                role={approvalInfo.prbl02a.role}
                                team={approvalInfo.prbl02a.team ?? undefined}
                                date={approvalInfo.prbl02a.date}
                            />
                        )}
                        {approvalInfo.prbl02b && (
                            <SubmitterLine
                                label="Review Anggaran"
                                name={`Disetujui — ${approvalInfo.prbl02b.name}`}
                                role={approvalInfo.prbl02b.role}
                                team={approvalInfo.prbl02b.team ?? undefined}
                                date={approvalInfo.prbl02b.date}
                            />
                        )}
                    </div>
                </SectionCard>

                {/* Rincian Realisasi */}
                <SectionCard title="Rincian Realisasi">
                    <RealisasiSummaryTable programs={kegiatanItems} />
                    <div className="mt-3">
                        <RefundSummaryCard
                            totalDicairkan={totalDicairkan}
                            totalRealisasi={totalRealisasi}
                            nominalRefund={nominalRefund}
                            itemCount={totalAnggaranItems}
                        />
                    </div>
                </SectionCard>

                {/* Rekening Organisasi (only when refund > 0) */}
                {nominalRefund > 0 && rekeningOrganisasi.length > 0 && (
                    <SectionCard title="Rekening Organisasi Tujuan Refund">
                        {ppLabel && <p className="mb-2 text-xs text-muted-foreground">Referensi PP: {ppLabel}</p>}
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="p-2">Nama Bank</th>
                                        <th className="p-2">Nama Rekening</th>
                                        <th className="p-2">Nomor Rekening</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rekeningOrganisasi.map((r, i) => (
                                        <tr key={i} className="border-b last:border-0">
                                            <td className="p-2">{r.nama_bank}</td>
                                            <td className="p-2">{r.nama_rekening}</td>
                                            <td className="p-2 font-mono">{r.nomor_rekening}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </SectionCard>
                )}

                {/* Bukti Transfer Upload */}
                <FileUploadSection
                    title={nominalRefund > 0 ? `Bukti Transfer Refund${!isReadonly ? ' *' : ''}` : 'Bukti Transfer Refund'}
                    description={
                        nominalRefund > 0
                            ? 'Upload bukti transfer refund ke rekening di atas (minimal 1 file). Format: JPG, PNG, PDF, DOC, XLS — Maks 25MB/file'
                            : 'Tidak ada refund. Upload bukti transfer bersifat opsional.'
                    }
                    existingFiles={buktiTransferFiles}
                    newFiles={newBuktiFiles}
                    removeIds={removeBuktiIds}
                    onAddFiles={(files) => setNewBuktiFiles((prev) => [...prev, ...files])}
                    onRemoveExisting={(id) => setRemoveBuktiIds((prev) => [...prev, id])}
                    onRemoveNew={(index) => setNewBuktiFiles((prev) => prev.filter((_, i) => i !== index))}
                    readonly={isReadonly}
                    accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx"
                />

                {/* Foto Nota Upload */}
                <FileUploadSection
                    title={`Foto Nota di Kantor Pusat${!isReadonly ? ' *' : ''}`}
                    description="Upload foto nota/kuitansi yang disimpan di kantor pusat (minimal 1 file). Format: JPG, PNG, WEBP, PDF — Maks 25MB/file"
                    existingFiles={fotoNotaFiles}
                    newFiles={newNotaFiles}
                    removeIds={removeNotaIds}
                    onAddFiles={(files) => setNewNotaFiles((prev) => [...prev, ...files])}
                    onRemoveExisting={(id) => setRemoveNotaIds((prev) => [...prev, id])}
                    onRemoveNew={(index) => setNewNotaFiles((prev) => prev.filter((_, i) => i !== index))}
                    readonly={isReadonly}
                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                />

                {/* Keterangan */}
                <SectionCard title="Keterangan">
                    {isReadonly ? (
                        <p className="text-sm whitespace-pre-line">{prbl03.keterangan || <span className="text-muted-foreground italic">Tidak ada keterangan.</span>}</p>
                    ) : (
                        <Textarea
                            value={keterangan}
                            onChange={(e) => setKeterangan(e.target.value)}
                            placeholder="Tambahkan keterangan jika diperlukan (opsional)"
                            rows={3}
                        />
                    )}
                </SectionCard>

                {/* Zero Refund Banner */}
                {nominalRefund === 0 && !isReadonly && (
                    <div className="flex items-start gap-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                        <Info className="mt-0.5 h-4 w-4 shrink-0" />
                        <p>
                            Tidak ada selisih refund untuk bulan ini. Total realisasi sama dengan total anggaran dicairkan. Pastikan foto nota sudah
                            di-upload, kemudian submit.
                        </p>
                    </div>
                )}

                {/* Actions */}
                {!isReadonly && (canDraft || canSubmit) && (
                    <div className="flex justify-end gap-3">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button variant="secondary" disabled={processing}>
                                        Simpan Draft
                                    </Button>
                                }
                                title="Simpan Draft"
                                description="Data akan disimpan sebagai draft. Anda dapat melengkapi dan mengubah file nanti."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing || !!submitDisabledReason} title={submitDisabledReason}>
                                        Submit
                                    </Button>
                                }
                                title="Submit Bukti Refund"
                                description="Bukti refund akan dikirim ke BU untuk review final (PRBL04). Pastikan semua file sudah benar."
                                confirmLabel="Submit"
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
