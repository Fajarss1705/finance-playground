import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import StepProgress from '@/components/workflow/step-progress';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type StepStatus = {
    status: string;
    dataId: number | null;
    cycle: number;
};

type PlafonItem = {
    kode: string | null;
    tim: string;
    plafon_anggaran: number;
};

type DataTerbaru = {
    tahun: number | null;
    pra_raker: string | null;
    penetapan: string | null;
    revision: number;
    pp06_id: number;
    plafon: PlafonItem[];
    total_anggaran: number;
    kuisioner_count: number;
    kode_count: number;
    dokumen_count: number;
};

type Informasi = {
    tahun: number | null;
    dibuat_oleh: string;
    dibuat_oleh_role: string | null;
    dibuat_tanggal: string;
    status: string;
    step_aktif: string | null;
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
    informasi: Informasi;
    dataTerbaru: DataTerbaru | null;
    canTerminate: boolean;
    canRevise: boolean;
    canDelete: boolean;
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

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID').format(value);
}

export default function PpShow({ workflow, informasi, dataTerbaru, canTerminate, canRevise, canDelete }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
    ];

    function stepUrl(code: string): string | null {
        const status = workflow.step_statuses[code];
        if (!status || status.status === 'pending') return null;

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
                {/* Title + Status + Primary Action */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <Heading title={workflow.label} description="Detail perencanaan periode" />
                        <WorkflowStatusBadge status={workflow.status} />
                    </div>
                    {dataTerbaru && (
                        <Button asChild>
                            <Link href={`/admin/workflows/pp/${workflow.id}/pp06`}>
                                Lihat Periode Tahunan
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Informasi */}
                <div className="rounded-lg border">
                    <h3 className="border-b px-4 py-3 text-sm font-medium">Informasi</h3>
                    <div className="grid gap-3 px-4 py-3 sm:grid-cols-2">
                        <div>
                            <span className="text-xs text-muted-foreground">Tahun</span>
                            <p className="text-sm font-medium">{informasi.tahun ?? '—'}</p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Dibuat oleh</span>
                            <p className="text-sm font-medium">
                                {informasi.dibuat_oleh}
                                {informasi.dibuat_oleh_role && (
                                    <span className="ml-1 text-xs text-muted-foreground">({informasi.dibuat_oleh_role})</span>
                                )}
                                <span className="ml-1 text-xs text-muted-foreground">— {informasi.dibuat_tanggal}</span>
                            </p>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Status</span>
                            <div className="mt-0.5">
                                <WorkflowStatusBadge status={informasi.status} />
                            </div>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Step Aktif</span>
                            <p className="text-sm font-medium">{informasi.step_aktif ?? '—'}</p>
                        </div>
                    </div>
                </div>

                {/* Progress Step */}
                <div className="rounded-lg border p-4">
                    <h3 className="mb-3 text-sm font-medium">Progress Step</h3>
                    <StepProgress
                        steps={stepDefs.map((s) => ({
                            code: s.code,
                            label: s.label,
                            status: (workflow.step_statuses[s.code]?.status ?? 'pending') as 'completed' | 'active' | 'pending' | 'skipped' | 'rejected',
                            url: stepUrl(s.code),
                        }))}
                        currentStep={workflow.current_steps[0]}
                    />
                </div>

                {/* Data Terbaru */}
                <div className="rounded-lg border">
                    <h3 className="border-b px-4 py-3 text-sm font-medium">Data Terbaru</h3>
                    {dataTerbaru ? (
                        <div className="space-y-4 px-4 py-3">
                            <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                <span>Tahun: <strong>{dataTerbaru.tahun ?? '—'}</strong></span>
                                <span>Pra-Raker: <strong>{dataTerbaru.pra_raker ?? '—'}</strong></span>
                                <span>Penetapan: <strong>{dataTerbaru.penetapan ?? '—'}</strong></span>
                            </div>

                            <div>
                                <p className="mb-2 text-sm font-medium">
                                    Plafon Anggaran ({dataTerbaru.plafon.length} tim):
                                </p>
                                <div className="overflow-x-auto">
                                    <table className="min-w-80 w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="px-3 py-2 text-left font-medium">Kode</th>
                                                <th className="px-3 py-2 text-left font-medium">Tim</th>
                                                <th className="px-3 py-2 text-right font-medium">Plafon (Rp)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {dataTerbaru.plafon.map((p, i) => (
                                                <tr key={i} className="border-b last:border-0">
                                                    <td className="px-3 py-2 font-mono text-xs">{p.kode ?? '—'}</td>
                                                    <td className="px-3 py-2">{p.tim}</td>
                                                    <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(p.plafon_anggaran)}</td>
                                                </tr>
                                            ))}
                                            <tr className="border-t bg-muted/30 font-medium">
                                                <td className="px-3 py-2" colSpan={2}>TOTAL</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(dataTerbaru.total_anggaran)}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                <span>Kuisioner: {dataTerbaru.kuisioner_count} pertanyaan</span>
                                <span>Kode: {dataTerbaru.kode_count} tabel</span>
                                <span>Dokumen SOP: {dataTerbaru.dokumen_count} file</span>
                            </div>

                            <Link
                                href={`/admin/workflows/pp/${workflow.id}/pp06`}
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
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="show"
                    stepUrlResolver={(entry) => {
                        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
                        const step = entry.step;
                        if (step === 'PP05' || step === 'PP06') {
                            return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}`;
                        }
                        if (entry.id && entry.table) {
                            return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}/${entry.id}`;
                        }
                        return null;
                    }}
                />

                {/* Aksi */}
                {(canTerminate || canRevise || canDelete) && (
                    <div className="rounded-lg border">
                        <h3 className="border-b px-4 py-3 text-sm font-medium">Aksi</h3>
                        <div className="flex flex-wrap gap-2 px-4 py-3">
                            {canRevise && (
                                <Button
                                    variant="secondary"
                                    onClick={() => router.visit(`/admin/workflows/pp/${workflow.id}/pp07`)}
                                >
                                    Revisi
                                </Button>
                            )}
                            {canTerminate && (
                                <TerminateButton workflowId={workflow.id} />
                            )}
                            {canDelete && (
                                <DeleteButton workflowId={workflow.id} />
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function TerminateButton({ workflowId }: { workflowId: number }) {
    const [processing, setProcessing] = useState(false);

    return (
        <ActionConfirmDialog
            trigger={
                <Button variant="outline" className="text-destructive border-destructive hover:bg-destructive/10">
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

function DeleteButton({ workflowId }: { workflowId: number }) {
    const [processing, setProcessing] = useState(false);

    return (
        <ActionConfirmDialog
            trigger={
                <Button variant="destructive">Hapus</Button>
            }
            title="Hapus Workflow"
            description="Workflow akan dipindahkan ke tong sampah. Anda dapat memulihkannya nanti."
            confirmLabel="Hapus"
            variant="destructive"
            processing={processing}
            onConfirm={() => {
                setProcessing(true);
                router.delete(`/admin/workflows/pp/${workflowId}`, {
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}
