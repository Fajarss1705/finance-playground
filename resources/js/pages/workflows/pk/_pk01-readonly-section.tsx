import { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import SectionCard from '@/components/workflow/section-card';

// ────────────────────────────────────────────────────────────
// Types (exported for use by PK02A, PK02B, PK03)
// ────────────────────────────────────────────────────────────

export type AnggaranDisplay = {
    id: number;
    kode_bidang: string;
    bidang_nama: string | null;
    kode_sub_bidang: string;
    sub_bidang_nama: string | null;
    kode_jenis: string;
    jenis_nama: string | null;
    mata_anggaran: string;
    deskripsi_pk: string | null;
    nominal_anggaran: number;
};

export type KuisionerDisplay = {
    id: number;
    kode_kuisioner: string | null;
    pertanyaan: string;
    tipe: string;
    satuan: string | null;
};

export type KegiatanDisplay = {
    id: number;
    nama_kegiatan: string;
    bulan: number;
    bulan_label: string | null;
    anggaran: AnggaranDisplay[];
    kuisioner: KuisionerDisplay[];
};

export type Pk01ReadonlyData = {
    id: number;
    kode_kategori: string | null;
    kategori_nama: string | null;
    nama_program: string | null;
    deskripsi_program: string | null;
    tujuan_program: string | null;
    kegiatan: KegiatanDisplay[];
    total_anggaran: number;
    created_by: {
        user_name: string | null;
        role_name: string | null;
        team_name: string | null;
        submitted_at: string | null;
    } | null;
};

export type PreviousCycle = {
    cycle_number: number;
    pk01_data: Pk01ReadonlyData | null;
    rejection: {
        step: string;
        step_label: string;
        notes: string | null;
        by_name: string | null;
        role_name: string | null;
        at: string | null;
        files: string[];
        parallelTrackNotes: {
            step: string;
            step_label: string;
            action: string;
            notes: string | null;
            by_name: string | null;
            at: string | null;
        } | null;
    } | null;
};

export type Pk01Change = {
    type: string;
    field?: string;
    kegiatan?: string;
    bulan?: number;
    mata_anggaran?: string;
    pertanyaan?: string;
    nama_kegiatan?: string;
    old?: string | number | null;
    new?: string | number | null;
};

export type ParallelTrackStatus = {
    step: string;
    label: string;
    status: string;
};

// ────────────────────────────────────────────────────────────
// Component
// ────────────────────────────────────────────────────────────

export type KodeAnggaranContext = {
    kode_team: string | null;
    tim_nama: string | null;
    tahun: number | null;
};

type Props = {
    pk01Data: Pk01ReadonlyData | null;
    previousCycles: PreviousCycle[];
    pk01Changes: Pk01Change[] | null;
    pp06RevisionLabel: string | null;
    kodeAnggaranContext?: KodeAnggaranContext;
};

function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(value);
}

