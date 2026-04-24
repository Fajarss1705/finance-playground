import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Info, CheckCircle2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
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

type StepData = {
    id: number;
    ada_perubahan: boolean;
    items: { id: number; pk04_anggaran_id: number; dicairkan: boolean }[];
    updated_at: string;
};

type CycleBackNotes = {
    step: string;
    step_label: string;
    action: string;
    notes: string | null;
    by_name: string | null;
    role_name: string | null;
    team_name: string | null;
    at: string | null;
    files: number[] | null;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    history: HistoryEntry[];
    stepper_cycles: unknown[];
};

type WorkflowMeta = {
    bulan_anggaran: number;
    bulan_label: string;
    tahun_anggaran: number;
    team_name: string;
};

type Props = {
    workflow: Workflow;
    stepData: StepData;
    anggaranItems: ProgramGroup[];
    budgetCounter: BudgetCounterData;
    ppLabel: string | null;
    workflowMeta: WorkflowMeta;
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canSubmit: boolean;
    canComment: boolean;
    isReentry: boolean;
    cycleBackNotes: CycleBackNotes | null;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    teamName: string;
    scope: 'team' | 'admin';
    basePath: string;
};

// ─── Status badges ───────────────────────────────────

function StepStatusBadge({ mode, scope }: { mode: string; scope: string }) {
    if (mode === 'readonly' && scope === 'admin') {
        return <Badge className="bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">Admin View</Badge>;
    }
    if (mode === 'readonly') {
        return <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Sudah Disubmit</Badge>;
    }
    if (mode === 'edit') {
        return <Badge className="bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300">Draft</Badge>;
    }
    return <Badge className="bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">Menunggu Diisi</Badge>;
}

// ─── Main component ──────────────────────────────────

