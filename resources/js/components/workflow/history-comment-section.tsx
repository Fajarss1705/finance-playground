import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

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

export default function HistoryCommentSection({
    entries,
    commentUrl,
    commentSource,
    stepUrlResolver,
    defaultOpen = true,
}: Props) {
    const [isOpen, setIsOpen] = useState(defaultOpen);
    const commentForm = useForm({ notes: '', source: commentSource });

    const commentCount = entries.filter((e) => e.action === 'commented').length;

    function handleComment() {
        if (!commentForm.data.notes.trim()) return;
        commentForm.post(commentUrl, {
            preserveScroll: true,
            onSuccess: () => commentForm.reset('notes'),
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
                                value={commentForm.data.notes}
                                onChange={(e) => commentForm.setData('notes', e.target.value)}
                                placeholder="Tulis komentar..."
                                rows={2}
                                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <div className="flex justify-end">
                                <Button
                                    size="sm"
                                    disabled={!commentForm.data.notes.trim() || commentForm.processing}
                                    onClick={handleComment}
                                >
                                    {commentForm.processing ? 'Mengirim...' : 'Kirim Komentar'}
                                </Button>
                            </div>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
