import { ChevronDown, ChevronUp, Download, FileText, Paperclip, X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTimeShort } from '@/lib/utils';

export type HistoryEntry = {
    action: string;
    by: string;
    role: string;
    team?: string;
    at: string;
    notes?: string;
    files?: string[];
    table?: string;
};

const actionConfig: Record<string, { label: string; className: string }> = {
    created: { label: 'Dibuat', className: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
    drafted: { label: 'Draft', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
    submitted: { label: 'Submit', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
    approved: { label: 'Disetujui', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
    rejected: { label: 'Ditolak', className: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' },
    commented: { label: 'Komentar', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
    file_uploaded: { label: 'Upload File', className: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
    skipped: { label: 'Dilewati', className: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' },
    completed: { label: 'Selesai', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
};

const stepNames: Record<string, string> = {
    PP01: 'Program Kerja',
    PP02: 'Review Narasi',
    PP03: 'Plafon Anggaran',
    PP04: 'Review Anggaran',
    PP05: 'Sidang Pleno',
    PP06: 'Program Tahunan',
    PP07: 'Revisi',
    PK01: 'Program Kegiatan',
    PK02A: 'Approval Narasi',
    PK02B: 'Approval Anggaran',
    PK03: 'RAKER',
    PK04: 'Program Tahunan',
    PK05: 'Revisi',
};

function parseStep(table?: string): { code: string; name: string } | null {
    if (!table) return null;
    const code = table.replace('_data', '').replace(/_/g, '').toUpperCase();
    return { code, name: stepNames[code] ?? code };
}

const dotColor: Record<string, string> = {
    submitted: 'bg-green-500',
    approved: 'bg-green-500',
    completed: 'bg-green-500',
    drafted: 'bg-blue-500',
    rejected: 'bg-red-500',
    commented: 'bg-amber-500',
    created: 'bg-slate-400',
    file_uploaded: 'bg-slate-400',
    skipped: 'bg-slate-400',
};

export default function HistoryTimeline({ entries }: { entries: HistoryEntry[] }) {
    const [isOpen, setIsOpen] = useState(false);
    const [text, setText] = useState('');
    const [attachments, setAttachments] = useState<File[]>([]);

    const commentCount = entries.filter((e) => e.action === 'commented').length;

    function handleSubmit() {
        if (!text.trim() && attachments.length === 0) return;
        setText('');
        setAttachments([]);
    }

    function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
        if (e.target.files) {
            setAttachments((prev) => [...prev, ...Array.from(e.target.files!)]);
        }
        e.target.value = '';
    }

    function removeAttachment(index: number) {
        setAttachments((prev) => prev.filter((_, i) => i !== index));
    }

    return (
        <div className="rounded-lg border">
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="flex w-full items-center justify-between px-4 py-3 text-sm font-medium hover:bg-muted/50"
            >
                <span>
                    Riwayat ({entries.length}) & Komentar ({commentCount})
                </span>
                {isOpen ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
            </button>
            {isOpen && (
                <>
                    <div className="border-t px-4 py-3">
                        <div className="space-y-0">
                            {entries.map((entry, i) => {
                                const config = actionConfig[entry.action] || {
                                    label: entry.action,
                                    className: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                };
                                const step = parseStep(entry.table);
                                const dot = dotColor[entry.action] || 'bg-slate-400';

                                return (
                                    <div key={i} className="flex gap-3">
                                        <div className="flex flex-col items-center">
                                            <div className={`mt-2 h-2.5 w-2.5 shrink-0 rounded-full ${dot}`} />
                                            {i < entries.length - 1 && <div className="w-px flex-1 bg-border" />}
                                        </div>
                                        <div className="flex-1 pb-4">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                {step && (
                                                    <Badge className="bg-foreground text-background font-mono text-[10px] px-1.5 py-0 hover:bg-foreground/90">
                                                        {step.code}
                                                    </Badge>
                                                )}
                                                <Badge className={`text-[10px] px-1.5 py-0 ${config.className}`}>
                                                    {config.label}
                                                </Badge>
                                                {step && (
                                                    <span className="text-xs text-muted-foreground">{step.name}</span>
                                                )}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs">
                                                <span className="font-medium">{entry.by}</span>
                                                <span className="text-muted-foreground">·</span>
                                                <span className="text-muted-foreground">{entry.role}</span>
                                                {entry.team && (
                                                    <>
                                                        <span className="text-muted-foreground">·</span>
                                                        <span className="text-muted-foreground">{entry.team}</span>
                                                    </>
                                                )}
                                            </div>
                                            <p className="mt-0.5 text-xs text-muted-foreground/60">
                                                {formatDateTimeShort(entry.at)}
                                            </p>
                                            {entry.notes && (
                                                <div className="mt-1.5 rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                                    {entry.notes}
                                                </div>
                                            )}
                                            {entry.files && entry.files.length > 0 && (
                                                <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                    {entry.files.map((f, fi) => (
                                                        <div
                                                            key={fi}
                                                            className="inline-flex items-center gap-1.5 rounded border px-2 py-1 text-xs"
                                                        >
                                                            <FileText className="h-3 w-3 text-muted-foreground" />
                                                            <span>{f}</span>
                                                            <Button variant="link" size="sm" className="ml-1 h-auto p-0 text-xs">
                                                                <Download className="mr-0.5 h-3 w-3" />
                                                                Unduh
                                                            </Button>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="border-t px-4 py-3">
                        <p className="mb-2 text-xs font-medium text-muted-foreground">Tambah Komentar</p>
                        <div className="space-y-2">
                            <textarea
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                                placeholder="Tulis komentar..."
                                rows={2}
                                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            {attachments.length > 0 && (
                                <div className="flex flex-wrap gap-1.5">
                                    {attachments.map((file, i) => (
                                        <div
                                            key={i}
                                            className="inline-flex items-center gap-1.5 rounded border px-2 py-1 text-xs"
                                        >
                                            <FileText className="h-3 w-3 text-muted-foreground" />
                                            <span>{file.name}</span>
                                            <button
                                                type="button"
                                                onClick={() => removeAttachment(i)}
                                                className="text-muted-foreground hover:text-foreground"
                                            >
                                                <X className="h-3 w-3" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <div className="flex items-center justify-between">
                                <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground">
                                    <Paperclip className="h-4 w-4" />
                                    <span>Lampiran</span>
                                    <input
                                        type="file"
                                        className="sr-only"
                                        multiple
                                        onChange={handleFileChange}
                                    />
                                </label>
                                <Button size="sm" disabled={!text.trim() && attachments.length === 0} onClick={handleSubmit}>
                                    Kirim
                                </Button>
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
