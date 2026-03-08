import { Head, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SectionCard from '@/components/workflow/section-card';
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

type Workflow = { id: number; label: string };

type Props = {
    workflow: Workflow;
    stepData: StepData;
    mode: 'create' | 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canTerminate: boolean;
    isRejectionReentry: boolean;
};

export default function Pp04({ workflow, stepData, mode, canSubmit, canTerminate, isRejectionReentry }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isReadonly = mode === 'readonly';
    const [showTerminate, setShowTerminate] = useState(false);
    const [terminateNotes, setTerminateNotes] = useState('');
    const [terminateProcessing, setTerminateProcessing] = useState(false);

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

    function handleTerminate() {
        if (!terminateNotes.trim()) return;
        setTerminateProcessing(true);
        router.post(`/admin/workflows/pp/${workflow.id}/terminate`, { notes: terminateNotes }, {
            onFinish: () => setTerminateProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP04: Dokumen SOP — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP04: Dokumen SOP" description="Upload dokumen SOP (opsional, 0 file diperbolehkan)" />
                    <Badge variant={isReadonly ? 'secondary' : 'default'}>
                        {isReadonly ? 'Sudah Disubmit' : 'Pengisian'}
                    </Badge>
                </div>

                {isRejectionReentry && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        Step ini dikembalikan karena penolakan. Silakan perbaiki data dan submit ulang.
                    </div>
                )}

                {errors.submit && <AlertError errors={[errors.submit]} title="Gagal submit" />}

                <SectionCard title="Dokumen yang Dilampirkan">
                    {stepData.item_dokumen.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">Belum ada dokumen dilampirkan.</p>
                    ) : (
                        <div className="space-y-2">
                            {stepData.item_dokumen.map((dok) => (
                                <div key={dok.id} className="flex items-center justify-between rounded border px-3 py-2 text-sm">
                                    <span>{dok.file.original_filename}</span>
                                    <Badge variant="secondary">{dok.file.mime_type}</Badge>
                                </div>
                            ))}
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
                                Submit PP04
                            </Button>
                        )}
                        {canTerminate && !showTerminate && (
                            <Button variant="destructive" className="ml-auto" onClick={() => setShowTerminate(true)}>Batalkan Workflow</Button>
                        )}
                    </div>
                )}

                {showTerminate && (
                    <SectionCard title="Batalkan Workflow">
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">Workflow yang dibatalkan tidak dapat dilanjutkan. Tuliskan alasan pembatalan.</p>
                            <textarea
                                value={terminateNotes}
                                onChange={(e) => setTerminateNotes(e.target.value)}
                                placeholder="Alasan pembatalan (wajib)..."
                                rows={3}
                                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <div className="flex gap-2">
                                <Button variant="destructive" onClick={handleTerminate} disabled={terminateProcessing || !terminateNotes.trim()}>Konfirmasi Batalkan</Button>
                                <Button variant="outline" onClick={() => setShowTerminate(false)}>Batal</Button>
                            </div>
                        </div>
                    </SectionCard>
                )}
            </div>
        </AppLayout>
    );
}
