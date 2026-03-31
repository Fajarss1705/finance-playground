import { Head, router } from '@inertiajs/react';
import { Info, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import SubmitterLine from '@/components/workflow/submitter-line';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type AnggaranItem = {
    pabd01_item_id: number;
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    mata_anggaran: string;
    nominal: number;
    status_item: string;
    status_label: string | null;
    dicairkan: boolean;
};

type KegiatanGroup = {
    kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    anggaran: AnggaranItem[];
};

type ProgramGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    tipe: string;
    kegiatan: KegiatanGroup[];
};

type Submitter = { name: string; role: string; team: string; at: string } | null;

type SummaryTotals = {
    total: number;
    totalDicairkan: number;
    totalTidakDicairkan: number;
    countAll: number;
    countDicairkan: number;
    countTidakDicairkan: number;
};

type BankDetails = {
    nama_rekening: string | null;
    nomor_rekening: string | null;
    nama_bank: string | null;
    pp_label: string | null;
    pp_revision: number;
};

type Workflow = {
    id: number;
    uuid: string;
    team_name: string;
    bulan_anggaran: number;
    bulan_label: string;
    tahun_anggaran: number;
    updated_at: string;
};

type Props = {
    scope: 'team' | 'admin';
    mode: 'edit' | 'readonly';
    canApprove: boolean;
    canReject: boolean;
    canComment: boolean;
    canTerminate: boolean;
    workflow: Workflow;
    pabd01ChecklistData: ProgramGroup[];
    pabd01Submitter: Submitter;
    pabd01Cycle: number;
    pabd01PreviousCycles: { cycle: number; dataId: number }[];
    summaryTotals: SummaryTotals;
    bankDetails: BankDetails;
    budgetCounter: BudgetCounterData;
    stepStatuses: Record<string, { status: string; cycle?: number }>;
    stepperData: unknown;
    history: HistoryEntry[];
    actionRoles: ActionRole[];
    activeRoleName: string | null;
};

// ─── Step Status Badge ──────────────────────────────

function StepStatusBadge({ status }: { status: string }) {
    const map: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
        active: { label: 'Menunggu Keputusan', variant: 'outline' },
        completed: { label: 'Selesai', variant: 'default' },
    };
    const found = map[status];
    if (!found) return null;
    return <Badge variant={found.variant}>{found.label}</Badge>;
}

// ─── Checklist Table (readonly) ─────────────────────

