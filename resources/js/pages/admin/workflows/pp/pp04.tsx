import { Head, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { Paperclip, Trash2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type WorkspaceFile = {
    id: number;
    original_filename: string;
    mime_type: string;
    size: number;
};

type DokumenItem = {
    id: number;
    file_id: number;
    file: WorkspaceFile;
};

type StepData = {
    id: number;
    item_dokumen: DokumenItem[];
    updated_at: string;
};

type Workflow = { id: number; label: string; history: HistoryEntry[] };

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'create' | 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canTerminate: boolean;
    canComment: boolean;
    isRejectionReentry: boolean;
    workspaceFiles: WorkspaceFile[];
};

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function Pp04({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, canComment, isRejectionReentry, workspaceFiles }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [processing, setProcessing] = useState(false);
    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);
    const isPermissionLocked = mode !== 'readonly' && !canDraft && !canSubmit;

    // Track attached files locally — start from server state
    const [attachedFiles, setAttachedFiles] = useState<WorkspaceFile[]>(
        stepData.item_dokumen.map((dok) => dok.file),
    );

    const attachedIds = attachedFiles.map((f) => f.id);
    const availableFiles = (workspaceFiles ?? []).filter((f) => !attachedIds.includes(f.id));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP04: Dokumen SOP', href: '#' },
    ];

    function attachFile(file: WorkspaceFile) {
        setAttachedFiles([...attachedFiles, file]);
    }

    function detachFile(fileId: number) {
        setAttachedFiles(attachedFiles.filter((f) => f.id !== fileId));
    }

    function handleAction(action: 'draft' | 'submit', notes: string, files: File[]) {
        setProcessing(true);
        const url = `/admin/workflows/pp/${workflow.id}/pp04/${stepData.id}/${action}`;
        router.post(url, {
            attach_file_ids: attachedFiles.map((f) => f.id),
            expected_updated_at: stepData.updated_at,
            notes: notes || undefined,
            ...(files.length > 0 ? { files } : {}),
        }, {
            forceFormData: files.length > 0,
            onFinish: () => setProcessing(false),
        });
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
            <Head title={`PP04: Dokumen SOP — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP04: Dokumen SOP" description="Upload dokumen SOP (opsional, 0 file diperbolehkan)" />
                    <StepStatusBadge mode={mode} />
                </div>

                {isPermissionLocked && (
                    <div className="rounded-md border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-200">
                        Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {isRejectionReentry && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        Step ini dikembalikan dari PP05. Data dari pengisian sebelumnya sudah dimuat ulang.
                    </div>
                )}

                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp04"
                    canComment={canComment}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                <SectionCard title="Dokumen">
                    <p className="mb-3 text-sm text-destructive">
                        Contoh dokumen: SOP Pengisian Aplikasi untuk Tim, Standar harga konsumsi per orang, honor pembicara, dll.
                    </p>

                    {attachedFiles.length === 0 ? (
                        <p className="py-4 text-center text-sm text-destructive">Belum ada dokumen dilampirkan.</p>
                    ) : (
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
                                    {attachedFiles.map((file, i) => (
                                        <tr key={file.id} className="border-b last:border-0">
                                            <td className="px-3 py-2 text-destructive">{i + 1}</td>
                                            <td className="px-3 py-2">
                                                {isReadonly ? (
                                                    <a href={`/files/${file.id}/download`} className="text-primary hover:underline">
                                                        {file.original_filename}
                                                    </a>
                                                ) : (
                                                    file.original_filename
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right text-destructive">{formatFileSize(file.size)}</td>
                                            {!isReadonly && (
                                                <td className="px-3 py-2">
                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => detachFile(file.id)}>
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

                    {!isReadonly && availableFiles.length > 0 && (
                        <div className="mt-3">
                            <label className="mb-1 block text-xs font-medium text-destructive">Lampirkan file dari workspace:</label>
                            <div className="flex gap-2">
                                <FilePickerSelect files={availableFiles} onSelect={attachFile} />
                            </div>
                        </div>
                    )}

                    {!isReadonly && availableFiles.length === 0 && attachedFiles.length === 0 && (
                        <p className="mt-2 text-xs text-destructive">
                            Upload file melalui menu File terlebih dahulu, lalu lampirkan di sini.
                        </p>
                    )}
                </SectionCard>

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
                                description="Simpan lampiran dokumen sebagai draft. File bisa ditambah/dihapus kembali."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {!isReadonly && canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing}>
                                        {processing ? 'Mengirim...' : 'Submit'}
                                    </Button>
                                }
                                title="Submit PP04"
                                description="Dokumen akan dikunci dan dibuat publik di workspace. Step selanjutnya (PP05) akan diaktifkan."
                                confirmLabel="Submit"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
                        )}
                        {canTerminate && (
                            <TerminateButton workflowId={workflow.id} />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function FilePickerSelect({ files, onSelect }: { files: WorkspaceFile[]; onSelect: (file: WorkspaceFile) => void }) {
    const [selectedId, setSelectedId] = useState<string>('');

    function handleAttach() {
        const file = files.find((f) => f.id === Number(selectedId));
        if (file) {
            onSelect(file);
            setSelectedId('');
        }
    }

    return (
        <>
            <select
                value={selectedId}
                onChange={(e) => setSelectedId(e.target.value)}
                className="h-8 flex-1 rounded-md border bg-background px-2 text-sm"
            >
                <option value="">Pilih file...</option>
                {files.map((f) => (
                    <option key={f.id} value={f.id}>
                        {f.original_filename} ({formatFileSize(f.size)})
                    </option>
                ))}
            </select>
            <Button variant="outline" size="sm" onClick={handleAttach} disabled={!selectedId} className="h-8">
                <Paperclip className="mr-1 h-3.5 w-3.5" />
                Lampirkan
            </Button>
        </>
    );
}

function StepStatusBadge({ mode }: { mode: string }) {
    const config: Record<string, { label: string; className: string }> = {
        readonly: { label: 'Sudah Disubmit', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
        edit: { label: 'Draft', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
        create: { label: 'Menunggu Diisi', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
    };
    const c = config[mode] ?? config.create;
    return <Badge className={c.className}>{c.label}</Badge>;
}

function TerminateButton({ workflowId }: { workflowId: number }) {
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
                router.post(`/admin/workflows/pp/${workflowId}/terminate`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}
