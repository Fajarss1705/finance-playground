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

type KodeAnggaranFromStringProps = {
    kode: string | null;
    className?: string;
};

export default function KodeAnggaranFromString({ kode, className }: KodeAnggaranFromStringProps) {
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
                            {SEGMENT_LABELS[i] ?? `Segment ${i + 1}`}: {segment}
                        </TooltipContent>
                    </Tooltip>
                ))}
            </span>
        </TooltipProvider>
    );
}
