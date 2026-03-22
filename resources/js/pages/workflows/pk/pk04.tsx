import { Head, router } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import BudgetReferenceCard from '@/components/workflow/budget-reference-card';
import type { BudgetCounterData } from '@/components/workflow/budget-reference-card';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type AnggaranItem = {
    id: number;
    kode_anggaran_baru: string | null;
    kode_anggaran_lama: string | null;
    kode_bidang: string | null;
    kode_sub_bidang: string | null;
    kode_jenis: string | null;
    mata_anggaran: string;
    deskripsi_pk: string | null;
    nominal_anggaran: number;
    nomer_anggaran: number;
    revisi_terakhir: number | null;
    status_item: string;
    source: string | null;
    is_locked?: boolean;
    lock_reason?: string | null;
};

type KuisionerItem = {
    id: number;
    kode_kuisioner: string | null;
    pertanyaan: string;
    tipe: string;
    satuan: string | null;
};

type KegiatanItem = {
    id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string | null;
    nomer_kegiatan: number;
    source: string | null;
    anggaran: AnggaranItem[];
    kuisioner: KuisionerItem[];
};

type Pk04Data = {
    id: number;
    revision: number;
    kode_kategori: string;
    nama_program: string;
    deskripsi_program: string | null;
    tujuan_program: string | null;
    nomer_program: number;
    verification_code: string | null;
    created_at: string;
    pk01_created_by_user_name: string;
    pk01_created_by_role_name: string | null;
    pk01_created_by_team_name: string | null;
    pk01_created_at: string | null;
    kegiatan: KegiatanItem[];
};

type Revision = { id: number; revision: number; nomer_program: number; created_at: string; verification_code: string | null };

type Workflow = { id: number; label: string; status: string; history: HistoryEntry[]; tipe: string };

type ChangeItem = { description: string };

type KodeRefMap = {
    bidang: Record<string, string>;
    subBidang: Record<string, string>;
    jenis: Record<string, string>;
    kategori: Record<string, string>;
};

type ApproverInfo = {
    by_name: string | null;
    role_name: string | null;
    at: string | null;
};

type Props = {
    workflow: Workflow;
    pk04Data: Pk04Data | null;
    allRevisions: Revision[];
    changelogByRevision: Record<number, ChangeItem[]>;
    budgetContext: (BudgetCounterData & { pkIni: number }) | null;
    pkType: string;
    pp06RevisionLabel: string | null;
    approverInfo: ApproverInfo | null;
    canRevise: boolean;
    activeDraftId: number | null;
    canComment: boolean;
    commentUrl: string;
    scope: string;
    kodeRefMap: KodeRefMap | null;
    teamName: string | null;
};