export default function Pabd01({
    workflow,
    stepData,
    anggaranItems,
    budgetCounter,
    ppLabel,
    workflowMeta,
    mode,
    canDraft,
    canSubmit,
    canComment,
    isReentry,
    cycleBackNotes,
    actionRoles,
    activeRoleName,
    teamName,
    scope,
    basePath,
}: Props) {
    const [processing, setProcessing] = useState(false);

    // Local state for checkbox toggles
    const [checkedMap, setCheckedMap] = useState<Record<number, boolean>>(() => {
        const map: Record<number, boolean> = {};
        for (const item of stepData.items) {
            map[item.id] = item.dicairkan;
        }
        return map;
    });

    // Local state for ada_perubahan
    const [adaPerubahan, setAdaPerubahan] = useState(stepData.ada_perubahan);

    const isReadonly = mode === 'readonly' || (!canDraft && !canSubmit);

    // Build breadcrumbs
    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'team' ? 'Tim' : 'Admin', href: `/${scope}` },
        { title: 'Anggaran Bulanan', href: `/${scope}/workflows/pabd` },
        { title: workflow.label, href: `${basePath}` },
        { title: 'PABD01: Checklist Pencairan', href: '#' },
    ];

    // Live summary calculations
    const summary = useMemo(() => {
        let totalNominal = 0;
        let dicairkanNominal = 0;
        let dicairkanCount = 0;
        let tidakDicairkanNominal = 0;
        let tidakDicairkanCount = 0;
        let totalCount = 0;

        for (const program of anggaranItems) {
            for (const kegiatan of program.kegiatan) {
                for (const item of kegiatan.anggaran) {
                    totalNominal += item.nominal;
                    totalCount++;
                    if (checkedMap[item.pabd01_item_id]) {
                        dicairkanNominal += item.nominal;
                        dicairkanCount++;
                    } else {
                        tidakDicairkanNominal += item.nominal;
                        tidakDicairkanCount++;
                    }
                }
            }
        }

        return { totalNominal, totalCount, dicairkanNominal, dicairkanCount, tidakDicairkanNominal, tidakDicairkanCount };
    }, [anggaranItems, checkedMap]);

    // Select all / Deselect all
    const allChecked = summary.dicairkanCount === summary.totalCount && summary.totalCount > 0;
    function toggleSelectAll() {
        const newVal = !allChecked;
        setCheckedMap((prev) => {
            const next = { ...prev };
            for (const key of Object.keys(next)) {
                next[Number(key)] = newVal;
            }
            return next;
        });
    }

    // Build form data for submit/draft
    function buildFormData(notes: string, files: File[]) {
        const items = stepData.items.map((item) => ({
            pabd01_item_anggaran_id: item.id,
            dicairkan: checkedMap[item.id] ?? false,
        }));

        const data: Record<string, unknown> = {
            ada_perubahan: adaPerubahan,
            items,
            expected_updated_at: stepData.updated_at,
            notes: notes || undefined,
        };

        if (files.length > 0) {
            (data as Record<string, unknown>)['files'] = files;
        }

        return data;
    }

    function handleAction(action: 'draft' | 'submit', notes: string, files: File[]) {
        setProcessing(true);
        const url = `${basePath}/pabd01/${stepData.id}/${action}`;
        router.post(url, buildFormData(notes, files) as Record<string, unknown>, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    const hasNoItems = summary.totalCount === 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PABD01: Checklist Pencairan — ${workflowMeta.team_name}`} />
            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <Heading title="PABD01: Checklist Pencairan Anggaran" />
                            <StepStatusBadge mode={mode} scope={scope} />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {workflowMeta.bulan_label} {workflowMeta.tahun_anggaran} — {teamName}
                        </p>
                    </div>
                </div>

                {/* Banners */}
                {isReentry && cycleBackNotes && (
                    <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div className="space-y-1 text-sm">
                            <p className="font-medium text-amber-800 dark:text-amber-200">
                                {cycleBackNotes.action === 'approved'
                                    ? 'Perubahan anggaran telah disetujui dan PK04 telah diperbarui. Silakan review ulang checklist pencairan dengan data anggaran terbaru.'
                                    : cycleBackNotes.step === 'PABD03'
                                      ? 'Persetujuan transfer ditolak. Silakan review ulang checklist pencairan.'
                                      : 'Perubahan anggaran ditolak. Silakan review ulang checklist pencairan.'}
                            </p>
                            {cycleBackNotes.by_name && (
                                <p className="text-amber-700 dark:text-amber-300">
                                    Oleh: {cycleBackNotes.by_name}
                                    {cycleBackNotes.role_name && ` (${cycleBackNotes.role_name}`}
                                    {cycleBackNotes.team_name && ` \u00B7 ${cycleBackNotes.team_name}`}
                                    {cycleBackNotes.role_name && ')'}
                                    {cycleBackNotes.at && ` — ${cycleBackNotes.at}`}
                                </p>
                            )}
                            {!cycleBackNotes.by_name && cycleBackNotes.at && (
                                <p className="text-xs text-amber-600 dark:text-amber-400">— {cycleBackNotes.at}</p>
                            )}
                            {cycleBackNotes.notes && (
                                <p className="whitespace-pre-line text-amber-700 dark:text-amber-300">
                                    &ldquo;{cycleBackNotes.notes}&rdquo;
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {mode === 'readonly' && scope !== 'admin' && (
                    <div className="flex gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950">
                        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-green-600" />
                        <p className="text-sm text-green-800 dark:text-green-200">
                            Step ini sudah selesai disubmit. Data hanya dapat dilihat.
                        </p>
                    </div>
                )}

                {mode !== 'readonly' && !canDraft && !canSubmit && (
                    <div className="flex gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
                        <Info className="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                        <p className="text-sm text-blue-800 dark:text-blue-200">
                            Anda hanya dapat melihat data pada step ini.
                        </p>
                    </div>
                )}

                {scope === 'admin' && (
                    <div className="flex gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
                        <Info className="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                        <p className="text-sm text-blue-800 dark:text-blue-200">
                            Anda hanya dapat melihat data pada step ini.
                        </p>
                    </div>
                )}

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                {/* History & Comments */}
                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`${basePath}/comment`}
                    commentSource="pabd01"
                    canComment={canComment}
                    finalSteps={['PABD05']}
                    stepUrlResolver={(entry: HistoryEntry) => {
                        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
                        const step = entry.step;
                        if (step === 'PABD01' && entry.id) return `${basePath}/pabd01/${entry.id}`;
                        if (step === 'PABD02A' && entry.id) return `${basePath}/pabd02a/${entry.id}`;
                        if (step === 'PABD02B' && entry.id) return `${basePath}/pabd02b/${entry.id}`;
                        if (step === 'PABD03') return `${basePath}/pabd03`;
                        if (step === 'PABD04' && entry.id) return `${basePath}/pabd04/${entry.id}`;
                        if (step === 'PABD05') return `${basePath}/pabd05`;
                        return null;
                    }}
                />

                {/* Budget Reference */}
                <BudgetReferenceCard counter={budgetCounter} variant="readonly" />

                {/* Checklist */}
                <SectionCard
                    title="Checklist Anggaran"
                    headerRight={
                        !isReadonly ? (
                            <Button variant="ghost" size="sm" onClick={toggleSelectAll} className="text-xs">
                                {allChecked ? 'Deselect All' : 'Select All'}
                            </Button>
                        ) : undefined
                    }
                >
                    {hasNoItems ? (
                        <p className="text-sm text-muted-foreground">Tidak ada item anggaran untuk bulan ini.</p>
                    ) : (
                        <div className="space-y-6">
                            {anggaranItems.map((program) => (
                                <div key={program.program_id}>
                                    <h4 className="mb-2 text-sm font-semibold text-foreground">
                                        {program.program_name}{' '}
                                        <span className="text-muted-foreground">({program.kode_kategori})</span>
                                        {program.tipe === 'proposal' && (
                                            <Badge className="ml-2 bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">
                                                Di Luar Plafon
                                            </Badge>
                                        )}
                                    </h4>
                                    {program.kegiatan.map((kegiatan) => (
                                        <div key={kegiatan.kegiatan_id} className="mb-4 ml-2">
                                            <p className="mb-1 text-xs font-medium text-muted-foreground">
                                                {kegiatan.nama_kegiatan} — {kegiatan.bulan_label}
                                            </p>
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-sm">
                                                    <thead>
                                                        <tr className="border-b bg-muted/50">
                                                            <th className="w-10 px-3 py-2"></th>
                                                            <th className="px-3 py-2 text-left font-medium">Kode Anggaran</th>
                                                            <th className="px-3 py-2 text-left font-medium">Mata Anggaran</th>
                                                            <th className="px-3 py-2 text-right font-medium">Nominal (Rp)</th>
                                                            <th className="px-3 py-2 text-left font-medium">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {kegiatan.anggaran.map((item) => (
                                                            <tr key={item.pabd01_item_id} className="border-b last:border-0">
                                                                <td className="px-3 py-2 text-center">
                                                                    {isReadonly ? (
                                                                        <span className="text-sm">
                                                                            {checkedMap[item.pabd01_item_id] ? '✓' : '✗'}
                                                                        </span>
                                                                    ) : (
                                                                        <Checkbox
                                                                            checked={checkedMap[item.pabd01_item_id] ?? false}
                                                                            onCheckedChange={(val) =>
                                                                                setCheckedMap((prev) => ({
                                                                                    ...prev,
                                                                                    [item.pabd01_item_id]: !!val,
                                                                                }))
                                                                            }
                                                                        />
                                                                    )}
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    <KodeAnggaranFromString kode={item.kode_anggaran_baru} />
                                                                </td>
                                                                <td className="px-3 py-2">{item.mata_anggaran}</td>
                                                                <td className="px-3 py-2 text-right tabular-nums">
                                                                    {formatRupiah(item.nominal)}
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {item.status_label && (
                                                                        <Badge
                                                                            className={
                                                                                item.status_item === 'ditarik_maju'
                                                                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300'
                                                                                    : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300'
                                                                            }
                                                                        >
                                                                            {item.status_label}
                                                                        </Badge>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    )}
                </SectionCard>

                {/* Summary */}
                <SectionCard title="Ringkasan Pencairan">
                    <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        {ppLabel && (
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground">Referensi PP</dt>
                                <dd className="font-medium">{ppLabel}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-muted-foreground">Total Anggaran</dt>
                            <dd className="font-medium">
                                Rp {formatRupiah(summary.totalNominal)}{' '}
                                <span className="text-muted-foreground">({summary.totalCount} item)</span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Dicairkan</dt>
                            <dd className="font-medium text-green-700 dark:text-green-400">
                                Rp {formatRupiah(summary.dicairkanNominal)}{' '}
                                <span className="text-muted-foreground">({summary.dicairkanCount} item)</span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Tidak Dicairkan</dt>
                            <dd className="font-medium text-red-700 dark:text-red-400">
                                Rp {formatRupiah(summary.tidakDicairkanNominal)}{' '}
                                <span className="text-muted-foreground">({summary.tidakDicairkanCount} item)</span>
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                {/* Perubahan toggle */}
                <SectionCard title="Perubahan Anggaran">
                    <div className="space-y-3">
                        <label className="flex cursor-pointer items-center gap-3">
                            <input
                                type="radio"
                                name="perubahan"
                                checked={!adaPerubahan}
                                onChange={() => setAdaPerubahan(false)}
                                disabled={isReadonly}
                                className="h-4 w-4"
                            />
                            <div>
                                <p className="text-sm font-medium">Tidak ada perubahan</p>
                                <p className="text-xs text-muted-foreground">
                                    Langsung ke persetujuan transfer
                                </p>
                            </div>
                        </label>
                        <label className="flex cursor-pointer items-center gap-3">
                            <input
                                type="radio"
                                name="perubahan"
                                checked={adaPerubahan}
                                onChange={() => setAdaPerubahan(true)}
                                disabled={isReadonly}
                                className="h-4 w-4"
                            />
                            <div>
                                <p className="text-sm font-medium">Ada perubahan</p>
                                <p className="text-xs text-muted-foreground">
                                    Ajukan perubahan dulu (PABD02A) sebelum proses pencairan dilanjutkan
                                </p>
                            </div>
                        </label>
                    </div>
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canDraft || canSubmit) && (
                    <div className="flex justify-end gap-3">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button variant="secondary" disabled={processing}>
                                        Simpan Draft
                                    </Button>
                                }
                                title="Simpan Draft"
                                description="Data checklist pencairan akan disimpan sebagai draft."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canSubmit && (
                            <ActionConfirmDialog
                                trigger={
                                    <Button disabled={processing || hasNoItems}>Submit</Button>
                                }
                                title="Submit Checklist Pencairan"
                                description={
                                    adaPerubahan
                                        ? 'Checklist akan disubmit. Karena ada perubahan, Anda akan melanjutkan ke form Perubahan Anggaran (PABD02A).'
                                        : 'Checklist akan disubmit. Karena tidak ada perubahan, proses akan langsung ke Persetujuan Transfer (PABD03).'
                                }
                                confirmLabel="Submit"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('submit', notes, files)}
                            />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
