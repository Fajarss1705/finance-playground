import { formatDateTime } from '@/lib/utils';

/**
 * SubmitterLine — displays submitter info with role and date.
 * Used by PABD approval pages to show who submitted predecessor steps.
 */
export default function SubmitterLine({
    label,
    name,
    role,
    team,
    date,
}: {
    label: string;
    name: string;
    role: string;
    team?: string;
    date: string;
}) {
    const roleDisplay = team ? `${role} · ${team}` : role;
    const dateDisplay = formatDateTime(date);

    return (
        <p className="text-xs text-muted-foreground">
            {label}: <span className="font-medium text-foreground">{name}</span>
            {roleDisplay && <> ({roleDisplay})</>}
            {dateDisplay && <> &mdash; {dateDisplay}</>}
        </p>
    );
}
