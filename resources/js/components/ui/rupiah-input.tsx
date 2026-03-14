import { useRef } from 'react';
import { Input } from '@/components/ui/input';

function formatNumber(value: number): string {
    if (value === 0) return '';
    return new Intl.NumberFormat('id-ID').format(value);
}

type RupiahInputProps = {
    value: number;
    onChange: (value: number) => void;
    disabled?: boolean;
    className?: string;
    min?: number;
};

export function RupiahInput({ value, onChange, disabled, className = '', min }: RupiahInputProps) {
    const inputRef = useRef<HTMLInputElement>(null);

    function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
        const raw = e.target.value.replace(/\D/g, '');
        const parsed = raw === '' ? 0 : parseInt(raw, 10);
        const finalValue = min !== undefined && parsed < min ? min : parsed;
        onChange(finalValue);

        // Restore cursor position after React re-renders with formatted value
        const el = inputRef.current;
        if (el) {
            const cursorPos = e.target.selectionStart ?? 0;
            const oldLen = e.target.value.length;
            const newFormatted = parsed === 0 ? '' : formatNumber(parsed);
            const newLen = newFormatted.length;
            const newPos = Math.max(0, cursorPos + (newLen - oldLen));
            requestAnimationFrame(() => {
                el.setSelectionRange(newPos, newPos);
            });
        }
    }

    return (
        <Input
            ref={inputRef}
            type="text"
            inputMode="numeric"
            value={formatNumber(value)}
            onChange={handleChange}
            disabled={disabled}
            className={`text-right ${className}`}
            placeholder="0"
        />
    );
}
