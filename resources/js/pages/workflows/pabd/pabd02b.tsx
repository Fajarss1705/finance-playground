import { Head, router } from '@inertiajs/react';
import { Info, CheckCircle2, ChevronDown, ChevronUp } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import KodeDiffDisplay from '@/components/workflow/kode-diff-display';
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

type ProposalAnggaran = {
    kode_bidang: string;
    kode_sub_bidang: string;
    kode_jenis: string;
    mata_anggaran: string;
    deskripsi_pk: string;
    nominal_anggaran: number;
};

type ProposalKuisioner = {
    kode_kuisioner: string | null;
    pertanyaan: string;
    tipe: string;
    satuan: string;
};

type ProposalKegiatan = {
    nama_kegiatan: string;
    bulan: number;
    anggaran: ProposalAnggaran[];
    kuisioner: ProposalKuisioner[];
};

type ProposalData = {
    kode_kategori: string;
    nama_program: string;
    deskripsi_program: string;
    tujuan_program: string;
    kegiatan: ProposalKegiatan[];
};

type ReviewItemFile = { id: number; name: string; url: string | null };

type ReviewItem = {
    id: number;
    pabd02b_item_review_id: number;
    tipe_perubahan: 'tarik_maju' | 'proposal_baru';
    pk04_anggaran_id: number | null;
    bulan_awal: number | null;
    bulan_tujuan: number | null;
    komentar: string | null;
    komentar_approval: string | null;
    files: ReviewItemFile[];
    anggaran_detail?: {
        program_name: string;
        kode_kategori: string;
        kegiatan_name: string;
        bulan: number;
        bulan_label: string;
        kode_anggaran_baru: string;
        mata_anggaran: string;
        nominal: number;
    };
    bulan_awal_label?: string;
    bulan_tujuan_label?: string;
    kode_preview?: { current_kode: string; preview_kode: string };
    proposal?: ProposalData;
};

type Submitter = { name: string; role: string; team: string; at: string } | null;

type Workflow = {
    id: number;
    uuid: string;
    team_name: string;
    bulan_anggaran: number;
    bulan_label: string;
    tahun_anggaran: number;
};

type Props = {
    scope: 'team' | 'admin';
    mode: 'edit' | 'readonly';
    canDraft: boolean;
    canApprove: boolean;
    canReject: boolean;
    canComment: boolean;
    canTerminate: boolean;
    workflow: Workflow;
    stepData: {
        id: number;
        updated_at: string;
        items: ReviewItem[];
    };
    cycle: number;
    previousCycles: { cycle: number; dataId: number }[];
    pabd01ChecklistData: ProgramGroup[];
    pabd01Submitter: Submitter;
    pabd01Cycle: number;
    pabd01PreviousCycles: { cycle: number; dataId: number }[];
    pabd02aSubmitter: Submitter;
    pabd02aCycle: number;
    pabd02aPreviousCycles: { cycle: number; dataId: number }[];
    tarikMajuCount: number;
    proposalBaruCount: number;
    budgetCounter: BudgetCounterData;
    stepStatuses: Record<string, { status: string; cycle?: number }>;
    stepperData: unknown;
    history: HistoryEntry[];
    actionRoles: ActionRole[];
    activeRoleName: string | null;
};

