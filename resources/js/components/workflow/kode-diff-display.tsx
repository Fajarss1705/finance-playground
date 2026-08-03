import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

/**
 * KodeDiffDisplay — shows "Saat ini" vs "Setelah pemindahan" kodes
 * with changed segments highlighted (bold/color).
 *
 * Both kodes are 13-segment dot-separated strings:
 * Tipe.Bidang.SubBidang.Tim.Jenis.Kategori.Program.Kegiatan.Anggaran.Tahun.Bulan.Revisi.Tarik
 */

const SEGMENT_LABELS = [
    'Tipe', 'Bidang', 'Sub Bidang', 'Tim', 'Jenis', 'Kategori',
    'Program', 'Kegiatan', 'Anggaran', 'Tahun', 'Bulan', 'Revisi', 'Tarik',
];

function SegmentSpan({ value, changed, label }: { value: string; changed: boolean; label: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={`cursor-help ${changed ? 'rounded bg-amber-100 px-0.5 font-bold text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' : 'text-muted-foreground hover:text-foreground'}`}
                >
                    {value}
                </span>
            </TooltipTrigger>
            <TooltipContent side="top" className="text-xs">
                {label}: {value}
            </TooltipContent>
        </Tooltip>
    );
}

export default function KodeDiffDisplay({
    currentKode,
    previewKode,
}: {
    currentKode: string;
    previewKode: string;
}) {
    const currentSegments = currentKode.split('.');
    const previewSegments = previewKode.split('.');

    if (currentSegments.length < 13 || previewSegments.length < 13) {
        return (
            <div className="space-y-1 text-xs">
                <div><span className="text-muted-foreground">Saat ini:</span> <code className="text-[11px]">{currentKode}</code></div>
                <div><span className="text-muted-foreground">Setelah pemindahan:</span> <code className="text-[11px]">{previewKode}</code></div>
            </div>
        );
    }

    return (
        <div className="space-y-1.5 rounded border bg-muted/30 p-2 text-xs">
            <div>
                <span className="text-muted-foreground">Saat ini:</span>
                <div className="mt-0.5 font-mono text-[11px]">
                    {currentSegments.map((seg, i) => (
                        <span key={i}>
                            {i > 0 && <span className="text-muted-foreground/50">.</span>}
                            <SegmentSpan value={seg} changed={false} label={SEGMENT_LABELS[i] || ''} />
                        </span>
                    ))}
                </div>
            </div>
            <div>
                <span className="text-muted-foreground">Setelah pemindahan:</span>
                <div className="mt-0.5 font-mono text-[11px]">
                    {previewSegments.map((seg, i) => (
                        <span key={i}>
                            {i > 0 && <span className="text-muted-foreground/50">.</span>}
                            <SegmentSpan
                                value={seg}
                                changed={seg !== currentSegments[i]}
                                label={SEGMENT_LABELS[i] || ''}
                            />
                        </span>
                    ))}
                </div>
            </div>
        </div>
    );
}