function formatTanggal(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function pad(val: string | number | null | undefined, len: number): string {
    return String(val ?? '0').padStart(len, '0');
}

type KodeSegment = { value: string; label: string };

export type KodeNames = {
    bidang_nama?: string | null;
    sub_bidang_nama?: string | null;
    tim_nama?: string | null;
    jenis_nama?: string | null;
    kategori_nama?: string | null;
};

const BULAN_LABELS_SHORT = [
    '', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

function segLabel(type: string, nama?: string | null): string {
    return nama ? `${type}: ${nama}` : type;
}

export function buildKodeSegments(
    a: { kode_bidang: string; kode_sub_bidang: string; kode_jenis: string },
    ctx: KodeAnggaranContext,
    kodeKategori: string | null,
    bulan: number | '' | 0,
    names?: KodeNames,
): { baru: KodeSegment[]; lama: KodeSegment[] } {
    const bidang = pad(a.kode_bidang, 2);
    const subBidang = pad(a.kode_sub_bidang, 2);
    const tim = pad(ctx.kode_team, 2);
    const jenis = pad(a.kode_jenis, 2);
    const kategori = pad(kodeKategori, 2);
    const tahun = pad(ctx.tahun, 4);
    const bln = pad(bulan || 0, 2);
    const bulanNum = typeof bulan === 'number' ? bulan : 0;

    const baru: KodeSegment[] = [
        { value: bidang, label: segLabel('Bidang', names?.bidang_nama) },
        { value: subBidang, label: segLabel('Sub Bidang', names?.sub_bidang_nama) },
        { value: tim, label: segLabel('Tim', names?.tim_nama) },
        { value: jenis, label: segLabel('Jenis', names?.jenis_nama) },
        { value: kategori, label: segLabel('Kategori', names?.kategori_nama) },
        { value: '***', label: 'Program (ditetapkan saat kompilasi)' },
        { value: '***', label: 'Kegiatan (ditetapkan saat kompilasi)' },
        { value: '***', label: 'Anggaran (ditetapkan saat kompilasi)' },
        { value: tahun, label: `Tahun: ${ctx.tahun ?? '-'}` },
        { value: bln, label: `Bulan: ${BULAN_LABELS_SHORT[bulanNum] || '-'}` },
        { value: '00', label: 'Revisi: 0' },
    ];

    const lama: KodeSegment[] = [
        { value: bidang, label: segLabel('Bidang', names?.bidang_nama) },
        { value: subBidang, label: segLabel('Sub Bidang', names?.sub_bidang_nama) },
        { value: tim, label: segLabel('Tim', names?.tim_nama) },
        { value: jenis, label: segLabel('Jenis', names?.jenis_nama) },
        { value: kategori, label: segLabel('Kategori', names?.kategori_nama) },
        { value: '***', label: 'Program (ditetapkan saat kompilasi)' },
    ];

    return { baru, lama };
}

export function KodePreviewCell({ segments }: { segments: KodeSegment[] }) {
    return (
        <span className="font-mono text-muted-foreground">
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

export default function Pk01ReadonlySection({ pk01Data, previousCycles, pk01Changes, pp06RevisionLabel, kodeAnggaranContext }: Props) {
    const [showPrevious, setShowPrevious] = useState(false);
    const [showChanges, setShowChanges] = useState(false);
    const cycleNumber = previousCycles.length + 1;

    if (!pk01Data) {
        return (
            <SectionCard title="PK01 - Program Kegiatan">
                <p className="text-sm text-muted-foreground">Data PK01 belum tersedia.</p>
            </SectionCard>
        );
    }

    return (
        <SectionCard
            title="PK01 - Program Kegiatan"
            headerRight={cycleNumber > 1 ? <span className="text-xs text-muted-foreground">Pengisian ke-{cycleNumber}</span> : undefined}
        >
            {/* Previous cycles (collapsed) */}
            {previousCycles.length > 0 && (
                <div className="mb-4">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setShowPrevious(!showPrevious)}
                        className="text-xs text-muted-foreground"
                    >
                        {showPrevious ? <ChevronUp className="mr-1 h-3 w-3" /> : <ChevronDown className="mr-1 h-3 w-3" />}
                        Pengisian sebelumnya ({previousCycles.length})
                    </Button>

                    {showPrevious && (
                        <div className="mt-2 space-y-3 border-l-2 border-muted pl-4">
                            {previousCycles.map((cycle) => (
                                <div key={cycle.cycle_number} className="space-y-2 rounded-md border bg-muted/20 p-3">
                                    <p className="text-xs font-medium">Pengisian ke-{cycle.cycle_number}</p>
                                    {cycle.pk01_data && (
                                        <Pk01DataDisplay data={cycle.pk01_data} compact kodeAnggaranContext={kodeAnggaranContext} />
                                    )}
                                    {cycle.rejection && (
                                        <div className="mt-2 rounded-md border border-red-200 bg-red-50 p-2 text-xs dark:border-red-800 dark:bg-red-950">
                                            <p className="font-medium text-red-700 dark:text-red-300">
                                                Ditolak oleh {cycle.rejection.by_name} ({cycle.rejection.role_name}) — {cycle.rejection.step_label}
                                            </p>
                                            {cycle.rejection.notes && <p className="mt-1 text-red-600 dark:text-red-400">{cycle.rejection.notes}</p>}
                                            {cycle.rejection.parallelTrackNotes && cycle.rejection.parallelTrackNotes.notes && (
                                                <p className="mt-1 text-red-500/80 dark:text-red-400/80">
                                                    {cycle.rejection.parallelTrackNotes.step_label}: {cycle.rejection.parallelTrackNotes.notes}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Changelog (cycle 2+) */}
            {pk01Changes && pk01Changes.length > 0 && (
                <div className="mb-4">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setShowChanges(!showChanges)}
                        className="text-xs text-muted-foreground"
                    >
                        {showChanges ? <ChevronUp className="mr-1 h-3 w-3" /> : <ChevronDown className="mr-1 h-3 w-3" />}
                        Perubahan dari Pengisian Sebelumnya ({pk01Changes.length} perubahan)
                    </Button>

                    {showChanges && (
                        <div className="mt-2 space-y-1 border-l-2 border-amber-300 pl-4 text-xs dark:border-amber-700">
                            {pk01Changes.map((change, i) => (
                                <ChangeItem key={i} change={change} />
                            ))}
                        </div>
                    )}
                </div>
            )}

            {/* Submitter info */}
            {pk01Data.created_by && (
                <p className="mb-3 text-xs text-muted-foreground">
                    Diisi oleh: <span className="font-medium text-foreground">
                        {[pk01Data.created_by.user_name, pk01Data.created_by.role_name, pk01Data.created_by.team_name].filter(Boolean).join(' · ')}
                    </span>
                    {pk01Data.created_by.submitted_at && ` — ${formatTanggal(pk01Data.created_by.submitted_at)}`}
                </p>
            )}

            {/* PK01 Data Display */}
            <Pk01DataDisplay data={pk01Data} kodeAnggaranContext={kodeAnggaranContext} />

            {/* PP06 revision label */}
            {pp06RevisionLabel && (
                <p className="mt-3 text-xs text-muted-foreground">Referensi: {pp06RevisionLabel}</p>
            )}
        </SectionCard>
    );
}

// ────────────────────────────────────────────────────────────
// Sub-components
// ────────────────────────────────────────────────────────────

function Pk01DataDisplay({ data, compact = false, kodeAnggaranContext }: { data: Pk01ReadonlyData; compact?: boolean; kodeAnggaranContext?: KodeAnggaranContext }) {
    return (
        <div className="space-y-4">
            {/* Program info */}
            <div className={compact ? 'grid gap-1 text-xs' : 'grid gap-2 text-sm'}>
                <div>
                    <span className="text-muted-foreground">Kategori Pelayanan:</span>{' '}
                    <span className="font-medium">{data.kode_kategori}{data.kategori_nama ? ` - ${data.kategori_nama}` : ''}</span>
                </div>
                <div>
                    <span className="text-muted-foreground">Nama Program:</span>{' '}
                    <span className="font-medium">{data.nama_program ?? '—'}</span>
                </div>
                {!compact && (
                    <>
                        <div>
                            <span className="text-muted-foreground">Deskripsi Program:</span>{' '}
                            <span>{data.deskripsi_program ?? '—'}</span>
                        </div>
                        <div>
                            <span className="text-muted-foreground">Tujuan Program:</span>{' '}
                            <span>{data.tujuan_program ?? '—'}</span>
                        </div>
                    </>
                )}
            </div>

            {/* Kegiatan */}
            {data.kegiatan.length > 0 && (
                <div className="space-y-3">
                    <h4 className={compact ? 'text-xs font-medium' : 'text-sm font-medium'}>
                        Kegiatan & Anggaran ({data.kegiatan.length})
                    </h4>
                    {data.kegiatan.map((k) => (
                        <KegiatanCard key={k.id} kegiatan={k} compact={compact} kodeAnggaranContext={kodeAnggaranContext} kodeKategori={data.kode_kategori} />
                    ))}
                </div>
            )}

            {/* Total */}
            <div className={`rounded-md bg-muted/50 p-3 ${compact ? 'text-xs' : 'text-sm'}`}>
                <span className="text-muted-foreground">Total Anggaran PK Ini:</span>{' '}
                <span className="font-semibold tabular-nums">{formatRupiah(data.total_anggaran)}</span>
            </div>
        </div>
    );
}

function KegiatanCard({ kegiatan, compact = false, kodeAnggaranContext, kodeKategori }: { kegiatan: KegiatanDisplay; compact?: boolean; kodeAnggaranContext?: KodeAnggaranContext; kodeKategori?: string | null }) {
    const [expanded, setExpanded] = useState(!compact);
    const kegiatanTotal = kegiatan.anggaran.reduce((sum, a) => sum + a.nominal_anggaran, 0);

    return (
        <div className="rounded-md border">
            <button
                type="button"
                onClick={() => setExpanded(!expanded)}
                className="flex w-full items-center justify-between p-3 text-left hover:bg-muted/30"
            >
                <div className={compact ? 'text-xs' : 'text-sm'}>
                    <span className="font-medium">{kegiatan.nama_kegiatan}</span>
                    <span className="text-muted-foreground"> — {kegiatan.bulan_label ?? `Bulan ${kegiatan.bulan}`}</span>
                    <span className="ml-2 text-muted-foreground tabular-nums">({formatRupiah(kegiatanTotal)})</span>
                </div>
                {expanded ? <ChevronUp className="h-4 w-4 text-muted-foreground" /> : <ChevronDown className="h-4 w-4 text-muted-foreground" />}
            </button>

            {expanded && (
                <div className="border-t p-3 space-y-3">
                    {/* Anggaran table */}
                    {kegiatan.anggaran.length > 0 && (
                        <div className="overflow-x-auto">
                            <table className="min-w-225 w-full text-xs">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-2 py-1.5 text-left font-medium min-w-48">Mata Anggaran</th>
                                        <th className="px-2 py-1.5 text-right font-medium min-w-40">Nominal (Rp)</th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-40">Deskripsi</th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-40">Bidang</th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-40">Sub Bidang</th>
                                        <th className="px-2 py-1.5 text-left font-medium min-w-40">Jenis</th>
                                        {kodeAnggaranContext && (
                                            <>
                                                <th className="px-2 py-1.5 text-left font-medium min-w-56">Kode Baru</th>
                                                <th className="px-2 py-1.5 text-left font-medium min-w-40">Kode Lama</th>
                                            </>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {kegiatan.anggaran.map((a) => {
                                        const segs = kodeAnggaranContext ? buildKodeSegments(a, kodeAnggaranContext, kodeKategori ?? null, kegiatan.bulan, {
                                            bidang_nama: a.bidang_nama,
                                            sub_bidang_nama: a.sub_bidang_nama,
                                            jenis_nama: a.jenis_nama,
                                            tim_nama: kodeAnggaranContext.tim_nama,
                                        }) : null;
                                        return (
                                        <tr key={a.id} className="border-b last:border-0">
                                            <td className="px-2 py-1.5">{a.mata_anggaran}</td>
                                            <td className="px-2 py-1.5 text-right tabular-nums font-medium">{formatRupiah(a.nominal_anggaran)}</td>
                                            <td className="px-2 py-1.5 text-muted-foreground">{a.deskripsi_pk ?? '—'}</td>
                                            <td className="px-2 py-1.5">
                                                <span className="font-mono">{a.kode_bidang}</span>
                                                {a.bidang_nama && <span className="ml-1 text-muted-foreground">({a.bidang_nama})</span>}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <span className="font-mono">{a.kode_sub_bidang}</span>
                                                {a.sub_bidang_nama && <span className="ml-1 text-muted-foreground">({a.sub_bidang_nama})</span>}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <span className="font-mono">{a.kode_jenis}</span>
                                                {a.jenis_nama && <span className="ml-1 text-muted-foreground">({a.jenis_nama})</span>}
                                            </td>
                                            {segs && (
                                                <>
                                                    <td className="px-2 py-1.5"><KodePreviewCell segments={segs.baru} /></td>
                                                    <td className="px-2 py-1.5"><KodePreviewCell segments={segs.lama} /></td>
                                                </>
                                            )}
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* Kuisioner table */}
                    {kegiatan.kuisioner.length > 0 && (
                        <div className="overflow-x-auto">
                            <h5 className="mb-1 text-xs font-medium text-muted-foreground">Kuisioner</h5>
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-2 py-1.5 text-left font-medium w-16">Kode</th>
                                        <th className="px-2 py-1.5 text-left font-medium">Pertanyaan</th>
                                        <th className="px-2 py-1.5 text-left font-medium w-24">Tipe</th>
                                        <th className="px-2 py-1.5 text-left font-medium w-20">Satuan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {kegiatan.kuisioner.map((q) => (
                                        <tr key={q.id} className="border-b last:border-0">
                                            <td className="px-2 py-1.5 font-mono text-muted-foreground">{q.kode_kuisioner ?? '—'}</td>
                                            <td className="px-2 py-1.5">{q.pertanyaan}</td>
                                            <td className="px-2 py-1.5">{q.tipe}</td>
                                            <td className="px-2 py-1.5 text-muted-foreground">{q.satuan ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function ChangeItem({ change }: { change: Pk01Change }) {
    const fieldLabels: Record<string, string> = {
        kode_kategori: 'Kategori Pelayanan',
        nama_program: 'Nama Program',
        deskripsi_program: 'Deskripsi Program',
        tujuan_program: 'Tujuan Program',
        kode_bidang: 'Bidang',
        kode_sub_bidang: 'Sub Bidang',
        kode_jenis: 'Jenis',
        nominal_anggaran: 'Nominal',
        deskripsi_pk: 'Deskripsi PK',
    };

    switch (change.type) {
        case 'program_changed':
            return (
                <div>
                    <span className="font-medium">{fieldLabels[change.field ?? ''] ?? change.field} diubah</span>
                    <div className="ml-2 text-muted-foreground">
                        "{String(change.old ?? '—')}" → "{String(change.new ?? '—')}"
                    </div>
                </div>
            );
        case 'kegiatan_added':
            return <div><span className="font-medium">Kegiatan "{change.nama_kegiatan}" ditambahkan</span></div>;
        case 'kegiatan_removed':
            return <div><span className="font-medium text-red-600 dark:text-red-400">Kegiatan "{change.nama_kegiatan}" dihapus</span></div>;
        case 'anggaran_added':
            return <div><span className="font-medium">Kegiatan "{change.kegiatan}": Anggaran "{change.mata_anggaran}" ditambahkan</span></div>;
        case 'anggaran_removed':
            return <div><span className="font-medium text-red-600 dark:text-red-400">Kegiatan "{change.kegiatan}": Anggaran "{change.mata_anggaran}" dihapus</span></div>;
        case 'anggaran_changed':
            return (
                <div>
                    <span className="font-medium">Kegiatan "{change.kegiatan}": {fieldLabels[change.field ?? ''] ?? change.field} diubah</span>
                    <div className="ml-2 text-muted-foreground">
                        {change.field === 'nominal_anggaran'
                            ? `${formatRupiah(Number(change.old ?? 0))} → ${formatRupiah(Number(change.new ?? 0))}`
                            : `"${String(change.old ?? '—')}" → "${String(change.new ?? '—')}"`
                        }
                    </div>
                </div>
            );
        case 'kuisioner_added':
            return <div><span className="font-medium">Kegiatan "{change.kegiatan}": Kuisioner "{change.pertanyaan}" ditambahkan</span></div>;
        case 'kuisioner_removed':
            return <div><span className="font-medium text-red-600 dark:text-red-400">Kegiatan "{change.kegiatan}": Kuisioner "{change.pertanyaan}" dihapus</span></div>;
        default:
            return <div><span className="text-muted-foreground">Perubahan: {change.type}</span></div>;
    }
}
