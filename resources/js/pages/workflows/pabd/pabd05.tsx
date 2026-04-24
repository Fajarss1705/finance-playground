import { Head } from '@inertiajs/react';
import { CheckCircle2, ClipboardCopy, ExternalLink, FileSpreadsheet, FileText, Package, Star } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import CopyButton from '@/components/ui/copy-button';
import ActionRolesSection from '@/components/workflow/action-roles-section';
import type { ActionRole } from '@/components/workflow/action-roles-section';
import HistoryCommentSection from '@/components/workflow/history-comment-section';
import type { HistoryEntry } from '@/components/workflow/history-comment-section';
import KodeAnggaranFromString from '@/components/workflow/kode-anggaran-from-string';
import SectionCard from '@/components/workflow/section-card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRupiah, rowToTSV, tableToTSV } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

// ─── Types ───────────────────────────────────────────

type AnggaranItem = {
    pabd05_item_id: number;
    pk04_anggaran_id: number;
    kode_anggaran_baru: string | null;
    kode_anggaran_lama: string | null;
    mata_anggaran: string;
    nominal_anggaran: number;
    status: 'dicairkan' | 'hangus';
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

type BuktiTransferFile = {
    id: number;
    file_id: number;
    filename: string;
    mime_type: string | null;
    size: number | null;
    path: string | null;
    download_url: string | null;
};

type ExportFile = {
    id: number;
    filename: string;
    path: string | null;
    available: boolean;
} | null;

type Pabd05Data = {
    id: number;
    verification_code: string | null;
    pabd01_created_by_user_name: string;
    pabd01_created_by_role_name: string;
    pabd01_created_by_team_name: string | null;
    pabd01_created_at: string | null;
    pabd03_approved_by_user_name: string;
    pabd03_approved_by_role_name: string;
    pabd03_approved_by_team_name: string | null;
    pabd03_approved_at: string | null;
    pabd04_created_by_user_name: string;
    pabd04_created_by_role_name: string;
    pabd04_created_by_team_name: string | null;
    pabd04_created_at: string | null;
    nama_bank: string;
    nama_rekening: string;
    nomor_rekening: string;
    total_anggaran_dicairkan: number;
    total_item_dicairkan: number;
    total_item_hangus: number;
    created_at: string | null;
};

type Workflow = {
    id: number;
    label: string;
    status: string;
    bulan_anggaran: number;
    tahun_anggaran: number;
    bulan_label: string;
};

type Props = {
    scope: 'team' | 'admin';
    workflow: Workflow;
    pabd05: Pabd05Data;
    items: ProgramGroup[];
    buktiTransferFiles: BuktiTransferFile[];
    exportFiles: { pdf: ExportFile; excel: ExportFile };
    ppLabel: string | null;
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

const PABD05_TABLE_HEADERS = ['Status', 'Kode Anggaran Baru', 'Nominal (Rp)', 'Mata Anggaran', 'Kode Anggaran Lama'];
const PABD05_SECTION_HEADERS = ['Program', 'Kategori', 'Kegiatan', 'Bulan', ...PABD05_TABLE_HEADERS];

function pabd05AnggaranToRow(a: AnggaranItem): string[] {
    return [
        a.status === 'dicairkan' ? 'Dicairkan' : 'Hangus',
        a.kode_anggaran_baru ?? '',
        String(a.nominal_anggaran),
        a.mata_anggaran,
        a.kode_anggaran_lama ?? '',
    ];
}

function buildPabd05SectionTSV(programs: ProgramGroup[]): string {
    const rows: string[][] = [];
    for (const program of programs) {
        for (const kegiatan of program.kegiatan) {
            for (const a of kegiatan.anggaran) {
                rows.push([program.program_name, program.kode_kategori, kegiatan.nama_kegiatan, kegiatan.bulan_label, ...pabd05AnggaranToRow(a)]);
            }
        }
    }
    return tableToTSV(PABD05_SECTION_HEADERS, rows);
}

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

export default function Pabd05Show({
    scope,
    workflow,
    pabd05,
    items,
    buktiTransferFiles,
    exportFiles,
    ppLabel,
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
        ? `/team/workflows/pabd/${workflow.id}`
        : `/admin/workflows/pabd/${workflow.id}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: scope === 'team' ? 'Tim' : 'Admin', href: `/${scope}` },
        { title: 'Pengajuan Anggaran', href: `/${scope}/workflows/pabd` },
        { title: workflow.label, href: `/${scope}/workflows/pabd/${workflow.id}` },
        { title: 'PABD05: Pengajuan Bulanan', href: `${basePath}/pabd05` },
    ];

    const totalItems = pabd05.total_item_dicairkan + pabd05.total_item_hangus;
    const totalAnggaranHangus = items.reduce((sum, p) =>
        sum + p.kegiatan.reduce((ks, k) =>
            ks + k.anggaran.filter(a => a.status === 'hangus').reduce((as, a) => as + a.nominal_anggaran, 0), 0), 0);
    const grandTotal = pabd05.total_anggaran_dicairkan + totalAnggaranHangus;

    function handleCopyCode() {
        if (pabd05.verification_code) {
            navigator.clipboard.writeText(pabd05.verification_code);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PABD05 — ${workflow.label}`} />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">
                {/* Header */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Heading title="PABD05: Pengajuan Anggaran Bulanan" description="" />
                        <Badge variant="default" className="bg-amber-500 text-white hover:bg-amber-600">
                            <Star className="mr-1 h-3 w-3" /> Selesai
                        </Badge>
                    </div>
                </div>

                {/* Green completion banner */}
                <div className="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    <CheckCircle2 className="h-5 w-5 shrink-0 text-green-600" />
                    Pengajuan anggaran bulanan telah selesai dikompilasi.
                </div>

                {/* Action Roles */}
                <ActionRolesSection items={actionRoles} activeRoleName={activeRoleName} />

                {/* History & Comment */}
                <HistoryCommentSection
                    entries={history}
                    commentUrl={commentUrl}
                    commentSource="PABD05"
                    canComment={canComment}
                    finalSteps={['PABD05']}
                    defaultOpen={false}
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

                {/* Informasi Umum */}
                <SectionCard title="Informasi Umum">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Tim</dt>
                            <dd className="text-sm">{teamName}</dd>
                        </div>
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Bulan Anggaran</dt>
                            <dd className="text-sm">{workflow.bulan_label} {workflow.tahun_anggaran}</dd>
                        </div>
                        {ppLabel && (
                            <div>
                                <dt className="text-sm font-medium text-gray-500">Referensi PP</dt>
                                <dd className="text-sm">{ppLabel}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Tanggal Kompilasi</dt>
                            <dd className="text-sm">{formatDate(pabd05.created_at)}</dd>
                        </div>
                    </div>

                    <div className="mt-4 space-y-2 border-t pt-4">
                        <AuthorLine
                            label="Checklist oleh"
                            name={pabd05.pabd01_created_by_user_name}
                            role={pabd05.pabd01_created_by_role_name}
                            team={pabd05.pabd01_created_by_team_name}
                            date={pabd05.pabd01_created_at}
                        />
                        <AuthorLine
                            label="Disetujui oleh"
                            name={pabd05.pabd03_approved_by_user_name}
                            role={pabd05.pabd03_approved_by_role_name}
                            team={pabd05.pabd03_approved_by_team_name}
                            date={pabd05.pabd03_approved_at}
                        />
                        <AuthorLine
                            label="Bukti Transfer oleh"
                            name={pabd05.pabd04_created_by_user_name}
                            role={pabd05.pabd04_created_by_role_name}
                            team={pabd05.pabd04_created_by_team_name}
                            date={pabd05.pabd04_created_at}
                        />
                    </div>
                </SectionCard>

                {/* Informasi Rekening */}
                <SectionCard title="Informasi Rekening">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Nama Bank</dt>
                            <dd className="text-sm">{pabd05.nama_bank || '-'}</dd>
                        </div>
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Nama Rekening</dt>
                            <dd className="text-sm">{pabd05.nama_rekening || '-'}</dd>
                        </div>
                        <div>
                            <dt className="text-sm font-medium text-gray-500">Nomor Rekening</dt>
                            <dd className="text-sm font-mono">{pabd05.nomor_rekening || '-'}</dd>
                        </div>
                    </div>
                </SectionCard>

                {/* Daftar Anggaran */}
                <SectionCard
                    title="Daftar Anggaran"
                    headerRight={items.length > 0 ? <CopyButton variant="button" label="Salin Daftar Anggaran" value={() => buildPabd05SectionTSV(items)} /> : undefined}
                >
                    {items.length === 0 ? (
                        <p className="text-sm text-gray-500">Tidak ada item anggaran.</p>
                    ) : (
                        <div className="space-y-6">
                            {items.map((program) => (
                                <div key={program.program_id}>
                                    <h4 className="mb-3 text-sm font-semibold text-gray-700">
                                        {program.tipe === 'proposal' ? 'Proposal: ' : ''}{program.program_name}
                                        <span className="ml-1 text-xs font-normal text-gray-500">({program.kode_kategori})</span>
                                    </h4>
                                    {program.kegiatan.map((kegiatan) => (
                                        <div key={kegiatan.kegiatan_id} className="mb-4 ml-2">
                                            <div className="mb-2 flex items-center justify-between gap-2">
                                                <h5 className="text-sm font-medium text-gray-600">
                                                    {kegiatan.nama_kegiatan} — {kegiatan.bulan_label}
                                                </h5>
                                                <CopyButton variant="button" label="Salin Tabel" value={() => tableToTSV(PABD05_TABLE_HEADERS, kegiatan.anggaran.map(pabd05AnggaranToRow))} />
                                            </div>
                                            <div className="overflow-x-auto">
                                                <table className="w-full min-w-200 border-collapse border text-sm">
                                                    <thead>
                                                        <tr className="bg-muted/50 text-left text-xs text-gray-500">
                                                            <th className="border px-3 py-2 font-medium">Status</th>
                                                            <th className="border px-3 py-2 font-medium whitespace-nowrap">Kode Anggaran Baru</th>
                                                            <th className="border px-3 py-2 text-right font-medium">Nominal (Rp)</th>
                                                            <th className="border px-3 py-2 font-medium">Mata Anggaran</th>
                                                            <th className="border px-3 py-2 font-medium whitespace-nowrap">Kode Anggaran Lama</th>
                                                            <th className="w-8 border px-2 py-2"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {kegiatan.anggaran.map((anggaran) => (
                                                            <tr key={anggaran.pabd05_item_id}>
                                                                <td className="border px-3 py-2">
                                                                    {anggaran.status === 'dicairkan' ? (
                                                                        <Badge variant="default" className="bg-green-100 text-green-800 hover:bg-green-100">
                                                                            Dicairkan
                                                                        </Badge>
                                                                    ) : (
                                                                        <Badge variant="destructive" className="bg-red-100 text-red-800 hover:bg-red-100">
                                                                            Hangus
                                                                        </Badge>
                                                                    )}
                                                                </td>
                                                                <td className="border px-3 py-2 whitespace-nowrap">
                                                                    <span className="inline-flex items-center gap-1">
                                                                        {anggaran.kode_anggaran_baru ? (
                                                                            <KodeAnggaranFromString kode={anggaran.kode_anggaran_baru} />
                                                                        ) : (
                                                                            <span className="text-gray-400">-</span>
                                                                        )}
                                                                        {anggaran.kode_anggaran_baru && <CopyButton value={anggaran.kode_anggaran_baru} label="Salin Kode Baru" />}
                                                                    </span>
                                                                </td>
                                                                <td className="border px-3 py-2 text-right font-mono">
                                                                    {formatRupiah(anggaran.nominal_anggaran)}
                                                                </td>
                                                                <td className="border px-3 py-2">{anggaran.mata_anggaran}</td>
                                                                <td className="border px-3 py-2 whitespace-nowrap font-mono text-xs text-muted-foreground">
                                                                    <span className="inline-flex items-center gap-1">
                                                                        {anggaran.kode_anggaran_lama || '—'}
                                                                        {anggaran.kode_anggaran_lama && <CopyButton value={anggaran.kode_anggaran_lama} label="Salin Kode Lama" />}
                                                                    </span>
                                                                </td>
                                                                <td className="border px-2 py-2 text-center">
                                                                    <CopyButton value={() => rowToTSV(pabd05AnggaranToRow(anggaran))} label="Salin Baris" />
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

                            {/* Summary */}
                            <div className="rounded-lg border bg-gray-50 p-4">
                                <div className="grid gap-2 text-sm sm:grid-cols-3">
                                    <div>
                                        <span className="text-gray-500">Total Anggaran Dicairkan:</span>
                                        <span className="ml-2 font-semibold text-green-700">
                                            {formatRupiah(pabd05.total_anggaran_dicairkan)}
                                        </span>
                                        <span className="ml-1 text-xs text-gray-500">({pabd05.total_item_dicairkan} item)</span>
                                    </div>
                                    <div>
                                        <span className="text-gray-500">Total Anggaran Hangus:</span>
                                        <span className="ml-2 font-semibold text-red-700">
                                            {formatRupiah(totalAnggaranHangus)}
                                        </span>
                                        <span className="ml-1 text-xs text-gray-500">({pabd05.total_item_hangus} item)</span>
                                    </div>
                                    <div>
                                        <span className="text-gray-500">Grand Total:</span>
                                        <span className="ml-2 font-semibold">
                                            {formatRupiah(grandTotal)}
                                        </span>
                                        <span className="ml-1 text-xs text-gray-500">({totalItems} item)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </SectionCard>

                {/* Bukti Transfer */}
                <SectionCard title="Bukti Transfer">
                    {buktiTransferFiles.length === 0 ? (
                        <p className="text-sm text-gray-500">Tidak ada bukti transfer.</p>
                    ) : (
                        <div className="space-y-2">
                            {buktiTransferFiles.map((file) => (
                                <div
                                    key={file.id}
                                    className="flex items-center justify-between rounded-lg border bg-white px-4 py-2"
                                >
                                    <div className="flex items-center gap-2 text-sm">
                                        <FileText className="h-4 w-4 text-gray-400" />
                                        <span>{file.filename}</span>
                                        {file.size && (
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
                    )}
                </SectionCard>

                {/* Verifikasi Dokumen */}
                <SectionCard title="Verifikasi Dokumen">
                    <div className="space-y-3">
                        <div className="flex items-center gap-3">
                            <span className="text-sm text-gray-500">Kode Verifikasi:</span>
                            <code className="rounded bg-gray-100 px-3 py-1 font-mono text-lg font-bold tracking-wider">
                                {pabd05.verification_code || '-'}
                            </code>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleCopyCode}
                                disabled={!pabd05.verification_code}
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
                                href={`${basePath}/pabd05/export/pdf`}
                                icon={<FileText className="h-4 w-4" />}
                                label="Unduh PDF"
                                available={exportFiles.pdf?.available ?? false}
                            />
                            <DownloadButton
                                href={`${basePath}/pabd05/export/excel`}
                                icon={<FileSpreadsheet className="h-4 w-4" />}
                                label="Unduh Excel"
                                available={exportFiles.excel?.available ?? false}
                            />
                            <DownloadButton
                                href={`${basePath}/pabd05/export/zip`}
                                icon={<Package className="h-4 w-4" />}
                                label="Unduh ZIP"
                                available={true}
                            />
                        </div>
                        <p className="text-xs text-gray-500">
                            ZIP berisi PDF, Excel, dan semua file bukti transfer.
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
