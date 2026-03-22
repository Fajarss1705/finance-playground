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
    const roleDisplay = team ? `${role} \u00B7 ${team}` : role;
    const dateDisplay = date
        ? new Date(date).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '';

    return (
        <p className="text-xs text-muted-foreground">
            {label}: <span className="font-medium text-foreground">{name}</span>
            {roleDisplay && <> ({roleDisplay})</>}
            {dateDisplay && <> &mdash; {dateDisplay}</>}
        </p>
    );
}
