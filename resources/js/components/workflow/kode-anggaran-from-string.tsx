import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

const SEGMENT_LABELS = [
    'Tipe',
    'Bidang',
    'Sub Bidang',
    'Tim',
    'Jenis',
    'Kategori',
    'Program',
    'Kegiatan',
    'Anggaran',
    'Tahun',
    'Bulan',
    'Revisi',
    'Tarik',
];

const TIPE_NAMES: Record<string, string> = {
    R: 'Raker',
    P: 'Proposal',
};

const BULAN_NAMES = [
    '', 'Januari', 'Februari', 'Maret', 'April',
    'Mei', 'Juni', 'Juli', 'Agustus',
    'September', 'Oktober', 'November', 'Desember',
];

function describeSegment(
    index: number,
    segment: string,
    programName?: string,
    kegiatanName?: string,
    mataAnggaran?: string,
): string {
    const label = SEGMENT_LABELS[index] ?? `Segment ${index + 1}`;

    switch (index) {
        case 0: {
            const tipe = TIPE_NAMES[segment];
            return tipe ? `${label}: ${segment} (${tipe})` : `${label}: ${segment}`;
        }
        case 6:
            return programName ? `${label}: ${programName}` : `${label}: ${segment}`;
        case 7:
            return kegiatanName ? `${label}: ${kegiatanName}` : `${label}: ${segment}`;
        case 8:
            return mataAnggaran ? `${label}: ${mataAnggaran}` : `${label}: ${segment}`;
        case 10: {
            const n = parseInt(segment, 10);
            const nama = Number.isFinite(n) ? BULAN_NAMES[n] : undefined;
            return nama ? `${label}: ${segment} (${nama})` : `${label}: ${segment}`;
        }
        case 12: {
            const match = segment.match(/^M(\d+)$/);
            if (!match) return `${label}: ${segment}`;
            const count = parseInt(match[1], 10);
            return count === 0
                ? `${label}: ${segment} (Tidak tarik maju)`
                : `${label}: ${segment} (Tarik maju ${count} kali)`;
        }
        default:
            return `${label}: ${segment}`;
    }
}

type KodeAnggaranFromStringProps = {
    kode: string | null;
    className?: string;
    programName?: string;
    kegiatanName?: string;
    mataAnggaran?: string;
};

export default function KodeAnggaranFromString({
    kode,
    className,
    programName,
    kegiatanName,
    mataAnggaran,
}: KodeAnggaranFromStringProps) {
    if (!kode) {
        return <span className="text-muted-foreground">—</span>;
    }

    const segments = kode.split('.');

    return (
        <TooltipProvider delayDuration={200}>
            <span className={`inline-flex flex-wrap items-center gap-0 font-mono text-xs ${className ?? ''}`}>
                {segments.map((segment, i) => (
                    <Tooltip key={i}>
                        <TooltipTrigger asChild>
                            <span className="cursor-help hover:text-primary">
                                {segment}
                                {i < segments.length - 1 && <span className="text-muted-foreground">.</span>}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top" className="text-xs">
                            {describeSegment(i, segment, programName, kegiatanName, mataAnggaran)}
                        </TooltipContent>
                    </Tooltip>
                ))}
            </span>
        </TooltipProvider>
    );
}
