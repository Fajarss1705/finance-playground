import { Head } from '@inertiajs/react';
import { CheckCircle2, ClipboardCopy, ExternalLink, FileSpreadsheet, FileText, Package, Star } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRupiah } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type FileEntry = {
    id: number;
    file_id: number;
    filename: string;
    mime_type: string | null;
    size: number | null;
    path: string | null;
    download_url: string | null;
    thumbnail_url?: string | null;
};

type KuisionerEntry = {
    id: number;
    pk04_kuisioner_id: number;
    pertanyaan: string;
    tipe: string;
    satuan: string;
    jawaban: string | null;
};

type KegiatanEntry = {
    prbl05_item_kegiatan_id: number;
    kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    masalah: string | null;
    langkah_penanganan: string | null;
    harapan: string | null;
    catatan_tim: string | null;
    foto: FileEntry[];
    nota: FileEntry[];
    kuisioner: KuisionerEntry[];
};

type KegiatanProgramGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    tipe: string;
    kegiatan: KegiatanEntry[];
};

type RealisasiAnggaranEntry = {
    prbl05_item_realisasi_id: number;
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    kode_anggaran_lama: string | null;
    mata_anggaran: string;
    nominal_anggaran: number;
    nominal_realisasi: number;
    selisih: number;
    komentar_realisasi: string | null;
};

type RealisasiKegiatanGroup = {
    kegiatan_id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string;
    anggaran: RealisasiAnggaranEntry[];
};

type RealisasiProgramGroup = {
    program_id: number;
    program_name: string;
    kode_kategori: string;
    kegiatan: RealisasiKegiatanGroup[];
};

type RekeningOrganisasi = {
    id: number;
    nama_bank: string;
    nama_rekening: string;
    nomor_rekening: string;
};

type ExportFile = {
    id: number;
    filename: string;
    path: string | null;
    available: boolean;
} | null;

type Prbl05Data = {
    id: number;
    verification_code: string | null;
    prbl01_created_by_user_name: string;
    prbl01_created_by_role_name: string;
    prbl01_created_by_team_name: string | null;
    prbl01_created_at: string | null;
    prbl02a_approved_by_user_name: string;
    prbl02a_approved_by_role_name: string;
    prbl02a_approved_by_team_name: string | null;
    prbl02a_approved_at: string | null;
    prbl02b_approved_by_user_name: string;
    prbl02b_approved_by_role_name: string;
    prbl02b_approved_by_team_name: string | null;
    prbl02b_approved_at: string | null;
    prbl03_created_by_user_name: string;
    prbl03_created_by_role_name: string;
    prbl03_created_by_team_name: string | null;
    prbl03_created_at: string | null;
    prbl04_approved_by_user_name: string;
    prbl04_approved_by_role_name: string;
    prbl04_approved_by_team_name: string | null;
    prbl04_approved_at: string | null;
    keterangan: string | null;
    total_anggaran_dicairkan: number;
    total_realisasi: number;
    total_refund: number;
    total_item: number;
    created_at: string | null;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    bulan_laporan: number;
    tahun_laporan: number;
    bulan_label: string;
};

type Props = {
    scope: 'team' | 'admin';
    workflow: Workflow;
    prbl05: Prbl05Data;
    kegiatanItems: KegiatanProgramGroup[];
    realisasiItems: RealisasiProgramGroup[];
    rekeningOrganisasi: RekeningOrganisasi[];
    buktiTransferFiles: FileEntry[];
    fotoNotaFiles: FileEntry[];
    exportFiles: { pdf: ExportFile; excel: ExportFile };
    ppLabel: string | null;
    pabdLabel: string | null;
    verifyUrl: string;
    canComment: boolean;
    commentUrl: string;
    history: HistoryEntry[];
    actionRoles: ActionRole[];
    activeRoleName: string | null;
    stepperData: unknown;
    teamName: string;
};

// ─── Helpers ──────────────────────────────────────────

function formatDate(iso: string | null): string {
    return iso ? formatDateTime(iso) : '-';
}

