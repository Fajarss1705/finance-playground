import { Head, usePage, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import ActionConfirmDialog from '@/components/workflow/action-confirm-dialog';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type KodeItem = { kode: string; nama: string; catatan: string | null };
type KuisionerItem = { kode: string; pertanyaan: string; tipe: string; satuan: string | null };
type PlafonItem = { team_id: number; kode_team: string; plafon_anggaran: number; nama_bank: string; nama_rekening: string; nomor_rekening: string; catatan: string | null; team?: { name: string } };
type RekeningOrganisasiItem = { nama_bank: string; nama_rekening: string; nomor_rekening: string; catatan: string | null };

type ReviewData = {
    pp01: {
        tahun: number;
        tanggal_mulai_pra_raker: string;
        tanggal_penetapan_program: string;
        kode_bidang_pelayanan: KodeItem[];
        kode_sub_bidang_pelayanan: KodeItem[];
        kode_kategori_pelayanan: KodeItem[];
        kode_jenis_program: KodeItem[];
    } | null;
    pp02: { item_kuisioner: KuisionerItem[] } | null;
    pp03: { item_plafon_anggaran: PlafonItem[]; rekening_organisasi: RekeningOrganisasiItem[] } | null;
    pp04: { item_dokumen: { file: { uuid: string; original_filename: string } }[] } | null;
};

type SubmitterInfo = {
    name: string;
    role: string | null;
    team: string | null;
    at: string;
};

type Workflow = { id: number; label: string; history: HistoryEntry[] };

type Props = {
    workflow: Workflow;
    reviewData: ReviewData;
    submitters: Record<string, SubmitterInfo>;
    stepStatus: string;
    canApprove: boolean;
    canReject: boolean;
    canTerminate: boolean;
    canComment: boolean;
    actionRoles: ActionRole[];
    activeRoleName: string | null;
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

function formatTanggal(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

export default function Pp05({ workflow, reviewData, submitters, stepStatus, canApprove, canReject, canTerminate, canComment, actionRoles, activeRoleName }: Props) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const isActive = canApprove || canReject;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: '/admin' },
        { title: 'Perencanaan Periode', href: '/admin/workflows/pp' },
        { title: workflow.label, href: `/admin/workflows/pp/${workflow.id}` },
        { title: 'PP05: Persetujuan', href: '#' },
    ];

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PP05') return `/admin/workflows/pp/${workflow.id}/pp05`;
        if (step === 'PP06') return `/admin/workflows/pp/${workflow.id}/pp06${entry.revision !== undefined ? `?revision=${entry.revision}` : ''}`;
        if (entry.id && entry.table) return `/admin/workflows/pp/${workflow.id}/${step.toLowerCase()}/${entry.id}`;
        return null;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PP05: Persetujuan — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Heading title="PP05: Persetujuan" description="Review dan setujui / tolak perencanaan periode" />
                    <ApprovalStatusBadge stepStatus={stepStatus} />
                </div>

                {(errors.approve || errors.reject) && (
                    <AlertError errors={[errors.approve || errors.reject]} title="Gagal" />
                )}

                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={`/admin/workflows/pp/${workflow.id}/comment`}
                    commentSource="pp05"
                    canComment={canComment}
                    finalSteps={['PP06']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* PP01 Review */}
                {reviewData.pp01 && (
                    <SectionCard title="PP01 — Rencana Periode">
                        <SubmittedBy info={submitters.PP01} />
                        <div className="mt-4 space-y-1.5 text-sm">
                            <div><span className="text-muted-foreground">Tahun:</span> {reviewData.pp01.tahun}</div>
                            <div><span className="text-muted-foreground">Mulai Pra-Raker:</span> {formatTanggal(reviewData.pp01.tanggal_mulai_pra_raker)}</div>
                            <div><span className="text-muted-foreground">Penetapan Program:</span> {formatTanggal(reviewData.pp01.tanggal_penetapan_program)}</div>
                        </div>
                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            <KodeList title="Bidang Pelayanan" items={reviewData.pp01.kode_bidang_pelayanan} />
                            <KodeList title="Sub Bidang" items={reviewData.pp01.kode_sub_bidang_pelayanan} />
                            <KodeList title="Kategori" items={reviewData.pp01.kode_kategori_pelayanan} />
                            <KodeList title="Jenis Program" items={reviewData.pp01.kode_jenis_program} />
                        </div>
                    </SectionCard>
                )}

                {/* PP02 Review */}
                {reviewData.pp02 && (
                    <SectionCard title="PP02 — Pertanyaan Kuisioner">
                        <SubmittedBy info={submitters.PP02} />
                        <div className="-mx-4 mt-2 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                            <table className="min-w-150 w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-3 py-2 text-left font-medium w-20">Kode</th>
                                        <th className="px-3 py-2 text-left font-medium">Pertanyaan</th>
                                        <th className="px-3 py-2 text-left font-medium w-36">Tipe</th>
                                        <th className="px-3 py-2 text-left font-medium w-28">Satuan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reviewData.pp02.item_kuisioner.map((item, i) => (
                                        <tr key={i} className="border-b last:border-0">
                                            <td className="px-3 py-2 font-mono text-xs">{item.kode}</td>
                                            <td className="px-3 py-2">{item.pertanyaan}</td>
                                            <td className="px-3 py-2">{item.tipe}</td>
                                            <td className="px-3 py-2 text-muted-foreground">{item.satuan || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </SectionCard>
                )}

                {/* PP03 Review */}
                {reviewData.pp03 && (
                    <SectionCard title="PP03 — Plafon Anggaran & Rekening">
                        <SubmittedBy info={submitters.PP03} />
                        <div className="-mx-4 mt-2 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                            <table className="min-w-200 w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-3 py-2 text-left font-medium w-20">Kode</th>
                                        <th className="px-3 py-2 text-left font-medium">Tim</th>
                                        <th className="px-3 py-2 text-right font-medium w-36">Plafon</th>
                                        <th className="px-3 py-2 text-left font-medium w-28">Bank</th>
                                        <th className="px-3 py-2 text-left font-medium w-32">Nama Rek.</th>
                                        <th className="px-3 py-2 text-left font-medium w-28">No. Rek.</th>
                                        <th className="px-3 py-2 text-left font-medium w-40">Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reviewData.pp03.item_plafon_anggaran.map((item, i) => (
                                        <tr key={i} className="border-b last:border-0">
                                            <td className="px-3 py-2 font-mono text-xs">{item.kode_team}</td>
                                            <td className="px-3 py-2">{item.team?.name ?? `Tim #${item.team_id}`}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{formatRupiah(Number(item.plafon_anggaran))}</td>
                                            <td className="px-3 py-2">{item.nama_bank}</td>
                                            <td className="px-3 py-2">{item.nama_rekening}</td>
                                            <td className="px-3 py-2">{item.nomor_rekening}</td>
                                            <td className="px-3 py-2 text-muted-foreground">{item.catatan || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t bg-muted/30 font-medium">
                                        <td className="px-3 py-2" colSpan={2}>Total Plafon</td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {formatRupiah(reviewData.pp03.item_plafon_anggaran.reduce((sum, item) => sum + Number(item.plafon_anggaran), 0))}
                                        </td>
                                        <td colSpan={4} />
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        {reviewData.pp03.rekening_organisasi.length > 0 && (
                            <div className="-mx-4 mt-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                                <h4 className="mb-1 text-xs font-medium text-muted-foreground">Rekening Organisasi untuk Refund</h4>
                                <table className="min-w-150 w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/50">
                                            <th className="px-3 py-2 text-left font-medium w-40">Nama Bank</th>
                                            <th className="px-3 py-2 text-left font-medium w-56">Nama Rekening</th>
                                            <th className="px-3 py-2 text-left font-medium w-44">Nomor Rekening</th>
                                            <th className="px-3 py-2 text-left font-medium">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {reviewData.pp03.rekening_organisasi.map((item, i) => (
                                            <tr key={i} className="border-b last:border-0">
                                                <td className="px-3 py-2">{item.nama_bank}</td>
                                                <td className="px-3 py-2">{item.nama_rekening}</td>
                                                <td className="px-3 py-2">{item.nomor_rekening}</td>
                                                <td className="px-3 py-2 text-muted-foreground">{item.catatan || '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </SectionCard>
                )}

                {/* PP04 Review */}
                {reviewData.pp04 && (
                    <SectionCard title="PP04 — Dokumen SOP">
                        <SubmittedBy info={submitters.PP04} />
                        {reviewData.pp04.item_dokumen.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Tidak ada dokumen dilampirkan.</p>
                        ) : (
                            <ul className="space-y-1.5 text-sm">
                                {reviewData.pp04.item_dokumen.map((dok, i) => (
                                    <li key={i}>
                                        <a
                                            href={`/files/${dok.file.uuid}/download`}
                                            className="inline-flex items-center gap-1.5 rounded border border-blue-300 bg-blue-50 px-2.5 py-1 text-xs text-blue-700 underline decoration-blue-300 hover:bg-blue-100 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900"
                                        >
                                            <Download className="h-3 w-3 shrink-0" />
                                            {dok.file.original_filename}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                )}

                {/* Action Buttons */}
                {(isActive || canTerminate) && (
                    <div className="flex gap-2">
                        {canApprove && (
                            <ApproveButton workflowId={workflow.id} />
                        )}
                        {canReject && (
                            <RejectButton workflowId={workflow.id} />
                        )}
                        {canTerminate && (
                            <TerminateButton workflowId={workflow.id} />
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function ApprovalStatusBadge({ stepStatus }: { stepStatus: string }) {
    if (stepStatus === 'active') {
        return <Badge className="bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300">Menunggu Keputusan</Badge>;
    }
    if (stepStatus === 'completed') {
        return <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Sudah Diputuskan</Badge>;
    }
    return <Badge className="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">Belum Aktif</Badge>;
}

function ApproveButton({ workflowId }: { workflowId: number }) {
    const [processing, setProcessing] = useState(false);
    return (
        <ActionConfirmDialog
            trigger={<Button>Setujui</Button>}
            title="Setujui Perencanaan Periode"
            description="Data PP01-PP04 akan dikompilasi ke Periode Tahunan (PP06)."
            confirmLabel="Setujui"
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`/admin/workflows/pp/${workflowId}/pp05/approve`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function RejectButton({ workflowId }: { workflowId: number }) {
    const [processing, setProcessing] = useState(false);
    return (
        <ActionConfirmDialog
            trigger={
                <Button variant="destructive">Tolak</Button>
            }
            title="Tolak Perencanaan Periode"
            description="Flow akan kembali ke PP01. Semua step PP01-PP04 perlu diisi ulang."
            confirmLabel="Tolak"
            variant="destructive"
            requireNotes
            processing={processing}
            onConfirm={({ notes, files }) => {
                setProcessing(true);
                router.post(`/admin/workflows/pp/${workflowId}/pp05/reject`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function TerminateButton({ workflowId }: { workflowId: number }) {
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
                router.post(`/admin/workflows/pp/${workflowId}/terminate`, { notes, ...(files.length > 0 ? { files } : {}) }, {
                    forceFormData: files.length > 0,
                    onFinish: () => setProcessing(false),
                });
            }}
        />
    );
}

function SubmittedBy({ info }: { info?: SubmitterInfo }) {
    if (!info) return null;
    const parts = [info.name];
    if (info.role) parts.push(info.role);
    if (info.team) parts.push(info.team);
    return (
        <p className="text-xs text-muted-foreground">
            Disubmit oleh: <span className="font-medium text-foreground">{parts.join(' · ')}</span> — {info.at}
        </p>
    );
}

function KodeList({ title, items }: { title: string; items: KodeItem[] }) {
    return (
        <div>
            <h4 className="mb-1 text-xs font-medium text-muted-foreground">{title} ({items.length})</h4>
            <div className="space-y-0.5">
                {items.map((item, i) => (
                    <div key={i} className="text-xs">
                        <span className="font-mono text-muted-foreground">{item.kode}</span> — {item.nama}
                        {item.catatan && (
                            <>
                                {' · '}
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span className="inline-block max-w-40 cursor-help truncate align-bottom text-muted-foreground">{item.catatan}</span>
                                    </TooltipTrigger>
                                    <TooltipContent>{item.catatan}</TooltipContent>
                                </Tooltip>
                            </>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