const BULAN_NAMES: Record<number, string> = {
    1: 'Januari', 2: 'Februari', 3: 'Maret', 4: 'April', 5: 'Mei', 6: 'Juni',
    7: 'Juli', 8: 'Agustus', 9: 'September', 10: 'Oktober', 11: 'November', 12: 'Desember',
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
                                <table className="w-full border-collapse border text-xs">
                                    <thead>
                                        <tr className="bg-muted/50 text-left text-muted-foreground">
                                            <th className="w-8 border p-1.5"></th>
                                            <th className="border p-1.5">Kode Anggaran</th>
                                            <th className="border p-1.5">Mata Anggaran</th>
                                            <th className="border p-1.5 text-right">Nominal (Rp)</th>
                                            <th className="border p-1.5">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {kegiatan.anggaran.map((a) => (
                                            <tr key={a.pk04_anggaran_id}>
                                                <td className="border p-1.5 text-center">{a.dicairkan ? <CheckCircle2 className="inline h-3.5 w-3.5 text-green-600" /> : <span className="text-muted-foreground">&times;</span>}</td>
                                                <td className="border p-1.5">{a.kode_anggaran_baru ? <KodeAnggaranFromString kode={a.kode_anggaran_baru} /> : '—'}</td>
                                                <td className="border p-1.5">{a.mata_anggaran}</td>
                                                <td className="border p-1.5 text-right font-mono">{formatRupiah(a.nominal)}</td>
                                                <td className="border p-1.5">{a.status_label && (
                                                    <Badge className={`text-[10px] ${
                                                        a.status_label.startsWith('Tarik Maju') || a.status_label.startsWith('Ditarik Maju')
                                                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300'
                                                            : 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300'
                                                    }`}>{a.status_label}</Badge>
                                                )}</td>
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

// ─── Proposal Readonly Section ──────────────────────

function ProposalReadonly({ proposal }: { proposal: ProposalData }) {
    const grandTotal = proposal.kegiatan.reduce((sum, k) => sum + k.anggaran.reduce((s, a) => s + a.nominal_anggaran, 0), 0);

    return (
        <div className="space-y-3 rounded border bg-muted/20 p-3">
            <div className="grid gap-2 sm:grid-cols-2 text-xs">
                <div><span className="text-muted-foreground">Kategori Pelayanan:</span> <span className="font-medium">{proposal.kode_kategori}</span></div>
                <div><span className="text-muted-foreground">Nama Program:</span> <span className="font-medium">{proposal.nama_program}</span></div>
                <div><span className="text-muted-foreground">Deskripsi:</span> <span>{proposal.deskripsi_program}</span></div>
                <div><span className="text-muted-foreground">Tujuan:</span> <span>{proposal.tujuan_program}</span></div>
            </div>
            {proposal.kegiatan.map((k, ki) => (
                <div key={ki} className="rounded border p-2">
                    <p className="text-xs font-semibold">{k.nama_kegiatan} &mdash; {BULAN_NAMES[k.bulan] || k.bulan}</p>
                    <div className="mt-1 overflow-x-auto">
                        <table className="w-full border-collapse border text-xs">
                            <thead>
                                <tr className="bg-muted/50 text-left text-muted-foreground">
                                    <th className="border p-1.5">Bidang</th>
                                    <th className="border p-1.5">Sub Bidang</th>
                                    <th className="border p-1.5">Jenis</th>
                                    <th className="border p-1.5">Mata Anggaran</th>
                                    <th className="border p-1.5 text-right">Nominal (Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                {k.anggaran.map((a, ai) => (
                                    <tr key={ai}>
                                        <td className="border p-1.5">{a.kode_bidang}</td>
                                        <td className="border p-1.5">{a.kode_sub_bidang}</td>
                                        <td className="border p-1.5">{a.kode_jenis}</td>
                                        <td className="border p-1.5">{a.mata_anggaran}</td>
                                        <td className="border p-1.5 text-right font-mono">{formatRupiah(a.nominal_anggaran)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-1 text-right text-xs font-medium">Total Kegiatan: {formatRupiah(k.anggaran.reduce((s, a) => s + a.nominal_anggaran, 0))}</p>
                    {k.kuisioner.length > 0 && (
                        <div className="mt-2">
                            <p className="text-[10px] font-semibold text-muted-foreground">Kuisioner</p>
                            <table className="mt-0.5 w-full border-collapse border text-xs">
                                <thead><tr className="bg-muted/50 text-left text-muted-foreground"><th className="border p-1.5">Pertanyaan</th><th className="border p-1.5">Tipe</th><th className="border p-1.5">Satuan</th><th className="border p-1.5">Sumber</th></tr></thead>
                                <tbody>
                                    {k.kuisioner.map((q, qi) => (
                                        <tr key={qi}>
                                            <td className="border p-1.5">{q.pertanyaan}</td>
                                            <td className="border p-1.5">{q.tipe}</td>
                                            <td className="border p-1.5">{q.satuan || '—'}</td>
                                            <td className="border p-1.5"><Badge variant="outline" className="text-[10px]">{q.kode_kuisioner ? 'Template PP' : 'Custom'}</Badge></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            ))}
            <p className="text-right text-xs font-semibold">Total Anggaran Proposal: {formatRupiah(grandTotal)}</p>
        </div>
    );
}

// ─── Main Page ──────────────────────────────────────

export default function Pabd02b({
    scope, mode, canDraft, canApprove, canReject, canComment, workflow,
    stepData, pabd01ChecklistData, pabd01Submitter, pabd01Cycle,
    pabd02aSubmitter, pabd02aCycle, tarikMajuCount, proposalBaruCount,
    budgetCounter, stepStatuses, history, actionRoles, activeRoleName,
}: Props) {
    const [processing, setProcessing] = useState(false);
    const [itemReviews, setItemReviews] = useState<Record<number, string>>(
        () => {
            const init: Record<number, string> = {};
            for (const item of stepData.items) {
                init[item.pabd02b_item_review_id] = item.komentar_approval || '';
            }
            return init;
        }
    );
    const [pabd01Open, setPabd01Open] = useState(false);

    const isReadonly = mode === 'readonly';
    const stepStatus = stepStatuses['PABD02B']?.status ?? 'active';

    const basePath = `/${scope}/workflows/pabd/${workflow.id}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'admin' ? 'Admin' : 'Tim', href: `/${scope}` },
        { title: 'Anggaran Bulanan', href: `/${scope}/workflows/pabd` },
        { title: `PABD-${workflow.team_name}-${workflow.bulan_label}/${workflow.tahun_anggaran}`, href: basePath },
        { title: 'PABD02B: Persetujuan Perubahan' },
    ];

    const commentUrl = `${basePath}/comment`;

    function buildFormItems() {
        return stepData.items.map(item => ({
            pabd02b_item_review_id: item.pabd02b_item_review_id,
            komentar_approval: itemReviews[item.pabd02b_item_review_id] || null,
        }));
    }

    function handleAction(action: 'draft' | 'approve' | 'reject', notes?: string, files?: File[]) {
        if (processing) return;
        setProcessing(true);

        const url = `${basePath}/pabd02b/${stepData.id}/${action}`;
        const data: Record<string, unknown> = {
            items: buildFormItems(),
            expected_updated_at: stepData.updated_at,
            notes: notes || null,
        };

        // Attach files via FormData
        if (files && files.length > 0) {
            (data as Record<string, unknown>).files = files;
        }

        router.post(url, data, {
            forceFormData: true,
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PABD02B: Persetujuan Perubahan Anggaran" />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6">
                <div className="flex items-center gap-3">
                    <Heading title="PABD02B: Persetujuan Perubahan Anggaran" description={`${workflow.team_name} — ${workflow.bulan_label} ${workflow.tahun_anggaran}`} />
                    <StepStatusBadge status={stepStatus} />
                </div>

                {/* Banners */}
                {isReadonly && stepStatus === 'completed' && (
                    <div className="flex items-center gap-2 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300">
                        <CheckCircle2 className="h-4 w-4" /> Step ini sudah selesai. Data hanya dapat dilihat.
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
                    commentSource="pabd02b"
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

                {/* PABD01 Checklist (readonly) */}
                <SectionCard
                    title={`PABD01 — Checklist Pencairan ${workflow.bulan_label} ${workflow.tahun_anggaran}`}
                    headerRight={
                        <div className="flex items-center gap-2">
                            {pabd01Cycle > 1 && <Badge variant="outline" className="text-[10px]">Pengisian ke-{pabd01Cycle}</Badge>}
                            <Button variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => setPabd01Open(!pabd01Open)}>
                                {pabd01Open ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                            </Button>
                        </div>
                    }
                >
                    {pabd01Open && (
                        <div className="space-y-3">
                            {pabd01Submitter && (
                                <SubmitterLine label="Diisi oleh" name={pabd01Submitter.name} role={pabd01Submitter.role} team={pabd01Submitter.team} date={pabd01Submitter.at} />
                            )}
                            <AnggaranChecklistReadonly programs={pabd01ChecklistData} />
                        </div>
                    )}
                    {!pabd01Open && (
                        <p className="text-xs text-muted-foreground">Klik tombol dropdown di kanan atas untuk melihat checklist pencairan PABD01.</p>
                    )}
                </SectionCard>

                {/* PABD02A Items — Per-item Review */}
                <SectionCard
                    title={`PABD02A — Perubahan Anggaran`}
                    headerRight={
                        <div className="flex items-center gap-2">
                            <Badge variant="outline" className="text-[10px]">
                                {tarikMajuCount + proposalBaruCount} perubahan: {tarikMajuCount} Tarik Maju, {proposalBaruCount} Proposal
                            </Badge>
                            {pabd02aCycle > 1 && <Badge variant="outline" className="text-[10px]">Pengisian ke-{pabd02aCycle}</Badge>}
                        </div>
                    }
                >
                    {pabd02aSubmitter && (
                        <SubmitterLine label="Diajukan oleh" name={pabd02aSubmitter.name} role={pabd02aSubmitter.role} team={pabd02aSubmitter.team} date={pabd02aSubmitter.at} />
                    )}

                    <div className="mt-3 space-y-4">
                        {stepData.items.map((item, idx) => (
                            <div key={item.id} className="rounded border p-3">
                                {/* Item header */}
                                <div className="mb-2 flex items-center gap-2">
                                    <span className="text-xs font-semibold text-muted-foreground">Item {idx + 1}</span>
                                    <Badge variant={item.tipe_perubahan === 'tarik_maju' ? 'secondary' : 'default'} className="text-[10px]">
                                        {item.tipe_perubahan === 'tarik_maju' ? 'Tarik Anggaran Maju' : 'Proposal Baru'}
                                    </Badge>
                                    {item.tipe_perubahan === 'proposal_baru' && (
                                        <Badge variant="outline" className="text-[10px]">Di Luar Plafon</Badge>
                                    )}
                                </div>

                                {/* Tarik Maju detail */}
                                {item.tipe_perubahan === 'tarik_maju' && item.anggaran_detail && (
                                    <div className="mb-3 space-y-2 text-xs">
                                        <div className="rounded border bg-muted/20 p-2">
                                            <p className="font-medium">Anggaran Sumber:</p>
                                            <div className="ml-2 mt-1 space-y-0.5 text-muted-foreground">
                                                <p>Program: {item.anggaran_detail.program_name} ({item.anggaran_detail.kode_kategori})</p>
                                                <p>Kegiatan: {item.anggaran_detail.kegiatan_name} &mdash; {item.anggaran_detail.bulan_label}</p>
                                                <p>Mata Anggaran: {item.anggaran_detail.mata_anggaran}</p>
                                                <p>Nominal: <span className="font-mono font-medium text-foreground">{formatRupiah(item.anggaran_detail.nominal)}</span></p>
                                            </div>
                                        </div>
                                        <p>Perpindahan Bulan: <span className="font-medium">{item.bulan_awal_label} &rarr; {item.bulan_tujuan_label}</span></p>
                                        {item.kode_preview && (
                                            <KodeDiffDisplay currentKode={item.kode_preview.current_kode} previewKode={item.kode_preview.preview_kode} />
                                        )}
                                    </div>
                                )}

                                {/* Proposal Baru detail */}
                                {item.tipe_perubahan === 'proposal_baru' && item.proposal && (
                                    <div className="mb-3">
                                        <ProposalReadonly proposal={item.proposal} />
                                    </div>
                                )}

                                {/* Team's reason */}
                                <div className="mb-3 text-xs">
                                    <p className="font-medium">Alasan Tim:</p>
                                    <p className="mt-0.5 whitespace-pre-line text-muted-foreground">{item.komentar || '—'}</p>
                                </div>

                                {/* Files */}
                                {item.files.length > 0 && (
                                    <div className="mb-3 text-xs">
                                        <p className="mb-1 font-medium">Lampiran:</p>
                                        <div className="flex flex-wrap gap-1">
                                            {item.files.map((f) => (
                                                <a key={f.id} href={f.url ?? '#'} target="_blank" rel="noopener noreferrer"
                                                    className={`rounded px-2 py-0.5 text-[11px] ${f.url ? 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/30 dark:text-blue-300' : 'bg-muted text-muted-foreground'}`}>
                                                    {f.name}
                                                </a>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* BU Review — komentar_approval */}
                                <div className="border-t pt-2">
                                    <Label className="text-xs font-medium">Catatan Persetujuan (BU)</Label>
                                    <Textarea
                                        className="mt-1 resize-y text-xs"
                                        rows={2}
                                        placeholder="Catatan opsional untuk item ini..."
                                        value={itemReviews[item.pabd02b_item_review_id] || ''}
                                        onChange={(e) => setItemReviews(prev => ({ ...prev, [item.pabd02b_item_review_id]: e.target.value }))}
                                        disabled={isReadonly}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </SectionCard>

                {/* Actions */}
                {!isReadonly && (canDraft || canApprove || canReject) && (
                    <div className="flex justify-end gap-3">
                        {canDraft && (
                            <ActionConfirmDialog
                                trigger={<Button variant="secondary" disabled={processing}>Simpan Draft</Button>}
                                title="Simpan Draft"
                                description="Catatan per-item akan disimpan. Anda dapat melanjutkan review nanti."
                                confirmLabel="Simpan Draft"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('draft', notes, files)}
                            />
                        )}
                        {canApprove && (
                            <ActionConfirmDialog
                                trigger={<Button disabled={processing}>Setujui</Button>}
                                title="Setujui Perubahan Anggaran"
                                description="Semua perubahan akan disetujui. PK04 akan di-recompile (tarik maju) dan proposal akan dikompilasi. Checklist pencairan baru akan dibuat."
                                confirmLabel="Setujui"
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('approve', notes, files)}
                            />
                        )}
                        {canReject && (
                            <ActionConfirmDialog
                                trigger={<Button variant="destructive" disabled={processing}>Tolak</Button>}
                                title="Tolak Perubahan Anggaran"
                                description="Semua perubahan akan ditolak. Tidak ada perubahan pada PK04. Proposal baru akan dihapus. Checklist pencairan baru akan dibuat."
                                confirmLabel="Tolak"
                                variant="destructive"
                                requireNotes
                                processing={processing}
                                onConfirm={({ notes, files }) => handleAction('reject', notes, files)}
                            />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
