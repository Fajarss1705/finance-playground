import { Head, useForm, usePage } from '@inertiajs/react';
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
};

export default function Pp04({ workflow, stepData, mode, canSubmit }: Props) {
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

                {!isReadonly && canSubmit && (
                    <Button onClick={handleSubmit} disabled={form.processing}>
                        Submit PP04
                    </Button>
                )}
            </div>
        </AppLayout>
    );
}