function formatFileSize(bytes: number | null): string {
    if (!bytes) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// ─── Page Component ───────────────────────────────────

export default function Prbl05Show({
    scope,
    workflow,
    prbl05,
    kegiatanItems,
    realisasiItems,
    rekeningOrganisasi,
    buktiTransferFiles,
    fotoNotaFiles,
    exportFiles,
    ppLabel,
    pabdLabel,
    verifyUrl,
    canComment,
    commentUrl,
    history,
    actionRoles,
    activeRoleName,
    teamName,
}: Props) {
    const [copied, setCopied] = useState(false);

    const basePath = scope === 'team'
        ? `/team/workflows/prbl/${workflow.id}`
        : `/admin/workflows/prbl/${workflow.id}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'team' ? 'Tim' : 'Admin', href: `/${scope}` },
        { title: 'Laporan Bulanan', href: `/${scope}/workflows/prbl` },
        { title: workflow.label, href: `/${scope}/workflows/prbl/${workflow.id}` },
        { title: 'PRBL05: Laporan Bulanan', href: `${basePath}/prbl05` },
    ];

    function handleCopyCode() {
        if (prbl05.verification_code) {
            navigator.clipboard.writeText(prbl05.verification_code);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PRBL05 — ${workflow.label}`} />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">
                {/* Header */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Heading title="PRBL05: Laporan Bulanan Final" description="" />
                        <Badge variant="default" className="bg-amber-500 text-white hover:bg-amber-600">
                            <Star className="mr-1 h-3 w-3" /> Selesai
                        </Badge>
                    </div>
                </div>

                {/* Green completion banner */}
                <div className="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-green-600" />
                    Laporan bulanan telah selesai dikompilasi.
                </div>

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                {/* History & Comment */}
                <HistoryCommentSection
                    entries={history}
                    commentUrl={commentUrl}
                    commentSource="PRBL05"
                    canComment={canComment}
                    finalSteps={['PRBL05']}
                    defaultOpen={false}
                    stepUrlResolver={(entry: HistoryEntry) => {
                        if (!entry.step || entry.action === 'terminated' || entry.action === 'deleted') return null;
                        const step = entry.step;
                        if (step === 'PRBL01' && entry.id) return `${basePath}/prbl01/${entry.id}`;
                        if (step === 'PRBL02A') return `${basePath}/prbl02a`;
                        if (step === 'PRBL02B') return `${basePath}/prbl02b`;
                        if (step === 'PRBL03' && entry.id) return `${basePath}/prbl03/${entry.id}`;
                        if (step === 'PRBL04') return `${basePath}/prbl04`;
                        if (step === 'PRBL05') return `${basePath}/prbl05`;
                        return null;
                    }}
                />

                {/* Informasi Umum */}
                <SectionCard title="Informasi Umum">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Tim</dt>
                            <dd className="text-sm">{teamName}</dd>
                        </div>
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Bulan Laporan</dt>
                            <dd className="text-sm">{workflow.bulan_label} {workflow.tahun_laporan}</dd>
                        </div>
                        {ppLabel && (
                            <div>
                                <dt className="text-sm font-medium text-gray-500">Referensi PP</dt>
                                <dd className="text-sm">{ppLabel}</dd>
                            </div>
                        )}
                        {pabdLabel && (
                            <div>
                                <dt className="text-sm font-medium text-gray-500">Referensi PABD</dt>
                                <dd className="text-sm">{pabdLabel}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Tanggal Kompilasi</dt>
                            <dd className="text-sm">{formatDate(prbl05.created_at)}</dd>
                        </div>
                    </div>

                    <div className="mt-4 space-y-2 border-t pt-4">
                        <AuthorLine
                            label="Laporan oleh"
                            name={prbl05.prbl01_created_by_user_name}
                            role={prbl05.prbl01_created_by_role_name}
                            team={prbl05.prbl01_created_by_team_name}
                            date={prbl05.prbl01_created_at}
                        />
                        <AuthorLine
                            label="Review Narasi oleh"
                            name={prbl05.prbl02a_approved_by_user_name}
                            role={prbl05.prbl02a_approved_by_role_name}
                            team={prbl05.prbl02a_approved_by_team_name}
                            date={prbl05.prbl02a_approved_at}
                        />
                        <AuthorLine
                            label="Review Anggaran oleh"
                            name={prbl05.prbl02b_approved_by_user_name}
                            role={prbl05.prbl02b_approved_by_role_name}
                            team={prbl05.prbl02b_approved_by_team_name}
                            date={prbl05.prbl02b_approved_at}
                        />
                        <AuthorLine
                            label="Refund oleh"
                            name={prbl05.prbl03_created_by_user_name}
                            role={prbl05.prbl03_created_by_role_name}
                            team={prbl05.prbl03_created_by_team_name}
                            date={prbl05.prbl03_created_at}
                        />
                        <AuthorLine
                            label="Disetujui oleh"
                            name={prbl05.prbl04_approved_by_user_name}
                            role={prbl05.prbl04_approved_by_role_name}
                            team={prbl05.prbl04_approved_by_team_name}
                            date={prbl05.prbl04_approved_at}
                        />
                    </div>
                </SectionCard>

                {/* Laporan Kegiatan */}
                <SectionCard title="Laporan Kegiatan">
                    {kegiatanItems.length === 0 ? (
                        <p className="text-sm text-gray-500">Tidak ada data kegiatan.</p>
                    ) : (
                        <div className="space-y-6">
                            {kegiatanItems.map((program) => (
                                <div key={program.program_id}>
                                    <h4 className="mb-3 text-sm font-semibold text-gray-700">
                                        {program.tipe === 'proposal' ? 'Proposal: ' : ''}{program.program_name}
                                        <span className="ml-1 text-xs font-normal text-gray-500">({program.kode_kategori})</span>
                                    </h4>
                                    {program.kegiatan.map((kegiatan) => (
                                        <KegiatanCard key={kegiatan.prbl05_item_kegiatan_id} kegiatan={kegiatan} />
                                    ))}
                                </div>
                            ))}
                        </div>
                    )}
                </SectionCard>

                {/* Realisasi Anggaran */}
                <SectionCard title="Realisasi Anggaran">
                    {realisasiItems.length === 0 ? (
                        <p className="text-sm text-gray-500">Tidak ada data realisasi.</p>
                    ) : (
                        <div className="space-y-6">
                            {realisasiItems.map((program) => (
                                <div key={program.program_id}>
                                    <h4 className="mb-3 text-sm font-semibold text-gray-700">
                                        {program.program_name}
                                        <span className="ml-1 text-xs font-normal text-gray-500">({program.kode_kategori})</span>
                                    </h4>
                                    {program.kegiatan.map((kegiatan) => (
                                        <div key={kegiatan.kegiatan_id} className="mb-4 ml-2">
                                            <h5 className="mb-2 text-sm font-medium text-gray-600">
                                                {kegiatan.nama_kegiatan} — {kegiatan.bulan_label}
                                            </h5>
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-sm">
                                                    <thead>
                                                        <tr className="border-b text-left text-xs text-gray-500">
                                                            <th className="pb-2 pr-3 font-medium">Kode Anggaran Baru</th>
                                                            <th className="pb-2 pr-3 font-medium">Mata Anggaran</th>
                                                            <th className="pb-2 pr-3 text-right font-medium">Dicairkan (Rp)</th>
                                                            <th className="pb-2 pr-3 text-right font-medium">Realisasi (Rp)</th>
                                                            <th className="pb-2 pr-3 text-right font-medium">Selisih (Rp)</th>
                                                            <th className="pb-2 pr-3 font-medium">Komentar</th>
                                                            <th className="pb-2 font-medium">Kode Anggaran Lama</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {kegiatan.anggaran.map((a) => (
                                                            <tr key={a.prbl05_item_realisasi_id} className="border-b border-gray-100">
                                                                <td className="py-2 pr-3">
                                                                    {a.kode_anggaran_baru ? (
                                                                        <KodeAnggaranFromString kode={a.kode_anggaran_baru} />
                                                                    ) : (
                                                                        <span className="text-gray-400">-</span>
                                                                    )}
                                                                </td>
                                                                <td className="py-2 pr-3">{a.mata_anggaran}</td>
                                                                <td className="py-2 pr-3 text-right font-mono">{formatRupiah(a.nominal_anggaran)}</td>
                                                                <td className="py-2 pr-3 text-right font-mono">{formatRupiah(a.nominal_realisasi)}</td>
                                                                <td className={`py-2 pr-3 text-right font-mono ${a.selisih < 0 ? 'text-amber-600 font-semibold' : ''}`}>
                                                                    {formatRupiah(a.selisih)}
                                                                </td>
                                                                <td className="py-2 pr-3 text-sm text-gray-600">{a.komentar_realisasi || '-'}</td>
                                                                <td className="py-2 font-mono text-gray-500">{a.kode_anggaran_lama ?? '—'}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ))}

                            {/* Summary */}
                            <div className="rounded-lg border bg-gray-50 p-4">
                                <div className="grid gap-2 text-sm sm:grid-cols-3">
                                    <div>
                                        <span className="text-gray-500">Total Anggaran Dicairkan:</span>
                                        <span className="ml-2 font-semibold text-green-700">
                                            {formatRupiah(prbl05.total_anggaran_dicairkan)}
                                        </span>
                                        <span className="ml-1 text-xs text-gray-500">({prbl05.total_item} item)</span>
                                    </div>
                                    <div>
                                        <span className="text-gray-500">Total Realisasi:</span>
                                        <span className="ml-2 font-semibold">
                                            {formatRupiah(prbl05.total_realisasi)}
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-gray-500">Total Refund:</span>
                                        <span className="ml-2 font-semibold text-amber-700">
                                            {formatRupiah(prbl05.total_refund)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </SectionCard>

                {/* Refund & Bukti Transfer */}
                <SectionCard title="Refund & Bukti Transfer">
                    {prbl05.total_refund > 0 ? (
                        <div className="space-y-4">
                            <div className="text-sm">
                                <span className="text-gray-500">Total Refund:</span>
                                <span className="ml-2 font-semibold text-amber-700">{formatRupiah(prbl05.total_refund)}</span>
                            </div>

                            {prbl05.keterangan && (
                                <div>
                                    <dt className="text-sm font-medium text-gray-500">Keterangan</dt>
                                    <dd className="mt-1 whitespace-pre-line text-sm">{prbl05.keterangan}</dd>
                                </div>
                            )}

                            {rekeningOrganisasi.length > 0 && (
                                <div>
                                    <h5 className="mb-2 text-sm font-medium text-gray-600">Rekening Organisasi Tujuan Refund</h5>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-xs text-gray-500">
                                                    <th className="pb-2 pr-3 font-medium">Nama Bank</th>
                                                    <th className="pb-2 pr-3 font-medium">Nama Rekening</th>
                                                    <th className="pb-2 font-medium">Nomor Rekening</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {rekeningOrganisasi.map((rek) => (
                                                    <tr key={rek.id} className="border-b border-gray-100">
                                                        <td className="py-2 pr-3">{rek.nama_bank}</td>
                                                        <td className="py-2 pr-3">{rek.nama_rekening}</td>
                                                        <td className="py-2 font-mono">{rek.nomor_rekening}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            {buktiTransferFiles.length > 0 && (
                                <div>
                                    <h5 className="mb-2 text-sm font-medium text-gray-600">Bukti Transfer Refund</h5>
                                    <FileList files={buktiTransferFiles} />
                                </div>
                            )}

                            {fotoNotaFiles.length > 0 && (
                                <div>
                                    <h5 className="mb-2 text-sm font-medium text-gray-600">Foto Nota di Kantor Pusat</h5>
                                    <FileList files={fotoNotaFiles} />
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                                Tidak ada selisih refund untuk bulan ini. Total realisasi sama dengan total anggaran dicairkan.
                            </div>

                            {fotoNotaFiles.length > 0 && (
                                <div>
                                    <h5 className="mb-2 text-sm font-medium text-gray-600">Foto Nota di Kantor Pusat</h5>
                                    <FileList files={fotoNotaFiles} />
                                </div>
                            )}
                        </div>
                    )}
                </SectionCard>

                {/* Verifikasi Dokumen */}
                <SectionCard title="Verifikasi Dokumen">
                    <div className="space-y-3">
                        <div className="flex items-center gap-3">
                            <span className="text-sm text-gray-500">Kode Verifikasi:</span>
                            <code className="rounded bg-gray-100 px-3 py-1 font-mono text-lg font-bold tracking-wider">
                                {prbl05.verification_code || '-'}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleCopyCode}
                                disabled={!prbl05.verification_code}
                            >
                                <ClipboardCopy className="mr-1 h-3 w-3" />
                                {copied ? 'Disalin!' : 'Salin'}
                            </Button>
                        </div>
                        <p className="text-xs text-gray-500">
                            Kode ini membuktikan keaslian dokumen. Gunakan halaman verifikasi untuk memvalidasi dokumen yang dicetak.
                        </p>
                        {verifyUrl && (
                            <a
                                href={verifyUrl}
                                className="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline"
                            >
                                Verifikasi Dokumen <ExternalLink className="h-3 w-3" />
                            </a>
                        )}
                    </div>
                </SectionCard>

                {/* Unduh Dokumen */}
                <SectionCard title="Unduh Dokumen">
                    <div className="space-y-3">
                        <div className="flex flex-wrap gap-3">
                            <DownloadButton
                                href={`${basePath}/prbl05/export/pdf`}
                                icon={<FileText className="h-4 w-4" />}
                                label="Unduh PDF"
                                available={exportFiles.pdf?.available ?? false}
                            />
                            <DownloadButton
                                href={`${basePath}/prbl05/export/excel`}
                                icon={<FileSpreadsheet className="h-4 w-4" />}
                                label="Unduh Excel"
                                available={exportFiles.excel?.available ?? false}
                            />
                            <DownloadButton
                                href={`${basePath}/prbl05/export/zip`}
                                icon={<Package className="h-4 w-4" />}
                                label="Unduh ZIP"
                                available={true}
                            />
                        </div>
                        <p className="text-xs text-gray-500">
                            ZIP berisi PDF, Excel, dan semua file foto kegiatan, nota pengeluaran, bukti transfer, dan foto nota.
                        </p>
                    </div>
                </SectionCard>
            </div>
        </AppLayout>
    );
}

// ─── Sub-Components ───────────────────────────────────

function AuthorLine({
    label,
    name,
    role,
    team,
    date,
}: {
    label: string;
    name: string;
    role: string;
    team: string | null;
    date: string | null;
}) {
    const roleTeam = team ? `${role} · ${team}` : role;

    return (
        <div className="text-sm">
            <span className="text-gray-500">{label}:</span>{' '}
            <span className="font-medium">{name}</span>{' '}
            <span className="text-gray-500">({roleTeam})</span>
            {date && <span className="ml-1 text-gray-400">— {formatDate(date)}</span>}
        </div>
    );
}

function KegiatanCard({ kegiatan }: { kegiatan: KegiatanEntry }) {
    return (
        <div className="mb-4 ml-2 rounded-lg border p-4">
            <h5 className="mb-3 text-sm font-medium text-gray-700">
                {kegiatan.nama_kegiatan} — {kegiatan.bulan_label}
            </h5>

            {/* Narratives */}
            <div className="space-y-3">
                <NarrativeField label="Masalah/Kendala" value={kegiatan.masalah} />
                <NarrativeField label="Langkah Penanganan" value={kegiatan.langkah_penanganan} />
                <NarrativeField label="Harapan" value={kegiatan.harapan} />
                <NarrativeField label="Catatan Tim" value={kegiatan.catatan_tim} />
            </div>

            {/* Kuisioner */}
            {kegiatan.kuisioner.length > 0 && (
                <div className="mt-4">
                    <h6 className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Kuisioner</h6>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs text-gray-500">
                                    <th className="pb-2 pr-3 font-medium">#</th>
                                    <th className="pb-2 pr-3 font-medium">Pertanyaan</th>
                                    <th className="pb-2 pr-3 font-medium">Tipe</th>
                                    <th className="pb-2 pr-3 font-medium">Satuan</th>
                                    <th className="pb-2 font-medium">Jawaban</th>
                                </tr>
                            </thead>
                            <tbody>
                                {kegiatan.kuisioner.map((q, idx) => (
                                    <tr key={q.id} className="border-b border-gray-100">
                                        <td className="py-2 pr-3 text-gray-400">{idx + 1}</td>
                                        <td className="py-2 pr-3">{q.pertanyaan}</td>
                                        <td className="py-2 pr-3 text-gray-500">{q.tipe}</td>
                                        <td className="py-2 pr-3 text-gray-500">{q.satuan || '-'}</td>
                                        <td className="py-2 font-medium">{q.jawaban || '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Foto Kegiatan */}
            {kegiatan.foto.length > 0 && (
                <div className="mt-4">
                    <h6 className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Foto Kegiatan</h6>
                    <div className="flex flex-wrap gap-2">
                        {kegiatan.foto.map((f) => (
                            <a
                                key={f.id}
                                href={f.download_url || '#'}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="group relative h-20 w-20 overflow-hidden rounded-lg border bg-gray-100"
                                title={f.filename}
                            >
                                {f.thumbnail_url && f.mime_type?.startsWith('image/') ? (
                                    <img
                                        src={f.thumbnail_url}
                                        alt={f.filename}
                                        className="h-full w-full object-cover transition-opacity group-hover:opacity-80"
                                    />
                                ) : (
                                    <div className="flex h-full w-full items-center justify-center text-xs text-gray-400">
                                        {f.filename.split('.').pop()?.toUpperCase() || 'FILE'}
                                    </div>
                                )}
                            </a>
                        ))}
                    </div>
                </div>
            )}

            {/* Nota Pengeluaran */}
            {kegiatan.nota.length > 0 && (
                <div className="mt-4">
                    <h6 className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Nota Pengeluaran</h6>
                    <FileList files={kegiatan.nota} />
                </div>
            )}
        </div>
    );
}

function NarrativeField({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs font-medium text-gray-500">{label}</dt>
            <dd className="mt-0.5 whitespace-pre-line border-l-2 border-gray-200 pl-3 text-sm text-gray-700">
                {value || <span className="italic text-gray-400">Tidak diisi</span>}
            </dd>
        </div>
    );
}

function FileList({ files }: { files: FileEntry[] }) {
    return (
        <div className="space-y-2">
            {files.map((file) => (
                <div
                    key={file.id}
                    className="flex items-center justify-between rounded-lg border bg-white px-4 py-2"
                >
                    <div className="flex items-center gap-2 text-sm">
                        <FileText className="h-4 w-4 text-gray-400" />
                        <span>{file.filename}</span>
                        {file.size != null && (
                            <span className="text-xs text-gray-400">({formatFileSize(file.size)})</span>
                        )}
                    </div>
                    {file.download_url && (
                        <a href={file.download_url} className="text-sm text-blue-600 hover:underline">
                            Unduh
                        </a>
                    )}
                </div>
            ))}
        </div>
    );
}

function DownloadButton({
    href,
    icon,
    label,
    available,
}: {
    href: string;
    icon: React.ReactNode;
    label: string;
    available: boolean;
}) {
    if (!available) {
        return (
            <Button variant="outline" disabled title="File sedang digenerate...">
                {icon}
                <span className="ml-2">{label}</span>
            </Button>
        );
    }

    return (
        <Button variant="outline" asChild>
            <a href={href}>
                {icon}
                <span className="ml-2">{label}</span>
            </a>
        </Button>
    );
}
