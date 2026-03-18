import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import StepProgress from '@/components/workflow/step-progress';
import type { StepperCycle } from '@/components/workflow/step-progress';
import WorkflowStatusBadge from '@/components/workflow/workflow-status-badge';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type KegiatanRow = {
    nomer: number;
    nama: string;
    bulan_label: string;
    total_anggaran: number;
};

type DataTerbaru = {
    program: string;
    kategori_label: string;
    revision: number;
    verification_code: string | null;
    pk04_id: number;
    kegiatan: KegiatanRow[];
    total_anggaran: number;
    kuisioner_count: number;
};


type Informasi = {
    team_name: string;
    pp_label: string;
    pp_workflow_id: number | null;
    tipe: 'raker' | 'proposal';
    dibuat_oleh: string;
    dibuat_oleh_role: string | null;
    dibuat_oleh_team: string | null;
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
    budgetCounter: (BudgetCounterData & { pkIni: number }) | null;
    dataTerbaru: DataTerbaru | null;
    canTerminate: boolean;
    canRevise: boolean;
    canDelete: boolean;
    canComment: boolean;
    canExportZip: boolean;
    activeRoleName: string | null;
    scope: 'team' | 'admin';
};


function TipeBadge({ tipe }: { tipe: 'raker' | 'proposal' }) {
    return (
        <Badge
            variant="outline"
            className={tipe === 'raker'
                ? 'border-blue-300 bg-blue-50 text-blue-700 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300'
                : 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-300'}
        >
            {tipe === 'raker' ? 'Raker' : 'Proposal'}
        </Badge>
    );
}

export default function PkShow({
    workflow,
    informasi,
    budgetCounter,
    dataTerbaru,
    canTerminate,
    canRevise,
    canDelete,
    canComment,
    canExportZip,
    activeRoleName,
    scope,
}: Props) {
    const scopeLabel = scope === 'team' ? 'Tim' : 'Manajemen';
    const scopeBase = scope === 'team' ? '/team' : '/admin';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scopeLabel, href: scopeBase },
        { title: 'Perencanaan Kegiatan', href: `${scopeBase}/workflows/pk` },
        { title: workflow.label, href: `${scopeBase}/workflows/pk/${workflow.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={workflow.label} />
            <div className="space-y-6 p-6">
                {/* Title + Status + Tipe + Actions */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Heading title={workflow.label} description="Detail program kegiatan" />
                        <WorkflowStatusBadge status={workflow.status} />
                        <TipeBadge tipe={informasi.tipe} />
                    </div>
                    <div className="flex gap-2">
                        {canExportZip && (
                            <Button variant="outline" asChild>
                                <a href={`${scopeBase}/workflows/pk/${workflow.id}/pk04/export/zip`}>
                                    <Download className="mr-1.5 h-4 w-4" />
                                    Unduh ZIP
                                </a>
                            </Button>
                        )}
                        {dataTerbaru && (
                            <Button asChild>
                                <Link href={`${scopeBase}/workflows/pk/${workflow.id}/pk04`}>
                                    Lihat Program Tahunan
                                </Link>
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
                            <span className="text-xs text-muted-foreground">Tipe</span>
                            <div className="mt-0.5">
                                <TipeBadge tipe={informasi.tipe} />
                            </div>
                        </div>
                        <div>
                            <span className="text-xs text-muted-foreground">Dibuat oleh</span>
                            <p className="text-sm font-medium">
                                {informasi.dibuat_oleh}
                                {(informasi.dibuat_oleh_role || informasi.dibuat_oleh_team) && (
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        ({[informasi.dibuat_oleh_role, informasi.dibuat_oleh_team].filter(Boolean).join(' · ')})
                                    </span>
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
                    <StepProgress cycles={workflow.stepper_cycles} activeRoleName={activeRoleName} />
                </div>

                {/* Anggaran (raker only) */}
                {budgetCounter && (
                    <BudgetReferenceCard counter={budgetCounter} variant="readonly" />
                )}

                {/* Data Terbaru */}
                <div className="rounded-lg border">
                    <h3 className="border-b px-4 py-3 text-sm font-medium">Data Terbaru</h3>
                    {dataTerbaru ? (
                        <div className="space-y-4 px-4 py-3">
                            <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                                <span>Program: <strong>{dataTerbaru.program}</strong></span>
                                <span>Kategori: <strong>{dataTerbaru.kategori_label}</strong></span>
                            </div>
                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                <span>Revisi ke-{dataTerbaru.revision}</span>
                                {dataTerbaru.verification_code && (
                                    <span>Kode Verifikasi: <strong className="font-mono">{dataTerbaru.verification_code}</strong></span>
                                )}
                            </div>

                            <div>
                                <p className="mb-2 text-sm font-medium">
                                    Kegiatan ({dataTerbaru.kegiatan.length} kegiatan):
                                </p>
                                <div className="overflow-x-auto">
                                    <table className="min-w-80 w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="px-3 py-2 text-left font-medium">#</th>
                                                <th className="px-3 py-2 text-left font-medium">Nama Kegiatan</th>
                                                <th className="px-3 py-2 text-left font-medium">Bulan</th>
                                                <th className="px-3 py-2 text-right font-medium">Anggaran (Rp)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {dataTerbaru.kegiatan.map((k) => (
                                                <tr key={k.nomer} className="border-b last:border-0">
                                                    <td className="px-3 py-2">{k.nomer}</td>
                                                    <td className="px-3 py-2">{k.nama}</td>
                                                    <td className="px-3 py-2">{k.bulan_label}</td>
                                                    <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(k.total_anggaran)}</td>
                                                </tr>
                                            ))}
                                            <tr className="border-t bg-muted/30 font-medium">
                                                <td className="px-3 py-2" colSpan={3}>TOTAL</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(dataTerbaru.total_anggaran)}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div className="text-sm text-muted-foreground">
                                Kuisioner: {dataTerbaru.kuisioner_count} pertanyaan
                            </div>

                            <Link
                                href={`${scopeBase}/workflows/pk/${workflow.id}/pk04`}
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
                    commentUrl={`${scopeBase}/workflows/pk/${workflow.id}/comment`}
                    commentSource="show"
                    canComment={canComment}
                    finalSteps={['PK04']}
                    stepUrlResolver={(entry) => {
                        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
                        const step = entry.step;
                        const base = `${scopeBase}/workflows/pk/${workflow.id}`;

                        if (step === 'PK01' && entry.id) return `${base}/pk01/${entry.id}`;
                        if (['PK02A', 'PK02B', 'PK03'].includes(step)) return `${base}/${step.toLowerCase()}`;
                        if (step === 'PK04') return `${base}/pk04${entry.revision !== undefined ? `?revision=${entry.revision}` : ''}`;
                        if (step === 'PK05' && entry.id) return `${base}/pk05/${entry.id}`;
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
                                    onClick={() => router.post(`${scopeBase}/workflows/pk/${workflow.id}/pk05`)}
                                >
                                    Revisi
                                </Button>
                            )}
                            {canTerminate && (
                                <TerminateButton workflowId={workflow.id} scopeBase={scopeBase} />
                            )}
                            {canDelete && (
                                <DeleteButton workflowId={workflow.id} scopeBase={scopeBase} />
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function TerminateButton({ workflowId, scopeBase }: { workflowId: number; scopeBase: string }) {
    const [processing, setProcessing] = useState(false);

    return (
        <ActionConfirmDialog
            trigger={
                <Button variant="outline" className="border-destructive text-destructive hover:bg-destructive/10">
                    Batalkan Workflow
                </Button>
            }
            title="Batalkan Workflow"
            description="Workflow yang dibatalkan tidak bisa dilanjutkan. Tulis alasan pembatalan."
            confirmLabel="Batalkan Workflow"
            variant="destructive"
            requireNotes
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`${scopeBase}/workflows/pk/${workflowId}/terminate`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function DeleteButton({ workflowId, scopeBase }: { workflowId: number; scopeBase: string }) {
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
                router.delete(`${scopeBase}/workflows/pk/${workflowId}`, {
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}
