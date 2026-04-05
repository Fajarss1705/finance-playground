import { FileText, Paperclip, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

export default function CommentBox() {
    const [text, setText] = useState('');
    const [attachments, setAttachments] = useState<File[]>([]);

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
            <div className="px-4 py-3 text-sm font-medium">Tambah Komentar</div>
            <div className="border-t px-4 py-3">
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
        </div>
    );
}
