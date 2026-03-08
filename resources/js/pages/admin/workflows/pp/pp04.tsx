import { Head, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { Trash2 } from 'lucide-react';
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

type DokumenItem = {
    id: number;
    file_id: number;
    file: { id: number; original_filename: string; mime_type: string; size: number };
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
    isRejectionReentry: boolean;
};

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function Pp04({ workflow, stepData, mode, canSubmit, canTerminate, isRejectionReentry }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isReadonly = mode === 'readonly';

    const form = useForm({
        expected_updated_at: stepData.updated_at,
        notes: '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP04: Dokumen SOP', href: '#' },
    ];

    function handleSubmit() {
        form.post(`/admin/workflows/pp/${workflow.id}/pp04/${stepData.id}/submit`);
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
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                <SectionCard title="Dokumen">
                    <p className="mb-3 text-sm text-muted-foreground">
                        Contoh dokumen: SOP Pengisian Aplikasi untuk Tim, Standar harga konsumsi per orang, honor pembicara, dll.
                    </p>

                    {stepData.item_dokumen.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">Belum ada dokumen dilampirkan.</p>
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
                                    {stepData.item_dokumen.map((dok, i) => (
                                        <tr key={dok.id} className="border-b last:border-0">
                                            <td className="px-3 py-2 text-muted-foreground">{i + 1}</td>
                                            <td className="px-3 py-2">
                                                {isReadonly ? (
                                                    <a href={`/files/${dok.file.id}/download`} className="text-primary hover:underline">
                                                        {dok.file.original_filename}
                                                    </a>
                                                ) : (
                                                    dok.file.original_filename
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right text-muted-foreground">{formatFileSize(dok.file.size)}</td>
                                            {!isReadonly && (
                                                <td className="px-3 py-2">
                                                    <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                                        <Trash2 className="h-3.5 w-3.5 text-muted-foreground" />
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
                        <p className="mt-2 text-xs text-muted-foreground">
                            Upload file melalui menu File terlebih dahulu, lalu lampirkan di sini. (Prototype: file upload dilewati)
                        </p>
                    )}
                </SectionCard>

                {!isReadonly && (
                    <div className="flex gap-2">
                        {canSubmit && (
                            <Button onClick={handleSubmit} disabled={form.processing}>
                                {form.processing ? 'Mengirim...' : 'Submit'}
                            </Button>
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
            onConfirm={({ notes }) => {
                setProcessing(true);
                router.post(`/admin/workflows/pp/${workflowId}/terminate`, { notes }, {
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}
