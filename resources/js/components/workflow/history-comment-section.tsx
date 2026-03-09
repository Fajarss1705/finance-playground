import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ChevronDown, FileText, Paperclip, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

type FileRef = {
    id: number;
    uuid: string;
    original_filename: string;
    size: number;
    path: string | null;
};

export type HistoryEntry = {
    step: string;
    action: string;
    by: number | null;
    by_name: string;
    role: number | null;
    role_name: string | null;
    team?: number | null;
    team_name?: string | null;
    at: string;
    notes?: string;
    table?: string;
    id?: number;
    source?: string;
    files?: FileRef[];
    triggered_by_text?: string;
    revision?: number;
    changes?: Array<{ description: string }>;
};

type Props = {
    entries: HistoryEntry[];
    commentUrl: string;
    commentSource: string;
    canComment?: boolean;
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
    terminated: 'Workflow dibatalkan',
    deleted: 'Workflow dihapus',
    restored: 'Workflow dipulihkan',
    skipped: 'dilewati',
    reset: 'direset',
    file_uploaded: 'Upload file',
};

/** Actions that use workflow-level labels (no step prefix). */
const workflowLevelActions = new Set(['created', 'terminated', 'deleted', 'restored']);

function getBadgeText(entry: HistoryEntry): string {
    const { action, step, source, revision } = entry;

    let text: string;
    if (workflowLevelActions.has(action)) {
        text = actionLabels[action] ?? action;
    } else if (action === 'commented') {
        const stepCode = step || (source && source !== 'show' ? source.toUpperCase() : null);
        text = stepCode ? `${stepCode} komentar` : 'Komentar';
    } else if (action === 'file_uploaded') {
        text = 'Upload file';
    } else {
        text = `${step} ${actionLabels[action] ?? action}`;
    }

    if (revision !== undefined && revision > 0) {
        text += ` (Revisi ${revision})`;
    }

    return text;
}

const badgeColorMap: Record<string, string> = {
    submitted: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800',
    approved: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800',
    completed: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800',
    drafted: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800',
    rejected: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800',
    terminated: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800',
    commented: 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
};

const defaultBadgeColor = 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/30 dark:text-slate-300 dark:border-slate-700';

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
    restored: 'bg-slate-400',
    reset: 'bg-slate-400',
};

const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50 MB

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Jakarta',
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
    canComment = true,
    stepUrlResolver,
    defaultOpen = true,
}: Props) {
    const [isOpen, setIsOpen] = useState(defaultOpen);
    const [notes, setNotes] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [processing, setProcessing] = useState(false);

    const commentCount = entries.filter((e) => e.action === 'commented').length;

    function handleFileAdd(newFiles: FileList) {
        const valid: File[] = [];
        for (const file of Array.from(newFiles)) {
            if (file.size > MAX_FILE_SIZE) {
                alert(`File "${file.name}" melebihi batas 50MB.`);
                continue;
            }
            valid.push(file);
        }
        if (valid.length > 0) {
            setFiles((prev) => [...prev, ...valid]);
        }
    }

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
                        {entries.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Belum ada riwayat.</p>
                        ) : (
                            <div className="space-y-0">
                                {entries.map((entry, i) => {
                                    const dot = dotColor[entry.action] || 'bg-slate-400';
                                    const url = stepUrlResolver?.(entry) ?? null;
                                    const badgeText = getBadgeText(entry);
                                    const badgeColor = badgeColorMap[entry.action] ?? defaultBadgeColor;

                                    const userParts: string[] = [entry.by_name];
                                    if (entry.role_name) userParts.push(entry.role_name);
                                    if (entry.team_name) userParts.push(entry.team_name);
                                    const userLine = userParts.join(' · ');

                                    return (
                                        <div key={i} className="flex gap-3">
                                            <div className="flex flex-col items-center">
                                                <div className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${dot}`} />
                                                {i < entries.length - 1 && <div className="w-px flex-1 bg-border" />}
                                            </div>
                                            <div className="flex-1 pb-4">
                                                {/* Badge */}
                                                {url ? (
                                                    <Link href={url} onClick={(e) => e.stopPropagation()}>
                                                        <Badge className={`cursor-pointer ${badgeColor}`}>
                                                            {badgeText}
                                                        </Badge>
                                                    </Link>
                                                ) : (
                                                    <Badge className={badgeColor}>
                                                        {badgeText}
                                                    </Badge>
                                                )}

                                                {/* User line */}
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {userLine}
                                                </p>

                                                {/* Triggered by subtitle (system entries) */}
                                                {entry.triggered_by_text && (
                                                    <p className="text-xs italic text-muted-foreground/80">
                                                        {entry.triggered_by_text}
                                                    </p>
                                                )}

                                                {/* Date */}
                                                <p className="text-xs text-muted-foreground/60">
                                                    {formatDate(entry.at)}
                                                </p>

                                                {/* Notes */}
                                                {entry.notes && (
                                                    <div className="mt-1.5 rounded-md border bg-muted/30 px-3 py-2 text-sm">
                                                        {entry.notes}
                                                    </div>
                                                )}

                                                {/* Changes diff (revision entries) */}
                                                {entry.changes !== undefined && (
                                                    <ChangesSection changes={entry.changes} />
                                                )}

                                                {/* File chips */}
                                                {entry.files && entry.files.length > 0 && (
                                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                        {entry.files.map((f) => (
                                                            <FileChip key={f.id} file={f} />
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {/* Comment Box */}
                    {canComment && (
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
                                                handleFileAdd(e.target.files);
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
                    )}
                </>
            )}
        </div>
    );
}

function FileChip({ file }: { file: FileRef }) {
    if (file.path === null) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex cursor-not-allowed items-center gap-1 rounded border bg-muted/30 px-2 py-1 text-xs text-muted-foreground opacity-60">
                        <Paperclip className="h-3 w-3" />
                        <span className="max-w-40 truncate">{file.original_filename}</span>
                        <span>({formatFileSize(file.size)})</span>
                    </span>
                </TooltipTrigger>
                <TooltipContent>File sedang digenerate...</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <a
            href={`/files/${file.uuid}/download`}
            className="inline-flex items-center gap-1 rounded border bg-muted/30 px-2 py-1 text-xs text-primary hover:bg-muted/50 hover:underline"
        >
            <Paperclip className="h-3 w-3" />
            <span className="max-w-40 truncate">{file.original_filename}</span>
            <span className="text-muted-foreground">({formatFileSize(file.size)})</span>
        </a>
    );
}

function ChangesSection({ changes }: { changes: Array<{ description: string }> }) {
    if (changes.length === 0) {
        return (
            <p className="mt-1.5 text-xs italic text-muted-foreground">
                Tidak ada perubahan
            </p>
        );
    }

    return (
        <div className="mt-1.5">
            <Collapsible>
                <CollapsibleTrigger className="group flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                    <ChevronDown className="h-3 w-3 transition-transform group-data-[state=open]:rotate-180" />
                    {changes.length} perubahan
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ul className="mt-1 space-y-0.5 rounded-md border bg-muted/30 px-3 py-2 text-sm">
                        {changes.map((c, i) => (
                            <li key={i} className="flex gap-2">
                                <span className="shrink-0 text-muted-foreground">&bull;</span>
                                <span>{c.description}</span>
                            </li>
                        ))}
                    </ul>
                </CollapsibleContent>
            </Collapsible>
        </div>
    );
}