function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatDateTime(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const BULAN_LABELS: Record<number, string> = {
    1: 'Januari', 2: 'Februari', 3: 'Maret', 4: 'April',
    5: 'Mei', 6: 'Juni', 7: 'Juli', 8: 'Agustus',
    9: 'September', 10: 'Oktober', 11: 'November', 12: 'Desember',
};

type KodeSegment = { value: string; label: string };

function parseKodeBaru(kode: string, refs: KodeRefMap | null, teamName: string | null): KodeSegment[] {
    const parts = kode.split('.');
    if (parts.length < 13) return [{ value: kode, label: 'Kode Anggaran' }];

    const [tipe, bidang, subBidang, tim, jenis, kategori, prog, keg, ang, tahun, bulan, revisi, tarik] = parts;
    const bulanNum = parseInt(bulan, 10);

    return [
        { value: tipe, label: `Tipe: ${tipe === 'R' ? 'Raker' : 'Proposal'}` },
        { value: bidang, label: `Bidang: ${refs?.bidang[bidang] ?? bidang}` },
        { value: subBidang, label: `Sub Bidang: ${refs?.subBidang[subBidang] ?? subBidang}` },
        { value: tim, label: `Tim: ${teamName ?? tim}` },
        { value: jenis, label: `Jenis: ${refs?.jenis[jenis] ?? jenis}` },
        { value: kategori, label: `Kategori: ${refs?.kategori[kategori] ?? kategori}` },
        { value: prog, label: `Program: ${prog}` },
        { value: keg, label: `Kegiatan: ${keg}` },
        { value: ang, label: `Anggaran: ${ang}` },
        { value: tahun, label: `Tahun: ${tahun}` },
        { value: bulan, label: `Bulan: ${BULAN_LABELS[bulanNum] ?? bulan}` },
        { value: revisi, label: `Revisi: ${revisi.replace('rev', '')}` },
        { value: tarik, label: `Tarik Maju: ${tarik.replace('M', '')}` },
    ];
}

function parseKodeLama(kode: string, refs: KodeRefMap | null, teamName: string | null): KodeSegment[] {
    const parts = kode.split('.');
    if (parts.length < 6) return [{ value: kode, label: 'Kode Lama' }];

    const [bidang, subBidang, tim, jenis, kategori, prog] = parts;

    return [
        { value: bidang, label: `Bidang: ${refs?.bidang[bidang] ?? bidang}` },
        { value: subBidang, label: `Sub Bidang: ${refs?.subBidang[subBidang] ?? subBidang}` },
        { value: tim, label: `Tim: ${teamName ?? tim}` },
        { value: jenis, label: `Jenis: ${refs?.jenis[jenis] ?? jenis}` },
        { value: kategori, label: `Kategori: ${refs?.kategori[kategori] ?? kategori}` },
        { value: prog, label: `Program: ${prog}` },
    ];
}

function KodeWithTooltip({ segments }: { segments: KodeSegment[] }) {
    return (
        <span className="font-mono text-xs">
            {segments.map((seg, i) => (
                <span key={i}>
                    {i > 0 && <span className="text-muted-foreground/50">.</span>}
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="cursor-help border-b border-dotted border-muted-foreground/30 hover:text-foreground hover:border-foreground/50 transition-colors">
                                {seg.value}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top">{seg.label}</TooltipContent>
                    </Tooltip>
                </span>
            ))}
        </span>
    );
}

export default function Pk04({
    workflow,
    pk04Data,
    allRevisions,
    changelogByRevision,
    budgetContext,
    pkType,
    pp06RevisionLabel,
    approverInfo,
    canRevise,
    activeDraftId,
    canComment,
    commentUrl,
    scope,
    kodeRefMap,
    teamName,
}: Props) {
    const basePath = scope === 'team'
        ? `/team/workflows/pk/${workflow.id}`
        : `/admin/workflows/pk/${workflow.id}`;

    const breadcrumbs: BreadcrumbItem[] = scope === 'team'
        ? [
            { title: 'Tim', href: '/team' },
            { title: 'Perencanaan Kegiatan', href: '/team/workflows/pk' },
            { title: workflow.label, href: basePath },
            { title: 'PK04: Program Tahunan', href: '#' },
        ]
        : [
            { title: 'Manajemen', href: '/admin' },
            { title: 'Perencanaan Kegiatan', href: '/admin/workflows/pk' },
            { title: workflow.label, href: basePath },
            { title: 'PK04: Program Tahunan', href: '#' },
        ];

    function stepUrlResolver(entry: HistoryEntry): string | null {
        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
        const step = entry.step;
        if (step === 'PK02A') return `${basePath}/pk02a`;
        if (step === 'PK02B') return `${basePath}/pk02b`;
        if (step === 'PK03') return `${basePath}/pk03`;
        if (step === 'PK04') return `${basePath}/pk04${entry.revision !== undefined ? `?revision=${entry.revision}` : ''}`;
        if (entry.id && entry.table) return `${basePath}/${step.toLowerCase()}/${entry.id}`;
        return null;
    }

    if (!pk04Data) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="PK04: Program Tahunan" />
                <div className="p-6">
                    <p className="text-muted-foreground">PK04 belum di-compile. Selesaikan PK03 terlebih dahulu.</p>
                </div>
            </AppLayout>
        );
    }

    const totalAnggaran = pk04Data.kegiatan.reduce(
        (sum, k) => sum + k.anggaran.reduce((s, a) => s + a.nominal_anggaran, 0),
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PK04: Program Tahunan — ${workflow.label}`} />
            <div className="space-y-6 p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <Heading title={pk04Data.nama_program} description="Data final program kegiatan yang sudah dikompilasi" />
                    <Badge className="bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">Revisi {pk04Data.revision}</Badge>
                    {pkType === 'proposal' && (
                        <Badge className="bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300">PK Proposal</Badge>
                    )}
                </div>

                <HistoryCommentSection
                    entries={workflow.history}
                    commentUrl={commentUrl}
                    commentSource="pk04"
                    canComment={canComment}
                    finalSteps={['PK04']}
                    stepUrlResolver={stepUrlResolver}
                    defaultOpen={false}
                />

                {/* Riwayat Revisi */}
                {allRevisions.length > 1 && (
                    <SectionCard title={`Riwayat Revisi (${allRevisions.length})`}>
                        <div className="flex flex-wrap gap-2">
                            {allRevisions.map((rev) => (
                                <a key={rev.id} href={`${basePath}/pk04?revision=${rev.revision}`}>
                                    <Badge
                                        variant={rev.id === pk04Data.id ? 'default' : 'outline'}
                                        className={rev.id !== pk04Data.id ? 'cursor-pointer hover:bg-muted' : ''}
                                    >
                                        Rev {rev.revision} — {formatDate(rev.created_at)}
                                    </Badge>
                                </a>
                            ))}
                        </div>
                    </SectionCard>
                )}

                {/* Changelog */}
                {pk04Data.revision > 0 && changelogByRevision[pk04Data.revision] !== undefined && (
                    <SectionCard title={`Perubahan dari Revisi ${pk04Data.revision - 1} → ${pk04Data.revision}`}>
                        {changelogByRevision[pk04Data.revision].length === 0 ? (
                            <p className="text-sm italic text-muted-foreground">Tidak ada perubahan</p>
                        ) : (
                            <ul className="space-y-1 text-sm">
                                {changelogByRevision[pk04Data.revision].map((c, i) => (
                                    <li key={i} className="flex gap-2">
                                        <span className="shrink-0 text-muted-foreground">&bull;</span>
                                        <span>{c.description}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                )}

                {/* Approver Info */}
                {approverInfo && (
                    <SectionCard title="Persetujuan RAKER (PK03)">
                        <p className="text-sm">
                            Disetujui oleh: <span className="font-medium">{approverInfo.by_name ?? 'Unknown'}</span>{' '}
                            <span className="text-muted-foreground">({approverInfo.role_name ?? '-'})</span>
                            {approverInfo.at && <> — {formatDateTime(approverInfo.at)}</>}
                        </p>
                    </SectionCard>
                )}

                {/* Informasi Program */}
                <SectionCard title="Informasi Program">
                    <div className="grid gap-2 text-sm sm:grid-cols-2">
                        <div><span className="text-muted-foreground">Nomer Program:</span> <span className="font-mono">{pk04Data.nomer_program}</span></div>
                        <div><span className="text-muted-foreground">Kategori:</span> <span className="font-mono">{pk04Data.kode_kategori}</span></div>
                        <div className="sm:col-span-2"><span className="text-muted-foreground">Nama Program:</span> {pk04Data.nama_program}</div>
                        {pk04Data.deskripsi_program && (
                            <div className="sm:col-span-2"><span className="text-muted-foreground">Deskripsi:</span> {pk04Data.deskripsi_program}</div>
                        )}
                        {pk04Data.tujuan_program && (
                            <div className="sm:col-span-2"><span className="text-muted-foreground">Tujuan:</span> {pk04Data.tujuan_program}</div>
                        )}
                    </div>
                    {pp06RevisionLabel && (
                        <p className="mt-2 text-xs text-muted-foreground">Berdasarkan: {pp06RevisionLabel}</p>
                    )}
                    {pk04Data.pk01_created_by_user_name && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Disusun oleh: {pk04Data.pk01_created_by_user_name}
                            {pk04Data.pk01_created_by_role_name && ` (${pk04Data.pk01_created_by_role_name}`}
                            {pk04Data.pk01_created_by_team_name && ` — ${pk04Data.pk01_created_by_team_name}`}
                            {pk04Data.pk01_created_by_role_name && ')'}
                            {pk04Data.pk01_created_at && ` — ${formatDate(pk04Data.pk01_created_at)}`}
                        </p>
                    )}
                </SectionCard>

                {/* Kegiatan Sections */}
                {pk04Data.kegiatan.map((kegiatan) => (
                    <SectionCard key={kegiatan.id} title={`Kegiatan ${kegiatan.nomer_kegiatan}: ${kegiatan.nama_kegiatan}`}>
                        <p className="mb-3 text-sm text-muted-foreground">Bulan: {kegiatan.bulan_label ?? '-'}</p>

                        {/* Anggaran Table */}
                        {kegiatan.anggaran.length > 0 && (
                            <div className="mb-4">
                                <h4 className="mb-2 text-xs font-medium text-muted-foreground">Anggaran ({kegiatan.anggaran.length})</h4>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-275 text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="w-10 px-3 py-2 text-left font-medium">No</th>
                                                <th className="min-w-52 px-3 py-2 text-left font-medium">Mata Anggaran</th>
                                                <th className="min-w-48 px-3 py-2 text-left font-medium">Deskripsi</th>
                                                <th className="min-w-40 px-3 py-2 text-right font-medium">Nominal (Rp)</th>
                                                <th className="min-w-72 px-3 py-2 text-left font-medium">Kode Baru</th>
                                                <th className="min-w-48 px-3 py-2 text-left font-medium">Kode Lama</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {kegiatan.anggaran.map((a, i) => {
                                                const lockLabel = a.lock_reason === 'sudah_dilaporkan' ? 'Item sudah dilaporkan di PRBL'
                                                    : a.lock_reason === 'sudah_dicairkan' ? 'Item sudah dicairkan'
                                                    : a.lock_reason === 'hangus' ? 'Item hangus'
                                                    : a.lock_reason === 'ditarik_maju' ? 'Item ditarik maju'
                                                    : 'Item terkunci';
                                                return (
                                                <tr key={a.id} className={`border-b last:border-0 ${a.is_locked ? 'bg-muted/40' : ''}`}>
                                                    <td className="px-3 py-2 text-center">
                                                        {a.is_locked && (
                                                            <span title={lockLabel}>
                                                                <Lock className="mr-1 inline h-3.5 w-3.5 text-amber-500" />
                                                            </span>
                                                        )}
                                                        {i + 1}
                                                    </td>
                                                    <td className="px-3 py-2">{a.mata_anggaran}</td>
                                                    <td className="px-3 py-2 text-muted-foreground">{a.deskripsi_pk || '-'}</td>
                                                    <td className="px-3 py-2 text-right tabular-nums font-medium">{formatRupiah(a.nominal_anggaran)}</td>
                                                    <td className="px-3 py-2">
                                                        {a.kode_anggaran_baru
                                                            ? <KodeWithTooltip segments={parseKodeBaru(a.kode_anggaran_baru, kodeRefMap, teamName)} />
                                                            : <span className="text-muted-foreground">-</span>}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {a.kode_anggaran_lama
                                                            ? <KodeWithTooltip segments={parseKodeLama(a.kode_anggaran_lama, kodeRefMap, teamName)} />
                                                            : <span className="text-muted-foreground">-</span>}
                                                    </td>
                                                </tr>
                                                );
                                            })}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t bg-muted/30 font-medium">
                                                <td className="px-3 py-2" colSpan={3}>Subtotal</td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {formatRupiah(kegiatan.anggaran.reduce((s, a) => s + a.nominal_anggaran, 0))}
                                                </td>
                                                <td colSpan={2} />
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        )}

                        {/* Kuisioner Table */}
                        {kegiatan.kuisioner.length > 0 && (
                            <div>
                                <h4 className="mb-2 text-xs font-medium text-muted-foreground">Kuisioner ({kegiatan.kuisioner.length})</h4>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b bg-muted/50">
                                                <th className="w-20 px-3 py-2 text-left font-medium">Kode</th>
                                                <th className="px-3 py-2 text-left font-medium">Pertanyaan</th>
                                                <th className="w-24 px-3 py-2 text-left font-medium">Tipe</th>
                                                <th className="w-20 px-3 py-2 text-left font-medium">Satuan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {kegiatan.kuisioner.map((q) => (
                                                <tr key={q.id} className="border-b last:border-0">
                                                    <td className="px-3 py-2 font-mono text-xs">{q.kode_kuisioner || '-'}</td>
                                                    <td className="px-3 py-2">{q.pertanyaan}</td>
                                                    <td className="px-3 py-2">{q.tipe}</td>
                                                    <td className="px-3 py-2 text-muted-foreground">{q.satuan || '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </SectionCard>
                ))}

                {/* Ringkasan */}
                <SectionCard title="Ringkasan">
                    <div className="grid gap-2 text-sm sm:grid-cols-3">
                        <div><span className="text-muted-foreground">Total Kegiatan:</span> {pk04Data.kegiatan.length}</div>
                        <div><span className="text-muted-foreground">Total Anggaran:</span> <span className="font-medium tabular-nums">{formatRupiah(totalAnggaran)}</span></div>
                        <div><span className="text-muted-foreground">Dikompilasi:</span> {formatDateTime(pk04Data.created_at)}</div>
                    </div>
                </SectionCard>

                {/* Budget Context (raker only) — moved below ringkasan */}
                {budgetContext && (
                    <BudgetReferenceCard counter={budgetContext} variant="readonly" />
                )}

                {/* Kode Verifikasi */}
                {pk04Data.verification_code && <VerificationCodeBox code={pk04Data.verification_code} />}

                {/* Download Buttons */}
                <div className="flex gap-2">
                    <a href={`${basePath}/pk04/export/pdf?revision=${pk04Data.revision}`}>
                        <Button variant="default" size="sm">Unduh PDF</Button>
                    </a>
                    <a href={`${basePath}/pk04/export/excel?revision=${pk04Data.revision}`}>
                        <Button variant="secondary" size="sm">Unduh Excel</Button>
                    </a>
                    <a href={`${basePath}/pk04/export/zip`}>
                        <Button variant="outline" size="sm">Unduh Semua (ZIP)</Button>
                    </a>
                </div>

                {/* Revision Button (admin only) */}
                {canRevise && (
                    <div className="flex gap-2">
                        {activeDraftId ? (
                            <Button variant="outline" onClick={() => router.visit(`/admin/workflows/pk/${workflow.id}/pk05/${activeDraftId}`)}>
                                Lanjutkan Revisi
                            </Button>
                        ) : (
                            <Button variant="outline" onClick={() => router.post(`/admin/workflows/pk/${workflow.id}/pk05/create`)}>
                                Revisi
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function VerificationCodeBox({ code }: { code: string }) {
    const [copied, setCopied] = useState(false);

    function handleCopy() {
        navigator.clipboard.writeText(code);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <SectionCard title="Kode Verifikasi">
            <div className="flex items-center gap-3">
                <code className="rounded bg-muted px-3 py-1.5 font-mono text-lg tracking-widest">{code}</code>
                <Button variant="outline" size="sm" onClick={handleCopy}>
                    {copied ? 'Tersalin!' : 'Salin'}
                </Button>
                <a href={`/verify/${code}`} target="_blank" rel="noopener noreferrer" className="text-xs text-primary hover:underline">
                    Verifikasi
                </a>
            </div>
            <p className="mt-2 text-xs text-muted-foreground">
                Kode ini digunakan untuk memverifikasi keaslian dokumen. Data yang berubah akan menghasilkan kode berbeda.
            </p>
        </SectionCard>
    );
}
