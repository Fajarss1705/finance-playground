import { Head, Link, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import StepProgress from '@/components/workflow/step-progress';
import HistoryTimeline from '@/components/workflow/history-timeline';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type StepStatus = {
    status: string;
    dataId: number | null;
    cycle: number;
};

type HistoryEntry = {
    step: string;
    action: string;
    by: number | null;
    by_name: string;
    role: number | null;
    at: string;
    notes?: string;
    table?: string;
    id?: number;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    step_statuses: Record<string, StepStatus>;
    current_steps: string[];
};

type Props = {
    workflow: Workflow;
};

const stepDefs = [
    { code: 'PP01', label: 'Rencana Periode' },
    { code: 'PP02', label: 'Pertanyaan Kuisioner' },
    { code: 'PP03', label: 'Plafon Anggaran' },
    { code: 'PP04', label: 'Dokumen SOP' },
    { code: 'PP05', label: 'Persetujuan' },
    { code: 'PP06', label: 'Periode Tahunan' },
    { code: 'PP07', label: 'Revisi' },
];

export default function PpShow({ workflow }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
    ];

    function stepUrl(code: string): string | null {
        const status = workflow.step_statuses[code];
        if (!status) return null;

        if (code === 'PP05' || code === 'PP06') {
            return `/admin/workflows/pp/${workflow.id}/${code.toLowerCase()}`;
        }

        if (status.dataId) {
            return `/admin/workflows/pp/${workflow.id}/${code.toLowerCase()}/${status.dataId}`;
        }

        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={workflow.label} />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Heading title={workflow.label} description="Detail perencanaan periode" />
                        <WorkflowStatusBadge status={workflow.status} />
                    </div>
                    {workflow.status === 'active' && (
                        <TerminateButton workflowId={workflow.id} />
                    )}
                </div>

                <div className="rounded-lg border p-4">
                    <h3 className="mb-3 text-sm font-medium">Langkah Workflow</h3>
                    <StepProgress
                        steps={stepDefs.map((s) => ({
                            code: s.code,
                            label: s.label,
                            status: (workflow.step_statuses[s.code]?.status ?? 'pending') as 'completed' | 'active' | 'pending' | 'skipped' | 'rejected',
                        }))}
                        currentStep={workflow.current_steps[0]}
                    />
                </div>

                <div className="rounded-lg border">
                    <h3 className="border-b px-4 py-3 text-sm font-medium">Daftar Langkah</h3>
                    <div className="divide-y">
                        {stepDefs.map((s) => {
                            const status = workflow.step_statuses[s.code];
                            const url = stepUrl(s.code);
                            const statusLabel = status?.status ?? 'pending';
                            const cycle = status?.cycle ?? 0;

                            return (
                                <div key={s.code} className="flex items-center justify-between px-4 py-3">
                                    <div className="flex items-center gap-3">
                                        <Badge variant="outline" className="font-mono text-xs">
                                            {s.code}
                                        </Badge>
                                        <span className="text-sm">{s.label}</span>
                                        {cycle > 1 && (
                                            <Badge variant="secondary" className="text-[10px]">
                                                Pengisian ke-{cycle}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StepStatusBadge status={statusLabel} />
                                        {url && (
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={url}>
                                                    {statusLabel === 'active' ? 'Buka' : 'Lihat'}
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <HistoryTimeline
                    entries={workflow.history.map((e) => ({
                        action: e.action,
                        by: e.by_name,
                        role: `Role #${e.role ?? '—'}`,
                        at: e.at,
                        notes: e.notes,
                        table: e.table,
                    }))}
                />
            </div>
        </AppLayout>
    );
}

function StepStatusBadge({ status }: { status: string }) {
    const config: Record<string, { label: string; className: string }> = {
        completed: { label: 'Selesai', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
        active: { label: 'Aktif', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
        pending: { label: 'Menunggu', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
        skipped: { label: 'Dilewati', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
    };

    const c = config[status] ?? config.pending;

    return <Badge className={`text-[10px] ${c.className}`}>{c.label}</Badge>;
}

function TerminateButton({ workflowId }: { workflowId: number }) {
    const form = useForm({ notes: '' });

    function handleTerminate() {
        if (!form.data.notes.trim()) return;
        form.post(`/admin/workflows/pp/${workflowId}/terminate`);
    }

    return (
        <ActionConfirmDialog
            trigger={<Button variant="destructive">Batalkan</Button>}
            title="Batalkan Workflow"
            description="Workflow yang dibatalkan tidak bisa dilanjutkan. Tulis alasan pembatalan."
            confirmLabel="Batalkan"
            variant="destructive"
            requireNotes
        />
    );
}
