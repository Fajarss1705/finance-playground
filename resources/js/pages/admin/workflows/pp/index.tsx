import { Form, Head, Link, router } from '@inertiajs/react';
import { FileText, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import StepProgress from '@/components/workflow/step-progress';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type StepStatus = {
    status: string;
    dataId: number | null;
    cycle: number;
};

type Workflow = {
    id: number;
    label: string;
    tahun: number | null;
    status: string;
    step_statuses: Record<string, StepStatus>;
    current_steps: string[];
    pp06: {
        revision: number;
        tahun: number;
        tanggal_mulai_pra_raker: string;
        tanggal_penetapan_program: string;
    } | null;
    created_at: string;
    updated_at: string;
};

type Props = {
    workflows: {
        data: Workflow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
];

const stepLabels = ['PP01', 'PP02', 'PP03', 'PP04', 'PP05', 'PP06', 'PP07'];

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function PpIndex({ workflows }: Props) {
    function handleCreate() {
        router.post('/admin/workflows/pp/create');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Perencanaan Periode" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title="Perencanaan Periode" description="Kelola perencanaan periode tahunan" />
                    <Button onClick={handleCreate}>
                        <Plus className="mr-1 h-4 w-4" />
                        Buat PP
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="min-w-[900px] w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">Label</th>
                                <th className="px-4 py-3 text-left font-medium">Tahun</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Langkah</th>
                                <th className="px-4 py-3 text-left font-medium">Revisi</th>
                                <th className="px-4 py-3 text-left font-medium">Dibuat</th>
                                <th className="px-4 py-3 text-right font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {workflows.data.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                                        <FileText className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada perencanaan periode.
                                    </td>
                                </tr>
                            ) : (
                                workflows.data.map((wf) => (
                                    <tr key={wf.id} className="border-b last:border-0">
                                        <td className="px-4 py-3 font-medium">
                                            <Link href={`/admin/workflows/pp/${wf.id}`} className="text-primary hover:underline">
                                                {wf.label}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">{wf.tahun ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <WorkflowStatusBadge status={wf.status} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <StepProgress
                                                steps={stepLabels.map((code) => ({
                                                    code,
                                                    label: code,
                                                    status: (wf.step_statuses[code]?.status ?? 'pending') as 'completed' | 'active' | 'pending' | 'skipped' | 'rejected',
                                                }))}
                                                currentStep={wf.current_steps[0]}
                                            />
                                        </td>
                                        <td className="px-4 py-3">{wf.pp06?.revision ?? '—'}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{formatDate(wf.created_at)}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/admin/workflows/pp/${wf.id}`}>Detail</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    links={workflows.links}
                    currentPage={workflows.current_page}
                    lastPage={workflows.last_page}
                    total={workflows.total}
                />
            </div>
        </AppLayout>
    );
}
