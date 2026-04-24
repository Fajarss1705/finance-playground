import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

export function formatRupiah(value: number): string {
    return new Intl.NumberFormat('id-ID').format(value);
}

/**
 * Format an ISO datetime string to Indonesian locale, WIB (Asia/Jakarta).
 * Returns empty string for null/undefined/invalid input.
 *
 * Example: "2026-04-24T19:27:33+07:00" → "24 April 2026, 19.27"
 */
export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Jakarta',
    });
}

/**
 * Format an ISO date/datetime string to Indonesian locale date only, WIB.
 * Returns empty string for null/undefined/invalid input.
 *
 * Example: "2026-04-24T19:27:33+07:00" → "24 April 2026"
 */
export function formatDate(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Jakarta',
    });
}

/**
 * Compact datetime (short month) for table cells and dense UI, WIB.
 *
 * Example: "2026-04-24T19:27:33+07:00" → "24 Apr 2026, 19.27"
 */
export function formatDateTimeShort(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Jakarta',
    });
}

/**
 * Compact date-only (short month), WIB.
 *
 * Example: "2026-04-24T19:27:33+07:00" → "24 Apr 2026"
 */
export function formatDateShort(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'Asia/Jakarta',
    });
}

/**
 * Pick the Tailwind class for an anggaran status badge (PABD pages).
 * "Tarik Maju …" / "Ditarik Maju …" → amber; "Di Luar Plafon" → purple;
 * "Normal" and anything else → slate.
 */
export function statusBadgeClass(label: string): string {
    if (label.startsWith('Tarik Maju') || label.startsWith('Ditarik Maju')) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300';
    }
    if (label === 'Di Luar Plafon') {
        return 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300';
    }
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

/**
 * Join row cells as a TSV line (tab-separated, no trailing newline).
 * Pastes cleanly into Excel/Sheets.
 */
export function rowToTSV(cells: Array<string | number | null | undefined>): string {
    return cells.map((c) => (c == null ? '' : String(c).replace(/\t/g, ' ').replace(/\n/g, ' '))).join('\t');
}

/**
 * Join header + multiple rows into a full TSV block.
 */
export function tableToTSV(
    headers: string[],
    rows: Array<Array<string | number | null | undefined>>,
): string {
    return [rowToTSV(headers), ...rows.map(rowToTSV)].join('\n');
}
