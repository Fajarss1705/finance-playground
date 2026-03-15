import { Badge } from '@/components/ui/badge';

const statusConfig: Record<string, { label: string; className: string }> = {
    empty: { label: 'Kosong', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
    active: { label: 'Aktif', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
    completed: { label: 'Selesai', className: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' },
    revising: { label: 'Dalam Revisi', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
    terminated: { label: 'Dibatalkan', className: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' },
    cancelled: { label: 'Dibatalkan', className: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' },
    deleted: { label: 'Dihapus', className: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' },
};

export default function WorkflowStatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] ?? statusConfig.active;
    return <Badge className={config.className}>{config.label}</Badge>;
}
