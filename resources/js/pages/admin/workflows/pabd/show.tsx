import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import HistoryTimeline from '@/components/workflow/history-timeline';
import SectionCard from '@/components/workflow/section-card';
import StepProgress from '@/components/workflow/step-progress';
import AppLayout from '@/layouts/app-layout';
import { pabdHistory, pabdSteps } from '@/lib/dummy-data/pabd';
import type { BreadcrumbItem } from '@/types';

const workflowUuid = 'pabd-uuid-001';
const workflowKode = 'PABD-2027-KA-03';
const workflowTeam = 'Divisi Pendidikan';
const workflowBulan = 'Maret';
const workflowTahun = 2027;
const workflowCreatedAt = '2027-02-15T08:00:00Z';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Anggaran Bulanan', href: '/admin/workflows/pabd' },
    { title: workflowKode, href: `/admin/workflows/pabd/${workflowUuid}` },
];

type StepStatus = 'completed' | 'active' | 'pending' | 'skipped' | 'rejected';

const stepStatusConfig: Record<StepStatus, { label: string; className: string }> = {
    completed: { label: 'Selesai', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
    active: { label: 'Aktif', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
    pending: { label: 'Menunggu', className: 'bg-muted text-muted-foreground' },
    skipped: { label: 'Dilewati', className: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' },
    rejected: { label: 'Ditolak', className: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' },
};

const stepDetails = [
    { code: 'PABD01', label: 'Pengajuan', status: 'completed' as const, scope: 'team', link: null },
    { code: 'PABD02A', label: 'Perubahan', status: 'completed' as const, scope: 'team', link: null },
    { code: 'PABD02B', label: 'Persetujuan', status: 'active' as const, scope: 'admin', link: `/admin/workflows/pabd/${workflowUuid}/pabd02b` },
    { code: 'PABD03', label: 'Verifikasi', status: 'pending' as const, scope: 'admin', link: `/admin/workflows/pabd/${workflowUuid}/pabd03` },
    { code: 'PABD04', label: 'Bukti Transfer', status: 'pending' as const, scope: 'team', link: null },
    { code: 'PABD05', label: 'Final', status: 'pending' as const, scope: 'admin', link: `/admin/workflows/pabd/${workflowUuid}/pabd05` },
];

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function AdminPabdShow() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={workflowKode} />
            <div className="space-y-6 p-6">
                <div className="flex items-start justify-between">
                    <Heading
                        title={workflowKode}
                        description={`Pengajuan Anggaran Bulan ${workflowBulan} ${workflowTahun} — ${workflowTeam}`}
                    />
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/admin/workflows/pabd">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Kembali
                        </Link>
                    </Button>
                </div>

                <StepProgress steps={pabdSteps} currentStep="PABD02B" />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <HistoryTimeline entries={pabdHistory} />
                    </div>

                    <div className="space-y-6">
                        <SectionCard title="Info Pengajuan">
                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Tim</span>
                                    <span className="font-medium">{workflowTeam}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Bulan</span>
                                    <span className="font-medium">{workflowBulan}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Tahun</span>
                                    <span className="font-medium">{workflowTahun}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Tanggal Dibuat</span>
                                    <span className="font-medium">{formatDate(workflowCreatedAt)}</span>
                                </div>
                            </div>
                        </SectionCard>

                        <SectionCard title="Langkah-langkah">
                            <div className="space-y-2">
                                {stepDetails.map((step) => (
                                    <div key={step.code} className="flex items-center justify-between gap-2 rounded-md border px-3 py-2">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">{step.code}</p>
                                            <p className="text-xs text-muted-foreground">{step.label}</p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge className={stepStatusConfig[step.status].className}>
                                                {stepStatusConfig[step.status].label}
                                            </Badge>
                                            {step.link ? (
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={step.link}>{step.status === 'active' ? 'Proses' : 'Lihat'}</Link>
                                                </Button>
                                            ) : step.scope === 'team' && step.status === 'completed' ? (
                                                <span className="text-xs text-muted-foreground">Diisi oleh Tim</span>
                                            ) : step.scope === 'team' && step.status === 'pending' ? (
                                                <span className="text-xs text-muted-foreground">Diisi oleh Tim</span>
                                            ) : null}
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
