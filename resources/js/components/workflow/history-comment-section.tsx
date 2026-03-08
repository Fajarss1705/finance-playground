import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { FileText, Paperclip, X } from 'lucide-react';
import { Button } from '@/components/ui/button';

type FileRef = {
    id: number;
    uuid: string;
    original_filename: string;
    size: number;
};

export type HistoryEntry = {
    step: string;
    action: string;
    by: number | null;
    by_name: string;
    role: number | null;
    role_name: string | null;
    at: string;
    notes?: string;
    table?: string;
    id?: number;
    source?: string;
    files?: FileRef[];
};

type Props = {
    entries: HistoryEntry[];
    commentUrl: string;
    commentSource: string;
    stepUrlResolver?: (entry: HistoryEntry) => string | null;
    defaultOpen?: boolean;
};

const actionLabels: Record<string, string> = {
    created: 'Workflow dibuat',
    drafted: 'di-draft',
    submitted: 'disubmit',
    approved: 'disetujui',
    rejected: 'ditolak',
    commented: 'komentar',
    completed: 'selesai',
    terminated: 'dibatalkan',
    deleted: 'dihapus',
    skipped: 'dilewati',
    file_uploaded: 'upload file',
};

const dotColor: Record<string, string> = {
    submitted: 'bg-green-500',
    approved: 'bg-green-500',
    completed: 'bg-green-500',
    drafted: 'bg-blue-500',
    rejected: 'bg-red-500',
    terminated: 'bg-red-500',
    commented: 'bg-amber-500',
    created: 'bg-slate-400',
    file_uploaded: 'bg-slate-400',
    skipped: 'bg-slate-400',
    deleted: 'bg-slate-400',
};

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function HistoryCommentSection({
    entries,
    commentUrl,
    commentSource,
    stepUrlResolver,
    defaultOpen = true,
}: Props) {
    const [isOpen, setIsOpen] = useState(defaultOpen);
    const [notes, setNotes] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [processing, setProcessing] = useState(false);

    const commentCount = entries.filter((e) => e.action === 'commented').length;

    function handleComment() {
        if (!notes.trim()) return;

        const data: Record<string, unknown> = {
            notes,
            source: commentSource,
        };
        if (files.length > 0) {
            data.files = files;
        }

        setProcessing(true);
        router.post(commentUrl, data, {
            preserveScroll: true,
            forceFormData: files.length > 0,
            onSuccess: () => {
                setNotes('');
                setFiles([]);
            },
            onFinish: () => setProcessing(false),
        });
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
                <svg className={`h-4 w-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
            {isOpen && (
                <>
                    <div className="border-t px-4 py-3">
                        <div className="space-y-0">
                            {entries.map((entry, i) => {
                                const dot = dotColor[entry.action] || 'bg-slate-400';
                                const url = stepUrlResolver?.(entry) ?? null;
                                const stepCode = entry.step;
                                const label = entry.action === 'created'
                                    ? 'Workflow dibuat'
                                    : `${stepCode} ${actionLabels[entry.action] ?? entry.action}`;

                                return (
                                    <div key={i} className="flex gap-3">
                                        <div className="flex flex-col items-center">
                                            <div className={`mt-2 h-2.5 w-2.5 shrink-0 rounded-full ${dot}`} />
                                            {i < entries.length - 1 && <div className="w-px flex-1 bg-border" />}
                                        </div>
                                        <div className="flex-1 pb-4">
                                            <div className="flex flex-wrap items-center gap-1.5 text-sm">
                                                <span className="font-medium">{entry.by_name}</span>
                                                {entry.role_name && (
                                                    <span className="text-xs text-muted-foreground">({entry.role_name})</span>
                                                )}
                                                <span className="text-muted-foreground">:</span>
                                                <span>{label}</span>
                                                {url && (
                                                    <Link
                                                        href={url}
                                                        className="text-xs text-primary hover:underline"
                                                        onClick={(e) => e.stopPropagation()}
                                                    >
                                                        [&rarr; {stepCode}]
                                                    </Link>
                                                )}
                                            </div>
                                            <p className="text-xs text-muted-foreground/60">
                                                {formatDate(entry.at)}
                                            </p>
                                            {entry.notes && (
                                                <div className="mt-1.5 rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                                    {entry.notes}
                                                </div>
                                            )}
                                            {entry.files && entry.files.length > 0 && (
                                                <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                    {entry.files.map((f) => (
                                                        <a
                                                            key={f.id}
                                                            href={`/files/${f.uuid}/download`}
                                                            className="inline-flex items-center gap-1 rounded border bg-muted/30 px-2 py-1 text-xs text-primary hover:bg-muted/50 hover:underline"
                                                        >
                                                            <Paperclip className="h-3 w-3" />
                                                            <span className="max-w-40 truncate">{f.original_filename}</span>
                                                            <span className="text-muted-foreground">({formatFileSize(f.size)})</span>
                                                        </a>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* Comment Box */}
                    <div className="border-t px-4 py-3">
                        <p className="mb-2 text-xs font-medium text-muted-foreground">Tambah Komentar</p>
                        <div className="space-y-2">
                            <textarea
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Tulis komentar..."
                                rows={2}
                                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />

                            {/* File Attachment */}
                            <div className="flex flex-wrap items-center gap-2">
                                <label
                                    htmlFor={`comment-file-${commentSource}`}
                                    className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-input bg-background px-2.5 text-xs shadow-xs hover:bg-accent hover:text-accent-foreground"
                                >
                                    <FileText className="h-3.5 w-3.5 text-muted-foreground" />
                                    <span className="text-muted-foreground">Lampiran...</span>
                                </label>
                                <input
                                    id={`comment-file-${commentSource}`}
                                    type="file"
                                    className="sr-only"
                                    multiple
                                    onChange={(e) => {
                                        if (e.target.files) {
                                            setFiles((prev) => [...prev, ...Array.from(e.target.files!)]);
                                            e.target.value = '';
                                        }
                                    }}
                                />
                                {files.map((f, i) => (
                                    <span key={i} className="inline-flex items-center gap-1 rounded border bg-muted/30 px-2 py-1 text-xs">
                                        <Paperclip className="h-3 w-3 text-muted-foreground" />
                                        <span className="max-w-32 truncate">{f.name}</span>
                                        <button
                                            type="button"
                                            onClick={() => setFiles((prev) => prev.filter((_, fi) => fi !== i))}
                                            className="ml-0.5 text-muted-foreground hover:text-destructive"
                                        >
                                            <X className="h-3 w-3" />
                                        </button>
                                    </span>
                                ))}
                            </div>

                            <div className="flex justify-end">
                                <Button
                                    size="sm"
                                    disabled={!notes.trim() || processing}
                                    onClick={handleComment}
                                >
                                    {processing ? 'Mengirim...' : 'Kirim Komentar'}
                                </Button>
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
