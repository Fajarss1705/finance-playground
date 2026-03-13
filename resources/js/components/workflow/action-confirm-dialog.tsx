import { useState } from 'react';
import { FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type ActionConfirmDialogProps = {
    trigger: React.ReactNode;
    title: string;
    description?: string;
    confirmLabel?: string;
    variant?: 'default' | 'destructive';
    requireNotes?: boolean;
    processing?: boolean;
    onConfirm?: (data: { notes: string; files: File[] }) => void;
};

export default function ActionConfirmDialog({
    trigger,
    title,
    description,
    confirmLabel = 'Konfirmasi',
    variant = 'default',
    requireNotes = false,
    processing = false,
    onConfirm,
}: ActionConfirmDialogProps) {
    const [open, setOpen] = useState(false);
    const [notes, setNotes] = useState('');
    const [files, setFiles] = useState<File[]>([]);

    const canConfirm = !requireNotes || notes.trim().length > 0;

    function handleConfirm() {
        if (!canConfirm) return;
        onConfirm?.({ notes, files });
        setOpen(false);
        setNotes('');
        setFiles([]);
    }

    return (
        <Dialog open={open} onOpenChange={(v) => { setOpen(v); if (!v) { setNotes(''); setFiles([]); } }}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                {description && <DialogDescription>{description}</DialogDescription>}

                <div className="space-y-3">
                    <div className="space-y-1.5">
                        <Label>
                            Komentar {requireNotes ? '*' : '(opsional)'}
                        </Label>
                        <textarea
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Tulis komentar..."
                            rows={3}
                            className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Lampiran (opsional)</Label>
                        <label
                            htmlFor="action-file-upload"
                            className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-xs hover:bg-accent hover:text-accent-foreground"
                        >
                            <FileText className="h-4 w-4 text-muted-foreground" />
                            <span className="text-muted-foreground">Pilih file...</span>
                        </label>
                        <input
                            id="action-file-upload"
                            type="file"
                            className="sr-only"
                            multiple
                            onChange={(e) => {
                                if (e.target.files) {
                                    setFiles((prev) => [...prev, ...Array.from(e.target.files!)]);
                                }
                            }}
                        />
                        {files.length > 0 && (
                            <div className="space-y-1">
                                {files.map((f, i) => (
                                    <div key={i} className="flex items-center justify-between rounded border px-2 py-1 text-xs">
                                        <span className="truncate">{f.name}</span>
                                        <button
                                            type="button"
                                            onClick={() => setFiles((prev) => prev.filter((_, fi) => fi !== i))}
                                            className="ml-2 text-muted-foreground hover:text-destructive"
                                        >
                                            &times;
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Batal</Button>
                    </DialogClose>
                    <Button
                        variant={variant === 'destructive' ? 'destructive' : 'default'}
                        disabled={!canConfirm || processing}
                        onClick={handleConfirm}
                    >
                        {processing ? 'Memproses...' : confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
