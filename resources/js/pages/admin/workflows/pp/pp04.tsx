import { Head, usePage, router } from '@inertiajs/react';
import { Download, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type WorkspaceFile = {
    id: number;
    uuid: string;
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
    rejectionNotes: { notes: string; by: string | null; at: string | null } | null;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
};

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

type ExistingFile = { type: 'existing'; id: number; uuid: string; name: string; size: number };
type NewFile = { type: 'new'; file: File; name: string; size: number; key: string };
type FileEntry = ExistingFile | NewFile;

export default function Pp04({ workflow, stepData, mode, canDraft, canSubmit, canTerminate, canComment, isRejectionReentry, rejectionNotes, actionRoles, activeRoleName }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [processing, setProcessing] = useState(false);
    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);
    const isPermissionLocked = mode !== 'readonly' && !canDraft && !canSubmit;
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [dragOver, setDragOver] = useState(false);

    const [fileEntries, setFileEntries] = useState<FileEntry[]>(
        stepData.item_dokumen.map((dok) => ({
            type: 'existing' as const,
            id: dok.file.id,
            uuid: dok.file.uuid,
            name: dok.file.original_filename,
            size: dok.file.size,
        })),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP04: Dokumen SOP', href: '#' },
    ];

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

    function removeFile(index: number) {
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
        const url = `/admin/workflows/pp/${workflow.id}/pp04/${stepData.id}/${action}`;

        const keepFileIds = fileEntries.filter((e): e is ExistingFile => e.type === 'existing').map((e) => e.id);
        const dokumenFiles = fileEntries.filter((e): e is NewFile => e.type === 'new').map((e) => e.file);

        router.post(url, {
            keep_file_ids: keepFileIds,
            dokumen_files: dokumenFiles,
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
            <Head title={`PP04: Dokumen SOP — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP04: Dokumen SOP" description="Upload dokumen SOP (opsional, 0 file diperbolehkan)" />
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

                {isRejectionReentry && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        <p className="font-medium">Step ini dikembalikan dari PP05. Data dari pengisian sebelumnya sudah dimuat ulang.</p>
                        {rejectionNotes && (
                            <div className="mt-2 rounded border border-amber-200 bg-amber-100/50 px-3 py-2 dark:border-amber-600 dark:bg-amber-900/30">
                                <p className="text-xs text-amber-600 dark:text-amber-400">
                                    Catatan penolakan{rejectionNotes.by ? ` dari ${rejectionNotes.by}` : ''}:
                                </p>
                                <p className="mt-0.5">{rejectionNotes.notes}</p>
                            </div>
                        )}
                    </div>
                )}

                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}

                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp04"
                    canComment={canComment}
                    finalSteps={['PP06']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                <SectionCard title="Dokumen">
                    <p className="mb-3 text-sm text-muted-foreground">
                        Contoh dokumen: SOP Pengisian Aplikasi untuk Tim, Standar harga konsumsi per orang, honor pembicara, dll.
                    </p>

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
                                        <tr key={entry.type === 'existing' ? `e-${entry.id}` : entry.key} className="border-b last:border-0">
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
                                            <td className="px-3 py-2 text-right text-muted-foreground">{formatFileSize(entry.size)}</td>
                                            {!isReadonly && (
                                                <td className="px-3 py-2">
                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => removeFile(i)}>
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
                                description="Dokumen akan dikunci. Step selanjutnya (PP05) akan diaktifkan."
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
