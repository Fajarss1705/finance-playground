import { Head, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import Pk01ReadonlySection from './_pk01-readonly-section';
import type { Pk01ReadonlyData, PreviousCycle, Pk01Change, KodeAnggaranContext } from './_pk01-readonly-section';

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    tipe: string;
};

type ParallelApproval = {
    step: string;
    label: string;
    by_name: string | null;
    role_name: string | null;
    at: string | null;
};

type BudgetCounter = {
    ppLabel: string | null;
    plafon: number;
    accepted: number;
    planned: number;
    sisa: number;
    proposalAccepted: number;
    proposalPlanned: number;
    pkIni: number;
};

type Props = {
    workflow: Workflow;
    teamName: string;
    pk01Data: Pk01ReadonlyData | null;
    previousCycles: PreviousCycle[];
    pk01Changes: Pk01Change[] | null;
    pp06RevisionLabel: string | null;
    kodeAnggaranContext: KodeAnggaranContext;
    parallelApprovals: ParallelApproval[];
    stepStatus: string;
    canApprove: boolean;
    canReject: boolean;
    canTerminate: boolean;
    canComment: boolean;
    budgetCounter: BudgetCounter;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    scope: string;
    basePath: string;
};

function formatTanggal(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function Pk03({
    workflow,
    teamName,
    pk01Data,
    previousCycles,
    pk01Changes,
    pp06RevisionLabel,
    kodeAnggaranContext,
    parallelApprovals,
    stepStatus,
    canApprove,
    canReject,
    canTerminate,
    canComment,
    budgetCounter,
    actionRoles,
    activeRoleName,
    scope,
    basePath,
}: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isActive = stepStatus === 'active';
    const isCompleted = stepStatus === 'approved' || stepStatus === 'rejected';
    const isReadonly = !canApprove && !canReject;

    const breadcrumbs: BreadcrumbItem[] = scope === 'team'
        ? [
            { title: 'Tim', href: '/team' },
            { title: 'Perencanaan Kegiatan', href: '/team/workflows/pk' },
            { title: workflow.label, href: `${basePath}` },
            { title: 'PK03: Approval RAKER', href: '#' },
        ]
        : [
            { title: 'Manajemen', href: '/admin' },
            { title: 'Perencanaan Kegiatan', href: '/admin/workflows/pk' },
            { title: workflow.label, href: `${basePath}` },
            { title: 'PK03: Approval RAKER', href: '#' },
        ];

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PK02A') return `${basePath}/pk02a`;
        if (step === 'PK02B') return `${basePath}/pk02b`;
        if (step === 'PK03') return `${basePath}/pk03`;
        if (entry.id && entry.table) return `${basePath}/${step.toLowerCase()}/${entry.id}`;
        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PK03: Approval RAKER — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div>
                    <div className="flex items-center gap-3">
                        <Heading title="PK03: Approval RAKER" description="Keputusan RAKER untuk program kegiatan" />
                        <StepStatusBadge status={stepStatus} />
                        {workflow.tipe === 'proposal' && (
                            <Badge className="bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">PK Proposal</Badge>
                        )}
                    </div>
                    <Badge variant="secondary" className="mt-1">{teamName}</Badge>
                </div>

                {(errors.approve || errors.reject) && (
                    <AlertError errors={[errors.approve || errors.reject]} title="Gagal" />
                )}

                {/* Read-only banner */}
                {isCompleted && (
                    <div className="rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-300">
                        Step ini sudah selesai. Data hanya dapat dilihat.
                    </div>
                )}
                {isActive && isReadonly && (
                    <div className="rounded-md border border-blue-300 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300">
                        Anda hanya dapat melihat data pada step ini.
                    </div>
                )}

                {/* Parallel approval status — shows both PK02A + PK02B as approved */}
                <ParallelApprovalStatus approvals={parallelApprovals} />

                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`${basePath}/comment`}
                    commentSource="pk03"
                    canComment={canComment}
                    finalSteps={['PK04']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* PK01 Read-only Display */}
                <Pk01ReadonlySection
                    pk01Data={pk01Data}
                    previousCycles={previousCycles}
                    pk01Changes={pk01Changes}
                    pp06RevisionLabel={pp06RevisionLabel}
                    kodeAnggaranContext={kodeAnggaranContext}
                />

                {/* Budget Counter */}
                <BudgetCounterSection counter={budgetCounter} />

                {/* Action Buttons */}
                {isActive && (canApprove || canReject || canTerminate) && (
                    <div className="flex gap-2">
                        {canApprove && (
                            <ApproveButton basePath={basePath} />
                        )}
                        {canReject && (
                            <RejectButton basePath={basePath} />
                        )}
                        {canTerminate && (
                            <TerminateButton basePath={basePath} />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function StepStatusBadge({ status }: { status: string }) {
    if (status === 'active') {
        return <Badge className="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">Menunggu Keputusan</Badge>;
    }
    if (status === 'rejected') {
        return <Badge className="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">Ditolak</Badge>;
    }
    return <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Disetujui</Badge>;
}

function ParallelApprovalStatus({ approvals }: { approvals: ParallelApproval[] }) {
    if (approvals.length === 0) return null;

    return (
        <SectionCard title="Status Approval Sebelumnya">
            <div className="space-y-2">
                {approvals.map((a) => (
                    <div key={a.step} className="flex items-start gap-2 text-sm">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-green-600 dark:text-green-400" />
                        <div>
                            <span className="font-medium">{a.step} ({a.label}):</span>{' '}
                            <span className="text-green-600 dark:text-green-400">Disetujui</span>
                            <div className="text-xs text-muted-foreground">
                                oleh {a.by_name ?? 'Unknown'} ({a.role_name ?? 'Unknown'})
                                {a.at && ` — ${formatTanggal(a.at)}`}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </SectionCard>
    );
}

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID').format(value);
}

function BudgetCounterSection({ counter }: { counter: BudgetCounter }) {
    const isOverBudget = counter.pkIni > counter.sisa && counter.sisa > 0;

    return (
        <SectionCard title="Referensi Anggaran">
            {counter.ppLabel && (
                <p className="mb-3 text-sm text-muted-foreground">Menggunakan data dari <Badge variant="secondary">{counter.ppLabel}</Badge></p>
            )}
            <div className="mt-4 space-y-4">
                <div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground uppercase tracking-wide">Dalam Plafon (Raker)</p>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <BudgetItem label="Plafon Tim" value={counter.plafon} />
                        <BudgetItem label="Sudah Ditetapkan (Raker)" value={counter.accepted} />
                        <BudgetItem label="Sedang Diajukan & Direview" value={counter.planned} />
                        <BudgetItem label="Sisa" value={counter.sisa} />
                    </div>
                </div>
                <div>
                    <p className="mb-2 text-xs font-medium text-muted-foreground uppercase tracking-wide">Di Luar Plafon (Proposal)</p>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <BudgetItem label="Sudah Ditetapkan (Proposal)" value={counter.proposalAccepted} />
                        <BudgetItem label="Sedang Diajukan & Direview" value={counter.proposalPlanned} />
                    </div>
                </div>
            </div>
            <div className="mt-3 flex items-center gap-2 border-t pt-3">
                <span className="text-sm text-muted-foreground">di antaranya, PK ini:</span>
                <span className={`text-sm font-semibold ${isOverBudget ? 'text-amber-600 dark:text-amber-400' : ''}`}>
                    Rp {formatRupiah(counter.pkIni)}
                </span>
            </div>
            {isOverBudget && (
                <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                    Total anggaran PK ini melebihi sisa plafon.
                </p>
            )}
        </SectionCard>
    );
}

function BudgetItem({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md border px-3 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-sm font-semibold">Rp {formatRupiah(value)}</p>
        </div>
    );
}

function ApproveButton({ basePath }: { basePath: string }) {
    const [processing, setProcessing] = useState(false);
    return (
        <ActionConfirmDialog
            trigger={<Button>Setujui</Button>}
            title="Setujui RAKER Program Kegiatan"
            description="Program kegiatan akan dilanjutkan ke kompilasi Program Tahunan (PK04)."
            confirmLabel="Setujui"
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`${basePath}/pk03/approve`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function RejectButton({ basePath }: { basePath: string }) {
    const [processing, setProcessing] = useState(false);
    return (
        <ActionConfirmDialog
            trigger={<Button variant="destructive">Tolak</Button>}
            title="Tolak RAKER Program Kegiatan"
            description="Program kegiatan akan dikembalikan ke tim untuk perbaikan."
            confirmLabel="Tolak"
            variant="destructive"
            requireNotes
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`${basePath}/pk03/reject`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function TerminateButton({ basePath }: { basePath: string }) {
    const [processing, setProcessing] = useState(false);
    return (
        <ActionConfirmDialog
            trigger={
                <Button variant="outline" className="ml-auto text-destructive border-destructive hover:bg-destructive/10">
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
                router.post(`${basePath}/terminate`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}
