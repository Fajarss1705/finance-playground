import { Head, router } from '@inertiajs/react';
import { CheckCircle2, FileText, Info, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import SubmitterLine from '@/components/workflow/submitter-line';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type AnggaranItem = {
    pabd01_item_id: number;
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    mata_anggaran: string;
    nominal: number;
    status_item: string;
    status_label: string | null;
    dicairkan: boolean;
};

type KegiatanGroup = {
    kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    anggaran: AnggaranItem[];
};

type ProgramGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    tipe: string;
    kegiatan: KegiatanGroup[];
};

type Submitter = { name: string; role: string; team: string; at: string } | null;

type ApprovalInfo = { name: string; role: string; team: string; at: string; notes: string | null } | null;

type SummaryTotals = {
    total: number;
    totalDicairkan: number;
    totalTidakDicairkan: number;
    countAll: number;
    countDicairkan: number;
    countTidakDicairkan: number;
};

type BankDetails = {
    nama_rekening: string | null;
    nomor_rekening: string | null;
    nama_bank: string | null;
    pp_label: string | null;
    pp_revision: number;
};

type Workflow = {
    id: number;
    uuid: string;
    team_name: string;
    bulan_anggaran: number;
    bulan_label: string;
    tahun_anggaran: number;
    updated_at: string;
};

type Pabd04DataProp = {
    id: number;
    updated_at: string;
};

type BuktiTransferFile = {
    id: number;
    file_id: number;
    original_filename: string | null;
    mime_type: string | null;
    size: number | null;
    uuid: string | null;
    download_url: string | null;
};

