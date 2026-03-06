import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Heading from '@/components/heading';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import StepProgress from '@/components/workflow/step-progress';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import { prblHistory, prblSteps } from '@/lib/dummy-data/prbl';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Laporan Bulanan', href: '/admin/workflows/prbl' },
    { title: 'PRBL-2027-KA-03', href: '/admin/workflows/prbl/prbl-uuid-001' },
];

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

type StepStatus = 'completed' | 'active' | 'pending';

const stepDetails: { code: string; label: string; status: StepStatus; scope: 'team' | 'admin' }[] = [
    { code: 'PRBL01', label: 'Laporan Realisasi', status: 'completed', scope: 'team' },
    { code: 'PRBL02A', label: 'Review Narasi (Monev)', status: 'completed', scope: 'admin' },
    { code: 'PRBL02B', label: 'Review Anggaran (Bendahara)', status: 'completed', scope: 'admin' },
    { code: 'PRBL03', label: 'Refund Selisih', status: 'completed', scope: 'team' },
    { code: 'PRBL04', label: 'Review Akhir', status: 'active', scope: 'admin' },
    { code: 'PRBL05', label: 'Finalisasi', status: 'pending', scope: 'admin' },
];

const stepStatusBadge: Record<StepStatus, { label: string; className: string }> = {
    completed: { label: 'Selesai', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
    active: { label: 'Aktif', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
    pending: { label: 'Menunggu', className: 'bg-muted text-muted-foreground' },
};

export default function AdminPrblShow() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PRBL-2027-KA-03" />
            <div className="space-y-6 p-6">
                <Heading title="PRBL-2027-KA-03" description="Pelaporan Bulan Maret 2027 — Divisi Pendidikan" />

                <StepProgress steps={prblSteps} currentStep="PRBL04" />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <HistoryTimeline entries={prblHistory} />
                    </div>

                    <div className="space-y-6">
                        <SectionCard title="Info Laporan">
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Tim</dt>
                                    <dd className="font-medium">Divisi Pendidikan</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Bulan</dt>
                                    <dd className="font-medium">Maret</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Tahun</dt>
                                    <dd className="font-medium">2027</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Status</dt>
                                    <dd>
                                        <WorkflowStatusBadge status="active" />
                                    </dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Tanggal Dibuat</dt>
                                    <dd className="font-medium">{formatDate('2027-03-16T08:00:00Z')}</dd>
                                </div>
                            </dl>
                        </SectionCard>

                        <SectionCard title="Langkah-langkah">
                            <div className="space-y-3">
                                {stepDetails.map((step) => (
                                    <div key={step.code} className="flex items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">{step.code}</p>
                                            <p className="truncate text-xs text-muted-foreground">{step.label}</p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge className={stepStatusBadge[step.status].className}>
                                                {stepStatusBadge[step.status].label}
                                            </Badge>
                                            {step.status === 'completed' && step.scope === 'team' && (
                                                <span className="text-xs text-muted-foreground">Diisi oleh Tim</span>
                                            )}
                                            {step.status === 'completed' && step.scope === 'admin' && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/admin/workflows/prbl/prbl-uuid-001/${step.code.toLowerCase()}`}>
                                                        Lihat
                                                    </Link>
                                                </Button>
                                            )}
                                            {step.status === 'active' && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/admin/workflows/prbl/prbl-uuid-001/${step.code.toLowerCase()}`}>
                                                        Lihat
                                                    </Link>
                                                </Button>
                                            )}
                                            {step.status === 'pending' && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/admin/workflows/prbl/prbl-uuid-001/${step.code.toLowerCase()}`}>
                                                        Lihat
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </SectionCard>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
