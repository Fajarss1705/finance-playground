import { Head, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import Pk01ReadonlySection from './_pk01-readonly-section';
import type { Pk01ReadonlyData, PreviousCycle, Pk01Change, ParallelTrackStatus, KodeAnggaranContext } from './_pk01-readonly-section';


type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    tipe: string;
};

type Props = {
    workflow: Workflow;
    teamName: string;
    pk01Data: Pk01ReadonlyData | null;
    previousCycles: PreviousCycle[];
    pk01Changes: Pk01Change[] | null;
    pp06RevisionLabel: string | null;
    kodeAnggaranContext: KodeAnggaranContext;
    parallelTrackStatus: ParallelTrackStatus;
    stepStatus: string;
    canApprove: boolean;
    canReject: boolean;
    canTerminate: boolean;
    canComment: boolean;
    budgetCounter: BudgetCounterData & { pkIni: number };
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    scope: string;
    basePath: string;
};

export default function Pk02a({
    workflow,
    teamName,
    pk01Data,
    previousCycles,
    pk01Changes,
    pp06RevisionLabel,
    kodeAnggaranContext,
    parallelTrackStatus,
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
            { title: 'PK02A: Approval Narasi', href: '#' },
        ]
        : [
            { title: 'Manajemen', href: '/admin' },
            { title: 'Perencanaan Kegiatan', href: '/admin/workflows/pk' },
            { title: workflow.label, href: `${basePath}` },
            { title: 'PK02A: Approval Narasi', href: '#' },
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
            <Head title={`PK02A: Approval Narasi — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div>
                    <div className="flex items-center gap-3">
                        <Heading title="PK02A: Approval Narasi" description="Review narasi dan konten program kegiatan" />
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

                {/* Parallel track status */}
                <ParallelTrackIndicator status={parallelTrackStatus} />

                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`${basePath}/comment`}
                    commentSource="pk02a"
                    canComment={canComment}
                    finalSteps={['PK04']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* Budget Counter */}
                <BudgetReferenceCard counter={budgetCounter} variant="approval" />

                {/* PK01 Read-only Display */}
                <Pk01ReadonlySection
                    pk01Data={pk01Data}
                    previousCycles={previousCycles}
                    pk01Changes={pk01Changes}
                    pp06RevisionLabel={pp06RevisionLabel}
                    kodeAnggaranContext={kodeAnggaranContext}
                />

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

function ParallelTrackIndicator({ status }: { status: ParallelTrackStatus }) {
    const statusLabels: Record<string, { text: string; className: string }> = {
        pending: { text: 'Menunggu keputusan', className: 'text-slate-600 dark:text-slate-400' },
        active: { text: 'Menunggu keputusan', className: 'text-slate-600 dark:text-slate-400' },
        approved: { text: 'Disetujui', className: 'text-green-600 dark:text-green-400' },
        rejected: { text: 'Ditolak', className: 'text-red-600 dark:text-red-400' },
    };

    const info = statusLabels[status.status] ?? statusLabels.pending;

    return (
        <div className="rounded-md border bg-muted/30 p-3 text-sm">
            <span className="text-muted-foreground">{status.step} ({status.label}):</span>{' '}
            <span className={info.className}>{info.text}</span>
        </div>
    );
}

function ApproveButton({ basePath }: { basePath: string }) {
    const [processing, setProcessing] = useState(false);
    return (
        <ActionConfirmDialog
            trigger={<Button>Setujui</Button>}
            title="Setujui Narasi Program Kegiatan"
            description="Narasi dan konten program kegiatan akan disetujui."
            confirmLabel="Setujui"
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`${basePath}/pk02a/approve`, { notes, ...(files.length > 0 ? { files } : {}) }, {
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
            title="Tolak Narasi Program Kegiatan"
            description="Program kegiatan akan dikembalikan ke tim untuk perbaikan setelah kedua track selesai."
            confirmLabel="Tolak"
            variant="destructive"
            requireNotes
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`${basePath}/pk02a/reject`, { notes, ...(files.length > 0 ? { files } : {}) }, {
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