type Props = {
    scope: 'team' | 'admin';
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canComment: boolean;
    canTerminate: boolean;
    workflow: Workflow;
    pabd04Data: Pabd04DataProp;
    pabd01ChecklistData: ProgramGroup[];
    pabd01Submitter: Submitter;
    pabd01Cycle: number;
    pabd01PreviousCycles: { cycle: number; dataId: number }[];
    summaryTotals: SummaryTotals;
    pabd03ApprovalInfo: ApprovalInfo;
    bankDetails: BankDetails;
    buktiTransferFiles: BuktiTransferFile[];
    budgetCounter: BudgetCounterData;
    expectedUpdatedAt: string;
    stepStatuses: Record<string, { status: string; cycle?: number }>;
    stepperData: unknown;
    history: HistoryEntry[];
    actionRoles: ActionRole[];
    activeRoleName: string | null;
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

// ─── Checklist Table (readonly) ─────────────────────

function AnggaranChecklistReadonly({ programs }: { programs: ProgramGroup[] }) {
    const totalAll = programs.flatMap(p => p.kegiatan.flatMap(k => k.anggaran));
    const dicairkanItems = totalAll.filter(a => a.dicairkan);
    const tidakDicairkan = totalAll.filter(a => !a.dicairkan);

    return (
        <div className="space-y-3">
            {programs.map((program) => (
                <div key={program.program_id} className="rounded border p-3">
                    <h5 className="text-xs font-semibold">{program.program_name} ({program.kode_kategori})</h5>
                    {program.kegiatan.map((kegiatan) => (
                        <div key={kegiatan.kegiatan_id} className="mt-2 ml-2">
                            <p className="text-xs font-medium text-muted-foreground">{kegiatan.nama_kegiatan} &mdash; {kegiatan.bulan_label}</p>
                            <div className="mt-1 overflow-x-auto">
                                <table className="w-full border-collapse border text-xs">
                                    <thead>
                                        <tr className="bg-muted/50 text-left text-muted-foreground">
                                            <th className="w-8 border p-1.5"></th>
                                            <th className="border p-1.5">Kode Anggaran</th>
                                            <th className="border p-1.5">Mata Anggaran</th>
                                            <th className="border p-1.5 text-right">Nominal (Rp)</th>
                                            <th className="border p-1.5">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.anggaran.map((a) => (
                                            <tr key={a.pk04_anggaran_id}>
                                                <td className="border p-1.5 text-center">{a.dicairkan ? <CheckCircle2 className="inline h-3.5 w-3.5 text-green-600" /> : <span className="text-muted-foreground">&times;</span>}</td>
                                                <td className="border p-1.5">{a.kode_anggaran_baru ? <KodeAnggaranFromString kode={a.kode_anggaran_baru} /> : '—'}</td>
                                                <td className="border p-1.5">{a.mata_anggaran}</td>
                                                <td className="border p-1.5 text-right font-mono">{formatRupiah(a.nominal)}</td>
                                                <td className="border p-1.5">{a.status_label && (
                                                    <Badge className={`text-[10px] ${
                                                        a.status_label.startsWith('Tarik Maju') || a.status_label.startsWith('Ditarik Maju')
                                                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300'
                                                            : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300'
                                                    }`}>{a.status_label}</Badge>
                                                )}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}
                </div>
            ))}
            <div className="rounded border bg-muted/30 p-2 text-xs">
                <div className="flex justify-between"><span>Total Anggaran:</span><span className="font-mono font-medium">{formatRupiah(totalAll.reduce((s, a) => s + a.nominal, 0))} ({totalAll.length} item)</span></div>
                <div className="flex justify-between"><span>Dicairkan:</span><span className="font-mono">{formatRupiah(dicairkanItems.reduce((s, a) => s + a.nominal, 0))} ({dicairkanItems.length} item)</span></div>
                <div className="flex justify-between"><span>Tidak Dicairkan:</span><span className="font-mono">{formatRupiah(tidakDicairkan.reduce((s, a) => s + a.nominal, 0))} ({tidakDicairkan.length} item)</span></div>
            </div>
        </div>
    );
}

// ─── Bank Details Card ──────────────────────────────

function BankDetailsCard({ bankDetails }: { bankDetails: BankDetails }) {
    return (
        <div className="rounded border bg-muted/20 p-3 text-sm">
            <div className="grid gap-2 sm:grid-cols-2">
                <div>
                    <span className="text-xs text-muted-foreground">Nama Rekening</span>
                    <p className="font-medium">{bankDetails.nama_rekening || '—'}</p>
                </div>
                <div>
                    <span className="text-xs text-muted-foreground">Nomor Rekening</span>
                    <p className="font-mono font-medium">{bankDetails.nomor_rekening || '—'}</p>
                </div>
                {bankDetails.nama_bank && (
                    <div>
                        <span className="text-xs text-muted-foreground">Bank</span>
                        <p className="font-medium">{bankDetails.nama_bank}</p>
                    </div>
                )}
                <div>
                    <span className="text-xs text-muted-foreground">Referensi PP</span>
                    <p className="font-medium">{bankDetails.pp_label ? `${bankDetails.pp_label} Revisi ${bankDetails.pp_revision}` : '—'}</p>
                </div>
            </div>
        </div>
    );
}

// ─── Bukti Transfer Upload ──────────────────────────

function BuktiTransferUpload({
    existingFiles,
    newFiles,
    removeIds,
    onAddFiles,
    onRemoveExisting,
    onRemoveNew,
    readonly,
}: {
    existingFiles: BuktiTransferFile[];
    newFiles: File[];
    removeIds: number[];
    onAddFiles: (files: File[]) => void;
    onRemoveExisting: (id: number) => void;
    onRemoveNew: (index: number) => void;
    readonly: boolean;
}) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const visibleExisting = existingFiles.filter(f => !removeIds.includes(f.id));
    const totalFiles = visibleExisting.length + newFiles.length;

    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                Upload bukti transfer dari bank (minimal 1 file). Format: JPG, PNG, GIF, WEBP, PDF, DOC, XLS — Maks 25MB/file
            </p>

            {/* Existing files */}
            {visibleExisting.map((file) => (
                <div key={file.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                    {file.download_url ? (
                        <a href={file.download_url} target="_blank" rel="noopener noreferrer" className="flex-1 truncate text-blue-600 hover:underline dark:text-blue-400">
                            {file.original_filename || 'File'}
                        </a>
                    ) : (
                        <span className="flex-1 truncate">{file.original_filename || 'File'}</span>
                    )}
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

            {/* New files (pending upload) */}
            {newFiles.map((file, index) => (
                <div key={`new-${index}`} className="flex items-center gap-2 rounded-md border border-dashed border-blue-300 bg-blue-50/50 px-3 py-2 text-sm dark:border-blue-700 dark:bg-blue-950/20">
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

            {/* Upload button */}
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
                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx"
                        onChange={(e) => {
                            if (e.target.files) {
                                onAddFiles(Array.from(e.target.files));
                                e.target.value = '';
                            }
                        }}
                    />
                </>
            )}

            {totalFiles === 0 && readonly && (
                <p className="text-xs text-muted-foreground italic">Belum ada file bukti transfer.</p>
            )}
        </div>
    );
}

// ─── Main Page ──────────────────────────────────────

export default function Pabd04({
    scope, mode, canDraft, canSubmit, canComment, workflow,
    pabd04Data, pabd01ChecklistData, pabd01Submitter, pabd01Cycle,
    pabd03ApprovalInfo, bankDetails, buktiTransferFiles,
    budgetCounter, expectedUpdatedAt, stepStatuses,
    history, actionRoles, activeRoleName,
}: Props) {
    const [processing, setProcessing] = useState(false);
    const [newFiles, setNewFiles] = useState<File[]>([]);
    const [removeIds, setRemoveIds] = useState<number[]>([]);

    const isReadonly = mode === 'readonly';
    const stepStatus = stepStatuses['PABD04']?.status ?? 'active';

    // Compute total file count for submit validation
    const visibleExistingCount = buktiTransferFiles.filter(f => !removeIds.includes(f.id)).length;
    const totalFileCount = visibleExistingCount + newFiles.length;

    const basePath = `/${scope}/workflows/pabd/${workflow.id}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'admin' ? 'Admin' : 'Tim', href: `/${scope}` },
        { title: 'Anggaran Bulanan', href: `/${scope}/workflows/pabd` },
        { title: `PABD-${workflow.team_name}-${workflow.bulan_label}/${workflow.tahun_anggaran}`, href: basePath },
        { title: 'PABD04: Upload Bukti Transfer' },
    ];

    const commentUrl = `${basePath}/comment`;

    function buildFormData(notes?: string, actionFiles?: File[]): FormData {
        const formData = new FormData();
        formData.append('expected_updated_at', expectedUpdatedAt);

        // New bukti transfer files
        newFiles.forEach((file) => {
            formData.append('bukti_transfer_files[]', file);
        });

        // Remove file IDs
        removeIds.forEach((id) => {
            formData.append('remove_file_ids[]', String(id));
        });

        if (notes) {
            formData.append('notes', notes);
        }

        // Action-level files from dialog
        if (actionFiles && actionFiles.length > 0) {
            actionFiles.forEach((file) => {
                formData.append('files[]', file);
            });
        }

        return formData;
    }

    function handleAction(action: 'draft' | 'submit', notes?: string, files?: File[]) {
        if (processing) return;
        setProcessing(true);

        const url = `${basePath}/pabd04/${pabd04Data.id}/${action}`;
        const formData = buildFormData(notes, files);

        router.post(
            url,
            formData,
            {
                forceFormData: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setNewFiles([]);
                    setRemoveIds([]);
                },
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PABD04: Upload Bukti Transfer" />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <div className="flex items-center gap-3">
                    <Heading title="PABD04: Upload Bukti Transfer" description={`${workflow.team_name} — ${workflow.bulan_label} ${workflow.tahun_anggaran}`} />
                    <StepStatusBadge status={stepStatus} />
                </div>

                {/* Banners */}
                {isReadonly && stepStatus === 'completed' && (
                    <div className="flex items-center gap-2 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300">
                        <CheckCircle2 className="h-4 w-4" /> Step ini sudah selesai disubmit. Data hanya dapat dilihat.
                    </div>
                )}
                {isReadonly && stepStatus !== 'completed' && (
                    <div className="flex items-center gap-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                        <Info className="h-4 w-4" /> Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName ?? undefined} />

                {/* History & Comments */}
                <HistoryCommentSection
                    entries={history}
                    commentUrl={commentUrl}
                    commentSource="pabd04"
                    canComment={canComment}
                    finalSteps={['PABD05']}
                    stepUrlResolver={(entry: HistoryEntry) => {
                        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
                        const step = entry.step;
                        if (step === 'PABD01' && entry.id) return `${basePath}/pabd01/${entry.id}`;
                        if (step === 'PABD02A' && entry.id) return `${basePath}/pabd02a/${entry.id}`;
                        if (step === 'PABD02B' && entry.id) return `${basePath}/pabd02b/${entry.id}`;
                        if (step === 'PABD03') return `${basePath}/pabd03`;
                        if (step === 'PABD04' && entry.id) return `${basePath}/pabd04/${entry.id}`;
                        if (step === 'PABD05') return `${basePath}/pabd05`;
                        return null;
                    }}
                />

                {/* Budget Reference */}
                <BudgetReferenceCard counter={budgetCounter} variant="readonly" />

                {/* PABD03 Approval Info */}
                {pabd03ApprovalInfo && (
                    <SectionCard title="PABD03 — Persetujuan Transfer">
                        <SubmitterLine label="Disetujui oleh" name={pabd03ApprovalInfo.name} role={pabd03ApprovalInfo.role} team={pabd03ApprovalInfo.team} date={pabd03ApprovalInfo.at} />
                        {pabd03ApprovalInfo.notes && (
                            <p className="mt-1 text-xs text-muted-foreground italic whitespace-pre-line">&ldquo;{pabd03ApprovalInfo.notes}&rdquo;</p>
                        )}
                    </SectionCard>
                )}

                {/* PABD01 Checklist (readonly) */}
                <SectionCard
                    title={`PABD01 — Checklist Pencairan ${workflow.bulan_label} ${workflow.tahun_anggaran}`}
                    headerRight={
                        pabd01Cycle > 1 ? <Badge variant="outline" className="text-[10px]">Pengisian ke-{pabd01Cycle}</Badge> : undefined
                    }
                >
                    {pabd01Submitter && (
                        <SubmitterLine label="Diisi oleh" name={pabd01Submitter.name} role={pabd01Submitter.role} team={pabd01Submitter.team} date={pabd01Submitter.at} />
                    )}
                    <div className="mt-2">
                        <AnggaranChecklistReadonly programs={pabd01ChecklistData} />
                    </div>
                </SectionCard>

                {/* Bank Details */}
                <SectionCard title="Informasi Rekening Tujuan">
                    <BankDetailsCard bankDetails={bankDetails} />
                </SectionCard>

                {/* Bukti Transfer Upload */}
                <SectionCard title={`Bukti Transfer${!isReadonly ? ' *' : ''}`}>
                    <BuktiTransferUpload
                        existingFiles={buktiTransferFiles}
                        newFiles={newFiles}
                        removeIds={removeIds}
                        onAddFiles={(files) => setNewFiles(prev => [...prev, ...files])}
                        onRemoveExisting={(id) => setRemoveIds(prev => [...prev, id])}
                        onRemoveNew={(index) => setNewFiles(prev => prev.filter((_, i) => i !== index))}
                        readonly={isReadonly}
                    />
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canDraft || canSubmit) && (
                    <div className="flex justify-end gap-3">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={<Button variant="secondary" disabled={processing}>Simpan Draft</Button>}
                                title="Simpan Draft"
                                description="Bukti transfer akan disimpan sebagai draft. Anda dapat melengkapi dan mengubah file nanti."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing || totalFileCount === 0} title={totalFileCount === 0 ? 'Upload minimal 1 bukti transfer' : undefined}>
                                        Submit
                                    </Button>
                                }
                                title="Submit Bukti Transfer"
                                description="Bukti transfer akan dikirim dan dilanjutkan ke tahap final (PABD05). Pastikan file sudah benar."
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