function AnggaranChecklistReadonly({ programs }: { programs: ProgramGroup[] }) {
    const totalAll = programs.flatMap(p => p.kegiatan.flatMap(k => k.anggaran));
    const totalAnggaran = totalAll.reduce((sum, a) => sum + a.nominal, 0);
    const dicairkanItems = totalAll.filter(a => a.dicairkan);
    const tidakDicairkan = totalAll.filter(a => !a.dicairkan);

    return (
        <div className="space-y-3">
            {programs.map((program) => (
                <div key={program.program_id} className="rounded border p-3">
                    <h5 className="text-xs font-semibold">{program.program_name} ({program.kode_kategori})</h5>
                    {program.kegiatan.map((kegiatan) => (
                        <div key={kegiatan.kegiatan_id} className="mt-2 ml-2">
                            <p className="text-xs font-medium text-muted-foreground">{kegiatan.nama_kegiatan} &mdash; {kegiatan.bulan_label}</p>
                            <div className="mt-1 overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="w-8 p-1"></th>
                                            <th className="p-1">Kode Anggaran</th>
                                            <th className="p-1">Mata Anggaran</th>
                                            <th className="p-1 text-right">Nominal (Rp)</th>
                                            <th className="p-1">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.anggaran.map((a) => (
                                            <tr key={a.pk04_anggaran_id} className="border-b last:border-0">
                                                <td className="p-1 text-center">{a.dicairkan ? <CheckCircle2 className="inline h-3.5 w-3.5 text-green-600" /> : <span className="text-muted-foreground">&times;</span>}</td>
                                                <td className="p-1">{a.kode_anggaran_baru ? <KodeAnggaranFromString kode={a.kode_anggaran_baru} /> : '—'}</td>
                                                <td className="p-1">{a.mata_anggaran}</td>
                                                <td className="p-1 text-right font-mono">{formatRupiah(a.nominal)}</td>
                                                <td className="p-1">{a.status_label && <Badge variant="outline" className="text-[10px]">{a.status_label}</Badge>}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}
                </div>
            ))}
            <div className="rounded border bg-muted/30 p-2 text-xs">
                <div className="flex justify-between"><span>Total Anggaran:</span><span className="font-mono font-medium">{formatRupiah(totalAnggaran)} ({totalAll.length} item)</span></div>
                <div className="flex justify-between"><span>Dicairkan:</span><span className="font-mono">{formatRupiah(dicairkanItems.reduce((s, a) => s + a.nominal, 0))} ({dicairkanItems.length} item)</span></div>
                <div className="flex justify-between"><span>Tidak Dicairkan:</span><span className="font-mono">{formatRupiah(tidakDicairkan.reduce((s, a) => s + a.nominal, 0))} ({tidakDicairkan.length} item)</span></div>
            </div>
        </div>
    );
}

// ─── Bank Details Card ──────────────────────────────

function BankDetailsCard({ bankDetails }: { bankDetails: BankDetails }) {
    return (
        <div className="rounded border bg-muted/20 p-3 text-sm">
            <div className="grid gap-2 sm:grid-cols-2">
                <div>
                    <span className="text-xs text-muted-foreground">Nama Rekening</span>
                    <p className="font-medium">{bankDetails.nama_rekening || '—'}</p>
                </div>
                <div>
                    <span className="text-xs text-muted-foreground">Nomor Rekening</span>
                    <p className="font-mono font-medium">{bankDetails.nomor_rekening || '—'}</p>
                </div>
                {bankDetails.nama_bank && (
                    <div>
                        <span className="text-xs text-muted-foreground">Bank</span>
                        <p className="font-medium">{bankDetails.nama_bank}</p>
                    </div>
                )}
                <div>
                    <span className="text-xs text-muted-foreground">Referensi PP</span>
                    <p className="font-medium">{bankDetails.pp_label ? `${bankDetails.pp_label} Revisi ${bankDetails.pp_revision}` : '—'}</p>
                </div>
            </div>
        </div>
    );
}

// ─── Main Page ──────────────────────────────────────

export default function Pabd03({
    scope, mode, canApprove, canReject, canComment, workflow,
    pabd01ChecklistData, pabd01Submitter, pabd01Cycle,
    bankDetails, budgetCounter, stepStatuses,
    history, actionRoles, activeRoleName,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const isReadonly = mode === 'readonly';
    const stepStatus = stepStatuses['PABD03']?.status ?? 'active';

    const basePath = `/${scope}/workflows/pabd/${workflow.id}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'admin' ? 'Admin' : 'Tim', href: `/${scope}` },
        { title: 'Anggaran Bulanan', href: `/${scope}/workflows/pabd` },
        { title: `PABD-${workflow.team_name}-${workflow.bulan_label}/${workflow.tahun_anggaran}`, href: basePath },
        { title: 'PABD03: Persetujuan Transfer' },
    ];

    const commentUrl = `${basePath}/comment`;

    function handleAction(action: 'approve' | 'reject', notes?: string, files?: File[]) {
        if (processing) return;
        setProcessing(true);

        const url = `${basePath}/pabd03/${action}`;
        const data: Record<string, unknown> = {
            expected_updated_at: workflow.updated_at,
            notes: notes || null,
        };

        if (files && files.length > 0) {
            data.files = files;
        }

        router.post(url, data, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PABD03: Persetujuan Transfer Anggaran" />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <div className="flex items-center gap-3">
                    <Heading title="PABD03: Persetujuan Transfer Anggaran" description={`${workflow.team_name} — ${workflow.bulan_label} ${workflow.tahun_anggaran}`} />
                    <StepStatusBadge status={stepStatus} />
                </div>

                {/* Banners */}
                {isReadonly && stepStatus === 'completed' && (
                    <div className="flex items-center gap-2 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300">
                        <CheckCircle2 className="h-4 w-4" /> Transfer telah disetujui. Data hanya dapat dilihat.
                    </div>
                )}
                {isReadonly && stepStatus !== 'completed' && (
                    <div className="flex items-center gap-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300">
                        <Info className="h-4 w-4" /> Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName ?? undefined} />

                {/* History & Comments */}
                <HistoryCommentSection
                    entries={history}
                    commentUrl={commentUrl}
                    commentSource="pabd03"
                    canComment={canComment}
                    finalSteps={['PABD05']}
                />

                {/* Budget Reference */}
                <BudgetReferenceCard counter={budgetCounter} variant="readonly" />

                {/* PABD01 Checklist (readonly) */}
                <SectionCard
                    title="PABD01 — Checklist Pencairan"
                    headerRight={
                        pabd01Cycle > 1 ? <Badge variant="outline" className="text-[10px]">Pengisian ke-{pabd01Cycle}</Badge> : undefined
                    }
                >
                    {pabd01Submitter && (
                        <SubmitterLine label="Diisi oleh" name={pabd01Submitter.name} role={pabd01Submitter.role} team={pabd01Submitter.team} date={pabd01Submitter.at} />
                    )}
                    <div className="mt-2">
                        <AnggaranChecklistReadonly programs={pabd01ChecklistData} />
                    </div>
                </SectionCard>

                {/* Bank Details */}
                <SectionCard title="Informasi Rekening Tujuan">
                    <BankDetailsCard bankDetails={bankDetails} />
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canApprove || canReject) && (
                    <div className="flex justify-end gap-3">
                        {canReject && (
                            <ActionConfirmDialog
                                trigger={<Button variant="destructive" disabled={processing}>Tolak</Button>}
                                title="Tolak Transfer"
                                description="Transfer akan ditolak dan dikembalikan ke tim untuk review ulang checklist pencairan."
                                confirmLabel="Tolak"
                                variant="destructive"
                                requireNotes
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('reject', notes, files)}
                            />
                        )}
                        {canApprove && (
                            <ActionConfirmDialog
                                trigger={<Button disabled={processing}>Setujui</Button>}
                                title="Setujui Transfer"
                                description="Transfer akan disetujui dan dilanjutkan ke tahap upload bukti transfer (PABD04) oleh Kantor Pusat."
                                confirmLabel="Setujui"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('approve', notes, files)}
                            />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
