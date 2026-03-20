import { Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import StepProgress from '@/components/workflow/step-progress';
import type { StepperCycle } from '@/components/workflow/step-progress';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type DataTerbaru = {
    bulan_label: string;
    tahun_laporan: number;
    verification_code: string | null;
    prbl05_id: number;
    total_anggaran_dicairkan: number;
    total_realisasi: number;
    total_refund: number;
    total_item: number;
    kegiatan_count: number;
    foto_count: number;
    bukti_count: number;
};

type Informasi = {
    team_name: string;
    bulan_laporan: number;
    bulan_label: string;
    tahun_laporan: number;
    pp_label: string;
    pp_workflow_id: number | null;
    pabd_label: string;
    pabd_workflow_id: number | null;
    dibuat_tanggal: string;
    status: string;
    step_aktif: string | null;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    stepper_cycles: StepperCycle[];
};

type Props = {
    workflow: Workflow;
    informasi: Informasi;
    dataTerbaru: DataTerbaru | null;
    canComment: boolean;
    canExportZip: boolean;
    activeRoleName: string | null;
    scope: 'team' | 'admin';
};

export default function PrblShow({
    workflow,
    informasi,
    dataTerbaru,
    canComment,
    canExportZip,
    activeRoleName,
    scope,
}: Props) {
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Laporan Bulanan', href: `${scopeBase}/workflows/prbl` },
        { title: workflow.label, href: `${scopeBase}/workflows/prbl/${workflow.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={workflow.label} />
            <div className="space-y-6 p-6">
                {/* Title + Status + Actions */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Heading title={workflow.label} description="Detail laporan bulanan" />
                        <WorkflowStatusBadge status={workflow.status} />
                    </div>
                    <div className="flex gap-2">
                        {canExportZip && (
                            <Button variant="outline" asChild>
                                <a href={`${scopeBase}/workflows/prbl/${workflow.id}/prbl05/export/zip`}>
                                    <Download className="mr-1.5 h-4 w-4" />
                                    Unduh ZIP
                                </a>
                            </Button>
                        )}
                    </div>
                </div>

                {/* Informasi */}
                <div className="rounded-lg border">
                    <h3 className="border-b px-4 py-3 text-sm font-medium">Informasi</h3>
                    <div className="grid gap-3 px-4 py-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <span className="text-xs text-muted-foreground">Tim</span>
                            <p className="text-sm font-medium">{informasi.team_name}</p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Bulan Laporan</span>
                            <p className="text-sm font-medium">{informasi.bulan_label} {informasi.tahun_laporan}</p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Periode PP</span>
                            <p className="text-sm font-medium">
                                {informasi.pp_workflow_id ? (
                                    <Link href={`/admin/workflows/pp/${informasi.pp_workflow_id}`} className="text-primary hover:underline">
                                        {informasi.pp_label}
                                    </Link>
                                ) : (
                                    informasi.pp_label
                                )}
                            </p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">PABD Referensi</span>
                            <p className="text-sm font-medium">
                                {informasi.pabd_workflow_id ? (
                                    <Link href={`${scopeBase}/workflows/pabd/${informasi.pabd_workflow_id}`} className="text-primary hover:underline">
                                        {informasi.pabd_label}
                                    </Link>
                                ) : (
                                    informasi.pabd_label
                                )}
                            </p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Dibuat oleh</span>
                            <p className="text-sm font-medium">
                                Sistem
                                <span className="ml-1 text-xs text-muted-foreground">— {informasi.dibuat_tanggal}</span>
                            </p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Status</span>
                            <div className="mt-0.5">
                                <WorkflowStatusBadge status={informasi.status} />
                            </div>
                        </div>
                        <div className="sm:col-span-2 lg:col-span-3">
                            <span className="text-xs text-muted-foreground">Step Aktif</span>
                            <p className="text-sm font-medium">{informasi.step_aktif ?? '—'}</p>
                        </div>
                    </div>
                </div>

                {/* Progress Step */}
                <div className="rounded-lg border p-4">
                    <h3 className="mb-3 text-sm font-medium">Progress Step</h3>
                    <StepProgress cycles={workflow.stepper_cycles} activeRoleName={activeRoleName} />
                </div>

                {/* Data Terbaru */}
                <div className="rounded-lg border">
                    <h3 className="border-b px-4 py-3 text-sm font-medium">Data Terbaru</h3>
                    {dataTerbaru ? (
                        <div className="space-y-4 px-4 py-3">
                            <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                <span>Bulan Laporan: <strong>{dataTerbaru.bulan_label} {dataTerbaru.tahun_laporan}</strong></span>
                            </div>
                            {dataTerbaru.verification_code && (
                                <div className="text-sm text-muted-foreground">
                                    Kode Verifikasi: <strong className="font-mono">{dataTerbaru.verification_code}</strong>
                                </div>
                            )}

                            <div>
                                <p className="mb-2 text-sm font-medium">Ringkasan Realisasi &amp; Refund:</p>
                                <div className="overflow-x-auto">
                                    <table className="min-w-60 w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="px-3 py-2 text-left font-medium">Kategori</th>
                                                <th className="px-3 py-2 text-right font-medium">Nominal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr className="border-b">
                                                <td className="px-3 py-2">Anggaran Dicairkan</td>
                                                <td className="px-3 py-2 text-right tabular-nums">Rp {formatRupiah(dataTerbaru.total_anggaran_dicairkan)}</td>
                                            </tr>
                                            <tr className="border-b">
                                                <td className="px-3 py-2">Total Realisasi</td>
                                                <td className="px-3 py-2 text-right tabular-nums">Rp {formatRupiah(dataTerbaru.total_realisasi)}</td>
                                            </tr>
                                            <tr className="border-t bg-muted/30 font-medium">
                                                <td className="px-3 py-2">Total Refund</td>
                                                <td className="px-3 py-2 text-right tabular-nums">Rp {formatRupiah(dataTerbaru.total_refund)}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="text-sm text-muted-foreground">
                                {dataTerbaru.kegiatan_count} kegiatan, {dataTerbaru.foto_count} foto, {dataTerbaru.bukti_count} bukti
                            </div>

                            <Link
                                href={`${scopeBase}/workflows/prbl/${workflow.id}/prbl05`}
                                className="text-sm text-primary hover:underline"
                            >
                                Lihat Detail Lengkap &rarr;
                            </Link>
                        </div>
                    ) : (
                        <p className="px-4 py-6 text-center text-sm text-muted-foreground">Belum dikompilasi</p>
                    )}
                </div>

                {/* Riwayat & Komentar */}
                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`${scopeBase}/workflows/prbl/${workflow.id}/comment`}
                    commentSource="show"
                    canComment={canComment}
                    finalSteps={['PRBL05']}
                    stepUrlResolver={(entry) => {
                        if (!entry.step) return null;
                        const step = entry.step;
                        const base = `${scopeBase}/workflows/prbl/${workflow.id}`;

                        if (step === 'PRBL01' && entry.id) return `${base}/prbl01/${entry.id}`;
                        if (step === 'PRBL02A') return `${base}/prbl02a`;
                        if (step === 'PRBL02B') return `${base}/prbl02b`;
                        if (step === 'PRBL03' && entry.id) return `${base}/prbl03/${entry.id}`;
                        if (step === 'PRBL04') return `${base}/prbl04`;
                        if (step === 'PRBL05') return `${base}/prbl05`;
                        return null;
                    }}
                />
            </div>
        </AppLayout>
    );
}
