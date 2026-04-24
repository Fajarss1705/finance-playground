import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    value: string | (() => string);
    label?: string;
    className?: string;
    size?: 'sm' | 'md';
    variant?: 'icon' | 'button';
};

export default function CopyButton({ value, label, className, size = 'sm', variant = 'icon' }: Props) {
    const [copied, setCopied] = useState(false);

    async function handleCopy(e: React.MouseEvent) {
        e.stopPropagation();
        e.preventDefault();
        const text = typeof value === 'function' ? value() : value;
        if (!text) return;
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 1200);
        } catch {
            // Silent fail — clipboard API can be blocked on insecure contexts.
        }
    }

    const iconSize = size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5';

    if (variant === 'button') {
        return (
            <button
                type="button"
                onClick={handleCopy}
                className={cn(
                    'inline-flex items-center gap-1 rounded border bg-background px-2 py-0.5 text-[11px] font-medium text-muted-foreground transition hover:bg-muted',
                    className,
                )}
                title={copied ? 'Tersalin' : label ?? 'Salin'}
            >
                {copied ? <Check className={cn(iconSize, 'text-green-600')} /> : <Copy className={iconSize} />}
                {label && <span>{copied ? 'Tersalin' : label}</span>}
            </button>
        );
    }

    return (
        <button
            type="button"
            onClick={handleCopy}
            className={cn(
                'inline-flex items-center justify-center rounded p-0.5 text-muted-foreground transition hover:bg-muted hover:text-foreground',
                className,
            )}
            title={copied ? 'Tersalin' : label ?? 'Salin'}
            aria-label={label ?? 'Salin'}
        >
            {copied ? <Check className={cn(iconSize, 'text-green-600')} /> : <Copy className={iconSize} />}
        </button>
    );
}
