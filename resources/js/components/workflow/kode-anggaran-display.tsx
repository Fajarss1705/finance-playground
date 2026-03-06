import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

type SegmentDef = {
    label: string;
    length: number;
};

const segmentDefs: SegmentDef[] = [
    { label: 'Bidang Pelayanan', length: 2 },
    { label: 'Sub Bidang Pelayanan', length: 2 },
    { label: 'Tim', length: 2 },
    { label: 'Jenis Program', length: 2 },
    { label: 'Kategori Pelayanan', length: 2 },
    { label: 'Program', length: 3 },
    { label: 'Kegiatan', length: 3 },
    { label: 'Anggaran', length: 3 },
    { label: 'Tahun', length: 4 },
    { label: 'Bulan', length: 2 },
    { label: 'Revisi', length: 2 },
];

// Prototype lookup tables — in production these come from PP06 reference data
const lookups: Record<string, Record<string, string>> = {
    'Bidang Pelayanan': {
        '01': 'Bidang 1',
        '02': 'Bidang 2',
        '03': 'Bidang 3',
        '04': 'Bidang 4',
        '05': 'BPMJ',
    },
    'Sub Bidang Pelayanan': {
        '01': 'POKJA KEB',
        '02': 'PEMBINAAN',
        '03': 'PERSEKUTUAN',
        '04': 'KESPEL',
        '05': 'BEA SISWA',
        '06': 'PERKUNJUNGAN',
        '07': 'KEDUKAAN',
        '08': 'DIAKONIA',
        '09': 'SARPPAS',
        '10': 'HRD',
        '11': 'PGG',
        '12': 'ASET',
        '13': 'HUMAS',
        '14': 'RAPAT/KOORDINASI',
    },
    Tim: {
        '10': 'Divisi Pendidikan',
        '20': 'Divisi Kepemudaan & TR',
        '30': 'Divisi Kaderisasi',
        '40': 'Divisi Dewasa',
        '50': 'Divisi Lansia',
        '70': 'Divisi Beasiswa',
        '80': 'Divisi MUGER',
    },
    'Jenis Program': {
        '01': 'Rutin',
        '02': 'Non Rutin',
    },
    'Kategori Pelayanan': {
        '01': 'Lyturgia | Perkegiatanan',
        '02': 'Kerygma | Pengajaran',
        '03': 'Koinonia | Persekutuan',
        '04': 'Diakonia Internal',
        '05': 'Diakonia Eksternal',
        '06': 'Marturya | Kesaksian',
        '07': 'Kepemimpinan',
    },
    Bulan: {
        '01': 'Januari',
        '02': 'Februari',
        '03': 'Maret',
        '04': 'April',
        '05': 'Mei',
        '06': 'Juni',
        '07': 'Juli',
        '08': 'Agustus',
        '09': 'September',
        '10': 'Oktober',
        '11': 'November',
        '12': 'Desember',
    },
};

function getTooltipText(label: string, value: string): string {
    const lookup = lookups[label];
    const name = lookup?.[value];
    if (name) return `${label}: ${name} (${value})`;
    if (label === 'Tahun') return `Tahun: ${value}`;
    if (label === 'Revisi') return `Revisi: ${parseInt(value, 10)}`;
    return `${label}: ${value}`;
}

function Segment({ label, value }: { label: string; value: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="cursor-help decoration-muted-foreground/40 decoration-dotted underline-offset-2 hover:underline hover:text-foreground">
                    {value}
                </span>
            </TooltipTrigger>
            <TooltipContent side="top">{getTooltipText(label, value)}</TooltipContent>
        </Tooltip>
    );
}

export default function KodeAnggaranDisplay({ kode }: { kode: string }) {
    const parts = kode.split('.');

    if (parts.length !== 11) {
        return <span>{kode}</span>;
    }

    return (
        <span className="inline-flex flex-wrap items-center font-mono text-xs">
            {parts.map((part, i) => (
                <span key={i} className="inline-flex items-center">
                    {i > 0 && <span className="text-muted-foreground/50">.</span>}
                    <Segment label={segmentDefs[i].label} value={part} />
                </span>
            ))}
        </span>
    );
}
