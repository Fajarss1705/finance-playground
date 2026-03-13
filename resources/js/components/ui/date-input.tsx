import * as React from 'react';
import { Calendar } from 'lucide-react';
import { cn } from '@/lib/utils';

type DateInputProps = Omit<React.ComponentProps<'input'>, 'type' | 'value' | 'onChange'> & {
    value: string;
    onChange: (value: string) => void;
};

function toDisplay(iso: string): string {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    if (!y || !m || !d) return iso;
    return `${d}/${m}/${y}`;
}

function toIso(display: string): string {
    if (!display) return '';
    const [d, m, y] = display.split('/');
    if (!d || !m || !y) return display;
    return `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
}

function isValidDisplay(display: string): boolean {
    if (!display) return true;
    if (!/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(display)) return false;
    const [d, m, y] = display.split('/').map(Number);
    const date = new Date(y, m - 1, d);
    return date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d;
}

function DateInput({ value, onChange, disabled, className, ...props }: DateInputProps) {
    const [display, setDisplay] = React.useState(() => toDisplay(value));
    const pickerRef = React.useRef<HTMLInputElement>(null);

    React.useEffect(() => {
        setDisplay(toDisplay(value));
    }, [value]);

    function handleTextChange(e: React.ChangeEvent<HTMLInputElement>) {
        const raw = e.target.value;
        setDisplay(raw);
        if (isValidDisplay(raw)) {
            onChange(raw ? toIso(raw) : '');
        }
    }

    function handleTextBlur() {
        if (display && isValidDisplay(display)) {
            const iso = toIso(display);
            onChange(iso);
            setDisplay(toDisplay(iso));
        } else if (display && !isValidDisplay(display)) {
            setDisplay(toDisplay(value));
        }
    }

    function handlePickerChange(e: React.ChangeEvent<HTMLInputElement>) {
        const iso = e.target.value;
        onChange(iso);
        setDisplay(toDisplay(iso));
    }

    function openPicker() {
        pickerRef.current?.showPicker();
    }

    return (
        <div className="relative">
            <input
                {...props}
                type="text"
                inputMode="numeric"
                placeholder="dd/mm/yyyy"
                value={display}
                onChange={handleTextChange}
                onBlur={handleTextBlur}
                disabled={disabled}
                data-slot="input"
                className={cn(
                    'border-input placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 pr-9 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                    'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                    'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
                    className,
                )}
            />
            {!disabled && (
                <>
                    <input
                        ref={pickerRef}
                        type="date"
                        value={value}
                        onChange={handlePickerChange}
                        tabIndex={-1}
                        aria-hidden
                        className="sr-only"
                    />
                    <button
                        type="button"
                        onClick={openPicker}
                        tabIndex={-1}
                        className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                    >
                        <Calendar className="h-4 w-4" />
                    </button>
                </>
            )}
        </div>
    );
}

export { DateInput };
