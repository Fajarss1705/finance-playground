import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import { prblWorkflowList } from '@/lib/dummy-data/prbl';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: '/admin' },
    { title: 'Semua Laporan Bulanan', href: '/admin/workflows/prbl' },
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

export default function AdminPrblIndex() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Semua Laporan Bulanan" />
            <div className="space-y-6 p-6">
                <Heading title="Semua Laporan Bulanan" description="Seluruh pelaporan dan realisasi anggaran" />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="min-w-200 w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">Kode</th>
                                <th className="px-4 py-3 text-left font-medium">Tim</th>
                                <th className="px-4 py-3 text-left font-medium">Bulan</th>
                                <th className="px-4 py-3 text-left font-medium">Tahun</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Langkah Saat Ini</th>
                                <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Tanggal Dibuat</th>
                                <th className="px-4 py-3 text-left font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {prblWorkflowList.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">
                                        Belum ada laporan bulanan.
                                    </td>
                                </tr>
                            ) : (
                                prblWorkflowList.map((wf) => (
                                    <tr key={wf.id} className="border-b last:border-0 hover:bg-muted/30">
                                        <td className="px-4 py-3 font-medium">{wf.kode}</td>
                                        <td className="px-4 py-3">{wf.team}</td>
                                        <td className="px-4 py-3">{wf.bulan}</td>
                                        <td className="px-4 py-3">{wf.tahun}</td>
                                        <td className="px-4 py-3">
                                            <WorkflowStatusBadge status={wf.status} />
                                        </td>
                                        <td className="px-4 py-3">{wf.currentStep}</td>
                                        <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">{formatDate(wf.createdAt)}</td>
                                        <td className="px-4 py-3">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/admin/workflows/prbl/${wf.uuid}`}>Lihat</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
